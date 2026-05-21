<?php

/**
 * Prints the exact redirect URI(s) to add in Google Cloud Console.
 * Run: php debug_google_oauth.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/.env');

$base = rtrim($_ENV['DEFAULT_URI'] ?? 'http://127.0.0.1:8000', '/');
$callback = $base . '/connect/google/check';

echo "=== Google OAuth redirect URIs ===\n\n";
echo "Add this URI in Google Cloud Console → Credentials → your OAuth client\n";
echo "→ Authorized redirect URIs:\n\n";
echo "  {$callback}\n\n";

if (str_contains($base, '127.0.0.1')) {
    echo "If you open the site as http://localhost:8000, also add:\n";
    echo "  http://localhost:8000/connect/google/check\n\n";
} elseif (str_contains($base, 'localhost')) {
    echo "If you open the site as http://127.0.0.1:8000, also add:\n";
    echo "  http://127.0.0.1:8000/connect/google/check\n\n";
}

echo "Staff login and customer register both use this same callback.\n";
echo "CLIENT_ID: " . substr($_ENV['GOOGLE_CLIENT_ID'] ?? 'NOT SET', 0, 30) . "...\n";
