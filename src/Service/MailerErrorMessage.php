<?php

namespace App\Service;

final class MailerErrorMessage
{
    public static function fromThrowable(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'null://') || str_contains($message, 'Mailer DSN')) {
            return 'Email is not configured on this server. Add MAILER_DSN to .env.local (see .env.local.example), then restart the web server.';
        }

        if (str_contains($message, 'unrecognised IP')
            || str_contains($message, 'authorized_ips')
            || str_contains($message, 'IP address')
        ) {
            return 'Brevo blocked this server IP. In Brevo go to Settings → Security → Authorized IPs, add your IP or turn off IP restriction.';
        }

        if (str_contains(strtolower($message), 'sender')
            || str_contains($message, 'not verified')
            || str_contains($message, 'From')
        ) {
            return 'The sender email is not verified in Brevo. In Brevo go to Senders & IP → Senders and verify your MAILER_FROM address.';
        }

        if (str_contains($message, 'key') || str_contains($message, '401') || str_contains($message, '403')) {
            return 'Brevo rejected the API key. Check MAILER_DSN in .env.local uses a valid API key from Brevo → SMTP & API → API keys.';
        }

        return 'Could not send the verification email right now. Check spam, try again, or use Resend verification on the login page.';
    }
}
