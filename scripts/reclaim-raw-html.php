<?php
/**
 * CouncilRadar - one-time raw_html reclaim
 *
 * Clears raw_html on every already-parsed meeting, then rebuilds the table so the
 * freed space returns to the filesystem.
 *
 * Background: raw_html is read only by KeywordParser::parseMeeting(), and the
 * parser selects WHERE parsed = 0, so the column is dead weight once a meeting is
 * parsed. It had grown to 1.2 GB on disk across 631 meetings while agenda_items,
 * the parsed output the app actually queries, was under 1 MB.
 *
 * KeywordParser now clears the column at parse time and cron/cleanup.php carries a
 * backstop, so this script is only needed once for the existing backlog. It is
 * safe to re-run: it becomes a no-op when nothing matches.
 *
 * THIS DELETES DATA IRREVERSIBLY. Meetings keep their parsed agenda_items, but the
 * original source HTML is gone and re-parsing would need a re-fetch.
 *
 * OPTIMIZE TABLE rebuilds the table and holds a lock for the duration, so run it
 * at a quiet hour. Skip that step with --no-optimize; InnoDB then keeps the freed
 * space internally and reuses it for new rows rather than returning it to disk.
 *
 * Usage:
 *   php scripts/reclaim-raw-html.php --dry-run
 *   php scripts/reclaim-raw-html.php
 *   php scripts/reclaim-raw-html.php --no-optimize
 */

// CLI only. The docroot is the repo root on this host, so anything under
// scripts/ is reachable over HTTP unless .htaccess blocks it. A script that
// NULLs database columns and runs OPTIMIZE TABLE must never be triggerable by a
// web request, and this guard holds even if the .htaccess rules are wrong.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__ . '/..');

require 'app/config.php';
require 'app/db.php';

$dryRun     = in_array('--dry-run', $argv, true);
$noOptimize = in_array('--no-optimize', $argv, true);

function tableSizeMb(PDO $pdo): ?float {
    $sql = "SELECT ROUND(((data_length + index_length) / 1048576), 1)
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE() AND table_name = 'meetings'";
    $v = $pdo->query($sql)->fetchColumn();
    return $v === false || $v === null ? null : (float) $v;
}

try {
    $pdo = DB::get();

    $before   = tableSizeMb($pdo);
    $affected = (int) $pdo->query(
        "SELECT COUNT(*) FROM meetings WHERE parsed = 1 AND raw_html IS NOT NULL"
    )->fetchColumn();
    $unparsed = (int) $pdo->query(
        "SELECT COUNT(*) FROM meetings WHERE parsed = 0 AND raw_html IS NOT NULL"
    )->fetchColumn();

    printf("meetings table before ....... %s MB%s", $before ?? '?', PHP_EOL);
    printf("parsed rows with raw_html ... %d  (will be cleared)%s", $affected, PHP_EOL);
    printf("unparsed rows with raw_html . %d  (left alone, still needed)%s", $unparsed, PHP_EOL);

    if ($dryRun) {
        echo "dry run, nothing changed" . PHP_EOL;
        exit(0);
    }

    if ($affected === 0) {
        echo "nothing to clear" . PHP_EOL;
    } else {
        $stmt = $pdo->prepare("UPDATE meetings SET raw_html = NULL WHERE parsed = 1 AND raw_html IS NOT NULL");
        $stmt->execute();
        printf("cleared ..................... %d rows%s", $stmt->rowCount(), PHP_EOL);
    }

    if ($noOptimize) {
        echo "skipped OPTIMIZE TABLE (--no-optimize)" . PHP_EOL;
    } else {
        echo "running OPTIMIZE TABLE meetings, this locks the table ..." . PHP_EOL;
        $t = microtime(true);
        $pdo->query("OPTIMIZE TABLE meetings");
        printf("optimize took ............... %.1fs%s", microtime(true) - $t, PHP_EOL);
    }

    $after = tableSizeMb($pdo);
    printf("meetings table after ........ %s MB%s", $after ?? '?', PHP_EOL);
    if ($before !== null && $after !== null) {
        printf("reclaimed ................... %.1f MB%s", $before - $after, PHP_EOL);
    }

} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
