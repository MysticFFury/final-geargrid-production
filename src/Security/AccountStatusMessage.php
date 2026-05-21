<?php

namespace App\Security;

final class AccountStatusMessage
{
    public const INACTIVE = 'Your account is inactive. Please contact an administrator.';

    public const NOT_REGISTERED = 'No account found for this email. Ask an administrator to create your account before signing in with Google.';

    public const GOOGLE_STAFF_ONLY = 'Google sign-in is only available for staff and admin accounts. Please register or sign in with email and password.';

    public const GOOGLE_USE_STAFF_LOGIN = 'This email is a staff or admin account. Please sign in with your email and password instead of Google.';

    public const VERIFY_EMAIL_SEND_FAILED = 'Your email is not verified yet. We could not send the verification email. Try again or use "Resend verification" on the login page.';

    public const REGISTRATION_VERIFY_EMAIL = 'Account created! Please check your email to verify your account before signing in.';

    public static function verifyEmailRequired(string $email): string
    {
        return sprintf(
            'Your email is not verified yet. We just sent a new verification link to %s. Check your inbox and spam folder, then click the link before signing in again.',
            $email
        );
    }
}
