<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();

// Get statistics
$stats = [];
$queries = [
    'total_rooms' => "SELECT COUNT(*) as count FROM rooms",
    'total_users' => "SELECT COUNT(*) as count FROM users WHERE is_active = 1",
    'pending_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'",
    'available_rooms' => "SELECT COUNT(*) as count FROM rooms WHERE status = 'vacant'",
    'today_reservations' => "SELECT COUNT(*) as count FROM reservations WHERE DATE(reservation_date) = CURDATE()",
    'recent_logs' => "SELECT COUNT(*) as count FROM system_logs WHERE DATE(created_at) = CURDATE()"
];

foreach ($queries as $key => $sql) {
    $result = $conn->query($sql);
    $stats[$key] = $result->fetch_assoc()['count'];
}

// Get recent reservations
$reservations_query = "
    SELECT r.*, rm.room_code, rm.room_name, u.first_name, u.last_name 
    FROM reservations r 
    JOIN rooms rm ON r.room_id = rm.id 
    JOIN users u ON r.faculty_id = u.id 
    ORDER BY r.created_at DESC 
    LIMIT 5
";
$recent_reservations = $conn->query($reservations_query)->fetch_all(MYSQLI_ASSOC);

// Get system logs
$logs_query = "
    SELECT sl.*, u.first_name, u.last_name 
    FROM system_logs sl 
    LEFT JOIN users u ON sl.user_id = u.id 
    ORDER BY sl.created_at DESC 
    LIMIT 10
";
$system_logs = $conn->query($logs_query)->fetch_all(MYSQLI_ASSOC);

// Get all rooms with live occupancy status
$rooms_query = "SELECT * FROM rooms ORDER BY department, room_code";
$rooms = $conn->query($rooms_query)->fetch_all(MYSQLI_ASSOC);
foreach ($rooms as &$room) {
    applyLiveOccupancy($room, $conn);

    // If reserved (but not currently occupied), attach the next approved reservation details for viewing.
    if (empty($room['is_currently_occupied']) && ($room['status_live'] ?? '') === 'reserved') {
        try {
            $roomId = (int)$room['id'];
            $nextStmt = $conn->prepare("
                SELECT r.*, u.first_name, u.last_name
                  FROM reservations r
                  JOIN users u ON r.faculty_id = u.id
                 WHERE r.room_id = ?
                   AND r.status = 'approved'
                   AND (
                        r.reservation_date > CURDATE()
                        OR (r.reservation_date = CURDATE() AND r.end_time >= CURTIME())
                   )
                 ORDER BY r.reservation_date ASC, r.start_time ASC
                 LIMIT 1
            ");
            $nextStmt->bind_param("i", $roomId);
            $nextStmt->execute();
            $next = $nextStmt->get_result()->fetch_assoc();
            $nextStmt->close();

            if ($next) {
                $room['reserved_by'] = trim(($next['first_name'] ?? '') . ' ' . ($next['last_name'] ?? ''));
                $room['reserved_subject_code'] = $next['subject_code'] ?? '';
                $room['reserved_subject_name'] = $next['subject_name'] ?? '';
                $room['reserved_date'] = $next['reservation_date'] ?? '';
                $room['reserved_from'] = $next['start_time'] ?? '';
                $room['reserved_until'] = $next['end_time'] ?? '';
            } else {
                $room['reserved_by'] = '';
                $room['reserved_subject_code'] = '';
                $room['reserved_subject_name'] = '';
                $room['reserved_date'] = '';
                $room['reserved_from'] = '';
                $room['reserved_until'] = '';
            }
        } catch (Throwable $e) {
            $room['reserved_by'] = '';
            $room['reserved_subject_code'] = '';
            $room['reserved_subject_name'] = '';
            $room['reserved_date'] = '';
            $room['reserved_from'] = '';
            $room['reserved_until'] = '';
        }
    }
}
unset($room);

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - VACANSEE</title>
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
                        <h1>Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!</h1>
                        <p>Admin Dashboard - VACANSEE System</p>
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
                        <i class="fas fa-door-open"></i>
                        <h3><?php echo $stats['total_rooms']; ?></h3>
                        <p>Total Rooms</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-users"></i>
                        <h3><?php echo $stats['total_users']; ?></h3>
                        <p>Active Users</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo $stats['pending_reservations']; ?></h3>
                        <p>Pending Reservations</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-check-circle"></i>
                        <h3><?php echo $stats['available_rooms']; ?></h3>
                        <p>Available Rooms</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-calendar-day"></i>
                        <h3><?php echo $stats['today_reservations']; ?></h3>
                        <p>Today's Reservations</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-history"></i>
                        <h3><?php echo $stats['recent_logs']; ?></h3>
                        <p>Today's Logs</p>
                    </div>
                </div>

                <!-- Room Status Overview -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-door-open"></i> Room Status Overview</h3>
                        <a href="rooms.php" class="view-all">Room Management</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($rooms) > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Room Code</th>
                                            <th>Room Name</th>
                                            <th>Department</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rooms as $room): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($room['room_code']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($room['room_name']); ?></td>
                                                <td><?php echo htmlspecialchars($room['department']); ?></td>
                                                <td class="text-center">
                                                    <span class="room-status status-<?php echo $room['status_live']; ?>">
                                                        <?php echo getRoomStatusText($room['status_live']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if (!empty($room['is_currently_occupied']) || ($room['status_live'] ?? '') === 'reserved'): ?>
                                                        <button class="action-btn view-btn"
                                                            data-room="<?php echo htmlspecialchars($room['room_code'] . ' - ' . $room['room_name']); ?>"
                                                            data-department="<?php echo htmlspecialchars($room['department']); ?>"
                                                            data-status="<?php echo htmlspecialchars(getRoomStatusText($room['status_live'])); ?>"
                                                            data-type="<?php echo !empty($room['is_currently_occupied']) ? 'occupied' : 'reserved'; ?>"
                                                            data-date="<?php echo !empty($room['is_currently_occupied']) ? htmlspecialchars(date('F j, Y')) : htmlspecialchars(!empty($room['reserved_date']) ? date('F j, Y', strtotime($room['reserved_date'])) : ''); ?>"
                                                            data-subject="<?php echo !empty($room['is_currently_occupied'])
                                                                ? htmlspecialchars(trim(($room['occupied_subject_code'] ?? '') . (!empty($room['occupied_subject_name']) ? ' - ' . $room['occupied_subject_name'] : '')))
                                                                : htmlspecialchars(trim(($room['reserved_subject_code'] ?? '') . (!empty($room['reserved_subject_name']) ? ' - ' . $room['reserved_subject_name'] : ''))); ?>"
                                                            data-faculty="<?php echo !empty($room['is_currently_occupied'])
                                                                ? htmlspecialchars($room['occupied_by'] ?? '')
                                                                : htmlspecialchars($room['reserved_by'] ?? ''); ?>"
                                                            data-time="<?php echo !empty($room['is_currently_occupied'])
                                                                ? ((!empty($room['occupied_from']) && !empty($room['occupied_until'])) ? htmlspecialchars(date('g:i A', strtotime($room['occupied_from'])) . ' - ' . date('g:i A', strtotime($room['occupied_until']))) : '')
                                                                : ((!empty($room['reserved_from']) && !empty($room['reserved_until'])) ? htmlspecialchars(date('g:i A', strtotime($room['reserved_from'])) . ' - ' . date('g:i A', strtotime($room['reserved_until']))) : ''); ?>"
                                                            data-source="<?php echo !empty($room['is_currently_occupied'])
                                                                ? htmlspecialchars($room['occupied_source'] ?? '')
                                                                : 'reservation'; ?>"
                                                            onclick="openRoomView(this)">
                                                            <i class="fas fa-eye"></i> View Details
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-door-closed"></i>
                                <h3>No Rooms Found</h3>
                                <p>Add rooms to start tracking availability.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Reservations -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Recent Reservations</h3>
                        <a href="reservations.php" class="view-all">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($recent_reservations) > 0): ?>
                            <div class="table-responsive"> 
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Room</th>
                                            <th>Faculty</th>
                                            <th>Subject</th>
                                            <th>Date & Time</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    
                                    <tbody>
                                        <?php foreach ($recent_reservations as $reservation): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($reservation['room_code']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($reservation['room_name']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($reservation['subject_code']); ?><br>
                                                    <small><?php echo htmlspecialchars($reservation['subject_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($reservation['reservation_date'])); ?><br>
                                                    <small><?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="status-badge status-<?php echo $reservation['status']; ?>">
                                                        <?php echo ucfirst($reservation['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="action-btn view-btn"
                                                        onclick="openReservationView(this)"
                                                        data-room="<?php echo htmlspecialchars($reservation['room_code'] . ' - ' . $reservation['room_name']); ?>"
                                                        data-faculty="<?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?>"
                                                        data-subject="<?php echo htmlspecialchars($reservation['subject_code'] . ' - ' . $reservation['subject_name']); ?>"
                                                        data-date="<?php echo date('F j, Y', strtotime($reservation['reservation_date'])); ?>"
                                                        data-time="<?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>"
                                                        data-status="<?php echo ucfirst($reservation['status']); ?>"
                                                        data-purpose="<?php echo htmlspecialchars($reservation['purpose'] ?? ''); ?>">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <?php if ($reservation['status'] == 'pending'): ?>
                                                        <button class="action-btn approve-btn" onclick="updateReservation(<?php echo $reservation['id']; ?>, 'approved')">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                        <button class="action-btn reject-btn" onclick="updateReservation(<?php echo $reservation['id']; ?>, 'rejected')">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">Completed</span>
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
                                <h3>No Recent Reservations</h3>
                                <p>No reservations have been made yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Logs -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent System Logs</h3>
                        <a href="logs.php" class="view-all">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($system_logs) > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>User</th>
                                            <th>Action</th>
                                            <th>Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($system_logs as $log): ?>
                                            <tr>
                                                <td><?php echo date('M j, g:i A', strtotime($log['created_at'])); ?></td>
                                                <td>
                                                    <?php if ($log['user_id']): ?>
                                                        <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">System</span>
                                                    <?php endif; ?>
                                                </td>
                                            <td>
                                                <span class="status-badge">
                                                    <?php echo htmlspecialchars($log['action']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <h3>No System Logs</h3>
                                <p>No system activities recorded yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reservation View Modal -->
    <div id="reservationViewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reservation Details</h3>
                <button class="close-modal" onclick="closeReservationView()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <div class="detail-label">Room</div>
                    <div class="detail-value" id="viewRoom"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Faculty</div>
                    <div class="detail-value" id="viewFaculty"></div>
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

    <!-- Occupied Room Details Modal -->
    <div id="roomViewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Occupied Room Details</h3>
                <button class="close-modal" onclick="closeRoomView()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <div class="detail-label">Room</div>
                    <div class="detail-value" id="roomViewRoom"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Department</div>
                    <div class="detail-value" id="roomViewDepartment"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Date</div>
                    <div class="detail-value" id="roomViewDate"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="roomViewStatus"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Faculty</div>
                    <div class="detail-value" id="roomViewFaculty"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Subject</div>
                    <div class="detail-value" id="roomViewSubject"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Time</div>
                    <div class="detail-value" id="roomViewTime"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Source</div>
                    <div class="detail-value" id="roomViewSource"></div>
                </div>
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
        
        function updateReservation(reservationId, status) {
            if (confirm(`Are you sure you want to ${status} this reservation?`)) {
                const formData = new FormData();
                formData.append('reservation_id', reservationId);
                formData.append('status', status);
                
                fetch('../includes/update_reservation.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`Reservation ${status} successfully!`);
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Auto-refresh every 60 seconds for real-time updates
        setInterval(() => {
            fetch('../includes/refresh_stats.php')
                .then(response => response.json())
                .then(data => {
                    // Update statistics if needed
                    console.log('Stats refreshed');
                });
        }, 60000);

        function openReservationView(button) {
            document.getElementById('viewRoom').textContent = button.dataset.room;
            document.getElementById('viewFaculty').textContent = button.dataset.faculty;
            document.getElementById('viewSubject').textContent = button.dataset.subject;
            document.getElementById('viewDateTime').textContent = button.dataset.date + ' | ' + button.dataset.time;
            document.getElementById('viewStatus').textContent = button.dataset.status;
            document.getElementById('viewPurpose').textContent = button.dataset.purpose || 'No additional details provided';

            document.getElementById('reservationViewModal').style.display = 'flex';
        }

        function closeReservationView() {
            document.getElementById('reservationViewModal').style.display = 'none';
        }

        function openRoomView(button) {
            document.getElementById('roomViewRoom').textContent = button.dataset.room || '';
            document.getElementById('roomViewDepartment').textContent = button.dataset.department || '';
            document.getElementById('roomViewDate').textContent = button.dataset.date || '—';
            document.getElementById('roomViewStatus').textContent = button.dataset.status || '';
            document.getElementById('roomViewFaculty').textContent = button.dataset.faculty || '—';
            document.getElementById('roomViewSubject').textContent = button.dataset.subject || '—';
            document.getElementById('roomViewTime').textContent = button.dataset.time || '—';
            document.getElementById('roomViewSource').textContent = button.dataset.source || '—';

            document.getElementById('roomViewModal').style.display = 'flex';
        }

        function closeRoomView() {
            document.getElementById('roomViewModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const reservationModal = document.getElementById('reservationViewModal');
            if (event.target === reservationModal) closeReservationView();

            const roomModal = document.getElementById('roomViewModal');
            if (event.target === roomModal) closeRoomView();
        });
    </script>
</body>
</html>
