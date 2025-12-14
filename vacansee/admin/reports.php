<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();

// Get report parameters
$report_type = isset($_GET['type']) ? $_GET['type'] : 'room_utilization';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$department = isset($_GET['department']) ? $_GET['department'] : '';

// Initialize report data
$report_data = [];
$chart_data = [];
$total_count = 0;

switch ($report_type) {
    case 'room_utilization':
        // Room utilization report
        $sql = "
            SELECT 
                r.room_code,
                r.room_name,
                r.department,
                r.capacity,
                COUNT(DISTINCT CASE WHEN s.is_active = 1 THEN s.id END) as total_schedules,
                COUNT(DISTINCT res.id) as total_reservations,
                AVG(CASE WHEN res.status = 'approved' THEN 1 ELSE 0 END) * 100 as utilization_rate
            FROM rooms r
            LEFT JOIN schedules s ON r.id = s.room_id
            LEFT JOIN reservations res ON r.id = res.room_id 
                AND res.reservation_date BETWEEN ? AND ?
                AND res.status = 'approved'
            WHERE 1=1
        ";
        
        if ($department) {
            $sql .= " AND r.department = ?";
            $params = [$start_date, $end_date, $department];
            $types = "sss";
        } else {
            $params = [$start_date, $end_date];
            $types = "ss";
        }
        
        $sql .= " GROUP BY r.id ORDER BY utilization_rate DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $report_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Prepare chart data
        foreach ($report_data as $room) {
            $chart_data[] = [
                'room' => $room['room_code'],
                'utilization' => (float)$room['utilization_rate']
            ];
        }
        break;
        
    case 'reservation_analysis':
        // Reservation analysis report
        $sql = "
            SELECT 
                DATE(res.reservation_date) as date,
                DAYNAME(res.reservation_date) as day,
                COUNT(*) as total_reservations,
                SUM(CASE WHEN res.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN res.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN res.status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM reservations res
            WHERE res.reservation_date BETWEEN ? AND ?
        ";
        
        if ($department) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM rooms r 
                WHERE r.id = res.room_id 
                AND r.department = ?
            )";
            $params = [$start_date, $end_date, $department];
            $types = "sss";
        } else {
            $params = [$start_date, $end_date];
            $types = "ss";
        }
        
        $sql .= " GROUP BY DATE(res.reservation_date) ORDER BY date";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $report_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Prepare chart data
        foreach ($report_data as $day) {
            $chart_data[] = [
                'date' => $day['date'],
                'approved' => (int)$day['approved'],
                'rejected' => (int)$day['rejected'],
                'pending' => (int)$day['pending']
            ];
        }
        break;
        
    case 'faculty_activity':
        // Faculty activity report
        $sql = "
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                u.department,
                u.email,
                COUNT(res.id) as total_reservations,
                SUM(CASE WHEN res.status = 'approved' THEN 1 ELSE 0 END) as approved_reservations,
                SUM(CASE WHEN res.status = 'rejected' THEN 1 ELSE 0 END) as rejected_reservations,
                MAX(res.reservation_date) as last_reservation
            FROM users u
            LEFT JOIN reservations res ON u.id = res.faculty_id
                AND res.reservation_date BETWEEN ? AND ?
            WHERE u.user_type = 'faculty'
        ";
        
        if ($department) {
            $sql .= " AND u.department = ?";
            $params = [$start_date, $end_date, $department];
            $types = "sss";
        } else {
            $params = [$start_date, $end_date];
            $types = "ss";
        }
        
        $sql .= " GROUP BY u.id ORDER BY total_reservations DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $report_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        break;
        
    case 'system_usage':
        // System usage statistics
        $sql = "
            SELECT 
                'Total Rooms' as metric,
                COUNT(*) as value
            FROM rooms
            UNION ALL
            SELECT 
                'Active Users',
                COUNT(*)
            FROM users
            WHERE is_active = 1
            UNION ALL
            SELECT 
                'Total Reservations',
                COUNT(*)
            FROM reservations
            WHERE reservation_date BETWEEN ? AND ?
            UNION ALL
            SELECT 
                'Approved Reservations',
                COUNT(*)
            FROM reservations
            WHERE reservation_date BETWEEN ? AND ?
            AND status = 'approved'
            UNION ALL
            SELECT 
                'Pending Reservations',
                COUNT(*)
            FROM reservations
            WHERE status = 'pending'
            UNION ALL
            SELECT 
                'Active Faculty',
                COUNT(*)
            FROM users
            WHERE user_type = 'faculty'
            AND is_active = 1
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
        $stmt->execute();
        $report_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        break;
}

// Export helpers
function buildExportRows($report_type, $report_data) {
    $headers = [];
    $rows = [];
    
    switch ($report_type) {
        case 'room_utilization':
            $headers = ['Room Code', 'Room Name', 'Department', 'Capacity', 'Utilization Rate (%)', 'Total Reservations'];
            foreach ($report_data as $row) {
                $rows[] = [
                    $row['room_code'],
                    $row['room_name'],
                    $row['department'],
                    $row['capacity'],
                    round($row['utilization_rate'], 2),
                    $row['total_reservations']
                ];
            }
            break;
        case 'reservation_analysis':
            $headers = ['Date', 'Day', 'Total Reservations', 'Approved', 'Rejected', 'Pending'];
            foreach ($report_data as $row) {
                $rows[] = [
                    $row['date'],
                    $row['day'],
                    $row['total_reservations'],
                    $row['approved'],
                    $row['rejected'],
                    $row['pending']
                ];
            }
            break;
        case 'faculty_activity':
            $headers = ['Faculty Name', 'Department', 'Email', 'Total Reservations', 'Approved Reservations', 'Last Reservation'];
            foreach ($report_data as $row) {
                $rows[] = [
                    $row['first_name'] . ' ' . $row['last_name'],
                    $row['department'],
                    $row['email'],
                    $row['total_reservations'],
                    $row['approved_reservations'],
                    $row['last_reservation'] ? date('Y-m-d', strtotime($row['last_reservation'])) : 'No activity'
                ];
            }
            break;
        case 'system_usage':
            $headers = ['Metric', 'Value'];
            foreach ($report_data as $row) {
                $rows[] = [$row['metric'], $row['value']];
            }
            break;
    }
    
    return [$headers, $rows];
}

function exportCsv($filename, $headers, $rows) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// Handle exports before rendering HTML
if (isset($_GET['export'])) {
    [$export_headers, $export_rows] = buildExportRows($report_type, $report_data);
    if ($_GET['export'] === 'excel') {
        exportCsv("{$report_type}_report_" . date('Ymd') . ".csv", $export_headers, $export_rows);
    }
}

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - VACANSEE Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <h1>Reports & Analytics</h1>
                        <p>Generate and view system reports</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container reports-container" id="reportContent">
                <!-- Report Header -->
                <div class="report-header">
                    <h2><i class="fas fa-chart-bar"></i> Generate Reports</h2>
                    
                    <!-- Report Type Selector -->
                    <div class="report-type-selector">
                        <button class="report-type-btn <?php echo $report_type == 'room_utilization' ? 'active' : ''; ?>" 
                                onclick="changeReportType('room_utilization')">
                            <i class="fas fa-door-open"></i> Room Utilization
                        </button>
                        <button class="report-type-btn <?php echo $report_type == 'reservation_analysis' ? 'active' : ''; ?>" 
                                onclick="changeReportType('reservation_analysis')">
                            <i class="fas fa-calendar-check"></i> Reservation Analysis
                        </button>
                        <button class="report-type-btn <?php echo $report_type == 'faculty_activity' ? 'active' : ''; ?>" 
                                onclick="changeReportType('faculty_activity')">
                            <i class="fas fa-chalkboard-teacher"></i> Faculty Activity
                        </button>
                        <button class="report-type-btn <?php echo $report_type == 'system_usage' ? 'active' : ''; ?>" 
                                onclick="changeReportType('system_usage')">
                            <i class="fas fa-chart-line"></i> System Usage
                        </button>
                    </div>
                    
                    <!-- Report Controls -->
                    <form method="GET" action="" class="report-controls">
                        <input type="hidden" name="type" id="report_type" value="<?php echo $report_type; ?>">
                        
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($start_date); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($end_date); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Department (Optional)</label>
                            <select name="department" class="form-control">
                                <option value="">All Departments</option>
                                <option value="Engineering" <?php echo $department == 'Engineering' ? 'selected' : ''; ?>>Engineering</option>
                                <option value="DCE" <?php echo $department == 'DCE' ? 'selected' : ''; ?>>DCE</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn-login" style="width: 100%;">
                                <i class="fas fa-filter"></i> Generate Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Report Content -->
                <div class="report-content">
                    <!-- Statistics Card -->
                    <div class="report-card">
                        <h3><i class="fas fa-chart-pie"></i> Summary Statistics</h3>
                        
                        <?php if ($report_type == 'room_utilization'): ?>
                            <div class="stats-grid">
                                <div class="stat-box">
                                    <h4><?php echo count($report_data); ?></h4>
                                    <p>Total Rooms</p>
                                </div>
                                <div class="stat-box">
                                    <h4>
                                        <?php 
                                        $avg_utilization = array_reduce($report_data, function($carry, $item) {
                                            return $carry + (float)$item['utilization_rate'];
                                        }, 0) / max(count($report_data), 1);
                                        echo round($avg_utilization, 1) . '%';
                                        ?>
                                    </h4>
                                    <p>Avg Utilization</p>
                                </div>
                                <div class="stat-box">
                                    <h4>
                                        <?php 
                                        $total_reservations = array_reduce($report_data, function($carry, $item) {
                                            return $carry + (int)$item['total_reservations'];
                                        }, 0);
                                        echo $total_reservations;
                                        ?>
                                    </h4>
                                    <p>Total Reservations</p>
                                </div>
                            </div>
                            
                            <div class="chart-container">
                                <canvas id="utilizationChart"></canvas>
                            </div>
                            
                        <?php elseif ($report_type == 'reservation_analysis'): ?>
                            <div class="stats-grid">
                                <div class="stat-box">
                                    <h4>
                                        <?php 
                                        $total = array_reduce($report_data, function($carry, $item) {
                                            return $carry + (int)$item['total_reservations'];
                                        }, 0);
                                        echo $total;
                                        ?>
                                    </h4>
                                    <p>Total Reservations</p>
                                </div>
                                <div class="stat-box">
                                    <h4>
                                        <?php 
                                        $approved = array_reduce($report_data, function($carry, $item) {
                                            return $carry + (int)$item['approved'];
                                        }, 0);
                                        echo $approved;
                                        ?>
                                    </h4>
                                    <p>Approved</p>
                                </div>
                                <div class="stat-box">
                                    <h4>
                                        <?php 
                                        $rejected = array_reduce($report_data, function($carry, $item) {
                                            return $carry + (int)$item['rejected'];
                                        }, 0);
                                        echo $rejected;
                                        ?>
                                    </h4>
                                    <p>Rejected</p>
                                </div>
                            </div>
                            
                            <div class="chart-container">
                                <canvas id="reservationChart"></canvas>
                            </div>
                            
                        <?php elseif ($report_type == 'faculty_activity'): ?>
                            <div class="stats-grid">
                                <div class="stat-box">
                                    <h4><?php echo count($report_data); ?></h4>
                                    <p>Total Faculty</p>
                                </div>
                                <div class="stat-box">
                                    <h4>
                                        <?php 
                                        $total_res = array_reduce($report_data, function($carry, $item) {
                                            return $carry + (int)$item['total_reservations'];
                                        }, 0);
                                        echo $total_res;
                                        ?>
                                    </h4>
                                    <p>Total Reservations</p>
                                </div>
                                <div class="stat-box">
                                    <h4>
                                        <?php 
                                        $avg_per_faculty = count($report_data) > 0 ? 
                                            round($total_res / count($report_data), 1) : 0;
                                        echo $avg_per_faculty;
                                        ?>
                                    </h4>
                                    <p>Avg per Faculty</p>
                                </div>
                            </div>
                            
                        <?php elseif ($report_type == 'system_usage'): ?>
                            <div class="stats-grid">
                                <?php foreach ($report_data as $stat): ?>
                                    <div class="stat-box">
                                        <h4><?php echo $stat['value']; ?></h4>
                                        <p><?php echo $stat['metric']; ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Detailed Report Card -->
                    <div class="report-card">
                        <h3><i class="fas fa-table"></i> Detailed Report</h3>
                        
                        <?php if (count($report_data) > 0): ?>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <?php if ($report_type == 'room_utilization'): ?>
                                            <tr>
                                                <th>Room Code</th>
                                                <th>Room Name</th>
                                                <th>Department</th>
                                                <th>Capacity</th>
                                                <th>Utilization Rate</th>
                                                <th>Reservations</th>
                                            </tr>
                                        <?php elseif ($report_type == 'reservation_analysis'): ?>
                                            <tr>
                                                <th>Date</th>
                                                <th>Day</th>
                                                <th>Total</th>
                                                <th>Approved</th>
                                                <th>Rejected</th>
                                                <th>Pending</th>
                                            </tr>
                                        <?php elseif ($report_type == 'faculty_activity'): ?>
                                            <tr>
                                                <th>Faculty Name</th>
                                                <th>Department</th>
                                                <th>Email</th>
                                                <th>Total Reservations</th>
                                                <th>Approved</th>
                                                <th>Last Activity</th>
                                            </tr>
                                        <?php elseif ($report_type == 'system_usage'): ?>
                                            <tr>
                                                <th>Metric</th>
                                                <th>Value</th>
                                            </tr>
                                        <?php endif; ?>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <?php if ($report_type == 'room_utilization'): ?>
                                                    <td><strong><?php echo htmlspecialchars($row['room_code']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['room_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                                    <td><?php echo $row['capacity']; ?> seats</td>
                                                    <td>
                                                        <?php echo round($row['utilization_rate'], 1); ?>%
                                                        <div class="utilization-bar">
                                                            <div class="utilization-fill" style="width: <?php echo min($row['utilization_rate'], 100); ?>%"></div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo $row['total_reservations']; ?></td>
                                                    
                                                <?php elseif ($report_type == 'reservation_analysis'): ?>
                                                    <td><?php echo date('M j, Y', strtotime($row['date'])); ?></td>
                                                    <td><?php echo $row['day']; ?></td>
                                                    <td><strong><?php echo $row['total_reservations']; ?></strong></td>
                                                    <td><span style="color: #28a745;"><?php echo $row['approved']; ?></span></td>
                                                    <td><span style="color: #dc3545;"><?php echo $row['rejected']; ?></span></td>
                                                    <td><span style="color: #ffc107;"><?php echo $row['pending']; ?></span></td>
                                                    
                                                <?php elseif ($report_type == 'faculty_activity'): ?>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></strong>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                                    <td><?php echo $row['total_reservations']; ?></td>
                                                    <td><?php echo $row['approved_reservations']; ?></td>
                                                    <td>
                                                        <?php echo $row['last_reservation'] ? 
                                                            date('M j, Y', strtotime($row['last_reservation'])) : 'No activity'; ?>
                                                    </td>
                                                    
                                                <?php elseif ($report_type == 'system_usage'): ?>
                                                    <td><?php echo $row['metric']; ?></td>
                                                    <td><strong><?php echo $row['value']; ?></strong></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-database"></i>
                                <h3>No Data Available</h3>
                                <p>No data found for the selected criteria.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Export Options -->
                <div class="export-options">
                    <button class="btn-export pdf" onclick="exportReport('pdf')">
                        <i class="fas fa-file-pdf"></i> Export as PDF
                    </button>
                    <button class="btn-export excel" onclick="exportReport('excel')">
                        <i class="fas fa-file-excel"></i> Export as Excel
                    </button>
                    <button class="btn-export" onclick="printReport()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
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
        
        function changeReportType(type) {
            document.getElementById('report_type').value = type;
            document.querySelectorAll('.report-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            document.querySelector('form').submit();
        }
        
        function exportReport(format) {
            const params = new URLSearchParams({
                type: document.getElementById('report_type').value,
                start_date: document.querySelector('[name="start_date"]').value,
                end_date: document.querySelector('[name="end_date"]').value,
                department: document.querySelector('[name="department"]').value
            });

            if (format === 'excel') {
                window.location.href = `reports.php?${params.toString()}&export=excel`;
                return;
            }

            if (format === 'pdf') {
                openPrintWindow();
            }
        }
        
        function printReport() {
            window.print();
        }

        function openPrintWindow() {
            const content = document.getElementById('reportContent');
            if (!content) {
                window.print();
                return;
            }

            const printable = content.cloneNode(true);
            const win = window.open('', '_blank');
            const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
                .map(link => link.outerHTML)
                .join('');

            win.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Report - VACANSEE</title>
                    ${styles}
                    <style>
                        body { padding: 20px; }
                        .export-options { display: none; }
                        .report-header { margin-bottom: 1.5rem; }
                    </style>
                </head>
                <body class="dashboard-page">
                    ${printable.outerHTML}
                </body>
                </html>
            `);

            win.document.close();
            win.focus();
            win.print();
        }
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($report_type == 'room_utilization' && !empty($chart_data)): ?>
                // Room Utilization Chart
                const ctx1 = document.getElementById('utilizationChart');
                if (ctx1) {
                    new Chart(ctx1, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode(array_column($chart_data, 'room')); ?>,
                            datasets: [{
                                label: 'Utilization Rate (%)',
                                data: <?php echo json_encode(array_column($chart_data, 'utilization')); ?>,
                                backgroundColor: 'rgba(46, 204, 113, 0.7)',
                                borderColor: 'rgba(46, 204, 113, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100,
                                    title: {
                                        display: true,
                                        text: 'Utilization Rate (%)'
                                    }
                                }
                            }
                        }
                    });
                }
                
            <?php elseif ($report_type == 'reservation_analysis' && !empty($chart_data)): ?>
                // Reservation Analysis Chart
                const ctx2 = document.getElementById('reservationChart');
                if (ctx2) {
                    new Chart(ctx2, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode(array_column($chart_data, 'date')); ?>,
                            datasets: [
                                {
                                    label: 'Approved',
                                    data: <?php echo json_encode(array_column($chart_data, 'approved')); ?>,
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                    fill: true
                                },
                                {
                                    label: 'Rejected',
                                    data: <?php echo json_encode(array_column($chart_data, 'rejected')); ?>,
                                    borderColor: 'rgba(220, 53, 69, 1)',
                                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                    fill: true
                                },
                                {
                                    label: 'Pending',
                                    data: <?php echo json_encode(array_column($chart_data, 'pending')); ?>,
                                    borderColor: 'rgba(255, 193, 7, 1)',
                                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Number of Reservations'
                                    }
                                }
                            }
                        }
                    });
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>
