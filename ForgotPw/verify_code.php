<?php
require_once '../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Aktifkan error reporting untuk debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Jika pengguna sudah login, arahkan ke dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Pastikan pengguna berada di langkah yang benar (step 2) dan memiliki sesi reset yang valid
$session_valid = true;
$missing_session_vars = [];
if (!isset($_SESSION['reset_step']) || $_SESSION['reset_step'] != 2) {
    $session_valid = false;
    $missing_session_vars[] = 'reset_step';
}
if (!isset($_SESSION['reset_user_id'])) {
    $session_valid = false;
    $missing_session_vars[] = 'reset_user_id';
}
if (!isset($_SESSION['reset_username'])) {
    $session_valid = false;
    $missing_session_vars[] = 'reset_username';
}
if (!isset($_SESSION['reset_email'])) {
    $session_valid = false;
    $missing_session_vars[] = 'reset_email';
}
if (!isset($_SESSION['no_whatsapp'])) {
    $session_valid = false;
    $missing_session_vars[] = 'no_whatsapp';
}

if (!$session_valid) {
    // Log untuk debugging
    error_log("Sesi tidak valid di verify_code.php. Variabel yang hilang: " . implode(", ", $missing_session_vars));
    error_log("Sesi saat ini: " . print_r($_SESSION, true));
    $_SESSION['error'] = "Sesi tidak valid. Variabel yang hilang: " . implode(", ", $missing_session_vars) . ". Silakan ulangi proses.";
    header("Location: forgot_password.php?error=invalid_session");
    exit();
}

// Debug session (hanya untuk pengembangan)
$debug_session = false;
if ($debug_session) {
    echo '<pre>';
    print_r($_SESSION);
    echo '</pre>';
}

// Fungsi untuk menghasilkan link WhatsApp
function sendVerificationCodeWhatsApp($number, $code) {
    if (substr($number, 0, 1) === '0') {
        $number = '62' . substr($number, 1);
    }
    $message = "Kode verifikasi reset password Anda: $code";
    $url = "https://wa.me/" . urlencode($number) . "?text=" . urlencode($message);
    return $url;
}

// Cek apakah permintaan sudah disetujui
$user_id = $_SESSION['reset_user_id'];
$query = "SELECT * FROM password_reset_requests WHERE user_id = '$user_id' AND status = 'approved'";
$result = mysqli_query($conn, $query);
$already_approved = mysqli_num_rows($result) > 0;

// Jika sudah disetujui
if ($already_approved) {
    // Jika formulir verifikasi dikirim
    if (isset($_POST['verify'])) {
        $entered_code = $_POST['verification_code'];
        
        // Ambil kode verifikasi dari database sebagai sumber kebenaran
        $code_query = "SELECT verification_code FROM password_reset_requests 
                      WHERE user_id = '$user_id' AND status = 'approved' 
                      ORDER BY created_at DESC LIMIT 1";
        $code_result = mysqli_query($conn, $code_query);
        
        if ($code_result && mysqli_num_rows($code_result) > 0) {
            $code_row = mysqli_fetch_assoc($code_result);
            $db_verification_code = $code_row['verification_code'];
            
            // Bandingkan kode yang dimasukkan dengan kode dari database
            if ($entered_code === $db_verification_code) {
                // Kode benar, lanjutkan ke langkah reset password
                $_SESSION['reset_step'] = 3;
                header("Location: reset_password.php");
                exit();
            } else {
                $error = "Kode verifikasi tidak valid.";
            }
        } else {
            $error = "Tidak dapat menemukan kode verifikasi.";
        }
    }
    
    // Jika belum memiliki kode verifikasi dalam sesi, dapatkan atau buat
    if (!isset($_SESSION['verification_code'])) {
        // Cek apakah sudah ada kode verifikasi di database
        $check_code_query = "SELECT verification_code FROM password_reset_requests 
                            WHERE user_id = '$user_id' AND status = 'approved' 
                            AND verification_code IS NOT NULL 
                            ORDER BY created_at DESC LIMIT 1";
        $check_code_result = mysqli_query($conn, $check_code_query);
        
        if ($check_code_result && mysqli_num_rows($check_code_result) > 0) {
            // Gunakan kode yang sudah ada
            $code_row = mysqli_fetch_assoc($check_code_result);
            $verification_code = $code_row['verification_code'];
        } else {
            // Generate kode verifikasi acak (6 digit)
            $verification_code = sprintf("%06d", mt_rand(1, 999999));
            
            // Simpan kode dalam database
            $code_esc = mysqli_real_escape_string($conn, $verification_code);
            $query = "UPDATE password_reset_requests SET verification_code = '$code_esc' 
                      WHERE user_id = '$user_id' AND status = 'approved'";
            mysqli_query($conn, $query);
        }
        
        // Simpan kode dalam sesi juga untuk kemudahan akses
        $_SESSION['verification_code'] = $verification_code;
        
        // Ambil nomor WhatsApp pengguna dari sesi
        $user_whatsapp = $_SESSION['no_whatsapp'];
        
        // Buat link WhatsApp untuk mengirim kode
        $_SESSION['whatsapp_code_link'] = sendVerificationCodeWhatsApp($user_whatsapp, $verification_code);
        
        $success = "Permintaan reset password Anda telah disetujui. Silakan ambil kode verifikasi melalui WhatsApp.";
    }
} else {
    // Jika belum disetujui, cek status permintaan
    $query = "SELECT * FROM password_reset_requests WHERE user_id = '$user_id' ORDER BY created_at DESC LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if (mysqli_num_rows($result) > 0) {
        $request = mysqli_fetch_assoc($result);
        
        if ($request['status'] === 'approved') {
            // Jika sudah disetujui tapi belum memiliki kode verifikasi
            $verification_code = sprintf("%06d", mt_rand(1, 999999));
            $_SESSION['verification_code'] = $verification_code;
            
            // Simpan kode dalam database
            $code_esc = mysqli_real_escape_string($conn, $verification_code);
            $query = "UPDATE password_reset_requests SET verification_code = '$code_esc' 
                      WHERE user_id = '$user_id' AND status = 'approved'";
            mysqli_query($conn, $query);
            
            // Ambil nomor WhatsApp pengguna dari sesi
            $user_whatsapp = $_SESSION['no_whatsapp'];
            
            // Buat link WhatsApp untuk mengirim kode
            $_SESSION['whatsapp_code_link'] = sendVerificationCodeWhatsApp($user_whatsapp, $verification_code);
            
            $success = "Permintaan reset password Anda telah disetujui. Silakan ambil kode verifikasi melalui WhatsApp.";
        } else {
            $status = $request['status'];
            if ($status === 'pending') {
                $message = "Permintaan reset password Anda sedang menunggu persetujuan. Silahkan cek WhatsApp Anda atau refresh halaman ini.";
            } elseif ($status === 'rejected') {
                $error = "Permintaan reset password Anda ditolak. Silakan hubungi admin untuk informasi lebih lanjut.";
            } else {
                $error = "Status permintaan reset password tidak valid.";
            }
        }
    } else {
        $error = "Tidak ditemukan permintaan reset password.";
    }
}

// Simulasi penerimaan permintaan (untuk testing/development)
$simulate_approval = false;
if ($simulate_approval && isset($_POST['simulate_approve'])) {
    $query = "UPDATE password_reset_requests SET status = 'approved' WHERE user_id = '$user_id' AND status = 'pending'";
    if (mysqli_query($conn, $query)) {
        header("Location: verify_code.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Verifikasi Kode - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="../css/styles.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="jpg/png" />
    <style>
    .alert {
        padding: 10px 15px;
        margin-bottom: 15px;
        border-radius: 4px;
    }

    .alert-danger {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-info {
        background-color: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }

    .btn {
        display: inline-block;
        padding: 8px 15px;
        background-color: #4CAF50;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        border: none;
        cursor: pointer;
    }

    .btn:hover {
        background-color: #45a049;
    }
    </style>
</head>

<body>
    <div class="container">
        <h2>Verifikasi Kode</h2>

        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>

        <?php if (isset($success)) { ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
        <?php } ?>

        <?php if (isset($message)) { ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
        <?php } ?>

        <?php if ($already_approved) { ?>
        <!-- Tampilkan link WhatsApp untuk mendapatkan kode -->
        <p>Klik tombol di bawah untuk menerima kode verifikasi melalui WhatsApp ke nomor Anda
            (<?php echo $_SESSION['no_whatsapp']; ?>):</p>
        <p><a href="<?php echo $_SESSION['whatsapp_code_link']; ?>" target="_blank" class="btn">Terima Kode via
                WhatsApp</a></p>

        <!-- Form verifikasi kode -->
        <form method="POST" action="">
            <div class="form-group">
                <label>Kode Verifikasi</label>
                <input type="text" name="verification_code" maxlength="6" required>
            </div>
            <div class="form-group">
                <button type="submit" name="verify" class="btn">Verifikasi</button>
            </div>
        </form>
        <?php } elseif (!isset($error)) { ?>
        <p>Menunggu persetujuan dari admin. Silakan periksa WhatsApp Anda secara berkala.</p>
        <p><a href="verify_code.php" class="btn">Refresh Status</a></p>

        <?php if ($simulate_approval) { ?>
        <!-- Hanya untuk development, hapus di production -->
        <form method="POST" action="">
            <div class="form-group">
                <button type="submit" name="simulate_approve" class="btn" style="background-color: #ff9800;">Simulate
                    Approval (Dev Only)</button>
            </div>
        </form>
        <?php } ?>
        <?php } ?>

        <p><a href="forgot_password.php">Kembali</a></p>
    </div>
</body>

</html>