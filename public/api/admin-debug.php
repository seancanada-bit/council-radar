<?php
/**
 * Admin debug endpoint for remote diagnostics.
 * Protected by GIT_WEBHOOK_SECRET passed as ?key= parameter.
 *
 * Actions:
 *   ?action=ls&path=public          - list files in a directory (relative to repo root)
 *   ?action=read&path=logs/scrape.log&lines=50  - read last N lines of a file
 *   ?action=log&file=deploy         - shortcut to read a log file (deploy, scrape, parse, etc.)
 *   ?action=status                  - site health check (DB, config, file counts)
 *   ?action=phpinfo                 - PHP version and modules
 */

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/functions.php';

// Authenticate
$secret = env('GIT_WEBHOOK_SECRET');
$provided = $_GET['key'] ?? '';

if (!$secret || !hash_equals($secret, $provided)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? 'status';
$baseDir = realpath(__DIR__ . '/../../');

header('Content-Type: application/json');

switch ($action) {
    case 'ls':
        $relPath = $_GET['path'] ?? '';
        $relPath = str_replace('..', '', $relPath); // prevent traversal
        $fullPath = $baseDir . '/' . ltrim($relPath, '/');

        if (!is_dir($fullPath)) {
            echo json_encode(['error' => 'Not a directory', 'path' => $relPath]);
            break;
        }

        $files = [];
        foreach (scandir($fullPath) as $f) {
            if ($f === '.' || $f === '..') continue;
            $fp = $fullPath . '/' . $f;
            $files[] = [
                'name' => $f,
                'type' => is_dir($fp) ? 'dir' : 'file',
                'size' => is_file($fp) ? filesize($fp) : null,
                'modified' => date('Y-m-d H:i:s', filemtime($fp)),
            ];
        }
        echo json_encode(['path' => $relPath, 'files' => $files], JSON_PRETTY_PRINT);
        break;

    case 'read':
        $relPath = $_GET['path'] ?? '';
        $relPath = str_replace('..', '', $relPath);
        $fullPath = $baseDir . '/' . ltrim($relPath, '/');
        $lines = (int) ($_GET['lines'] ?? 50);

        if (!is_file($fullPath)) {
            echo json_encode(['error' => 'File not found', 'path' => $relPath]);
            break;
        }

        // Read last N lines
        $allLines = file($fullPath, FILE_IGNORE_NEW_LINES);
        $total = count($allLines);
        $start = max(0, $total - $lines);
        $content = array_slice($allLines, $start);

        echo json_encode([
            'path' => $relPath,
            'total_lines' => $total,
            'showing' => count($content),
            'content' => $content,
        ], JSON_PRETTY_PRINT);
        break;

    case 'log':
        $file = $_GET['file'] ?? 'deploy';
        $file = preg_replace('/[^a-z0-9_-]/i', '', $file);
        $logPath = $baseDir . '/logs/' . $file . '.log';
        $lines = (int) ($_GET['lines'] ?? 30);

        if (!is_file($logPath)) {
            echo json_encode(['error' => 'Log not found', 'file' => $file]);
            break;
        }

        $allLines = file($logPath, FILE_IGNORE_NEW_LINES);
        $total = count($allLines);
        $start = max(0, $total - $lines);
        $content = array_slice($allLines, $start);

        echo json_encode([
            'file' => $file . '.log',
            'total_lines' => $total,
            'showing' => count($content),
            'content' => $content,
        ], JSON_PRETTY_PRINT);
        break;

    case 'status':
        $status = [
            'php_version' => PHP_VERSION,
            'site_url' => SITE_URL,
            'db_name' => DB_NAME,
            'postmark_configured' => !empty(POSTMARK_API_KEY),
            'stripe_configured' => !empty(STRIPE_SECRET_KEY),
            'admin_email' => !empty(ADMIN_EMAIL),
        ];

        // Test DB
        try {
            require_once __DIR__ . '/../../app/db.php';
            $db = DB::get();
            $status['db_connected'] = true;

            $stmt = $db->query('SELECT COUNT(*) FROM municipalities WHERE active = 1');
            $status['active_municipalities'] = (int) $stmt->fetchColumn();

            $stmt = $db->query('SELECT COUNT(*) FROM meetings');
            $status['total_meetings'] = (int) $stmt->fetchColumn();

            $stmt = $db->query('SELECT COUNT(*) FROM agenda_items');
            $status['total_agenda_items'] = (int) $stmt->fetchColumn();

            $stmt = $db->query('SELECT COUNT(*) FROM agenda_items WHERE relevance_score > 0');
            $status['matched_items'] = (int) $stmt->fetchColumn();

            $stmt = $db->query('SELECT COUNT(*) FROM subscribers WHERE active = 1');
            $status['active_subscribers'] = (int) $stmt->fetchColumn();

            $stmt = $db->query('SELECT MAX(scraped_at) FROM meetings');
            $status['last_scrape'] = $stmt->fetchColumn();

        } catch (Exception $e) {
            $status['db_connected'] = false;
            $status['db_error'] = $e->getMessage();
        }

        // Check log files
        $logDir = $baseDir . '/logs';
        if (is_dir($logDir)) {
            $logFiles = glob($logDir . '/*.log');
            $status['log_files'] = array_map(function ($f) {
                return [
                    'name' => basename($f),
                    'size' => filesize($f),
                    'modified' => date('Y-m-d H:i:s', filemtime($f)),
                ];
            }, $logFiles);
        }

        echo json_encode($status, JSON_PRETTY_PRINT);
        break;

    case 'phpinfo':
        echo json_encode([
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'extensions' => get_loaded_extensions(),
            'ini' => [
                'display_errors' => ini_get('display_errors'),
                'error_reporting' => ini_get('error_reporting'),
                'max_execution_time' => ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit'),
            ],
        ], JSON_PRETTY_PRINT);
        break;

    case 'clear-subscribers':
        require_once __DIR__ . '/../../app/db.php';
        $db = DB::get();
        $db->exec('DELETE FROM alerts_sent');
        $stmt = $db->query('SELECT COUNT(*) FROM subscribers');
        $count = $stmt->fetchColumn();
        $db->exec('DELETE FROM subscribers');
        echo json_encode(['action' => 'clear-subscribers', 'deleted' => (int) $count]);
        break;

    case 'clear-rate-limits':
        require_once __DIR__ . '/../../app/db.php';
        $db = DB::get();
        $stmt = $db->query('SELECT COUNT(*) FROM rate_limits');
        $count = $stmt->fetchColumn();
        $db->exec('TRUNCATE TABLE rate_limits');
        echo json_encode(['action' => 'clear-rate-limits', 'cleared' => (int) $count]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action', 'available' => ['ls', 'read', 'log', 'status', 'phpinfo', 'clear-rate-limits']]);
}
