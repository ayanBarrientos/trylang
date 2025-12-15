<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];
$room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
$subject_code = trim($_POST['subject_code'] ?? '');
$subject_name = trim($_POST['subject_name'] ?? '');
$reservation_date = date('Y-m-d'); // same-day occupancy only
$end_time_raw = trim($_POST['end_time'] ?? '');

if (!$room_id || !$subject_code || !$subject_name || !$end_time_raw) {
    echo json_encode(['success' => false, 'message' => 'All fields are required (including end time).']);
    exit();
}

// "Use This Room" is an immediate occupancy action.
// Force the reservation to start now so admin Room Management shows the room as occupied right away.
$now = time();
$start_time = date('H:i:s', $now);
$endTs = strtotime($reservation_date . ' ' . $end_time_raw);
if ($endTs === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid end time.']);
    exit();
}
if ($endTs <= $now) {
    echo json_encode(['success' => false, 'message' => 'End time must be after the current time.']);
    exit();
}
$end_time = date('H:i:s', $endTs);

try {
    $conn = getConnection();

    // If the room has an upcoming approved reservation later today by another faculty, prevent overlap.
    $nextStmt = $conn->prepare("
        SELECT r.start_time, r.faculty_id, u.first_name, u.last_name, r.subject_code, r.subject_name
          FROM reservations r
          JOIN users u ON r.faculty_id = u.id
         WHERE r.room_id = ?
           AND r.status = 'approved'
           AND r.reservation_date = ?
           AND r.start_time > ?
         ORDER BY r.start_time ASC
         LIMIT 1
    ");
    $nextStmt->bind_param("iss", $room_id, $reservation_date, $start_time);
    $nextStmt->execute();
    $next = $nextStmt->get_result()->fetch_assoc();
    $nextStmt->close();

    if ($next && (int)$next['faculty_id'] !== $faculty_id) {
        $reservedStartTs = strtotime($reservation_date . ' ' . ($next['start_time'] ?? ''));
        if ($reservedStartTs !== false && $endTs > $reservedStartTs) {
            $reservedBy = trim(($next['first_name'] ?? '') . ' ' . ($next['last_name'] ?? ''));
            $reservedStart = date('g:i A', $reservedStartTs);
            $reservedSubject = trim(($next['subject_code'] ?? '') . (!empty($next['subject_name']) ? ' - ' . $next['subject_name'] : ''));
            echo json_encode([
                'success' => false,
                'message' => "This room is reserved at {$reservedStart} by {$reservedBy}. End time must be before {$reservedStart}."
            ]);
            closeConnection($conn);
            exit();
        }
    }

    // Prevent faculty from double-booking themselves across different rooms.
    $facultyConflictStmt = $conn->prepare("
        SELECT 1 FROM reservations
         WHERE faculty_id = ?
           AND reservation_date = ?
           AND status IN ('pending','approved')
           AND (start_time < ? AND end_time > ?)
         LIMIT 1
    ");
    $facultyConflictStmt->bind_param("isss", $faculty_id, $reservation_date, $end_time, $start_time);
    $facultyConflictStmt->execute();
    $facultyConflict = $facultyConflictStmt->get_result()->num_rows > 0;
    $facultyConflictStmt->close();

    if ($facultyConflict) {
        echo json_encode(['success' => false, 'message' => 'You already have a reservation during that time.']);
        closeConnection($conn);
        exit();
    }

    // Prevent overlapping reservations/schedules for the same room/time
    $conflict = findRoomConflict($conn, $room_id, $reservation_date, $start_time, $end_time);
    if ($conflict) {
        $by = $conflict['faculty_name'] ?: 'another faculty member';
        $from = !empty($conflict['start_time']) ? date('g:i A', strtotime($conflict['start_time'])) : '';
        $until = !empty($conflict['end_time']) ? date('g:i A', strtotime($conflict['end_time'])) : '';
        $type = $conflict['type'] ?: ucfirst((string)($conflict['source'] ?? ''));
        echo json_encode([
            'success' => false,
            'message' => "This room is already occupied by {$by}" . (!empty($from) && !empty($until) ? " from {$from} to {$until}" : '') . " ({$type})."
        ]);
        closeConnection($conn);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO reservations
            (room_id, faculty_id, subject_code, subject_name, reservation_date, start_time, end_time, purpose, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Quick class booking', 'approved')
    ");
    $stmt->bind_param(
        "iisssss",
        $room_id,
        $faculty_id,
        $subject_code,
        $subject_name,
        $reservation_date,
        $start_time,
        $end_time
    );

    if ($stmt->execute()) {
        // Persist occupied status so admin Room Management reflects the action.
        try {
            $roomStmt = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ? AND status <> 'maintenance'");
            $roomStmt->bind_param("i", $room_id);
            $roomStmt->execute();
            $roomStmt->close();
        } catch (Throwable $e) {
            // Ignore status update failures; live occupancy still works.
        }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unable to save reservation.']);
    }
    $stmt->close();
    closeConnection($conn);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Unexpected error.']);
}
