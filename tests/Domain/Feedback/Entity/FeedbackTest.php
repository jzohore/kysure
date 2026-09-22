<?php

declare(strict_types=1);

namespace App\Tests\Domain\Feedback\Entity;

use App\Domain\Feedback\Entity\Feedback;
use App\Domain\User\Entity\User;
use PHPUnit\Framework\TestCase;

final class FeedbackTest extends TestCase
{
    public function testSubmitTrimsTheMessage(): void
    {
        $feedback = Feedback::submit($this->createStub(User::class), '  Le bouton ne répond pas  ', 'https://staging.kysure.fr/app/dashboard', 'Tableau de bord');

        self::assertSame('Le bouton ne répond pas', $feedback->message);
        self::assertSame('https://staging.kysure.fr/app/dashboard', $feedback->pageUrl);
        self::assertSame('Tableau de bord', $feedback->pageTitle);
    }

    public function testRejectsAnEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Feedback::submit($this->createStub(User::class), '   ', 'https://staging.kysure.fr/app/dashboard', null);
    }
}
