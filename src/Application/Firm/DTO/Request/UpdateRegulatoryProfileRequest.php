<?php

declare(strict_types=1);

namespace App\Application\Firm\DTO\Request;

use App\Domain\Firm\Entity\RegulatoryProfile;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateRegulatoryProfileRequest
{
    // Le numéro ORIAS : 10 chiffres (immatriculation)
    #[Assert\Regex(
        pattern: '/^\d{8}$/',
        message: 'Le numéro ORIAS doit comporter exactement 8 chiffres.'
    )]
    public ?string $oriasNumber = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public ?string $professionalAssociation = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $rcProInsurer = null;

    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9\-\s]{5,50}$/i',
        message: 'Format de numéro de police RC Pro invalide.'
    )]
    public ?string $rcProPolicyNumber = null;

    #[Assert\NotNull]
    public bool $isIndependent = true;

    #[Assert\Length(max: 150)]
    public ?string $signatoryName = null;

    #[Assert\Length(max: 120)]
    public ?string $signatoryCity = null;

    /** Précisions du cabinet imprimées dans le DER (services, rémunération, conflits d'intérêts). */
    #[Assert\Length(
        max: RegulatoryProfile::DER_ADDITIONAL_INFORMATION_MAX_LENGTH,
        maxMessage: 'Ce texte ne peut dépasser {{ limit }} caractères.',
    )]
    public ?string $derAdditionalInformation = null;

    /**
     * @var PartnerDTO[]
     */
    #[Assert\Valid] // 🪄 C'est tout ce dont tu as besoin !
    public array $partners = [];
}
