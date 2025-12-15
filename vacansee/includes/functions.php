<?php
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

    // Check for an approved reservation currently in progress
    $reservationStmt = $conn->prepare("
        SELECT r.*, u.first_name, u.last_name
          FROM reservations r
          JOIN users u ON r.faculty_id = u.id
         WHERE r.room_id = ?
           AND r.status = 'approved'
           AND r.reservation_date = ?
           AND r.start_time <= ?
           AND r.end_time >= ?
         LIMIT 1
    ");
    $reservationStmt->bind_param("isss", $room_id, $current_date, $current_time, $current_time);
    $reservationStmt->execute();
    $resResult = $reservationStmt->get_result();
    if ($resResult->num_rows > 0) {
        $reservation = $resResult->fetch_assoc();
        $reservationStmt->close();
        return [
            'first_name'   => $reservation['first_name'],
            'last_name'    => $reservation['last_name'],
            'subject_name' => $reservation['subject_name'] ?? '',
            'subject_code' => $reservation['subject_code'] ?? '',
            'start_time'   => $reservation['start_time'],
            'end_time'     => $reservation['end_time'],
            'source'       => 'reservation'
        ];
    }
    $reservationStmt->close();
    
    $stmt = $conn->prepare("
        SELECT s.*, u.first_name, u.last_name 
        FROM schedules s 
        LEFT JOIN users u ON s.faculty_id = u.id 
        WHERE s.room_id = ? 
        AND s.day_of_week = ? 
        AND s.start_time <= ? 
        AND s.end_time >= ? 
        AND s.is_active = 1
        AND NOT EXISTS (
            SELECT 1 FROM faculty_leave_notes fln 
            WHERE fln.faculty_id = s.faculty_id
            AND fln.leave_date = ?
            AND fln.start_time <= ?
            AND fln.end_time >= ?
        )
        LIMIT 1
    ");
    $stmt->bind_param("issssss", $room_id, $current_day, $current_time, $current_time, $current_date, $current_time, $current_time);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $schedule = $result->fetch_assoc();
        $stmt->close();
        return [
            'first_name'   => $schedule['first_name'],
            'last_name'    => $schedule['last_name'],
            'subject_name' => $schedule['subject_name'] ?? '',
            'subject_code' => $schedule['subject_code'] ?? '',
            'start_time'   => $schedule['start_time'],
            'end_time'     => $schedule['end_time'],
            'source'       => 'schedule'
        ];
    }
    
    $stmt->close();
    return null;
}

/**
 * Attach live occupancy info to a room array without changing persisted fields.
 * Derives current occupancy from approved reservations first, then active schedules.
 */
function applyLiveOccupancy(&$room, $conn) {
    $roomId = (int)($room['id'] ?? 0);
    $current = getCurrentScheduleStatus($conn, $roomId);
    $persistedStatus = $room['status'] ?? 'vacant';

    if ($current) {
        $room['is_currently_occupied'] = true;
        $room['status_live'] = 'occupied';
        $room['is_available_live'] = false;
        $room['occupied_by'] = trim(($current['first_name'] ?? '') . ' ' . ($current['last_name'] ?? ''));
        $room['occupied_subject_code'] = $current['subject_code'] ?? '';
        $room['occupied_subject_name'] = $current['subject_name'] ?? '';
        $room['occupied_from'] = $current['start_time'] ?? '';
        $room['occupied_until'] = $current['end_time'] ?? '';
        $room['occupied_source'] = $current['source'] ?? '';

        // Keep persisted room status in sync so admin "Room Management" reflects live occupancy.
        if ($roomId > 0 && $persistedStatus !== 'occupied' && $persistedStatus !== 'maintenance') {
            try {
                $stmt = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ? AND status <> 'maintenance'");
                $stmt->bind_param("i", $roomId);
                $stmt->execute();
                $stmt->close();
                $room['status'] = 'occupied';
            } catch (Throwable $e) {
                // Ignore status sync errors; live status is still shown via status_live.
            }
        }
    } else {
        // Auto-vacate rooms previously marked occupied when no longer occupied.
        if ($roomId > 0 && $persistedStatus === 'occupied') {
            try {
                $stmt = $conn->prepare("UPDATE rooms SET status = 'vacant' WHERE id = ? AND status = 'occupied'");
                $stmt->bind_param("i", $roomId);
                $stmt->execute();
                $stmt->close();
                $persistedStatus = 'vacant';
                $room['status'] = 'vacant';
            } catch (Throwable $e) {
                // Ignore status sync errors.
            }
        }

        $room['is_currently_occupied'] = false;
        $room['status_live'] = $persistedStatus;
        $room['is_available_live'] = (bool)($room['is_available'] ?? true);
        $room['occupied_by'] = '';
        $room['occupied_subject_code'] = '';
        $room['occupied_subject_name'] = '';
        $room['occupied_from'] = '';
        $room['occupied_until'] = '';
        $room['occupied_source'] = '';
    }
}

function parseDateToYmd($input) {
    $input = trim((string)$input);
    if ($input === '') {
        return null;
    }

    $formats = ['Y-m-d', 'm/d/Y', 'n/j/Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $input);
        if ($dt instanceof DateTime) {
            $errors = DateTime::getLastErrors();
            if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                return $dt->format('Y-m-d');
            }
        }
    }

    $ts = strtotime($input);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

function parseTimeToHis($input) {
    $input = trim((string)$input);
    if ($input === '') {
        return null;
    }

    $formats = ['H:i:s', 'H:i', 'g:i A', 'g:iA'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $input);
        if ($dt instanceof DateTime) {
            $errors = DateTime::getLastErrors();
            if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                return $dt->format('H:i:s');
            }
        }
    }

    $ts = strtotime($input);
    if ($ts === false) {
        return null;
    }
    return date('H:i:s', $ts);
}

function ceilTimeToInterval($timestamp, $intervalMinutes = 30) {
    $intervalMinutes = max(1, (int)$intervalMinutes);
    $timestamp = (int)$timestamp;

    $seconds = $intervalMinutes * 60;
    $ceil = (int)(ceil($timestamp / $seconds) * $seconds);
    return $ceil;
}

function addMinutesToTimeOnDate($dateYmd, $startTimeHis, $minutes) {
    $dateYmd = parseDateToYmd($dateYmd);
    $startTimeHis = parseTimeToHis($startTimeHis);
    $minutes = (int)$minutes;
    if (!$dateYmd || !$startTimeHis) {
        return null;
    }

    $startTs = strtotime($dateYmd . ' ' . $startTimeHis);
    if ($startTs === false) {
        return null;
    }

    $endTs = $startTs + ($minutes * 60);
    // Keep within same day; UI selection is per-day.
    $dayEndTs = strtotime($dateYmd . ' 23:59:59');
    if ($dayEndTs !== false && $endTs > $dayEndTs) {
        $endTs = $dayEndTs;
    }

    if ($endTs <= $startTs) {
        return null;
    }

    return date('H:i:s', $endTs);
}

/**
 * Find the first schedule/reservation that conflicts with a selected date/time window.
 * Returns null if room is available.
 */
function findRoomConflict($conn, $room_id, $dateYmd, $startTimeHis, $endTimeHis = null, $excludeReservationId = 0) {
    $room_id = (int)$room_id;
    $excludeReservationId = (int)$excludeReservationId;
    $dateYmd = parseDateToYmd($dateYmd);
    $startTimeHis = parseTimeToHis($startTimeHis);
    $endTimeHis = $endTimeHis !== null ? parseTimeToHis($endTimeHis) : null;

    if (!$room_id || !$dateYmd || !$startTimeHis) {
        return null;
    }

    $isWindow = !empty($endTimeHis);

    // 1) One-time reservations (pending/approved) take precedence over schedules.
    if ($isWindow) {
        $reservationStmt = $conn->prepare("
            SELECT r.*, u.first_name, u.last_name
              FROM reservations r
              JOIN users u ON r.faculty_id = u.id
             WHERE r.room_id = ?
               AND r.reservation_date = ?
               AND r.id <> ?
               AND r.status IN ('pending','approved')
               AND (r.start_time < ? AND r.end_time > ?)
             ORDER BY (r.status = 'approved') DESC, r.start_time ASC
             LIMIT 1
        ");
        $reservationStmt->bind_param("isiss", $room_id, $dateYmd, $excludeReservationId, $endTimeHis, $startTimeHis);
    } else {
        $reservationStmt = $conn->prepare("
            SELECT r.*, u.first_name, u.last_name
              FROM reservations r
              JOIN users u ON r.faculty_id = u.id
             WHERE r.room_id = ?
               AND r.reservation_date = ?
               AND r.id <> ?
               AND r.status IN ('pending','approved')
               AND (? >= r.start_time AND ? < r.end_time)
             ORDER BY (r.status = 'approved') DESC, r.start_time ASC
             LIMIT 1
        ");
        $reservationStmt->bind_param("isiss", $room_id, $dateYmd, $excludeReservationId, $startTimeHis, $startTimeHis);
    }
    $reservationStmt->execute();
    $reservation = $reservationStmt->get_result()->fetch_assoc();
    $reservationStmt->close();

    if ($reservation) {
        return [
            'source' => 'reservation',
            'type' => 'One-time reservation',
            'status' => $reservation['status'] ?? '',
            'first_name' => $reservation['first_name'] ?? '',
            'last_name' => $reservation['last_name'] ?? '',
            'faculty_name' => trim(($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? '')),
            'subject_code' => $reservation['subject_code'] ?? '',
            'subject_name' => $reservation['subject_name'] ?? '',
            'start_time' => $reservation['start_time'] ?? '',
            'end_time' => $reservation['end_time'] ?? '',
        ];
    }

    // 2) Weekly schedules (recurring by design).
    $weekday = date('l', strtotime($dateYmd));
    if (!$weekday) {
        return null;
    }

    if ($isWindow) {
        $scheduleStmt = $conn->prepare("
            SELECT s.*, u.first_name, u.last_name
              FROM schedules s
              LEFT JOIN users u ON s.faculty_id = u.id
             WHERE s.room_id = ?
               AND s.day_of_week = ?
               AND s.is_active = 1
               AND (s.start_time < ? AND s.end_time > ?)
               AND NOT EXISTS (
                    SELECT 1 FROM faculty_leave_notes fln
                     WHERE fln.faculty_id = s.faculty_id
                       AND fln.leave_date = ?
                       AND (fln.start_time < ? AND fln.end_time > ?)
               )
             ORDER BY s.start_time ASC
             LIMIT 1
        ");
        $scheduleStmt->bind_param("issssss", $room_id, $weekday, $endTimeHis, $startTimeHis, $dateYmd, $endTimeHis, $startTimeHis);
    } else {
        $scheduleStmt = $conn->prepare("
            SELECT s.*, u.first_name, u.last_name
              FROM schedules s
              LEFT JOIN users u ON s.faculty_id = u.id
             WHERE s.room_id = ?
               AND s.day_of_week = ?
               AND s.is_active = 1
               AND (? >= s.start_time AND ? < s.end_time)
               AND NOT EXISTS (
                    SELECT 1 FROM faculty_leave_notes fln
                     WHERE fln.faculty_id = s.faculty_id
                       AND fln.leave_date = ?
                       AND (? >= fln.start_time AND ? < fln.end_time)
               )
             ORDER BY s.start_time ASC
             LIMIT 1
        ");
        $scheduleStmt->bind_param("issssss", $room_id, $weekday, $startTimeHis, $startTimeHis, $dateYmd, $startTimeHis, $startTimeHis);
    }
    $scheduleStmt->execute();
    $schedule = $scheduleStmt->get_result()->fetch_assoc();
    $scheduleStmt->close();

    if ($schedule) {
        return [
            'source' => 'schedule',
            'type' => 'Recurring',
            'status' => $schedule['is_active'] ? 'active' : 'inactive',
            'first_name' => $schedule['first_name'] ?? '',
            'last_name' => $schedule['last_name'] ?? '',
            'faculty_name' => trim(($schedule['first_name'] ?? '') . ' ' . ($schedule['last_name'] ?? '')),
            'subject_code' => $schedule['subject_code'] ?? '',
            'subject_name' => $schedule['subject_name'] ?? '',
            'start_time' => $schedule['start_time'] ?? '',
            'end_time' => $schedule['end_time'] ?? '',
        ];
    }

    return null;
}

function applyOccupancyForWindow(&$room, $conn, $dateYmd, $startTimeHis, $endTimeHis = null) {
    $roomId = (int)($room['id'] ?? 0);
    $persistedStatus = $room['status'] ?? 'vacant';

    // Maintenance always blocks.
    if ($persistedStatus === 'maintenance') {
        $room['status_window'] = 'maintenance';
        $room['is_occupied_window'] = true;
        $room['occupied_source'] = '';
        $room['occupied_type'] = '';
        $room['occupied_status'] = '';
        $room['occupied_by'] = '';
        $room['occupied_subject_code'] = '';
        $room['occupied_subject_name'] = '';
        $room['occupied_from'] = '';
        $room['occupied_until'] = '';
        return;
    }

    $conflict = findRoomConflict($conn, $roomId, $dateYmd, $startTimeHis, $endTimeHis);
    if ($conflict) {
        $room['status_window'] = 'occupied';
        $room['is_occupied_window'] = true;
        $room['occupied_source'] = $conflict['source'] ?? '';
        $room['occupied_type'] = $conflict['type'] ?? '';
        $room['occupied_status'] = $conflict['status'] ?? '';
        $room['occupied_by'] = $conflict['faculty_name'] ?? '';
        $room['occupied_subject_code'] = $conflict['subject_code'] ?? '';
        $room['occupied_subject_name'] = $conflict['subject_name'] ?? '';
        $room['occupied_from'] = $conflict['start_time'] ?? '';
        $room['occupied_until'] = $conflict['end_time'] ?? '';
        return;
    }

    $room['status_window'] = 'vacant';
    $room['is_occupied_window'] = false;
    $room['occupied_source'] = '';
    $room['occupied_type'] = '';
    $room['occupied_status'] = '';
    $room['occupied_by'] = '';
    $room['occupied_subject_code'] = '';
    $room['occupied_subject_name'] = '';
    $room['occupied_from'] = '';
    $room['occupied_until'] = '';
}

// Reservation status helpers
function isReservationDone($reservation) {
    if (($reservation['status'] ?? '') !== 'approved') {
        return false;
    }

    $date = $reservation['reservation_date'] ?? '';
    $end = $reservation['end_time'] ?? '';
    if (!$date || !$end) {
        return false;
    }

    $endTs = strtotime($date . ' ' . $end);
    if ($endTs === false) {
        return false;
    }

    return $endTs < time();
}

function isReservationOngoing($reservation) {
    if (($reservation['status'] ?? '') !== 'approved') {
        return false;
    }

    // Only treat "Use This Room" quick bookings as ongoing in the reservations list.
    if (($reservation['purpose'] ?? '') !== 'Quick class booking') {
        return false;
    }

    $date = $reservation['reservation_date'] ?? '';
    $start = $reservation['start_time'] ?? '';
    $end = $reservation['end_time'] ?? '';
    if (!$date || !$start || !$end) {
        return false;
    }

    $startTs = strtotime($date . ' ' . $start);
    $endTs = strtotime($date . ' ' . $end);
    if ($startTs === false || $endTs === false) {
        return false;
    }

    $now = time();
    return $startTs <= $now && $now <= $endTs;
}

function getReservationStatusDisplay($reservation) {
    if (isReservationDone($reservation)) {
        return 'done';
    }
    if (isReservationOngoing($reservation)) {
        return 'ongoing';
    }
    return $reservation['status'] ?? '';
}

function getNextApprovedReservation($conn, $room_id) {
    $room_id = (int)$room_id;
    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');

    $stmt = $conn->prepare("
        SELECT r.*, u.first_name, u.last_name
          FROM reservations r
          JOIN users u ON r.faculty_id = u.id
         WHERE r.room_id = ?
           AND r.status = 'approved'
           AND (
                r.reservation_date > ?
                OR (r.reservation_date = ? AND r.end_time >= ?)
           )
         ORDER BY r.reservation_date ASC, r.start_time ASC
         LIMIT 1
    ");
    $stmt->bind_param("isss", $room_id, $current_date, $current_date, $current_time);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'faculty_id' => (int)($row['faculty_id'] ?? 0),
        'faculty_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        'subject_code' => $row['subject_code'] ?? '',
        'subject_name' => $row['subject_name'] ?? '',
        'reservation_date' => $row['reservation_date'] ?? '',
        'start_time' => $row['start_time'] ?? '',
        'end_time' => $row['end_time'] ?? ''
    ];
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
    $sql = "SELECT r.*, 
            (SELECT COUNT(*) FROM schedules s 
             WHERE s.room_id = r.id 
             AND s.day_of_week = ? 
             AND s.start_time <= ? 
             AND s.end_time >= ? 
             AND s.is_active = 1
             AND NOT EXISTS (
                SELECT 1 FROM faculty_leave_notes fln
                WHERE fln.faculty_id = s.faculty_id
                AND fln.leave_date = CURDATE()
                AND fln.start_time <= ?
                AND fln.end_time >= ?
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
