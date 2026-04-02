<?php
/**
 * CouncilRadar - Account Registration
 *
 * Handles two scenarios:
 *   1. Brand new user creating account from scratch
 *   2. Existing passwordless subscriber (from homepage signup) upgrading to full account
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../app/auth/Auth.php';
require_once __DIR__ . '/../app/email/PostmarkClient.php';
require_once __DIR__ . '/../templates/layout.php';

Session::startSession();

if (Session::isLoggedIn()) {
    redirect('/dashboard.php');
}

$errors = [];
$old = ['email' => '', 'name' => '', 'organization' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    if (!checkRateLimit('signup', RATE_LIMIT_SIGNUP_MAX, RATE_LIMIT_SIGNUP_WINDOW)) {
        $errors[] = 'Too many signup attempts. Please try again later.';
    }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';
    $name     = trim($_POST['name'] ?? '');
    $org      = trim($_POST['organization'] ?? '');
    $consent  = !empty($_POST['casl_consent']);

    $old = ['email' => $email, 'name' => $name, 'organization' => $org];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$consent) {
        $errors[] = 'You must consent to receive alerts to create an account.';
    }

    if (empty($errors)) {
        recordRateLimit('signup');
        $consentText = 'Yes, I want to receive municipal council agenda alerts from CouncilRadar. I understand I can unsubscribe at any time.';

        // Check if email exists as a passwordless subscriber (from homepage signup)
        $db = DB::get();
        $stmt = $db->prepare('SELECT id, password_hash FROM subscribers WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        $existing = $stmt->fetch();

        if ($existing && empty($existing['password_hash'])) {
            // Upgrade passwordless subscriber to full account
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare(
                'UPDATE subscribers SET password_hash = ?, name = COALESCE(?, name),
                 organization = COALESCE(?, organization), email_verified = 1 WHERE id = ?'
            );
            $stmt->execute([$hash, $name ?: null, $org ?: null, $existing['id']]);

            Session::login($existing['id']);
            flash('success', 'Your account is set up. Welcome to CouncilRadar.');
            redirect('/dashboard.php');

        } elseif ($existing) {
            $errors[] = 'An account with that email already exists.';
        } else {
            $subscriber = Auth::register($email, $password, $name, $org, getClientIp(), $consentText);

            if ($subscriber) {
                // Send verification email
                try {
                    $verifyUrl = SITE_URL . '/verify.php?token=' . $subscriber['verify_token'];
                    $pm = new PostmarkClient();
                    $pm->send(
                        $email,
                        'Verify your CouncilRadar account',
                        '<h2>Welcome to CouncilRadar</h2>'
                        . '<p>Click the link below to verify your email and complete your account setup:</p>'
                        . '<p><a href="' . h($verifyUrl) . '" style="display:inline-block;padding:12px 24px;background:#2b6cb0;color:#fff;text-decoration:none;border-radius:6px;">Verify Email</a></p>'
                        . '<p style="color:#718096;font-size:14px;">Or copy this link: ' . h($verifyUrl) . '</p>',
                        "Welcome to CouncilRadar\n\nVerify your email: {$verifyUrl}",
                        'verification'
                    );
                } catch (Exception $e) {
                    logMessage('email.log', "Verification email failed for {$email}: " . $e->getMessage());
                }

                Session::login($subscriber['id']);
                flash('success', 'Account created. Check your email to verify your address.');
                redirect('/dashboard.php');
            } else {
                $errors[] = 'Could not create account. Please try again.';
            }
        }
    }
}

layoutHeader('Create Account', 'Sign up for CouncilRadar - BC municipal agenda monitoring.');
?>

<section class="auth-page">
    <div class="container container-narrow">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1>Create Your Account</h1>
                <p class="auth-subtitle">Start monitoring BC council agendas.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p><?php echo $err; ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/signup.php" class="auth-form" novalidate>
                <?php echo csrfField(); ?>

                <div class="form-group">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email" value="<?php echo h($old['email']); ?>" required placeholder="you@example.com">
                </div>

                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <input type="password" id="password" name="password" required minlength="8" placeholder="Minimum 8 characters">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirm Password <span class="required">*</span></label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                </div>

                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" value="<?php echo h($old['name']); ?>" placeholder="Optional">
                </div>

                <div class="form-group">
                    <label for="organization">Organization</label>
                    <input type="text" id="organization" name="organization" value="<?php echo h($old['organization']); ?>" placeholder="Optional">
                </div>

                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="casl_consent" value="1">
                        <span>Yes, I want to receive municipal council agenda alerts from CouncilRadar. I understand I can unsubscribe at any time.</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Create Account</button>
            </form>
        </div>

        <p class="auth-alt">Already have an account? <a href="/login.php">Log in</a></p>
    </div>
</section>

<?php layoutFooter(); ?>
