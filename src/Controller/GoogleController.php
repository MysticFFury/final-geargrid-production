<?php

namespace App\Controller;

use App\Security\GoogleOAuthFlow;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class GoogleController extends AbstractController
{
    #[Route('/connect/google', name: 'connect_google')]
    public function connectAction(Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $request->getSession()->set(GoogleOAuthFlow::SESSION_KEY, GoogleOAuthFlow::STAFF);

        return $clientRegistry
            ->getClient('google_staff')
            ->redirect(['email', 'profile']);
    }

    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function connectCheckAction(Request $request): void
    {
        // Handled by GoogleAuthenticator / GoogleCustomerAuthenticator.
    }

    #[Route('/connect/google/login', name: 'connect_google_login')]
    public function connectLoginAction(Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $request->getSession()->set(GoogleOAuthFlow::SESSION_KEY, GoogleOAuthFlow::CUSTOMER_LOGIN);

        return $clientRegistry
            ->getClient('google_customer')
            ->redirect(['email', 'profile']);
    }

    #[Route('/connect/google/register', name: 'connect_google_register')]
    public function connectRegisterAction(Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $request->getSession()->set(GoogleOAuthFlow::SESSION_KEY, GoogleOAuthFlow::CUSTOMER_REGISTER);

        return $clientRegistry
            ->getClient('google_customer')
            ->redirect(['email', 'profile']);
    }
}
