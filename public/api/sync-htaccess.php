<?php
/**
 * Syncs the domain root .htaccess with the security headers and rewrite rules.
 * Called after deploy to ensure the domain-level .htaccess stays in sync.
 */

require_once __DIR__ . '/../../app/config.php';

$secret = env('GIT_WEBHOOK_SECRET');

// Verify request
if ($secret) {
    $provided = $_GET['key'] ?? '';
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$htaccess = <<<'HTACCESS'
# Security headers
<IfModule mod_headers.c>
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(self)"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'"
</IfModule>

RewriteEngine On

RewriteCond %{REQUEST_URI} ^/\.well-known/ [NC]
RewriteRule ^ - [L]

RewriteRule ^(.*)$ /home/seanw2/councilradar/public/$1 [L]
HTACCESS;

$path = '/home/seanw2/public_html/councilradar.ca/.htaccess';
$result = file_put_contents($path, $htaccess);

header('Content-Type: text/plain');
if ($result !== false) {
    echo "OK - wrote $result bytes to $path";
} else {
    http_response_code(500);
    echo "FAILED to write to $path";
}
