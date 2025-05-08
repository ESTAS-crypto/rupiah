<?php
require_once 'config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Jika pengguna sudah login, arahkan ke dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Pastikan pengguna berada di langkah yang benar (step 2) dan memiliki sesi reset yang valid
if (!isset($_SESSION['reset_step']) || $_SESSION['reset_step'] != 2 ||
    !isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_username']) ||
    !isset($_SESSION['reset_email']) || !isset($_SESSION['no_whatsapp'])) {
    header("Location: forgot_password.php");
    exit();
}

if (isset($_POST['verify_code'])) {
    $code = mysqli_real_escape_string($conn, $_POST['verification_code']);
    $user_id = $_SESSION['reset_user_id'];

    if (empty($code)) {
        $error = "Kode verifikasi harus diisi.";
    } else {
        // Cek kode verifikasi dan waktu kadaluarsa dari tabel password_reset_requests
        $query = "SELECT verification_code, expired_at FROM password_reset_requests 
                  WHERE user_id = '$user_id' AND status = 'approved' 
                  ORDER BY approved_at DESC LIMIT 1";
        $result = mysqli_query($conn, $query);

        if ($result && $request = mysqli_fetch_assoc($result)) {
            $current_time = date('Y-m-d H:i:s');
            if ($request['verification_code'] && $request['verification_code'] == $code && $current_time <= $request['expired_at']) {
                $_SESSION['reset_step'] = 3; // Langkah berikutnya: Reset password
                header("Location: reset_password.php");
                exit();
            } else if ($current_time > $request['expired_at']) {
                $error = "Kode verifikasi telah kadaluarsa. Silakan ajukan permintaan reset password baru.";
            } else {
                $error = "Kode verifikasi salah.";
            }
        } else {
            $error = "Permintaan reset password tidak ditemukan atau belum disetujui.";
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Verifikasi Kode - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/styles.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
</head>

<body>
    <div class="container">
        <h2>Verifikasi Kode</h2>
        <p>Silakan masukkan kode verifikasi yang telah dikirimkan oleh admin ke nomor WhatsApp Anda.</p>
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Kode Verifikasi</label>
                <input type="text" name="verification_code" required>
            </div>
            <div class="form-group">
                <button type="submit" name="verify_code" class="btn">Verifikasi Kode</button>
            </div>
        </form>
        <p><a href="forgot_password.php">Kembali</a></p>
    </div>
</body>

</html>