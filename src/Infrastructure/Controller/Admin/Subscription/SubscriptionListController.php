<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\Subscription;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(path: '/admin/subscriptions', name: 'admin_subscriptions_list', methods: ['GET'])]
final class SubscriptionListController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@admin/subscription/list.html.twig', [
            'page_title' => 'Abonnements',
        ]);
    }
}
