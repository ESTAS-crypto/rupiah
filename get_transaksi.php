<?php
require_once 'config/config.php';
require_once 'config/auth_check.php';

// Pastikan sesi dimulai
startSession();

// Periksa apakah pengguna sudah login
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    // Tambahkan URL pengalihan dalam respons JSON
    echo json_encode(['error' => 'Unauthorized', 'redirect' => '/rupiah/login.php?msg=login_required']);
    exit();
}

$user_id = $_SESSION['user_id'];
$transaksi_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($transaksi_id) {
    $query = "SELECT * FROM transaksi WHERE transaksi_id = ? AND user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $transaksi_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $transaksi = mysqli_fetch_assoc($result);
    
    if ($transaksi) {
        header('Content-Type: application/json');
        echo json_encode($transaksi);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Transaksi not found']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid ID']);
}
?>