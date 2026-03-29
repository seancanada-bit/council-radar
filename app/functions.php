<?php
/**
 * Shared utility functions
 */

/**
 * Sanitize output for HTML display
 */
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a cryptographically secure random token
 */
function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

/**
 * Get the client IP address
 */
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check rate limit for an action from an IP
 */
function checkRateLimit(string $action, int $maxAttempts, int $windowSeconds): bool {
    $db = DB::get();
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
    $ip = getClientIp();

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM rate_limits WHERE ip_address = ? AND action = ? AND attempted_at > ?'
    );
    $stmt->execute([$ip, $action, $cutoff]);
    return $stmt->fetchColumn() < $maxAttempts;
}

/**
 * Record a rate limit attempt
 */
function recordRateLimit(string $action): void {
    $db = DB::get();
    $stmt = $db->prepare('INSERT INTO rate_limits (ip_address, action) VALUES (?, ?)');
    $stmt->execute([getClientIp(), $action]);
}

/**
 * Generate and store a CSRF token in the session
 */
function csrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token
 */
function verifyCsrf(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Render a CSRF hidden input field
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

/**
 * Redirect to a URL and exit
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Set a flash message for the next page load
 */
function flash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get and clear flash messages
 */
function getFlash(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * JSON response helper for API endpoints
 */
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Log a message to a file
 */
function logMessage(string $file, string $message): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(
        $logDir . '/' . $file,
        "[$timestamp] $message\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Send admin notification email on critical failure
 */
function notifyAdmin(string $subject, string $body): void {
    if (empty(ADMIN_EMAIL) || empty(POSTMARK_API_KEY)) {
        logMessage('error.log', "Admin notification failed (no config): $subject");
        return;
    }
    try {
        require_once __DIR__ . '/email/PostmarkClient.php';
        $pm = new PostmarkClient();
        $pm->send(ADMIN_EMAIL, $subject, '<pre>' . h($body) . '</pre>', $body);
    } catch (Exception $e) {
        logMessage('error.log', "Admin notification failed: " . $e->getMessage());
    }
}

/**
 * Clean old rate limit records
 */
function cleanRateLimits(): void {
    $db = DB::get();
    $db->exec("DELETE FROM rate_limits WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}
