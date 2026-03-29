<?php
/**
 * CouncilRadar - Parse New Meetings Job
 * Runs at 3 AM Pacific via cPanel cron
 * Parses newly scraped meetings and extracts agenda items with keyword matching
 */

chdir(__DIR__ . '/..');

require 'app/config.php';
require 'app/db.php';
require 'app/functions.php';
require 'app/parsers/KeywordParser.php';

$logFile = 'logs/parse_new.log';
$startTime = date('Y-m-d H:i:s');

file_put_contents($logFile, "[{$startTime}] Parse job started\n", FILE_APPEND);

try {
    $parser = new KeywordParser();
    $results = $parser->parseAll();

    $meetingsParsed = isset($results['meetings_parsed']) ? $results['meetings_parsed'] : 0;
    $itemsCreated = isset($results['items_created']) ? $results['items_created'] : 0;

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Meetings parsed: {$meetingsParsed}\n", FILE_APPEND);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Agenda items created: {$itemsCreated}\n", FILE_APPEND);

    $endTime = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$endTime}] Parse job completed\n", FILE_APPEND);

} catch (Exception $e) {
    $errorTime = date('Y-m-d H:i:s');
    $errorMsg = "[{$errorTime}] CRITICAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

    file_put_contents($logFile, $errorMsg, FILE_APPEND);
    notifyAdmin('Parse job failed: ' . $e->getMessage());
}
