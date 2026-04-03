<?php
// ============================================================
//  logout.php — Secure Session Termination
//  Works for both Admin and User dashboards
// ============================================================
session_start();

// 1. Unset all session variables
$_SESSION = [];

// 2. Destroy the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// 3. Destroy the session
session_destroy();

// 4. Redirect back to login with logout flag
header('Location: ../index.php?logout=1');
exit;