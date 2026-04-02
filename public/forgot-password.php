<?php
/**
 * CouncilRadar - Forgot Password
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../app/auth/Auth.php';
require_once __DIR__ . '/../app/email/PostmarkClient.php';
require_once __DIR__ . '/../templates/layout.php';

Session::startSession();

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Invalid form submission. Please try again.');
        redirect('/forgot-password.php');
    }

    $email = trim($_POST['email'] ?? '');

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $token = Auth::requestPasswordReset($email);

        if ($token) {
            $resetUrl = SITE_URL . '/reset-password.php?token=' . $token;
            logMessage('email.log', "Password reset for $email - link: $resetUrl");

            try {
                $pm = new PostmarkClient();
                $pm->send(
                    $email,
                    'Reset your CouncilRadar password',
                    '<h2>Password Reset</h2>'
                    . '<p>Click the link below to reset your password. This link expires in 1 hour.</p>'
                    . '<p><a href="' . h($resetUrl) . '" style="display:inline-block;padding:12px 24px;background:#2b6cb0;color:#fff;text-decoration:none;border-radius:6px;">Reset Password</a></p>'
                    . '<p style="color:#718096;font-size:14px;">Or copy this link: ' . h($resetUrl) . '</p>'
                    . '<p style="color:#718096;font-size:14px;">If you did not request this, you can ignore this email.</p>',
                    "Reset your CouncilRadar password\n\nReset link: {$resetUrl}\n\nThis link expires in 1 hour.",
                    'password-reset'
                );
            } catch (Exception $e) {
                logMessage('email.log', "Password reset email failed for {$email}: " . $e->getMessage());
            }
        }
    }

    $submitted = true;
}

layoutHeader('Forgot Password', 'Reset your CouncilRadar account password.');
$flash = getFlash();
?>

<section class="auth-page">
    <div class="container container-narrow">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1>Forgot Password</h1>
                <p class="auth-subtitle">We'll send you a link to reset it.</p>
            </div>

            <?php if (!empty($flash['error'])): ?>
                <div class="alert alert-error"><?php echo h($flash['error']); ?></div>
            <?php endif; ?>

            <?php if ($submitted): ?>
                <div class="alert alert-success">
                    If an account exists with that email address, we have sent password reset instructions. Please check your inbox.
                </div>
                <div class="auth-links">
                    <a href="/login.php">Back to login</a>
                </div>
            <?php else: ?>
                <form method="post" action="/forgot-password.php" class="auth-form" novalidate>
                    <?php echo csrfField(); ?>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required placeholder="you@example.com">
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Send Reset Link</button>
                </form>

                <div class="auth-links">
                    <a href="/login.php">Back to login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php layoutFooter(); ?>
