<?php
// File: config/session_check.php
// Fungsi untuk verifikasi dan manajemen sesi

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fungsi untuk memperbarui waktu aktivitas terakhir
function updateLastActivity() {
    $_SESSION['last_activity'] = time();
}

// Fungsi untuk memeriksa apakah sesi telah timeout
function isSessionTimedOut() {
    $timeout = 1800; // 30 menit dalam detik
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        return true;
    }
    return false;
}

// Jika sesi sudah timeout, hapus sesi
if (isSessionTimedOut()) {
    session_unset();
    session_destroy();
    if (!isset($_GET['msg']) || $_GET['msg'] !== 'timeout') {
        $redirect_url = '/rupiah/login.php?msg=timeout';
        
        // Jika ini adalah permintaan AJAX, kirim respons JSON
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sesi telah berakhir', 'redirect' => $redirect_url]);
            exit();
        }
        
        // Jika bukan AJAX, redirect
        header("Location: $redirect_url");
        exit();
    }
}

// Jika pengguna sedang login, perbarui waktu aktivitas terakhir
if (isset($_SESSION['user_id'])) {
    updateLastActivity();
}
?>