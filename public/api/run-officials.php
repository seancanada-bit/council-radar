<?php
/**
 * Admin endpoint to set up and run the officials scraper.
 * Protected by GIT_WEBHOOK_SECRET.
 *
 * Actions:
 *   ?action=setup   - Create the officials tables if they don't exist
 *   ?action=run     - Run the provincial scraper
 *   ?action=results - Show what's in the elected_officials table
 */

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/functions.php';

// Authenticate
$secret = env('GIT_WEBHOOK_SECRET');
$provided = $_GET['key'] ?? '';
if (!$secret || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'status';
$db = DB::get();

switch ($action) {
    case 'setup':
        // Create tables if they don't exist
        $statements = [
            "CREATE TABLE IF NOT EXISTS elected_officials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                government_level ENUM('provincial','municipal','regional_district','school_board') NOT NULL,
                jurisdiction_name VARCHAR(200) NOT NULL,
                municipality_id INT NULL,
                name VARCHAR(200) NOT NULL,
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                role VARCHAR(100) NOT NULL,
                party VARCHAR(100) NULL,
                email VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                fax VARCHAR(50) NULL,
                office_address TEXT NULL,
                constituency_office_address TEXT NULL,
                photo_url VARCHAR(500) NULL,
                source_url VARCHAR(500) NOT NULL,
                source_name VARCHAR(100) NOT NULL,
                confidence_score TINYINT DEFAULT 1,
                verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE SET NULL,
                INDEX idx_gov_level (government_level),
                INDEX idx_jurisdiction (jurisdiction_name),
                INDEX idx_role (role),
                INDEX idx_confidence (confidence_score),
                INDEX idx_source (source_name),
                UNIQUE KEY uk_person_jurisdiction (name, jurisdiction_name, government_level)
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS official_verifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                official_id INT NOT NULL,
                source_name VARCHAR(100) NOT NULL,
                source_url VARCHAR(500),
                fields_matched JSON,
                fields_mismatched JSON NULL,
                verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (official_id) REFERENCES elected_officials(id) ON DELETE CASCADE,
                INDEX idx_official (official_id),
                INDEX idx_verified (verified_at)
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS officials_scrape_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                scraper VARCHAR(50) NOT NULL,
                government_level ENUM('provincial','municipal','regional_district','school_board') NOT NULL,
                status ENUM('success','error','partial') NOT NULL,
                officials_found INT DEFAULT 0,
                officials_inserted INT DEFAULT 0,
                officials_updated INT DEFAULT 0,
                error_message TEXT NULL,
                duration_ms INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_scraper (scraper),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB"
        ];

        $results = [];
        foreach ($statements as $sql) {
            try {
                $db->exec($sql);
                preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m);
                $results[] = ($m[1] ?? 'unknown') . ': OK';
            } catch (Exception $e) {
                $results[] = 'ERROR: ' . $e->getMessage();
            }
        }

        echo json_encode(['action' => 'setup', 'results' => $results], JSON_PRETTY_PRINT);
        break;

    case 'run':
        $level = $_GET['level'] ?? 'provincial';

        $scraperMap = [
            'provincial' => ['file' => 'ProvincialScraper.php', 'class' => 'ProvincialScraper'],
            'municipal' => ['file' => 'LocalGovScraper.php', 'class' => 'LocalGovScraper'],
        ];

        if (!isset($scraperMap[$level])) {
            echo json_encode(['error' => "Level '$level' not supported. Available: " . implode(', ', array_keys($scraperMap))]);
            break;
        }

        $info = $scraperMap[$level];
        require_once __DIR__ . '/../../app/scrapers/' . $info['file'];
        $scraper = new $info['class']();

        try {
            $result = $scraper->scrapeAll();
            echo json_encode(['action' => 'run', 'level' => $level, 'result' => $result], JSON_PRETTY_PRINT);
        } catch (Exception $e) {
            echo json_encode(['action' => 'run', 'level' => $level, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
        }
        break;

    case 'results':
        $level = $_GET['level'] ?? 'provincial';
        $stmt = $db->prepare(
            'SELECT id, name, first_name, last_name, jurisdiction_name, role, party, email, phone, confidence_score, source_name
             FROM elected_officials
             WHERE government_level = ?
             ORDER BY last_name, first_name'
        );
        $stmt->execute([$level]);
        $officials = $stmt->fetchAll();

        // Also get counts
        $countStmt = $db->prepare('SELECT COUNT(*) FROM elected_officials WHERE government_level = ?');
        $countStmt->execute([$level]);
        $total = $countStmt->fetchColumn();

        echo json_encode([
            'action' => 'results',
            'level' => $level,
            'total' => $total,
            'officials' => $officials,
        ], JSON_PRETTY_PRINT);
        break;

    case 'test-civicinfo':
        // Test if CivicInfo BC is accessible from this server
        $testUrl = 'https://www.civicinfo.bc.ca/municipalities?id=1';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $testUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-CA,en-US;q=0.9,en;q=0.8',
                'Referer: https://www.civicinfo.bc.ca/',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $hasOfficials = $body ? (bool) preg_match('/Elected\s+Officials|Mayor|Councillor/i', $body) : false;
        $title = '';
        if ($body && preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $body, $hm)) {
            $title = strip_tags($hm[1]);
        }

        echo json_encode([
            'url' => $testUrl,
            'http_code' => $code,
            'curl_error' => $err ?: null,
            'body_length' => strlen($body ?: ''),
            'page_title' => $title,
            'has_officials_section' => $hasOfficials,
            'body_preview' => $body ? substr(strip_tags($body), 0, 500) : null,
        ], JSON_PRETTY_PRINT);
        break;

    default:
        echo json_encode(['error' => 'Unknown action', 'available' => ['setup', 'run', 'results', 'test-civicinfo']]);
}
