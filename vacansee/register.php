<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    redirectBasedOnRole($_SESSION['user_type']);
}

$auth = new Auth();
$error = '';
$success = '';

// Get role from URL
$allowed_roles = ['admin', 'faculty', 'student'];
$selected_role = isset($_GET['role']) && in_array($_GET['role'], $allowed_roles) ? $_GET['role'] : 'student';

if (!in_array($selected_role, $allowed_roles)) {
    $selected_role = 'student';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $post_role = isset($_POST['user_type']) && in_array($_POST['user_type'], $allowed_roles) ? $_POST['user_type'] : 'student';
    
    $data = [
        'email' => $_POST['email'],
        'password' => $_POST['password'],
        'confirm_password' => $_POST['confirm_password'],
        'first_name' => $_POST['first_name'],
        'last_name' => $_POST['last_name'],
        'user_type' => $post_role,
        'department' => $_POST['department'] ?? null
    ];
    
    // Keep UI selection in sync
    $selected_role = $data['user_type'];
    
    $result = $auth->register($data);
    
    if ($result['success']) {
        $success = $result['message'];
        // Clear form
        $_POST = [];
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - VACANSEE</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        <?php include 'login_styles.php'; ?>
        
        .registration-card {
            max-width: 560px;
        }
        
        .user-type-indicator {
            background: rgba(190, 0, 2, 0.08);
            border: 2px solid rgba(190, 0, 2, 0.25);
            color: var(--secondary-color);
            padding: 12px;
            border-radius: var(--border-radius);
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 600;
            display: inline-flex;
            gap: 10px;
            align-items: center;
            justify-content: center;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .password-strength {
            height: 5px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .strength-meter {
            height: 100%;
            width: 0;
            transition: width 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak {
            background: #e74c3c;
        }
        
        .strength-fair {
            background: #f39c12;
        }
        
        .strength-good {
            background: var(--primary-color);
        }
        
        .strength-strong {
            background: var(--secondary-color);
        }
        
        .terms-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 1.5rem 0;
        }
        
        .terms-checkbox input {
            margin: 0;
        }
        
        .terms-checkbox label {
            font-size: 0.9rem;
            color: var(--text-light);
        }
        
        .terms-checkbox a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        
        .terms-checkbox a:hover {
            text-decoration: underline;
        }
        
        .btn-register {
            background: var(--primary-color);
        }
        
        .btn-register:hover {
            background: var(--primary-dark);
        }

        /* Legal modal */
        .legal-modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 9999;
        }

        .legal-modal .legal-content {
            background: #fff;
            max-width: 720px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 12px;
            box-shadow: var(--shadow-hover);
            padding: 1.25rem 1.5rem;
        }

        .legal-modal h3 {
            margin-top: 0;
            margin-bottom: 0.75rem;
            color: var(--secondary-color);
        }

        .legal-meta {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .legal-content ul {
            padding-left: 1.2rem;
            margin: 0.5rem 0 1rem;
        }

        .legal-content li {
            margin-bottom: 0.35rem;
            line-height: 1.5;
        }

        .legal-close {
            margin-top: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-color);
            color: #fff;
            border: none;
            padding: 0.55rem 1rem;
            border-radius: 8px;
            cursor: pointer;
        }

        .legal-close:hover {
            background: var(--primary-dark);
        }
    </style>
</head>
<body>
    <div class="registration-container">
        <div class="registration-card">
            <div class="registration-header">
                <div class="registration-logo">
                    <img src="assets/images/UM-Tagum-College-1950-removebg-preview.png" alt="UM Logo">
                    <div class="registration-logo-text">
                        <h1>UNIVERSITY OF MINDANAO</h1>
                        <p>Visayan Campus</p>
                    </div>
                </div>
                <h2 class="registration-title">Create Account</h2>
                <p class="login-subtitle">Register for VACANSEE System</p>
            </div>

            <div class="user-type-indicator">
                <i class="fas <?php echo $selected_role === 'admin' ? 'fa-user-shield' : ($selected_role === 'faculty' ? 'fa-chalkboard-teacher' : 'fa-user-graduate'); ?>" id="roleIcon"></i>
                <span id="roleLabel">Registering as <?php echo ucfirst($selected_role); ?></span>
            </div>

            <div class="user-type-selector" style="margin-bottom: 1.5rem;">
                <button type="button" class="user-type-btn <?php echo $selected_role === 'admin' ? 'active' : ''; ?>" data-type="admin">
                    <i class="fas fa-user-shield"></i>
                    Admin
                </button>
                <button type="button" class="user-type-btn <?php echo $selected_role === 'faculty' ? 'active' : ''; ?>" data-type="faculty">
                    <i class="fas fa-chalkboard-teacher"></i>
                    Faculty
                </button>
                <button type="button" class="user-type-btn <?php echo $selected_role === 'student' ? 'active' : ''; ?>" data-type="student">
                    <i class="fas fa-user-graduate"></i>
                    Student
                </button>
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
            
            <form method="POST" action="" id="registrationForm">
                <input type="hidden" name="user_type" id="user_type" value="<?php echo $selected_role; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name" class="form-label">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" required
                               value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name" class="form-label">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" required
                               value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">UM Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control" required
                           placeholder="username@umindanao.edu.ph"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    <small class="form-text">Must end with @umindanao.edu.ph</small>
                </div>
                
                <div class="form-group" id="departmentGroup" style="<?php echo $selected_role === 'faculty' ? '' : 'display:none;'; ?>">
                    <label for="department" class="form-label">Department *</label>
                    <select id="department" name="department" class="form-control" <?php echo $selected_role === 'faculty' ? 'required' : ''; ?>>
                        <option value="">Select Department</option>
                        <option value="Engineering" <?php echo isset($_POST['department']) && $_POST['department'] == 'Engineering' ? 'selected' : ''; ?>>Engineering</option>
                        <option value="DCE" <?php echo isset($_POST['department']) && $_POST['department'] == 'DCE' ? 'selected' : ''; ?>>DCE</option>
                        <option value="Other" <?php echo isset($_POST['department']) && $_POST['department'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password" class="form-label">Password *</label>
                        <div class="password-container">
                            <input type="password" id="password" name="password" class="form-control" required minlength="8">
                            <button type="button" class="toggle-password" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="strength-meter" id="strengthMeter"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                        <div class="password-container">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8">
                            <button type="button" class="toggle-password" id="toggleConfirmPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="passwordMatch" class="form-text"></div>
                    </div>
                </div>
                
                <div class="password-requirements">
                    <p>Password must contain:</p>
                    <ul>
                        <li id="lengthReq" class="invalid">At least 8 characters</li>
                        <li id="numberReq" class="invalid">At least one number</li>
                        <li id="matchReq" class="invalid">Passwords must match</li>
                    </ul>
                </div>
                
                <div class="terms-checkbox">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">
                        I agree to the <a href="#" onclick="showTerms()">Terms of Service</a> and <a href="#" onclick="showPrivacy()">Privacy Policy</a>
                    </label>
                </div>
                
                <button type="submit" class="btn-register" id="registerBtn" disabled>
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
                
                <div class="registration-footer">
                    <p>Already have an account? 
                        <a href="login.php?role=<?php echo $selected_role; ?>">
                            Login here
                        </a>
                    </p>
                    <p><a href="index.html"><i class="fas fa-arrow-left"></i> Back to Home</a></p>
                </div>
            </form>
    </div>
</div>

<!-- Legal modal -->
<div id="legalModal" class="legal-modal" onclick="closeLegal(event)">
    <div class="legal-content">
        <h3 id="legalTitle">Legal</h3>
        <div class="legal-meta" id="legalMeta"></div>
        <div id="legalBody"></div>
        <button class="legal-close" onclick="closeLegal(event)">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>

<script>
        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.querySelector('i').classList.toggle('fa-eye');
            this.querySelector('i').classList.toggle('fa-eye-slash');
        });
        
        toggleConfirmPassword.addEventListener('click', function() {
            const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPasswordInput.setAttribute('type', type);
            this.querySelector('i').classList.toggle('fa-eye');
            this.querySelector('i').classList.toggle('fa-eye-slash');
        });
        
        // Password strength checker
        const passwordInputField = document.getElementById('password');
        const confirmPasswordField = document.getElementById('confirm_password');
        const strengthMeter = document.getElementById('strengthMeter');
        const lengthReq = document.getElementById('lengthReq');
        const numberReq = document.getElementById('numberReq');
        const matchReq = document.getElementById('matchReq');
        const registerBtn = document.getElementById('registerBtn');
        const passwordMatch = document.getElementById('passwordMatch');
        const roleButtons = document.querySelectorAll('.user-type-btn');
        const userTypeInput = document.getElementById('user_type');
        const roleLabel = document.getElementById('roleLabel');
        const roleIcon = document.getElementById('roleIcon');
        const departmentGroup = document.getElementById('departmentGroup');
        const departmentField = document.getElementById('department');
        
        function checkPasswordStrength(password) {
            let strength = 0;
            
            // Length check
            if (password.length >= 8) {
                strength += 25;
                lengthReq.classList.remove('invalid');
                lengthReq.classList.add('valid');
            } else {
                lengthReq.classList.remove('valid');
                lengthReq.classList.add('invalid');
            }
            
            // Number check
            if (/\d/.test(password)) {
                strength += 25;
                numberReq.classList.remove('invalid');
                numberReq.classList.add('valid');
            } else {
                numberReq.classList.remove('valid');
                numberReq.classList.add('invalid');
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
            const password = passwordInputField.value;
            const confirmPassword = confirmPasswordField.value;
            
            if (password && confirmPassword) {
                if (password === confirmPassword) {
                    matchReq.classList.remove('invalid');
                    matchReq.classList.add('valid');
                    passwordMatch.textContent = 'Passwords match';
                    passwordMatch.style.color = '#28a745';
                    return true;
                } else {
                    matchReq.classList.remove('valid');
                    matchReq.classList.add('invalid');
                    passwordMatch.textContent = 'Passwords do not match';
                    passwordMatch.style.color = '#dc3545';
                    return false;
                }
            }
            return false;
        }
        
        function validateForm() {
            const password = passwordInputField.value;
            const confirmPassword = confirmPasswordField.value;
            const firstName = document.getElementById('first_name').value;
            const lastName = document.getElementById('last_name').value;
            const email = document.getElementById('email').value;
            const terms = document.getElementById('terms').checked;
            const selectedRole = userTypeInput.value;
            const departmentValid = selectedRole !== 'faculty' || (departmentField && departmentField.value);
            
            const strength = checkPasswordStrength(password);
            const match = checkPasswordMatch();
            
            // Enable register button if all conditions are met
            if (firstName && lastName && email && strength >= 50 && match && terms && departmentValid) {
                registerBtn.disabled = false;
            } else {
                registerBtn.disabled = true;
            }
        }
        
        // Event listeners
        passwordInputField.addEventListener('input', validateForm);
        confirmPasswordField.addEventListener('input', validateForm);
        document.getElementById('first_name').addEventListener('input', validateForm);
        document.getElementById('last_name').addEventListener('input', validateForm);
        document.getElementById('email').addEventListener('input', validateForm);
        document.getElementById('terms').addEventListener('change', validateForm);
        if (departmentField) {
            departmentField.addEventListener('change', validateForm);
        }
        
        // Role selection
        roleButtons.forEach(button => {
            button.addEventListener('click', function() {
                roleButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                
                const role = this.dataset.type;
                userTypeInput.value = role;
                
                // Update indicator
                const iconMap = {
                    admin: 'fa-user-shield',
                    faculty: 'fa-chalkboard-teacher',
                    student: 'fa-user-graduate'
                };
                roleIcon.className = `fas ${iconMap[role]}`;
                roleLabel.textContent = `Registering as ${role.charAt(0).toUpperCase() + role.slice(1)}`;
                
                // Toggle department field for faculty
                if (role === 'faculty') {
                    departmentGroup.style.display = '';
                    if (departmentField) {
                        departmentField.required = true;
                    }
                } else {
                    departmentGroup.style.display = 'none';
                    if (departmentField) {
                        departmentField.required = false;
                        departmentField.value = '';
                    }
                }
                
                validateForm();
            });
        });
        
        // Email validation
        document.getElementById('email').addEventListener('blur', function() {
            const email = this.value;
            if (email && !email.endsWith('@umindanao.edu.ph')) {
                this.setCustomValidity('Please use your @umindanao.edu.ph email address.');
                this.reportValidity();
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Form submission validation
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            
            const role = userTypeInput.value;

            // Validate UM email
            if (!email.endsWith('@umindanao.edu.ph')) {
                e.preventDefault();
                alert('Please use your @umindanao.edu.ph email address.');
                return false;
            }
            
            // Validate password strength
            const strength = checkPasswordStrength(password);
            if (strength < 50) {
                e.preventDefault();
                alert('Password is too weak. Please use a stronger password.');
                return false;
            }
            
            // Validate password match
            if (!checkPasswordMatch()) {
                e.preventDefault();
                alert('Passwords do not match.');
                return false;
            }

            // Validate department if faculty
            if (role === 'faculty' && (!departmentField || !departmentField.value)) {
                e.preventDefault();
                alert('Please select your department.');
                return false;
            }
            
            return true;
        });
        
        function showTerms() {
            const title = 'Terms of Service';
            const meta = 'Effective: January 15, 2025 | Applies to all VACANSEE users (admins, faculty, students)';
            const body = `
                <p>VACANSEE provides a scheduling and room reservation system for the University of Mindanao. By creating an account or using the platform, you agree to:</p>
                <ul>
                    <li>Use your official UM credentials and keep your login details confidential.</li>
                    <li>Submit accurate reservation details and avoid duplicate or conflicting bookings.</li>
                    <li>Respect room policies (capacity, equipment care, start/end times) and leave rooms ready for the next user.</li>
                    <li>Accept that admins may approve, modify, or cancel reservations to resolve conflicts or operational needs.</li>
                    <li>Use the system only for university-related activities; no commercial or unauthorized events.</li>
                    <li>Comply with UM code of conduct; misuse can lead to account suspension or disciplinary action.</li>
                </ul>
                <p>Service availability: We aim for reliable access but may perform maintenance or suspend access for security or operational reasons. We may update these terms; continued use means acceptance of updates.</p>
                <p>Support: For issues or appeals about reservations, contact the system administrator or your department office.</p>
            `;
            openLegal(title, meta, body);
            return false;
        }
        
        function showPrivacy() {
            const title = 'Privacy Policy';
            const meta = 'Effective: January 15, 2025 | VACANSEE (University of Mindanao)';
            const body = `
                <p>VACANSEE collects and uses your information to operate the room reservation system and secure access.</p>
                <ul>
                    <li><strong>Data we collect:</strong> UM email, name, role, department; reservation details (rooms, dates, times, purpose); activity logs (login events, changes to reservations).</li>
                    <li><strong>How we use it:</strong> Authenticate users, manage and audit reservations, prevent conflicts, and improve service reliability.</li>
                    <li><strong>Access:</strong> Admins can view and manage reservation data for operational purposes. Faculty and students can access only their own reservations.</li>
                    <li><strong>Retention:</strong> Reservation history and logs are retained for audit and reporting in line with UM policies.</li>
                    <li><strong>Security:</strong> We use credential hashing and role-based access. No system is immune to risk; report suspected issues immediately.</li>
                    <li><strong>Sharing:</strong> Data is used within UM for scheduling, reporting, and compliance; we do not sell personal data.</li>
                    <li><strong>Your choices:</strong> You may update your profile information and can request corrections through the administrator.</li>
                </ul>
                <p>Updates: We may revise this policy; continued use of VACANSEE means you accept the changes.</p>
            `;
            openLegal(title, meta, body);
            return false;
        }

        function openLegal(title, meta, bodyHtml) {
            document.getElementById('legalTitle').textContent = title;
            document.getElementById('legalMeta').textContent = meta;
            document.getElementById('legalBody').innerHTML = bodyHtml;
            document.getElementById('legalModal').style.display = 'flex';
        }

        function closeLegal(event) {
            // Close on overlay click or button click
            if (event.target.id === 'legalModal' || event.target.classList.contains('legal-close')) {
                document.getElementById('legalModal').style.display = 'none';
            }
        }
        
        // Initialize validation
        validateForm();
    </script>
</body>
</html>
