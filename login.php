<?php
require_once 'config/config.php';

startSession();

// Jika pengguna sudah login, arahkan ke dashboard
redirectIfLoggedIn();

$error = null;
$warning_message = null;
$ban_expiry = null;
$warning_expiry = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Username dan password harus diisi.";
    } else {
        $username = mysqli_real_escape_string($conn, $username);
        $query = "SELECT user_id, username, nama_lengkap, password, role, ban_status, ban_expiry, warning_count, warning_expiry 
                  FROM users WHERE username = ?";
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if ($user) {
                // Verifikasi password
                if (password_verify($password, $user['password'])) {
                    // Cek status ban
                    if ($user['ban_status'] === 'banned') {
                        if ($user['ban_expiry'] === null) {
                            $error = "Akun Anda diban permanen. Hubungi admin (0895385890629).";
                        } elseif (strtotime($user['ban_expiry']) > time()) {
                            $ban_expiry = $user['ban_expiry'];
                            $error = "Akun Anda telah diban. Sisa waktu: <span id='ban-countdown'></span>. Hubungi admin (0895385890629).";
                        } else {
                            // Reset ban jika kadaluarsa
                            $reset_query = "UPDATE users SET ban_status = 'active', ban_expiry = NULL WHERE user_id = ?";
                            $reset_stmt = mysqli_prepare($conn, $reset_query);
                            mysqli_stmt_bind_param($reset_stmt, "i", $user['user_id']);
                            mysqli_stmt_execute($reset_stmt);
                            mysqli_stmt_close($reset_stmt);
                        }
                    }

                    // Cek peringatan
                    if ($user['warning_count'] > 0) {
                        if ($user['warning_expiry'] && strtotime($user['warning_expiry']) > time()) {
                            $warning_expiry = $user['warning_expiry'];
                            $warning_message = "Anda memiliki " . $user['warning_count'] . " peringatan. Sisa waktu: <span id='warning-countdown'></span>. 3 peringatan akan memban akun Anda.";
                        } else {
                            $warning_message = "Anda memiliki " . $user['warning_count'] . " peringatan. 3 peringatan akan memban akun Anda.";
                            // Reset peringatan jika kadaluarsa
                            if ($user['warning_expiry'] && strtotime($user['warning_expiry']) <= time()) {
                                $reset_warning_query = "UPDATE users SET warning_count = 0, warning_expiry = NULL WHERE user_id = ?";
                                $reset_warning_stmt = mysqli_prepare($conn, $reset_warning_query);
                                mysqli_stmt_bind_param($reset_warning_stmt, "i", $user['user_id']);
                                mysqli_stmt_execute($reset_warning_stmt);
                                mysqli_stmt_close($reset_warning_stmt);
                                $warning_message = null;
                            }
                        }
                    }

                    // Jika tidak ada error ban, lanjutkan login
                    if (!$error) {
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['last_activity'] = time();
                        header("Location: dashboard.php");
                        exit();
                    }
                } else {
                    $error = "Password salah!";
                }
            } else {
                $error = "Username tidak ditemukan!";
            }
        } else {
            $error = "Kesalahan database: " . mysqli_error($conn);
        }
    }
}

// Handle pesan sukses registrasi
$register_success = $_SESSION['register_success'] ?? false;
$registered_username = $_SESSION['registered_username'] ?? '';
unset($_SESSION['register_success'], $_SESSION['registered_username']);

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <title>Login - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/styles.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
</head>

<body>
    <div class="container">
        <h2>Login</h2>
        <?php if ($register_success): ?>
        <div class="alert alert-success">
            Akun "<?php echo htmlspecialchars($registered_username); ?>" berhasil dibuat. Silakan login.
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($warning_message): ?>
        <div class="alert alert-warning"><?php echo $warning_message; ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <button type="submit" class="btn">Login</button>
            </div>
            <p><a href="forgot_username.php">Lupa Username?</a> | <a href="forgot_password.php">Lupa Password?</a></p>
            <p>Belum punya akun? <a href="register.php">Register di sini</a></p>
        </form>
    </div>

    <script>
    function startCountdown(elementId, expiryTime) {
        const element = document.getElementById(elementId);
        if (!element) return;

        const expiry = new Date(expiryTime).getTime();

        const updateCountdown = () => {
            const now = new Date().getTime();
            const distance = expiry - now;

            if (distance <= 0) {
                element.innerHTML = "Kadaluarsa";
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            element.innerHTML = `${days} hari, ${hours} jam, ${minutes} menit, ${seconds} detik`;
        };

        updateCountdown();
        setInterval(updateCountdown, 1000);
    }

    <?php if ($ban_expiry): ?>
    startCountdown('ban-countdown', '<?php echo $ban_expiry; ?>');
    <?php endif; ?>
    <?php if ($warning_expiry): ?>
    startCountdown('warning-countdown', '<?php echo $warning_expiry; ?>');
    <?php endif; ?>
    </script>
</body>

</html>