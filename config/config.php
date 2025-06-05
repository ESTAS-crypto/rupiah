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

// Fungsi untuk mendapatkan nomor WhatsApp admin dengan fallback
function getAdminWhatsApp($conn) {
    // Tetapkan nomor admin yang benar (dapat dikomfirmasi di database)
    $admin_whatsapp_correct = "62895385890629";
    
    $query = "SELECT no_whatsapp FROM users WHERE role = 'admin' LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $admin = mysqli_fetch_assoc($result);
        if (isset($admin['no_whatsapp']) && !empty($admin['no_whatsapp'])) {
            // Jika nomor di database, gunakan nomor tersebut
            return $admin['no_whatsapp'];
        }
    }
    
    // Fallback: Jika tidak ada admin atau tidak ada nomor WhatsApp admin
    // Gunakan nomor yang sudah dikonfirmasi benar (nomor admin yang benar)
    
    // Cek apakah ada admin
    $check_query = "SELECT COUNT(*) as count FROM users WHERE role = 'admin'";
    $check_result = mysqli_query($conn, $check_query);
    $admin_count = mysqli_fetch_assoc($check_result)['count'];
    
    if ($admin_count == 0) {
        // Jika tidak ada admin, tambahkan admin baru dengan nomor yang benar
        $admin_username = "admin";
        $admin_password = password_hash("admin123", PASSWORD_DEFAULT);
        $admin_email = "admin@example.com";
        
        $insert_query = "INSERT INTO users (username, password, email, role, no_whatsapp) 
                         VALUES ('$admin_username', '$admin_password', '$admin_email', 'admin', '$admin_whatsapp_correct')";
        mysqli_query($conn, $insert_query);
    } else {
        // Update nomor WhatsApp admin yang sudah ada
        $update_query = "UPDATE users SET no_whatsapp = '$admin_whatsapp_correct' WHERE role = 'admin' AND (no_whatsapp IS NULL OR no_whatsapp = '')";
        mysqli_query($conn, $update_query);
    }
    
    return $admin_whatsapp_correct;
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
        header("Location: /rupiah/dashboard/dashboard.php");
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

// Fungsi untuk memperbarui peran pengguna
function updateUserRole($conn) {
    header('Content-Type: application/json');
    startSession();

    $response = ['success' => false];

    // Periksa apakah pengguna sudah login
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'Anda belum login. Silakan login terlebih dahulu.';
        echo json_encode($response);
        exit();
    }

    // Periksa metode permintaan
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response['message'] = 'Metode permintaan harus POST.';
        echo json_encode($response);
        exit();
    }

    // Decode input JSON
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null || !isset($input['code'])) {
        error_log('Gagal mendekode JSON atau kode tidak ada: ' . file_get_contents('php://input'));
        $response['message'] = 'Input JSON tidak valid atau kode tidak ada.';
        echo json_encode($response);
        exit();
    }

    // Periksa kode rahasia
    if ($input['code'] !== '230525') {
        error_log('Kode rahasia salah: ' . $input['code']);
        $response['message'] = 'Kode rahasia salah.';
        echo json_encode($response);
        exit();
    }

    // Update peran pengguna
    $user_id = $_SESSION['user_id'];
    $new_role = 'secret';

    try {
        $update_query = "UPDATE users SET role = ? WHERE user_id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        if ($stmt === false) {
            throw new Exception('Gagal menyiapkan query: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "si", $new_role, $user_id);
        $success = mysqli_stmt_execute($stmt);

        if ($success) {
            $_SESSION['role'] = $new_role;
            $response['success'] = true;
            $response['message'] = 'Anda telah menemukan role terssembunyi yaitu role: ' . $new_role;
        } else {
            throw new Exception('Gagal memperbarui peran: ' . mysqli_error($conn));
        }
        mysqli_stmt_close($stmt);
    } catch (Exception $e) {
        error_log('Error memperbarui peran: ' . $e->getMessage());
        $response['message'] = 'Terjadi kesalahan saat memperbarui peran: ' . $e->getMessage();
    }

    echo json_encode($response);
}

// Aktifkan error reporting untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>