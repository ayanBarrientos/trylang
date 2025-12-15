<?php
// Application timezone (affects "current time" checks for occupancy and reservations)
date_default_timezone_set('Asia/Manila');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'vacansee');

// Create connection
function getConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        // Align MySQL session timezone with PHP (mainly for any NOW()/CURDATE() usage).
        // TIME/DATE columns are stored without timezone, but this keeps server-side queries consistent.
        $conn->query("SET time_zone = '+08:00'");
        
        return $conn;
    } catch (Exception $e) {
        error_log($e->getMessage());
        die("Database connection failed. Please try again later.");
    }
}

// Close connection
function closeConnection($conn) {
    if ($conn) {
        $conn->close();
    }
}
?>
