<?php

declare(strict_types=1);

namespace App\Infrastructure\ClientFile\Controller;

use App\Application\ClientFile\DTO\Request\UpdateClientFileRequest;
use App\Application\ClientFile\UseCase\FindClientFileUseCase;
use App\Application\ClientFile\UseCase\UpdateClientFileUseCase;
use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Enum\AssetClass;
use App\Domain\ClientFile\ValueObject\InvestmentObjective;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Infrastructure\ClientFile\Form\ClientFileType;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formulaire de la fiche patrimoniale (EER, lot 1 du chantier "rapport d'adéquation" — cf.
 * mémoire cif-pilot-roadmap-phase2). Formulaire Symfony classique (pas de LiveComponent) :
 * écran de saisie CGP en back-office, pas le chemin critique client — la sobriété prime ici sur
 * le craft, conformément à la doctrine produit de KYSURE.
 */
#[AsController]
#[Route(path: '/app/compliance/show/{slugId}/fiche-patrimoniale', name: 'app_client_file_edit', methods: ['GET', 'POST'])]
#[IsGranted(ComplianceFolderVoter::EDIT, subject: 'complianceFolder', message: 'Vous ne pouvez pas modifier ce dossier.')]
final class ClientFileFormController extends AbstractController
{
    private const int CENTS_TO_EUROS = 100;

    public function __construct(
        private readonly FindClientFileUseCase $findClientFile,
        private readonly UpdateClientFileUseCase $updateClientFile,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ComplianceFolder $complianceFolder,
    ): Response {
        $clientFile = ($this->findClientFile)($complianceFolder);

        $form = $this->createForm(ClientFileType::class, $this->buildInitialRequest($clientFile));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UpdateClientFileRequest $dto */
            $dto = $form->getData();

            try {
                ($this->updateClientFile)($complianceFolder, $dto);
                $this->addFlash('success', 'Fiche patrimoniale enregistrée.');

                return $this->redirectToRoute('app_compliance_show', ['slugId' => $complianceFolder->slugId]);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('@app/compliance/client_file_form.html.twig', [
            'page_title' => 'Fiche patrimoniale (EER)',
            'compliance_folder' => $complianceFolder,
            'client_file_form' => $form,
        ]);
    }

    private function buildInitialRequest(?ClientFile $clientFile): UpdateClientFileRequest
    {
        $request = new UpdateClientFileRequest();

        if (!$clientFile instanceof ClientFile) {
            return $request;
        }

        $request->civility = $clientFile->civility?->value;
        $request->birthDate = $clientFile->birthDate;
        $request->birthPlace = $clientFile->birthPlace;
        $request->nationality = $clientFile->nationality;
        $request->maritalStatus = $clientFile->maritalStatus?->value;
        $request->childrenCount = $clientFile->childrenCount;
        $request->professionalStatus = $clientFile->professionalStatus?->value;
        $request->profession = $clientFile->profession;
        $request->employer = $clientFile->employer;
        $request->professionalSeniorityYears = $clientFile->professionalSeniorityYears;

        $netWorth = $clientFile->netWorthStatement();
        if ($netWorth instanceof \App\Domain\ClientFile\ValueObject\NetWorthStatement) {
            $request->annualIncome = intdiv($netWorth->annualIncomeInCents, self::CENTS_TO_EUROS);
            $request->annualExpenses = intdiv($netWorth->annualExpensesInCents, self::CENTS_TO_EUROS);
            $request->outstandingDebt = intdiv($netWorth->outstandingDebtInCents, self::CENTS_TO_EUROS);
            $request->investmentCapacity = intdiv($netWorth->investmentCapacityInCents, self::CENTS_TO_EUROS);

            foreach ($netWorth->lines as $line) {
                $amountInEuros = intdiv($line->amountInCents, self::CENTS_TO_EUROS);
                match ($line->assetClass) {
                    AssetClass::REAL_ESTATE => $request->realEstateAmount = $amountInEuros,
                    AssetClass::LIFE_INSURANCE => $request->lifeInsuranceAmount = $amountInEuros,
                    AssetClass::PEA => $request->peaAmount = $amountInEuros,
                    AssetClass::SECURITIES_ACCOUNT => $request->securitiesAccountAmount = $amountInEuros,
                    AssetClass::REGULATED_SAVINGS => $request->regulatedSavingsAmount = $amountInEuros,
                    AssetClass::AVAILABLE_LIQUIDITY => $request->availableLiquidityAmount = $amountInEuros,
                };
            }
        }

        $existingObjectives = $clientFile->investmentObjectives();
        foreach ($request->objectives as $index => $slot) {
            $objective = $existingObjectives[$index] ?? null;
            if (!$objective instanceof InvestmentObjective) {
                continue;
            }

            $slot->type = $objective->type->value;
            $slot->priority = $objective->priority->value;
            $slot->horizon = $objective->horizon->value;
            $slot->amount = null !== $objective->amountInCents ? intdiv($objective->amountInCents, self::CENTS_TO_EUROS) : null;
        }

        return $request;
    }
}
