<?php
/**
 * CouncilRadar - Forgot Password
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../app/auth/Auth.php';
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
            // Log reset link (send for real once email is configured)
            logMessage('email.log', "Password reset for $email - token: $token");
            logMessage('email.log', "Reset link: " . SITE_URL . "/reset-password.php?token=$token");
        }
    }

    $submitted = true;
}

layoutHeader('Forgot Password', 'Reset your CouncilRadar account password.');
$flash = getFlash();
?>

<section class="auth-page">
    <div class="container container-narrow">
        <h1>Forgot Password</h1>

        <?php if (!empty($flash['error'])): ?>
            <div class="alert alert-error"><?php echo h($flash['error']); ?></div>
        <?php endif; ?>

        <?php if ($submitted): ?>
            <div class="alert alert-success">
                If an account exists with that email address, we have sent password reset instructions. Please check your inbox.
            </div>
            <p class="auth-alt"><a href="/login.php">Back to login</a></p>

        <?php else: ?>
            <p>Enter your email address and we will send you a link to reset your password.</p>

            <form method="post" action="/forgot-password.php" class="auth-form" novalidate>
                <?php echo csrfField(); ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Send Reset Link</button>
            </form>

            <p class="auth-alt"><a href="/login.php">Back to login</a></p>
        <?php endif; ?>
    </div>
</section>

<?php layoutFooter(); ?>
