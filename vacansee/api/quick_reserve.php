<?php
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'faculty') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = getConnection();
$faculty_id = (int)$_SESSION['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    closeConnection($conn);
    exit();
}

$room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
$subject_code = sanitizeInput($_POST['subject_code'] ?? '');
$class_code = sanitizeInput($_POST['class_code'] ?? '');
$reservation_date = sanitizeInput($_POST['reservation_date'] ?? '');
$start_time = sanitizeInput($_POST['start_time'] ?? '');
$end_time = sanitizeInput($_POST['end_time'] ?? '');
$purpose = sanitizeInput($_POST['purpose'] ?? '');

if ($room_id <= 0 || !$reservation_date || !$start_time || !$end_time || !$subject_code || !$class_code) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    closeConnection($conn);
    exit();
}

if ($start_time >= $end_time) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
    closeConnection($conn);
    exit();
}

// Ensure room exists and is available
$roomStmt = $conn->prepare("SELECT id FROM rooms WHERE id = ? AND is_available = 1 LIMIT 1");
$roomStmt->bind_param("i", $room_id);
$roomStmt->execute();
$roomExists = $roomStmt->get_result()->num_rows > 0;
$roomStmt->close();

if (!$roomExists) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Room not found or not available.']);
    closeConnection($conn);
    exit();
}

// Check if room is already reserved for the selected time slot (pending or approved)
$check_availability = $conn->prepare("
    SELECT 1 FROM reservations
    WHERE room_id = ?
      AND reservation_date = ?
      AND (start_time < ? AND end_time > ?)
      AND status IN ('pending', 'approved')
    LIMIT 1
");
$check_availability->bind_param("isss", $room_id, $reservation_date, $end_time, $start_time);
$check_availability->execute();
$existing_reservations = $check_availability->get_result();
$check_availability->close();

if ($existing_reservations->num_rows > 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Room is already reserved for the selected time slot.']);
    closeConnection($conn);
    exit();
}

// Prevent faculty from double-booking themselves across different rooms.
$check_faculty_conflict = $conn->prepare("
    SELECT 1 FROM reservations
    WHERE faculty_id = ?
      AND reservation_date = ?
      AND (start_time < ? AND end_time > ?)
      AND status IN ('pending', 'approved')
    LIMIT 1
");
$check_faculty_conflict->bind_param("isss", $faculty_id, $reservation_date, $end_time, $start_time);
$check_faculty_conflict->execute();
$faculty_conflicts = $check_faculty_conflict->get_result();
$check_faculty_conflict->close();

if ($faculty_conflicts->num_rows > 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'You already have a reservation during the selected time slot.']);
    closeConnection($conn);
    exit();
}

// Insert reservation as pending
$stmt = $conn->prepare("
    INSERT INTO reservations
    (room_id, faculty_id, subject_code, class_code, reservation_date, start_time, end_time, purpose, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");
$stmt->bind_param(
    "iissssss",
    $room_id,
    $faculty_id,
    $subject_code,
    $class_code,
    $reservation_date,
    $start_time,
    $end_time,
    $purpose
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error submitting reservation request.']);
    $stmt->close();
    closeConnection($conn);
    exit();
}
$newId = $stmt->insert_id;
$stmt->close();

logActivity($conn, $faculty_id, 'reservation_request', "Requested reservation for room ID: $room_id (quick reserve), reservation ID: $newId");

echo json_encode([
    'success' => true,
    'message' => 'Reservation request submitted successfully! Waiting for admin approval.',
    'reservation_id' => (int)$newId
]);

closeConnection($conn);
