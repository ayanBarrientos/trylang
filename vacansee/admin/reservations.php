<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();
$message = '';

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_status'])) {
        $reservation_id = (int)$_POST['reservation_id'];
        $status = sanitizeInput($_POST['status']);
        $admin_notes = isset($_POST['admin_notes']) ? sanitizeInput($_POST['admin_notes']) : '';
        
        $stmt = $conn->prepare("UPDATE reservations SET status = ?, admin_notes = ? WHERE id = ?");
        $stmt->bind_param("ssi", $status, $admin_notes, $reservation_id);
        
        if ($stmt->execute()) {
            // Update room status based on reservation outcome
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
            
            $message = '<div class="alert alert-success">Reservation status updated successfully!</div>';
            logActivity($conn, $_SESSION['user_id'], 'update_reservation_status', "Updated reservation $reservation_id to $status");
        } else {
            $message = '<div class="alert alert-danger">Error updating reservation: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$room_filter = isset($_GET['room']) ? (int)$_GET['room'] : 0;

// Build query for reservations
$sql = "
    SELECT r.*, rm.room_code, rm.room_name, rm.department, 
           u.first_name, u.last_name, u.email, u.department as user_dept
    FROM reservations r 
    JOIN rooms rm ON r.room_id = rm.id 
    JOIN users u ON r.faculty_id = u.id 
    WHERE 1=1
";

$params = [];
$types = "";

if ($status_filter) {
    $sql .= " AND r.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($date_filter) {
    $sql .= " AND DATE(r.reservation_date) = ?";
    $params[] = $date_filter;
    $types .= "s";
}

if ($room_filter > 0) {
    $sql .= " AND r.room_id = ?";
    $params[] = $room_filter;
    $types .= "i";
}

// Show latest submitted requests first (stack newest on top)
$sql .= " ORDER BY r.created_at DESC, r.id DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all rooms for filter
$rooms_query = "SELECT id, room_code, room_name FROM rooms ORDER BY room_code";
$rooms = $conn->query($rooms_query)->fetch_all(MYSQLI_ASSOC);

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Management - VACANSEE Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard-page">
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>

        <div class="main-content" id="mainContent">
            <div class="dashboard-header">
                <div class="container header-content">
                    <button class="menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <div class="welcome-message">
                        <h1>Reservation Management</h1>
                        <p>Manage and approve room reservations</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container reservations-container">
                <?php echo $message; ?>
                
                <!-- Filters -->
                <div class="reservation-filters">
                    <h3><i class="fas fa-filter"></i> Filter Reservations</h3>
                    <form method="GET" action="" class="filter-grid">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>" onchange="this.form.submit()">
                        </div>
                        <div class="form-group">
                            <label>Room</label>
                            <select name="room" class="form-control" onchange="this.form.submit()">
                                <option value="">All Rooms</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>" <?php echo $room_filter == $room['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($room['room_code']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="button" class="logout-btn" onclick="window.location.href='reservations.php'" style="width: 100%; padding: 8px 14px; font-size: 0.95rem;">
                                <i class="fas fa-redo"></i> Clear Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Reservations List -->
                <div class="reservations-list">
                    <?php if (count($reservations) > 0): ?>
                        <?php foreach ($reservations as $reservation): ?>
                            <div class="reservation-card">
                                <div class="reservation-header">
                                    <div class="reservation-info">
                                        <div class="reservation-icon icon-<?php echo $reservation['status']; ?>">
                                            <i class="fas fa-calendar-check"></i>
                                        </div>
                                        <div class="reservation-details">
                                            <div class="reservation-title">
                                                <?php echo htmlspecialchars($reservation['subject_code'] . ' - ' . $reservation['class_code']); ?>
                                            </div>
                                            <div class="reservation-subtitle">
                                                Room: <?php echo htmlspecialchars($reservation['room_code']); ?> • 
                                                Faculty: <?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="reservation-status status-<?php echo $reservation['status']; ?>">
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </div>
                                </div>
                                
                                <div class="reservation-body">
                                    <div class="details-grid">
                                        <div class="detail-group">
                                            <div class="detail-label">Date & Time</div>
                                            <div class="detail-value">
                                                <?php echo date('F j, Y', strtotime($reservation['reservation_date'])); ?><br>
                                                <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-group">
                                            <div class="detail-label">Room Details</div>
                                            <div class="detail-value">
                                                <?php echo htmlspecialchars($reservation['room_code'] . ' (' . $reservation['room_name'] . ')'); ?><br>
                                                <?php echo htmlspecialchars($reservation['department']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-group">
                                            <div class="detail-label">Faculty Details</div>
                                            <div class="detail-value">
                                                <?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?><br>
                                                <?php echo htmlspecialchars($reservation['email']); ?><br>
                                                Department: <?php echo htmlspecialchars($reservation['user_dept'] ?? 'N/A'); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-group">
                                            <div class="detail-label">Purpose</div>
                                            <div class="detail-value">
                                                <?php echo htmlspecialchars($reservation['purpose'] ?: 'No additional details provided'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($reservation['admin_notes']): ?>
                                        <div class="detail-group" style="margin-top: 1rem;">
                                            <div class="detail-label">Admin Notes</div>
                                            <div class="detail-value" style="background: #f8f9fa; padding: 10px; border-radius: 4px;">
                                                <?php echo htmlspecialchars($reservation['admin_notes']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php
                                        $time_range = date('g:i A', strtotime($reservation['start_time'])) . ' - ' . date('g:i A', strtotime($reservation['end_time']));
                                        $subject_display = $reservation['subject_code'] . ' - ' . $reservation['class_code'];
                                        $room_display = $reservation['room_code'] . ' - ' . $reservation['room_name'];
                                        $requested_display = date('M j, g:i A', strtotime($reservation['created_at']));
                                    ?>
                                    <?php if ($reservation['status'] == 'pending'): ?>
                                        <div class="reservation-actions">
                                            <button class="action-btn btn-approve" onclick="openStatusModal(<?php echo $reservation['id']; ?>, 'approved')">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button class="action-btn btn-reject" onclick="openStatusModal(<?php echo $reservation['id']; ?>, 'rejected')">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-reservations">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No Reservations Found</h3>
                            <p>No reservations match your current filters.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Update Reservation Status</h3>
                <button class="close-modal" onclick="closeStatusModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="statusForm">
                    <input type="hidden" name="reservation_id" id="status_reservation_id">
                    <input type="hidden" name="status" id="status_value">
                    
                    <div class="form-group">
                        <label>Admin Notes (Optional)</label>
                        <textarea name="admin_notes" class="form-control" rows="4" 
                                  placeholder="Add any notes or comments..."></textarea>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="update_status" class="btn-login" style="flex: 1;">
                            <i class="fas fa-check"></i> Confirm Update
                        </button>
                        <button type="button" class="logout-btn" onclick="closeStatusModal()" style="flex: 1;">
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
        
        let currentReservationId = null;
        let currentStatus = '';
        
        function openStatusModal(reservationId, status) {
            currentReservationId = reservationId;
            currentStatus = status;
            
            document.getElementById('status_reservation_id').value = reservationId;
            document.getElementById('status_value').value = status;
            
            const modalTitle = document.getElementById('modalTitle');
            const statusText = status.charAt(0).toUpperCase() + status.slice(1);
            modalTitle.textContent = `${statusText} Reservation`;
            
            document.getElementById('statusModal').style.display = 'flex';
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
            currentReservationId = null;
            currentStatus = '';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('statusModal');
            if (event.target == modal) {
                closeStatusModal();
            }
        }
        
        // Auto-refresh pending reservations every 30 seconds
        setInterval(() => {
            if (window.location.search.includes('status=pending') || !window.location.search) {
                fetch('../includes/check_pending.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.count > 0) {
                            // Show notification
                            console.log('New pending reservations:', data.count);
                        }
                    });
            }
        }, 30000);
    </script>
</body>
</html>
