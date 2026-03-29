<?php
/**
 * CouncilRadar - Public Alerts Archive (SEO page)
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../templates/layout.php';

$db = DB::get();

$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Count total items in the last 30 days with relevance_score > 0
$countStmt = $db->prepare(
    'SELECT COUNT(*)
     FROM agenda_items ai
     JOIN meetings m ON ai.meeting_id = m.id
     WHERE ai.relevance_score > 0
       AND m.meeting_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
);
$countStmt->execute();
$totalItems = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));

// Clamp page
if ($page > $totalPages) {
    $page   = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Fetch items
$stmt = $db->prepare(
    'SELECT ai.title, ai.keywords_matched, ai.relevance_score,
            m.meeting_type, m.meeting_date,
            mu.name AS municipality_name
     FROM agenda_items ai
     JOIN meetings m ON ai.meeting_id = m.id
     JOIN municipalities mu ON m.municipality_id = mu.id
     WHERE ai.relevance_score > 0
       AND m.meeting_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     ORDER BY m.meeting_date DESC, ai.relevance_score DESC
     LIMIT ? OFFSET ?'
);
$stmt->execute([$perPage, $offset]);
$items = $stmt->fetchAll();

layoutHeader(
    'Recent Council Agenda Alerts',
    'Browse recent BC municipal council agenda items flagged by CouncilRadar. Rezoning, development permits, public hearings, and more.'
);
?>

<section class="alerts-archive">
    <div class="container">
        <h1>Recent Council Agenda Alerts</h1>
        <p style="max-width: 700px; color: #4a5568; margin-bottom: 2rem;">
            CouncilRadar monitors 16 BC municipalities and flags agenda items matching keywords related to rezoning,
            development permits, public hearings, infrastructure, and more. Below are recently flagged items from
            the past 30 days. Sign up to receive these alerts directly in your inbox.
        </p>

        <?php if (empty($items)): ?>
            <div class="card" style="padding: 2rem; text-align: center; color: #718096;">
                <p>No flagged agenda items in the last 30 days. Check back soon - we scan municipal agendas daily.</p>
            </div>
        <?php else: ?>
            <div class="alerts-list">
                <?php foreach ($items as $item): ?>
                <div class="card" style="margin-bottom: 1rem; padding: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <span style="font-weight: 600; color: #1a365d;"><?php echo h($item['municipality_name']); ?></span>
                        <span style="font-size: 0.85rem; color: #718096;">
                            <?php echo h($item['meeting_type'] ?: 'Council Meeting'); ?>
                            - <?php echo date('M j, Y', strtotime($item['meeting_date'])); ?>
                        </span>
                    </div>
                    <h3 style="margin: 0 0 0.75rem; font-size: 1.05rem; line-height: 1.4;">
                        <?php echo h($item['title']); ?>
                    </h3>
                    <?php
                    $keywords = json_decode($item['keywords_matched'] ?? '[]', true) ?: [];
                    if (!empty($keywords)):
                    ?>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                        <?php foreach ($keywords as $kw): ?>
                        <span style="display: inline-block; padding: 0.15rem 0.5rem; border-radius: 3px; font-size: 0.78rem; font-weight: 500; background: #ebf4ff; color: #2b6cb0;">
                            <?php echo h(is_array($kw) ? ($kw['keyword'] ?? '') : $kw); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav style="display: flex; justify-content: center; gap: 0.5rem; margin: 2rem 0;">
                <?php if ($page > 1): ?>
                    <a href="/alerts.php?page=<?php echo $page - 1; ?>" class="btn btn-outline" style="font-size: 0.9rem;">Previous</a>
                <?php endif; ?>

                <?php
                $startP = max(1, $page - 2);
                $endP   = min($totalPages, $page + 2);
                for ($p = $startP; $p <= $endP; $p++):
                ?>
                    <?php if ($p === $page): ?>
                        <span class="btn btn-primary" style="font-size: 0.9rem; cursor: default;"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="/alerts.php?page=<?php echo $p; ?>" class="btn btn-outline" style="font-size: 0.9rem;"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="/alerts.php?page=<?php echo $page + 1; ?>" class="btn btn-outline" style="font-size: 0.9rem;">Next</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        <?php endif; ?>

        <!-- CTA -->
        <div class="card" style="margin-top: 2rem; padding: 2rem; text-align: center; background: #ebf4ff; border: 1px solid #bee3f8;">
            <h2 style="color: #1a365d; margin-top: 0;">Get These Alerts in Your Inbox</h2>
            <p style="color: #4a5568; max-width: 500px; margin: 0 auto 1.5rem;">
                Stop checking municipal websites manually. Sign up for CouncilRadar and receive filtered agenda alerts
                delivered straight to your email - free to start.
            </p>
            <a href="/signup.php" class="btn btn-primary btn-lg">Sign Up Free</a>
        </div>
    </div>
</section>

<?php layoutFooter(); ?>
