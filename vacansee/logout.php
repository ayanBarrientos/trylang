<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    $conn = getConnection();
    logActivity($conn, $_SESSION['user_id'], 'logout', 'User logged out');
    closeConnection($conn);
}

// Destroy all session data
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>