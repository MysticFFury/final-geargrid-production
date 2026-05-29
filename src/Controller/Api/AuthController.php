<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api')]
class AuthController extends AbstractController
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerificationService $emailVerificationService,
    ) {
    }

    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['email']) || !isset($data['password'])) {
            return new JsonResponse([
                'error' => 'Email and password are required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $email = $data['email'];
        $password = $data['password'];

        // Find user by email
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            return new JsonResponse([
                'error' => 'Invalid credentials'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Match web UserChecker: only customers must verify email (staff/admin skip this).
        $roles = $user->getRoles();
        $isCustomerOnly =
            !in_array('ROLE_ADMIN', $roles, true)
            && !in_array('ROLE_STAFF', $roles, true);

        // Auto-verify if they try to log in and were legacy unverified
        if ($isCustomerOnly && $user->isVerified() !== true) {
            $user->setIsVerified(true);
            $this->entityManager->flush();
        }

        // Verify password
        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse([
                'error' => 'Invalid credentials'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Generate JWT token
        $token = $this->jwtManager->create($user);

        return new JsonResponse([
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles()
            ]
        ]);
    }

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $requiredFields = ['email', 'password', 'name'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return new JsonResponse([
                    'error' => ucfirst($field) . ' is required'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        // Check if user already exists
        $userRepository = $this->entityManager->getRepository(User::class);
        $existingUser = $userRepository->findOneBy(['email' => $data['email']]);
        
        if ($existingUser) {
            return new JsonResponse([
                'error' => 'Email already exists'
            ], Response::HTTP_CONFLICT);
        }

        // Create new user
        $user = new User();
        $user->setEmail($data['email']);
        $user->setName($data['name']);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, $data['password'])
        );
        $user->setRoles(['ROLE_USER']);

        $user->setIsVerified(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

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
                    'message' => "New app user {$user->getEmail()} registered!"
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

        // Generate JWT token immediately
        $token = $this->jwtManager->create($user);

        return new JsonResponse([
            'message' => 'Account created successfully',
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles()
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['email'])) {
            return new JsonResponse([
                'error' => 'Email is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $email = trim((string) $data['email']);
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user || $user->isVerified()) {
            return new JsonResponse([
                'message' => 'If an account with this email exists and is unverified, a verification email has been sent.',
            ]);
        }

        $newToken = EmailVerificationService::generateToken();
        $user->setVerificationToken($newToken);
        $this->entityManager->flush();

        try {
            $this->emailVerificationService->sendVerificationEmail($user, $newToken);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to send verification email. Please try again later.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse([
            'message' => 'Verification email sent. Please check your inbox.',
        ]);
    }

    #[Route('/google', name: 'api_google', methods: ['POST'])]
    public function googleLogin(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['email'])) {
            return new JsonResponse([
                'error' => 'Email is required for Google login'
            ], Response::HTTP_BAD_REQUEST);
        }

        $email = $data['email'];
        $name = $data['name'] ?? explode('@', $email)[0];

        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setName($name);
            $randomPassword = bin2hex(random_bytes(16));
            $user->setPassword($this->passwordHasher->hashPassword($user, $randomPassword));
            $user->setRoles([]);
            $user->setIsVerified(true);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

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
                        'message' => "New Google app user {$user->getEmail()} registered!"
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

            // Generate JWT token
            $token = $this->jwtManager->create($user);

            return new JsonResponse([
                'message' => 'Google account created and authenticated',
                'token' => $token,
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'name' => $user->getName(),
                    'roles' => $user->getRoles()
                ]
            ], Response::HTTP_CREATED);
        }

        if (!$user->isActive()) {
            return new JsonResponse([
                'error' => 'Your account is inactive. Please contact an administrator.',
            ], Response::HTTP_FORBIDDEN);
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_STAFF', $roles, true)) {
            return new JsonResponse([
                'error' => 'This email is a staff account and cannot use customer Google sign-in.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Auto-verify if they were legacy unverified
        if ($user->isVerified() !== true) {
            $user->setIsVerified(true);
            $this->entityManager->flush();
        }

        // Generate JWT token
        $token = $this->jwtManager->create($user);

        return new JsonResponse([
            'message' => 'Google authentication successful',
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles()
            ]
        ], Response::HTTP_OK);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function getCurrentUser(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user) {
            return new JsonResponse([
                'error' => 'Not authenticated'
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles()
            ]
        ]);
    }
}
