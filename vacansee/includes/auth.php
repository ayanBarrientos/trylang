<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// Authentication functions
class Auth {
    private $conn;
    
    public function __construct() {
        $this->conn = getConnection();
    }
    
    // Register new user
    public function register($data) {
        $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
        $password = $data['password'];
        $confirm_password = $data['confirm_password'];
        $first_name = sanitizeInput($data['first_name']);
        $last_name = sanitizeInput($data['last_name']);
        $user_type = sanitizeInput($data['user_type']);
        $department = isset($data['department']) ? sanitizeInput($data['department']) : null;
        
        // Validate email domain
        if (!preg_match('/@umindanao\.edu\.ph$/', $email)) {
            return ['success' => false, 'message' => 'Please use your @umindanao.edu.ph email address.'];
        }
        
        // Validate password
        $password_validation = validatePassword($password);
        if ($password_validation !== true) {
            return ['success' => false, 'message' => $password_validation];
        }
        
        // Check if passwords match
        if ($password !== $confirm_password) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }
        
        // Check if email already exists
        if (emailExists($this->conn, $email)) {
            return ['success' => false, 'message' => 'Email already registered.'];
        }
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert user
        $stmt = $this->conn->prepare("
            INSERT INTO users (email, password, first_name, last_name, user_type, department) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssss", $email, $hashed_password, $first_name, $last_name, $user_type, $department);
        
        if ($stmt->execute()) {
            $user_id = $stmt->insert_id;
            logActivity($this->conn, $user_id, 'register', 'New user registered');
            $stmt->close();
            
            return ['success' => true, 'message' => 'Registration successful! You can now login.'];
        } else {
            $stmt->close();
            return ['success' => false, 'message' => 'Registration failed: ' . $this->conn->error];
        }
    }
    
    // Login user
    public function login($email, $password, $user_type) {
        // Validate email domain
        if (!preg_match('/@umindanao\.edu\.ph$/', $email)) {
            return ['success' => false, 'message' => 'Please use your @umindanao.edu.ph email address.'];
        }
        
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ? AND user_type = ? AND is_active = 1");
        $stmt->bind_param("ss", $email, $user_type);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['department'] = $user['department'];
                $_SESSION['last_login'] = time();
                
                // Regenerate session ID for security
                regenerateSession();
                
                // Log login activity
                logActivity($this->conn, $user['id'], 'login', 'User logged in successfully');
                
                $stmt->close();
                return ['success' => true, 'user_type' => $user['user_type']];
            }
        }
        
        $stmt->close();
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }
    
    // Change password
    public function changePassword($user_id, $current_password, $new_password) {
        // Get user current password
        $stmt = $this->conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify current password
            if (!password_verify($current_password, $user['password'])) {
                $stmt->close();
                return ['success' => false, 'message' => 'Current password is incorrect.'];
            }
            
            // Validate new password
            $password_validation = validatePassword($new_password);
            if ($password_validation !== true) {
                $stmt->close();
                return ['success' => false, 'message' => $password_validation];
            }
            
            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $update_stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($update_stmt->execute()) {
                logActivity($this->conn, $user_id, 'change_password', 'Password changed successfully');
                $update_stmt->close();
                $stmt->close();
                return ['success' => true, 'message' => 'Password changed successfully.'];
            }
            
            $update_stmt->close();
        }
        
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to change password.'];
    }
    
    // Update profile
    public function updateProfile($user_id, $data) {
        $first_name = sanitizeInput($data['first_name']);
        $last_name = sanitizeInput($data['last_name']);
        $department = isset($data['department']) ? sanitizeInput($data['department']) : null;
        
        $stmt = $this->conn->prepare("UPDATE users SET first_name = ?, last_name = ?, department = ? WHERE id = ?");
        $stmt->bind_param("sssi", $first_name, $last_name, $department, $user_id);
        
        if ($stmt->execute()) {
            // Update session variables
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['department'] = $department;
            
            logActivity($this->conn, $user_id, 'update_profile', 'Profile updated');
            $stmt->close();
            return ['success' => true, 'message' => 'Profile updated successfully.'];
        }
        
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to update profile.'];
    }
    
    // Get user by ID
    public function getUserById($user_id) {
        $stmt = $this->conn->prepare("SELECT id, email, first_name, last_name, user_type, department, created_at FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user;
    }
    
    // Check if email exists
    public function checkEmailExists($email) {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }
    
    // Logout
    public function logout($user_id) {
        logActivity($this->conn, $user_id, 'logout', 'User logged out');
        destroySession();
        return ['success' => true];
    }
    
    public function __destruct() {
        closeConnection($this->conn);
    }
}
?>
