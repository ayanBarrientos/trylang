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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_room'])) {
        $room_code = sanitizeInput($_POST['room_code']);
        $room_name = sanitizeInput($_POST['room_name']);
        $department = sanitizeInput($_POST['department']);
        $capacity = (int)$_POST['capacity'];
        $has_aircon = isset($_POST['has_aircon']) ? 1 : 0;
        $has_projector = isset($_POST['has_projector']) ? 1 : 0;
        $has_computers = isset($_POST['has_computers']) ? 1 : 0;
        $has_whiteboard = isset($_POST['has_whiteboard']) ? 1 : 0;
        $status = sanitizeInput($_POST['status']);
        
        $stmt = $conn->prepare("INSERT INTO rooms (room_code, room_name, department, capacity, has_aircon, has_projector, has_computers, has_whiteboard, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiiiiis", $room_code, $room_name, $department, $capacity, $has_aircon, $has_projector, $has_computers, $has_whiteboard, $status);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Room added successfully!</div>';
            logActivity($conn, $_SESSION['user_id'], 'add_room', "Added room: $room_code");
        } else {
            $message = '<div class="alert alert-danger">Error adding room: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_room'])) {
        $room_id = (int)$_POST['room_id'];
        $room_code = sanitizeInput($_POST['room_code']);
        $room_name = sanitizeInput($_POST['room_name']);
        $department = sanitizeInput($_POST['department']);
        $capacity = (int)$_POST['capacity'];
        $has_aircon = isset($_POST['has_aircon']) ? 1 : 0;
        $has_projector = isset($_POST['has_projector']) ? 1 : 0;
        $has_computers = isset($_POST['has_computers']) ? 1 : 0;
        $has_whiteboard = isset($_POST['has_whiteboard']) ? 1 : 0;
        $status = sanitizeInput($_POST['status']);
        $is_available = isset($_POST['is_available']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE rooms SET room_code = ?, room_name = ?, department = ?, capacity = ?, has_aircon = ?, has_projector = ?, has_computers = ?, has_whiteboard = ?, status = ?, is_available = ? WHERE id = ?");
        $stmt->bind_param("sssiiiiisii", $room_code, $room_name, $department, $capacity, $has_aircon, $has_projector, $has_computers, $has_whiteboard, $status, $is_available, $room_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Room updated successfully!</div>';
            logActivity($conn, $_SESSION['user_id'], 'update_room', "Updated room: $room_code");
        } else {
            $message = '<div class="alert alert-danger">Error updating room: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['delete_room'])) {
        $room_id = (int)$_POST['room_id'];
        
        // Check if room has active schedules
        $check_schedules = $conn->prepare("SELECT COUNT(*) as count FROM schedules WHERE room_id = ? AND is_active = 1");
        $check_schedules->bind_param("i", $room_id);
        $check_schedules->execute();
        $result = $check_schedules->get_result()->fetch_assoc();
        $check_schedules->close();
        
        if ($result['count'] > 0) {
            $message = '<div class="alert alert-danger">Cannot delete room with active schedules. Please deactivate schedules first.</div>';
        } else {
            $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
            $stmt->bind_param("i", $room_id);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Room deleted successfully!</div>';
                logActivity($conn, $_SESSION['user_id'], 'delete_room', "Deleted room ID: $room_id");
            } else {
                $message = '<div class="alert alert-danger">Error deleting room: ' . $conn->error . '</div>';
            }
            $stmt->close();
        }
    }
}

// Get all rooms
$rooms_query = "SELECT * FROM rooms ORDER BY department, room_code";
$rooms_result = $conn->query($rooms_query);
$rooms = $rooms_result->fetch_all(MYSQLI_ASSOC);

// Attach live occupancy details
foreach ($rooms as &$room) {
    applyLiveOccupancy($room, $conn);
}
unset($room);

// Attach live occupancy details
foreach ($rooms as &$room) {
    $current = getCurrentScheduleStatus($conn, $room['id']);
    if ($current) {
        $room['occupied_now'] = true;
        $room['occupied_by'] = trim(($current['first_name'] ?? '') . ' ' . ($current['last_name'] ?? ''));
        $room['occupied_subject_code'] = $current['subject_code'] ?? '';
        $room['occupied_subject_name'] = $current['subject_name'] ?? '';
        $room['occupied_start'] = $current['start_time'] ?? '';
        $room['occupied_end'] = $current['end_time'] ?? '';
    } else {
        $room['occupied_now'] = false;
    }
}
unset($room);

// Attach reserved details for viewing (next approved reservation that hasn't ended yet)
foreach ($rooms as &$room) {
    $room['reserved_by'] = '';
    $room['reserved_subject_code'] = '';
    $room['reserved_subject_name'] = '';
    $room['reserved_date'] = '';
    $room['reserved_from'] = '';
    $room['reserved_until'] = '';

    if (empty($room['is_currently_occupied']) && ($room['status_live'] ?? '') === 'reserved') {
        try {
            $roomId = (int)$room['id'];
            $stmt = $conn->prepare("
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
            $stmt->bind_param("i", $roomId);
            $stmt->execute();
            $next = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($next) {
                $room['reserved_by'] = trim(($next['first_name'] ?? '') . ' ' . ($next['last_name'] ?? ''));
                $room['reserved_subject_code'] = $next['subject_code'] ?? '';
                $room['reserved_subject_name'] = $next['subject_name'] ?? '';
                $room['reserved_date'] = $next['reservation_date'] ?? '';
                $room['reserved_from'] = $next['start_time'] ?? '';
                $room['reserved_until'] = $next['end_time'] ?? '';
            }
        } catch (Throwable $e) {
            // ignore
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
    <title>Room Management - VACANSEE Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard-page">
    <div class="dashboard">
        <!-- Include Sidebar -->
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
                        <h1>Room Management</h1>
                        <p>Manage all rooms in the system</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container">
                <?php echo $message; ?>
                
                <!-- Add Room Button -->
                <div style="margin-top: 1rem; margin-bottom: 2rem;">
                    <button class="btn-login" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add New Room
                    </button>
                </div>

                <!-- Rooms Table -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-door-open"></i> All Rooms</h3>
                        <span>Total: <?php echo count($rooms); ?> rooms</span>
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
                                            <th>Capacity</th>
                                            <th>Amenities</th>
                                            <th class="text-center">Status</th>
                                            <th>Availability</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rooms as $room): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($room['room_code']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($room['room_name']); ?></td>
                                                <td><?php echo htmlspecialchars($room['department']); ?></td>
                                                <td><?php echo $room['capacity']; ?> seats</td>
                                                <td>
                                                    <div style="display: flex; gap: 10px; font-size: 1.2rem;">
                                                        <?php 
                                                        echo getAmenityIcon('aircon', $room['has_aircon']);
                                                        echo getAmenityIcon('projector', $room['has_projector']);
                                                        echo getAmenityIcon('computers', $room['has_computers']);
                                                        echo getAmenityIcon('whiteboard', $room['has_whiteboard']);
                                                        ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="room-status status-<?php echo $room['status_live']; ?>">
                                                        <?php echo getRoomStatusText($room['status_live']); ?>
                                                    </span>
                                                    <?php if (!empty($room['is_currently_occupied'])): ?>
                                                        <button type="button"
                                                            class="btn-view"
                                                            style="margin-top: 6px; padding: 6px 12px; font-size: 12px; box-shadow: none;"
                                                            data-room="<?php echo htmlspecialchars($room['room_code'] . ' - ' . $room['room_name']); ?>"
                                                            data-subject="<?php echo htmlspecialchars(trim(($room['occupied_subject_code'] ?? '') . (!empty($room['occupied_subject_name']) ? ' - ' . $room['occupied_subject_name'] : ''))); ?>"
                                                            data-faculty="<?php echo htmlspecialchars($room['occupied_by'] ?? ''); ?>"
                                                            data-time="<?php echo (!empty($room['occupied_from']) && !empty($room['occupied_until'])) ? htmlspecialchars(date('g:i A', strtotime($room['occupied_from'])) . ' - ' . date('g:i A', strtotime($room['occupied_until']))) : ''; ?>"
                                                            onclick="openOccupiedDetails(this)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                        <div class="room-meta" style="margin-top:6px; text-align:left; display:none;">
                                                            <strong>Occupied Now</strong><br>
                                                            <?php if (!empty($room['occupied_subject_code']) || !empty($room['occupied_subject_name'])): ?>
                                                                <?php echo htmlspecialchars($room['occupied_subject_code']); ?><?php if (!empty($room['occupied_subject_name'])): ?> — <?php echo htmlspecialchars($room['occupied_subject_name']); ?><?php endif; ?><br>
                                                            <?php endif; ?>
                                                            <?php if (!empty($room['occupied_by'])): ?>
                                                                Faculty: <?php echo htmlspecialchars($room['occupied_by']); ?><br>
                                                            <?php endif; ?>
                                                            <?php if (!empty($room['occupied_from']) && !empty($room['occupied_until'])): ?>
                                                                Time: <?php echo date('g:i A', strtotime($room['occupied_from'])); ?> - <?php echo date('g:i A', strtotime($room['occupied_until'])); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif (($room['status_live'] ?? '') === 'reserved'): ?>
                                                        <button type="button"
                                                            class="btn-view"
                                                            style="margin-top: 6px; padding: 6px 12px; font-size: 12px; box-shadow: none;"
                                                            data-room="<?php echo htmlspecialchars($room['room_code'] . ' - ' . $room['room_name']); ?>"
                                                            data-date="<?php echo !empty($room['reserved_date']) ? htmlspecialchars(date('F j, Y', strtotime($room['reserved_date']))) : ''; ?>"
                                                            data-subject="<?php echo htmlspecialchars(trim(($room['reserved_subject_code'] ?? '') . (!empty($room['reserved_subject_name']) ? ' - ' . $room['reserved_subject_name'] : ''))); ?>"
                                                            data-faculty="<?php echo htmlspecialchars($room['reserved_by'] ?? ''); ?>"
                                                            data-time="<?php echo (!empty($room['reserved_from']) && !empty($room['reserved_until'])) ? htmlspecialchars(date('g:i A', strtotime($room['reserved_from'])) . ' - ' . date('g:i A', strtotime($room['reserved_until']))) : ''; ?>"
                                                            onclick="openReservedDetails(this)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($room['is_available_live'])): ?>
                                                        <span class="status-badge status-vacant">Available</span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-occupied">Unavailable</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($room)); ?>)">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <button class="action-btn delete-btn" onclick="confirmDelete(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['room_code']); ?>')">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </div>
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
                                <p>Add rooms to get started.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Room Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Add New Room</h3>
                <button class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Room Code *</label>
                            <input type="text" name="room_code" class="form-control" required 
                                   placeholder="e.g., R-V1">
                        </div>
                        <div class="form-group">
                            <label>Room Name *</label>
                            <input type="text" name="room_name" class="form-control" required 
                                   placeholder="e.g., Room V1">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Department *</label>
                            <select name="department" class="form-control" required>
                                <option value="">Select Department</option>
                                <option value="Engineering">Engineering</option>
                                <option value="DCE">DCE</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Capacity *</label>
                            <input type="number" name="capacity" class="form-control" required 
                                   min="1" max="500" value="30">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" class="form-control" required>
                                <option value="vacant">Vacant</option>
                                <option value="occupied">Occupied</option>
                                <option value="reserved">Reserved</option>
                                <option value="maintenance">Under Maintenance</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Available for Booking</label>
                            <select name="is_available" class="form-control">
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                    </div>
                    
                    <h4>Amenities</h4>
                    <div class="amenities-grid">
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="has_aircon" value="1">
                            <i class="fas fa-snowflake"></i> Air Conditioning
                        </label>
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="has_projector" value="1">
                            <i class="fas fa-video"></i> Projector
                        </label>
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="has_computers" value="1">
                            <i class="fas fa-desktop"></i> Computers
                        </label>
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="has_whiteboard" value="1" checked>
                            <i class="fas fa-chalkboard"></i> Whiteboard
                        </label>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="add_room" class="btn-login" style="flex: 1;">
                            <i class="fas fa-save"></i> Save Room
                        </button>
                        <button type="button" class="logout-btn" onclick="closeAddModal()" style="flex: 1;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Room Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Room</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="room_id" id="edit_room_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Room Code *</label>
                            <input type="text" name="room_code" id="edit_room_code" 
                                   class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Room Name *</label>
                            <input type="text" name="room_name" id="edit_room_name" 
                                   class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Department *</label>
                            <select name="department" id="edit_department" class="form-control" required>
                                <option value="Engineering">Engineering</option>
                                <option value="DCE">DCE</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Capacity *</label>
                            <input type="number" name="capacity" id="edit_capacity" 
                                   class="form-control" required min="1" max="500">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" id="edit_status" class="form-control" required>
                                <option value="vacant">Vacant</option>
                                <option value="occupied">Occupied</option>
                                <option value="reserved">Reserved</option>
                                <option value="maintenance">Under Maintenance</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Available for Booking</label>
                            <select name="is_available" id="edit_is_available" class="form-control">
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                    </div>
                    
                    <h4>Amenities</h4>
                    <div class="amenities-grid">
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="has_aircon" id="edit_has_aircon" value="1">
                            <i class="fas fa-snowflake"></i> Air Conditioning
                        </label>
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="has_projector" id="edit_has_projector" value="1">
                            <i class="fas fa-video"></i> Projector
                        </label>
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="has_computers" id="edit_has_computers" value="1">
                            <i class="fas fa-desktop"></i> Computers
                        </label>
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="has_whiteboard" id="edit_has_whiteboard" value="1">
                            <i class="fas fa-chalkboard"></i> Whiteboard
                        </label>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="update_room" class="btn-login" style="flex: 1;">
                            <i class="fas fa-save"></i> Update Room
                        </button>
                        <button type="button" class="logout-btn" onclick="closeEditModal()" style="flex: 1;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header" style="background: var(--danger-color);">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
                <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete room <strong id="delete_room_code"></strong>?</p>
                <p class="text-danger"><i class="fas fa-exclamation-circle"></i> This action cannot be undone.</p>
                
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="room_id" id="delete_room_id">
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="delete_room" class="logout-btn" style="background: var(--danger-color); flex: 1;">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <button type="button" class="btn-login" onclick="closeDeleteModal()" style="flex: 1;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Occupied Details Modal -->
    <div id="occupiedDetailsModal" class="modal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3><i class="fas fa-door-closed"></i> Occupied Details</h3>
                <button class="close-modal" onclick="closeOccupiedDetails()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <div class="detail-label">Room</div>
                    <div class="detail-value" id="occRoom"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Subject</div>
                    <div class="detail-value" id="occSubject"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Faculty</div>
                    <div class="detail-value" id="occFaculty"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Time</div>
                    <div class="detail-value" id="occTime"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reserved Details Modal -->
    <div id="reservedDetailsModal" class="modal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-check"></i> Reserved Details</h3>
                <button class="close-modal" onclick="closeReservedDetails()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <div class="detail-label">Room</div>
                    <div class="detail-value" id="resRoom"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Date</div>
                    <div class="detail-value" id="resDate"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Subject</div>
                    <div class="detail-value" id="resSubject"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Faculty</div>
                    <div class="detail-value" id="resFaculty"></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Time</div>
                    <div class="detail-value" id="resTime"></div>
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
        
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        function openEditModal(room) {
            document.getElementById('edit_room_id').value = room.id;
            document.getElementById('edit_room_code').value = room.room_code;
            document.getElementById('edit_room_name').value = room.room_name;
            document.getElementById('edit_department').value = room.department;
            document.getElementById('edit_capacity').value = room.capacity;
            document.getElementById('edit_status').value = room.status;
            document.getElementById('edit_is_available').value = room.is_available;
            document.getElementById('edit_has_aircon').checked = room.has_aircon == 1;
            document.getElementById('edit_has_projector').checked = room.has_projector == 1;
            document.getElementById('edit_has_computers').checked = room.has_computers == 1;
            document.getElementById('edit_has_whiteboard').checked = room.has_whiteboard == 1;
            
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function confirmDelete(roomId, roomCode) {
            document.getElementById('delete_room_id').value = roomId;
            document.getElementById('delete_room_code').textContent = roomCode;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function openOccupiedDetails(button) {
            document.getElementById('occRoom').textContent = button.dataset.room || '';
            document.getElementById('occSubject').textContent = button.dataset.subject || '—';
            document.getElementById('occFaculty').textContent = button.dataset.faculty || '—';
            document.getElementById('occTime').textContent = button.dataset.time || '—';
            document.getElementById('occupiedDetailsModal').style.display = 'flex';
        }

        function closeOccupiedDetails() {
            document.getElementById('occupiedDetailsModal').style.display = 'none';
        }

        function openReservedDetails(button) {
            document.getElementById('resRoom').textContent = button.dataset.room || '';
            document.getElementById('resDate').textContent = button.dataset.date || '—';
            document.getElementById('resSubject').textContent = button.dataset.subject || '—';
            document.getElementById('resFaculty').textContent = button.dataset.faculty || '—';
            document.getElementById('resTime').textContent = button.dataset.time || '—';
            document.getElementById('reservedDetailsModal').style.display = 'flex';
        }

        function closeReservedDetails() {
            document.getElementById('reservedDetailsModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
