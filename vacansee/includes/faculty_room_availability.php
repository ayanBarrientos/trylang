<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'faculty') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = getConnection();

$status_filter = isset($_GET['status']) ? (string)$_GET['status'] : '';
$department_filter = isset($_GET['department']) ? (string)$_GET['department'] : '';
$avail_date = parseDateToYmd($_GET['avail_date'] ?? '') ?: date('Y-m-d');
$defaultStartTs = ceilTimeToInterval(time(), 30);
$avail_time = parseTimeToHis($_GET['avail_time'] ?? '') ?: date('H:i:s', $defaultStartTs);
$duration_minutes = isset($_GET['duration']) ? (int)$_GET['duration'] : 60;
$duration_minutes = max(0, $duration_minutes);
$avail_end_time = null;
if ($duration_minutes > 0) {
    $avail_end_time = addMinutesToTimeOnDate($avail_date, $avail_time, $duration_minutes);
    if (!$avail_end_time) {
        $duration_minutes = 60;
        $avail_end_time = addMinutesToTimeOnDate($avail_date, $avail_time, $duration_minutes);
    }
}
$vacant_first = !empty($_GET['vacant_first']);
$room_search = trim((string)($_GET['q'] ?? ''));

$rooms_query = "SELECT * FROM rooms ORDER BY department, room_code";
$rooms_raw = $conn->query($rooms_query)->fetch_all(MYSQLI_ASSOC);

$available_rooms = [];
$available_count_filtered = 0;
foreach ($rooms_raw as &$room) {
    applyLiveOccupancy($room, $conn);
    applyOccupancyForWindow($room, $conn, $avail_date, $avail_time, $avail_end_time);

    $room['next_reservation'] = null;
    if (empty($room['is_currently_occupied']) && ($room['status_live'] ?? '') === 'reserved') {
        $room['next_reservation'] = getNextApprovedReservation($conn, $room['id']);
    }

    $passes = true;
    if ($status_filter && ($room['status_window'] ?? '') !== $status_filter) {
        $passes = false;
    }
    if ($department_filter && ($room['department'] ?? '') !== $department_filter) {
        $passes = false;
    }
    if ($room_search) {
        $hay = strtolower(($room['room_code'] ?? '') . ' ' . ($room['room_name'] ?? '') . ' ' . ($room['department'] ?? ''));
        if (strpos($hay, strtolower($room_search)) === false) {
            $passes = false;
        }
    }

    if ($passes) {
        $isMaintenance = (($room['status'] ?? '') === 'maintenance');
        $isAvailable = !empty($room['is_available']);
        $isVacantInWindow = empty($room['is_occupied_window']);
        if (!$isMaintenance && $isAvailable && $isVacantInWindow) {
            $available_count_filtered++;
        }
        $available_rooms[] = $room;
    }
}
unset($room);

if ($vacant_first) {
    usort($available_rooms, function ($a, $b) {
        $aOcc = !empty($a['is_occupied_window']);
        $bOcc = !empty($b['is_occupied_window']);
        if ($aOcc !== $bOcc) {
            return $aOcc ? 1 : -1;
        }
        return strcmp((string)($a['room_code'] ?? ''), (string)($b['room_code'] ?? ''));
    });
}

$count = count($available_rooms);

ob_start();
?>
<?php if ($count > 0): ?>
    <div class="rooms-grid">
        <?php foreach ($available_rooms as $room): ?>
            <div class="room-card">
                <?php $isMaintenance = (($room['status'] ?? '') === 'maintenance'); ?>
                <div class="room-status <?php echo (!empty($room['is_occupied_window']) || $isMaintenance) ? 'status-occupied' : 'status-available'; ?>">
                    <i class="fas <?php echo $isMaintenance ? 'fa-tools' : (!empty($room['is_occupied_window']) ? 'fa-times-circle' : 'fa-check-circle'); ?>"></i>
                    <?php echo $isMaintenance ? 'Under Maintenance' : (!empty($room['is_occupied_window']) ? 'Occupied' : 'Vacant'); ?>
                </div>

                <div class="room-header">
                    <div>
                        <div class="room-code"><?php echo htmlspecialchars($room['room_code']); ?></div>
                        <div class="room-name"><?php echo htmlspecialchars($room['room_name']); ?></div>
                    </div>
                    <span class="room-department"><?php echo htmlspecialchars($room['department']); ?></span>
                </div>

                <div class="room-amenities">
                    <?php
                    echo getAmenityIcon('aircon', $room['has_aircon']);
                    echo getAmenityIcon('projector', $room['has_projector']);
                    echo getAmenityIcon('computers', $room['has_computers']);
                    echo getAmenityIcon('whiteboard', $room['has_whiteboard']);
                    ?>
                </div>

                <div class="room-details">
                    <div class="detail-item">
                        <i class="fas fa-users"></i>
                        <span>Capacity: <?php echo (int)$room['capacity']; ?> seats</span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-building"></i>
                        <span>Department: <?php echo htmlspecialchars($room['department']); ?></span>
                    </div>
                </div>

                <?php if (!empty($room['is_occupied_window'])): ?>
                    <div class="occupancy-info">
                        <strong><i class="fas fa-chalkboard-teacher"></i> Occupied</strong><br>
                        <?php if (!empty($room['occupied_subject_code']) || !empty($room['occupied_subject_name'])): ?>
                            Subject: <?php echo htmlspecialchars($room['occupied_subject_code']); ?><?php if (!empty($room['occupied_subject_name'])): ?> ƒ?" <?php echo htmlspecialchars($room['occupied_subject_name']); ?><?php endif; ?><br>
                        <?php endif; ?>
                        <?php if (!empty($room['occupied_by'])): ?>
                            Faculty: <?php echo htmlspecialchars($room['occupied_by']); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($room['occupied_from']) && !empty($room['occupied_until'])): ?>
                            Time: <?php echo date('g:i A', strtotime($room['occupied_from'])); ?> - <?php echo date('g:i A', strtotime($room['occupied_until'])); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($room['occupied_type']) || !empty($room['occupied_source'])): ?>
                            <small>
                                <?php echo htmlspecialchars($room['occupied_type'] ?: ucfirst((string)$room['occupied_source'])); ?>
                                <?php if (!empty($room['occupied_source']) && $room['occupied_source'] === 'reservation' && !empty($room['occupied_status'])): ?>
                                    (<?php echo htmlspecialchars(ucfirst((string)$room['occupied_status'])); ?>)
                                <?php endif; ?>
                            </small>
                        <?php endif; ?>
                    </div>
                <?php elseif (($room['status'] ?? '') === 'maintenance'): ?>
                    <div class="detail-item" style="color: #dc3545; font-weight: 600;">
                        <i class="fas fa-tools"></i>
                        <span>Under maintenance</span>
                    </div>
                <?php else: ?>
                    <div class="detail-item" style="color: #2ecc71; font-weight: 600;">
                        <i class="fas fa-check-circle"></i>
                        <span>This room is available for use</span>
                    </div>
                <?php endif; ?>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a class="reserve-btn"
                       href="../faculty/reservation.php?room_id=<?php echo (int)$room['id']; ?>&reservation_date=<?php echo urlencode($avail_date); ?>&start_time=<?php echo urlencode(date('H:i', strtotime($avail_time))); ?><?php if ($avail_end_time): ?>&end_time=<?php echo urlencode(date('H:i', strtotime($avail_end_time))); ?><?php endif; ?>"
                       <?php echo (!empty($room['is_occupied_window']) || (($room['status'] ?? '') === 'maintenance')) ? 'aria-disabled="true" style="pointer-events:none; opacity:0.7; cursor:not-allowed;"' : ''; ?>>
                        <i class="fas fa-calendar-plus"></i> Reserve
                    </a>
                    <?php
                    $nowInWindow = false;
                    $nowTs = time();
                    $startTs = strtotime($avail_date . ' ' . $avail_time);
                    $endTs = $avail_end_time ? strtotime($avail_date . ' ' . $avail_end_time) : null;
                    if ($startTs !== false && $avail_date === date('Y-m-d')) {
                        if ($endTs !== false && $endTs !== null) {
                            $nowInWindow = ($startTs <= $nowTs && $nowTs <= $endTs);
                        } else {
                            $nowInWindow = (date('H:i:s', $nowTs) === date('H:i:s', $startTs));
                        }
                    }
                    ?>
                    <button class="reserve-btn"
                        <?php echo (!$nowInWindow || !empty($room['is_currently_occupied']) || (($room['status'] ?? '') === 'maintenance')) ? 'disabled style="opacity:0.7; cursor:not-allowed;"' : ''; ?>
                        onclick="openQuickReserve(<?php echo htmlspecialchars(json_encode($room)); ?>)">
                        <i class="fas fa-bolt"></i> Use Now
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-door-closed"></i>
        <h3>No Rooms Found</h3>
        <p>Try adjusting your filters.</p>
    </div>
<?php endif; ?>
<?php
$html = ob_get_clean();

closeConnection($conn);

echo json_encode([
    'success' => true,
    'count' => $count,
    'available' => (int)$available_count_filtered,
    'html' => $html
]);
?>
