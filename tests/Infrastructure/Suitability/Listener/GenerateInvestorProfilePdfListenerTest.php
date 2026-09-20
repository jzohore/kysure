<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Suitability\Listener;

use App\Domain\Suitability\Event\InvestorProfileValidatedEvent;
use App\Infrastructure\Suitability\Listener\GenerateInvestorProfilePdfListener;
use App\Infrastructure\Suitability\Message\GenerateInvestorProfilePdfMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class GenerateInvestorProfilePdfListenerTest extends TestCase
{
    public function testDispatchesThePdfGenerationMessageWithTheValidatedProfileSlug(): void
    {
        /** @var MessageBusInterface&MockObject $messageBus */
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (GenerateInvestorProfilePdfMessage $m): bool => 'vip_1' === $m->profileSlugId))
            ->willReturn(new Envelope(new \stdClass()));

        $listener = new GenerateInvestorProfilePdfListener($messageBus);

        ($listener)(new InvestorProfileValidatedEvent(
            profileSlugId: 'vip_1',
            clientSlugId: 'cli_1',
            version: 1,
            retainedProfileLevel: 4,
            overridden: false,
            validatedByName: 'Marie Curie',
        ));
    }
}
