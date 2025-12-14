<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    redirectBasedOnRole($_SESSION['user_type']);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $user_type = $_POST['user_type'];
    
    // Validate email domain
    if (!preg_match('/@umindanao\.edu\.ph$/', $email)) {
        $error = 'Please use your @umindanao.edu.ph email address.';
    } elseif (strlen($password) < 8 || !preg_match('/\d/', $password)) {
        $error = 'Password must be at least 8 characters long and contain at least one number.';
    } else {
        $conn = getConnection();
        
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND user_type = ? AND is_active = 1");
        $stmt->bind_param("ss", $email, $user_type);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                // Successful login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['department'] = $user['department'];
                
                // Log login activity
                logActivity($conn, $user['id'], 'login', 'User logged in successfully');
                
                // Redirect based on user type
                redirectBasedOnRole($user['user_type']);
            } else {
                $error = 'Invalid email or password.';
            }
        } else {
            $error = 'Invalid email or password, or account is not active.';
        }
        
        $stmt->close();
        closeConnection($conn);
    }
}

// Get role from URL if specified
$selected_role = isset($_GET['role']) ? $_GET['role'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - VACANSEE</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 2rem;
        }
        
        .login-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-hover);
            width: 100%;
            max-width: 450px;
            padding: 3rem;
            position: relative;
            overflow: hidden;
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .login-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 1rem;
        }
        
        .login-logo img {
            height: 50px;
            width: auto;
        }
        
        .login-logo-text h1 {
            font-size: 1.2rem;
            color: var(--secondary-color);
            margin: 0;
        }
        
        .login-logo-text p {
            font-size: 0.8rem;
            color: var(--primary-color);
            margin: 0;
        }
        
        .login-title {
            font-size: 1.8rem;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }
        
        .login-subtitle {
            color: var(--text-light);
            font-size: 0.95rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(190, 0, 2, 0.1);
        }
        
        .password-container {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        .user-type-selector {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .user-type-btn {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--border-color);
            background: var(--bg-light);
            border-radius: var(--border-radius);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .user-type-btn:hover {
            border-color: var(--primary-light);
        }
        
        .user-type-btn.active {
            border-color: var(--primary-color);
            background: rgba(190, 0, 2, 0.1);
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .user-type-btn i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            margin-bottom: 1.5rem;
        }
        
        .btn-login:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        
        .login-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }
        
        .password-requirements {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 0.5rem;
            padding-left: 10px;
        }
        
        .password-requirements ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        
        .password-requirements li.valid {
            color: var(--success-color);
        }
        
        .password-requirements li.invalid {
            color: var(--text-light);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <img src="assets/images/UM-Tagum-College-1950-removebg-preview.png" alt="UM Logo">
                    <div class="login-logo-text">
                        <h1>UNIVERSITY OF MINDANAO</h1>
                        <p>Visayan Campus</p>
                    </div>
                </div>
                <h2 class="login-title">VACANSEE Login</h2>
                <p class="login-subtitle">Access the Room Vacancy System</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <div class="user-type-selector">
                    <button type="button" class="user-type-btn <?php echo ($selected_role == 'admin' || !$selected_role) ? 'active' : ''; ?>" data-type="admin">
                        <i class="fas fa-user-shield"></i>
                        Admin
                    </button>
                    <button type="button" class="user-type-btn <?php echo $selected_role == 'faculty' ? 'active' : ''; ?>" data-type="faculty">
                        <i class="fas fa-chalkboard-teacher"></i>
                        Faculty
                    </button>
                    <button type="button" class="user-type-btn <?php echo $selected_role == 'student' ? 'active' : ''; ?>" data-type="student">
                        <i class="fas fa-user-graduate"></i>
                        Student
                    </button>
                </div>
                
                <input type="hidden" name="user_type" id="user_type" value="<?php echo $selected_role ?: 'admin'; ?>">
                
                <div class="form-group">
                    <label for="email" class="form-label">
                        <i class="fas fa-envelope"></i> UM Email Address
                    </label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           class="form-control" 
                           placeholder="username@umindanao.edu.ph" 
                           required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <div class="password-container">
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control" 
                               placeholder="Enter your password" 
                               required
                               minlength="8">
                        <button type="button" class="toggle-password" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-requirements">
                        <p>Password must contain:</p>
                        <ul>
                            <li id="lengthRequirement" class="invalid">At least 8 characters</li>
                            <li id="numberRequirement" class="invalid">At least one number</li>
                        </ul>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Login to VACANSEE
                </button>
                
                <div class="login-footer">
                    <p>Don't have an account? 
                        <a href="register.php?role=<?php echo isset($_GET['role']) ? $_GET['role'] : 'student'; ?>">
                            Register here
                        </a>
                    </p>
                    <p><a href="index.html"><i class="fas fa-arrow-left"></i> Back to Home</a></p>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = togglePassword.querySelector('i');
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            eyeIcon.classList.toggle('fa-eye');
            eyeIcon.classList.toggle('fa-eye-slash');
        });
        
        // User type selection
        document.querySelectorAll('.user-type-btn').forEach(button => {
            button.addEventListener('click', function() {
                document.querySelectorAll('.user-type-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                document.getElementById('user_type').value = this.dataset.type;
            });
        });
        
        // Password validation
        const passwordInputField = document.getElementById('password');
        const lengthRequirement = document.getElementById('lengthRequirement');
        const numberRequirement = document.getElementById('numberRequirement');
        
        passwordInputField.addEventListener('input', function() {
            const password = this.value;
            
            // Check length
            if (password.length >= 8) {
                lengthRequirement.classList.remove('invalid');
                lengthRequirement.classList.add('valid');
            } else {
                lengthRequirement.classList.remove('valid');
                lengthRequirement.classList.add('invalid');
            }
            
            // Check for number
            if (/\d/.test(password)) {
                numberRequirement.classList.remove('invalid');
                numberRequirement.classList.add('valid');
            } else {
                numberRequirement.classList.remove('valid');
                numberRequirement.classList.add('invalid');
            }
        });
        
        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            
            // Validate UM email
            if (!email.endsWith('@umindanao.edu.ph')) {
                e.preventDefault();
                alert('Please use your @umindanao.edu.ph email address.');
                return false;
            }
            
            // Validate password
            if (password.length < 8 || !/\d/.test(password)) {
                e.preventDefault();
                alert('Password must be at least 8 characters long and contain at least one number.');
                return false;
            }
        });
        
        // Auto-select user type from URL parameter
        const urlParams = new URLSearchParams(window.location.search);
        const role = urlParams.get('role');
        if (role) {
            document.querySelectorAll('.user-type-btn').forEach(btn => {
                if (btn.dataset.type === role) {
                    btn.click();
                }
            });
        }
    </script>
</body>
</html>