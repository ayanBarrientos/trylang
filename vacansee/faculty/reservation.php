<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();
$faculty_id = $_SESSION['user_id'];
$message = '';

// Get room details if room_id is provided
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$room = null;
$edit_reservation = null;

// If editing, load reservation details
if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE id = ? AND faculty_id = ?");
    $stmt->bind_param("ii", $edit_id, $faculty_id);
    $stmt->execute();
    $edit_reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($edit_reservation) {
        $room_id = (int)$edit_reservation['room_id'];
    } else {
        $edit_id = 0; // invalid edit request
    }
}

if ($room_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Handle reservation form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_id = (int)$_POST['room_id'];
    $edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
    $subject_code = sanitizeInput($_POST['subject_code']);
    $class_code = sanitizeInput($_POST['class_code']);
    $reservation_date = sanitizeInput($_POST['reservation_date']);
    $start_time = sanitizeInput($_POST['start_time']);
    $end_time = sanitizeInput($_POST['end_time']);
    $purpose = sanitizeInput($_POST['purpose']);

    // Server-side validation (don't rely only on client-side JS)
    if ($start_time >= $end_time) {
        $message = '<div class="alert alert-danger">End time must be after start time.</div>';
    } else {
        // Check if room is available at requested time
        $check_availability = $conn->prepare("
            SELECT 1 FROM reservations 
            WHERE room_id = ? 
              AND reservation_date = ? 
              AND id <> ? 
              AND (start_time < ? AND end_time > ?)
              AND status IN ('pending', 'approved')
        ");
        $check_availability->bind_param(
            "isiss",
            $room_id,
            $reservation_date,
            $edit_id,
            $end_time,
            $start_time
        );
        $check_availability->execute();
        $existing_reservations = $check_availability->get_result();

        if ($existing_reservations->num_rows > 0) {
            $message = '<div class="alert alert-danger">Room is already reserved for the selected time slot.</div>';
        } else {
            // Prevent faculty from double-booking themselves across different rooms.
            $check_faculty_conflict = $conn->prepare("
                SELECT 1 FROM reservations
                WHERE faculty_id = ?
                  AND reservation_date = ?
                  AND id <> ?
                  AND (start_time < ? AND end_time > ?)
                  AND status IN ('pending', 'approved')
            ");
            $check_faculty_conflict->bind_param(
                "isiss",
                $faculty_id,
                $reservation_date,
                $edit_id,
                $end_time,
                $start_time
            );
            $check_faculty_conflict->execute();
            $faculty_conflicts = $check_faculty_conflict->get_result();

            if ($faculty_conflicts->num_rows > 0) {
                $message = '<div class="alert alert-danger">You already have a reservation during the selected time slot.</div>';
            } else {
                if ($edit_id > 0) {
            // Update reservation
            $stmt = $conn->prepare("
                UPDATE reservations 
                   SET room_id = ?, subject_code = ?, class_code = ?, reservation_date = ?, start_time = ?, end_time = ?, purpose = ?, status = 'pending'
                 WHERE id = ? AND faculty_id = ?
            ");
            $stmt->bind_param(
                "issssssii",
                $room_id,
                $subject_code,
                $class_code,
                $reservation_date,
                $start_time,
                $end_time,
                $purpose,
                $edit_id,
                $faculty_id
            );
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Reservation updated successfully and sent for approval.</div>';
                logActivity($conn, $faculty_id, 'update_reservation', "Updated reservation ID: $edit_id");
            } else {
                $message = '<div class="alert alert-danger">Error updating reservation: ' . $conn->error . '</div>';
            }
            $stmt->close();
        } else {
            // Insert reservation
            $stmt = $conn->prepare("
                INSERT INTO reservations 
                (room_id, faculty_id, subject_code, class_code, reservation_date, start_time, end_time, purpose, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->bind_param(
                "iissssss",
                $room_id,
                $faculty_id,
                $subject_code,
                $class_code,
                $reservation_date,
                $start_time,
                $end_time,
                $purpose
            );

            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Reservation request submitted successfully! Waiting for admin approval.</div>';
                logActivity($conn, $faculty_id, 'reservation_request', "Requested reservation for room ID: $room_id");

                // Reset form
                $room_id = 0;
                $room = null;
            } else {
                $message = '<div class="alert alert-danger">Error submitting reservation: ' . $conn->error . '</div>';
            }
            $stmt->close();
        }
            }
            $check_faculty_conflict->close();
        }
        $check_availability->close();
    }
}

// Form defaults
$subject_code_val = isset($_POST['subject_code']) ? htmlspecialchars($_POST['subject_code']) : ($edit_reservation['subject_code'] ?? '');
$class_code_val = isset($_POST['class_code']) ? htmlspecialchars($_POST['class_code']) : ($edit_reservation['class_code'] ?? '');
$reservation_date_val = isset($_POST['reservation_date']) ? htmlspecialchars($_POST['reservation_date']) : ($edit_reservation['reservation_date'] ?? '');
$start_time_val = isset($_POST['start_time']) ? htmlspecialchars($_POST['start_time']) : ($edit_reservation['start_time'] ?? '08:00');
$end_time_val = isset($_POST['end_time']) ? htmlspecialchars($_POST['end_time']) : ($edit_reservation['end_time'] ?? '09:00');
$purpose_val = isset($_POST['purpose']) ? htmlspecialchars($_POST['purpose']) : ($edit_reservation['purpose'] ?? '');

// Get all available rooms
$rooms_query = "
    SELECT * FROM rooms 
    WHERE is_available = 1 
      AND status = 'vacant' 
    ORDER BY department, room_code
";
$rooms = $conn->query($rooms_query)->fetch_all(MYSQLI_ASSOC);

closeConnection($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Reservation - VACANSEE Faculty</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard-page">
    <div class="dashboard">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- Header -->
            <div class="dashboard-header">
                <div class="container header-content">
                    <button class="menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <div class="welcome-message">
                        <h1>Make Reservation</h1>
                        <p>Reserve a room for your class</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include __DIR__ . '/user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container reservation-container">
                <?php echo $message; ?>
                
                <div class="form-card">
                    <div class="form-header">
                        <h2><i class="fas fa-calendar-plus"></i> New Room Reservation</h2>
                        <p>Fill in the details to reserve a room for your class</p>
                    </div>
                    
                    <form method="POST" action="" id="reservationForm">
                        <!-- Room Selection -->
                        <div class="room-selection">
                            <h4>1. Select a Room</h4>
                            <div class="room-list" id="roomList">
                                <?php foreach ($rooms as $room_item): ?>
                                    <div class="room-option <?php echo $room_item['id'] == $room_id ? 'selected' : ''; ?>" 
                                         data-room-id="<?php echo $room_item['id']; ?>"
                                         data-room-code="<?php echo htmlspecialchars($room_item['room_code']); ?>"
                                         data-room-name="<?php echo htmlspecialchars($room_item['room_name']); ?>"
                                         data-department="<?php echo htmlspecialchars($room_item['department']); ?>"
                                         data-capacity="<?php echo $room_item['capacity']; ?>"
                                         data-aircon="<?php echo $room_item['has_aircon']; ?>"
                                         data-projector="<?php echo $room_item['has_projector']; ?>"
                                         data-computers="<?php echo $room_item['has_computers']; ?>"
                                         data-whiteboard="<?php echo $room_item['has_whiteboard']; ?>">
                                        <div class="room-info">
                                            <div class="room-details">
                                                <div class="room-code"><?php echo htmlspecialchars($room_item['room_code']); ?></div>
                                                <div class="room-name"><?php echo htmlspecialchars($room_item['room_name']); ?></div>
                                                <div class="room-amenities">
                                                    <?php 
                                                    echo getAmenityIcon('aircon', $room_item['has_aircon']);
                                                    echo getAmenityIcon('projector', $room_item['has_projector']);
                                                    echo getAmenityIcon('computers', $room_item['has_computers']);
                                                    echo getAmenityIcon('whiteboard', $room_item['has_whiteboard']);
                                                    ?>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="room-department"><?php echo htmlspecialchars($room_item['department']); ?></span>
                                                <div style="text-align: right; margin-top: 5px;">
                                                    <small><?php echo $room_item['capacity']; ?> seats</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Selected Room Display -->
                        <div class="selected-room <?php echo $room ? 'show' : ''; ?>" id="selectedRoomDisplay">
                            <h4>Selected Room</h4>
                            <div id="selectedRoomDetails">
                        <?php if ($room): ?>
                            <div class="room-info">
                                <div class="room-details">
                                    <div class="room-code"><?php echo htmlspecialchars($room['room_code']); ?></div>
                                    <div class="room-name"><?php echo htmlspecialchars($room['room_name']); ?> | <?php echo $room['capacity']; ?> seats | <?php echo htmlspecialchars($room['department']); ?></div>
                                    <div class="room-amenities">
                                        <?php 
                                        echo getAmenityIcon('aircon', $room['has_aircon']);
                                        echo getAmenityIcon('projector', $room['has_projector']);
                                        echo getAmenityIcon('computers', $room['has_computers']);
                                        echo getAmenityIcon('whiteboard', $room['has_whiteboard']);
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <input type="hidden" name="room_id" id="selectedRoomId" value="<?php echo $room_id; ?>">
                <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
                        
                        <!-- Subject Details -->
                        <div class="form-group">
                            <label class="form-label">2. Subject Details</label>
                            <div class="form-row">
                                <div class="form-group">
                                   <input type="text" name="class_code" class="form-control" 
                                           placeholder="Class Code (e.g., 9023)" required
                                           value="<?php echo $class_code_val; ?>">
                                </div>
                                <div class="form-group">
                                    <input type="text" name="subject_code" class="form-control" 
                                           placeholder="Subject Code (e.g., IT14/L)" required
                                           value="<?php echo $subject_code_val; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Date and Time -->
                        <div class="form-group">
                            <label class="form-label">3. Schedule</label>
                            <div class="form-row">
                                <div class="form-group">
                                    <input type="date" name="reservation_date" class="form-control" required
                                           min="<?php echo date('Y-m-d'); ?>"
                                           value="<?php echo $reservation_date_val; ?>">
                                </div>
                                <div class="form-group">
                                    <div class="time-inputs">
                                        <input type="time" name="start_time" class="form-control" required
                                               value="<?php echo $start_time_val; ?>">
                                        <input type="time" name="end_time" class="form-control" required
                                               value="<?php echo $end_time_val; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Purpose -->
                        <div class="form-group">
                            <label class="form-label">4. Purpose (Optional)</label>
                            <textarea name="purpose" class="form-control" 
                                      placeholder="Additional details about your reservation..."><?php echo $purpose_val; ?></textarea>
                        </div>
                        
                        <!-- Submit Button -->
                        <button type="submit" class="btn-submit" id="submitBtn" <?php echo !$room ? 'disabled' : ''; ?>>
                            <i class="fas fa-paper-plane"></i> <?php echo $edit_id > 0 ? 'Update Reservation' : 'Submit Reservation Request'; ?>
                        </button>
                    </form>
                </div>
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
        
        // Room selection
        const roomOptions = document.querySelectorAll('.room-option');
        const selectedRoomId = document.getElementById('selectedRoomId');
        const selectedRoomDisplay = document.getElementById('selectedRoomDisplay');
        const selectedRoomDetails = document.getElementById('selectedRoomDetails');
        const submitBtn = document.getElementById('submitBtn');
        
        roomOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                roomOptions.forEach(opt => opt.classList.remove('selected'));
                
                // Add selected class to clicked option
                this.classList.add('selected');
                
                // Update hidden input
                selectedRoomId.value = this.dataset.roomId;
                
                // Update selected room display
                selectedRoomDetails.innerHTML = `
                    <div class="room-info">
                        <div class="room-details">
                            <div class="room-code">${this.dataset.roomCode}</div>
                            <div class="room-name">${this.dataset.roomName} | ${this.dataset.capacity} seats | ${this.dataset.department}</div>
                            <div class="room-amenities">
                                ${this.dataset.aircon == 1 ? '<i class="fas fa-snowflake text-info" title="Air Conditioned"></i>' : ''}
                                ${this.dataset.projector == 1 ? '<i class="fas fa-video text-primary" title="Projector Available"></i>' : ''}
                                ${this.dataset.computers == 1 ? '<i class="fas fa-desktop text-success" title="Computers Available"></i>' : ''}
                                ${this.dataset.whiteboard == 1 ? '<i class="fas fa-chalkboard text-secondary" title="Whiteboard Available"></i>' : ''}
                            </div>
                        </div>
                    </div>
                `;
                
                // Show selected room display
                selectedRoomDisplay.classList.add('show');
                
                // Enable submit button
                submitBtn.disabled = false;
            });
        });
        
        // Form validation
        document.getElementById('reservationForm').addEventListener('submit', function(e) {
            const startTime = document.querySelector('input[name="start_time"]').value;
            const endTime = document.querySelector('input[name="end_time"]').value;
            const reservationDate = document.querySelector('input[name="reservation_date"]').value;
            
            // Check if end time is after start time
            if (startTime >= endTime) {
                e.preventDefault();
                alert('End time must be after start time.');
                return false;
            }
            
            // Check if date is not in the past
            const today = new Date().toISOString().split('T')[0];
            if (reservationDate < today) {
                e.preventDefault();
                alert('Reservation date cannot be in the past.');
                return false;
            }
            
            // Check if room is selected
            if (!selectedRoomId.value) {
                e.preventDefault();
                alert('Please select a room.');
                return false;
            }
            
            return true;
        });
        
        // Set minimum time for end time input
        const startTimeInput = document.querySelector('input[name="start_time"]');
        const endTimeInput = document.querySelector('input[name="end_time"]');
        
        startTimeInput.addEventListener('change', function() {
            endTimeInput.min = this.value;
            if (endTimeInput.value <= this.value) {
                const time = this.value.split(':');
                let hour = parseInt(time[0]);
                let minute = parseInt(time[1]);
                minute += 30;
                if (minute >= 60) {
                    hour += 1;
                    minute -= 60;
                }
                endTimeInput.value = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
            }
        });
        
        // Auto-select room if room_id is in URL
        const urlParams = new URLSearchParams(window.location.search);
        const roomId = urlParams.get('room_id');
        if (roomId) {
            const roomOption = document.querySelector(`.room-option[data-room-id="${roomId}"]`);
            if (roomOption) {
                roomOption.click();
            }
        }
    </script>
</body>
</html>
