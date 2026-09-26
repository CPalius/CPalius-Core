<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Settings\SettingsRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, SettingsRegistry $settings): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $siteName = trim((string) $settings->getForLocale('core.site_name', $request->getLocale(), ''));

        return $this->render('admin/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'siteName' => $siteName !== '' ? $siteName : 'CPalius CMF',
        ]);
    }

    /**
     * Empty body: logout is handled by the firewall before this route. Exists for URL generation.
     */
    #[Route('/logout', name: 'admin_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('This method should never be reached; logout is handled by the security firewall.');
    }
}
