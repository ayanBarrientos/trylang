<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Notification badges for faculty reservations
$facultyPendingCount = 0;
$facultyApprovedCount = 0;
if (isset($_SESSION['user_id'])) {
    try {
        require_once '../config/database.php';
        $connSidebar = getConnection();
        $stmtSidebar = $connSidebar->prepare("SELECT COUNT(*) as count FROM reservations WHERE faculty_id = ? AND status = 'pending'");
        $stmtSidebar->bind_param("i", $_SESSION['user_id']);
        $stmtSidebar->execute();
        $resultSidebar = $stmtSidebar->get_result();
        if ($resultSidebar) {
            $facultyPendingCount = (int)$resultSidebar->fetch_assoc()['count'];
        }
        $stmtSidebar->close();

        // Count approved reservations awaiting view
        $stmtApproved = $connSidebar->prepare("SELECT COUNT(*) as count FROM reservations WHERE faculty_id = ? AND status = 'approved' AND faculty_viewed = 0");
        $stmtApproved->bind_param("i", $_SESSION['user_id']);
        $stmtApproved->execute();
        $resultApproved = $stmtApproved->get_result();
        if ($resultApproved) {
            $facultyApprovedCount = (int)$resultApproved->fetch_assoc()['count'];
        }
        $stmtApproved->close();

        closeConnection($connSidebar);
    } catch (Throwable $e) {
        $facultyPendingCount = 0;
        $facultyApprovedCount = 0;
    }
}
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-text">
            <span class="brand-name">Vacansee</span>
            <span class="brand-subtitle">Faculty Portal</span>
        </div>
    </div>
    <ul class="sidebar-menu">
        <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a></li>
        <li><a href="schedules.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'schedules.php' ? 'active' : ''; ?>" title="Manage your schedules">
            <i class="fas fa-calendar-alt"></i> Schedules
        </a></li>
        <li><a href="reservations.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'reservations.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-check"></i> My Reservations
            <?php if ($facultyApprovedCount > 0): ?>
                <span class="menu-badge badge-success" title="Approved reservations"><?php echo $facultyApprovedCount; ?></span>
            <?php endif; ?>
            <?php if ($facultyPendingCount > 0): ?>
                <span class="menu-badge" title="Pending reservations"><?php echo $facultyPendingCount; ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="leave_notes.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'leave_notes.php' ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-list"></i> Leave Notes
        </a></li>
    </ul>
</div>
