<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if ($_SESSION['user_type'] !== 'admin') {
    if ($_SESSION['user_type'] === 'faculty') {
        header('Location: ../faculty/dashboard.php');
    } elseif ($_SESSION['user_type'] === 'student') {
        header('Location: ../student/dashboard.php');
    } else {
        header('Location: ../logout.php');
    }
    exit();
}

header('Location: dashboard.php');
exit();
