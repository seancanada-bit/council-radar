<?php
/**
 * CouncilRadar - Reset Password
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../app/auth/Auth.php';
require_once __DIR__ . '/../templates/layout.php';

Session::startSession();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];

if (empty($token)) {
    flash('error', 'Invalid or missing reset token.');
    redirect('/forgot-password.php');
}

// Verify token exists and is not expired before showing the form
$db = DB::get();
$stmt = $db->prepare(
    'SELECT id FROM subscribers WHERE reset_token = ? AND reset_token_expires > NOW()'
);
$stmt->execute([$token]);
$validToken = $stmt->fetch();

if (!$validToken) {
    flash('error', 'This reset link has expired or is invalid. Please request a new one.');
    redirect('/forgot-password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    $password     = $_POST['password'] ?? '';
    $passwordConf = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $passwordConf) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        if (Auth::resetPassword($token, $password)) {
            flash('success', 'Your password has been reset. You can now log in with your new password.');
            redirect('/login.php');
        } else {
            $errors[] = 'This reset link has expired. Please request a new one.';
        }
    }
}

layoutHeader('Reset Password', 'Set a new password for your CouncilRadar account.');
?>

<section class="auth-page">
    <div class="container container-narrow">
        <h1>Reset Password</h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo h($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/reset-password.php" class="auth-form" novalidate>
            <?php echo csrfField(); ?>
            <input type="hidden" name="token" value="<?php echo h($token); ?>">

            <div class="form-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" minlength="8" required>
                <small>Minimum 8 characters</small>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirm New Password</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Reset Password</button>
        </form>

        <p class="auth-alt"><a href="/login.php">Back to login</a></p>
    </div>
</section>

<?php layoutFooter(); ?>
