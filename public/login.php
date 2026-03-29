<?php
/**
 * CouncilRadar - Subscriber Login
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../app/auth/Auth.php';
require_once __DIR__ . '/../templates/layout.php';

Session::startSession();

// If already logged in, go to dashboard
if (Session::isLoggedIn()) {
    redirect('/dashboard.php');
}

$errors = [];
$oldEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    // Rate limit
    if (!checkRateLimit('login', RATE_LIMIT_LOGIN_MAX, RATE_LIMIT_LOGIN_WINDOW)) {
        $errors[] = 'Too many login attempts. Please wait 15 minutes and try again.';
    }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $oldEmail = $email;

    if (empty($email) || empty($password)) {
        $errors[] = 'Email and password are required.';
    }

    if (empty($errors)) {
        recordRateLimit('login');

        $subscriber = Auth::login($email, $password);

        if ($subscriber) {
            Session::login($subscriber['id']);
            redirect('/dashboard.php');
        } else {
            $errors[] = 'Invalid email or password.';
        }
    }
}

layoutHeader('Log In', 'Log in to your CouncilRadar account.');
$flash = getFlash();
?>

<section class="auth-page">
    <div class="container container-narrow">
        <h1>Log In</h1>

        <?php if (!empty($flash['success'])): ?>
            <div class="alert alert-success"><?php echo h($flash['success']); ?></div>
        <?php endif; ?>

        <?php if (!empty($flash['error'])): ?>
            <div class="alert alert-error"><?php echo h($flash['error']); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo h($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/login.php" class="auth-form" novalidate>
            <?php echo csrfField(); ?>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="<?php echo h($oldEmail); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Log In</button>
        </form>

        <p class="auth-alt">
            <a href="/forgot-password.php">Forgot your password?</a>
        </p>
        <p class="auth-alt">
            Don't have an account? <a href="/signup.php">Sign up</a>
        </p>
    </div>
</section>

<?php layoutFooter(); ?>
