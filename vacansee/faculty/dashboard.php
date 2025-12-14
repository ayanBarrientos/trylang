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
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT 5
";
$upcoming_reservations = $conn->query($reservations_query)->fetch_all(MYSQLI_ASSOC);

// Get all rooms for quick view and enrich with current occupant if any
$rooms_raw = $conn->query("SELECT * FROM rooms ORDER BY department, room_code")->fetch_all(MYSQLI_ASSOC);

$rooms_enriched = [];
$today_day = date('l'); // e.g., Monday
$current_time = date('H:i:s');
$leaveApprovalCondition = leaveNotesUseApproval($conn) ? "AND fln.status = 'approved'" : "";

foreach ($rooms_raw as $room) {
    $occupant = null;

    // 1) Check for an approved reservation happening right now for this room
    $resStmt = $conn->prepare(
        "SELECT r.*, u.first_name, u.last_name FROM reservations r JOIN users u ON r.faculty_id = u.id WHERE r.room_id = ? AND r.status = 'approved' AND DATE(r.reservation_date) = CURDATE() AND r.start_time <= CURRENT_TIME() AND r.end_time > CURRENT_TIME() LIMIT 1"
    );
    $resStmt->bind_param('i', $room['id']);
    $resStmt->execute();
    $res = $resStmt->get_result()->fetch_assoc();
    $resStmt->close();

    if ($res) {
        $occupant = [
            'type' => 'reservation',
            'faculty_name' => $res['first_name'] . ' ' . $res['last_name'],
            'class_code' => isset($res['class_code']) ? $res['class_code'] : ($res['subject_name'] ?? ''),
            'subject_code' => $res['subject_code'] ?? '',
            'start_time' => $res['start_time'],
            'end_time' => $res['end_time']
        ];
    } else {
        // 2) Check for an active schedule for this room today at the current time
        // Use MySQL DAYNAME(CURDATE()) and compare case-insensitively to handle different stored casing
        $schSql = "
            SELECT s.*, u.first_name, u.last_name
              FROM schedules s
              JOIN users u ON s.faculty_id = u.id
             WHERE s.room_id = ?
               AND LOWER(s.day_of_week) = LOWER(DAYNAME(CURDATE()))
               AND s.start_time <= ?
               AND s.end_time > ?
               AND s.is_active = 1
               AND NOT EXISTS (
                    SELECT 1 FROM faculty_leave_notes fln
                    WHERE fln.faculty_id = s.faculty_id
                      AND fln.leave_date = CURDATE()
                      AND fln.start_time <= ?
                      AND fln.end_time > ?
                      $leaveApprovalCondition
               )
             LIMIT 1
        ";
        $schStmt = $conn->prepare($schSql);
        $schStmt->bind_param('issss', $room['id'], $current_time, $current_time, $current_time, $current_time);
        $schStmt->execute();
        $sch = $schStmt->get_result()->fetch_assoc();
        $schStmt->close();

        if ($sch) {
            $occupant = [
                'type' => 'schedule',
                'faculty_name' => $sch['first_name'] . ' ' . $sch['last_name'],
                'class_code' => $sch['class_code'] ?? '',
                'subject_code' => $sch['subject_code'] ?? '',
                'start_time' => $sch['start_time'],
                'end_time' => $sch['end_time']
            ];
        }
    }

    $room['occupant'] = $occupant;
    $rooms_enriched[] = $room;
}

// Get all active schedules for this faculty to populate quick-reserve dropdown
$facSchStmt = $conn->prepare(
    "SELECT s.*, rm.room_code, rm.room_name FROM schedules s LEFT JOIN rooms rm ON s.room_id = rm.id WHERE s.faculty_id = ? AND s.is_active = 1 ORDER BY FIELD(LOWER(s.day_of_week),'monday','tuesday','wednesday','thursday','friday','saturday','sunday'), s.start_time"
);
$facSchStmt->bind_param('i', $user_id);
$facSchStmt->execute();
$faculty_schedules = $facSchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$facSchStmt->close();

// Close DB connection (we'll render using $rooms_enriched)
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

                <!-- Quick Reserve Modal -->
                <div id="quickReserveModal" class="modal">
                    <div class="modal-content" style="max-width:640px;">
                        <div class="modal-header">
                            <h3><i class="fas fa-calendar-plus"></i> Quick Reserve</h3>
                            <button class="close-modal" onclick="closeQuickReserve()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form id="quickReserveForm" method="POST" action="../api/quick_reserve.php">
                                <input type="hidden" name="room_id" id="qr_room_id">
                                <div id="quickReserveInlineMsg" style="display:none; margin-bottom:12px; padding:10px; border-radius:6px;"></div>
                                <div class="form-group">
                                    <label class="form-label">1. Date & Time</label>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <input type="date" name="reservation_date" id="qr_date" class="form-control" required>
                                        </div>
                                        <div class="form-group">
                                            <input type="time" name="start_time" id="qr_start" class="form-control" required>
                                        </div>
                                        <div class="form-group">
                                            <input type="time" name="end_time" id="qr_end" class="form-control" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">2. Subject Details</label>
                                    <div class="form-row">
                                        <div class="form-group">
                                           <input type="text" name="class_code" id="qr_class_code" class="form-control" 
                                                   placeholder="Class Code (e.g., 9023)" required>
                                        </div>
                                        <div class="form-group">
                                            <input type="text" name="subject_code" id="qr_subject_code" class="form-control" 
                                                   placeholder="Subject Code (e.g., IT14/L)" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group" id="qr_schedule_group" style="display:none;">
                                    <label class="form-label">Or choose from schedule</label>
                                    <select id="qr_schedule_select" class="form-control">
                                        <option value="">-- Select schedule subject --</option>
                                    </select>
                                </div>

                                <div style="margin-top:1rem; display:flex; gap:10px;">
                                    <button type="submit" class="btn-login" name="quick_reserve">Reserve</button>
                                    <button type="button" class="btn-outline" onclick="closeQuickReserve()">Cancel</button>
                                </div>
                            </form>
                        </div>
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
                        <h3><?php echo $stats['available_rooms']; ?></h3>
                        <p>Available Rooms</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-building"></i>
                        <h3><?php echo $stats['total_rooms']; ?></h3>
                        <p>Total Rooms</p>
                    </div>
                </div>

                <!-- Available Rooms -->
                <div class="card" id="available-rooms">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <h3 style="margin:0;"><i class="fas fa-door-open"></i> All Rooms</h3>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <input type="search" id="roomSearch" placeholder="Search rooms, department, occupant, class, subject" style="padding:8px 10px; border-radius:6px; border:1px solid var(--border-color); width:320px;">
                                <input type="date" id="roomDate" style="padding:8px 10px; border-radius:6px; border:1px solid var(--border-color);">
                                <input type="time" id="roomTime" style="padding:8px 10px; border-radius:6px; border:1px solid var(--border-color);">
                                <button id="roomCheckBtn" class="btn-login" style="padding:8px 12px; display:inline-flex; align-items:center; gap:8px;">Check</button>
                            </div>
                        </div>

                    </div>
                    <div class="card-body">
                        <?php if (count($rooms_enriched) > 0): ?>
                                <div class="rooms-grid">
                                    <?php foreach ($rooms_enriched as $room): ?>
                                        <div class="room-card" data-room-id="<?php echo $room['id']; ?>">
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

                                            <div class="room-capacity">
                                                <i class="fas fa-users"></i>
                                                <?php echo $room['capacity']; ?> seats
                                            </div>

                                            <?php if ($room['occupant']): ?>
                                                <div class="occupied-info" style="margin-top:12px; background:#fff6f6; padding:10px; border-radius:6px; border:1px solid rgba(220,53,69,0.08);">
                                                    <div style="font-weight:700; color:var(--primary-color);">Occupied Now</div>
                                                    <div style="margin-top:6px;"><strong><?php echo htmlspecialchars($room['occupant']['faculty_name']); ?></strong></div>
                                                    <div style="font-size:0.95rem; color:var(--text-light);">
                                                        <?php echo htmlspecialchars($room['occupant']['class_code']); ?> &middot; <?php echo htmlspecialchars($room['occupant']['subject_code']); ?>
                                                    </div>
                                                    <div style="font-size:0.9rem; margin-top:6px; color:var(--text-light);">
                                                        <?php echo date('g:i A', strtotime($room['occupant']['start_time'])); ?> - <?php echo date('g:i A', strtotime($room['occupant']['end_time'])); ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <button class="btn-login reserve-btn" onclick="openQuickReserve(<?php echo $room['id']; ?>)" style="width:100%; display:inline-flex; align-items:center; gap:8px; justify-content:center;">
                                                    <i class="fas fa-calendar-plus"></i> Reserve This Room
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-door-closed"></i>
                                    <h3>No Rooms Found</h3>
                                    <p>There are no rooms configured in the system.</p>
                                </div>
                            <?php endif; ?>
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
                                                    <small><?php echo htmlspecialchars($reservation['class_code']); ?></small>
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
                                                        <button class="action-btn" onclick="viewReservation(<?php echo $reservation['id']; ?>)" style="background: #2ecc71; color: white;">
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

    <script>
        // Room search/filter
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('roomSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const query = this.value.trim().toLowerCase();
                    const cards = document.querySelectorAll('.rooms-grid .room-card');
                    cards.forEach(card => {
                        const roomCode = card.querySelector('.room-code')?.textContent?.toLowerCase() || '';
                        const roomName = card.querySelector('.room-name')?.textContent?.toLowerCase() || '';
                        const dept = card.querySelector('.room-department')?.textContent?.toLowerCase() || '';
                        const occupantName = card.querySelector('.occupied-info strong')?.textContent?.toLowerCase() || '';
                        const classCode = card.querySelector('.occupied-info') ? (card.querySelector('.occupied-info').textContent || '').toLowerCase() : '';
                        const subjectCode = classCode; // subject/class included in occupied-info text

                        const hay = roomCode + ' ' + roomName + ' ' + dept + ' ' + occupantName + ' ' + classCode + ' ' + subjectCode;
                        const show = query === '' || hay.indexOf(query) !== -1;
                        card.style.display = show ? '' : 'none';
                    });
                });
            }
            // date/time check + availability cache
            const dateInput = document.getElementById('roomDate');
            const timeInput = document.getElementById('roomTime');
            const checkBtn = document.getElementById('roomCheckBtn');
            let lastAvailabilityData = null;
            let lastAvailabilityKey = '';
            if (checkBtn) {
                checkBtn.addEventListener('click', function() {
                    const date = dateInput.value;
                    const time = timeInput.value;
                    if (!date || !time) {
                        alert('Please select date and time to check availability.');
                        return;
                    }
                    const key = `${date}|${time}`;
                    fetch(`../api/check_room_availability.php?date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}`)
                        .then(r => r.json())
                        .then(data => {
                            // cache for later use by the quick reserve modal
                            lastAvailabilityData = data;
                            lastAvailabilityKey = key;

                            // data is map roomId => { occupant: {...} | null, schedules: [...] }
                            document.querySelectorAll('.rooms-grid .room-card').forEach(card => {
                                const roomId = card.getAttribute('data-room-id') || card.querySelector('.reserve-btn')?.getAttribute('onclick')?.match(/\((\d+)\)/)?.[1];
                                const roomIdNum = roomId ? parseInt(roomId, 10) : null;
                                const roomData = roomIdNum && data[roomIdNum] ? data[roomIdNum] : null;
                                const occupant = roomData && roomData.occupant ? roomData.occupant : null;
                                // update card UI
                                let occupiedEl = card.querySelector('.occupied-info');
                                if (occupant) {
                                    if (!occupiedEl) {
                                        occupiedEl = document.createElement('div');
                                        occupiedEl.className = 'occupied-info';
                                        occupiedEl.style = 'margin-top:12px; background:#fff6f6; padding:10px; border-radius:6px; border:1px solid rgba(220,53,69,0.08);';
                                        card.appendChild(occupiedEl);
                                    }
                                    // show who scheduled it and relevant codes
                                    const facultyLabel = occupant.faculty_name ? escapeHtml(occupant.faculty_name) : 'Unknown';
                                    const classText = occupant.class_code ? escapeHtml(occupant.class_code) : '';
                                    const subjectText = occupant.subject_code ? escapeHtml(occupant.subject_code) : '';
                                    occupiedEl.innerHTML = `<div style="font-weight:700; color:var(--primary-color);">Occupied</div><div style="margin-top:6px;"><strong>${facultyLabel}</strong></div><div style="font-size:0.95rem; color:var(--text-light);">${classText} &middot; ${subjectText}</div><div style="font-size:0.9rem; margin-top:6px; color:var(--text-light);">${formatTime(occupant.start_time)} - ${formatTime(occupant.end_time)}</div>`;
                                    // remove reserve button if exists
                                    const btn = card.querySelector('.reserve-btn'); if (btn) btn.remove();
                                } else {
                                    // remove occupied element if present
                                    if (occupiedEl) occupiedEl.remove();
                                    // ensure reserve button exists
                                    if (!card.querySelector('.reserve-btn')) {
                                        const btn = document.createElement('button');
                                        btn.className = 'btn-login reserve-btn';
                                        btn.style = 'width:100%; display:inline-flex; align-items:center; gap:8px; justify-content:center;';
                                        btn.innerHTML = '<i class="fas fa-calendar-plus"></i> Reserve This Room';
                                        const rid = roomIdNum || '';
                                        btn.setAttribute('onclick', `openQuickReserve(${rid})`);
                                        card.appendChild(btn);
                                    }
                                }
                            });
                        });
                });
            }

            // Quick reserve modal functions
            window.openQuickReserve = function(roomId) {
                const modal = document.getElementById('quickReserveModal');
                if (!modal) return;
                document.getElementById('qr_room_id').value = roomId;
                const dateInput = document.getElementById('roomDate');
                const timeInput = document.getElementById('roomTime');
                const qrDate = document.getElementById('qr_date');
                const qrStart = document.getElementById('qr_start');
                const qrEnd = document.getElementById('qr_end');

                if (dateInput && dateInput.value) qrDate.value = dateInput.value;
                if (timeInput && timeInput.value) {
                    qrStart.value = timeInput.value;
                    // default end time +1 hour
                    const t = timeInput.value.split(':');
                    if (t.length >= 2) {
                        let hh = parseInt(t[0],10);
                        let mm = parseInt(t[1],10);
                        hh = (hh + 1) % 24;
                        qrEnd.value = `${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}`;
                    }
                }

                // Populate schedule select from cached availability or fetch fresh
                const date = qrDate.value;
                const time = qrStart.value;
                const key = `${date}|${time}`;
                const scheduleSelect = document.getElementById('qr_schedule_select');
                const scheduleGroup = document.getElementById('qr_schedule_group');

                function populateScheduleSelectFromData(data) {
                    // clear existing options except placeholder
                    scheduleSelect.innerHTML = '<option value="">-- Select schedule subject --</option>';
                    const roomData = data && data[roomId] ? data[roomId] : null;
                    const roomSchedules = roomData && Array.isArray(roomData.schedules) ? roomData.schedules : [];
                    // Use a map to dedupe by schedule id
                    const added = {};

                    // First add room-specific schedules (if any)
                    roomSchedules.forEach(s => {
                        added[s.id] = true;
                        const opt = document.createElement('option');
                        opt.value = s.id;
                        opt.dataset.class = s.class_code || '';
                        opt.dataset.subject = s.subject_code || '';
                        opt.dataset.start = s.start_time || '';
                        opt.dataset.end = s.end_time || '';
                        opt.dataset.faculty = s.faculty_name || (s.first_name && s.last_name ? s.first_name + ' ' + s.last_name : '');
                        opt.textContent = `${opt.dataset.faculty} — ${opt.dataset.class} · ${opt.dataset.subject} (${formatTime(opt.dataset.start)} - ${formatTime(opt.dataset.end)})`;
                        scheduleSelect.appendChild(opt);
                    });

                    // Then add the faculty's schedules (from FACULTY_SCHEDULES) if not already included
                    if (typeof FACULTY_SCHEDULES !== 'undefined' && Array.isArray(FACULTY_SCHEDULES)) {
                        FACULTY_SCHEDULES.forEach(s => {
                            if (added[s.id]) return; // skip duplicates
                            added[s.id] = true;
                            const opt = document.createElement('option');
                            opt.value = s.id;
                            opt.dataset.class = s.class_code || '';
                            opt.dataset.subject = s.subject_code || '';
                            opt.dataset.start = s.start_time || '';
                            opt.dataset.end = s.end_time || '';
                            opt.dataset.faculty = (s.first_name && s.last_name) ? s.first_name + ' ' + s.last_name : (s.faculty_name || '');
                            const roomLabel = s.room_code ? ` in ${s.room_code}` : '';
                            const dayLabel = s.day_of_week ? ` ${s.day_of_week}` : '';
                            opt.textContent = `${opt.dataset.faculty} — ${opt.dataset.class} · ${opt.dataset.subject}${roomLabel} ${dayLabel} (${formatTime(opt.dataset.start)} - ${formatTime(opt.dataset.end)}) — My schedule`;
                            scheduleSelect.appendChild(opt);
                        });
                    }

                    // Show group if any options besides placeholder exist
                    scheduleGroup.style.display = scheduleSelect.options.length > 1 ? '' : 'none';
                }

                if (lastAvailabilityKey === key && lastAvailabilityData) {
                    populateScheduleSelectFromData(lastAvailabilityData);
                } else {
                    // fetch fresh availability for this date/time and populate
                    if (!date || !time) {
                        scheduleGroup.style.display = 'none';
                    } else {
                        fetch(`../api/check_room_availability.php?date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}`)
                            .then(r => r.json())
                            .then(data => {
                                lastAvailabilityData = data;
                                lastAvailabilityKey = key;
                                populateScheduleSelectFromData(data);
                            })
                            .catch(err => {
                                console.error('Failed to load schedules for quick reserve', err);
                                scheduleGroup.style.display = 'none';
                            });
                    }
                }

                // when a schedule is chosen, autofill the class/subject fields
                scheduleSelect.onchange = function() {
                    const sel = this.options[this.selectedIndex];
                    if (sel && sel.value) {
                        document.getElementById('qr_class_code').value = sel.dataset.class || '';
                        document.getElementById('qr_subject_code').value = sel.dataset.subject || '';
                        // optionally adjust times
                        if (sel.dataset.start) qrStart.value = sel.dataset.start;
                        if (sel.dataset.end) qrEnd.value = sel.dataset.end;
                    }
                };

                modal.style.display = 'flex';
            }

            window.closeQuickReserve = function() {
                const modal = document.getElementById('quickReserveModal');
                if (!modal) return;
                modal.style.display = 'none';
                // reset schedule select and group visibility
                const scheduleGroup = document.getElementById('qr_schedule_group');
                const scheduleSelect = document.getElementById('qr_schedule_select');
                if (scheduleSelect) scheduleSelect.innerHTML = '<option value="">-- Select schedule subject --</option>';
                if (scheduleGroup) scheduleGroup.style.display = 'none';

                const msg = document.getElementById('quickReserveInlineMsg');
                if (msg) {
                    msg.style.display = 'none';
                    msg.textContent = '';
                    msg.style.background = '';
                    msg.style.border = '';
                    msg.style.color = '';
                }
            }

            // Submit quick reserve without redirect
            const quickReserveForm = document.getElementById('quickReserveForm');
            if (quickReserveForm) {
                quickReserveForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const msg = document.getElementById('quickReserveInlineMsg');
                    const submitBtn = quickReserveForm.querySelector('button[type="submit"]');
                    const formData = new FormData(quickReserveForm);

                    if (msg) {
                        msg.style.display = 'none';
                        msg.textContent = '';
                    }

                    if (submitBtn) submitBtn.disabled = true;

                    fetch(quickReserveForm.action, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                        .then(async (r) => {
                            let data = null;
                            try { data = await r.json(); } catch (e) {}
                            if (!r.ok || !data || data.success !== true) {
                                const errMsg = (data && data.message) ? data.message : 'Failed to submit reservation request.';
                                throw new Error(errMsg);
                            }
                            return data;
                        })
                        .then((data) => {
                            if (msg) {
                                msg.style.display = '';
                                msg.style.background = '#eaf8ef';
                                msg.style.border = '1px solid rgba(46, 204, 113, 0.25)';
                                msg.style.color = '#1e7e34';
                                msg.textContent = data.message || 'Reservation request submitted successfully.';
                            } else {
                                alert(data.message || 'Reservation request submitted successfully.');
                            }

                            // Close after a short delay and refresh availability cards
                            setTimeout(() => {
                                window.closeQuickReserve();
                                const dateInput = document.getElementById('roomDate');
                                const timeInput = document.getElementById('roomTime');
                                if (dateInput && timeInput && dateInput.value && timeInput.value) {
                                    fetchRoomAvailability(dateInput.value, timeInput.value);
                                }
                            }, 900);
                        })
                        .catch((err) => {
                            if (msg) {
                                msg.style.display = '';
                                msg.style.background = '#fff6f6';
                                msg.style.border = '1px solid rgba(220,53,69,0.20)';
                                msg.style.color = '#b02a37';
                                msg.textContent = err && err.message ? err.message : 'Failed to submit reservation request.';
                            } else {
                                alert(err && err.message ? err.message : 'Failed to submit reservation request.');
                            }
                        })
                        .finally(() => {
                            if (submitBtn) submitBtn.disabled = false;
                        });
                });
            }
        });

        function escapeHtml(s) { return String(s || '').replace(/[&<>"]/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];}); }
        function formatTime(t){ if(!t) return ''; try { return new Date('1970-01-01T'+t).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});}catch(e){return t;} }

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
        
        function editReservation(reservationId) {
            window.location.href = `reservation.php?edit=${reservationId}`;
        }
        
        function viewReservation(reservationId) {
            // Open view modal or redirect to detailed view
            alert('Viewing reservation details...');
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
    </script>
    <script>
        // Expose faculty schedules to JS so the quick reserve modal can list them
        const FACULTY_SCHEDULES = <?php echo json_encode($faculty_schedules ?: []); ?>;
    </script>
</body>
</html>
