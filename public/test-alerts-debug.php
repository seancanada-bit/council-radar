<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';

echo "<pre>";
$db = DB::get();
$stmt = $db->query('SELECT ai.keywords_matched FROM agenda_items ai WHERE ai.relevance_score > 0 LIMIT 1');
$row = $stmt->fetch();

echo "Raw keywords_matched:\n";
var_dump($row['keywords_matched']);

echo "\nDecoded:\n";
$keywords = json_decode($row['keywords_matched'] ?? '[]', true);
var_dump($keywords);

echo "\nIterating:\n";
foreach ($keywords as $kw) {
    echo "Type: " . gettype($kw) . "\n";
    if (is_array($kw)) {
        echo "  keyword: " . ($kw['keyword'] ?? 'N/A') . "\n";
        echo "  tier: " . ($kw['tier'] ?? 'N/A') . "\n";
    } else {
        echo "  value: $kw\n";
    }
}
echo "</pre>";
