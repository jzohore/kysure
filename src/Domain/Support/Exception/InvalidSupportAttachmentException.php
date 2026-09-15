<?php

declare(strict_types=1);

namespace App\Domain\Support\Exception;

use App\Domain\Shared\Enum\ErrorCode;
use App\Domain\Shared\Exception\AbstractDomainException;
use Symfony\Component\HttpFoundation\Response;

class InvalidSupportAttachmentException extends AbstractDomainException
{
    public static function tooLarge(int $maxSizeBytes): self
    {
        return new self(
            message: sprintf('Le fichier dépasse la taille maximale autorisée (%d Mo).', intdiv($maxSizeBytes, 1024 * 1024)),
            errorCode: ErrorCode::INVALID_SUPPORT_ATTACHMENT,
            statusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    public static function unsupportedType(array $allowedMimeTypes): self
    {
        return new self(
            message: sprintf('Type de fichier non autorisé. Formats acceptés : %s.', implode(', ', $allowedMimeTypes)),
            errorCode: ErrorCode::INVALID_SUPPORT_ATTACHMENT,
            statusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
