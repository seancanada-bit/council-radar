<?php
/**
 * Authentication logic for CouncilRadar
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

class Auth {

    /**
     * Register a new subscriber with bcrypt-hashed password
     *
     * @return array|false Subscriber array on success, false on failure
     */
    public static function register(
        string $email,
        string $password,
        ?string $name,
        ?string $org,
        string $consentIp,
        string $consentText
    ) {
        $db = DB::get();

        // Check if email already exists
        $stmt = $db->prepare('SELECT id FROM subscribers WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        if ($stmt->fetch()) {
            return false;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $verifyToken = generateToken();
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO subscribers
                (email, password_hash, name, organization, verify_token,
                 consent_date, consent_method, consent_ip, consent_text,
                 tier, active, email_verified, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            strtolower(trim($email)),
            $hash,
            $name ?: null,
            $org ?: null,
            $verifyToken,
            $now,
            'web_form',
            $consentIp,
            $consentText,
            'free',
            1,
            0,
            $now,
        ]);

        $id = (int) $db->lastInsertId();

        return [
            'id'           => $id,
            'email'        => strtolower(trim($email)),
            'name'         => $name,
            'organization' => $org,
            'verify_token' => $verifyToken,
        ];
    }

    /**
     * Authenticate a subscriber by email and password
     *
     * @return array|false Subscriber array on success, false on failure
     */
    public static function login(string $email, string $password) {
        $db = DB::get();

        $stmt = $db->prepare(
            'SELECT id, email, name, password_hash, email_verified, active, tier
             FROM subscribers
             WHERE email = ?'
        );
        $stmt->execute([strtolower(trim($email))]);
        $subscriber = $stmt->fetch();

        if (!$subscriber) {
            return false;
        }

        if (!password_verify($password, $subscriber['password_hash'])) {
            return false;
        }

        if (!$subscriber['active']) {
            return false;
        }

        unset($subscriber['password_hash']);
        return $subscriber;
    }

    /**
     * Verify a subscriber's email address by token
     *
     * @return bool True if verification succeeded
     */
    public static function verifyEmail(string $token): bool {
        $db = DB::get();

        $stmt = $db->prepare(
            'SELECT id FROM subscribers WHERE verify_token = ?'
        );
        $stmt->execute([$token]);
        $subscriber = $stmt->fetch();

        if (!$subscriber) {
            return false;
        }

        $stmt = $db->prepare(
            'UPDATE subscribers SET email_verified = 1, verify_token = NULL WHERE id = ?'
        );
        $stmt->execute([$subscriber['id']]);

        return true;
    }

    /**
     * Generate a password reset token for a subscriber
     *
     * @return string|false The reset token, or false if email not found
     */
    public static function requestPasswordReset(string $email) {
        $db = DB::get();

        $stmt = $db->prepare('SELECT id FROM subscribers WHERE email = ? AND active = 1');
        $stmt->execute([strtolower(trim($email))]);
        $subscriber = $stmt->fetch();

        if (!$subscriber) {
            return false;
        }

        $token = generateToken();
        $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        $stmt = $db->prepare(
            'UPDATE subscribers SET reset_token = ?, reset_token_expires = ? WHERE id = ?'
        );
        $stmt->execute([$token, $expiry, $subscriber['id']]);

        return $token;
    }

    /**
     * Reset a subscriber's password using a valid reset token
     *
     * @return bool True if reset succeeded
     */
    public static function resetPassword(string $token, string $newPassword): bool {
        $db = DB::get();

        $stmt = $db->prepare(
            'SELECT id FROM subscribers
             WHERE reset_token = ? AND reset_token_expires > NOW()'
        );
        $stmt->execute([$token]);
        $subscriber = $stmt->fetch();

        if (!$subscriber) {
            return false;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $db->prepare(
            'UPDATE subscribers
             SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL
             WHERE id = ?'
        );
        $stmt->execute([$hash, $subscriber['id']]);

        return true;
    }
}
