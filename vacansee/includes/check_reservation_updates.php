<?php
session_start();
require_once '../config/database.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$last_check = isset($_GET['last_check']) ? $_GET['last_check'] : date('Y-m-d H:i:s', strtotime('-5 minutes'));

$conn = getConnection();

if ($user_type === 'faculty') {
    // Check for status updates on faculty's reservations
    $sql = "
        SELECT COUNT(*) as updated 
        FROM reservations 
        WHERE faculty_id = ? 
        AND status IN ('approved', 'rejected') 
        AND updated_at > ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $user_id, $last_check);
} else {
    // For admin, check for new pending reservations
    $sql = "
        SELECT COUNT(*) as updated 
        FROM reservations 
        WHERE status = 'pending' 
        AND created_at > ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $last_check);
}

$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$updated = $result['updated'] > 0;

$stmt->close();
closeConnection($conn);

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'count' => $result['updated'],
    'timestamp' => date('Y-m-d H:i:s')
]);
?>