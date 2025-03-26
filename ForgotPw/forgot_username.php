<?php
require_once '../config/config.php';

// Memulai sesi jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Menangani pengiriman formulir
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    // Query untuk mencari username berdasarkan email
    $query = "SELECT username FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);
        $username = $user['username'];
        $message = "Username Anda adalah: " . htmlspecialchars($username);
    } else {
        $error = "Tidak ada pengguna yang ditemukan dengan alamat email tersebut.";
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Lupa Username - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="../css/styles.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
</head>

<body>
    <div class="container">
        <h2>Lupa Username</h2>
        <?php if (isset($message)) { ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
        <?php } ?>
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Alamat Email</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <button type="submit" class="btn">Ambil Username</button>
            </div>
            <p><a href="../login.php">Kembali ke Login</a></p>
        </form>
    </div>
</body>

</html>