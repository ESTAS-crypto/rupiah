<?php
require_once '../config/config.php'; // Disesuaikan dengan struktur direktori
require_once '../config/auth_check.php';

// Mulai sesi
startSession();

// Periksa apakah pengguna sudah login
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized', 'redirect' => '/rupiah/login.php?msg=login_required']);
    exit();
}

$user_id = $_SESSION['user_id'];
$transaksi_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

header('Content-Type: application/json'); // Pastikan header JSON selalu dikirim

if ($transaksi_id <= 0) {
    echo json_encode(['error' => 'Invalid ID']);
    exit();
}

try {
    // Periksa koneksi database
    if (!$conn) {
        throw new Exception("Koneksi database gagal: " . mysqli_connect_error());
    }

    $query = "SELECT * FROM transaksi WHERE transaksi_id = ? AND user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt === false) {
        throw new Exception("Gagal menyiapkan statement: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "ii", $transaksi_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $transaksi = mysqli_fetch_assoc($result);

    if ($transaksi) {
        echo json_encode($transaksi);
    } else {
        echo json_encode(['error' => 'Transaksi tidak ditemukan']);
    }

    mysqli_stmt_close($stmt);
} catch (Exception $e) {
    // Log error untuk debugging
    error_log("Error di get_transaksi.php: " . $e->getMessage());
    echo json_encode(['error' => 'Terjadi kesalahan saat mengambil data transaksi: ' . $e->getMessage()]);
}

mysqli_close($conn); // Tutup koneksi
?>