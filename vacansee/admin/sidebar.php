<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Lightweight pending reservation badge for admin menu
$pendingReservationCount = 0;
$newLeaveNotesCount = 0;
try {
    require_once '../config/database.php';
    $connSidebar = getConnection();
    $pendingResult = $connSidebar->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
    if ($pendingResult) {
        $pendingReservationCount = (int)$pendingResult->fetch_assoc()['count'];
    }

    // New leave notes since the admin last viewed the leave notes page (tracked in system_logs).
    $adminId = $_SESSION['user_id'] ?? null;
    if ($adminId !== null) {
        $adminId = (int)$adminId;
        $lastViewedAt = null;

        $lastViewedStmt = $connSidebar->prepare("
            SELECT MAX(created_at) AS last_viewed
            FROM system_logs
            WHERE user_id = ?
              AND action = 'view_leave_notes'
        ");
        $lastViewedStmt->bind_param("i", $adminId);
        $lastViewedStmt->execute();
        $lastViewedRow = $lastViewedStmt->get_result()->fetch_assoc();
        $lastViewedStmt->close();

        if (!empty($lastViewedRow['last_viewed'])) {
            $lastViewedAt = $lastViewedRow['last_viewed'];
        }

        if ($lastViewedAt) {
            $newNotesStmt = $connSidebar->prepare("SELECT COUNT(*) AS count FROM faculty_leave_notes WHERE created_at > ?");
            $newNotesStmt->bind_param("s", $lastViewedAt);
            $newNotesStmt->execute();
            $newLeaveNotesCount = (int)$newNotesStmt->get_result()->fetch_assoc()['count'];
            $newNotesStmt->close();
        } else {
            $allNotesResult = $connSidebar->query("SELECT COUNT(*) as count FROM faculty_leave_notes");
            if ($allNotesResult) {
                $newLeaveNotesCount = (int)$allNotesResult->fetch_assoc()['count'];
            }
        }
    }
    closeConnection($connSidebar);
} catch (Throwable $e) {
    // Silently ignore badge errors to avoid breaking the sidebar
    $pendingReservationCount = 0;
    $newLeaveNotesCount = 0;
}
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-text">
            <span class="brand-name">Vacansee</span>
            <span class="brand-subtitle">Admin Portal</span>
        </div>
    </div>
    <ul class="sidebar-menu">
        <li><a href="dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a></li>
        <li><a href="rooms.php" <?php echo basename($_SERVER['PHP_SELF']) == 'rooms.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-door-open"></i> Room Management
        </a></li>
        <li><a href="schedules.php" <?php echo basename($_SERVER['PHP_SELF']) == 'schedules.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-calendar-alt"></i> Schedule Management
        </a></li>
        <li><a href="users.php" <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-users"></i> User Management
        </a></li>
        <li><a href="reservations.php" <?php echo basename($_SERVER['PHP_SELF']) == 'reservations.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-calendar-check"></i> Reservations
            <?php if ($pendingReservationCount > 0): ?>
                <span class="menu-badge"><?php echo $pendingReservationCount; ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="reports.php" <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-chart-bar"></i> Reports
        </a></li>
        <li><a href="leave_notes.php" <?php echo basename($_SERVER['PHP_SELF']) == 'leave_notes.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-clipboard-list"></i> Faculty Leave Notes
            <?php if ($newLeaveNotesCount > 0): ?>
                <span class="menu-badge"><?php echo $newLeaveNotesCount; ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="logs.php" <?php echo basename($_SERVER['PHP_SELF']) == 'logs.php' ? 'class="active"' : ''; ?>>
            <i class="fas fa-history"></i> System Logs
        </a></li>
    </ul>
</div>
