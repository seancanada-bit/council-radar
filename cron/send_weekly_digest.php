<?php
/**
 * CouncilRadar - Send Weekly Digest
 * Runs Monday at 6 AM Pacific via cPanel cron
 * Sends weekly digest emails to free-tier subscribers
 */

chdir(__DIR__ . '/..');

require 'app/config.php';
require 'app/db.php';
require 'app/functions.php';
require 'app/email/AlertSender.php';

$logFile = 'logs/send_weekly_digest.log';
$startTime = date('Y-m-d H:i:s');

file_put_contents($logFile, "[{$startTime}] Weekly digest job started\n", FILE_APPEND);

try {
    $sender = new AlertSender();
    $results = $sender->sendWeeklyDigest();

    $digestsSent = isset($results['digests_sent']) ? $results['digests_sent'] : 0;
    $errors = isset($results['errors']) ? $results['errors'] : 0;

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Digests sent: {$digestsSent}\n", FILE_APPEND);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Errors: {$errors}\n", FILE_APPEND);

    $endTime = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$endTime}] Weekly digest job completed\n", FILE_APPEND);

} catch (Exception $e) {
    $errorTime = date('Y-m-d H:i:s');
    $errorMsg = "[{$errorTime}] CRITICAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

    file_put_contents($logFile, $errorMsg, FILE_APPEND);
    notifyAdmin('Weekly digest job failed: ' . $e->getMessage());
}
