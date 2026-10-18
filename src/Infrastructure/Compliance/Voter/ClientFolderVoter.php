<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Voter;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\User\Entity\Client;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autorisations du client final (portail) sur SON dossier de conformité.
 * Distinct de {@see ComplianceFolderVoter}, réservé aux membres du cabinet.
 *
 * @extends Voter<string, ComplianceFolder>
 */
final class ClientFolderVoter extends Voter
{
    public const string DEPOSIT_DOCUMENTS = 'CLIENT_DEPOSIT_DOCUMENTS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::DEPOSIT_DOCUMENTS === $attribute && $subject instanceof ComplianceFolder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Client) {
            return false;
        }

        /** @var ComplianceFolder $folder */
        $folder = $subject;

        // Rattachement explicite du dossier à CE client. L'état de la collecte
        // (ouverte ou non) reste une règle métier du dossier, vérifiée par le use case.
        $owner = $folder->client;

        return $owner instanceof Client && $owner->id instanceof \Symfony\Component\Uid\Uuid && $owner->id->equals($user->id);
    }
}
