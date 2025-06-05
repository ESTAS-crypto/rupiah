<?php
require_once '../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
    $query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    $success = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => $success]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>