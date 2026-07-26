<?php
/**
 * CouncilRadar - Weekly Cleanup Job
 * Runs weekly via cPanel cron
 * Cleans up old log entries, rate limits, and raw HTML from parsed meetings
 */

chdir(__DIR__ . '/..');

require 'app/config.php';
require 'app/db.php';
require 'app/functions.php';

$logFile = 'logs/cleanup.log';
$startTime = date('Y-m-d H:i:s');

file_put_contents($logFile, "[{$startTime}] Cleanup job started\n", FILE_APPEND);

try {
    // Was getDb(), which does not exist anywhere in the codebase. app/db.php
    // provides a singleton class instead, which is what the rest of the app uses.
    $pdo = DB::get();

    // Delete scrape_log entries older than 90 days
    $stmt = $pdo->prepare("DELETE FROM scrape_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $stmt->execute();
    $scrapeLogDeleted = $stmt->rowCount();

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Scrape log entries deleted: {$scrapeLogDeleted}\n", FILE_APPEND);

    // Delete rate_limits entries older than 1 day
    cleanRateLimits();

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Rate limits cleaned\n", FILE_APPEND);

    // NULL out raw_html for parsed meetings older than 1 year to save space
    $stmt = $pdo->prepare("UPDATE meetings SET raw_html = NULL WHERE parsed = 1 AND scraped_at < DATE_SUB(NOW(), INTERVAL 1 YEAR) AND raw_html IS NOT NULL");
    $stmt->execute();
    $rawHtmlCleared = $stmt->rowCount();

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Meetings raw_html cleared: {$rawHtmlCleared}\n", FILE_APPEND);

    $endTime = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$endTime}] Cleanup job completed\n", FILE_APPEND);

// Throwable, not Exception. The getDb() failure raised an Error, which is a
// Throwable but not an Exception, so this block never ran and the job died as an
// uncaught fatal with nothing written to the cleanup log.
} catch (Throwable $e) {
    $errorTime = date('Y-m-d H:i:s');
    $errorMsg = "[{$errorTime}] CRITICAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

    file_put_contents($logFile, $errorMsg, FILE_APPEND);

    // notifyAdmin takes (subject, body). It was being called with one argument,
    // so even reaching this line would have thrown a TypeError.
    notifyAdmin(
        'CouncilRadar cleanup job failed',
        $e->getMessage() . "\n\n" . $e->getTraceAsString()
    );
}
