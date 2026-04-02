<?php
/**
 * CouncilRadar - Subscriber Dashboard
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../templates/layout.php';

Session::startSession();
Session::requireLogin();

$db = DB::get();
$subscriberId = Session::getSubscriberId();

// Load subscriber
$stmt = $db->prepare(
    'SELECT id, email, name, organization, tier, municipalities_filter,
            keywords_filter, frequency, created_at
     FROM subscribers WHERE id = ?'
);
$stmt->execute([$subscriberId]);
$subscriber = $stmt->fetch();

if (!$subscriber) {
    Session::logout();
    redirect('/login.php');
}

// Load all municipalities
$municipalities = $db->query(
    'SELECT id, name FROM municipalities WHERE active = 1 ORDER BY name'
)->fetchAll();

// Decode subscriber filters
$muniFilter = json_decode($subscriber['municipalities_filter'] ?? '[]', true) ?: [];
$kwFilter   = json_decode($subscriber['keywords_filter'] ?? '[]', true) ?: [];

// Load recent alerts
$stmt = $db->prepare(
    'SELECT alert_type, subject, items_count, sent_at
     FROM alerts_sent
     WHERE subscriber_id = ?
     ORDER BY sent_at DESC
     LIMIT 10'
);
$stmt->execute([$subscriberId]);
$recentAlerts = $stmt->fetchAll();

// Handle password change
$pwErrors  = [];
$pwSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $pwErrors[] = 'Invalid form submission. Please try again.';
    }

    $currentPw = $_POST['current_password'] ?? '';
    $newPw     = $_POST['new_password'] ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    if (empty($currentPw) || empty($newPw) || empty($confirmPw)) {
        $pwErrors[] = 'All password fields are required.';
    }

    if ($newPw !== $confirmPw) {
        $pwErrors[] = 'New passwords do not match.';
    }

    if (strlen($newPw) < 8) {
        $pwErrors[] = 'New password must be at least 8 characters.';
    }

    if (empty($pwErrors)) {
        $stmt = $db->prepare('SELECT password_hash FROM subscribers WHERE id = ?');
        $stmt->execute([$subscriberId]);
        $row = $stmt->fetch();

        if (!password_verify($currentPw, $row['password_hash'])) {
            $pwErrors[] = 'Current password is incorrect.';
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare('UPDATE subscribers SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $subscriberId]);
            $pwSuccess = true;
        }
    }
}

$tierLabels = [
    'free'         => 'Free',
    'professional' => 'Professional',
    'firm'         => 'Firm',
];
$tierLabel = $tierLabels[$subscriber['tier']] ?? 'Free';

layoutHeader('Dashboard', 'Manage your CouncilRadar preferences and alerts.');
$flash = getFlash();
?>

<section class="dashboard-page">
    <div class="container">

        <?php if (!empty($flash['success'])): ?>
            <div class="alert alert-success"><?php echo h($flash['success']); ?></div>
        <?php endif; ?>

        <?php if (!empty($flash['error'])): ?>
            <div class="alert alert-error"><?php echo h($flash['error']); ?></div>
        <?php endif; ?>

        <!-- Welcome Section -->
        <div class="card" style="margin-bottom: 2rem;">
            <h1>Welcome, <?php echo h($subscriber['name'] ?: $subscriber['email']); ?></h1>
            <p><strong>Email:</strong> <?php echo h($subscriber['email']); ?></p>
            <p>
                <strong>Plan:</strong>
                <span class="tier-badge tier-badge--<?php echo h($subscriber['tier']); ?>"
                      style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.85rem; font-weight: 600; color: #fff; background: <?php echo $subscriber['tier'] === 'free' ? '#718096' : '#2b6cb0'; ?>;">
                    <?php echo h($tierLabel); ?>
                </span>
            </p>
            <p><strong>Member since:</strong> <?php echo date('F j, Y', strtotime($subscriber['created_at'])); ?></p>
        </div>

        <?php if ($subscriber['tier'] === 'free'): ?>
        <!-- Upgrade CTA -->
        <div class="card" style="margin-bottom: 2rem; background: #ebf4ff; border-left: 4px solid #2b6cb0;">
            <h2 style="color: #1a365d; margin-top: 0;">Upgrade to Professional</h2>
            <p>Get daily alerts, full item details with source links, custom keyword filters, and municipality selection - all for $19/month.</p>
            <a href="/upgrade.php" class="btn btn-primary">Upgrade Now</a>
        </div>
        <?php endif; ?>

        <!-- Your Preferences -->
        <div class="card" style="margin-bottom: 2rem;">
            <h2>Your Preferences</h2>
            <div id="prefs-message" style="display: none; margin-bottom: 1rem;"></div>

            <form id="prefsForm">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">

                <!-- Municipality Filter -->
                <fieldset style="margin-bottom: 1.5rem; border: 1px solid #e2e8f0; padding: 1rem; border-radius: 6px;">
                    <legend style="font-weight: 600; padding: 0 0.5rem;">Municipalities</legend>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.5rem;">
                        <?php foreach ($municipalities as $muni): ?>
                        <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="checkbox" name="municipalities[]" value="<?php echo (int)$muni['id']; ?>"
                                <?php echo in_array((int)$muni['id'], $muniFilter) ? 'checked' : ''; ?>>
                            <?php echo h($muni['name']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <!-- Keyword Filter -->
                <fieldset style="margin-bottom: 1.5rem; border: 1px solid #e2e8f0; padding: 1rem; border-radius: 6px;">
                    <legend style="font-weight: 600; padding: 0 0.5rem;">Keywords</legend>

                    <div style="margin-bottom: 1rem;">
                        <h4 style="margin: 0 0 0.5rem; color: #1a365d;">Primary Keywords</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.4rem;">
                            <?php foreach (KEYWORDS_PRIMARY as $kw): ?>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                                <input type="checkbox" name="keywords[]" value="<?php echo h($kw); ?>"
                                    <?php echo in_array($kw, $kwFilter) ? 'checked' : ''; ?>>
                                <?php echo h($kw); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <h4 style="margin: 0 0 0.5rem; color: #1a365d;">Secondary Keywords</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.4rem;">
                            <?php foreach (KEYWORDS_SECONDARY as $kw): ?>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                                <input type="checkbox" name="keywords[]" value="<?php echo h($kw); ?>"
                                    <?php echo in_array($kw, $kwFilter) ? 'checked' : ''; ?>>
                                <?php echo h($kw); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <h4 style="margin: 0 0 0.5rem; color: #1a365d;">Tertiary Keywords</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.4rem;">
                            <?php foreach (KEYWORDS_TERTIARY as $kw): ?>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                                <input type="checkbox" name="keywords[]" value="<?php echo h($kw); ?>"
                                    <?php echo in_array($kw, $kwFilter) ? 'checked' : ''; ?>>
                                <?php echo h($kw); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </fieldset>

                <!-- Frequency -->
                <fieldset style="margin-bottom: 1.5rem; border: 1px solid #e2e8f0; padding: 1rem; border-radius: 6px;">
                    <legend style="font-weight: 600; padding: 0 0.5rem;">Alert Frequency</legend>
                    <div style="display: flex; gap: 2rem; align-items: center; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="radio" name="frequency" value="weekly"
                                <?php echo $subscriber['frequency'] === 'weekly' ? 'checked' : ''; ?>>
                            Weekly Digest
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.4rem; cursor: <?php echo $subscriber['tier'] === 'free' ? 'not-allowed' : 'pointer'; ?>;">
                            <input type="radio" name="frequency" value="daily"
                                <?php echo $subscriber['frequency'] === 'daily' ? 'checked' : ''; ?>
                                <?php echo $subscriber['tier'] === 'free' ? 'disabled' : ''; ?>>
                            Daily Alerts
                            <?php if ($subscriber['tier'] === 'free'): ?>
                                <span style="font-size: 0.8rem; color: #718096; margin-left: 0.25rem;">
                                    - <a href="/upgrade.php" style="color: #2b6cb0;">Upgrade to Professional</a>
                                </span>
                            <?php endif; ?>
                        </label>
                    </div>
                </fieldset>

                <button type="submit" class="btn btn-primary" id="savePrefsBtn">Save Preferences</button>
            </form>
        </div>

        <!-- Recent Alerts -->
        <div class="card" style="margin-bottom: 2rem;">
            <h2>Recent Alerts</h2>
            <?php if (empty($recentAlerts)): ?>
                <p style="color: #718096;">No alerts sent yet. We will start sending once matching agenda items are found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
                        <thead>
                            <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                                <th style="padding: 0.75rem 0.5rem;">Date</th>
                                <th style="padding: 0.75rem 0.5rem;">Type</th>
                                <th style="padding: 0.75rem 0.5rem;">Subject</th>
                                <th style="padding: 0.75rem 0.5rem;">Items</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentAlerts as $alert): ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 0.75rem 0.5rem;"><?php echo date('M j, Y', strtotime($alert['sent_at'])); ?></td>
                                <td style="padding: 0.75rem 0.5rem;">
                                    <span style="display: inline-block; padding: 0.15rem 0.5rem; border-radius: 3px; font-size: 0.8rem; font-weight: 600; background: <?php echo $alert['alert_type'] === 'daily' ? '#ebf4ff' : '#f0fff4'; ?>; color: <?php echo $alert['alert_type'] === 'daily' ? '#2b6cb0' : '#276749'; ?>;">
                                        <?php echo h(ucfirst($alert['alert_type'])); ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem 0.5rem;"><?php echo h($alert['subject']); ?></td>
                                <td style="padding: 0.75rem 0.5rem;"><?php echo (int)$alert['items_count']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Account Section -->
        <div class="card" style="margin-bottom: 2rem;">
            <h2>Account</h2>

            <h3>Change Password</h3>

            <?php if ($pwSuccess): ?>
                <div class="alert alert-success">Password updated successfully.</div>
            <?php endif; ?>

            <?php if (!empty($pwErrors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($pwErrors as $err): ?>
                            <li><?php echo h($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="/dashboard.php" class="auth-form" style="max-width: 420px;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8" placeholder="Minimum 8 characters">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>

                <button type="submit" class="btn btn-primary">Update Password</button>
            </form>

            <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">

            <h3>Upgrade Plan</h3>
            <?php if ($subscriber['tier'] === 'free'): ?>
                <p>You are on the <strong>Free</strong> plan. Upgrade to unlock daily alerts, source links, and custom filters.</p>
                <a href="/upgrade.php" class="btn btn-primary">Upgrade to Professional - $19/month</a>
            <?php elseif ($subscriber['tier'] === 'professional'): ?>
                <p>You are on the <strong>Professional</strong> plan. Need team access? Email us to upgrade to the Firm plan.</p>
                <a href="mailto:<?php echo h(ADMIN_EMAIL ?: 'support@councilradar.ca'); ?>" class="btn btn-outline">Email Us to Upgrade</a>
            <?php else: ?>
                <p>You are on the <strong>Firm</strong> plan. Thank you for your support.</p>
            <?php endif; ?>
        </div>

    </div>
</section>

<script>
document.getElementById('prefsForm').addEventListener('submit', function(e) {
    e.preventDefault();

    var msgEl = document.getElementById('prefs-message');
    var btn   = document.getElementById('savePrefsBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    var munis = [];
    document.querySelectorAll('input[name="municipalities[]"]:checked').forEach(function(cb) {
        munis.push(parseInt(cb.value, 10));
    });

    var keywords = [];
    document.querySelectorAll('input[name="keywords[]"]:checked').forEach(function(cb) {
        keywords.push(cb.value);
    });

    var freqEl = document.querySelector('input[name="frequency"]:checked');
    var frequency = freqEl ? freqEl.value : 'weekly';

    fetch('/api/update-prefs.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            municipalities_filter: munis,
            keywords_filter: keywords,
            frequency: frequency
        })
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Save Preferences';
        if (data.success) {
            msgEl.style.display = 'block';
            msgEl.className = 'alert alert-success';
            msgEl.textContent = 'Preferences saved.';
        } else {
            msgEl.style.display = 'block';
            msgEl.className = 'alert alert-error';
            msgEl.textContent = data.error || 'Failed to save preferences.';
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = 'Save Preferences';
        msgEl.style.display = 'block';
        msgEl.className = 'alert alert-error';
        msgEl.textContent = 'Network error. Please try again.';
    });
});
</script>

<?php layoutFooter(); ?>
