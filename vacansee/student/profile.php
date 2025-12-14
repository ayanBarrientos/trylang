<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$auth = new Auth();
$user = $auth->getUserById($_SESSION['user_id']);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $data = [
            'first_name' => $_POST['first_name'],
            'last_name' => $_POST['last_name'],
            'department' => $_POST['department']
        ];
        
        $result = $auth->updateProfile($_SESSION['user_id'], $data);
        
        if ($result['success']) {
            $message = $result['message'];
            $user = $auth->getUserById($_SESSION['user_id']);
        } else {
            $error = $result['message'];
        }
    }
    
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } else {
            $result = $auth->changePassword($_SESSION['user_id'], $current_password, $new_password);
            
            if ($result['success']) {
                $message = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - VACANSEE Student</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
</head>
<body class="dashboard-page">
    <div class="dashboard">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="container header-content">
                <div class="welcome-message">
                    <h1>Profile Settings</h1>
                    <p>Manage your account information</p>
                </div>
                
                <div class="user-menu">
                    <?php include 'user_menu.php'; ?>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="container profile-container">
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                        <p><?php echo htmlspecialchars($user['email']); ?></p>
                        <p>Student • <?php echo htmlspecialchars($user['department'] ?? 'Not specified'); ?></p>
                    </div>
                </div>
                
                <!-- Account Information -->
                <div class="profile-card">
                    <h3 class="card-title"><i class="fas fa-info-circle"></i> Account Information</h3>
                    <div class="account-info">
                        <div class="info-item">
                            <div class="info-label">Account Type</div>
                            <div class="info-value"><?php echo ucfirst($user['user_type']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Department</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Member Since</div>
                            <div class="info-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email Status</div>
                            <div class="info-value" style="color: #28a745;">
                                <i class="fas fa-check-circle"></i> Verified
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Update Profile -->
                <div class="profile-card">
                    <h3 class="card-title"><i class="fas fa-user-edit"></i> Update Profile</h3>
                    <form method="POST" action="">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Department</label>
                                <select name="department" class="form-control">
                                    <option value="">Select Department</option>
                                    <option value="Engineering" <?php echo $user['department'] == 'Engineering' ? 'selected' : ''; ?>>Engineering</option>
                                    <option value="DCE" <?php echo $user['department'] == 'DCE' ? 'selected' : ''; ?>>DCE</option>
                                    <option value="Other" <?php echo $user['department'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                                <small class="form-text">Email cannot be changed</small>
                            </div>
                        </div>
                        <button type="submit" name="update_profile" class="btn-update">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </form>
                </div>
                
                <!-- Change Password -->
                <div class="profile-card">
                    <h3 class="card-title"><i class="fas fa-lock"></i> Change Password</h3>
                    <form method="POST" action="" id="passwordForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Current Password</label>
                                <div class="password-container">
                                    <input type="password" name="current_password" class="form-control" required>
                                    <button type="button" class="toggle-password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">New Password</label>
                                <div class="password-container">
                                    <input type="password" name="new_password" id="new_password" class="form-control" required minlength="8">
                                    <button type="button" class="toggle-password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength">
                                    <div class="strength-meter" id="strengthMeter"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Confirm New Password</label>
                                <div class="password-container">
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required minlength="8">
                                    <button type="button" class="toggle-password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div id="passwordMatch" class="form-text"></div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 1rem; color: var(--text-light); font-size: 0.9rem;">
                            <p>Password must contain:</p>
                            <ul>
                                <li id="lengthReq">At least 8 characters</li>
                                <li id="numberReq">At least one number</li>
                            </ul>
                        </div>
                        
                        <button type="submit" name="change_password" class="btn-update" id="changePasswordBtn" disabled>
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const input = this.parentElement.querySelector('input');
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            });
        });
        
        // Password strength checker
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const strengthMeter = document.getElementById('strengthMeter');
        const lengthReq = document.getElementById('lengthReq');
        const numberReq = document.getElementById('numberReq');
        const changePasswordBtn = document.getElementById('changePasswordBtn');
        const passwordMatch = document.getElementById('passwordMatch');
        
        function checkPasswordStrength(password) {
            let strength = 0;
            
            // Length check
            if (password.length >= 8) {
                strength += 50;
                lengthReq.style.color = '#28a745';
                lengthReq.innerHTML = '✓ At least 8 characters';
            } else {
                lengthReq.style.color = '#dc3545';
                lengthReq.innerHTML = '✗ At least 8 characters';
            }
            
            // Number check
            if (/\d/.test(password)) {
                strength += 50;
                numberReq.style.color = '#28a745';
                numberReq.innerHTML = '✓ At least one number';
            } else {
                numberReq.style.color = '#dc3545';
                numberReq.innerHTML = '✗ At least one number';
            }
            
            // Update strength meter
            strengthMeter.style.width = strength + '%';
            
            // Update strength meter color
            if (strength <= 25) {
                strengthMeter.className = 'strength-meter strength-weak';
            } else if (strength <= 50) {
                strengthMeter.className = 'strength-meter strength-fair';
            } else if (strength <= 75) {
                strengthMeter.className = 'strength-meter strength-good';
            } else {
                strengthMeter.className = 'strength-meter strength-strong';
            }
            
            return strength;
        }
        
        function checkPasswordMatch() {
            const password = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if (password && confirmPassword) {
                if (password === confirmPassword) {
                    passwordMatch.textContent = '✓ Passwords match';
                    passwordMatch.style.color = '#28a745';
                    return true;
                } else {
                    passwordMatch.textContent = '✗ Passwords do not match';
                    passwordMatch.style.color = '#dc3545';
                    return false;
                }
            }
            return false;
        }
        
        function validatePasswordForm() {
            const currentPassword = document.querySelector('input[name="current_password"]').value;
            const newPassword = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            const strength = checkPasswordStrength(newPassword);
            const match = checkPasswordMatch();
            
            // Enable button if all conditions are met
            if (currentPassword && newPassword && confirmPassword && strength === 100 && match) {
                changePasswordBtn.disabled = false;
            } else {
                changePasswordBtn.disabled = true;
            }
        }
        
        // Event listeners
        newPasswordInput.addEventListener('input', validatePasswordForm);
        confirmPasswordInput.addEventListener('input', validatePasswordForm);
        document.querySelector('input[name="current_password"]').addEventListener('input', validatePasswordForm);
        
        // Form validation
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const newPassword = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            // Check password strength
            const strength = checkPasswordStrength(newPassword);
            if (strength < 100) {
                e.preventDefault();
                alert('Please meet all password requirements.');
                return false;
            }
            
            // Check password match
            if (!checkPasswordMatch()) {
                e.preventDefault();
                alert('Passwords do not match.');
                return false;
            }
            
            return true;
        });
        
        // Initialize validation
        validatePasswordForm();
    </script>
</body>
</html>
