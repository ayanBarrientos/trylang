<?php
session_start();
require_once '../config/database.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$reservation_id = (int)$_POST['reservation_id'];
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

$conn = getConnection();

// Check if reservation exists and user has permission to cancel it
$sql = "SELECT * FROM reservations WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $reservation_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Reservation not found']);
    exit();
}

// Check permissions
if ($user_type === 'faculty' && $reservation['faculty_id'] !== $user_id) {
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'You can only cancel your own reservations']);
    exit();
}

if ($user_type === 'admin' && $reservation['status'] === 'approved') {
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Approved reservations can only be cancelled by the faculty owner']);
    exit();
}

// Check if reservation can be cancelled (faculty cannot cancel past reservations)
$reservation_date = strtotime($reservation['reservation_date']);
$current_date = strtotime(date('Y-m-d'));

if ($reservation_date < $current_date && $user_type === 'faculty') {
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Cannot cancel past reservations']);
    exit();
}

// Update reservation status to cancelled
$update_stmt = $conn->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?");
$update_stmt->bind_param("i", $reservation_id);

if ($update_stmt->execute()) {
    // Update room status if it was reserved
    if ($reservation['status'] === 'approved') {
        $room_update = $conn->prepare("
            UPDATE rooms 
            SET status = 'vacant' 
            WHERE id = ? AND status = 'reserved'
        ");
        $room_update->bind_param("i", $reservation['room_id']);
        $room_update->execute();
        $room_update->close();
    }
    
    // Log activity
    logActivity($conn, $user_id, 'cancel_reservation', "Cancelled reservation ID: $reservation_id");
    
    $update_stmt->close();
    closeConnection($conn);
    
    echo json_encode(['success' => true, 'message' => 'Reservation cancelled successfully']);
} else {
    $update_stmt->close();
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to cancel reservation: ' . $conn->error]);
}
?>
