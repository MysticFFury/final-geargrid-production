<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Log;
use App\Form\RegistrationFormType;
use App\Service\EmailVerificationService;
use App\Service\MailerErrorMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class RegistrationController extends AbstractController
{
    public function __construct(
        private EmailVerificationService $emailVerificationService,
    ) {
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        AuthenticationUtils $authenticationUtils
    ): Response
    {
        $user = new User();
        $currentUser = $this->getUser();
        $isAdmin = $currentUser && $this->isGranted('ROLE_ADMIN');
        
        $form = $this->createForm(RegistrationFormType::class, $user, [
            'is_admin' => $isAdmin
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // encode the plain password
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            // Check if admin is creating the user or public user
            $currentUser = $this->getUser();
            $isAdmin = $currentUser && $this->isGranted('ROLE_ADMIN');
            
            // If admin is creating user, use selected roles. Otherwise, set default role as USER (public registration)
            if (!$isAdmin) {
                // Public user registration - set as regular user (ROLE_USER is automatically added by User entity)
                $user->setRoles([]);
                
                // No verification required - auto-verify public users
                $user->setIsVerified(true);
            } elseif (empty($user->getRoles()) || (count($user->getRoles()) === 1 && in_array('ROLE_USER', $user->getRoles()))) {
                // Admin created user without selecting roles - default to STAFF
                $user->setRoles(['ROLE_STAFF']);
                // Admin-created users are automatically verified
                $user->setIsVerified(true);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            // Broadcast to WebSocket server
            try {
                $wsUrl = $_ENV['WEBSOCKET_SERVER_URL'] ?? 'http://127.0.0.1:8085/broadcast';
                $ch = curl_init($wsUrl);
                $payload = json_encode([
                    'event' => 'new-user',
                    'data' => [
                        'userId' => $user->getId(),
                        'name' => $user->getName() ?: 'Unknown User',
                        'email' => $user->getEmail(),
                        'roles' => $user->getRoles(),
                        'isActive' => $user->isActive(),
                        'message' => "New user {$user->getEmail()} registered!"
                    ]
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Exception $e) {
                // Silence network errors
            }

            // Log user creation if admin is creating it
            if ($isAdmin) {
                $log = new Log();
                $log->setAction('CREATE')
                    ->setMessage("Admin '{$currentUser->getUserIdentifier()}' created user '{$user->getUserIdentifier()}'")
                    ->setStatus('active')
                    ->setUserName($currentUser->getUserIdentifier())
                    ->setUserRole(implode(', ', $currentUser->getRoles()))
                    ->setEntity('User')
                    ->setCreatedAt(new \DateTime());
                $entityManager->persist($log);
                $entityManager->flush();

                $this->addFlash('success', 'User "' . $user->getName() . '" has been registered successfully!');
                // Redirect admin to user list page
                return $this->redirectToRoute('app_user_index');
            } else {
                // Public registration: Auto-verified, login now.
                $this->addFlash('success', 'Account created successfully! You can now sign in.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }
}
