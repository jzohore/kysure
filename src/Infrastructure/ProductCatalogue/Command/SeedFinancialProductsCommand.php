<?php

declare(strict_types=1);

namespace App\Infrastructure\ProductCatalogue\Command;

use App\Application\ProductCatalogue\DTO\Request\FinancialProductRequest;
use App\Application\ProductCatalogue\UseCase\CreateFinancialProductUseCase;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Repository\FinancialProductRepositoryInterface;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Repository\WorkspaceRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed d'un jeu de produits financiers de démonstration pour un cabinet, pour tester le
 * catalogue (lot 2 du chantier "rapport d'adéquation" — cf. mémoire cif-pilot-roadmap-phase2)
 * sans ressaisie manuelle répétée. Idempotent par nom : relancer la commande ne duplique pas
 * les produits déjà présents.
 */
#[AsCommand(
    name: 'app:product-catalogue:seed',
    description: 'Seed des produits financiers de démonstration pour un workspace',
)]
readonly class SeedFinancialProductsCommand
{
    public function __construct(
        private WorkspaceRepositoryInterface $workspaceRepository,
        private FinancialProductRepositoryInterface $financialProductRepository,
        private CreateFinancialProductUseCase $createFinancialProduct,
    ) {
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $io->ask('Nom du workspace à alimenter');
        $workspace = $this->workspaceRepository->findOneByName($name);
        if (!$workspace instanceof Workspace) {
            $io->error(sprintf('Aucun workspace nommé "%s".', $name));

            return Command::FAILURE;
        }

        $existingNames = array_map(
            static fn (FinancialProduct $product): string => $product->name,
            $this->financialProductRepository->findByWorkspace($workspace),
        );

        $created = 0;
        $skipped = 0;

        foreach ($this->seedData() as $item) {
            if (in_array($item['name'], $existingNames, true)) {
                ++$skipped;

                continue;
            }

            ($this->createFinancialProduct)($workspace, $this->toRequest($item));
            ++$created;
        }

        $io->success(sprintf(
            '%d produit(s) créé(s), %d déjà présent(s) ignoré(s) pour "%s".',
            $created,
            $skipped,
            $workspace->name,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array{name: string, isin: ?string, family: ProductFamily, sriLevel: int, minimumHorizonYears: int, annualFeesPercent: float, targetInvestorProfiles: list<InvestorProfileLevel>} $item
     */
    private function toRequest(array $item): FinancialProductRequest
    {
        $request = new FinancialProductRequest();
        $request->name = $item['name'];
        $request->isin = $item['isin'];
        $request->family = $item['family']->value;
        $request->sriLevel = $item['sriLevel'];
        $request->minimumHorizonYears = $item['minimumHorizonYears'];
        $request->annualFeesPercent = $item['annualFeesPercent'];
        $request->targetInvestorProfiles = array_map(
            static fn (InvestorProfileLevel $level): int => $level->value,
            $item['targetInvestorProfiles'],
        );

        return $request;
    }

    /**
     * @return list<array{name: string, isin: ?string, family: ProductFamily, sriLevel: int, minimumHorizonYears: int, annualFeesPercent: float, targetInvestorProfiles: list<InvestorProfileLevel>}>
     */
    private function seedData(): array
    {
        return [
            [
                'name' => 'Fonds Euro Garanti',
                'isin' => null,
                'family' => ProductFamily::ASSURANCE_VIE,
                'sriLevel' => 1,
                'minimumHorizonYears' => 2,
                'annualFeesPercent' => 0.60,
                'targetInvestorProfiles' => [InvestorProfileLevel::TRES_PRUDENT, InvestorProfileLevel::PRUDENT],
            ],
            [
                'name' => 'OPCVM Obligataire Court Terme',
                'isin' => 'FR0000000101',
                'family' => ProductFamily::OPCVM_ETF,
                'sriLevel' => 2,
                'minimumHorizonYears' => 2,
                'annualFeesPercent' => 0.40,
                'targetInvestorProfiles' => [InvestorProfileLevel::PRUDENT, InvestorProfileLevel::MODERE],
            ],
            [
                'name' => 'SCPI Pierre Rendement',
                'isin' => null,
                'family' => ProductFamily::IMMOBILIER_SCPI,
                'sriLevel' => 3,
                'minimumHorizonYears' => 8,
                'annualFeesPercent' => 1.20,
                'targetInvestorProfiles' => [InvestorProfileLevel::MODERE, InvestorProfileLevel::EQUILIBRE],
            ],
            [
                'name' => 'ETF MSCI World',
                'isin' => 'FR0000000102',
                'family' => ProductFamily::OPCVM_ETF,
                'sriLevel' => 4,
                'minimumHorizonYears' => 5,
                'annualFeesPercent' => 0.30,
                'targetInvestorProfiles' => [InvestorProfileLevel::EQUILIBRE, InvestorProfileLevel::DYNAMIQUE],
            ],
            [
                'name' => 'Action TotalEnergies',
                'isin' => 'FR0000120271',
                'family' => ProductFamily::TITRES_VIFS,
                'sriLevel' => 5,
                'minimumHorizonYears' => 5,
                'annualFeesPercent' => 0.50,
                'targetInvestorProfiles' => [InvestorProfileLevel::DYNAMIQUE, InvestorProfileLevel::TRES_DYNAMIQUE],
            ],
            [
                'name' => 'Produit structuré Autocall 10%',
                'isin' => 'FR0000000103',
                'family' => ProductFamily::PRODUITS_COMPLEXES,
                'sriLevel' => 6,
                'minimumHorizonYears' => 8,
                'annualFeesPercent' => 2.00,
                'targetInvestorProfiles' => [InvestorProfileLevel::TRES_DYNAMIQUE, InvestorProfileLevel::AGRESSIF],
            ],
        ];
    }
}
