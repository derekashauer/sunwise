<?php
/**
 * Application Configuration
 */

// Load .env file if exists
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// Configuration constants
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true');
define('APP_URL', getenv('APP_URL') ?: 'https://sunwise.dev');

// Database
define('DB_PATH', __DIR__ . '/../../data/sunwise.db');

// JWT
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'change-this-secret-in-production');
define('JWT_EXPIRY', 86400 * 7); // 7 days

// Claude API
define('CLAUDE_API_KEY', getenv('CLAUDE_API_KEY') ?: '');
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');

// VAPID for push notifications
define('VAPID_PUBLIC_KEY', getenv('VAPID_PUBLIC_KEY') ?: '');
define('VAPID_PRIVATE_KEY', getenv('VAPID_PRIVATE_KEY') ?: '');

// File uploads
define('UPLOAD_PATH', __DIR__ . '/../../uploads');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/heic']);

// Email (for magic links)
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'noreply@sunwise.dev');
define('MAIL_FROM_NAME', 'Sunwise');

// Timezone
define('DEFAULT_TIMEZONE', 'America/New_York');
date_default_timezone_set(DEFAULT_TIMEZONE);
