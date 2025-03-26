<?php
require_once 'config/config.php';
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cek login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$id = $_SESSION['user_id'];

// Ambil data user 
$query = "SELECT * FROM users WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

// Set path untuk foto profil
$default_image = './images/default-profil.png';
$profil_image = $default_image;

if (!empty($user['foto_profil'])) {
    $custom_image = './uploads/profil/' . $user['foto_profil'];
    if (file_exists($custom_image)) {
        $profil_image = $custom_image;
    }
}

// Proses update profil
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama_lengkap = mysqli_real_escape_string($conn, $_POST['nama_lengkap']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $no_whatsapp = mysqli_real_escape_string($conn, $_POST['no_whatsapp']); // Tambah no_whatsapp
    
    // Proses upload foto
    if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['foto_profil']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $upload_dir = './uploads/profil/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_filename = uniqid() . '.' . $ext;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['foto_profil']['tmp_name'], $upload_path)) {
                if (!empty($user['foto_profil'])) {
                    $old_file = $upload_dir . $user['foto_profil'];
                    if (file_exists($old_file) && $user['foto_profil'] != 'default-profil.png') {
                        unlink($old_file);
                    }
                }
                
                // Update dengan foto dan no_whatsapp
                $query = "UPDATE users SET nama_lengkap = ?, email = ?, foto_profil = ?, no_whatsapp = ? WHERE user_id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "ssssi", $nama_lengkap, $email, $new_filename, $no_whatsapp, $id);
            } else {
                $error = "Gagal mengupload file. Periksa izin folder.";
            }
        } else {
            $error = "Format file tidak diizinkan. Gunakan format: jpg, jpeg, png, atau gif.";
        }
    } else {
        // Update tanpa foto
        $query = "UPDATE users SET nama_lengkap = ?, email = ?, no_whatsapp = ? WHERE user_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "sssi", $nama_lengkap, $email, $no_whatsapp, $id);
    }
    
    if (isset($stmt) && mysqli_stmt_execute($stmt)) {
        $_SESSION['nama_lengkap'] = $nama_lengkap;
        $_SESSION['success'] = "Profil berhasil diperbarui!";
        header("Location: profile.php");
        exit();
    } else {
        $error = "Gagal memperbarui profil: " . mysqli_error($conn);
    }
}

// Debug informasi upload
if (isset($_FILES['foto_profil'])) {
    error_log("Upload info: " . print_r($_FILES['foto_profil'], true));
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Profil - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="./css/styles.css">


</head>

<body>
    <div class="sidebar">
        <div class="menu">
            <a href="dashboard.php" class="keluar-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <div class="main-content">
        <div class="container">
            <h2>Edit Profil</h2>

            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="profile-card">
                <div class="profile-image">
                    <img src="<?php echo $profil_image; ?>" alt="Profil" id="preview-image"
                        onerror="this.src='./images/default-profil.png'">
                    <div class="image-overlay">
                        <label for="foto_profil" class="edit-image-btn">
                            <i class="fas fa-camera"></i>
                            Ubah Foto
                        </label>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="file" name="foto_profil" id="foto_profil" accept="image/*" style="display: none;">

                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                    </div>

                    <div class="form-group">
                        <label>Nama Lengkap</label>
                        <input type="text" name="nama_lengkap"
                            value="<?php echo htmlspecialchars($user['nama_lengkap']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Nomor WhatsApp</label>
                        <input type="text" name="no_whatsapp"
                            value="<?php echo htmlspecialchars($user['no_whatsapp'] ?? ''); ?>"
                            placeholder="Contoh: 081234567890">
                    </div>

                    <button type="submit" class="btn">Simpan Perubahan</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('foto_profil').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('preview-image').src = e.target.result;
            }
            reader.readAsDataURL(file);
        }
    });
    </script>
</body>

</html>