<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Port\OcrProviderInterface;

class FakeOcrProvider implements OcrProviderInterface
{
    public function extractData(DocumentType $type, string $filePath): array
    {
        // On simule une latence réseau et le temps de calcul de l'IA (500ms)
        usleep(500000);

        return match ($type) {
            DocumentType::ID_CARD => [
                'first_name' => 'PAUL (MOCK)',
                'last_name' => 'ALOIS',
                'birth_date' => '1980-01-21',
                'birth_place' => 'PARIS (75)',
                'id_number' => '130375300819',
                'nationality' => 'Française',
                'gender' => 'M',
                'mrz' => [
                    'line_1' => 'IDFRAALOIS<<<<<<<<<<<<<<<<<<<<<<<<<<',
                    'line_2' => '1303753008192PAUL<<<<<<<<<<8001210M2',
                ],
            ],

            DocumentType::KBIS => [
                'company_name' => 'CONIBI (MOCK)',
                'registration_number' => '429 225 683 R.C.S. Bobigny',
                'document_date' => new \DateTimeImmutable('-1 month')->format('Y-m-d'),
                'legal_form' => 'Société par actions simplifiée',
                'share_capital' => 762500,
                'date_of_registration' => '2000-01-27',
                'registered_address' => '47 Allée des Impressionnistes 93420 Villepinte',
                'duration_of_company' => "Jusqu'au 27/01/2099",
                'activity_code_ape' => null,
                'management' => [
                    [
                        'name' => 'SIMONE Olivier, Gabriel',
                        'role' => 'Président',
                    ],
                    [
                        'name' => 'PLC CONSEIL',
                        'role' => 'Commissaire aux comptes titulaire',
                    ],
                ],
                'establishments' => [
                    [
                        'address' => '47 Allée des Impressionnistes 93420 Villepinte',
                        'activity' => 'Collecte et recyclage',
                    ],
                ],
            ],

            DocumentType::PROOF_OF_ADDRESS => [
                'holder_name' => 'ALOIS PAUL (MOCK)',
                'address' => '12 rue des Lilas 75011 Paris',
                'document_date' => new \DateTimeImmutable('-1 month')->format('Y-m-d'),
            ],

            default => throw new \Exception("Mock non implémenté pour le type de document: {$type->value}"),
        };
    }
}
