<?php
/**
 * CouncilRadar Configuration
 */

// Load environment variables from .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Helper to get env var with optional default
function env(string $key, string $default = ''): string {
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

// Database
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'councilradar'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));

// Postmark
define('POSTMARK_API_KEY', env('POSTMARK_API_KEY'));
define('POSTMARK_FROM_EMAIL', env('POSTMARK_FROM_EMAIL', 'alerts@councilradar.ca'));
define('POSTMARK_FROM_NAME', env('POSTMARK_FROM_NAME', 'CouncilRadar'));

// Stripe
define('STRIPE_SECRET_KEY', env('STRIPE_SECRET_KEY'));
define('STRIPE_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET'));
define('STRIPE_PROFESSIONAL_PRICE_ID', env('STRIPE_PROFESSIONAL_PRICE_ID'));
define('STRIPE_FIRM_PRICE_ID', env('STRIPE_FIRM_PRICE_ID'));

// Site
define('SITE_URL', env('SITE_URL', 'https://councilradar.ca'));
define('SITE_NAME', env('SITE_NAME', 'CouncilRadar'));
define('ADMIN_EMAIL', env('ADMIN_EMAIL'));

// CASL compliance
define('CASL_MAILING_ADDRESS', env('CASL_MAILING_ADDRESS'));
define('CASL_CONTACT_EMAIL', env('CASL_CONTACT_EMAIL'));
define('CASL_CONTACT_PHONE', env('CASL_CONTACT_PHONE'));

// Keywords for agenda parsing
define('KEYWORDS_PRIMARY', [
    'rezoning', 'rezone', 'zone change', 'zoning amendment',
    'OCP amendment', 'Official Community Plan',
    'public hearing',
    'development permit', 'subdivision',
    'development variance permit', 'DVP',
    'housing agreement', 'density bonus',
    'community amenity contribution', 'CAC',
    'development cost charge', 'DCC'
]);

define('KEYWORDS_SECONDARY', [
    'water supply', 'water advisory', 'drought', 'snowpack',
    'budget', 'financial plan', 'tax rate',
    'bylaw amendment', 'infrastructure', 'capital plan',
    'sewer', 'wastewater', 'transportation',
    'heritage designation', 'short-term rental', 'STR',
    'building permit', 'flood', 'wildfire', 'FireSmart',
    'climate action'
]);

define('KEYWORDS_TERTIARY', [
    'park', 'parkland', 'liquor license', 'cannabis',
    'road', 'highway'
]);

// Scraper settings
define('SCRAPER_USER_AGENT', 'CouncilRadar/1.0 (councilradar.ca; municipal agenda monitoring)');
define('SCRAPER_REQUEST_DELAY', 2); // seconds between requests
define('SCRAPER_TIMEOUT', 30); // cURL timeout in seconds

// Session settings
define('SESSION_LIFETIME', 30 * 24 * 60 * 60); // 30 days

// Rate limiting
define('RATE_LIMIT_LOGIN_MAX', 5);
define('RATE_LIMIT_LOGIN_WINDOW', 15 * 60); // 15 minutes
define('RATE_LIMIT_SIGNUP_MAX', 3);
define('RATE_LIMIT_SIGNUP_WINDOW', 60 * 60); // 1 hour

// Timezone
date_default_timezone_set('America/Vancouver');
