<?php
/**
 * CouncilRadar - Send Daily Alerts
 * Runs at 6 AM Pacific via cPanel cron
 * Sends daily alert emails to paid subscribers
 */

chdir(__DIR__ . '/..');

require 'app/config.php';
require 'app/db.php';
require 'app/functions.php';
require 'app/email/AlertSender.php';

$logFile = 'logs/send_daily_alerts.log';
$startTime = date('Y-m-d H:i:s');

file_put_contents($logFile, "[{$startTime}] Daily alerts job started\n", FILE_APPEND);

try {
    $sender = new AlertSender();
    $results = $sender->sendDailyAlerts();

    $alertsSent = isset($results['alerts_sent']) ? $results['alerts_sent'] : 0;
    $errors = isset($results['errors']) ? $results['errors'] : 0;

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Alerts sent: {$alertsSent}\n", FILE_APPEND);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Errors: {$errors}\n", FILE_APPEND);

    $endTime = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$endTime}] Daily alerts job completed\n", FILE_APPEND);

} catch (Exception $e) {
    $errorTime = date('Y-m-d H:i:s');
    $errorMsg = "[{$errorTime}] CRITICAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

    file_put_contents($logFile, $errorMsg, FILE_APPEND);
    notifyAdmin('Daily alerts job failed: ' . $e->getMessage());
}
