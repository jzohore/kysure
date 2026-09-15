<?php

declare(strict_types=1);

namespace App\Application\Support\Service;

use App\Domain\Support\Exception\InvalidSupportAttachmentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Règle métier partagée : mêmes limites taille/type pour un fichier joint à un
 * message support, côté client (PostSupportMessageUseCase) et admin (ReplyToSupportThreadUseCase).
 */
final class SupportAttachmentValidator
{
    private const int MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    /**
     * @throws InvalidSupportAttachmentException
     */
    public static function assertValid(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw InvalidSupportAttachmentException::tooLarge(self::MAX_SIZE_BYTES);
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw InvalidSupportAttachmentException::unsupportedType(self::ALLOWED_MIME_TYPES);
        }
    }
}
