<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();
$faculty_id = (int)$_SESSION['user_id'];
$message = '';

// Handle delete first to avoid conflicting form states
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    $delete_id = (int)$_POST['delete_schedule'];
    $stmt = $conn->prepare("DELETE FROM schedules WHERE id = ? AND faculty_id = ?");
    $stmt->bind_param("ii", $delete_id, $faculty_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $message = '<div class="alert alert-success">Schedule removed.</div>';
    } else {
        $message = '<div class="alert alert-danger">Unable to remove schedule.</div>';
    }
    $stmt->close();
}

// Handle add/update submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_schedule'])) {
    $mode = isset($_POST['mode']) ? $_POST['mode'] : 'add';
    $room_id = (int)$_POST['room_id'];
    $day_values = isset($_POST['day_of_week']) ? (array)$_POST['day_of_week'] : [];
    $start_time = sanitizeInput($_POST['start_time']);
    $end_time = sanitizeInput($_POST['end_time']);
    $subject_code = sanitizeInput($_POST['subject_code']);
    $subject_name = sanitizeInput($_POST['subject_name']);
    $schedule_id = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;

    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $day_values = array_values(array_filter($day_values, function($d) use ($valid_days) {
        return in_array($d, $valid_days, true);
    }));

    if (count($day_values) === 0) {
        $message = '<div class="alert alert-danger">Please select at least one valid day.</div>';
    } elseif ($start_time >= $end_time) {
        $message = '<div class="alert alert-danger">End time must be after start time.</div>';
    } else {
        if ($mode === 'edit' && $schedule_id > 0) {
            // For edit, enforce a single day (the existing record)
            $day_of_week = $day_values[0];
            $stmt = $conn->prepare("
                UPDATE schedules 
                   SET room_id = ?, day_of_week = ?, start_time = ?, end_time = ?, 
                       subject_code = ?, subject_name = ?
                 WHERE id = ? AND faculty_id = ?
            ");
            $stmt->bind_param(
                "isssssii",
                $room_id,
                $day_of_week,
                $start_time,
                $end_time,
                $subject_code,
                $subject_name,
                $schedule_id,
                $faculty_id
            );
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = '<div class="alert alert-success">Schedule updated successfully.</div>';
            } else {
                $message = '<div class="alert alert-danger">Unable to update schedule.</div>';
            }
            $stmt->close();
        } else {
            // Add one entry per selected day
            $stmt = $conn->prepare("
                INSERT INTO schedules (room_id, day_of_week, start_time, end_time, subject_code, subject_name, faculty_id, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $created = 0;
            foreach ($day_values as $day_of_week) {
                $stmt->bind_param(
                    "isssssi",
                    $room_id,
                    $day_of_week,
                    $start_time,
                    $end_time,
                    $subject_code,
                    $subject_name,
                    $faculty_id
                );
                if ($stmt->execute()) {
                    $created++;
                }
            }
            $stmt->close();
            if ($created > 0) {
                $message = '<div class="alert alert-success">Schedule added for ' . $created . ' day(s).</div>';
            } else {
                $message = '<div class="alert alert-danger">Error adding schedule.</div>';
            }
        }
    }
}

// Fetch rooms for dropdown
$rooms_query = "SELECT id, room_code, room_name FROM rooms ORDER BY room_code";
$rooms = $conn->query($rooms_query)->fetch_all(MYSQLI_ASSOC);

// Fetch faculty schedules
$schedules_query = "
    SELECT s.*, r.room_code, r.room_name
      FROM schedules s
      JOIN rooms r ON s.room_id = r.id
     WHERE s.faculty_id = ?
     ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time
";
$stmt = $conn->prepare($schedules_query);
$stmt->bind_param("i", $faculty_id);
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - VACANSEE Faculty</title>
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
                        <h1>My Schedule</h1>
                        <p>Maintain your weekly room assignments</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container">
                <?php echo $message; ?>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-alt"></i> Add / Edit Schedule</h3>
                        <small id="formModeLabel">Create a new weekly slot</small>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="scheduleForm" class="form-grid">
                            <input type="hidden" name="mode" id="formMode" value="add">
                            <input type="hidden" name="schedule_id" id="scheduleId" value="">

                            <div class="form-group">
                                <label>Room *</label>
                                <select name="room_id" class="form-control" id="roomSelect" required>
                                    <option value="">Select a room</option>
                                    <?php foreach ($rooms as $room): ?>
                                        <option value="<?php echo $room['id']; ?>">
                                            <?php echo htmlspecialchars($room['room_code'] . ' - ' . $room['room_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Day(s) of Week *</label>
                                <div class="days-filter" id="dayCheckboxes" style="flex-wrap: wrap; gap: 8px;">
                                    <?php
                                        $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                        foreach ($days as $day): ?>
                                            <label class="day-btn" style="cursor: pointer;">
                                                <input type="checkbox" name="day_of_week[]" value="<?php echo $day; ?>" style="display:none;">
                                                <?php echo $day; ?>
                                            </label>
                                    <?php endforeach; ?>
                                </div>
                                <small class="form-text">Select one or multiple days (adds a slot per day on save).</small>
                            </div>

                            <div class="form-group">
                                <label>Start Time *</label>
                                <input type="time" name="start_time" id="startTime" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label>End Time *</label>
                                <input type="time" name="end_time" id="endTime" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label>Subject Code</label>
                                <input type="text" name="subject_code" id="subjectCode" class="form-control" placeholder="e.g., PHY-101">
                            </div>

                            <div class="form-group">
                                <label>Subject Name</label>
                                <input type="text" name="subject_name" id="subjectName" class="form-control" placeholder="e.g., Physics Laboratory">
                            </div>

                            <div class="form-actions" style="grid-column: 1 / -1; display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="submit" name="save_schedule" class="btn-login" id="submitButton">
                                    <i class="fas fa-save"></i> Save Schedule
                                </button>
                                <button type="button" class="btn-reset" onclick="resetForm()">
                                    Cancel / New
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-week"></i> Weekly Schedule</h3>
                        <span>Total: <?php echo count($schedules); ?> slots</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($schedules) > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Day</th>
                                            <th>Time</th>
                                            <th>Room</th>
                                            <th>Subject</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($schedules as $schedule): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($schedule['day_of_week']); ?></td>
                                                <td>
                                                    <?php echo date('g:i A', strtotime($schedule['start_time'])); ?>
                                                    -
                                                    <?php echo date('g:i A', strtotime($schedule['end_time'])); ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($schedule['room_code']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($schedule['room_name']); ?></small>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($schedule['subject_code'] ?? ''); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($schedule['subject_name'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button 
                                                            class="action-btn edit-btn"
                                                            onclick='editSchedule(<?php echo json_encode($schedule); ?>)'>
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this schedule?');">
                                                            <input type="hidden" name="delete_schedule" value="<?php echo $schedule['id']; ?>">
                                                            <button type="submit" class="action-btn delete-btn">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>No schedule yet</h3>
                                <p>Add your weekly room assignments to see them here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('active');
            mainContent.style.marginLeft = sidebar.classList.contains('active') ? '250px' : '0';
        }

        function resetForm() {
            document.getElementById('scheduleForm').reset();
            document.getElementById('formMode').value = 'add';
            document.getElementById('scheduleId').value = '';
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-save"></i> Save Schedule';
            document.getElementById('formModeLabel').textContent = 'Create a new weekly slot';
            document.querySelectorAll('#dayCheckboxes input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
                cb.parentElement.classList.remove('active');
            });
        }

        function editSchedule(data) {
            document.getElementById('formMode').value = 'edit';
            document.getElementById('scheduleId').value = data.id;
            document.getElementById('roomSelect').value = data.room_id;
            // Clear and set day checkboxes (edit uses single day)
            document.querySelectorAll('#dayCheckboxes input[type="checkbox"]').forEach(cb => {
                cb.checked = cb.value === data.day_of_week;
                cb.parentElement.classList.toggle('active', cb.checked);
            });
            document.getElementById('startTime').value = data.start_time;
            document.getElementById('endTime').value = data.end_time;
            document.getElementById('subjectCode').value = data.subject_code || '';
            document.getElementById('subjectName').value = data.subject_name || '';
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-save"></i> Update Schedule';
            document.getElementById('formModeLabel').textContent = 'Editing schedule';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Toggle active state on day checkboxes
        document.querySelectorAll('#dayCheckboxes .day-btn input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', function() {
                this.parentElement.classList.toggle('active', this.checked);
            });
        });
    </script>
</body>
</html>
