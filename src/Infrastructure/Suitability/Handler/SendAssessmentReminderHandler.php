<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Handler;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Infrastructure\Notification\Email\Suitability\ClientAssessmentReminderEmail;
use App\Infrastructure\Suitability\Message\SendAssessmentReminderMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendAssessmentReminderHandler
{
    public function __construct(
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private UrlGeneratorInterface $router,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendAssessmentReminderMessage $message): void
    {
        $assessment = $this->assessmentRepository->findOneBySlugId($message->assessmentSlugId);

        if (!$assessment instanceof InvestorProfileAssessment || $assessment->isSubmitted()) {
            // Le client a fini son questionnaire (ou celui-ci a disparu) entre la
            // planification de la relance et son exécution : rien à relancer.
            $this->logger->info('Relance annulée : questionnaire introuvable ou déjà soumis.', [
                'assessment_slug_id' => $message->assessmentSlugId,
            ]);

            return;
        }

        $client = $assessment->client;
        $url = $this->router->generate('app_portal_investor_profile', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = new ClientAssessmentReminderEmail($client->email, $client->getNormalizedFirstName(), $url);
        $this->mailer->send($email);
    }
}
