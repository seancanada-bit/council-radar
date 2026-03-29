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
    $pdo = getDb();

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

} catch (Exception $e) {
    $errorTime = date('Y-m-d H:i:s');
    $errorMsg = "[{$errorTime}] CRITICAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

    file_put_contents($logFile, $errorMsg, FILE_APPEND);
    notifyAdmin('Cleanup job failed: ' . $e->getMessage());
}
