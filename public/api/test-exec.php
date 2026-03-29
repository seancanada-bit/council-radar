<?php
require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/functions.php';
$secret = env('GIT_WEBHOOK_SECRET');
if (!$secret || !hash_equals($secret, $_GET['key'] ?? '')) { http_response_code(403); exit; }

header('Content-Type: application/json');

$cpanelUser = env('CPANEL_USER');
$cpanelToken = env('CPANEL_API_TOKEN');
$repoPath = '/home/seanw2/public_html/councilradar.ca';

// Try create_or_convert_and_deploy
$url = 'https://localhost:2083/execute/VersionControl/create?repository_root=' . urlencode($repoPath) . '&type=git';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $cpanelUser . ':' . $cpanelToken],
    CURLOPT_TIMEOUT => 30,
]);
$r1 = curl_exec($ch);
curl_close($ch);

// Try retrieve (list repos)
$url2 = 'https://localhost:2083/execute/VersionControl/retrieve';
$ch2 = curl_init($url2);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $cpanelUser . ':' . $cpanelToken],
    CURLOPT_TIMEOUT => 30,
]);
$r2 = curl_exec($ch2);
curl_close($ch2);

echo json_encode([
    'create_response' => json_decode($r1, true),
    'retrieve_response' => json_decode($r2, true),
], JSON_PRETTY_PRINT);
