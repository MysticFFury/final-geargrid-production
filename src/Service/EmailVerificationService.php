<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

class EmailVerificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private string $senderEmail,
        private string $appUrl,
        private string $mailerDsn,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function sendVerificationEmail(User $user, string $verificationToken): void
    {
        $this->ensureMailerIsConfigured();

        $verificationUrl = $this->buildVerificationUrl($verificationToken);
        $recipient = $user->getEmail();

        if ($recipient === null || $recipient === '') {
            throw new \InvalidArgumentException('Cannot send verification email: user has no email address.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->senderEmail, 'GearGrid'))
            ->to($recipient)
            ->subject('Verify your GearGrid account')
            ->htmlTemplate('email/verification_email.html.twig')
            ->context([
                'user' => $user,
                'verificationUrl' => $verificationUrl,
                'verificationToken' => $verificationToken,
            ]);

        $this->mailer->send($email);
    }

    /**
     * Issue a new token and send a verification email (e.g. after failed login for unverified customers).
     */
    public function sendFreshVerificationEmail(User $user, EntityManagerInterface $entityManager): EmailSendResult
    {
        if ($user->isVerified() === true) {
            return EmailSendResult::failure('This account is already verified.');
        }

        if (!$this->isMailerConfigured()) {
            return EmailSendResult::failure(MailerErrorMessage::fromThrowable(
                new \RuntimeException('Mailer DSN is null://null — configure MAILER_DSN in .env.local')
            ));
        }

        $token = self::generateToken();
        $user->setVerificationToken($token);
        $entityManager->flush();

        try {
            $this->sendVerificationEmail($user, $token);

            return EmailSendResult::success();
        } catch (TransportExceptionInterface|\Throwable $e) {
            $this->logger?->error('Verification email failed', [
                'to' => $user->getEmail(),
                'exception' => $e->getMessage(),
            ]);

            return EmailSendResult::failure(MailerErrorMessage::fromThrowable($e));
        }
    }

    public function isMailerConfigured(): bool
    {
        $dsn = trim($this->mailerDsn);

        return $dsn !== '' && !str_starts_with($dsn, 'null://');
    }

    private function ensureMailerIsConfigured(): void
    {
        if ($this->isMailerConfigured()) {
            return;
        }

        throw new \RuntimeException(
            'MAILER_DSN is not configured (null://null). Copy .env.local.example to .env.local, set your Brevo API key and MAILER_FROM, then restart the web server.'
        );
    }

    private function buildVerificationUrl(string $verificationToken): string
    {
        if ($this->requestStack->getCurrentRequest() === null) {
            $this->applyRouterContextFromAppUrl();
        }

        return $this->urlGenerator->generate(
            'app_verify_email',
            ['token' => $verificationToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    private function applyRouterContextFromAppUrl(): void
    {
        $parts = parse_url($this->appUrl);
        if ($parts === false || !isset($parts['host'])) {
            return;
        }

        $context = new RequestContext();
        $context->setScheme($parts['scheme'] ?? 'http');
        $context->setHost($parts['host']);

        $isHttps = ($parts['scheme'] ?? 'http') === 'https';
        $port = $parts['port'] ?? ($isHttps ? 443 : 80);
        if ($isHttps) {
            $context->setHttpsPort((int) $port);
        } else {
            $context->setHttpPort((int) $port);
        }

        $this->urlGenerator->setContext($context);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
