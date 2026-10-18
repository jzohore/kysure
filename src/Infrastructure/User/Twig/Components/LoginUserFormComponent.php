<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Twig\Components;

use App\Application\User\DTO\Request\LoginUserRequest;
use App\Application\User\UseCase\SendLoginUserUseCase;
use App\Infrastructure\User\Form\LoginUserType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(
    name: 'LoginUserFormComponent',
    template: 'components/User/LoginUserFormComponent.html.twig',
)]
final class LoginUserFormComponent
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public bool $isSuccessful = false;

    #[LiveProp]
    public ?string $message = null;

    /** Variante « espace client » : libellés adaptés et retour vers la page d'accès client. */
    #[LiveProp]
    public bool $forClient = false;

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly SendLoginUserUseCase $sendLoginUserUseCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function instantiateForm(): FormInterface
    {
        return $this->formFactory->create(LoginUserType::class);
    }

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();
        $form = $this->getForm();

        if (!$form->isValid()) {
            return;
        }

        /** @var LoginUserRequest $userDTO */
        $userDTO = $form->getData();

        try {
            ($this->sendLoginUserUseCase)($userDTO);
        } catch (\DomainException $e) {
            $this->logger->error('Erreur métier lors de la connexion', [
                'email' => $userDTO->email,
                'error' => $e->getMessage(),
            ]);
        }

        $this->isSuccessful = true;
        $this->message = 'Si cette adresse est valide, un lien magique vous a été envoyé pour vous connecter de manière sécurisée.';
    }
}
