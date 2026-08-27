<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Gövde boş bırakılır: gerçek çıkış işlemi Symfony'nin security
     * firewall'ı (logout anahtarı, security.yaml) tarafından bu rotaya
     * ulaşılmadan ÖNCE ele alınır. Metot sadece rota tanımının var olması
     * (URL üretimi) için gereklidir.
     */
    #[Route('/logout', name: 'admin_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Bu metoda asla ulaşılmamalı; logout, security firewall tarafından ele alınır.');
    }
}
