<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$first = $_SESSION['first_name'] ?? '';
$last = $_SESSION['last_name'] ?? '';
$userType = $_SESSION['user_type'] ?? 'student';

// Fallback values for contexts where session isn't populated but $user is available (e.g., profile.php after fetch)
if (!$first && isset($user)) {
    $first = $user['first_name'] ?? '';
    $last = $user['last_name'] ?? '';
    $userType = $user['user_type'] ?? $userType;
}
?>
<a href="profile.php" class="user-info user-profile-link">
    <div class="user-avatar">
        <?php echo strtoupper(substr($first, 0, 1)); ?>
    </div>
    <div class="user-details">
        <h4><?php echo htmlspecialchars(trim($first . ' ' . $last)); ?></h4>
        <p><?php echo htmlspecialchars($userType); ?></p>
    </div>
</a>
<a href="../logout.php" class="logout-btn">
    <i class="fas fa-sign-out-alt"></i> Logout
</a>
