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

// Pastikan pengguna berada di langkah yang benar (step 1) dan memiliki sesi reset yang valid
if (!isset($_SESSION['reset_step']) || $_SESSION['reset_step'] != 1 ||
    !isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_username']) ||
    !isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit();
}

// Fungsi untuk menghasilkan link WhatsApp
function sendWhatsAppMessage($number, $message) {
    $url = "https://wa.me/" . urlencode($number) . "?text=" . urlencode($message);
    return $url;
}

// Cek apakah bukti sudah dikirim sebelumnya
$evidence_submitted = isset($_SESSION['evidence_submitted']) && $_SESSION['evidence_submitted'] === true;

if (isset($_POST['submit_evidence']) && !$evidence_submitted) {
    $evidence_text = mysqli_real_escape_string($conn, $_POST['evidence_text']);
    $user_id = $_SESSION['reset_user_id'];

    // Proses upload file
    $evidence_photo = '';
    if (isset($_FILES['evidence_photo']) && $_FILES['evidence_photo']['error'] == 0) {
        $target_dir = "uploads/evidence/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $target_file = $target_dir . basename($_FILES["evidence_photo"]["name"]);
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($imageFileType, $allowed_types)) {
            if (move_uploaded_file($_FILES["evidence_photo"]["tmp_name"], $target_file)) {
                $evidence_photo = $target_file;
            } else {
                $error = "Gagal mengunggah foto.";
            }
        } else {
            $error = "Hanya file JPG, JPEG, PNG, dan GIF yang diperbolehkan.";
        }
    }

    if (empty($evidence_text) && empty($evidence_photo)) {
        $error = "Bukti kepemilikan harus diisi atau foto harus diunggah.";
    } else {
        // Gabungkan bukti teks dan foto
        $evidence = $evidence_text . ($evidence_photo ? " [Foto: $evidence_photo]" : "");
        $query = "UPDATE password_reset_requests 
                  SET evidence = '$evidence', status = 'pending' 
                  WHERE user_id = '$user_id' AND status = 'pending'";
        $update_result = mysqli_query($conn, $query);

        if ($update_result) {
            // Ambil nomor WhatsApp admin dari database
            $query = "SELECT no_whatsapp FROM users WHERE role = 'admin' LIMIT 1";
            $result = mysqli_query($conn, $query);
            $admin = mysqli_fetch_assoc($result);

            if ($admin && is_array($admin) && isset($admin['no_whatsapp']) && !empty($admin['no_whatsapp'])) {
                $admin_whatsapp = $admin['no_whatsapp'];
                $username = $_SESSION['reset_username'];
                $email = $_SESSION['reset_email'];
                $message = "Permintaan reset password dari user ID: $user_id, username: $username, email: $email. Bukti kepemilikan: $evidence_text";
                if ($evidence_photo) {
                    $message .= " Foto bukti: " . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/$evidence_photo";
                }
                $whatsapp_link = sendWhatsAppMessage($admin_whatsapp, $message);

                // Tandai bahwa bukti sudah dikirim dan simpan link WhatsApp
                $_SESSION['evidence_submitted'] = true;
                $_SESSION['whatsapp_link'] = $whatsapp_link;
            } else {
                $error = "Nomor WhatsApp admin tidak ditemukan atau tidak valid.";
            }
        } else {
            $error = "Gagal menyimpan bukti: " . mysqli_error($conn);
        }
    }
}

// Jika tombol "Lanjutkan" ditekan, arahkan ke verify_code.php
if (isset($_POST['continue']) && $evidence_submitted) {
    $_SESSION['reset_step'] = 2;
    header("Location: verify_code.php");
    exit();
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Bukti Kepemilikan - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/styles.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
</head>

<body>
    <div class="container">
        <h2>Bukti Kepemilikan Akun</h2>

        <?php if (!$evidence_submitted) { ?>
        <!-- Form untuk mengirim bukti -->
        <p>Silakan masukkan bukti kuat yang menunjukkan bahwa Anda adalah pemilik akun ini. Anda dapat mengirim teks
            dan/atau foto.</p>
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>
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
                <button type="submit" name="submit_evidence" class="btn">Kirim Bukti</button>
            </div>
        </form>
        <?php } else { ?>
        <!-- Instruksi setelah bukti dikirim -->
        <p>Bukti Anda telah disimpan. Silakan kirim bukti tersebut ke admin melalui WhatsApp dengan mengklik link
            berikut:</p>
        <p><a href="<?php echo $_SESSION['whatsapp_link']; ?>" target="_blank" class="btn">Kirim via WhatsApp</a></p>
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