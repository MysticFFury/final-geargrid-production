<?php

namespace App\Security;

use App\Entity\User;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private EmailVerificationService $emailVerificationService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(AccountStatusMessage::INACTIVE);
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Verification check removed globally.
    }

    private function isUnverifiedCustomer(User $user): bool
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_STAFF', $roles, true)) {
            return false;
        }

        return $user->isVerified() !== true;
    }

    private function denyUnverifiedCustomer(User $user): void
    {
        $result = $this->emailVerificationService->sendFreshVerificationEmail($user, $this->entityManager);

        throw new CustomUserMessageAccountStatusException(
            $result->sent
                ? AccountStatusMessage::verifyEmailRequired((string) $user->getEmail())
                : ($result->userMessage ?? AccountStatusMessage::VERIFY_EMAIL_SEND_FAILED)
        );
    }
}
