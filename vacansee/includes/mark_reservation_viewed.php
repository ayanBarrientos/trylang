<?php
session_start();
require_once '../config/database.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$reservation_id = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;

if ($reservation_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reservation']);
    exit();
}

$faculty_id = $_SESSION['user_id'];
$conn = getConnection();

// Mark as viewed only if it belongs to the faculty and is approved
$stmt = $conn->prepare("
    UPDATE reservations 
    SET faculty_viewed = 1 
    WHERE id = ? 
      AND faculty_id = ? 
      AND status = 'approved'
");
$stmt->bind_param("ii", $reservation_id, $faculty_id);
$stmt->execute();
$updated = $stmt->affected_rows > 0;
$stmt->close();

// Fetch remaining unviewed approved reservations for badge update
$countStmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM reservations 
    WHERE faculty_id = ? 
      AND status = 'approved'
      AND faculty_viewed = 0
      AND (reservation_date > CURDATE() OR (reservation_date = CURDATE() AND end_time >= CURTIME()))
");
$countStmt->bind_param("i", $faculty_id);
$countStmt->execute();
$remaining = (int)$countStmt->get_result()->fetch_assoc()['count'];
$countStmt->close();

closeConnection($conn);

echo json_encode([
    'success' => $updated,
    'remaining' => $remaining
]);
?>
