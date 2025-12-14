<?php
session_start();
require_once '../config/database.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = getConnection();
$current_time = date('H:i:s');
$current_day = date('l');
$current_date = date('Y-m-d');
$leaveApprovalCondition = leaveNotesUseApproval($conn) ? "AND fln.status = 'approved'" : "";

// Get all rooms with current occupancy status
$sql = "
    SELECT r.*, 
    (SELECT COUNT(*) FROM schedules s 
     WHERE s.room_id = r.id 
     AND LOWER(s.day_of_week) = LOWER(?) 
     AND s.start_time <= ? 
     AND s.end_time > ? 
     AND s.is_active = 1
     AND NOT EXISTS (
        SELECT 1 FROM faculty_leave_notes fln
        WHERE fln.faculty_id = s.faculty_id
        AND fln.leave_date = ?
        AND fln.start_time <= ?
        AND fln.end_time > ?
        $leaveApprovalCondition
     )
    ) as is_occupied
    FROM rooms r 
    WHERE r.is_available = 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssss", $current_day, $current_time, $current_time, $current_date, $current_time, $current_time);
$stmt->execute();
$result = $stmt->get_result();

$available_rooms = [];
$occupied_count = 0;
$available_count = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['is_occupied'] > 0) {
        $row['status'] = 'occupied';
        $occupied_count++;
    } else {
        $row['status'] = 'vacant';
        $available_count++;
    }
    $available_rooms[] = $row;
}

$stmt->close();
closeConnection($conn);

echo json_encode([
    'success' => true,
    'rooms' => $available_rooms,
    'count' => $available_count,
    'occupied' => $occupied_count,
    'timestamp' => time(),
    'current_time' => $current_time,
    'current_day' => $current_day
]);
?>
