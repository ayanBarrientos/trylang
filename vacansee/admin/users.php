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
    if (isset($_POST['add_user'])) {
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $user_type = sanitizeInput($_POST['user_type']);
        $department = sanitizeInput($_POST['department']);
        
        // Validate email domain
        if (!preg_match('/@umindanao\.edu\.ph$/', $email)) {
            $message = '<div class="alert alert-danger">Please use @umindanao.edu.ph email address.</div>';
        } elseif (strlen($password) < 8 || !preg_match('/\d/', $password)) {
            $message = '<div class="alert alert-danger">Password must be at least 8 characters with a number.</div>';
        } else {
            // Check if email exists
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = '<div class="alert alert-danger">Email already exists.</div>';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("
                    INSERT INTO users (email, password, first_name, last_name, user_type, department) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("ssssss", $email, $hashed_password, $first_name, $last_name, $user_type, $department);
                
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">User added successfully!</div>';
                    logActivity($conn, $_SESSION['user_id'], 'add_user', "Added user: $email");
                } else {
                    $message = '<div class="alert alert-danger">Error adding user: ' . $conn->error . '</div>';
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
    }
    
    elseif (isset($_POST['update_user'])) {
        $user_id = (int)$_POST['user_id'];
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $user_type = sanitizeInput($_POST['user_type']);
        $department = sanitizeInput($_POST['department']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $conn->prepare("
            UPDATE users SET 
            first_name = ?, last_name = ?, user_type = ?, department = ?, is_active = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("ssssii", $first_name, $last_name, $user_type, $department, $is_active, $user_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">User updated successfully!</div>';
            logActivity($conn, $_SESSION['user_id'], 'update_user', "Updated user ID: $user_id");
        } else {
            $message = '<div class="alert alert-danger">Error updating user: ' . $conn->error . '</div>';
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['reset_password'])) {
        $user_id = (int)$_POST['user_id'];
        $new_password = $_POST['new_password'];
        
        if (strlen($new_password) < 8 || !preg_match('/\d/', $new_password)) {
            $message = '<div class="alert alert-danger">Password must be at least 8 characters with a number.</div>';
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Password reset successfully!</div>';
                logActivity($conn, $_SESSION['user_id'], 'reset_password', "Reset password for user ID: $user_id");
            } else {
                $message = '<div class="alert alert-danger">Error resetting password: ' . $conn->error . '</div>';
            }
            $stmt->close();
        }
    }
}

// Get all users
$users_query = "SELECT * FROM users ORDER BY user_type, last_name, first_name";
$users_result = $conn->query($users_query);
$users = $users_result->fetch_all(MYSQLI_ASSOC);

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - VACANSEE Admin</title>
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
                        <h1>User Management</h1>
                        <p>Manage system users and permissions</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container users-container">
                <?php echo $message; ?>
                
                <!-- Add User Button -->
                <div style="margin-top: 0.5rem; margin-bottom: 2rem;">
                    <button class="btn-login" onclick="openAddModal()">
                        <i class="fas fa-user-plus"></i> Add New User
                    </button>
                </div>

                <!-- Filters -->
                <div class="user-filters">
                    <h3><i class="fas fa-filter"></i> Filter Users</h3>
                    <div class="filter-controls">
                        <select class="form-control" id="roleFilter" style="width: 200px;">
                            <option value="">All Roles</option>
                            <option value="admin">Administrators</option>
                            <option value="faculty">Faculty</option>
                            <option value="student">Students</option>
                        </select>
                        <select class="form-control" id="statusFilter" style="width: 200px;">
                            <option value="">All Status</option>
                            <option value="active">Active Only</option>
                            <option value="inactive">Inactive Only</option>
                        </select>
                        <input type="text" class="form-control" id="searchInput" placeholder="Search by name or email..." style="flex: 1;">
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-users"></i> All Users</h3>
                        <span>Total: <?php echo count($users); ?> users</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="userTable">
                                    <?php foreach ($users as $user): ?>
                                        <tr data-role="<?php echo $user['user_type']; ?>" 
                                            data-status="<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>"
                                            data-search="<?php echo strtolower(htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' ' . $user['email'])); ?>">
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div class="user-avatar avatar-<?php echo $user['user_type']; ?>">
                                                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <span class="user-role role-<?php echo $user['user_type']; ?>">
                                                    <?php echo ucfirst($user['user_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="user-status <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                            <td class="actions-cell">
                                                <div class="user-actions">
                                                    <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button class="action-btn reset-btn" onclick="openResetModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')">
                                                        <i class="fas fa-key"></i> Reset PW
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
    </div>

    <!-- Add User Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                <button class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="addForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" name="email" class="form-control" required 
                                   placeholder="username@umindanao.edu.ph">
                        </div>
                        <div class="form-group">
                            <label>User Type *</label>
                            <select name="user_type" class="form-control" required>
                                <option value="admin">Administrator</option>
                                <option value="faculty">Faculty</option>
                                <option value="student">Student</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Department</label>
                            <select name="department" class="form-control">
                                <option value="">Select Department</option>
                                <option value="Engineering">Engineering</option>
                                <option value="DCE">DCE</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Password *</label>
                            <div class="password-container">
                                <input type="password" name="password" id="add_password" class="form-control" required minlength="8">
                                <button type="button" class="toggle-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-requirements">
                                Must be at least 8 characters with a number
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="add_user" class="btn-login" style="flex: 1;">
                            <i class="fas fa-save"></i> Add User
                        </button>
                        <button type="button" class="logout-btn" onclick="closeAddModal()" style="flex: 1;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit User</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" id="edit_email" class="form-control" disabled>
                            <small class="form-text">Email cannot be changed</small>
                        </div>
                        <div class="form-group">
                            <label>User Type *</label>
                            <select name="user_type" id="edit_user_type" class="form-control" required>
                                <option value="admin">Administrator</option>
                                <option value="faculty">Faculty</option>
                                <option value="student">Student</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Department</label>
                            <select name="department" id="edit_department" class="form-control">
                                <option value="">Select Department</option>
                                <option value="Engineering">Engineering</option>
                                <option value="DCE">DCE</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <div style="margin-top: 8px;">
                                <label class="amenity-checkbox">
                                    <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                                    Active User
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="update_user" class="btn-login" style="flex: 1;">
                            <i class="fas fa-save"></i> Update User
                        </button>
                        <button type="button" class="logout-btn" onclick="closeEditModal()" style="flex: 1;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header" style="background: var(--info-color);">
                <h3><i class="fas fa-key"></i> Reset Password</h3>
                <button class="close-modal" onclick="closeResetModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Reset password for <strong id="reset_user_name"></strong></p>
                
                <form method="POST" action="" id="resetForm">
                    <input type="hidden" name="user_id" id="reset_user_id">
                    
                    <div class="form-group">
                        <label>New Password *</label>
                        <div class="password-container">
                            <input type="password" name="new_password" id="reset_password" class="form-control" required minlength="8">
                            <button type="button" class="toggle-password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-requirements">
                            Must be at least 8 characters with a number
                        </div>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 10px;">
                        <button type="submit" name="reset_password" class="btn-login" style="flex: 1; background: var(--info-color);">
                            <i class="fas fa-key"></i> Reset Password
                        </button>
                        <button type="button" class="logout-btn" onclick="closeResetModal()" style="flex: 1;">
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
        const roleFilter = document.getElementById('roleFilter');
        const statusFilter = document.getElementById('statusFilter');
        const searchInput = document.getElementById('searchInput');
        const userRows = document.querySelectorAll('#userTable tr');
        
        function filterUsers() {
            const selectedRole = roleFilter.value;
            const selectedStatus = statusFilter.value;
            const searchTerm = searchInput.value.toLowerCase();
            
            userRows.forEach(row => {
                const role = row.dataset.role;
                const status = row.dataset.status;
                const search = row.dataset.search;
                
                let show = true;
                
                if (selectedRole && role !== selectedRole) show = false;
                if (selectedStatus && status !== selectedStatus) show = false;
                if (searchTerm && !search.includes(searchTerm)) show = false;
                
                row.style.display = show ? '' : 'none';
            });
        }
        
        roleFilter.addEventListener('change', filterUsers);
        statusFilter.addEventListener('change', filterUsers);
        searchInput.addEventListener('input', filterUsers);
        
        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_first_name').value = user.first_name;
            document.getElementById('edit_last_name').value = user.last_name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_user_type').value = user.user_type;
            document.getElementById('edit_department').value = user.department || '';
            document.getElementById('edit_is_active').checked = user.is_active == 1;
            
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function openResetModal(userId, userName) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_user_name').textContent = userName;
            document.getElementById('resetModal').style.display = 'flex';
        }
        
        function closeResetModal() {
            document.getElementById('resetModal').style.display = 'none';
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
        
        // Password toggle
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const input = this.parentElement.querySelector('input');
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            });
        });
        
        // Add form validation
        document.getElementById('addForm').addEventListener('submit', function(e) {
            const email = document.querySelector('input[name="email"]').value;
            const password = document.getElementById('add_password').value;
            
            if (!email.endsWith('@umindanao.edu.ph')) {
                e.preventDefault();
                alert('Please use @umindanao.edu.ph email address.');
                return false;
            }
            
            if (password.length < 8 || !/\d/.test(password)) {
                e.preventDefault();
                alert('Password must be at least 8 characters with a number.');
                return false;
            }
            
            return true;
        });
        
        // Reset form validation
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const password = document.getElementById('reset_password').value;
            
            if (password.length < 8 || !/\d/.test(password)) {
                e.preventDefault();
                alert('Password must be at least 8 characters with a number.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>
