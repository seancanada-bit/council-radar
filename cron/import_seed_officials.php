<?php
/**
 * Import manually curated officials from config/seed_officials.json
 *
 * This covers officials that can't be scraped automatically due to:
 *   - Cloudflare-protected websites
 *   - JS-rendered pages
 *   - Sites that don't publish individual emails
 *
 * Safe to run multiple times - uses upsert logic.
 *
 * Usage:
 *   php cron/import_seed_officials.php
 *   php cron/import_seed_officials.php --dry-run
 */

chdir(__DIR__ . '/..');

require 'app/config.php';
require 'app/db.php';
require 'app/functions.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$seedFile = __DIR__ . '/../config/seed_officials.json';

if (!file_exists($seedFile)) {
    echo "ERROR: Seed file not found: {$seedFile}\n";
    exit(1);
}

$data = json_decode(file_get_contents($seedFile), true);
if (!$data || !is_array($data)) {
    echo "ERROR: Invalid JSON in seed file\n";
    exit(1);
}

echo "Importing " . count($data) . " officials from seed file" . ($dryRun ? ' (DRY RUN)' : '') . "\n";

$db = DB::get();
$inserted = 0;
$updated = 0;
$skipped = 0;

foreach ($data as $official) {
    $name = trim($official['name'] ?? '');
    $role = trim($official['role'] ?? '');
    $jurisdiction = trim($official['jurisdiction'] ?? '');
    $email = trim($official['email'] ?? '');
    $phone = trim($official['phone'] ?? '');
    $level = trim($official['level'] ?? 'municipal');

    if (!$name || !$jurisdiction) {
        echo "  SKIP: Missing name or jurisdiction\n";
        $skipped++;
        continue;
    }

    // Map level names to enum values
    $levelMap = [
        'municipal' => 'municipal',
        'regional' => 'regional_district',
        'regional_district' => 'regional_district',
        'provincial' => 'provincial',
        'school_board' => 'school_board',
    ];
    $govLevel = $levelMap[$level] ?? 'municipal';

    // Split name
    $parts = preg_split('/\s+/', $name);
    $lastName = array_pop($parts);
    $firstName = implode(' ', $parts);

    // Find municipality_id if applicable
    $municipalityId = null;
    if ($govLevel === 'municipal') {
        $stmt = $db->prepare('SELECT id FROM municipalities WHERE name LIKE ? LIMIT 1');
        $stmt->execute(["%{$jurisdiction}%"]);
        $municipalityId = $stmt->fetchColumn() ?: null;
    }

    // Check if exists
    $stmt = $db->prepare(
        'SELECT id, email, source_name FROM elected_officials
         WHERE name = ? AND jurisdiction_name = ? AND government_level = ?'
    );
    $stmt->execute([$name, $jurisdiction, $govLevel]);
    $existing = $stmt->fetch();

    if ($dryRun) {
        $action = $existing ? 'UPDATE' : 'INSERT';
        echo "  {$action}: {$name} ({$role}, {$jurisdiction})" . ($email ? " <{$email}>" : '') . "\n";
        continue;
    }

    if ($existing) {
        // Only update if we have new data (email or phone) that's better than what's there
        $sets = [];
        $params = [];

        if ($email && !$existing['email']) {
            $sets[] = 'email = ?';
            $params[] = $email;
        }
        if ($phone) {
            $sets[] = 'phone = COALESCE(NULLIF(?, ""), phone)';
            $params[] = $phone;
        }

        // Always update role and name parts
        $sets[] = 'role = ?';
        $params[] = $role;
        $sets[] = 'first_name = ?';
        $params[] = $firstName;
        $sets[] = 'last_name = ?';
        $params[] = $lastName;

        // Mark as seed data if not already from a better source
        if (!$existing['source_name'] || $existing['source_name'] === 'seed_data') {
            $sets[] = 'source_name = ?';
            $params[] = $email ? 'seed_data+verified' : 'seed_data';
        }

        $sets[] = 'updated_at = NOW()';
        $params[] = $existing['id'];

        $stmt = $db->prepare('UPDATE elected_officials SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        $updated++;
        echo "  UPDATED: {$name} ({$jurisdiction})\n";
    } else {
        $stmt = $db->prepare(
            'INSERT INTO elected_officials
                (government_level, jurisdiction_name, municipality_id, name, first_name, last_name,
                 role, email, phone, source_url, source_name, confidence_score)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                email = COALESCE(NULLIF(VALUES(email), ""), email),
                role = VALUES(role),
                updated_at = NOW()'
        );
        $confidence = $email ? 2 : 1;
        $sourceName = $email ? 'seed_data+verified' : 'seed_data';
        $stmt->execute([
            $govLevel, $jurisdiction, $municipalityId, $name, $firstName, $lastName,
            $role, $email, $phone,
            '', $sourceName, $confidence
        ]);
        $inserted++;
        echo "  INSERTED: {$name} ({$role}, {$jurisdiction})" . ($email ? " <{$email}>" : '') . "\n";
    }
}

echo "\nDone: {$inserted} inserted, {$updated} updated, {$skipped} skipped\n";
