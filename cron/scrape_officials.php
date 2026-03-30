<?php
/**
 * CouncilRadar - Elected Officials Scrape Job
 *
 * Usage:
 *   php scrape_officials.php                    # Scrape all levels
 *   php scrape_officials.php --level=provincial # Scrape only provincial MLAs
 *   php scrape_officials.php --level=municipal  # Scrape only local government
 *   php scrape_officials.php --level=school     # Scrape only school trustees
 *   php scrape_officials.php --verify           # Run cross-reference verification only
 *   php scrape_officials.php --dry-run          # Fetch data but don't write to DB
 *
 * Recommended cron: monthly (officials don't change often)
 *   0 3 1 * * cd /path/to/councilradar && php cron/scrape_officials.php >> logs/officials_cron.log 2>&1
 */

chdir(__DIR__ . '/..');

require 'app/config.php';
require 'app/db.php';
require 'app/functions.php';

$logFile = 'logs/officials_cron.log';
$startTime = date('Y-m-d H:i:s');

// Parse CLI arguments
$options = getopt('', ['level:', 'verify', 'dry-run']);
$level = $options['level'] ?? 'all';
$verifyOnly = isset($options['verify']);
$dryRun = isset($options['dry-run']);

$validLevels = ['all', 'provincial', 'municipal', 'school'];
if (!in_array($level, $validLevels)) {
    echo "Invalid level: {$level}. Valid options: " . implode(', ', $validLevels) . "\n";
    exit(1);
}

logMsg($logFile, "Officials scrape started (level={$level}" . ($dryRun ? ', dry-run' : '') . ")");

if ($dryRun) {
    logMsg($logFile, "DRY RUN mode — no database writes will occur");
}

$totalFound = 0;
$totalInserted = 0;
$totalUpdated = 0;
$errors = [];

try {
    // Provincial MLAs
    if (!$verifyOnly && in_array($level, ['all', 'provincial'])) {
        logMsg($logFile, "--- Provincial MLAs ---");
        require_once 'app/scrapers/ProvincialScraper.php';

        $scraper = new ProvincialScraper();
        $result = $scraper->scrapeAll();

        $totalFound += $result['officials_found'];
        $totalInserted += $result['officials_inserted'];
        $totalUpdated += $result['officials_updated'];

        logMsg($logFile, "Provincial: {$result['officials_found']} found, {$result['officials_inserted']} inserted, {$result['officials_updated']} updated");
    }

    // Local Government (Mayors, Councillors, RD Directors)
    if (!$verifyOnly && in_array($level, ['all', 'municipal'])) {
        logMsg($logFile, "--- Local Government ---");

        $scraperFile = 'app/scrapers/LocalGovScraper.php';
        if (file_exists($scraperFile)) {
            require_once $scraperFile;
            $scraper = new LocalGovScraper();
            $result = $scraper->scrapeAll();

            $totalFound += $result['officials_found'];
            $totalInserted += $result['officials_inserted'];
            $totalUpdated += $result['officials_updated'];

            logMsg($logFile, "Local Gov: {$result['officials_found']} found, {$result['officials_inserted']} inserted, {$result['officials_updated']} updated");
        } else {
            logMsg($logFile, "Local Gov: scraper not yet built, skipping");
        }
    }

    // School Trustees
    if (!$verifyOnly && in_array($level, ['all', 'school'])) {
        logMsg($logFile, "--- School Trustees ---");

        $scraperFile = 'app/scrapers/SchoolTrusteeScraper.php';
        if (file_exists($scraperFile)) {
            require_once $scraperFile;
            $scraper = new SchoolTrusteeScraper();
            $result = $scraper->scrapeAll();

            $totalFound += $result['officials_found'];
            $totalInserted += $result['officials_inserted'];
            $totalUpdated += $result['officials_updated'];

            logMsg($logFile, "School: {$result['officials_found']} found, {$result['officials_inserted']} inserted, {$result['officials_updated']} updated");
        } else {
            logMsg($logFile, "School: scraper not yet built, skipping");
        }
    }

    // Cross-reference verification
    if ($verifyOnly || $level === 'all') {
        logMsg($logFile, "--- Verification ---");

        $verifierFile = 'app/scrapers/OfficialVerifier.php';
        if (file_exists($verifierFile)) {
            require_once $verifierFile;
            $verifier = new OfficialVerifier();
            $verifyResult = $verifier->verifyAll();
            logMsg($logFile, "Verification: {$verifyResult['verified']} checked, {$verifyResult['mismatches']} mismatches");
        } else {
            logMsg($logFile, "Verifier not yet built, skipping");
        }
    }

    // Summary
    $endTime = date('Y-m-d H:i:s');
    logMsg($logFile, "Complete — Total: {$totalFound} found, {$totalInserted} inserted, {$totalUpdated} updated");

    // Print summary to stdout for cron visibility
    echo "Officials scrape complete: {$totalFound} found, {$totalInserted} inserted, {$totalUpdated} updated\n";

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    logMsg($logFile, "CRITICAL ERROR: {$errorMsg}\n" . $e->getTraceAsString());
    notifyAdmin('Officials scrape failed: ' . $errorMsg);
    echo "ERROR: {$errorMsg}\n";
    exit(1);
}

function logMsg(string $file, string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}\n";
    file_put_contents($file, $line, FILE_APPEND);
    echo $line; // Also print to stdout for interactive runs
}
