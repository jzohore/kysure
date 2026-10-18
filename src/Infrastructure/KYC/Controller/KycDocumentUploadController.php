<?php

declare(strict_types=1);

namespace App\Infrastructure\KYC\Controller;

use App\Application\Compliance\DTO\Request\UploadDocumentRequest;
use App\Application\Compliance\UseCase\ComplianceDocument\UploadDocumentUseCase;
use App\Application\Compliance\UseCase\ComplianceFolder\FindFolderForDepositUseCase;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Exception\Document\VirusScannerUnavailableException;
use App\Infrastructure\Compliance\Voter\ClientFolderVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
final class KycDocumentUploadController extends AbstractController
{
    public function __construct(
        private readonly UploadDocumentUseCase $uploadDocumentUseCase,
        private readonly FindFolderForDepositUseCase $findFolderForDeposit,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route(
        path: '/portal/kyc/document/{documentId}/{folderId}/upload',
        name: 'portal_kyc_document_upload',
        methods: ['POST']
    )]
    public function __invoke(string $documentId, string $folderId, Request $request): JsonResponse
    {
        // 🛡️ Le dossier doit appartenir au client connecté. 404 (et non 403) pour ne pas révéler son existence.
        if (!Uuid::isValid($documentId)) {
            throw $this->createNotFoundException();
        }

        try {
            $folder = ($this->findFolderForDeposit)($folderId);
        } catch (ComplianceFolderNotFoundException) {
            throw $this->createNotFoundException();
        }

        if (!$this->isGranted(ClientFolderVoter::DEPOSIT_DOCUMENTS, $folder)) {
            throw $this->createNotFoundException();
        }

        $file = $request->files->get('document');

        if (!$file instanceof UploadedFile) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Veuillez sélectionner un fichier valide.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dto = new UploadDocumentRequest();
        $dto->folderId = $folderId;
        $dto->documentId = $documentId;
        $dto->file = $file;

        // Taille et type MIME bornés (cf. contraintes du DTO).
        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return new JsonResponse([
                'ok' => false,
                'message' => $violations->get(0)->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            ($this->uploadDocumentUseCase)($dto);

            return new JsonResponse([
                'ok' => true,
                'fileName' => $file->getClientOriginalName(),
            ]);
        } catch (VirusScannerUnavailableException $e) {
            // Réessayable : on ne dit pas « refusé », on dit « indisponible ».
            return new JsonResponse([
                'ok' => false,
                'retryable' => true,
                'message' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\DomainException|\InvalidArgumentException $e) {
            // 2. C'est une règle métier qui a pété (ex: "Ce dossier KYC est déjà clôturé")
            return new JsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            $this->addFlash('error', 'Une erreur est survenue lors de l\'enregistrement du document.');

            return new JsonResponse([
                'ok' => false,
                'message' => 'Une erreur système est survenue lors de l\'enregistrement.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
