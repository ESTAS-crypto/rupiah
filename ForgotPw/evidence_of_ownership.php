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

// Pastikan pengguna berada di langkah yang benar (step 1) dan memiliki sesi reset yang valid
if (!isset($_SESSION['reset_step']) || $_SESSION['reset_step'] != 1 ||
    !isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_username']) ||
    !isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php?error=invalid_session");
    exit();
}

// Fungsi untuk menghasilkan link WhatsApp
function sendWhatsAppMessage($number, $message) {
    if (empty($number)) {
        return false;
    }
    if (substr($number, 0, 1) === '0') {
        $number = '62' . substr($number, 1);
    }
    $url = "https://wa.me/" . urlencode($number) . "?text=" . urlencode($message);
    return $url;
}

// Buat direktori uploads jika belum ada
$upload_dir = __DIR__ . "/../uploads/evidence/";
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        $error = "Gagal membuat direktori upload. Hubungi administrator.";
    }
}

// Cek apakah bukti sudah dikirim sebelumnya
$evidence_submitted = isset($_SESSION['evidence_submitted']) && $_SESSION['evidence_submitted'] === true;

// Cek success atau error dari sesi
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Cek apakah pengguna sudah memiliki permintaan reset aktif
$user_id = $_SESSION['reset_user_id'];
$check_query = "SELECT COUNT(*) as count, status FROM password_reset_requests WHERE user_id = '$user_id' AND (status = 'pending' OR status = 'approved') ORDER BY created_at DESC LIMIT 1";
$check_result = mysqli_query($conn, $check_query);
$request = mysqli_fetch_assoc($check_result);

$has_active_request = $request['count'] > 0;

if ($has_active_request && !$evidence_submitted) {
    $status = $request['status'];
    if ($status === 'pending') {
        $message = "Anda sudah memiliki permintaan reset password yang sedang menunggu persetujuan. Silakan tunggu.";
        $_SESSION['evidence_submitted'] = true;
        $evidence_submitted = true;
    } elseif ($status === 'approved') {
        $_SESSION['reset_step'] = 2;
        // Pastikan semua variabel sesi yang diperlukan ada sebelum mengalihkan
        if (isset($_SESSION['no_whatsapp'])) {
            header("Location: verify_code.php");
            exit();
        } else {
            $_SESSION['error'] = "Nomor WhatsApp tidak tersedia. Silakan ulangi proses.";
            header("Location: forgot_password.php?error=missing_whatsapp");
            exit();
        }
    }
}

// Proses pengiriman bukti
if (isset($_POST['submit_evidence']) && !$evidence_submitted) {
    $evidence_text = isset($_POST['evidence_text']) ? mysqli_real_escape_string($conn, $_POST['evidence_text']) : '';
    $no_whatsapp = isset($_POST['no_whatsapp']) ? mysqli_real_escape_string($conn, $_POST['no_whatsapp']) : '';

    if (empty($no_whatsapp)) {
        $_SESSION['error'] = "Nomor WhatsApp harus diisi.";
    } elseif (!preg_match('/^[0-9]{10,15}$/', $no_whatsapp)) {
        $_SESSION['error'] = "Nomor WhatsApp tidak valid.";
    } else {
        $evidence_photo = '';
        $evidence_photo_url = '';
        if (isset($_FILES['evidence_photo']) && $_FILES['evidence_photo']['error'] == 0) {
            $target_dir = "../uploads/evidence/";
            $file_extension = strtolower(pathinfo($_FILES["evidence_photo"]["name"], PATHINFO_EXTENSION));
            $new_filename = $user_id . '_' . time() . '.' . $file_extension;
            $target_file = $target_dir . $new_filename;
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($file_extension, $allowed_types)) {
                if (move_uploaded_file($_FILES["evidence_photo"]["tmp_name"], $target_file)) {
                    $evidence_photo = $new_filename;
                    $base_url = "http://localhost/uplain/uploads/evidence/"; // Ganti dengan domain Anda jika bukan localhost
                    $evidence_photo_url = $base_url . $evidence_photo;
                } else {
                    $_SESSION['error'] = "Gagal mengunggah foto.";
                }
            } else {
                $_SESSION['error'] = "Hanya file JPG, JPEG, PNG, dan GIF yang diperbolehkan.";
            }
        }

        if (empty($evidence_text) && empty($evidence_photo)) {
            $_SESSION['error'] = "Bukti kepemilikan harus diisi atau foto harus diunggah.";
        } else {
            $evidence = $evidence_text . ($evidence_photo ? " [Foto: $evidence_photo]" : "");
            $query = "INSERT INTO password_reset_requests (user_id, status, evidence, created_at) 
                      VALUES ('$user_id', 'pending', '$evidence', NOW())";
            $insert_result = mysqli_query($conn, $query);

            if ($insert_result) {
                $admin_whatsapp = "62895385890629"; // Nomor WhatsApp admin sesuai dengan link Anda
                if (!empty($admin_whatsapp)) {
                    $_SESSION['no_whatsapp'] = $no_whatsapp;
                    $username = $_SESSION['reset_username'];
                    $email = $_SESSION['reset_email'];
                    $message = "Permintaan reset password dari user ID: $user_id, username: $username, email: $email. Bukti kepemilikan: $evidence_text";
                    if ($evidence_photo_url) {
                        $message .= "\nFoto bukti: $evidence_photo_url";
                    }
                    $whatsapp_link = sendWhatsAppMessage($admin_whatsapp, $message);
                    if ($whatsapp_link) {
                        $_SESSION['evidence_submitted'] = true;
                        $_SESSION['whatsapp_link'] = $whatsapp_link;
                        $_SESSION['success'] = "Bukti berhasil disimpan! Silakan kirim bukti ke admin.";
                        header("Location: evidence_of_ownership.php");
                        exit();
                    } else {
                        $_SESSION['error'] = "Gagal menghasilkan link WhatsApp. Hubungi administrator.";
                    }
                } else {
                    $_SESSION['error'] = "Nomor WhatsApp admin tidak ditemukan.";
                }
            } else {
                $_SESSION['error'] = "Gagal menyimpan bukti: " . mysqli_error($conn);
            }
        }
    }
    header("Location: evidence_of_ownership.php");
    exit();
}

// Proses tombol "Lanjutkan"
if (isset($_POST['continue']) && $evidence_submitted) {
    // Pastikan semua variabel sesi yang diperlukan ada sebelum mengalihkan
    if (isset($_SESSION['reset_user_id']) && isset($_SESSION['reset_username']) &&
        isset($_SESSION['reset_email']) && isset($_SESSION['no_whatsapp'])) {
        $_SESSION['reset_step'] = 2;
        header("Location: verify_code.php");
        exit();
    } else {
        // Log untuk debugging
        error_log("Sesi tidak lengkap saat tombol Lanjutkan diklik: " . print_r($_SESSION, true));
        $_SESSION['error'] = "Sesi tidak valid. Silakan ulangi proses reset password.";
        header("Location: forgot_password.php?error=invalid_session");
        exit();
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Bukti Kepemilikan - Manajemen Keuangan</title>
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
        <h2>Bukti Kepemilikan Akun</h2>

        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>

        <?php if (isset($success)) { ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
        <?php } ?>

        <?php if (isset($message)) { ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
        <?php } ?>

        <?php if (!$evidence_submitted) { ?>
        <p>Silakan masukkan bukti kuat yang menunjukkan bahwa Anda adalah pemilik akun ini. Anda dapat mengirim teks
            dan/atau foto.</p>
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label>Bukti Kepemilikan (teks)</label>
                <textarea name="evidence_text" rows="5"></textarea>
            </div>
            <div class="form-group">
                <label>Unggah Foto Bukti (opsional)</label>
                <input type="file" name="evidence_photo" accept="image/*">
            </div>
            <div class="form-group">
                <label>Nomor WhatsApp Anda</label>
                <input type="text" name="no_whatsapp" placeholder="Contoh: 628123456789" required>
                <small>* Wajib diisi</small>
            </div>
            <div class="form-group">
                <button type="submit" name="submit_evidence" class="btn">Kirim Bukti</button>
            </div>
        </form>
        <?php } else { ?>
        <p>Bukti Anda telah disimpan. Silakan kirim bukti tersebut ke admin melalui WhatsApp dengan mengklik link
            berikut:</p>
        <?php if (isset($_SESSION['whatsapp_link']) && !empty($_SESSION['whatsapp_link'])) { ?>
        <p><a href="<?php echo $_SESSION['whatsapp_link']; ?>" target="_blank" class="btn">Kirim via WhatsApp</a></p>
        <?php } else { ?>
        <p>Link WhatsApp tidak tersedia. Silakan coba lagi atau hubungi administrator.</p>
        <?php } ?>
        <p>Setelah mengirim bukti, klik "Lanjutkan" untuk melanjutkan ke langkah verifikasi kode.</p>
        <form method="POST" action="">
            <div class="form-group">
                <button type="submit" name="continue" class="btn">Lanjutkan</button>
            </div>
        </form>
        <?php } ?>

        <p><a href="forgot_password.php">Kembali</a></p>
    </div>
</body>

</html>