<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class AdminLoginController extends AbstractController
{
    #[Route('/admin/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function __invoke(AuthenticationUtils $authentication): Response
    {
        return $this->render('admin/login.html.twig', [
            'last_username' => $authentication->getLastUsername(),
            'error' => $authentication->getLastAuthenticationError(),
        ]);
    }

    #[Route('/admin/logout', name: 'admin_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new LogicException('Le firewall Symfony intercepte cette route.');
    }
}
