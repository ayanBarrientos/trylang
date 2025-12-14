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
$useApproval = leaveNotesUseApproval($conn);

// Handle approve/reject updates (only if approval mode is enabled in DB schema)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $useApproval) {
    if (isset($_POST['update_leave_status'])) {
        $note_id = isset($_POST['note_id']) ? (int)$_POST['note_id'] : 0;
        $status = sanitizeInput($_POST['status'] ?? '');
        if ($note_id <= 0 || !in_array($status, ['approved', 'rejected', 'pending'], true)) {
            $message = '<div class="alert alert-danger">Invalid request.</div>';
        } else {
            $stmt = $conn->prepare("UPDATE faculty_leave_notes SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $admin_id = (int)($_SESSION['user_id'] ?? 0);
            $stmt->bind_param("sii", $status, $admin_id, $note_id);
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Leave note updated.</div>';
                logActivity($conn, $_SESSION['user_id'] ?? null, 'update_leave_note_status', "Updated leave note $note_id to $status");
            } else {
                $message = '<div class="alert alert-danger">Failed to update leave note.</div>';
            }
            $stmt->close();
        }
    }
}

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
                <?php echo $message; ?>
                <?php if (!$useApproval): ?>
                    <div class="alert alert-warning">
                        
                    </div>
                <?php endif; ?>
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
                                            <?php if ($useApproval): ?><th>Status</th><?php endif; ?>
                                            <?php if ($useApproval): ?><th>Actions</th><?php endif; ?>
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
                                                <?php if ($useApproval): ?>
                                                    <td>
                                                        <?php
                                                            $st = $note['status'] ?? 'pending';
                                                            $badgeColor = $st === 'approved' ? '#22c55e' : ($st === 'rejected' ? '#ff6b6b' : '#f59e0b');
                                                        ?>
                                                        <span class="menu-badge" style="background: <?php echo $badgeColor; ?>;"><?php echo htmlspecialchars(ucfirst($st)); ?></span>
                                                    </td>
                                                    <td>
                                                        <form method="POST" action="" style="display:flex; gap:8px; align-items:center;">
                                                            <input type="hidden" name="note_id" value="<?php echo (int)$note['id']; ?>">
                                                            <input type="hidden" name="update_leave_status" value="1">
                                                            <button type="submit" name="status" value="approved" class="action-btn btn-approve" <?php echo (($note['status'] ?? 'pending') === 'approved') ? 'disabled' : ''; ?>>
                                                                <i class="fas fa-check"></i> Approve
                                                            </button>
                                                            <button type="submit" name="status" value="rejected" class="action-btn btn-reject" <?php echo (($note['status'] ?? 'pending') === 'rejected') ? 'disabled' : ''; ?>>
                                                                <i class="fas fa-times"></i> Reject
                                                            </button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
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
