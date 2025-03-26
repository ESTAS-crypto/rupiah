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

// Fungsi untuk menghasilkan link WhatsApp
function sendWhatsAppMessage($number, $message) {
    $url = "https://wa.me/" . urlencode($number) . "?text=" . urlencode($message);
    return $url;
}

if (isset($_POST['verify'])) {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    if (empty($username) || empty($email)) {
        $error = "Username dan email harus diisi.";
    } else {
        $query = "SELECT user_id, no_whatsapp, role FROM users WHERE username = '$username' AND email = '$email'";
        $result = mysqli_query($conn, $query);

        if ($result && mysqli_num_rows($result) == 1) {
            $user = mysqli_fetch_assoc($result);

            // Pastikan $user tidak null dan merupakan array
            if ($user && is_array($user)) {
                $_SESSION['reset_user_id'] = $user['user_id'];
                $_SESSION['reset_username'] = $username;
                $_SESSION['reset_email'] = $email;

                // Cek apakah no_whatsapp ada, gunakan string kosong jika tidak ada
                $_SESSION['no_whatsapp'] = isset($user['no_whatsapp']) && !empty($user['no_whatsapp']) ? $user['no_whatsapp'] : '';

                $_SESSION['reset_step'] = 1; // Mulai dari step 1

                // Simpan permintaan ke database
                $query = "INSERT INTO password_reset_requests (user_id, evidence, status, created_at) 
                          VALUES ('{$user['user_id']}', 'Permintaan otomatis via username dan email', 'pending', NOW())";
                $insert_result = mysqli_query($conn, $query);

                if ($insert_result) {
                    // Ambil nomor WhatsApp admin
                    $query = "SELECT no_whatsapp FROM users WHERE role = 'admin' LIMIT 1";
                    $result = mysqli_query($conn, $query);
                    $admin = mysqli_fetch_assoc($result);

                    // Pastikan $admin tidak null dan no_whatsapp tersedia
                    if ($admin && is_array($admin) && isset($admin['no_whatsapp']) && !empty($admin['no_whatsapp'])) {
                        $admin_whatsapp = $admin['no_whatsapp'];

                        $message = "Saya meminta reset password untuk akun dengan user ID: {$user['user_id']}, username: $username, email: $email.";
                        $whatsapp_link = sendWhatsAppMessage($admin_whatsapp, $message);
                        echo "<script>window.open('$whatsapp_link', '_blank'); window.location.href='evidence_of_ownership.php';</script>";
                        exit();
                    } else {
                        $error = "Nomor WhatsApp admin tidak ditemukan atau tidak valid.";
                    }
                } else {
                    $error = "Gagal menyimpan permintaan: " . mysqli_error($conn);
                }
            } else {
                $error = "Data pengguna tidak ditemukan atau tidak valid.";
            }
        } else {
            $error = "Username dan email tidak cocok.";
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Lupa Password - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/register.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
</head>

<body>
    <div class="container">
        <h2>Lupa Password</h2>
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Alamat Email</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <button type="submit" name="verify" class="btn">Lanjutkan</button>
            </div>
        </form>
        <p><a href="login.php">Kembali ke Login</a></p>
    </div>
</body>

</html>