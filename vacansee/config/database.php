<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'vacansee_db');

// Create connection
function getConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
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