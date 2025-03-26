<?php
// Konfigurasi Database
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'ManajemenK';

$conn = mysqli_connect($host, $username, $password, $database);
if (!$conn) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

// Fungsi Umum untuk Sesi
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn() {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: /rupiah/login.php?msg=login_required");
        exit();
    }
    checkSessionTimeout();
}

function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        header("Location: /rupiah/dashboard.php");
        exit();
    }
}

function checkSessionTimeout() {
    startSession();
    $timeout = 1800; // 30 menit
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        session_unset();
        session_destroy();
        header("Location: /rupiah/login.php?msg=timeout");
        exit();
    }
    $_SESSION['last_activity'] = time();
}

// Fungsi untuk memeriksa apakah pengguna bisa mengakses fitur tertentu
function canAccessFeature($warning_count) {
    return $warning_count < 2; // Hanya pengguna dengan 0-1 peringatan yang bisa mengakses fitur penuh
}

// Fungsi untuk memeriksa dan membersihkan sanksi kadaluarsa
function checkAndClearExpiredSanctions($conn, $user_id) {
    $query = "SELECT warning_count, ban_status, ban_expiry, warning_expiry FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    $current_time = time();
    $changes_made = false;

    // Cek ban kadaluarsa
    if ($user['ban_status'] === 'banned' && $user['ban_expiry'] !== null && strtotime($user['ban_expiry']) <= $current_time) {
        $update_query = "UPDATE users SET ban_status = 'active', ban_expiry = NULL WHERE user_id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "i", $user_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        $changes_made = true;
    }

    // Cek peringatan kadaluarsa
    if ($user['warning_count'] > 0 && $user['warning_expiry'] !== null && strtotime($user['warning_expiry']) <= $current_time) {
        $update_query = "UPDATE users SET warning_count = 0, warning_expiry = NULL WHERE user_id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "i", $user_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        $changes_made = true;
    }

    return $changes_made;
}

// Fungsi untuk hitungan mundur waktu kadaluarsa
function getTimeRemaining($expiry) {
    if (!$expiry) return "Tidak ada batas waktu";
    $now = time();
    $expiry_time = strtotime($expiry);
    if ($expiry_time <= $now) return "Kadaluarsa";

    $diff = $expiry_time - $now;
    $days = floor($diff / (24 * 60 * 60));
    $hours = floor(($diff % (24 * 60 * 60)) / (60 * 60));
    $minutes = floor(($diff % (60 * 60)) / 60);
    $seconds = $diff % 60;
    return sprintf("%d hari, %d jam, %d menit, %d detik", $days, $hours, $minutes, $seconds);
}

// Aktifkan error reporting untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>