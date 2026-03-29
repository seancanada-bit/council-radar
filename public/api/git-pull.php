<?php
/**
 * GitHub webhook endpoint to trigger git pull on push events.
 * Configure GIT_WEBHOOK_SECRET in .env and in GitHub repo Settings > Webhooks.
 */

require_once __DIR__ . '/../../app/config.php';

$secret = env('GIT_WEBHOOK_SECRET');

// Verify webhook signature if secret is configured
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

$repoPath = '/home/seanw2/councilradar';
$output = shell_exec("cd $repoPath && /usr/local/cpanel/3rdparty/bin/git pull origin main 2>&1");

http_response_code(200);
header('Content-Type: text/plain');
echo $output;
