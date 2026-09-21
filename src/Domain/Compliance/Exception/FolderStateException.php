<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Exception;

use App\Domain\Shared\Enum\ErrorCode;
use App\Domain\Shared\Exception\AbstractDomainException;
use Symfony\Component\HttpFoundation\Response;

class FolderStateException extends AbstractDomainException
{
    public static function cannotApproveIfNotInReview(string $reference, string $currentStatus): self
    {
        return new self(
            message: sprintf('Le dossier "%s" ne peut pas être approuvé car il est au statut "%s". Il doit être en cours d\'analyse.', $reference, $currentStatus),
            errorCode: ErrorCode::KYC_FOLDER_NOT_IN_REVIEW,
            statusCode: Response::HTTP_CONFLICT, // 409 Conflict est parfait pour une erreur d'état métier
            payload: [
                'folder_reference' => $reference,
                'current_status' => $currentStatus,
                'expected_status' => 'IN_REVIEW',
            ]
        );
    }

    public static function missingMandatoryDocuments(string $reference): self
    {
        return new self(
            message: sprintf('Le dossier "%s" ne peut pas être soumis car des documents obligatoires sont manquants.', $reference),
            errorCode: ErrorCode::KYC_MISSING_DOCUMENTS,
            statusCode: Response::HTTP_UNPROCESSABLE_ENTITY, // 422
            payload: ['folder_reference' => $reference],
        );
    }

    public static function cannotRejectIfNotInReview(string $reference, string $currentStatus): self
    {
        return new self(
            message: sprintf('Le dossier "%s" ne peut pas être rejeté car il est au statut "%s". Il doit être en cours d\'analyse.', $reference, $currentStatus),
            errorCode: ErrorCode::KYC_FOLDER_NOT_IN_REVIEW,
            statusCode: Response::HTTP_CONFLICT,
            payload: [
                'folder_reference' => $reference,
                'current_status' => $currentStatus,
                'expected_status' => 'IN_REVIEW',
            ]
        );
    }

    /**
     * Décision lot 5 : un dossier ne peut être déclaré conforme (LCB-FT) sans un profil
     * investisseur validé en vigueur pour ce client auprès de ce cabinet — chaque CIF a son
     * propre devoir d'évaluation de l'adéquation, il ne peut pas s'en dispenser.
     */
    public static function missingValidatedInvestorProfile(string $reference): self
    {
        return new self(
            message: sprintf('Le dossier "%s" ne peut pas être approuvé : aucun profil investisseur validé n\'est en vigueur pour ce client auprès de ce cabinet.', $reference),
            errorCode: ErrorCode::KYC_MISSING_VALIDATED_INVESTOR_PROFILE,
            statusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            payload: ['folder_reference' => $reference],
        );
    }
}
