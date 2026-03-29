<?php
/**
 * GitHub webhook endpoint to trigger git pull via cPanel UAPI.
 * Configure GIT_WEBHOOK_SECRET in .env and in GitHub repo Settings > Webhooks.
 */

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/functions.php';

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

// Call cPanel UAPI to pull the repository
$cpanelUser = env('CPANEL_USER');
$cpanelToken = env('CPANEL_API_TOKEN');
$repoPath = '/home/seanw2/public_html/councilradar.ca';

$url = 'https://localhost:2083/execute/VersionControl/update'
     . '?repository_root=' . urlencode($repoPath);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => [
        'Authorization: cpanel ' . $cpanelUser . ':' . $cpanelToken,
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

logMessage('deploy.log', "Deploy triggered - HTTP $httpCode" . ($error ? " Error: $error" : ''));

if ($error) {
    logMessage('deploy.log', "cURL error: $error");
    http_response_code(500);
    echo "Deploy failed: $error";
    exit;
}

$data = json_decode($response, true);
$lastCommit = $data['data']['last_update']['identifier'] ?? 'unknown';
logMessage('deploy.log', "Update result: status={$data['status']} commit=$lastCommit");

if (isset($data['status']) && $data['status'] == 1) {
    http_response_code(200);
    echo 'OK';
} else {
    $msg = $data['errors'][0] ?? $response;
    logMessage('deploy.log', "Update failed: $msg");
    http_response_code(200);
    echo "Deploy result: $msg";
}
