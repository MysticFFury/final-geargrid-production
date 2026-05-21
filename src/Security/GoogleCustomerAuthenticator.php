<?php

namespace App\Security;

use App\Entity\User;
use App\Service\EmailVerificationService;
use App\Service\MailerErrorMessage;
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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Google OAuth for public customers: register new ROLE_USER accounts or sign in existing customers.
 */
class GoogleCustomerAuthenticator extends OAuth2Authenticator
{
    public const SESSION_SIGNUP_PENDING = 'google_customer_signup_pending';

    public const SESSION_SIGNUP_EMAIL = 'google_customer_signup_email';

    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerificationService $emailVerificationService
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $flow = $request->getSession()->get(GoogleOAuthFlow::SESSION_KEY);
        
        return $request->attributes->get('_route') === 'connect_google_check'
            && in_array($flow, [
                GoogleOAuthFlow::CUSTOMER,
                GoogleOAuthFlow::CUSTOMER_LOGIN,
                GoogleOAuthFlow::CUSTOMER_REGISTER
            ], true);
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google_customer');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client, $request) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);
                $email = $googleUser->getEmail();
                $flow = $request->getSession()->get(GoogleOAuthFlow::SESSION_KEY);

                $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

                if ($existingUser) {
                    if ($flow === GoogleOAuthFlow::CUSTOMER_REGISTER) {
                        throw new CustomUserMessageAccountStatusException('This account is already taken. Please sign in instead.');
                    }
                    if (!$existingUser->isActive()) {
                        throw new CustomUserMessageAccountStatusException(AccountStatusMessage::INACTIVE);
                    }

                    $roles = $existingUser->getRoles();
                    $isStaffOrAdmin = in_array('ROLE_STAFF', $roles) || in_array('ROLE_ADMIN', $roles);

                    if (!$isStaffOrAdmin && $existingUser->isVerified() !== true) {
                        $result = $this->emailVerificationService->sendFreshVerificationEmail(
                            $existingUser,
                            $this->entityManager
                        );
                        throw new CustomUserMessageAccountStatusException(
                            $result->sent
                                ? AccountStatusMessage::verifyEmailRequired($email)
                                : ($result->userMessage ?? AccountStatusMessage::VERIFY_EMAIL_SEND_FAILED)
                        );
                    }

                    return $existingUser;
                }

                if ($flow === GoogleOAuthFlow::CUSTOMER_LOGIN) {
                    throw new CustomUserMessageAccountStatusException('Account not found. Please register first.');
                }

                $user = new User();
                $user->setEmail($email);
                $user->setName($googleUser->getName() ?? explode('@', $email)[0]);
                $user->setRoles([]);
                $user->setIsVerified(false);

                $verificationToken = EmailVerificationService::generateToken();
                $user->setVerificationToken($verificationToken);

                $randomPassword = bin2hex(random_bytes(16));
                $user->setPassword($this->passwordHasher->hashPassword($user, $randomPassword));

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $session = $request->getSession();
                $session->set(self::SESSION_SIGNUP_PENDING, true);
                $session->set(self::SESSION_SIGNUP_EMAIL, $email);

                try {
                    $this->emailVerificationService->sendVerificationEmail($user, $verificationToken);
                } catch (TransportExceptionInterface $e) {
                    $session->set(self::SESSION_SIGNUP_PENDING . '_email_failed', true);
                    $session->set(self::SESSION_SIGNUP_PENDING . '_email_error', MailerErrorMessage::fromThrowable($e));
                } catch (\Exception $e) {
                    $session->set(self::SESSION_SIGNUP_PENDING . '_email_failed', true);
                    $session->set(self::SESSION_SIGNUP_PENDING . '_email_error', MailerErrorMessage::fromThrowable($e));
                }

                throw new CustomUserMessageAccountStatusException(AccountStatusMessage::REGISTRATION_VERIFY_EMAIL);
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $request->getSession()->remove(GoogleOAuthFlow::SESSION_KEY);

        $user = $token->getUser();
        
        if (in_array('ROLE_STAFF', $user->getRoles()) || in_array('ROLE_ADMIN', $user->getRoles())) {
            return new RedirectResponse($this->router->generate('app_dashboard'));
        }

        return new RedirectResponse($this->router->generate('app_customer_landing'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $session = $request->getSession();
        $flow = $session->get(GoogleOAuthFlow::SESSION_KEY);
        $session->remove(GoogleOAuthFlow::SESSION_KEY);

        if ($session->get(self::SESSION_SIGNUP_PENDING)) {
            $signupEmail = $session->get(self::SESSION_SIGNUP_EMAIL, 'your email');
            $session->remove(self::SESSION_SIGNUP_PENDING);
            $session->remove(self::SESSION_SIGNUP_EMAIL);

            if ($session->get(self::SESSION_SIGNUP_PENDING . '_email_failed')) {
                $emailError = $session->get(self::SESSION_SIGNUP_PENDING . '_email_error', 'Use "Resend verification" on the login page.');
                $session->remove(self::SESSION_SIGNUP_PENDING . '_email_failed');
                $session->remove(self::SESSION_SIGNUP_PENDING . '_email_error');
                $session->getFlashBag()->add(
                    'warning',
                    sprintf(
                        'Account created, but the verification email could not be sent to %s. %s',
                        $signupEmail,
                        $emailError
                    )
                );
            } else {
                $session->getFlashBag()->add(
                    'success',
                    sprintf(
                        'Account created! We sent a verification link to %s. Open that email and click the link before signing in.',
                        $signupEmail
                    )
                );
            }

            return new RedirectResponse($this->router->generate('app_login'));
        }

        $session->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        if ($flow === GoogleOAuthFlow::CUSTOMER_REGISTER) {
            return new RedirectResponse($this->router->generate('app_register'));
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }
}
