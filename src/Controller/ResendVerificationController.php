<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ResendVerificationController extends AbstractController
{
    public function __construct(
        private EmailVerificationService $emailVerificationService,
    ) {
    }

    #[Route('/resend-verification-email', name: 'app_resend_verification_email')]
    public function resendVerificationEmail(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $token = $request->request->get('_csrf_token');

            if (!$this->isCsrfTokenValid('resend_verification', $token)) {
                $this->addFlash('error', 'Invalid request. Please try again.');
                return $this->render('resend_verification_email.html.twig');
            }

            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if (!$user) {
                // For security, don't reveal if user exists
                $this->addFlash('info', 'If an account with this email exists and is unverified, a new verification email has been sent.');
                return $this->redirectToRoute('app_login');
            }

            if ($user->isVerified()) {
                $this->addFlash('info', 'This email is already verified! You can log in now.');
                return $this->redirectToRoute('app_login');
            }

            $result = $this->emailVerificationService->sendFreshVerificationEmail($user, $entityManager);
            if ($result->sent) {
                $this->addFlash('success', 'Verification email sent! Please check your inbox and spam folder.');
            } else {
                $this->addFlash('error', $result->userMessage ?? 'Failed to send verification email. Please try again later.');
            }

            return $this->redirectToRoute('app_login');
        }

        return $this->render('resend_verification_email.html.twig');
    }
}
