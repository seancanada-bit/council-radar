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
require_once __DIR__ . '/../../app/email/PostmarkClient.php';

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
$consent = $input['casl_consent'] ?? $input['consent'] ?? false;

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

$verifyUrl = SITE_URL . '/verify.php?token=' . $verifyToken;
logMessage('email.log', "Newsletter signup for $email - verify link: $verifyUrl");

// Send welcome/verification email
try {
    $pm = new PostmarkClient();
    $htmlBody = '
        <div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;padding:24px">
            <h1 style="color:#1a365d">Welcome to CouncilRadar</h1>
            <p>Thanks for signing up. Please confirm your email address to start receiving BC municipal agenda alerts.</p>
            <p style="margin:32px 0">
                <a href="' . $verifyUrl . '" style="background:#2b6cb0;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block">
                    Confirm Email Address
                </a>
            </p>
            <p style="color:#666;font-size:14px">Or copy this link into your browser:<br>' . $verifyUrl . '</p>
            <hr style="border:none;border-top:1px solid #e2e8f0;margin:32px 0">
            <p style="color:#999;font-size:12px">
                CouncilRadar - Municipal Agenda Monitoring<br>
                Operated by Pacific Logo Design<br>
                ' . CASL_MAILING_ADDRESS . '<br><br>
                You received this because you signed up at councilradar.ca.<br>
                If you did not sign up, you can ignore this email.
            </p>
        </div>';

    $textBody = "Welcome to CouncilRadar\n\nPlease confirm your email address:\n$verifyUrl\n\n"
        . "CouncilRadar - Municipal Agenda Monitoring\nOperated by Pacific Logo Design\n" . CASL_MAILING_ADDRESS;

    $pm->send($email, 'Confirm your CouncilRadar subscription', $htmlBody, $textBody);
} catch (Exception $e) {
    logMessage('email.log', "Welcome email failed for $email: " . $e->getMessage());
}

jsonResponse(['success' => true, 'message' => 'You are signed up. Check your email to confirm your address.']);
