<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Voter;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Infrastructure\Compliance\Voter\ClientFolderVoter;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

final class ClientFolderVoterTest extends TestCase
{
    use ReflectionHelperTrait;

    private ClientFolderVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new ClientFolderVoter();
    }

    private function token(?object $user): TokenInterface&Stub
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function client(): Client
    {
        return $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_' . bin2hex(random_bytes(3))]);
    }

    private function folderOf(?Client $client): BusinessFolder
    {
        return $this->createEntityState(BusinessFolder::class, ['slugId' => 'comp_fol_1', 'client' => $client]);
    }

    public function testTheOwnerClientCanDepositDocuments(): void
    {
        $client = $this->client();

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($client), $this->folderOf($client), [ClientFolderVoter::DEPOSIT_DOCUMENTS])
        );
    }

    public function testAnotherClientCannotDepositOnSomeoneElsesFolder(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($this->client()), $this->folderOf($this->client()), [ClientFolderVoter::DEPOSIT_DOCUMENTS])
        );
    }

    public function testNobodyCanDepositOnAFolderWithoutClient(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($this->client()), $this->folderOf(null), [ClientFolderVoter::DEPOSIT_DOCUMENTS])
        );
    }

    public function testAFirmMemberIsNotAClientOfTheFolder(): void
    {
        $client = $this->client();

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($this->createEntityState(User::class, [])), $this->folderOf($client), [ClientFolderVoter::DEPOSIT_DOCUMENTS])
        );
    }

    public function testAbstainsOnUnsupportedAttributeOrSubject(): void
    {
        $client = $this->client();

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($client), $this->folderOf($client), ['SOMETHING_ELSE'])
        );
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($client), new \stdClass(), [ClientFolderVoter::DEPOSIT_DOCUMENTS])
        );
    }
}
