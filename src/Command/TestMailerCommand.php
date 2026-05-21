<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Entity\User;
use App\Service\EmailVerificationService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:test-mailer',
    description: 'Send a test email to verify Brevo / MAILER_DSN configuration',
)]
class TestMailerCommand extends Command
{
    public function __construct(
        private MailerInterface $mailer,
        private EmailVerificationService $emailVerificationService,
        private string $senderEmail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::OPTIONAL, 'Recipient email', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = $input->getArgument('to') ?? $this->senderEmail;

        $io->title('GearGrid mailer test');
        $io->text(sprintf('From: %s', $this->senderEmail));
        $io->text(sprintf('To: %s', $to));

        $email = (new Email())
            ->from(new Address($this->senderEmail, 'GearGrid'))
            ->to($to)
            ->subject('GearGrid mailer test')
            ->text('If you receive this, MAILER_DSN is working.');

        try {
            $this->mailer->send($email);
            $io->success('Plain test email accepted by the transport.');

            $user = new User();
            $user->setEmail($to);
            $user->setName('Test User');
            $this->emailVerificationService->sendVerificationEmail(
                $user,
                EmailVerificationService::generateToken()
            );
            $io->success('Verification template email sent. Check inbox and spam.');

            return Command::SUCCESS;
        } catch (TransportExceptionInterface $e) {
            $io->error('Transport failed: '.$e->getMessage());
            $this->printBrevoHints($io, $e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    private function printBrevoHints(SymfonyStyle $io, string $message): void
    {
        if (str_contains($message, 'unrecognised IP') || str_contains($message, 'authorized_ips') || str_contains($message, 'IP address')) {
            $io->section('Fix: Brevo IP restriction');
            $io->listing([
                'Open https://app.brevo.com → Settings → Security → Authorized IPs',
                'Add your current public IP, or disable IP restriction for development',
            ]);
        }

        if (str_contains(strtolower($message), 'sender') || str_contains($message, 'from')) {
            $io->section('Fix: Sender not verified');
            $io->listing([
                'Open https://app.brevo.com → Senders & IP → Senders',
                'Add and verify '.$this->senderEmail.' (Brevo sends a confirmation to that inbox)',
            ]);
        }
    }
}
