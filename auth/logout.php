<?php
// ============================================================
//  logout.php — Secure Session Termination
//  Works for both Admin and User dashboards
// ============================================================
session_start();

// 1. Unset all session variables
// Remove all session variables
$_SESSION = [];
// Also clear session array and any registered session variables
@session_unset();

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
// Destroy session data on the server
session_destroy();
// Remove session cookie from PHP global cookies as well
if (isset($_COOKIE[session_name()])) {
    unset($_COOKIE[session_name()]);
}
// Regenerate session id to avoid session fixation
@session_regenerate_id(true);

// 4. Redirect back to login with logout flag
header('Location: ../index.php?logout=1');
exit;