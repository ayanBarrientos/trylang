<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();
$faculty_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Build query for faculty's reservations
$sql = "
    SELECT r.*, rm.room_code, rm.room_name, rm.department 
    FROM reservations r 
    JOIN rooms rm ON r.room_id = rm.id 
    WHERE r.faculty_id = ?
";

$params = [$faculty_id];
$types = "i";

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

$sql .= " ORDER BY r.created_at DESC, r.reservation_date DESC, r.start_time DESC, r.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations - VACANSEE Faculty</title>
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
                        <h1>My Reservations</h1>
                        <p>View and manage your room reservations</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include __DIR__ . '/user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container reservations-container">
                <!-- Filters -->
                <div class="reservation-filters">
                    <h3><i class="fas fa-filter"></i> Filter Reservations</h3>
                    <form method="GET" action="" class="filter-controls">
                        <div class="form-group" style="flex: 1;">
                            <label>Status</label>
                            <select name="status" class="form-control" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="flex: 1;">
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" 
                                   value="<?php echo htmlspecialchars($date_filter); ?>" 
                                   onchange="this.form.submit()">
                        </div>
                        
                        <div class="form-group" style="flex: 1;">
                            <label>&nbsp;</label>
                            <button type="button" class="logout-btn" onclick="window.location.href='reservations.php'" style="width: 100%;">
                                <i class="fas fa-redo"></i> Clear Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Reservations List -->
                <div class="reservations-grid reservations-list">
                    <?php if (count($reservations) > 0): ?>
                        <?php foreach ($reservations as $reservation): ?>
                            <div class="reservation-item reservation-card">
                                <div class="reservation-header">
                                    <div class="reservation-date">
                                        <?php echo date('F j, Y', strtotime($reservation['reservation_date'])); ?>
                                    </div>
                                    <div class="reservation-status status-<?php echo $reservation['status']; ?>">
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </div>
                                </div>
                                
                                <div class="reservation-body">
                                    <div class="reservation-details">
                                        <div class="detail-row">
                                            <span class="detail-label">Room:</span>
                                            <span class="detail-value room-code">
                                                <?php echo htmlspecialchars($reservation['room_code']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <span class="detail-label">Time:</span>
                                            <span class="detail-value">
                                                <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <span class="detail-label">Department:</span>
                                            <span class="detail-value">
                                                <?php echo htmlspecialchars($reservation['department']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <span class="detail-label">Requested:</span>
                                            <span class="detail-value">
                                                <?php echo date('M j, g:i A', strtotime($reservation['created_at'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="subject-info">
                                        <div class="subject-code">
                                            <?php echo htmlspecialchars($reservation['subject_code']); ?>
                                        </div>
                                        <div class="subject-name">
                                            <?php echo htmlspecialchars($reservation['class_code']); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($reservation['purpose']): ?>
                                        <div style="margin: 1rem 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                                            <small><strong>Purpose:</strong> <?php echo htmlspecialchars($reservation['purpose']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($reservation['admin_notes']): ?>
                                        <div style="margin: 1rem 0; padding: 10px; background: #fff3cd; border-radius: 4px;">
                                            <small><strong>Admin Notes:</strong> <?php echo htmlspecialchars($reservation['admin_notes']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="reservation-actions">
                                    <?php if ($reservation['status'] == 'pending'): ?>
                                        <button class="btn-inline btn-edit" onclick="editReservation(<?php echo $reservation['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn-inline btn-cancel" onclick="cancelReservation(<?php echo $reservation['id']; ?>)">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    <?php elseif ($reservation['status'] == 'approved'): ?>
                                        
                                    <?php endif; ?>
                                        <button class="btn-view"
                                            data-id="<?php echo $reservation['id']; ?>"
                                            data-room="<?php echo htmlspecialchars($reservation['room_code'] . ' - ' . $reservation['room_name']); ?>"
                                            data-date="<?php echo htmlspecialchars(date('F j, Y', strtotime($reservation['reservation_date']))); ?>"
                                            data-time="<?php echo htmlspecialchars(date('g:i A', strtotime($reservation['start_time'])) . ' - ' . date('g:i A', strtotime($reservation['end_time']))); ?>"
                                            data-status="<?php echo htmlspecialchars($reservation['status']); ?>"
                                            data-department="<?php echo htmlspecialchars($reservation['department']); ?>"
                                            data-class_code="<?php echo htmlspecialchars($reservation['class_code']); ?>"
                                            data-subject_code="<?php echo htmlspecialchars($reservation['subject_code']); ?>"
                                            data-purpose="<?php echo htmlspecialchars($reservation['purpose'] ?? ''); ?>"
                                            data-notes="<?php echo htmlspecialchars($reservation['admin_notes'] ?? ''); ?>"
                                            data-requested="<?php echo htmlspecialchars(date('M j, g:i A', strtotime($reservation['created_at']))); ?>"
                                            onclick="viewReservation(this)">
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-reservations">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No Reservations Found</h3>
                            <p>You haven't made any reservations yet.</p>
                            <button class="btn-login" onclick="window.location.href='reservation.php'" style="margin-top: 1rem;">
                                <i class="fas fa-calendar-plus"></i> Make Your First Reservation
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- View Reservation Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3 style="margin:0;"><i class="fas fa-eye"></i> Reservation Details</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody">
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
        
        function editReservation(reservationId) {
            window.location.href = `reservation.php?edit=${reservationId}`;
        }
        
        function viewReservation(buttonEl) {
            const data = buttonEl.dataset;
            const modal = document.getElementById('viewModal');
            const body = document.getElementById('viewModalBody');
            body.innerHTML = `
                <div class="detail-row"><strong>Room:</strong> ${data.room}</div>
                <div class="detail-row"><strong>Date:</strong> ${data.date}</div>
                <div class="detail-row"><strong>Time:</strong> ${data.time}</div>
                <div class="detail-row"><strong>Class Code:</strong> ${data.class_code}</div>
                <div class="detail-row"><strong>Subject Code:</strong> ${data.subject_code}</div>
                <div class="detail-row"><strong>Status:</strong> <span class="reservation-status status-${data.status}">${data.status.charAt(0).toUpperCase() + data.status.slice(1)}</span></div>
                <div class="detail-row"><strong>Department:</strong> ${data.department}</div>
                ${data.purpose ? `<div class="detail-row"><strong>Purpose:</strong> ${data.purpose}</div>` : ''}
                ${data.notes ? `<div class="detail-row"><strong>Admin Notes:</strong> ${data.notes}</div>` : ''}
                <div class="detail-row"><strong>Requested:</strong> ${data.requested}</div>
            `;
            modal.style.display = 'flex';

            // Mark approved reservations as viewed to clear the sidebar badge
            if (data.status === 'approved') {
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
                .catch(() => {
                    // Fail silently; badge will clear next refresh
                });
            }
        }
        
        function cancelReservation(reservationId) {
            if (confirm('Are you sure you want to cancel this reservation?')) {
                fetch('../includes/cancel_reservation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `reservation_id=${reservationId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Reservation cancelled successfully!');
                        window.location.reload();
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
        
        function formatTime(timeString) {
            const time = new Date(`2000-01-01T${timeString}`);
            return time.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }
        
        // Auto-refresh reservations every 60 seconds
        setInterval(() => {
            const pendingCount = document.querySelectorAll('.status-pending').length;
            if (pendingCount > 0) {
                // Check for status updates
                fetch('../includes/check_reservation_updates.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.updated) {
                            window.location.reload();
                        }
                    });
            }
        }, 60000);

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
