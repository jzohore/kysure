<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees\Controller;

use App\Domain\User\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Webmozart\Assert\Assert;

#[AsController]
#[Route(path: '/app/employees', name: 'app_employees_list', methods: ['GET', 'POST'])]
final readonly class EmployeesListController
{
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        Environment $twig,
        #[CurrentUser]
        User $user,
    ): Response {
        $userId = $user->id;
        Assert::notNull($userId, "L'utilisateur doit avoir un ID pour récupérer le workspace.");

        return new Response(
            $twig->render('@app/employees/list.html.twig', [
                'page_title' => 'Équipe & Accès',
                'sub_title' => 'Gérez les accès, les rôles et la sécurité de vos collaborateurs.',
            ])
        );
    }
}
