<?php
/**
 * GitHub webhook endpoint - acknowledges push events.
 * Actual deployment is handled by a cron job that runs git pull every 5 minutes.
 */

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/functions.php';

$secret = env('GIT_WEBHOOK_SECRET');

if ($secret) {
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    if (!hash_equals($expected, $signature)) {
        http_response_code(403);
        echo 'Invalid signature';
        exit;
    }
}

logMessage('deploy.log', "Push notification received - cron will pull shortly");
http_response_code(200);
echo 'OK';
