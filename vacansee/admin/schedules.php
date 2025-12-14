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
    // DELETE ALL (admin)
    if (isset($_POST['delete_all_schedules']) && $_SESSION['user_type'] === 'admin') {
        $stmt = $conn->prepare("DELETE FROM schedules");

        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">All schedules have been deleted.</div>';
            logActivity($conn, $_SESSION['user_id'], 'delete_all_schedules', "Deleted all schedules by admin ID: " . $_SESSION['user_id']);
        } else {
            $message = '<div class="alert alert-danger">Error deleting schedules: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
    if (isset($_POST['add_schedule'])) {
        $room_id = (int)$_POST['room_id'];
        $day_of_week = sanitizeInput($_POST['day_of_week']);
        $start_time = sanitizeInput($_POST['start_time']);
        $end_time = sanitizeInput($_POST['end_time']);
        $subject_code = sanitizeInput($_POST['subject_code']);
        $class_code = sanitizeInput($_POST['class_code']);
        $faculty_id = isset($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;
        
        $stmt = $conn->prepare("
            INSERT INTO schedules 
            (room_id, day_of_week, start_time, end_time, subject_code, class_code, faculty_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssssi", $room_id, $day_of_week, $start_time, $end_time, $subject_code, $class_code, $faculty_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Schedule added successfully!</div>';
            logActivity($conn, $_SESSION['user_id'], 'add_schedule', "Added schedule for room ID: $room_id");
        } else {
            $message = '<div class="alert alert-danger">Error adding schedule: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_schedule'])) {
        $schedule_id = (int)$_POST['schedule_id'];
        $room_id = (int)$_POST['room_id'];
        $day_of_week = sanitizeInput($_POST['day_of_week']);
        $start_time = sanitizeInput($_POST['start_time']);
        $end_time = sanitizeInput($_POST['end_time']);
        $subject_code = sanitizeInput($_POST['subject_code']);
        $class_code = sanitizeInput($_POST['class_code']);
        $faculty_id = isset($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $conn->prepare("
            UPDATE schedules SET 
            room_id = ?, day_of_week = ?, start_time = ?, end_time = ?, 
            subject_code = ?, class_code = ?, faculty_id = ?, is_active = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("isssssiii", $room_id, $day_of_week, $start_time, $end_time, $subject_code, $class_code, $faculty_id, $is_active, $schedule_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Schedule updated successfully!</div>';
            logActivity($conn, $_SESSION['user_id'], 'update_schedule', "Updated schedule ID: $schedule_id");
        } else {
            $message = '<div class="alert alert-danger">Error updating schedule: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['delete_schedule'])) {
        $schedule_id = (int)$_POST['schedule_id'];
        
        $stmt = $conn->prepare("DELETE FROM schedules WHERE id = ?");
        $stmt->bind_param("i", $schedule_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Schedule deleted successfully!</div>';
            logActivity($conn, $_SESSION['user_id'], 'delete_schedule', "Deleted schedule ID: $schedule_id");
        } else {
            $message = '<div class="alert alert-danger">Error deleting schedule: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
}

// Get all schedules with room and faculty info
$schedules_query = "
    SELECT s.*, r.room_code, r.room_name, r.department, 
           u.first_name, u.last_name, u.email 
    FROM schedules s 
    JOIN rooms r ON s.room_id = r.id 
    LEFT JOIN users u ON s.faculty_id = u.id 
    ORDER BY s.day_of_week, s.start_time
";
$schedules_result = $conn->query($schedules_query);
$schedules = $schedules_result->fetch_all(MYSQLI_ASSOC);

// Get all rooms for dropdown
$rooms_query = "SELECT id, room_code, room_name FROM rooms ORDER BY room_code";
$rooms = $conn->query($rooms_query)->fetch_all(MYSQLI_ASSOC);

// Get all faculty for dropdown
$faculty_query = "SELECT id, first_name, last_name, email FROM users WHERE user_type = 'faculty' ORDER BY last_name";
$faculty = $conn->query($faculty_query)->fetch_all(MYSQLI_ASSOC);

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Management - VACANSEE Admin</title>
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
                        <h1>Schedule Management</h1>
                        <p>Manage class schedules and room assignments</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

                    <!-- Delete All Modal -->
                    <div id="deleteAllModal" class="modal">
                        <div class="modal-content" style="max-width:480px;">
                            <div class="modal-header">
                                <h3><i class="fas fa-trash-alt"></i> Delete All Schedules</h3>
                                <button class="close-modal" onclick="closeDeleteAllModal()">&times;</button>
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to delete <strong>all</strong> schedules? This action cannot be undone.</p>
                                <form method="POST" action="">
                                    <div style="display:flex; gap:10px; margin-top:12px;">
                                        <button type="submit" name="delete_all_schedules" class="btn-danger" style="flex:1;">Delete All</button>
                                        <button type="button" class="btn-outline" onclick="closeDeleteAllModal()" style="flex:1;">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

            <div class="container schedules-container">
                <?php echo $message; ?>
                
                <!-- Add Schedule Button -->
                <div style="margin-bottom: 2rem; display:flex; gap:12px; align-items:center;">
                    <button class="btn-login" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add New Schedule
                    </button>
                    <button class="btn-login" onclick="openDeleteAllModal()" title="Delete all schedules">
                        <i class="fas fa-trash-alt" style="margin-right:6px;"></i> Delete All
                    </button>
                </div>

                <!-- Filters -->
                <div class="schedule-filters">
                    <h3><i class="fas fa-filter"></i> Filter Schedules</h3>
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Room</label>
                            <select class="form-control" id="roomFilter">
                                <option value="">All Rooms</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>">
                                        <?php echo htmlspecialchars($room['room_code']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Day of Week</label>
                            <div class="days-filter">
                                <button type="button" class="day-btn active" data-day="">All</button>
                                <button type="button" class="day-btn" data-day="Monday">Mon</button>
                                <button type="button" class="day-btn" data-day="Tuesday">Tue</button>
                                <button type="button" class="day-btn" data-day="Wednesday">Wed</button>
                                <button type="button" class="day-btn" data-day="Thursday">Thu</button>
                                <button type="button" class="day-btn" data-day="Friday">Fri</button>
                                <button type="button" class="day-btn" data-day="Saturday">Sat</button>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select class="form-control" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="active">Active Only</option>
                                <option value="inactive">Inactive Only</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Schedules Table -->
                <div class="schedule-table">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Day & Time</th>
                                    <th>Room</th>
                                    <th>Subject</th>
                                    <th>Faculty</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="scheduleTable">
                                <?php foreach ($schedules as $schedule): ?>
                                    <tr data-room="<?php echo $schedule['room_id']; ?>" 
                                        data-day="<?php echo $schedule['day_of_week']; ?>"
                                        data-status="<?php echo $schedule['is_active'] ? 'active' : 'inactive'; ?>">
                                        <td>
                                            <div class="time-slot">
                                                <?php echo $schedule['day_of_week']; ?>
                                            </div>
                                            <div>
                                                <?php echo date('g:i A', strtotime($schedule['start_time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($schedule['end_time'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($schedule['room_code']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($schedule['room_name']); ?></small>
                                        </td>
                                        <td>
                                            <div class="subject-info">
                                                <span class="subject-code"><?php echo htmlspecialchars($schedule['subject_code']); ?></span>
                                                <span class="subject-name"><?php echo htmlspecialchars($schedule['class_code']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($schedule['faculty_id']): ?>
                                                <div class="faculty-info">
                                                    <span class="faculty-name">
                                                        <?php echo htmlspecialchars($schedule['first_name'] . ' ' . $schedule['last_name']); ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Not Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="schedule-status <?php echo $schedule['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo $schedule['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="schedule-actions">
                                                <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($schedule)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="action-btn delete-btn" onclick="confirmDelete(<?php echo $schedule['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Schedule Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Add New Schedule</h3>
                <button class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Room *</label>
                            <select name="room_id" class="form-control" required>
                                <option value="">Select Room</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>">
                                        <?php echo htmlspecialchars($room['room_code'] . ' - ' . $room['room_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Day of Week *</label>
                            <select name="day_of_week" class="form-control" required>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Time Slot *</label>
                            <div class="time-inputs">
                                <input type="time" name="start_time" class="form-control" required value="08:00">
                                <input type="time" name="end_time" class="form-control" required value="09:00">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Faculty (Optional)</label>
                            <select name="faculty_id" class="form-control">
                                <option value="">Select Faculty</option>
                                <?php foreach ($faculty as $fac): ?>
                                    <option value="<?php echo $fac['id']; ?>">
                                        <?php echo htmlspecialchars($fac['last_name'] . ', ' . $fac['first_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Class Code *</label>
                            <input type="text" name="class_code" class="form-control" required 
                                   placeholder="e.g., 2093">
                        </div>
                        <div class="form-group">
                            <label>Subject Code *</label>
                            <input type="text" name="subject_code" class="form-control" required 
                                   placeholder="e.g., IT14/L">
                        </div>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="add_schedule" class="btn-login" style="flex: 1;">
                            <i class="fas fa-save"></i> Save Schedule
                        </button>
                        <button type="button" class="logout-btn" onclick="closeAddModal()" style="flex: 1;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Schedule Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Schedule</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="schedule_id" id="edit_schedule_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Room *</label>
                            <select name="room_id" id="edit_room_id" class="form-control" required>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>">
                                        <?php echo htmlspecialchars($room['room_code'] . ' - ' . $room['room_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Day of Week *</label>
                            <select name="day_of_week" id="edit_day_of_week" class="form-control" required>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Time Slot *</label>
                            <div class="time-inputs">
                                <input type="time" name="start_time" id="edit_start_time" class="form-control" required>
                                <input type="time" name="end_time" id="edit_end_time" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Faculty (Optional)</label>
                            <select name="faculty_id" id="edit_faculty_id" class="form-control">
                                <option value="">Select Faculty</option>
                                <?php foreach ($faculty as $fac): ?>
                                    <option value="<?php echo $fac['id']; ?>">
                                        <?php echo htmlspecialchars($fac['last_name'] . ', ' . $fac['first_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Subject Code *</label>
                            <input type="text" name="subject_code" id="edit_subject_code" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Subject Name *</label>
                            <input type="text" name="class_code" id="edit_class_code" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: 1rem;">
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                            Active Schedule
                        </label>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="update_schedule" class="btn-login" style="flex: 1;">
                            <i class="fas fa-save"></i> Update Schedule
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
                <p>Are you sure you want to delete this schedule?</p>
                <p class="text-danger"><i class="fas fa-exclamation-circle"></i> This action cannot be undone.</p>
                
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="schedule_id" id="delete_schedule_id">
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="delete_schedule" class="logout-btn" style="background: var(--danger-color); flex: 1;">
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
        
        // Filter functions
        const roomFilter = document.getElementById('roomFilter');
        const dayButtons = document.querySelectorAll('.day-btn');
        const statusFilter = document.getElementById('statusFilter');
        const scheduleRows = document.querySelectorAll('#scheduleTable tr');
        
        function filterSchedules() {
            const selectedRoom = roomFilter.value;
            const selectedDay = document.querySelector('.day-btn.active').dataset.day;
            const selectedStatus = statusFilter.value;
            
            scheduleRows.forEach(row => {
                const room = row.dataset.room;
                const day = row.dataset.day;
                const status = row.dataset.status;
                
                let show = true;
                
                if (selectedRoom && room !== selectedRoom) show = false;
                if (selectedDay && day !== selectedDay) show = false;
                if (selectedStatus && status !== selectedStatus) show = false;
                
                row.style.display = show ? '' : 'none';
            });
        }
        
        roomFilter.addEventListener('change', filterSchedules);
        statusFilter.addEventListener('change', filterSchedules);
        
        dayButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                dayButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                filterSchedules();
            });
        });
        
        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        function openEditModal(schedule) {
            document.getElementById('edit_schedule_id').value = schedule.id;
            document.getElementById('edit_room_id').value = schedule.room_id;
            document.getElementById('edit_day_of_week').value = schedule.day_of_week;
            document.getElementById('edit_start_time').value = schedule.start_time;
            document.getElementById('edit_end_time').value = schedule.end_time;
            document.getElementById('edit_subject_code').value = schedule.subject_code;
            document.getElementById('edit_class_code').value = schedule.class_code;
            document.getElementById('edit_faculty_id').value = schedule.faculty_id || '';
            document.getElementById('edit_is_active').checked = schedule.is_active == 1;
            
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function confirmDelete(scheduleId) {
            document.getElementById('delete_schedule_id').value = scheduleId;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function openDeleteAllModal() {
            document.getElementById('deleteAllModal').style.display = 'flex';
        }

        function closeDeleteAllModal() {
            document.getElementById('deleteAllModal').style.display = 'none';
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
        
        // Time validation
        const startTimeInput = document.querySelector('input[name="start_time"]');
        const endTimeInput = document.querySelector('input[name="end_time"]');
        
        if (startTimeInput && endTimeInput) {
            startTimeInput.addEventListener('change', function() {
                endTimeInput.min = this.value;
                if (endTimeInput.value <= this.value) {
                    const time = this.value.split(':');
                    let hour = parseInt(time[0]);
                    let minute = parseInt(time[1]);
                    minute += 30;
                    if (minute >= 60) {
                        hour += 1;
                        minute -= 60;
                    }
                    endTimeInput.value = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
                }
            });
        }
    </script>
</body>
</html>

