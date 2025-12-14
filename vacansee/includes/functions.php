<?php
function leaveNotesUseApproval($conn) {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $res = $conn->query("SHOW COLUMNS FROM faculty_leave_notes LIKE 'status'");
        $cached = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

// Redirect user based on role
function redirectBasedOnRole($role) {
    switch ($role) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'faculty':
            header('Location: faculty/dashboard.php');
            break;
        case 'student':
            header('Location: student/dashboard.php');
            break;
        default:
            header('Location: login.php');
    }
    exit();
}

// Log activity
function logActivity($conn, $user_id, $action, $description = '') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    try {
        $userExists = false;
        if ($user_id !== null) {
            $user_id = (int)$user_id;
            $check = $conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
            $check->bind_param("i", $user_id);
            $check->execute();
            $res = $check->get_result();
            $userExists = $res && $res->num_rows > 0;
            $check->close();
        }

        if ($userExists) {
            $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $user_id, $action, $description, $ip_address, $user_agent);
        } else {
            // Avoid FK failures if session contains a deleted/invalid user ID.
            $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) VALUES (NULL, ?, ?, ?, ?)");
            $stmt->bind_param("ssss", $action, $description, $ip_address, $user_agent);
        }

        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Never break app flow due to logging failures.
    }
}

// Get room status color
function getRoomStatusColor($status) {
    switch ($status) {
        case 'vacant':
            return 'success';
        case 'occupied':
            return 'danger';
        case 'reserved':
            return 'warning';
        case 'maintenance':
            return 'secondary';
        default:
            return 'light';
    }
}

// Get room status text
function getRoomStatusText($status) {
    switch ($status) {
        case 'vacant':
            return 'Vacant';
        case 'occupied':
            return 'Occupied';
        case 'reserved':
            return 'Reserved';
        case 'maintenance':
            return 'Under Maintenance';
        default:
            return 'Unknown';
    }
}

// Get current schedule status
function getCurrentScheduleStatus($conn, $room_id) {
    $current_day = date('l');
    $current_time = date('H:i:s');
    $current_date = date('Y-m-d');

    $leaveApprovalCondition = leaveNotesUseApproval($conn) ? "AND fln.status = 'approved'" : "";
    
    $stmt = $conn->prepare("
        SELECT s.*, u.first_name, u.last_name 
        FROM schedules s 
        LEFT JOIN users u ON s.faculty_id = u.id 
        WHERE s.room_id = ? 
        AND LOWER(s.day_of_week) = LOWER(?) 
        AND s.start_time <= ? 
        AND s.end_time > ? 
        AND s.is_active = 1
        AND NOT EXISTS (
            SELECT 1 FROM faculty_leave_notes fln 
            WHERE fln.faculty_id = s.faculty_id
            AND fln.leave_date = ?
            AND fln.start_time <= ?
            AND fln.end_time > ?
            $leaveApprovalCondition
        )
        LIMIT 1
    ");
    $stmt->bind_param("issssss", $room_id, $current_day, $current_time, $current_time, $current_date, $current_time, $current_time);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Check if email exists
function emailExists($conn, $email) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

// Validate password strength
function validatePassword($password) {
    if (strlen($password) < 8) {
        return "Password must be at least 8 characters long.";
    }
    
    if (!preg_match('/\d/', $password)) {
        return "Password must contain at least one number.";
    }
    
    return true;
}

// Sanitize input
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Format date for display
function formatDate($date, $format = 'F j, Y g:i A') {
    $dateTime = new DateTime($date);
    return $dateTime->format($format);
}

// Get available rooms
function getAvailableRooms($conn, $department = null) {
    $leaveApprovalCondition = leaveNotesUseApproval($conn) ? "AND fln.status = 'approved'" : "";
    $sql = "SELECT r.*, 
            (SELECT COUNT(*) FROM schedules s 
             WHERE s.room_id = r.id 
             AND LOWER(s.day_of_week) = LOWER(?) 
             AND s.start_time <= ? 
             AND s.end_time > ? 
             AND s.is_active = 1
             AND NOT EXISTS (
                SELECT 1 FROM faculty_leave_notes fln
                WHERE fln.faculty_id = s.faculty_id
                AND fln.leave_date = CURDATE()
                AND fln.start_time <= ?
                AND fln.end_time > ?
                $leaveApprovalCondition
             )
            ) as currently_occupied
            FROM rooms r 
            WHERE r.is_available = 1";
    
    if ($department) {
        $sql .= " AND r.department = ?";
    }
    
    $sql .= " ORDER BY r.department, r.room_code";
    
    $current_day = date('l');
    $current_time = date('H:i:s');
    
    $stmt = $conn->prepare($sql);
    
    if ($department) {
        $stmt->bind_param("ssssss", $current_day, $current_time, $current_time, $current_time, $current_time, $department);
    } else {
        $stmt->bind_param("sssss", $current_day, $current_time, $current_time, $current_time, $current_time);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $rooms = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $rooms;
}

// Get amenities icon
function getAmenityIcon($amenity, $has_amenity) {
    if (!$has_amenity) {
        return '<i class="fas fa-times text-danger"></i>';
    }
    
    switch ($amenity) {
        case 'aircon':
            return '<i class="fas fa-snowflake text-info" title="Air Conditioned"></i>';
        case 'projector':
            return '<i class="fas fa-video text-primary" title="Projector Available"></i>';
        case 'computers':
            return '<i class="fas fa-desktop text-success" title="Computers Available"></i>';
        case 'whiteboard':
            return '<i class="fas fa-chalkboard text-secondary" title="Whiteboard Available"></i>';
        default:
            return '<i class="fas fa-check text-success"></i>';
    }
}
?>
