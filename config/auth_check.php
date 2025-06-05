<?php
// File: config/auth_check.php
// Fungsi untuk verifikasi autentikasi

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function checkAuthentication() {
    global $conn;
    
    // Jika tidak ada ID pengguna dalam sesi, berarti tidak terotentikasi
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }
    
    // Verifikasi bahwa pengguna benar-benar ada di database
    $user_id = $_SESSION['user_id'];
    $query = "SELECT * FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);
            
            // Periksa status pengguna (pastikan tidak dalam keadaan banned)
            if ($user['ban_status'] === 'banned') {
                // Jika pengguna dalam status banned, logout
                session_unset();
                session_destroy();
                return false;
            }
            
            return true;
        } else {
            // User ID tidak ditemukan di database
            session_unset();
            session_destroy();
            return false;
        }
    }
    
    return false;
}

// Jika file ini dipanggil dari API endpoint, periksa otentikasi dan berikan respons JSON
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    if (!checkAuthentication()) {
        echo json_encode(['success' => false, 'message' => 'Autentikasi gagal']);
        exit();
    }
}
?>