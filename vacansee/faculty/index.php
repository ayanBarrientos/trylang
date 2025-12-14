<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if ($_SESSION['user_type'] !== 'faculty') {
    if ($_SESSION['user_type'] === 'admin') {
        header('Location: ../admin/dashboard.php');
    } elseif ($_SESSION['user_type'] === 'student') {
        header('Location: ../student/dashboard.php');
    } else {
        header('Location: ../logout.php');
    }
    exit();
}

header('Location: dashboard.php');
exit();
