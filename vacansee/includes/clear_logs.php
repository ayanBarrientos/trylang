<?php
session_start();
require_once '../config/database.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$conn = getConnection();

// Delete logs older than 30 days
$sql = "DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
$stmt = $conn->prepare($sql);

if ($stmt->execute()) {
    $deleted_count = $stmt->affected_rows;
    
    // Log the clearing activity
    logActivity($conn, $_SESSION['user_id'], 'clear_logs', "Cleared $deleted_count old logs");
    
    $stmt->close();
    closeConnection($conn);
    
    echo json_encode([
        'success' => true, 
        'message' => "Successfully cleared $deleted_count old logs",
        'count' => $deleted_count
    ]);
} else {
    $stmt->close();
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to clear logs: ' . $conn->error]);
}
?>