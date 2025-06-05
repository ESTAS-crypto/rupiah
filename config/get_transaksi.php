<?php
require_once './config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing ID']);
    exit();
}

$transaksi_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    $query = "SELECT t.*, k.jenis as kategori_jenis 
              FROM transaksi t 
              LEFT JOIN kategori k ON t.kategori_id = k.kategori_id 
              WHERE t.transaksi_id = ? AND t.user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $transaksi_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $transaksi = mysqli_fetch_assoc($result);

    header('Content-Type: application/json');
    if ($transaksi) {
        echo json_encode($transaksi);
    } else {
        echo json_encode(['error' => 'Transaction not found']);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}

mysqli_close($conn);