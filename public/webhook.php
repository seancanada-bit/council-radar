<?php
/**
 * Postmark Bounce/Complaint Webhook Handler
 *
 * Receives POST requests from Postmark when a bounce or spam complaint occurs.
 * Deactivates the subscriber on hard bounces and spam complaints.
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// Read and decode JSON body
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!$data) {
    logMessage('webhook.log', 'Invalid JSON payload received');
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

// Basic validation - Postmark payloads include RecordType and Email
if (!isset($data['RecordType']) || !isset($data['Email'])) {
    logMessage('webhook.log', 'Missing required fields (RecordType or Email) - possible non-Postmark request');
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

$recordType = $data['RecordType'];
$email      = $data['Email'];
$type       = $data['Type'] ?? '';
$description = $data['Description'] ?? '';
$messageId  = $data['MessageID'] ?? '';

logMessage('webhook.log', "Received: RecordType=$recordType Type=$type Email=$email MessageID=$messageId Description=$description");

$shouldDeactivate = false;
$reason = '';

switch ($recordType) {
    case 'Bounce':
        if ($type === 'HardBounce') {
            $shouldDeactivate = true;
            $reason = 'hard_bounce';
        }
        break;

    case 'SpamComplaint':
        $shouldDeactivate = true;
        $reason = 'spam_complaint';
        break;

    default:
        // Log other event types (soft bounces, etc.) but take no action
        logMessage('webhook.log', "No action for RecordType=$recordType Type=$type Email=$email");
        break;
}

if ($shouldDeactivate) {
    try {
        $db = DB::get();
        $stmt = $db->prepare('UPDATE subscribers SET active = 0 WHERE email = ? AND active = 1');
        $stmt->execute([$email]);
        $affected = $stmt->rowCount();

        logMessage('webhook.log', "DEACTIVATED ($reason): $email - $affected row(s) affected");
    } catch (Exception $e) {
        logMessage('webhook.log', "ERROR deactivating $email: " . $e->getMessage());
    }
}

// Always return 200 so Postmark does not retry
http_response_code(200);
echo 'OK';
