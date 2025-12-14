<a href="profile.php" class="user-info user-profile-link">
    <div class="user-avatar">
        <?php echo strtoupper(substr($_SESSION['first_name'], 0, 1)); ?>
    </div>
    <div class="user-details">
        <h4><?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></h4>
        <p><?php echo htmlspecialchars($_SESSION['user_type']); ?></p>
    </div>
</a>
<a href="../logout.php" class="logout-btn">
    <i class="fas fa-sign-out-alt"></i> Logout
</a>
