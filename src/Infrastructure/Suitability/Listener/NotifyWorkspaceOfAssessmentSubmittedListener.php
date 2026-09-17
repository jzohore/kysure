<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Listener;

use App\Domain\Suitability\Event\InvestorProfileAssessmentSubmittedEvent;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Infrastructure\Suitability\Message\DispatchNotifyAssessmentSubmitted;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

/**
 * Alerte les administrateurs du cabinet dès qu'un client soumet son questionnaire profil
 * investisseur : sans ça, la seule visibilité était l'indicateur du tableau de bord, consulté
 * au bon vouloir du CGP — pas assez réactif pour un client qui attend son prochain rendez-vous.
 */
#[AsEventListener]
readonly class NotifyWorkspaceOfAssessmentSubmittedListener
{
    public function __construct(
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(InvestorProfileAssessmentSubmittedEvent $event): void
    {
        $assessment = $this->assessmentRepository->findOneBySlugId($event->assessmentSlugId);
        Assert::notNull($assessment, 'Assessment profil investisseur introuvable pour la notification cabinet.');

        $membersToNotify = $this->workspaceMemberRepository->findMembersAdmin($assessment->workspace);

        if ([] === $membersToNotify) {
            $this->logger->critical('NotifyWorkspaceOfAssessmentSubmitted: aucun administrateur trouvé pour le workspace.', [
                'workspace_id' => $assessment->workspace->id,
            ]);

            return; // On stoppe le process proprement
        }

        $clientName = $assessment->client->getNormalizedFirstName() . ' ' . $assessment->client->getNormalizedLastName();
        $reviewUrl = $this->urlGenerator->generate('app_clients_show', [
            'slugId' => $assessment->client->slugId,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($membersToNotify as $member) {
            Assert::notNull($member->email);

            $this->messageBus->dispatch(new DispatchNotifyAssessmentSubmitted(
                recipientEmail: $member->email,
                clientName: $clientName,
                reviewUrl: $reviewUrl,
            ));
        }
    }
}
