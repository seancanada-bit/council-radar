<?php
/**
 * CouncilRadar - Unsubscribe Page
 *
 * Works from email links without login.
 * Accepts ?token={verify_token} or ?id={subscriber_id}&email={email}
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../templates/layout.php';

Session::startSession();

$db = DB::get();
$subscriber = null;
$confirmed = false;
$error = null;

// Identify the subscriber
$token = trim($_GET['token'] ?? '');
$id    = (int) ($_GET['id'] ?? 0);
$email = trim($_GET['email'] ?? '');

if ($token) {
    $stmt = $db->prepare('SELECT id, email, name FROM subscribers WHERE verify_token = ? AND active = 1');
    $stmt->execute([$token]);
    $subscriber = $stmt->fetch();
} elseif ($id && $email) {
    $stmt = $db->prepare('SELECT id, email, name FROM subscribers WHERE id = ? AND email = ? AND active = 1');
    $stmt->execute([$id, strtolower($email)]);
    $subscriber = $stmt->fetch();
}

if (!$subscriber) {
    $error = 'We could not find an active subscription matching that link. You may have already unsubscribed.';
}

// Handle confirmation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $subscriber) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $stmt = $db->prepare(
            'UPDATE subscribers SET active = 0, unsubscribed_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$subscriber['id']]);
        $confirmed = true;

        logMessage('unsubscribe.log', "Unsubscribed: {$subscriber['email']} (ID: {$subscriber['id']})");
    }
}

layoutHeader('Unsubscribe', 'Unsubscribe from CouncilRadar alerts.');
?>

<section class="auth-page">
    <div class="container container-narrow">
        <h1>Unsubscribe</h1>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo h($error); ?></div>

        <?php elseif ($confirmed): ?>
            <div class="alert alert-success">
                You have been unsubscribed from CouncilRadar alerts.
                You will no longer receive emails from us.
            </div>
            <p>If this was a mistake, you can <a href="/signup.php">sign up again</a> at any time.</p>

        <?php elseif ($subscriber): ?>
            <p>Are you sure you want to unsubscribe <strong><?php echo h($subscriber['email']); ?></strong> from CouncilRadar alerts?</p>

            <form method="post" class="auth-form">
                <?php echo csrfField(); ?>

                <?php if ($token): ?>
                    <input type="hidden" name="token" value="<?php echo h($token); ?>">
                <?php else: ?>
                    <input type="hidden" name="id" value="<?php echo (int) $subscriber['id']; ?>">
                    <input type="hidden" name="email" value="<?php echo h($subscriber['email']); ?>">
                <?php endif; ?>

                <button type="submit" class="btn btn-danger btn-full">Yes, Unsubscribe Me</button>
            </form>

            <p class="auth-alt"><a href="/">Never mind, take me back</a></p>

        <?php endif; ?>
    </div>
</section>

<?php layoutFooter(); ?>
