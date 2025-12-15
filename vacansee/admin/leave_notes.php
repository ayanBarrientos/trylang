<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();

// Track that the admin viewed leave notes (used for sidebar badge count).
try {
    logActivity($conn, $_SESSION['user_id'] ?? null, 'view_leave_notes', 'Viewed faculty leave notes');
} catch (Throwable $e) {
    // ignore
}

$notes_query = "
    SELECT fln.*, u.first_name, u.last_name, u.email 
    FROM faculty_leave_notes fln
    LEFT JOIN users u ON fln.faculty_id = u.id
    ORDER BY fln.leave_date DESC, fln.start_time DESC
";
$leave_notes = $conn->query($notes_query)->fetch_all(MYSQLI_ASSOC);

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Leave Notes - VACANSEE Admin</title>
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
                        <p>View submitted leave notes (read-only)</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clipboard-list"></i> All Leave Notes</h3>
                    </div>
                    <div class="card-body">
                        <?php if (count($leave_notes) > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Faculty</th>
                                            <th>Date</th>
                                            <th>Time Window</th>
                                            <th>Reason</th>
                                            <th>Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($leave_notes as $note): ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars(trim(($note['first_name'] ?? '') . ' ' . ($note['last_name'] ?? ''))); ?><br>
                                                    <small><?php echo htmlspecialchars($note['email'] ?? ''); ?></small>
                                                </td>
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
                                <h3>No Leave Notes Yet</h3>
                                <p>Faculty leave notes will appear here once submitted.</p>
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
