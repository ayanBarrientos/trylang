<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$conn = getConnection();

// Get filter parameters
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$user_filter = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$search_filter = isset($_GET['search']) ? $_GET['search'] : '';

// Build query for system logs
$sql = "
    SELECT sl.*, u.first_name, u.last_name, u.email, u.user_type 
    FROM system_logs sl 
    LEFT JOIN users u ON sl.user_id = u.id 
    WHERE 1=1
";

$params = [];
$types = "";

if ($action_filter) {
    $sql .= " AND sl.action = ?";
    $params[] = $action_filter;
    $types .= "s";
}

if ($user_filter > 0) {
    $sql .= " AND sl.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

if ($date_filter) {
    $sql .= " AND DATE(sl.created_at) = ?";
    $params[] = $date_filter;
    $types .= "s";
}

if ($search_filter) {
    $sql .= " AND (sl.description LIKE ? OR sl.action LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%$search_filter%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= str_repeat("s", 4);
}

$sql .= " ORDER BY sl.created_at DESC";

// Get total count for pagination
$count_sql = str_replace("SELECT sl.*, u.first_name, u.last_name, u.email, u.user_type", 
                        "SELECT COUNT(*) as total", $sql);
$count_stmt = $conn->prepare($count_sql);
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_result = $count_stmt->get_result()->fetch_assoc();
$total_logs = $total_result['total'];
$count_stmt->close();

// Pagination
$per_page = 50;
$total_pages = ceil($total_logs / $per_page);
$current_page = isset($_GET['page']) ? max(1, min($total_pages, (int)$_GET['page'])) : 1;
$offset = ($current_page - 1) * $per_page;

$sql .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get distinct actions for filter
$actions_query = "SELECT DISTINCT action FROM system_logs ORDER BY action";
$actions = $conn->query($actions_query)->fetch_all(MYSQLI_ASSOC);

// Get users for filter
$users_query = "SELECT id, first_name, last_name FROM users ORDER BY last_name, first_name";
$users = $conn->query($users_query)->fetch_all(MYSQLI_ASSOC);

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - VACANSEE Admin</title>
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
                        <h1>System Logs</h1>
                        <p>Monitor system activities and user actions</p>
                    </div>
                    
                    <div class="user-menu">
                        <?php include 'user_menu.php'; ?>
                    </div>
                </div>
            </div>

            <div class="container logs-container">
                <!-- Filters -->
                <div class="logs-filters">
                    <h3><i class="fas fa-filter"></i> Filter Logs</h3>
                    <form method="GET" action="" class="filter-grid">
                        <div class="form-group">
                            <label>Action Type</label>
                            <select name="action" class="form-control" onchange="this.form.submit()">
                                <option value="">All Actions</option>
                                <?php foreach ($actions as $action): ?>
                                    <option value="<?php echo htmlspecialchars($action['action']); ?>" 
                                            <?php echo $action_filter == $action['action'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $action['action']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>User</label>
                            <select name="user" class="form-control" onchange="this.form.submit()">
                                <option value="">All Users</option>
                                <option value="0" <?php echo $user_filter === 0 ? 'selected' : ''; ?>>System</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" 
                                            <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['last_name'] . ', ' . $user['first_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" 
                                   value="<?php echo htmlspecialchars($date_filter); ?>" 
                                   onchange="this.form.submit()">
                        </div>
                        
                        <div class="form-group">
                            <label>Search</label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search in logs..." 
                                   value="<?php echo htmlspecialchars($search_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <div class="logs-actions">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button type="button" class="btn-outline" onclick="window.location.href='logs.php'">
                                    <i class="fas fa-redo"></i> Clear
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <div class="logs-actions">
                        <button class="btn-danger" onclick="confirmClearLogs()">
                            <i class="fas fa-trash"></i> Clear logs older than 30 days
                        </button>
                    </div>
                </div>

                <!-- Logs List -->
                <div class="logs-table">
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <div class="log-entry">
                                <div class="log-header">
                                    <div class="log-user">
                                        <?php if ($log['user_id']): ?>
                                            <div class="user-avatar avatar-<?php echo $log['user_type']; ?>">
                                                <?php echo strtoupper(substr($log['first_name'], 0, 1) . substr($log['last_name'], 0, 1)); ?>
                                            </div>
                                            <div class="user-info">
                                                <div class="user-name">
                                                    <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                                                </div>
                                                <div class="user-type">
                                                    <?php echo ucfirst($log['user_type']); ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="user-avatar avatar-system">
                                                <i class="fas fa-server"></i>
                                            </div>
                                            <div class="user-info">
                                                <div class="user-name">System</div>
                                                <div class="user-type">Automated Process</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="log-time">
                                        <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                    </div>
                                </div>
                                
                                <div class="log-action action-<?php echo explode('_', $log['action'])[0]; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                                </div>
                                
                                <?php if ($log['description']): ?>
                                    <div class="log-description">
                                        <?php echo htmlspecialchars($log['description']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="log-meta">
                                    <div>
                                        User Agent: 
                                        <span title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                            <?php 
                                            $ua = $log['user_agent'];
                                            if (strpos($ua, 'Chrome') !== false) echo 'Chrome';
                                            elseif (strpos($ua, 'Firefox') !== false) echo 'Firefox';
                                            elseif (strpos($ua, 'Safari') !== false) echo 'Safari';
                                            elseif (strpos($ua, 'Edge') !== false) echo 'Edge';
                                            else echo 'Browser';
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-logs">
                            <i class="fas fa-history"></i>
                            <h3>No Logs Found</h3>
                            <p>No system logs match your current filters.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <button class="page-btn" <?php echo $current_page == 1 ? 'disabled' : ''; ?>
                                onclick="changePage(<?php echo $current_page - 1; ?>)">
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): ?>
                            <button class="page-btn" onclick="changePage(1)">1</button>
                            <?php if ($start_page > 2): ?>
                                <span>...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <button class="page-btn <?php echo $i == $current_page ? 'active' : ''; ?>" 
                                    onclick="changePage(<?php echo $i; ?>)">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span>...</span>
                            <?php endif; ?>
                            <button class="page-btn" onclick="changePage(<?php echo $total_pages; ?>)">
                                <?php echo $total_pages; ?>
                            </button>
                        <?php endif; ?>
                        
                        <button class="page-btn" <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>
                                onclick="changePage(<?php echo $current_page + 1; ?>)">
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                <?php endif; ?>
                
                <!-- Logs Summary -->
                <div style="margin-top: 1.25rem; text-align: center; color: var(--text-light); font-size: 0.9rem;">
                    Showing <?php echo count($logs); ?> of <?php echo $total_logs; ?> total logs
                    <?php if ($total_logs > 0): ?>
                        | Oldest: <?php echo date('M j, Y', strtotime($logs[count($logs)-1]['created_at'])); ?>
                        | Newest: <?php echo date('M j, Y', strtotime($logs[0]['created_at'])); ?>
                    <?php endif; ?>
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
        
        function changePage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
        
        function confirmClearLogs() {
            if (confirm('Are you sure you want to clear all logs older than 30 days?\n\nThis action cannot be undone.')) {
                fetch('../includes/clear_logs.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Logs cleared successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                    });
            }
        }
        
        // Auto-refresh logs every 60 seconds
        let autoRefresh = true;
        
        function refreshLogs() {
            if (autoRefresh) {
                fetch('../includes/check_new_logs.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.has_new) {
                            // Show notification
                            if (Notification.permission === 'granted') {
                                new Notification('New System Log', {
                                    body: 'New system activity detected',
                                    icon: '/assets/images/logo.png'
                                });
                            }
                            // Refresh page
                            window.location.reload();
                        }
                    })
                    .catch(error => console.error('Error refreshing logs:', error));
            }
        }
        
        // Start auto-refresh
        setInterval(refreshLogs, 60000);
        
        // Request notification permission
        if (Notification.permission === 'default') {
            Notification.requestPermission();
        }
        
        // Stop auto-refresh when page is not visible
        document.addEventListener('visibilitychange', function() {
            autoRefresh = !document.hidden;
        });
    </script>
</body>
</html>



