<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$conn = getConnection();

$date = isset($_REQUEST['date']) ? $_REQUEST['date'] : '';
$time = isset($_REQUEST['time']) ? $_REQUEST['time'] : '';

if (!$date || !$time) {
    // return empty result
    echo json_encode([]);
    closeConnection($conn);
    exit();
}

$response = [];
$leaveApprovalCondition = leaveNotesUseApproval($conn) ? "AND fln.status = 'approved'" : "";

// 1) Reservations at that date/time (approved) - include exact start minute, exclude end minute
$resSql = "SELECT r.*, u.first_name, u.last_name FROM reservations r JOIN users u ON r.faculty_id = u.id WHERE DATE(r.reservation_date) = ? AND r.start_time <= ? AND r.end_time > ? AND r.status = 'approved'";
$stmt = $conn->prepare($resSql);
$stmt->bind_param('sss', $date, $time, $time);
$stmt->execute();
$resRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($resRows as $r) {
    $roomId = (int)$r['room_id'];
    if (!isset($response[$roomId])) $response[$roomId] = ['occupant' => null, 'schedules' => []];
    $response[$roomId]['occupant'] = [
        'type' => 'reservation',
        'faculty_name' => trim($r['first_name'] . ' ' . $r['last_name']),
        'faculty_id' => isset($r['faculty_id']) ? (int)$r['faculty_id'] : null,
        'class_code' => isset($r['class_code']) ? $r['class_code'] : ($r['subject_name'] ?? ''),
        'subject_code' => $r['subject_code'] ?? '',
        'start_time' => $r['start_time'],
        'end_time' => $r['end_time']
    ];
}

// 2) Schedules for the day-of-week at that time (only if no reservation) - same inclusive start/exclusive end logic
$dayName = date('l', strtotime($date));
// match schedules case-insensitively and include room code for convenience
$schSql = "
    SELECT s.*, u.first_name, u.last_name, rm.room_code
      FROM schedules s
      JOIN users u ON s.faculty_id = u.id
      LEFT JOIN rooms rm ON s.room_id = rm.id
     WHERE LOWER(s.day_of_week) = LOWER(?)
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
";
$stmt = $conn->prepare($schSql);
$stmt->bind_param('ssssss', $dayName, $time, $time, $date, $time, $time);
$stmt->execute();
$schRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($schRows as $s) {
    $roomId = (int)$s['room_id'];
    if (!isset($response[$roomId])) $response[$roomId] = ['occupant' => null, 'schedules' => []];
    // add this schedule to the schedules list for the room
    $response[$roomId]['schedules'][] = [
        'id' => isset($s['id']) ? (int)$s['id'] : null,
        'room_id' => $roomId,
        'room_code' => $s['room_code'] ?? '',
        'day_of_week' => $s['day_of_week'] ?? '',
        'faculty_id' => isset($s['faculty_id']) ? (int)$s['faculty_id'] : null,
        'faculty_name' => trim($s['first_name'] . ' ' . $s['last_name']),
        'class_code' => isset($s['class_code']) ? $s['class_code'] : ($s['subject_name'] ?? ''),
        'subject_code' => $s['subject_code'] ?? '',
        'start_time' => $s['start_time'],
        'end_time' => $s['end_time']
    ];
    // if there's no occupant set (no reservation), set the first matching schedule as occupant
    if ($response[$roomId]['occupant'] === null) {
        $response[$roomId]['occupant'] = [
            'type' => 'schedule',
            'faculty_name' => trim($s['first_name'] . ' ' . $s['last_name']),
            'faculty_id' => isset($s['faculty_id']) ? (int)$s['faculty_id'] : null,
            'class_code' => isset($s['class_code']) ? $s['class_code'] : ($s['subject_name'] ?? ''),
            'subject_code' => $s['subject_code'] ?? '',
            'start_time' => $s['start_time'],
            'end_time' => $s['end_time']
        ];
    }
}

echo json_encode($response);

closeConnection($conn);

?>
