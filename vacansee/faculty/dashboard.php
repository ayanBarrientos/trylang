<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();
$user_id = $_SESSION['user_id'];

// Get faculty statistics
$stats = [];
$queries = [
    'total_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE faculty_id = $user_id",
    'pending_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE faculty_id = $user_id AND status = 'pending'",
    'approved_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE faculty_id = $user_id AND status = 'approved'",
    'today_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE faculty_id = $user_id AND DATE(reservation_date) = CURDATE()",
    'available_rooms' => "SELECT COUNT(*) as count FROM rooms WHERE is_available = 1 AND status = 'vacant'",
    'total_rooms' => "SELECT COUNT(*) as count FROM rooms"
];

foreach ($queries as $key => $sql) {
    $result = $conn->query($sql);
    $stats[$key] = $result->fetch_assoc()['count'];
}

// Get faculty's upcoming reservations
$reservations_query = "
    SELECT r.*, rm.room_code, rm.room_name, rm.department 
    FROM reservations r 
    JOIN rooms rm ON r.room_id = rm.id 
    WHERE r.faculty_id = $user_id 
    AND r.reservation_date >= CURDATE() 
    ORDER BY r.reservation_date, r.start_time 
    LIMIT 5
";
$upcoming_reservations = $conn->query($reservations_query)->fetch_all(MYSQLI_ASSOC);

// Filters for rooms section
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
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

// Get all rooms for quick view
$rooms_query = "
    SELECT * FROM rooms 
    ORDER BY department, room_code
";
$rooms_raw = $conn->query($rooms_query)->fetch_all(MYSQLI_ASSOC);

// Attach live occupancy and apply filters
$available_rooms = [];
foreach ($rooms_raw as &$room) {
    applyLiveOccupancy($room, $conn);
    applyOccupancyForWindow($room, $conn, $avail_date, $avail_time, $avail_end_time);

    // Attach upcoming reservation info when a room is reserved (for "Use This Room" constraints).
    $room['next_reservation'] = null;
    if (empty($room['is_currently_occupied']) && ($room['status_live'] ?? '') === 'reserved') {
        $room['next_reservation'] = getNextApprovedReservation($conn, $room['id']);
    }

    $passes = true;
    if ($status_filter && ($room['status_window'] ?? '') !== $status_filter) {
        $passes = false;
    }
    if ($department_filter && $room['department'] !== $department_filter) {
        $passes = false;
    }
    if ($room_search) {
        $hay = strtolower(($room['room_code'] ?? '') . ' ' . ($room['room_name'] ?? '') . ' ' . ($room['department'] ?? ''));
        if (strpos($hay, strtolower($room_search)) === false) {
            $passes = false;
        }
    }

    if ($passes) {
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

$available_count_filtered = 0;
foreach ($available_rooms as $r) {
    $isMaintenance = (($r['status'] ?? '') === 'maintenance');
    $isAvailable = !empty($r['is_available']);
    $isVacantInWindow = empty($r['is_occupied_window']);
    if (!$isMaintenance && $isAvailable && $isVacantInWindow) {
        $available_count_filtered++;
    }
}
unset($r);

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard - VACANSEE</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
</head>
<body class="dashboard-page">
    <div class="dashboard">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- Header -->
            <div class="dashboard-header">
                <div class="container header-content">
                    <button class="menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <div class="welcome-message">
                        <h1>Welcome, Prof. <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</h1>
                        <p>Faculty Dashboard - VACANSEE System</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="container">
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <i class="fas fa-calendar-check"></i>
                        <h3><?php echo $stats['total_reservations']; ?></h3>
                        <p>Total Reservations</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo $stats['pending_reservations']; ?></h3>
                        <p>Pending Approvals</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-check-circle"></i>
                        <h3><?php echo $stats['approved_reservations']; ?></h3>
                        <p>Approved Reservations</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-calendar-day"></i>
                        <h3><?php echo $stats['today_reservations']; ?></h3>
                        <p>Today's Reservations</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-door-open"></i>
                        <h3 id="availableRoomsCount"><?php echo (int)$available_count_filtered; ?></h3>
                        <p>Available Rooms</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-building"></i>
                        <h3><?php echo $stats['total_rooms']; ?></h3>
                        <p>Total Rooms</p>
                    </div>
                </div>

                <!-- Rooms -->
                <div class="card" id="available-rooms">
                    <div class="card-header">
                        <h3><i class="fas fa-door-open"></i> Room Availability</h3>
                        <span class="rooms-count" id="roomCount"><?php echo count($available_rooms); ?> rooms</span>
                    </div>
                    <div class="card-body">
                        <form id="availabilityFilters" method="GET" style="margin-bottom: 1rem; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                            <div class="form-group">
                                <label>Date (MM/DD/YYYY)</label>
                                <input type="date" name="avail_date" class="form-control" required value="<?php echo htmlspecialchars($avail_date); ?>">
                            </div>
                            <div class="form-group">
                                <label>Time (Start)</label>
                                <input type="time" name="avail_time" class="form-control" required value="<?php echo htmlspecialchars(date('H:i', strtotime($avail_time))); ?>">
                            </div>
                            <div class="form-group">
                                <label>Duration</label>
                                <select name="duration" class="form-control">
                                    <option value="0" <?php echo $duration_minutes===0 ? 'selected' : ''; ?>>Point-in-time</option>
                                    <option value="30" <?php echo $duration_minutes===30 ? 'selected' : ''; ?>>30 minutes</option>
                                    <option value="60" <?php echo $duration_minutes===60 ? 'selected' : ''; ?>>1 hour</option>
                                    <option value="120" <?php echo $duration_minutes===120 ? 'selected' : ''; ?>>2 hours</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All</option>
                                    <option value="vacant" <?php echo $status_filter==='vacant' ? 'selected' : ''; ?>>Vacant</option>
                                    <option value="occupied" <?php echo $status_filter==='occupied' ? 'selected' : ''; ?>>Occupied</option>
                                    <option value="maintenance" <?php echo $status_filter==='maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department" class="form-control">
                                    <option value="">All</option>
                                    <option value="Engineering" <?php echo $department_filter==='Engineering' ? 'selected' : ''; ?>>Engineering</option>
                                    <option value="DCE" <?php echo $department_filter==='DCE' ? 'selected' : ''; ?>>DCE</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Search</label>
                                <input type="text" name="q" class="form-control" placeholder="Room name/code" value="<?php echo htmlspecialchars($room_search); ?>">
                            </div>
                            <div class="form-group">
                                <label style="display:block;">&nbsp;</label>
                                <label style="display:flex; gap:8px; align-items:center; margin: 0;">
                                    <input type="checkbox" name="vacant_first" value="1" <?php echo $vacant_first ? 'checked' : ''; ?>>
                                    Vacant first
                                </label>
                            </div>
                            <div class="form-group" style="display:flex; align-items:flex-end;">
                                <button type="submit" class="btn-apply"><i class="fas fa-search"></i> Search</button>
                            </div>
                        </form>

                        <div id="roomResults">
                            <?php if (count($available_rooms) > 0): ?>
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
                                                <span>Capacity: <?php echo $room['capacity']; ?> seats</span>
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
                                                    Subject: <?php echo htmlspecialchars($room['occupied_subject_code']); ?><?php if (!empty($room['occupied_subject_name'])): ?> — <?php echo htmlspecialchars($room['occupied_subject_name']); ?><?php endif; ?><br>
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
                                               href="reservation.php?room_id=<?php echo (int)$room['id']; ?>&reservation_date=<?php echo urlencode($avail_date); ?>&start_time=<?php echo urlencode(date('H:i', strtotime($avail_time))); ?><?php if ($avail_end_time): ?>&end_time=<?php echo urlencode(date('H:i', strtotime($avail_end_time))); ?><?php endif; ?>"
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
                        </div>
                    </div>
                </div>

                <!-- Upcoming Reservations -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-alt"></i> Upcoming Reservations</h3>
                        <a href="reservations.php" class="view-all">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($upcoming_reservations) > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Room</th>
                                            <th>Subject</th>
                                            <th>Date & Time</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_reservations as $reservation): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($reservation['room_code']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($reservation['room_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($reservation['subject_code']); ?><br>
                                                    <small><?php echo htmlspecialchars($reservation['subject_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($reservation['reservation_date'])); ?><br>
                                                    <small><?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $reservation['status']; ?>">
                                                        <?php echo ucfirst($reservation['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($reservation['status'] == 'pending'): ?>
                                                        <button class="action-btn edit-btn" onclick="editReservation(<?php echo $reservation['id']; ?>)">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                    <?php elseif ($reservation['status'] == 'approved'): ?>
                                                        <button class="action-btn view-btn"
                                                            data-id="<?php echo $reservation['id']; ?>"
                                                            data-room="<?php echo htmlspecialchars($reservation['room_code'] . ' - ' . $reservation['room_name']); ?>"
                                                            data-date="<?php echo htmlspecialchars(date('F j, Y', strtotime($reservation['reservation_date']))); ?>"
                                                            data-time="<?php echo htmlspecialchars(date('g:i A', strtotime($reservation['start_time'])) . ' - ' . date('g:i A', strtotime($reservation['end_time']))); ?>"
                                                            data-status="<?php echo htmlspecialchars(ucfirst($reservation['status'])); ?>"
                                                            data-status-raw="<?php echo htmlspecialchars($reservation['status']); ?>"
                                                            data-department="<?php echo htmlspecialchars($reservation['department']); ?>"
                                                            data-subject="<?php echo htmlspecialchars($reservation['subject_code'] . ' - ' . $reservation['subject_name']); ?>"
                                                            data-purpose="<?php echo htmlspecialchars($reservation['purpose'] ?? ''); ?>"
                                                            data-notes="<?php echo htmlspecialchars($reservation['admin_notes'] ?? ''); ?>"
                                                            data-requested="<?php echo htmlspecialchars(date('M j, g:i A', strtotime($reservation['created_at']))); ?>"
                                                            onclick="viewReservation(this)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>No Upcoming Reservations</h3>
                                <p>You don't have any upcoming reservations. Make your first reservation!</p>
                                <button class="reserve-btn" onclick="window.location.href='reservation.php'" style="width: auto; padding: 10px 20px;">
                                    <i class="fas fa-calendar-plus"></i> Make Reservation
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Reservation Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reservation Details</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <div class="detail-label">Room</div>
                    <div class="detail-value" id="viewRoom"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Subject</div>
                    <div class="detail-value" id="viewSubject"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Date & Time</div>
                    <div class="detail-value" id="viewDateTime"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="viewStatus"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Purpose</div>
                    <div class="detail-value" id="viewPurpose"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Reserve Modal -->
    <div id="quickReserveModal" class="modal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3 style="margin:0;"><i class="fas fa-calendar-plus"></i> Use This Room</h3>
                <button class="close-modal" onclick="closeQuickReserve()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="quickReserveError" class="alert alert-danger" style="display:none;"></div>
                <div id="qr_reserved_info" class="occupancy-info" style="display:none; background: #fff5d6; border-left: 4px solid #ffc107;"></div>
                <form id="quickReserveForm">
                    <input type="hidden" name="room_id" id="qr_room_id">
                    <div class="form-group">
                        <label>Room</label>
                        <input type="text" id="qr_room_label" class="form-control" disabled>
                    </div>
                    <div class="form-group">
                        <label>Subject Code *</label>
                        <input type="text" name="subject_code" id="qr_subject_code" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Subject Name *</label>
                        <input type="text" name="subject_name" id="qr_subject_name" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" id="qr_start" class="form-control" disabled tabindex="-1">
                        </div>
                        <div class="form-group">
                            <label>End Time *</label>
                            <input type="time" name="end_time" id="qr_end" class="form-control" required>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="submit" class="btn-login" style="flex:1;">
                            <i class="fas fa-save"></i> Save & Occupy
                        </button>
                        <button type="button" class="btn-reset" style="flex:1;" onclick="closeQuickReserve()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('active');
            
            if (sidebar.classList.contains('active')) {
                mainContent.style.marginLeft = '250px';
            } else {
                mainContent.style.marginLeft = '0';
            }
        }
        
        function makeReservation(roomId) {
            window.location.href = `reservation.php?room_id=${roomId}`;
        }

        // Quick reserve flow
        function openQuickReserve(room) {
            if (room.is_currently_occupied) return;
            document.getElementById('qr_room_id').value = room.id;
            document.getElementById('qr_room_label').value = `${room.room_code} - ${room.room_name}`;
            document.getElementById('qr_subject_code').value = '';
            document.getElementById('qr_subject_name').value = '';

            const now = new Date();
            const pad2 = (n) => String(n).padStart(2, '0');
            document.getElementById('qr_start').value = `${pad2(now.getHours())}:${pad2(now.getMinutes())}`;
            document.getElementById('qr_end').value = '';

            // Reserved-room constraint: if reserved by another faculty later today, end time must be before reserved start.
            const reservedInfo = document.getElementById('qr_reserved_info');
            reservedInfo.style.display = 'none';
            reservedInfo.innerHTML = '';
            document.getElementById('qr_end').removeAttribute('max');

            const next = room.next_reservation;
            const currentFacultyId = <?php echo (int)$_SESSION['user_id']; ?>;
            if (next && next.faculty_id && next.faculty_id !== currentFacultyId) {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                const todayStr = `${yyyy}-${mm}-${dd}`;

                if (next.reservation_date === todayStr && next.start_time) {
                    const maxEnd = String(next.start_time).slice(0, 5);
                    if (maxEnd) {
                        document.getElementById('qr_end').setAttribute('max', maxEnd);
                    }
                    const subjectLine = [next.subject_code, next.subject_name].filter(Boolean).join(' - ');
                    reservedInfo.innerHTML = `
                        <strong><i class="fas fa-calendar-check"></i> Upcoming Reservation</strong><br>
                        Reserved By: ${next.faculty_name || '—'}<br>
                        Subject: ${subjectLine || '—'}<br>
                        Time: ${maxEnd || '—'} (start)<br>
                        <span style="font-weight:700;">You can only use this room until before ${maxEnd || 'that time'}.</span>
                    `;
                    reservedInfo.style.display = 'block';
                }
            }

            document.getElementById('quickReserveError').style.display = 'none';
            document.getElementById('quickReserveModal').style.display = 'flex';
        }

        function closeQuickReserve() {
            document.getElementById('quickReserveModal').style.display = 'none';
        }

        document.getElementById('quickReserveForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('../includes/quick_reserve.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    const err = document.getElementById('quickReserveError');
                    err.textContent = data.message || 'Unable to save reservation.';
                    err.style.display = 'block';
                }
            })
            .catch(() => {
                const err = document.getElementById('quickReserveError');
                err.textContent = 'Unexpected error. Please try again.';
                err.style.display = 'block';
            });
        });
        
        function editReservation(reservationId) {
            window.location.href = `reservation.php?edit=${reservationId}`;
        }
        
        function viewReservation(buttonEl) {
            const data = buttonEl.dataset;
            const modal = document.getElementById('viewModal');

            document.getElementById('viewRoom').textContent = data.room || '';
            document.getElementById('viewSubject').textContent = data.subject || '';
            document.getElementById('viewDateTime').textContent = `${data.date || ''} | ${data.time || ''}`.trim();
            document.getElementById('viewStatus').textContent = data.status || '';
            document.getElementById('viewPurpose').textContent = data.purpose || 'No additional details provided';
            modal.style.display = 'flex';

            if ((data.statusRaw || '').toLowerCase() === 'approved') {
                fetch('../includes/mark_reservation_viewed.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `reservation_id=${encodeURIComponent(data.id)}`
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        const approvedBadge = document.querySelector('.sidebar .menu-badge.badge-success');
                        if (approvedBadge) {
                            if (result.remaining > 0) {
                                approvedBadge.textContent = result.remaining;
                            } else {
                                approvedBadge.remove();
                            }
                        }
                    }
                })
                .catch(() => {});
            }
        }
        
        // Auto-refresh available rooms every 30 seconds
        setInterval(() => {
            fetch('../includes/refresh_rooms.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Rooms refreshed');
                    }
                });
        }, 30000);

        // Live-update availability filters without refreshing the page
        (function () {
            const form = document.getElementById('availabilityFilters');
            const results = document.getElementById('roomResults');
            const countEl = document.getElementById('roomCount');
            if (!form || !results || !countEl) return;

            let typingTimer = null;
            let aborter = null;

            const setLoading = (isLoading) => {
                if (isLoading) {
                    results.style.opacity = '0.65';
                    results.style.pointerEvents = 'none';
                } else {
                    results.style.opacity = '';
                    results.style.pointerEvents = '';
                }
            };

            const updateAvailability = async () => {
                if (aborter) aborter.abort();
                aborter = new AbortController();

                const params = new URLSearchParams(new FormData(form));
                const url = `../includes/faculty_room_availability.php?${params.toString()}`;

                // Keep the URL in sync (shareable) without navigation.
                const newUrl = new URL(window.location.href);
                newUrl.search = params.toString();
                window.history.replaceState({}, '', newUrl.toString());

                setLoading(true);
                try {
                    const res = await fetch(url, { signal: aborter.signal });
                    const data = await res.json();
                    if (!data || !data.success) {
                        throw new Error((data && data.message) || 'Unable to load rooms.');
                    }

                    results.innerHTML = data.html || '';
                    countEl.textContent = `${data.count || 0} rooms`;
                    const availableEl = document.getElementById('availableRoomsCount');
                    if (availableEl) {
                        availableEl.textContent = String(data.available || 0);
                    }
                } catch (e) {
                    if (e && e.name === 'AbortError') return;
                    results.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>Unable to load rooms</h3>
                            <p>Please try again.</p>
                        </div>
                    `;
                    countEl.textContent = '0 rooms';
                    const availableEl = document.getElementById('availableRoomsCount');
                    if (availableEl) {
                        availableEl.textContent = '0';
                    }
                } finally {
                    setLoading(false);
                }
            };

            const debounceUpdate = () => {
                if (typingTimer) clearTimeout(typingTimer);
                typingTimer = setTimeout(updateAvailability, 450);
            };

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                updateAvailability();
            });

            const date = form.querySelector('input[name="avail_date"]');
            const time = form.querySelector('input[name="avail_time"]');
            const duration = form.querySelector('select[name="duration"]');
            const status = form.querySelector('select[name="status"]');
            const department = form.querySelector('select[name="department"]');
            const q = form.querySelector('input[name="q"]');
            const vacantFirst = form.querySelector('input[name="vacant_first"]');

            [date, time, duration, status, department, vacantFirst].filter(Boolean).forEach((el) => {
                el.addEventListener('change', updateAvailability);
            });

            if (q) {
                q.addEventListener('input', debounceUpdate);
                q.addEventListener('change', updateAvailability);
            }
        })();

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('viewModal');
            if (event.target === modal) {
                closeViewModal();
            }
        });
    </script>
</body>
</html>
