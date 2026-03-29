<?php
/**
 * CouncilRadar - AJAX Newsletter Signup Endpoint
 *
 * Creates a subscriber without a password (free newsletter signup).
 * Expects JSON POST: {email, name, organization, consent}
 */

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['success' => false, 'error' => 'Invalid request body.'], 400);
}

$email   = trim($input['email'] ?? '');
$name    = trim($input['name'] ?? '');
$org     = trim($input['organization'] ?? '');
$consent = $input['consent'] ?? false;

// Validate email
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'A valid email address is required.'], 422);
}

// Validate consent
if (!$consent) {
    jsonResponse(['success' => false, 'error' => 'You must consent to receive alerts.'], 422);
}

// Rate limit
if (!checkRateLimit('signup', RATE_LIMIT_SIGNUP_MAX, RATE_LIMIT_SIGNUP_WINDOW)) {
    jsonResponse(['success' => false, 'error' => 'Too many signup attempts. Please try again later.'], 429);
}

recordRateLimit('signup');

$db = DB::get();

// Check for existing subscriber
$stmt = $db->prepare('SELECT id, active FROM subscribers WHERE email = ?');
$stmt->execute([strtolower($email)]);
$existing = $stmt->fetch();

if ($existing) {
    if ($existing['active']) {
        jsonResponse(['success' => true, 'message' => 'You are already subscribed. Check your inbox for alerts.']);
    }
    // Reactivate an inactive subscriber
    $stmt = $db->prepare(
        'UPDATE subscribers SET active = 1, unsubscribed_at = NULL, consent_date = NOW(),
         consent_ip = ?, consent_method = ?, consent_text = ?, name = COALESCE(?, name), organization = COALESCE(?, organization)
         WHERE id = ?'
    );
    $consentText = 'Yes, I want to receive municipal council agenda alerts from CouncilRadar. I understand I can unsubscribe at any time.';
    $stmt->execute([
        getClientIp(),
        'web_form',
        $consentText,
        $name ?: null,
        $org ?: null,
        $existing['id'],
    ]);
    jsonResponse(['success' => true, 'message' => 'Welcome back. Your subscription has been reactivated.']);
}

// Create new subscriber (no password)
$consentText = 'Yes, I want to receive municipal council agenda alerts from CouncilRadar. I understand I can unsubscribe at any time.';
$verifyToken = generateToken();
$now = date('Y-m-d H:i:s');

$stmt = $db->prepare(
    'INSERT INTO subscribers
        (email, name, organization, verify_token, consent_date, consent_method,
         consent_ip, consent_text, tier, active, email_verified, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    strtolower($email),
    $name ?: null,
    $org ?: null,
    $verifyToken,
    $now,
    'web_form',
    getClientIp(),
    $consentText,
    'free',
    1,
    0,
    $now,
]);

logMessage('email.log', "Newsletter signup for $email - verify token: $verifyToken");
logMessage('email.log', "Verify link: " . SITE_URL . "/verify.php?token=$verifyToken");

jsonResponse(['success' => true, 'message' => 'You are signed up. Check your email to confirm your address.']);
