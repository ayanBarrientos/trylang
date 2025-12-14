<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Only faculty can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    header('Location: ../auth/login.php');
    exit();
}

$conn = getConnection();
$message = '';
// show flash message from PRG redirect
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
$faculty_user_id = (int)$_SESSION['user_id'];

// Handle form submissions (faculty can only manage their own schedules)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // ADD
    if (isset($_POST['add_schedule'])) {
        $room_id = (int)$_POST['room_id'];
        $day_of_week = sanitizeInput($_POST['day_of_week']);
        $start_time = sanitizeInput($_POST['start_time']);
        $end_time = sanitizeInput($_POST['end_time']);
        $subject_code = sanitizeInput($_POST['subject_code']);
        $class_code = sanitizeInput($_POST['class_code']);
        $faculty_id = $faculty_user_id; // assign to current faculty

        // room-level overlap
        $overlap_check = $conn->prepare(
            "SELECT id FROM schedules WHERE room_id = ? AND day_of_week = ? AND NOT (end_time <= ? OR start_time >= ?) LIMIT 1"
        );
        $overlap_check->bind_param('isss', $room_id, $day_of_week, $start_time, $end_time);
        $overlap_check->execute();
        $overlap_res = $overlap_check->get_result()->fetch_assoc();
        $overlap_check->close();

        if ($overlap_res) {
            $message = '<div class="alert alert-danger">Cannot add schedule: time slot overlaps with an existing schedule for the selected room.</div>';
        } else {
            // faculty-level overlap
            $faculty_overlap = $conn->prepare(
                "SELECT id FROM schedules WHERE faculty_id = ? AND day_of_week = ? AND NOT (end_time <= ? OR start_time >= ?) LIMIT 1"
            );
            $faculty_overlap->bind_param('isss', $faculty_id, $day_of_week, $start_time, $end_time);
            $faculty_overlap->execute();
            $faculty_overlap_res = $faculty_overlap->get_result()->fetch_assoc();
            $faculty_overlap->close();

            if ($faculty_overlap_res) {
                $message = '<div class="alert alert-danger">Cannot add schedule: you already have a schedule at this time on the selected day.</div>';
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO schedules (room_id, day_of_week, start_time, end_time, subject_code, class_code, faculty_id) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("isssssi", $room_id, $day_of_week, $start_time, $end_time, $subject_code, $class_code, $faculty_id);

                if ($stmt->execute()) {
                    logActivity($conn, $faculty_user_id, 'add_schedule', "Added schedule for room ID: $room_id");
                    $_SESSION['flash_message'] = '<div class="alert alert-success">Schedule added successfully!</div>';
                    $stmt->close();
                    closeConnection($conn);
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit();
                } else {
                    $message = '<div class="alert alert-danger">Error adding schedule: ' . $conn->error . '</div>';
                    $stmt->close();
                }
            }
        }
    }

    // UPDATE
    elseif (isset($_POST['update_schedule'])) {
        $schedule_id = (int)$_POST['schedule_id'];
        $room_id = (int)$_POST['room_id'];
        $day_of_week = sanitizeInput($_POST['day_of_week']);
        $start_time = sanitizeInput($_POST['start_time']);
        $end_time = sanitizeInput($_POST['end_time']);
        $subject_code = sanitizeInput($_POST['subject_code']);
        $class_code = sanitizeInput($_POST['class_code']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Ensure the schedule belongs to this faculty before updating
        $check = $conn->prepare("SELECT faculty_id FROM schedules WHERE id = ? LIMIT 1");
        $check->bind_param('i', $schedule_id);
        $check->execute();
        $res = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$res || (int)$res['faculty_id'] !== $faculty_user_id) {
            $message = '<div class="alert alert-danger">You are not authorized to update this schedule.</div>';
        } else {
            // room-level overlap excluding this schedule
            $overlap_check = $conn->prepare(
                "SELECT id FROM schedules WHERE room_id = ? AND day_of_week = ? AND id != ? AND NOT (end_time <= ? OR start_time >= ?) LIMIT 1"
            );
            $overlap_check->bind_param('isiss', $room_id, $day_of_week, $schedule_id, $start_time, $end_time);
            $overlap_check->execute();
            $overlap_res = $overlap_check->get_result()->fetch_assoc();
            $overlap_check->close();

            if ($overlap_res) {
                $message = '<div class="alert alert-danger">Cannot update schedule: time slot overlaps with an existing schedule for the selected room.</div>';
            } else {
                // faculty-level overlap excluding this schedule
                $faculty_overlap = $conn->prepare(
                    "SELECT id FROM schedules WHERE faculty_id = ? AND day_of_week = ? AND id != ? AND NOT (end_time <= ? OR start_time >= ?) LIMIT 1"
                );
                $faculty_overlap->bind_param('isiss', $faculty_user_id, $day_of_week, $schedule_id, $start_time, $end_time);
                $faculty_overlap->execute();
                $faculty_overlap_res = $faculty_overlap->get_result()->fetch_assoc();
                $faculty_overlap->close();

                if ($faculty_overlap_res) {
                    $message = '<div class="alert alert-danger">Cannot update schedule: you already have a schedule at this time on the selected day.</div>';
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE schedules SET room_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_code = ?, class_code = ?, is_active = ? WHERE id = ?"
                    );
                    $stmt->bind_param("issssiii", $room_id, $day_of_week, $start_time, $end_time, $subject_code, $class_code, $is_active, $schedule_id);

                    if ($stmt->execute()) {
                        logActivity($conn, $faculty_user_id, 'update_schedule', "Updated schedule ID: $schedule_id");
                        $_SESSION['flash_message'] = '<div class="alert alert-success">Schedule updated successfully!</div>';
                        $stmt->close();
                        closeConnection($conn);
                        header('Location: ' . $_SERVER['REQUEST_URI']);
                        exit();
                    } else {
                        $message = '<div class="alert alert-danger">Error updating schedule: ' . $conn->error . '</div>';
                        $stmt->close();
                    }
                }
            }
        }
    }

    // DELETE
    elseif (isset($_POST['delete_schedule'])) {
        $schedule_id = (int)$_POST['schedule_id'];

        // Ensure the schedule belongs to this faculty before deleting
        $check = $conn->prepare("SELECT faculty_id FROM schedules WHERE id = ? LIMIT 1");
        $check->bind_param('i', $schedule_id);
        $check->execute();
        $res = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$res || (int)$res['faculty_id'] !== $faculty_user_id) {
            $message = '<div class="alert alert-danger">You are not authorized to delete this schedule.</div>';
        } else {
            $stmt = $conn->prepare("DELETE FROM schedules WHERE id = ?");
            $stmt->bind_param("i", $schedule_id);

            if ($stmt->execute()) {
                logActivity($conn, $faculty_user_id, 'delete_schedule', "Deleted schedule ID: $schedule_id");
                $_SESSION['flash_message'] = '<div class="alert alert-success">Schedule deleted successfully!</div>';
                $stmt->close();
                closeConnection($conn);
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit();
            } else {
                $message = '<div class="alert alert-danger">Error deleting schedule: ' . $conn->error . '</div>';
                $stmt->close();
            }
        }
    }
    // DELETE ALL
    elseif (isset($_POST['delete_all_schedules'])) {
        // Delete all schedules belonging to this faculty
        $stmt = $conn->prepare("DELETE FROM schedules WHERE faculty_id = ?");
        $stmt->bind_param('i', $faculty_user_id);

        if ($stmt->execute()) {
            logActivity($conn, $faculty_user_id, 'delete_all_schedules', "Deleted all schedules for faculty ID: $faculty_user_id");
            $_SESSION['flash_message'] = '<div class="alert alert-success">All your schedules have been deleted.</div>';
            $stmt->close();
            closeConnection($conn);
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit();
        } else {
            $message = '<div class="alert alert-danger">Error deleting schedules: ' . $conn->error . '</div>';
            $stmt->close();
        }
    }
}

// Get schedules belonging to this faculty
$schedules_query = "
    SELECT s.*, r.room_code, r.room_name, r.department
    FROM schedules s
    JOIN rooms r ON s.room_id = r.id
    WHERE s.faculty_id = ?
    ORDER BY FIELD(s.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), s.start_time
";
$stmt = $conn->prepare($schedules_query);
$stmt->bind_param('i', $faculty_user_id);
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get rooms for dropdown
$rooms_query = "SELECT id, room_code, room_name FROM rooms ORDER BY room_code";
$rooms = $conn->query($rooms_query)->fetch_all(MYSQLI_ASSOC);

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedules - Faculty</title>
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
                        <h1>My Schedules</h1>
                        <p>Manage your class schedules and room assignments</p>
                    </div>
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container schedules-container">
                <?php echo $message; ?>

                <div style="margin-bottom: 1.5rem; display:flex; justify-content:space-between; align-items:center; gap:12px;">
                    <div style="display:flex; gap:12px; align-items:center;">
                        <button class="btn-login" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Schedule</button>
                        <div style="color:var(--dashboard-muted); font-weight:700;">Total: <?php echo count($schedules); ?> schedules</div>
                    </div>
                    <div style="display:flex; gap:12px; align-items:center;">
                        <button class="btn-login" onclick="openDeleteAllModal()" title="Delete all my schedules">
                            <i class="fas fa-trash-alt" style="margin-right:6px;"></i> Delete All
                        </button>
                    </div>
                </div>

                <div class="schedule-table">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Day & Time</th>
                                    <th>Room</th>
                                    <th>Class Code</th>
                                    <th>Subject Code</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="scheduleTable">
                                <?php foreach ($schedules as $schedule): ?>
                                    <tr>
                                        <td>
                                            <div class="time-slot"><?php echo $schedule['day_of_week']; ?></div>
                                            <div><?php echo date('g:i A', strtotime($schedule['start_time'])); ?> - <?php echo date('g:i A', strtotime($schedule['end_time'])); ?></div>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($schedule['room_code']); ?></strong><br><small><?php echo htmlspecialchars($schedule['room_name']); ?></small></td>
                                        <td>
                                            <div class="subject-info">
                                                
                                                <span class="subject-name"><?php echo htmlspecialchars($schedule['class_code']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="subject-info">
                                                <span class="subject-code"><?php echo htmlspecialchars($schedule['subject_code']); ?></span>
                                                
                                            </div>
                                        </td>
                                        <td><span class="schedule-status <?php echo $schedule['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $schedule['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                        <td>
                                            <div class="schedule-actions">
                                                <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($schedule)); ?>)"><i class="fas fa-edit"></i></button>
                                                <button class="action-btn delete-btn" onclick="confirmDelete(<?php echo $schedule['id']; ?>)"><i class="fas fa-trash"></i></button>
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

    <!-- Add Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Add Schedule</h3>
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
                                    <option value="<?php echo $room['id']; ?>"><?php echo htmlspecialchars($room['room_code'] . ' - ' . $room['room_name']); ?></option>
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
                            <label>Class Code *</label>
                            <input type="text" name="class_code" class="form-control" required placeholder="e.g., 9023">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Subject Code *</label>
                            <input type="text" name="subject_code" class="form-control" required placeholder="e.g., IT14/L">
                        </div>
                    </div>

                    <div style="margin-top:1.5rem; display:flex; gap:10px;">
                        <button type="submit" name="add_schedule" class="btn-login" style="flex:1;"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="logout-btn" onclick="closeAddModal()" style="flex:1;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
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
                                    <option value="<?php echo $room['id']; ?>"><?php echo htmlspecialchars($room['room_code'] . ' - ' . $room['room_name']); ?></option>
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
                            <label>Subject Code *</label>
                            <input type="text" name="subject_code" id="edit_subject_code" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Subject Name *</label>
                            <input type="text" name="class_code" id="edit_class_code" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Active</label>
                            <select name="is_active" id="edit_is_active" class="form-control">
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top:1.5rem; display:flex; gap:10px;">
                        <button type="submit" name="update_schedule" class="btn-login" style="flex:1;">Update</button>
                        <button type="button" class="btn-outline" onclick="closeEditModal()" style="flex:1;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width:420px;">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
                <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this schedule?</p>
                <form method="POST" action="">
                    <input type="hidden" name="schedule_id" id="delete_schedule_id">
                    <div style="display:flex; gap:10px; margin-top:12px;">
                        <button type="submit" name="delete_schedule" class="btn-danger" style="flex:1;">Delete</button>
                        <button type="button" class="btn-outline" onclick="closeDeleteModal()" style="flex:1;">Cancel</button>
                    </div>
                </form>
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
                <p>Are you sure you want to delete <strong>all</strong> your schedules? This action cannot be undone.</p>
                <form method="POST" action="">
                    <div style="display:flex; gap:10px; margin-top:12px;">
                        <button type="submit" name="delete_all_schedules" class="btn-danger" style="flex:1;">Delete All</button>
                        <button type="button" class="btn-outline" onclick="closeDeleteAllModal()" style="flex:1;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAddModal() { document.getElementById('addModal').style.display = 'flex'; }
        function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }
        function openEditModal(schedule) {
            document.getElementById('edit_schedule_id').value = schedule.id;
            document.getElementById('edit_room_id').value = schedule.room_id;
            document.getElementById('edit_day_of_week').value = schedule.day_of_week;
            document.getElementById('edit_start_time').value = schedule.start_time;
            document.getElementById('edit_end_time').value = schedule.end_time;
            document.getElementById('edit_subject_code').value = schedule.subject_code;
            document.getElementById('edit_class_code').value = schedule.class_code;
            document.getElementById('edit_is_active').value = schedule.is_active ? '1' : '0';
            document.getElementById('editModal').style.display = 'flex';
        }
        function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
        function confirmDelete(id) { document.getElementById('delete_schedule_id').value = id; document.getElementById('deleteModal').style.display = 'flex'; }
        function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
    function openDeleteAllModal() { document.getElementById('deleteAllModal').style.display = 'flex'; }
    function closeDeleteAllModal() { document.getElementById('deleteAllModal').style.display = 'none'; }
        function toggleSidebar() { const s = document.getElementById('sidebar'); s.classList.toggle('active'); }
        // clicking outside closes modal
        window.onclick = function(e) { document.querySelectorAll('.modal').forEach(m => { if (e.target === m) m.style.display = 'none'; }); }
    </script>
</body>
</html>
