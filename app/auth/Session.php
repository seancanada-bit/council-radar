<?php
/**
 * Session management for CouncilRadar
 */

class Session {

    /**
     * Start a secure session with strict settings
     */
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite'  => 'Strict',
        ]);

        session_name('cr_session');
        session_start();
    }

    /**
     * Check if a subscriber is logged in
     */
    public static function isLoggedIn(): bool {
        self::startSession();
        return isset($_SESSION['subscriber_id']);
    }

    /**
     * Get the current subscriber ID or null
     */
    public static function getSubscriberId(): ?int {
        self::startSession();
        return $_SESSION['subscriber_id'] ?? null;
    }

    /**
     * Log in a subscriber by setting session variables
     */
    public static function login(int $subscriberId): void {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['subscriber_id'] = $subscriberId;
        $_SESSION['logged_in_at'] = time();
    }

    /**
     * Destroy the session and log out
     */
    public static function logout(): void {
        self::startSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Require login - redirect to login page if not authenticated
     */
    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            redirect('/login.php');
        }
    }
}
