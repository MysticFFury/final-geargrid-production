<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EmailVerificationController extends AbstractController
{
    #[Route('/verify-email/{token}', name: 'app_verify_email')]
    public function verifyEmail(string $token, EntityManagerInterface $entityManager): Response
    {
        // Find user with this token
        $user = $entityManager->getRepository(User::class)->findOneBy(['verificationToken' => $token]);

        if (!$user) {
            $this->addFlash('error', 'Invalid or expired verification link. Please use the "Resend it" link at the bottom of the page to get a new one.');
            return $this->redirectToRoute('app_login');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Your email is already verified!');
            return $this->redirectToRoute('app_login');
        }

        // Mark user as verified and clear the token
        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $entityManager->flush();

        $this->addFlash('success', 'Email verified successfully! You can now log in.');
        return $this->redirectToRoute('app_login');
    }
}
