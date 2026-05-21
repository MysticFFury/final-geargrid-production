<?php

namespace App\Security;

/**
 * Tracks whether Google OAuth was started from staff login or customer registration.
 * Both flows share one redirect URI (/connect/google/check) for Google Cloud Console.
 */
final class GoogleOAuthFlow
{
    public const SESSION_KEY = 'google_oauth_flow';

    public const STAFF = 'staff';

    public const CUSTOMER = 'customer';
    public const CUSTOMER_LOGIN = 'customer_login';
    public const CUSTOMER_REGISTER = 'customer_register';
}
