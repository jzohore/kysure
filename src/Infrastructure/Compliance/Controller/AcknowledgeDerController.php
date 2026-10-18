<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use App\Application\Compliance\DTO\Request\AcknowledgeDerRequest;
use App\Application\Compliance\DTO\Request\DeclineDerRequest;
use App\Application\Compliance\UseCase\ComplianceDocument\DER\AcknowledgeDerUseCase;
use App\Application\Compliance\UseCase\ComplianceDocument\DER\ResolveDerAcknowledgementLinkUseCase;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Exception\AcknowledgementLinkException;
use App\Domain\Compliance\ValueObject\DerStatement;
use App\Infrastructure\Compliance\Form\DerAcknowledgementType;
use App\Infrastructure\Compliance\Form\DerDeclineType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique d'accusé de réception du DER. Accès par lien nominatif à jeton
 * (256 bits, haché en base) : le client n'a pas encore de compte.
 */
#[AsController]
#[Route(
    path: '/der/{token}',
    name: 'app_der_acknowledge',
    requirements: ['token' => self::TOKEN_REQUIREMENT],
    methods: ['GET', 'POST'],
)]
final class AcknowledgeDerController extends AbstractController
{
    use DerAcknowledgementLinkTrait;

    public function __construct(
        private readonly ResolveDerAcknowledgementLinkUseCase $resolveLink,
        private readonly AcknowledgeDerUseCase $acknowledgeDer,
        private readonly RateLimiterFactory $rateLimiter,
        private readonly RateLimiterFactory $readRateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        $this->enforceLimiter($this->readRateLimiter, $request, 'Trop de consultations. Merci de réessayer plus tard.');

        try {
            $document = ($this->resolveLink)($token);
        } catch (AcknowledgementLinkException $exception) {
            return $this->render('app/der/invalid.html.twig', ['reason' => $exception->reason], new Response('', Response::HTTP_GONE));
        }

        $workspace = $document->folder->workspace;

        $inForce = $document->acknowledgementInForce();
        if ($inForce instanceof DerAcknowledgement) {
            return $this->render('app/der/acknowledged.html.twig', [
                'acknowledgement' => $inForce,
                'token' => $token,
                'workspace' => $workspace,
                'pdf_url' => $this->generateUrl('app_der_pdf', ['token' => $token]),
                // Étape suivante : déposer les pièces. L'espace exige la connexion (lien magique envoyé
                // par e-mail) : on ne connecte jamais quelqu'un à partir de ce seul lien d'accusé.
                'portal_url' => $this->generateUrl('app_portal_folder_detail', ['id' => $document->folder->slugId]),
                'documents_to_provide' => $document->folder->documentsAwaitingClient(),
            ]);
        }

        if ($document->isDerDeclined()) {
            return $this->render('app/der/declined.html.twig', [
                'reason' => $document->derDeclineReason,
                'workspace' => $workspace,
            ]);
        }

        $acknowledgeRequest = new AcknowledgeDerRequest();
        $acknowledgeRequest->token = $token;
        $form = $this->createForm(DerAcknowledgementType::class, $acknowledgeRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->enforceLimiter($this->rateLimiter, $request, 'Trop de tentatives. Merci de réessayer plus tard.');

            $acknowledgeRequest->ipAddress = $request->getClientIp();
            $userAgent = (string) $request->headers->get('User-Agent', '');
            $acknowledgeRequest->userAgent = '' !== $userAgent ? mb_substr($userAgent, 0, 255) : null;

            try {
                ($this->acknowledgeDer)($acknowledgeRequest);
            } catch (UniqueConstraintViolationException $exception) {
                // Double soumission concurrente (double-clic) : l'un des deux
                // POST a gagné, l'autre échoue sur l'index unique. Bénin — le
                // redirect (nouvelle requête, nouvel EntityManager) affichera
                // le bon état final. Rien à signaler au client.
                $this->logger->info('Accusé de réception DER : collision bénigne sur double soumission.', [
                    'token_hash' => hash('sha256', $token),
                    'error' => $exception->getMessage(),
                ]);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\InvalidArgumentException $exception) {
                // Garde de précondition (Assert) : donnée du dossier incohérente
                // (ex. e-mail de contact absent). Filet de sécurité pour ne
                // jamais renvoyer un 500 brut sur cette page publique.
                $this->logger->error('Accusé de réception DER : précondition invalide.', [
                    'token_hash' => hash('sha256', $token),
                    'error' => $exception->getMessage(),
                ]);
                $this->addFlash('error', 'Une information de votre dossier est incomplète. Contactez votre conseiller.');
            }

            // POST-Redirect-GET : le rechargement affiche l'écran de confirmation.
            return $this->redirectToRoute('app_der_acknowledge', ['token' => $token]);
        }

        return $this->render('app/der/acknowledge.html.twig', [
            'form' => $form,
            'decline_form' => $this->createForm(DerDeclineType::class, new DeclineDerRequest(), [
                'action' => $this->generateUrl('app_der_decline', ['token' => $token]),
            ])->createView(),
            'statement' => DerStatement::current(),
            'workspace' => $workspace,
            'pdf_url' => $this->generateUrl('app_der_pdf', ['token' => $token]),
        ]);
    }
}
