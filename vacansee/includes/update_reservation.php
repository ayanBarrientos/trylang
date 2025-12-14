<?php
session_start();
require_once '../config/database.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$reservation_id = (int)$_POST['reservation_id'];
$status = sanitizeInput($_POST['status']);
$admin_notes = isset($_POST['admin_notes']) ? sanitizeInput($_POST['admin_notes']) : '';

$conn = getConnection();

// Fetch current status to enforce rules (e.g., admin cannot cancel approved)
$currentStmt = $conn->prepare("SELECT status FROM reservations WHERE id = ?");
$currentStmt->bind_param("i", $reservation_id);
$currentStmt->execute();
$current = $currentStmt->get_result()->fetch_assoc();
$currentStmt->close();

if (!$current) {
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Reservation not found']);
    exit();
}

if ($status === 'cancelled' && $current['status'] === 'approved') {
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Approved reservations cannot be cancelled by admin']);
    exit();
}

// Update reservation status
$stmt = $conn->prepare("UPDATE reservations SET status = ?, admin_notes = ? WHERE id = ?");
$stmt->bind_param("ssi", $status, $admin_notes, $reservation_id);

if ($stmt->execute()) {
    // Reset faculty_viewed when a reservation is approved so it surfaces in the faculty badge
    if ($status === 'approved') {
        $resetViewed = $conn->prepare("UPDATE reservations SET faculty_viewed = 0 WHERE id = ?");
        $resetViewed->bind_param("i", $reservation_id);
        $resetViewed->execute();
        $resetViewed->close();
    }
    // Log activity
    logActivity($conn, $_SESSION['user_id'], 'update_reservation', "Updated reservation $reservation_id to $status");
    
    // Update room status when reservation moves to approved or cancelled
    if ($status === 'approved') {
        $update_room = $conn->prepare("
            UPDATE rooms r
            JOIN reservations res ON r.id = res.room_id
            SET r.status = 'reserved'
            WHERE res.id = ?
        ");
        $update_room->bind_param("i", $reservation_id);
        $update_room->execute();
        $update_room->close();
    } elseif ($status === 'cancelled') {
        $vacate_room = $conn->prepare("
            UPDATE rooms r
            JOIN reservations res ON r.id = res.room_id
            SET r.status = 'vacant'
            WHERE res.id = ?
        ");
        $vacate_room->bind_param("i", $reservation_id);
        $vacate_room->execute();
        $vacate_room->close();
    }
    
    $stmt->close();
    closeConnection($conn);
    
    echo json_encode(['success' => true, 'message' => 'Reservation updated successfully']);
} else {
    $stmt->close();
    closeConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to update reservation: ' . $conn->error]);
}
?>
