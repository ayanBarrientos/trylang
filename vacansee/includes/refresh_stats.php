<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = getConnection();
$stats = [];

// Get real-time stats based on user role
if ($_SESSION['user_type'] === 'admin') {
    $queries = [
        'available_rooms' => "SELECT COUNT(*) as count FROM rooms WHERE status = 'vacant'",
        'pending_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'",
        'today_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE DATE(reservation_date) = CURDATE()"
    ];
} elseif ($_SESSION['user_type'] === 'faculty') {
    $user_id = $_SESSION['user_id'];
    $queries = [
        'pending_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE faculty_id = $user_id AND status = 'pending'",
        'today_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE faculty_id = $user_id AND DATE(reservation_date) = CURDATE()"
    ];
} else {
    $queries = [
        'available_rooms' => "SELECT COUNT(*) as count FROM rooms WHERE is_available = 1 AND status = 'vacant'"
    ];
}

foreach ($queries as $key => $sql) {
    $result = $conn->query($sql);
    $stats[$key] = $result->fetch_assoc()['count'];
}

closeConnection($conn);

echo json_encode([
    'success' => true,
    'stats' => $stats,
    'timestamp' => time()
]);
?>