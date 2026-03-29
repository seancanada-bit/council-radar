<?php
/**
 * CouncilRadar - Subscriber Registration
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../app/auth/Auth.php';
require_once __DIR__ . '/../templates/layout.php';

Session::startSession();

$errors = [];
$old = [
    'email'        => '',
    'name'         => '',
    'organization' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    // Rate limit
    if (!checkRateLimit('signup', RATE_LIMIT_SIGNUP_MAX, RATE_LIMIT_SIGNUP_WINDOW)) {
        $errors[] = 'Too many signup attempts. Please try again later.';
    }

    $email        = trim($_POST['email'] ?? '');
    $password     = $_POST['password'] ?? '';
    $passwordConf = $_POST['password_confirm'] ?? '';
    $name         = trim($_POST['name'] ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $consent      = isset($_POST['casl_consent']);

    $old['email']        = $email;
    $old['name']         = $name;
    $old['organization'] = $organization;

    // Validation
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $passwordConf) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$consent) {
        $errors[] = 'You must consent to receive alerts to sign up.';
    }

    if (empty($errors)) {
        recordRateLimit('signup');

        $consentText = 'Yes, I want to receive municipal council agenda alerts from CouncilRadar. I understand I can unsubscribe at any time.';
        $consentIp = getClientIp();

        $subscriber = Auth::register($email, $password, $name ?: null, $organization ?: null, $consentIp, $consentText);

        if ($subscriber) {
            // Log verification email (send for real once email is configured)
            logMessage('email.log', "Verification email for {$subscriber['email']} - token: {$subscriber['verify_token']}");
            logMessage('email.log', "Verify link: " . SITE_URL . "/verify.php?token={$subscriber['verify_token']}");

            flash('success', 'Account created. Please check your email to verify your address, then log in.');
            redirect('/login.php');
        } else {
            $errors[] = 'An account with that email already exists.';
        }
    }
}

layoutHeader('Sign Up', 'Create your CouncilRadar account to receive municipal agenda alerts.');
$flash = getFlash();
?>

<section class="auth-page">
    <div class="container container-narrow">
        <h1>Create Your Account</h1>

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

        <form method="post" action="/signup.php" class="auth-form" novalidate>
            <?php echo csrfField(); ?>

            <div class="form-group">
                <label for="email">Email Address <span class="required">*</span></label>
                <input type="email" id="email" name="email" value="<?php echo h($old['email']); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <input type="password" id="password" name="password" minlength="8" required>
                <small>Minimum 8 characters</small>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirm Password <span class="required">*</span></label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?php echo h($old['name']); ?>">
            </div>

            <div class="form-group">
                <label for="organization">Organization</label>
                <input type="text" id="organization" name="organization" value="<?php echo h($old['organization']); ?>">
            </div>

            <div class="form-group form-group-checkbox">
                <label>
                    <input type="checkbox" name="casl_consent" value="1" required>
                    Yes, I want to receive municipal council agenda alerts from CouncilRadar. I understand I can unsubscribe at any time.
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Create Account</button>
        </form>

        <p class="auth-alt">Already have an account? <a href="/login.php">Log in</a></p>
    </div>
</section>

<?php layoutFooter(); ?>
