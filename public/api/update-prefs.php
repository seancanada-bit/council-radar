<?php
/**
 * CouncilRadar - Update Subscriber Preferences (AJAX)
 *
 * Requires login session.
 * Expects JSON POST: {municipalities_filter, keywords_filter, frequency}
 */

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/functions.php';
require_once __DIR__ . '/../../app/auth/Session.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
}

Session::startSession();

if (!Session::isLoggedIn()) {
    jsonResponse(['success' => false, 'error' => 'Authentication required.'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['success' => false, 'error' => 'Invalid request body.'], 400);
}

$subscriberId       = Session::getSubscriberId();
$municipalitiesFilter = $input['municipalities_filter'] ?? null;
$keywordsFilter       = $input['keywords_filter'] ?? null;
$frequency            = $input['frequency'] ?? null;

$db = DB::get();

// Get current subscriber info
$stmt = $db->prepare('SELECT tier FROM subscribers WHERE id = ?');
$stmt->execute([$subscriberId]);
$subscriber = $stmt->fetch();

if (!$subscriber) {
    jsonResponse(['success' => false, 'error' => 'Subscriber not found.'], 404);
}

// Validate frequency
$validFrequencies = ['daily', 'weekly'];
if ($frequency !== null && !in_array($frequency, $validFrequencies, true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid frequency. Must be daily or weekly.'], 422);
}

// Daily frequency requires a paid tier
if ($frequency === 'daily' && $subscriber['tier'] === 'free') {
    jsonResponse(['success' => false, 'error' => 'Daily alerts require a paid plan. Please upgrade.'], 403);
}

// Validate municipalities_filter is array of integers if provided
if ($municipalitiesFilter !== null) {
    if (!is_array($municipalitiesFilter)) {
        jsonResponse(['success' => false, 'error' => 'municipalities_filter must be an array.'], 422);
    }
    $municipalitiesFilter = array_map('intval', $municipalitiesFilter);
}

// Validate keywords_filter is array of strings if provided
if ($keywordsFilter !== null) {
    if (!is_array($keywordsFilter)) {
        jsonResponse(['success' => false, 'error' => 'keywords_filter must be an array.'], 422);
    }
    $keywordsFilter = array_map('trim', $keywordsFilter);
    $keywordsFilter = array_filter($keywordsFilter, fn($k) => $k !== '');
}

// Build update query dynamically
$updates = [];
$params = [];

if ($municipalitiesFilter !== null) {
    $updates[] = 'municipalities_filter = ?';
    $params[] = json_encode($municipalitiesFilter);
}

if ($keywordsFilter !== null) {
    $updates[] = 'keywords_filter = ?';
    $params[] = json_encode($keywordsFilter);
}

if ($frequency !== null) {
    $updates[] = 'frequency = ?';
    $params[] = $frequency;
}

if (empty($updates)) {
    jsonResponse(['success' => false, 'error' => 'No preferences to update.'], 422);
}

$params[] = $subscriberId;

$sql = 'UPDATE subscribers SET ' . implode(', ', $updates) . ' WHERE id = ?';
$stmt = $db->prepare($sql);
$stmt->execute($params);

jsonResponse(['success' => true, 'message' => 'Preferences updated.']);
