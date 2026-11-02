<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Twig\Components\Client;

use App\Application\Portal\DTO\Request\UpdateClientProfileRequest;
use App\Application\Portal\UseCase\UpdateClientProfileUseCase;
use App\Domain\User\Entity\Client;
use App\Infrastructure\Shared\Component\LiveFlashTrait;
use App\Infrastructure\User\Form\ClientProfileType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Formulaire réactif « Mon profil » de l'espace client : prénom, nom, téléphone.
 * L'e-mail (identifiant de connexion) reste géré hors de ce formulaire.
 */
#[AsLiveComponent(
    name: 'ClientProfileFormComponent',
    template: 'components/User/Client/ClientProfileFormComponent.html.twig',
    // Servi sous /portal pour rester dans le firewall `portal` (cf. config/routes/ux_live_component.yaml).
    route: 'portal_ux_live_component',
)]
class ClientProfileFormComponent extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    use LiveFlashTrait;

    public function __construct(
        private readonly UpdateClientProfileUseCase $updateClientProfileUseCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        $client = $this->currentClient();

        $dto = new UpdateClientProfileRequest();
        $dto->firstName = $client->firstName;
        $dto->lastName = $client->lastName;
        $dto->phoneNumber = $client->phoneNumber;

        return $this->createForm(ClientProfileType::class, $dto);
    }

    #[LiveAction]
    public function save(): ?RedirectResponse
    {
        $this->submitForm();
        $this->clearLiveFlash();

        /** @var UpdateClientProfileRequest $dto */
        $dto = $this->getForm()->getData();

        try {
            ($this->updateClientProfileUseCase)($this->currentClient(), $dto);

            $this->addFlash('success', 'Votre profil a bien été mis à jour.');

            return $this->redirectToRoute('portal_profile');
        } catch (\DomainException $e) {
            $this->logger->warning('Mise à jour du profil client refusée.', [
                'client_slug_id' => $this->currentClient()->slugId,
                'error' => $e->getMessage(),
            ]);
            $this->addLiveFlash('error', $e->getMessage());

            return null;
        }
    }

    private function currentClient(): Client
    {
        $client = $this->getUser();

        if (!$client instanceof Client) {
            throw $this->createAccessDeniedException('Espace réservé aux clients.');
        }

        return $client;
    }
}
