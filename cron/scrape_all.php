<?php
/**
 * CouncilRadar - Daily Scrape Job
 * Runs at 2 AM Pacific via cPanel cron
 * Scrapes all configured municipality meeting sources
 */

chdir(__DIR__ . '/..');

require 'app/config.php';
require 'app/db.php';
require 'app/functions.php';
require 'app/scrapers/CivicWebScraper.php';
require 'app/scrapers/EscribeScraper.php';

$logFile = 'logs/scrape_all.log';
$startTime = date('Y-m-d H:i:s');

file_put_contents($logFile, "[{$startTime}] Scrape job started\n", FILE_APPEND);

try {
    // Scrape CivicWeb sources
    $civicWeb = new CivicWebScraper();
    $civicWebResults = $civicWeb->scrapeAll();
    $civicWebCount = is_array($civicWebResults) ? count($civicWebResults) : 0;

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] CivicWeb: {$civicWebCount} meetings found\n", FILE_APPEND);

    // Scrape Escribe sources
    $escribe = new EscribeScraper();
    $escribeResults = $escribe->scrapeAll();
    $escribeCount = is_array($escribeResults) ? count($escribeResults) : 0;

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Escribe: {$escribeCount} meetings found\n", FILE_APPEND);

    $totalCount = $civicWebCount + $escribeCount;
    $endTime = date('Y-m-d H:i:s');

    file_put_contents($logFile, "[{$endTime}] Scrape job completed - Total: {$totalCount} meetings found\n", FILE_APPEND);

} catch (Exception $e) {
    $errorTime = date('Y-m-d H:i:s');
    $errorMsg = "[{$errorTime}] CRITICAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";

    file_put_contents($logFile, $errorMsg, FILE_APPEND);
    notifyAdmin('Scrape job failed: ' . $e->getMessage());
}
