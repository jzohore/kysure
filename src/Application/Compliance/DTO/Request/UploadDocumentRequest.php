<?php

declare(strict_types=1);

namespace App\Application\Compliance\DTO\Request;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

class UploadDocumentRequest
{
    #[Assert\NotBlank]
    public string $documentId;

    #[Assert\NotBlank]
    public string $folderId;

    /** Face déposée d'une pièce à deux faces : `front` (recto, par défaut) ou `back` (verso). */
    #[Assert\Choice(choices: ['front', 'back'], message: 'Face de pièce inconnue.')]
    public string $side = 'front';

    #[Assert\NotNull(message: 'Veuillez sélectionner un fichier.')]
    #[Assert\File(
        maxSize: '10M',
        mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
        mimeTypesMessage: 'Veuillez uploader un PDF, un JPG ou un PNG valide.',
    )]
    public UploadedFile $file;
}
