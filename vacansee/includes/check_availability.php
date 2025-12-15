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

// Optional filters (used by student dashboard auto-refresh)
$department = isset($_GET['department']) ? $_GET['department'] : '';
$amenities = isset($_GET['amenities']) ? $_GET['amenities'] : [];

// Build query for rooms
$sql = "SELECT * FROM rooms WHERE is_available = 1";
$params = [];
$types = "";

if ($department) {
    $sql .= " AND department = ?";
    $params[] = $department;
    $types .= "s";
}

if (is_array($amenities)) {
    foreach ($amenities as $amenity) {
        switch ($amenity) {
            case 'aircon':
                $sql .= " AND has_aircon = 1";
                break;
            case 'projector':
                $sql .= " AND has_projector = 1";
                break;
            case 'computers':
                $sql .= " AND has_computers = 1";
                break;
            case 'whiteboard':
                $sql .= " AND has_whiteboard = 1";
                break;
        }
    }
}

$sql .= " ORDER BY department, room_code";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$available_rooms = [];
$occupied_count = 0;
$available_count = 0;

while ($row = $result->fetch_assoc()) {
    // Attach live occupancy (includes approved reservations + schedules)
    applyLiveOccupancy($row, $conn);

    // Student dashboard should not list reserved rooms
    if (($row['status_live'] ?? '') === 'reserved') {
        continue;
    }

    if (($row['status_live'] ?? '') === 'occupied') {
        $occupied_count++;
    }
    if (!empty($row['is_available_live']) && ($row['status_live'] ?? '') === 'vacant') {
        $available_count++;
    }
    $available_rooms[] = $row;
}

$stmt->close();
closeConnection($conn);

echo json_encode([
    'success' => true,
    'rooms' => $available_rooms,
    // Backwards-compat: `count` means available rooms
    'count' => $available_count,
    'available' => $available_count,
    'occupied' => $occupied_count,
    'total' => count($available_rooms),
    'timestamp' => time(),
    'current_time' => $current_time,
    'current_day' => $current_day
]);
?>
