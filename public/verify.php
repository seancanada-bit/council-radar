<?php
/**
 * CouncilRadar - Email Verification + Password Setup
 *
 * Flow:
 *   1. User signs up on homepage (email only, no password)
 *   2. Welcome email contains link to /verify.php?token=xxx
 *   3. This page verifies the email AND lets them set a password
 *   4. On submit, they're logged in and redirected to dashboard
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../templates/layout.php';

Session::startSession();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$verified = false;
$subscriber = null;

if (empty($token)) {
    flash('error', 'Invalid verification link.');
    redirect('/login.php');
}

// Look up the subscriber by verify token
$db = DB::get();
$stmt = $db->prepare('SELECT id, email, name, email_verified, password_hash FROM subscribers WHERE verify_token = ?');
$stmt->execute([$token]);
$subscriber = $stmt->fetch();

if (!$subscriber) {
    // Token not found - might be already verified
    flash('error', 'This verification link has already been used or is invalid.');
    redirect('/login.php');
}

// Handle form submission (setting password)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // Verify email + set password + clear token in one update
        $stmt = $db->prepare(
            'UPDATE subscribers SET email_verified = 1, password_hash = ?, verify_token = NULL WHERE id = ?'
        );
        $stmt->execute([$hash, $subscriber['id']]);

        // Log them in immediately
        Session::login($subscriber['id']);

        flash('success', 'Your account is set up. Welcome to CouncilRadar.');
        redirect('/dashboard.php');
    }
}

layoutHeader('Verify Your Email', 'Complete your CouncilRadar account setup.');
?>

<section class="auth-page">
    <div class="container container-narrow">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1>Welcome to CouncilRadar</h1>
                <p class="auth-subtitle">Set a password to complete your account setup.</p>
            </div>

            <div class="auth-verified-badge">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="vertical-align: middle; margin-right: 6px;">
                    <circle cx="10" cy="10" r="10" fill="#38a169"/>
                    <path d="M6 10l3 3 5-5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php echo h($subscriber['email']); ?> - verified
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p><?php echo h($err); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/verify.php" class="auth-form" novalidate>
                <?php echo csrfField(); ?>
                <input type="hidden" name="token" value="<?php echo h($token); ?>">

                <div class="form-group">
                    <label for="password">Create Password</label>
                    <input type="password" id="password" name="password" required minlength="8" placeholder="Minimum 8 characters">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                </div>

                <button type="submit" class="btn btn-primary btn-full">Set Password and Continue</button>
            </form>
        </div>
    </div>
</section>

<?php layoutFooter(); ?>
