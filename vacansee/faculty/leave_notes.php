<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();
$message = '';

// Handle new leave note submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_date = $_POST['leave_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $reason = sanitizeInput($_POST['reason'] ?? '');

    if (!$leave_date || !$start_time || !$end_time) {
        $message = '<div class="alert alert-danger">Please complete the date and time fields.</div>';
    } elseif (strtotime($start_time) >= strtotime($end_time)) {
        $message = '<div class="alert alert-danger">Start time must be before end time.</div>';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO faculty_leave_notes (faculty_id, leave_date, start_time, end_time, reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $_SESSION['user_id'], $leave_date, $start_time, $end_time, $reason);

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'create_leave_note', 'Submitted a leave note for ' . $leave_date);
            $message = '<div class="alert alert-success">Leave note submitted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to save leave note. Please try again.</div>';
        }
        $stmt->close();
    }
}

// Fetch existing leave notes for the faculty
$notes_stmt = $conn->prepare("
    SELECT * FROM faculty_leave_notes 
    WHERE faculty_id = ? 
    ORDER BY leave_date DESC, start_time DESC
");
$notes_stmt->bind_param("i", $_SESSION['user_id']);
$notes_stmt->execute();
$leave_notes = $notes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notes_stmt->close();

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Leave Notes - VACANSEE</title>
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
                        <h1>Faculty Leave Notes</h1>
                        <p>Submit a leave note so your rooms show as available.</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container">
                <?php echo $message; ?>

                <div class="form-card">
                    <div class="form-header">
                        <h2><i class="fas fa-clipboard-list"></i> New Leave Note</h2>
                    </div>
                    <form method="POST" action="" class="form-grid">
                        <div class="form-group">
                            <label>Leave Date</label>
                            <input type="date" name="leave_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>End Time</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Reason (optional)</label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Brief reason for the leave"></textarea>
                        </div>
                        <div style="grid-column: 1 / -1; display: flex; gap: 10px; justify-content: flex-end;">
                            <button type="submit" class="btn-login" style="min-width: 180px;">
                                <i class="fas fa-save"></i> Submit Leave Note
                            </button>
                            <button type="reset" class="btn-reset" style="min-width: 140px;">
                                Clear
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Your Leave Notes</h3>
                    </div>
                    <div class="card-body">
                        <?php if (count($leave_notes) > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Time Window</th>
                                            <th>Reason</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($leave_notes as $note): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($note['leave_date'])); ?></td>
                                                <td><?php echo date('g:i A', strtotime($note['start_time'])); ?> - <?php echo date('g:i A', strtotime($note['end_time'])); ?></td>
                                                <td><?php echo htmlspecialchars($note['reason'] ?: 'No reason provided'); ?></td>
                                                <td><?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h3>No Leave Notes</h3>
                                <p>Submit a leave note to make your scheduled rooms available to others.</p>
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
            
            if (sidebar.classList.contains('active')) {
                mainContent.style.marginLeft = '250px';
            } else {
                mainContent.style.marginLeft = '0';
            }
        }
    </script>
</body>
</html>
