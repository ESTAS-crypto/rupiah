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

// Pastikan pengguna berada di langkah yang benar (step 3) dan memiliki sesi reset yang valid
if (!isset($_SESSION['reset_step']) || $_SESSION['reset_step'] != 3 ||
    !isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_username']) ||
    !isset($_SESSION['reset_email']) || !isset($_SESSION['no_whatsapp'])) {
    header("Location: forgot_password.php");
    exit();
}

if (isset($_POST['reset'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Kata sandi baru dan konfirmasi harus diisi.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Kata sandi tidak cocok.";
    } else {
        // Validasi persyaratan password
        $uppercase = preg_match('@[A-Z]@', $new_password);
        $lowercase = preg_match('@[a-z]@', $new_password);
        $number    = preg_match('@[0-9]@', $new_password);
        $specialChars = preg_match('@[^\w]@', $new_password);

        if(!$uppercase || !$lowercase || !$number || !$specialChars || strlen($new_password) < 8) {
            $error = "Password harus minimal 8 karakter dan harus mengandung huruf besar, huruf kecil, angka, dan simbol!";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $hashed_password_esc = mysqli_real_escape_string($conn, $hashed_password);
            $user_id = $_SESSION['reset_user_id'];

            $query = "UPDATE users SET password = '$hashed_password_esc' WHERE user_id = '$user_id'";
            $update_result = mysqli_query($conn, $query);

            if ($update_result && mysqli_affected_rows($conn) == 1) {
                // Tandai permintaan sebagai selesai
                $query = "UPDATE password_reset_requests SET status = 'completed' 
                          WHERE user_id = '$user_id' AND status = 'approved'";
                mysqli_query($conn, $query);

                $message = "Kata sandi telah berhasil diatur ulang. Silakan <a href='login.php'>login</a> dengan kata sandi baru.";
                // Hapus semua sesi terkait reset
                unset($_SESSION['reset_step']);
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['reset_username']);
                unset($_SESSION['reset_email']);
                unset($_SESSION['no_whatsapp']);
            } else {
                $error = "Gagal mengatur ulang kata sandi: " . mysqli_error($conn);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Reset Password - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
    <style>
    .password-requirements ul {
        list-style: none;
        padding: 0;
    }

    .password-requirements li {
        margin: 5px 0;
    }

    .password-requirements i {
        margin-right: 5px;
    }

    .valid {
        color: green;
    }

    .valid i {
        color: green;
    }
    </style>
</head>

<body>
    <div class="container">
        <h2>Reset Password</h2>
        <?php if (isset($message)) { ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
        <?php } ?>
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Kata Sandi Baru</label>
                <input type="password" name="new_password" id="new_password" required>
                <div class="password-requirements">
                    Password harus memiliki:
                    <ul>
                        <li id="length"><i class="fa fa-circle"></i> Minimal 8 karakter</li>
                        <li id="uppercase"><i class="fa fa-circle"></i> Minimal 1 huruf besar</li>
                        <li id="lowercase"><i class="fa fa-circle"></i> Minimal 1 huruf kecil</li>
                        <li id="number"><i class="fa fa-circle"></i> Minimal 1 angka</li>
                        <li id="symbol"><i class="fa fa-circle"></i> Minimal 1 simbol</li>
                    </ul>
                </div>
            </div>
            <div class="form-group">
                <label>Konfirmasi Kata Sandi</label>
                <input type="password" name="confirm_password" required>
            </div>
            <div class="form-group">
                <button type="submit" name="reset" class="btn">Atur Ulang Kata Sandi</button>
            </div>
        </form>
        <p><a href="login.php">Kembali ke Login</a></p>
    </div>

    <script>
    const new_password = document.getElementById('new_password');
    const requirements = {
        length: document.getElementById('length'),
        uppercase: document.getElementById('uppercase'),
        lowercase: document.getElementById('lowercase'),
        number: document.getElementById('number'),
        symbol: document.getElementById('symbol')
    };

    new_password.addEventListener('input', function() {
        const value = this.value;

        // Check length
        if (value.length >= 8) {
            requirements.length.classList.add('valid');
        } else {
            requirements.length.classList.remove('valid');
        }

        // Check uppercase
        if (/[A-Z]/.test(value)) {
            requirements.uppercase.classList.add('valid');
        } else {
            requirements.uppercase.classList.remove('valid');
        }

        // Check lowercase
        if (/[a-z]/.test(value)) {
            requirements.lowercase.classList.add('valid');
        } else {
            requirements.lowercase.classList.remove('valid');
        }

        // Check number
        if (/[0-9]/.test(value)) {
            requirements.number.classList.add('valid');
        } else {
            requirements.number.classList.remove('valid');
        }

        // Check symbol
        if (/[^A-Za-z0-9]/.test(value)) {
            requirements.symbol.classList.add('valid');
        } else {
            requirements.symbol.classList.remove('valid');
        }
    });
    </script>
</body>

</html>