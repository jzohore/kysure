<?php

declare(strict_types=1);

namespace App\Infrastructure\EventSubscriber;

use App\Domain\Common\Attribute\Encrypted;
use App\Infrastructure\Security\MessageCipher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Chiffrement applicatif des propriétés #[Encrypted].
 *
 * 🛡️ Invariant : en mémoire, l'entité ET l'instantané de l'UnitOfWork portent le CLAIR ;
 * seule la base contient le chiffré. Sinon, chaque entité chargée paraît modifiée (clair en
 * mémoire ≠ chiffré lu en base) et chaque flush réécrit l'identité avec une copie
 * potentiellement périmée (lost update sur les données imprimées sur le DER).
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postLoad)]
final class EncryptionListener
{
    /**
     * Mémo des propriétés chiffrées par classe : métadonnées pures, aucune donnée utilisateur
     * → sûr en mode worker, pas de reset nécessaire.
     *
     * @var array<class-string, list<\ReflectionProperty>>
     */
    private array $encryptedPropertiesByClass = [];

    public function __construct(private readonly MessageCipher $cipher)
    {
    }

    /**
     * Chiffre l'entité avant l'INSERT (l'instantané de l'UoW est pris après cet événement :
     * il contient donc le chiffré, aligné dans postPersist).
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        foreach ($this->encryptedProperties($entity) as $property) {
            $value = $property->getValue($entity);

            if (\is_string($value) && '' !== $value) {
                $property->setValue($entity, $this->cipher->encrypt($value));
            }
        }
    }

    /** Redonne le clair à l'entité après l'INSERT et aligne l'instantané. */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->decryptAndSyncSnapshot($args->getObject(), $args->getObjectManager());
    }

    /**
     * Chiffre UNIQUEMENT les champs réellement modifiés, via le change-set (API officielle) :
     * l'entité garde son clair, et un champ non modifié n'est jamais réécrit.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        foreach ($this->encryptedProperties($args->getObject()) as $property) {
            $field = $property->getName();

            if (!$args->hasChangedField($field)) {
                continue;
            }

            $newValue = $args->getNewValue($field);

            if (\is_string($newValue) && '' !== $newValue) {
                $args->setNewValue($field, $this->cipher->encrypt($newValue));
            }
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->decryptAndSyncSnapshot($args->getObject(), $args->getObjectManager());
    }

    private function decryptAndSyncSnapshot(object $entity, EntityManagerInterface $entityManager): void
    {
        $properties = $this->encryptedProperties($entity);

        if ([] === $properties) {
            return;
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        $oid = spl_object_id($entity);

        foreach ($properties as $property) {
            $value = $property->getValue($entity);

            if (!\is_string($value) || '' === $value) {
                continue;
            }

            $plainText = $this->cipher->decrypt($value);
            $property->setValue($entity, $plainText);
            // L'instantané contient le chiffré lu en base : on l'aligne sur le clair.
            $unitOfWork->setOriginalEntityProperty($oid, $property->getName(), $plainText);
        }
    }

    /**
     * @return list<\ReflectionProperty>
     */
    private function encryptedProperties(object $entity): array
    {
        return $this->encryptedPropertiesByClass[$entity::class] ??= array_values(array_filter(
            new \ReflectionClass($entity)->getProperties(),
            static fn (\ReflectionProperty $property): bool => [] !== $property->getAttributes(Encrypted::class),
        ));
    }
}
