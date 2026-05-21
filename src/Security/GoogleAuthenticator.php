<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check'
            && $request->getSession()->get(GoogleOAuthFlow::SESSION_KEY) === GoogleOAuthFlow::STAFF;
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google_staff');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);
                $email = $googleUser->getEmail();

                // 1. Check if user exists in the GearGrid database
                $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

                if (!$existingUser) {
                    throw new CustomUserMessageAccountStatusException(AccountStatusMessage::NOT_REGISTERED);
                }

                if (!$existingUser->isActive()) {
                    throw new CustomUserMessageAccountStatusException(AccountStatusMessage::INACTIVE);
                }

                $roles = $existingUser->getRoles();
                if (!in_array('ROLE_ADMIN', $roles, true) && !in_array('ROLE_STAFF', $roles, true)) {
                    throw new CustomUserMessageAccountStatusException(AccountStatusMessage::GOOGLE_STAFF_ONLY);
                }

                return $existingUser;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $request->getSession()->remove(GoogleOAuthFlow::SESSION_KEY);

        // Redirect based on user roles
        $user = $token->getUser();
        
        // Staff and Admin go to dashboard
        if (in_array('ROLE_STAFF', $user->getRoles()) || in_array('ROLE_ADMIN', $user->getRoles())) {
            return new RedirectResponse($this->router->generate('app_dashboard'));
        }
        
        // Other users go to customer landing
        return new RedirectResponse($this->router->generate('app_customer_landing'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->remove(GoogleOAuthFlow::SESSION_KEY);
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->router->generate('app_login'));
    }
}