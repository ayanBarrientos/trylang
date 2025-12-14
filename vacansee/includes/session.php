<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if user has specific role
function hasRole($role) {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === $role;
}

// Get current user ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Get current user type
function getCurrentUserType() {
    return $_SESSION['user_type'] ?? null;
}

// Destroy session (logout)
function destroySession() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

// Regenerate session ID for security
function regenerateSession() {
    session_regenerate_id(true);
}

// Set flash message
function setFlashMessage($type, $message) {
    $_SESSION['flash_messages'][$type] = $message;
}

// Get flash message
function getFlashMessage($type) {
    if (isset($_SESSION['flash_messages'][$type])) {
        $message = $_SESSION['flash_messages'][$type];
        unset($_SESSION['flash_messages'][$type]);
        return $message;
    }
    return null;
}

// Set CSRF token
function setCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Clear old sessions (garbage collection)
function clearOldSessions($max_lifetime = 3600) {
    $session_files = glob(session_save_path() . '/*');
    $now = time();
    
    foreach ($session_files as $file) {
        if (is_file($file) && ($now - filemtime($file) > $max_lifetime)) {
            unlink($file);
        }
    }
}
?>