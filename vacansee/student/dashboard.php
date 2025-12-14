<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();

// Get filter parameters
$department = isset($_GET['department']) ? $_GET['department'] : '';
$amenities = isset($_GET['amenities']) ? $_GET['amenities'] : [];
$time = isset($_GET['time']) ? $_GET['time'] : 'current';

// Build query for available rooms
$sql = "SELECT * FROM rooms WHERE is_available = 1";
$params = [];
$types = "";

if ($department) {
    $sql .= " AND department = ?";
    $params[] = $department;
    $types .= "s";
}

// Filter by amenities
if (is_array($amenities)) {
    foreach ($amenities as $amenity) {
        switch ($amenity) {
            case 'aircon':
                $sql .= " AND has_aircon = 1";
                break;
            case 'projector':
                $sql .= " AND has_projector = 1";
                break;
            case 'computers':
                $sql .= " AND has_computers = 1";
                break;
            case 'whiteboard':
                $sql .= " AND has_whiteboard = 1";
                break;
        }
    }
}

$sql .= " ORDER BY department, room_code";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$available_rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get room occupancy status (approved reservations take priority over schedules)
$current_date = date('Y-m-d');
$current_time = date('H:i:s');
$resOccStmt = $conn->prepare("
    SELECT r.*, u.first_name, u.last_name
      FROM reservations r
      JOIN users u ON r.faculty_id = u.id
     WHERE r.room_id = ?
       AND r.status = 'approved'
       AND DATE(r.reservation_date) = ?
       AND r.start_time <= ?
       AND r.end_time > ?
     LIMIT 1
");

foreach ($available_rooms as &$room) {
    $room['is_currently_occupied'] = false;
    $room['occupied_by'] = '';
    $room['occupied_class_code'] = '';
    $room['occupied_subject_code'] = '';
    $room['occupied_subject_name'] = '';
    $room['occupied_until'] = '';
    $room['occupied_type'] = '';

    // 1) Check active approved reservation right now
    $room_id = (int)$room['id'];
    $resOccStmt->bind_param('isss', $room_id, $current_date, $current_time, $current_time);
    $resOccStmt->execute();
    $reservation = $resOccStmt->get_result()->fetch_assoc();

    if ($reservation) {
        $room['is_currently_occupied'] = true;
        $room['occupied_type'] = 'reservation';
        $room['occupied_by'] = trim(($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? ''));
        $room['occupied_class_code'] = $reservation['class_code'] ?? '';
        $room['occupied_subject_code'] = $reservation['subject_code'] ?? '';
        $room['occupied_subject_name'] = $reservation['subject_name'] ?? '';
        $room['occupied_until'] = $reservation['end_time'] ?? '';
        continue;
    }

    // 2) Fallback: active schedule right now
    $room['current_schedule'] = getCurrentScheduleStatus($conn, $room_id);
    if ($room['current_schedule']) {
        $room['is_currently_occupied'] = true;
        $room['occupied_type'] = 'schedule';
        $room['occupied_by'] = trim(($room['current_schedule']['first_name'] ?? '') . ' ' . ($room['current_schedule']['last_name'] ?? ''));
        $room['occupied_class_code'] = $room['current_schedule']['class_code'] ?? '';
        $room['occupied_subject_code'] = $room['current_schedule']['subject_code'] ?? '';
        $room['occupied_subject_name'] = $room['current_schedule']['subject_name'] ?? '';
        $room['occupied_until'] = $room['current_schedule']['end_time'] ?? '';
    }
}
$resOccStmt->close();

$stmt->close();
closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - VACANSEE</title>
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
                    <div class="header-brand">
                        <img src="../assets/images/UM-Tagum-College-1950-removebg-preview.png" alt="UM Logo">
                        <div class="brand-text">
                            <h1>Vacansee</h1>
                            <p>Student Portal</p>
                        </div>
                    </div>
                    <p>Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?> — view available rooms</p>
                </div>
                
                <div class="user-menu">
                    <?php include 'user_menu.php'; ?>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="container">
                <!-- Real-time Update Indicator -->
                <div class="real-time-update">
                    <i class="fas fa-sync-alt fa-spin"></i>
                    <span>Room availability updates in real-time. Last updated: <span id="lastUpdate">Just now</span></span>
                </div>
                
                <!-- Filters Section -->
                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filter Rooms</h3>
                        <div class="rooms-count"><?php echo count($available_rooms); ?> rooms available</div>
                    </div>
                    
                    <form method="GET" action="" id="filterForm">
                        <!-- Department Filter -->
                        <div class="filter-group">
                            <label class="filter-label">Department</label>
                            <div class="filter-options">
                                <button type="button" class="filter-btn <?php echo !$department ? 'active' : ''; ?>" 
                                        onclick="setDepartment('')">All Departments</button>
                                <button type="button" class="filter-btn <?php echo $department == 'Engineering' ? 'active' : ''; ?>" 
                                        onclick="setDepartment('Engineering')">Engineering</button>
                                <button type="button" class="filter-btn <?php echo $department == 'DCE' ? 'active' : ''; ?>" 
                                        onclick="setDepartment('DCE')">DCE</button>
                            </div>
                            <input type="hidden" name="department" id="departmentInput" value="<?php echo htmlspecialchars($department); ?>">
                        </div>
                        
                        <!-- Amenities Filter -->
                        <div class="filter-group">
                            <label class="filter-label">Amenities</label>
                            <div class="amenities-grid">
                                <label class="amenity-option <?php echo in_array('aircon', $amenities) ? 'selected' : ''; ?>">
                                    <input type="checkbox" name="amenities[]" value="aircon" 
                                           <?php echo in_array('aircon', $amenities) ? 'checked' : ''; ?> 
                                           onchange="this.parentElement.classList.toggle('selected')">
                                    <i class="fas fa-snowflake amenity-icon text-info"></i>
                                    Air Conditioning
                                </label>
                                <label class="amenity-option <?php echo in_array('projector', $amenities) ? 'selected' : ''; ?>">
                                    <input type="checkbox" name="amenities[]" value="projector" 
                                           <?php echo in_array('projector', $amenities) ? 'checked' : ''; ?> 
                                           onchange="this.parentElement.classList.toggle('selected')">
                                    <i class="fas fa-video amenity-icon text-primary"></i>
                                    Projector
                                </label>
                                <label class="amenity-option <?php echo in_array('computers', $amenities) ? 'selected' : ''; ?>">
                                    <input type="checkbox" name="amenities[]" value="computers" 
                                           <?php echo in_array('computers', $amenities) ? 'checked' : ''; ?> 
                                           onchange="this.parentElement.classList.toggle('selected')">
                                    <i class="fas fa-desktop amenity-icon text-success"></i>
                                    Computers
                                </label>
                                <label class="amenity-option <?php echo in_array('whiteboard', $amenities) ? 'selected' : ''; ?>">
                                    <input type="checkbox" name="amenities[]" value="whiteboard" 
                                           <?php echo in_array('whiteboard', $amenities) ? 'checked' : ''; ?> 
                                           onchange="this.parentElement.classList.toggle('selected')">
                                    <i class="fas fa-chalkboard amenity-icon text-secondary"></i>
                                    Whiteboard
                                </label>
                            </div>
                        </div>
                        
                        <div class="apply-filters">
                            <button type="reset" class="btn-reset" onclick="resetFilters()">
                                <i class="fas fa-redo"></i> Reset Filters
                            </button>
                            <button type="submit" class="btn-apply">
                                <i class="fas fa-check"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Available Rooms -->
                <div class="rooms-section">
                    <div class="rooms-header">
                        <h3><i class="fas fa-door-open"></i> Available Rooms</h3>
                        <div class="rooms-count" id="roomCount"><?php echo count($available_rooms); ?> rooms</div>
                    </div>
                    
                    <?php if (count($available_rooms) > 0): ?>
                        <div class="rooms-grid" id="roomsGrid">
                            <?php foreach ($available_rooms as $room): ?>
                                <div class="room-card">
                                    <div class="room-status <?php echo $room['is_currently_occupied'] ? 'status-occupied' : 'status-available'; ?>">
                                        <i class="fas <?php echo $room['is_currently_occupied'] ? 'fa-times-circle' : 'fa-check-circle'; ?>"></i>
                                        <?php echo $room['is_currently_occupied'] ? 'Currently Occupied' : 'Available Now'; ?>
                                    </div>
                                    
                                    <div class="room-content">
                                        <div class="room-header">
                                            <div>
                                                <div class="room-code"><?php echo htmlspecialchars($room['room_code']); ?></div>
                                                <div class="room-name"><?php echo htmlspecialchars($room['room_name']); ?></div>
                                            </div>
                                            <span class="room-department"><?php echo htmlspecialchars($room['department']); ?></span>
                                        </div>
                                        
                                        <div class="room-amenities">
                                            <?php 
                                            echo getAmenityIcon('aircon', $room['has_aircon']);
                                            echo getAmenityIcon('projector', $room['has_projector']);
                                            echo getAmenityIcon('computers', $room['has_computers']);
                                            echo getAmenityIcon('whiteboard', $room['has_whiteboard']);
                                            ?>
                                        </div>
                                        
                                        <div class="room-details">
                                            <div class="detail-item">
                                                <i class="fas fa-users"></i>
                                                <span>Capacity: <?php echo $room['capacity']; ?> seats</span>
                                            </div>
                                            <div class="detail-item">
                                                <i class="fas fa-building"></i>
                                                <span>Department: <?php echo htmlspecialchars($room['department']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <i class="fas fa-info-circle"></i>
                                                <span>Status: <?php echo $room['is_currently_occupied'] ? 'Occupied' : getRoomStatusText($room['status']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if ($room['is_currently_occupied']): ?>
                                            <div class="occupancy-info">
                                                <strong><i class="fas fa-chalkboard-teacher"></i> Currently in use:</strong><br>
                                                Professor: <?php echo htmlspecialchars($room['occupied_by']); ?><br>
                                                <?php if (!empty($room['occupied_class_code'])): ?>
                                                    Class Code: <?php echo htmlspecialchars($room['occupied_class_code']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($room['occupied_subject_code'])): ?>
                                                    Subject Code: <?php echo htmlspecialchars($room['occupied_subject_code']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($room['occupied_subject_name'])): ?>
                                                    Subject: <?php echo htmlspecialchars($room['occupied_subject_name']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($room['occupied_until'])): ?>
                                                    Until: <?php echo date('g:i A', strtotime($room['occupied_until'])); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="detail-item" style="color: #2ecc71; font-weight: 600;">
                                                <i class="fas fa-check-circle"></i>
                                                <span>This room is available for use</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-rooms">
                            <i class="fas fa-door-closed"></i>
                            <h3>No Rooms Available</h3>
                            <p>No rooms match your current filters. Try adjusting your filter criteria.</p>
                            <button class="btn-apply" onclick="resetFilters()" style="margin-top: 1rem;">
                                <i class="fas fa-redo"></i> Reset Filters
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Update last update time
        function updateLastUpdateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            document.getElementById('lastUpdate').textContent = timeString;
        }
        
        // Set initial time
        updateLastUpdateTime();
        
        // Update time every minute
        setInterval(updateLastUpdateTime, 60000);
        
        // Department filter functions
        function setDepartment(dept) {
            document.getElementById('departmentInput').value = dept;
            
            // Update button states
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
        }
        
        // Reset filters
        function resetFilters() {
            document.getElementById('filterForm').reset();
            document.querySelectorAll('.amenity-option').forEach(option => {
                option.classList.remove('selected');
            });
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector('.filter-btn').classList.add('active');
            document.getElementById('departmentInput').value = '';
            
            // Submit form
            document.getElementById('filterForm').submit();
        }
        
        // Auto-refresh rooms every 60 seconds
        function refreshRooms() {
            fetch('../includes/check_availability.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.timestamp > lastRefresh) {
                        // Update room count
                        document.getElementById('roomCount').textContent = data.count + ' rooms';
                        lastRefresh = data.timestamp;
                    }
                })
                .catch(error => console.error('Error refreshing rooms:', error));
        }
        
        let lastRefresh = Date.now();
        setInterval(refreshRooms, 60000);
        
        // Initialize filter buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Set active state for department filter
            const deptButtons = document.querySelectorAll('.filter-btn');
            deptButtons.forEach(btn => {
                if (btn.textContent === 'All Departments' && !document.getElementById('departmentInput').value) {
                    btn.classList.add('active');
                } else if (btn.textContent === document.getElementById('departmentInput').value) {
                    btn.classList.add('active');
                }
            });
            
            // Set selected state for amenities
            const checkboxes = document.querySelectorAll('input[name="amenities[]"]');
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    checkbox.parentElement.classList.add('selected');
                }
            });
        });
    </script>
</body>
</html>
