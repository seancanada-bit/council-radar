<?php
/**
 * CouncilRadar - Email Verification Handler
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth/Session.php';
require_once __DIR__ . '/../app/auth/Auth.php';

Session::startSession();

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    flash('error', 'Invalid verification link.');
    redirect('/login.php');
}

if (Auth::verifyEmail($token)) {
    flash('success', 'Your email has been verified. You can now log in.');
} else {
    flash('error', 'Invalid or expired verification link. Your email may already be verified.');
}

redirect('/login.php');
