<?php
require_once '../config/config.php';
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
    $custom_image = '../uploads/profil/' . $user['foto_profil'];
    if (file_exists($custom_image)) {
        $profil_image = $custom_image;
    }
}

// Cek apakah user memiliki role "secret"
$is_secret_role = strtolower($user['role']) === 'secret';

// Tambahkan ini: Periksa apakah pengguna bisa mengakses fitur berdasarkan jumlah peringatan
$can_access_features = canAccessFeature($user['warning_count'] ?? 0);

// Proses update profil
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama_lengkap = mysqli_real_escape_string($conn, $_POST['nama_lengkap']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $no_whatsapp = mysqli_real_escape_string($conn, $_POST['no_whatsapp']); // Tambah no_whatsapp
    
    // Proses upload foto
    if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] == 0) {
        // Tentukan file yang diizinkan berdasarkan role
        $allowed = ['jpg', 'jpeg', 'png'];
        
        // Khusus untuk role "secret", izinkan gif animasi
        if ($is_secret_role) {
            $allowed[] = 'gif';
        }
        
        $filename = $_FILES['foto_profil']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $upload_dir = '../uploads/profil/';
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
            if ($is_secret_role) {
                $error = "Format file tidak diizinkan. Gunakan format: jpg, jpeg, png, atau gif.";
            } else {
                $error = "Format file tidak diizinkan. Gunakan format: jpg, jpeg, atau png.";
            }
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

// Cek apakah foto profil adalah GIF
$is_gif_profile = false;
if (!empty($user['foto_profil'])) {
    $ext = strtolower(pathinfo($user['foto_profil'], PATHINFO_EXTENSION));
    $is_gif_profile = ($ext === 'gif');
}

function getRoleIcon($role) {
    $icons = ['owner' => 'crown', 'coder' => 'code', 'admin' => 'user-shield', 'user' => 'user', 'secret' => 'user-secret'];
    return '<i class="fas fa-' . ($icons[strtolower($role)] ?? 'user') . '"></i>';
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Profil - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/profile.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="image/png" />
    <style>
    .profile-container {
        width: 100%;
        max-width: 800px;
        margin: 30px auto;
        padding: 20px;
        background-color: #fff;
        border-radius: 10px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    }

    .profile-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #eee;
    }

    .profile-image {
        position: relative;
        width: 150px;
        height: 150px;
        margin: 0 auto 30px;
    }

    .profile-image img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #2ecc71;
    }

    .image-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        border-radius: 50%;
        opacity: 0;
        transition: opacity 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .profile-image:hover .image-overlay {
        opacity: 1;
    }

    .edit-image-btn {
        color: white;
        cursor: pointer;
        text-align: center;
        font-size: 14px;
    }

    .edit-image-btn i {
        font-size: 24px;
        margin-bottom: 5px;
        display: block;
    }

    .gif-badge {
        position: absolute;
        bottom: 0;
        right: 10px;
        background-color: #ff5722;
        color: white;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: bold;
    }

    .secret-role-badge {
        background-color: #673ab7;
        color: white;
        padding: 5px 10px;
        border-radius: 4px;
        margin-bottom: 10px;
        display: inline-block;
    }

    .gif-controls {
        margin-top: 15px;
        text-align: center;
    }

    .gif-info {
        margin-top: 10px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 5px;
        font-size: 14px;
        margin-bottom: 20px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #34495e;
        font-weight: 600;
    }

    .form-group input {
        width: 100%;
        padding: 12px;
        border: 2px solid #eef2f7;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
    }

    .form-group input:disabled {
        background: #f8fafc;
        cursor: not-allowed;
    }

    .form-group input:focus {
        border-color: #2ecc71;
        outline: none;
    }

    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 8px;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .btn {
        padding: 10px 20px;
        background: #2ecc71;
        color: white;
        border: none;
        border-radius: 5px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-sm {
        padding: 5px 10px;
        font-size: 12px;
    }

    .btn:hover {
        background: #27ae60;
        transform: translateY(-2px);
    }

    .btn-block {
        display: block;
        width: 100%;
    }

    .main-content {
        margin-left: 250px;
        padding: 20px;
        transition: all 0.3s ease;
    }

    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
        }

        .profile-container {
            padding: 15px;
        }

        .profile-image {
            width: 120px;
            height: 120px;
        }
    }

    /* Animasi khusus untuk pengguna role "secret" */
    <?php if ($is_secret_role): ?>

    /* Rainbow animation untuk badge secret */
    @keyframes rainbow {
        0% {
            color: #ff0000;
        }

        14% {
            color: #ff7f00;
        }

        28% {
            color: #ffff00;
        }

        42% {
            color: #00ff00;
        }

        57% {
            color: #0000ff;
        }

        71% {
            color: #4b0082;
        }

        85% {
            color: #9400d3;
        }

        100% {
            color: #ff0000;
        }
    }

    @keyframes backgroundRainbow {
        0% {
            background-color: #ff0000;
        }

        14% {
            background-color: #ff7f00;
        }

        28% {
            background-color: #ffff00;
        }

        42% {
            background-color: #00ff00;
        }

        57% {
            background-color: #0000ff;
        }

        71% {
            background-color: #4b0082;
        }

        85% {
            background-color: #9400d3;
        }

        100% {
            background-color: #ff0000;
        }
    }

    .secret-role-badge {
        animation: backgroundRainbow 8s linear infinite;
        background-color: #673ab7;
        color: white !important;
        text-shadow: 0px 0px 5px rgba(0, 0, 0, 0.5);
        box-shadow: 0 0 15px rgba(255, 255, 255, 0.5);
        transition: all 0.5s ease;
    }

    .secret-role-badge:hover {
        transform: scale(1.1);
        box-shadow: 0 0 20px rgba(255, 255, 255, 0.8);
    }

    .secret-role {
        animation: rainbow 5s linear infinite;
        font-weight: bold;
        text-shadow: 0px 0px 2px rgba(0, 0, 0, 0.2);
    }

    /* Glowing border for profile image */
    .profile-image img {
        border: 3px solid #673ab7;
        box-shadow: 0 0 15px rgba(103, 58, 183, 0.8);
        transition: all 0.5s ease;
    }

    .profile-image:hover img {
        box-shadow: 0 0 25px rgba(103, 58, 183, 1);
    }

    /* Matrix-style background for main-content */
    .main-content {
        position: relative;
        overflow: hidden;
    }

    /* Particles container */
    #particles-js {
        position: fixed;
        width: 100%;
        height: 100%;
        top: 0;
        left: 0;
        z-index: -1;
        pointer-events: none;
    }

    /* Input field animation for secret users */
    .form-group input:focus {
        border-color: #673ab7;
        box-shadow: 0 0 10px rgba(103, 58, 183, 0.5);
    }

    /* Custom cursor */
    body {
        cursor: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="%23673ab7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line><path d="M12 2a10 10 0 0 0-10 10 10 10 0 0 0 10 10 10 10 0 0 0 10-10 10 10 0 0 0-10-10"></path></svg>'), auto;
    }

    /* Floating button animation */
    .btn {
        position: relative;
        overflow: hidden;
        background: linear-gradient(45deg, #673ab7, #3f51b5);
        box-shadow: 0 4px 15px rgba(103, 58, 183, 0.3);
        transition: all 0.3s ease;
    }

    .btn:hover {
        background: linear-gradient(45deg, #3f51b5, #673ab7);
        box-shadow: 0 8px 25px rgba(103, 58, 183, 0.5);
        transform: translateY(-5px);
    }

    .btn::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 5px;
        height: 5px;
        background: rgba(255, 255, 255, 0.5);
        opacity: 0;
        border-radius: 100%;
        transform: scale(1, 1) translate(-50%);
        transform-origin: 50% 50%;
    }

    .btn:hover::after {
        animation: ripple 1s ease-out;
    }

    @keyframes ripple {
        0% {
            transform: scale(0, 0);
            opacity: 0.5;
        }

        100% {
            transform: scale(20, 20);
            opacity: 0;
        }
    }

    .trail {
        /* className for the trail elements */
        position: absolute;
        height: 6px;
        width: 6px;
        border-radius: 3px;
        background: linear-gradient(to right, #673ab7, #9c27b0);
        pointer-events: none;
        opacity: 0.7;
    }

    <?php endif;
    ?>
    </style>
</head>

<body>
    <?php if ($is_secret_role): ?>
    <!-- Particle effect container for secret role users -->
    <div id="particles-js"></div>
    <?php endif; ?>

    <div class="sidebar">
        <div class="profile">
            <a href="profile.php">
                <img src="<?php echo $profil_image; ?>" alt="Profile" onerror="this.src='./images/default-profil.png'">
            </a>
            <h3>
                <?php 
                $role_class = $user['role'] === 'secret' ? 'secret-role' : '';
                echo htmlspecialchars($user['nama_lengkap']) . ' <span class="' . $role_class . '">(' . ucfirst($user['role']) . ')</span> ' . getRoleIcon($user['role']); 
                ?>
            </h3>
        </div>
        <div class="menu">
            <a href="dashboard.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-home"></i> Dashboard</a>
            <a href="katagori.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'katagori.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>><i class="fas fa-tags"></i>
                Kategori</a>
            <a href="transaksi.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'transaksi.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>><i class="fas fa-exchange-alt"></i>
                Transaksi</a>
            <a href="laporan.php" <?php echo basename($_SERVER['PHP_SELF']) == 'laporan.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>><i class="fas fa-chart-bar"></i>
                Laporan</a>
            <?php if (in_array($user['role'], ['admin', 'coder', 'owner'])): ?>
            <a href="../admin/approve_reset.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'approve_reset.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>><i class="fas fa-check-circle"></i>
                Persetujuan Reset</a>
            <?php endif; ?>
            <?php if (in_array($user['role'], ['coder', 'owner'])): ?>
            <a href="../admin/manage_users.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>><i class="fas fa-users-cog"></i>
                Manajemen Pengguna</a>
            <?php endif; ?>
        </div>
        <a href="../logout.php" class="btn logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="profile-container">
            <div class="profile-header">
                <h1>Edit Profil</h1>
                <?php if ($is_secret_role): ?>
                <div class="secret-role-badge">
                    <i class="fas fa-user-secret"></i> Secret Role
                </div>
                <?php endif; ?>
            </div>

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

            <div class="profile-image" style="position: relative;">
                <img src="<?php echo $profil_image; ?>" alt="Profil" id="preview-image"
                    onerror="this.src='./images/default-profil.png'">
                <?php if ($is_gif_profile && $is_secret_role): ?>
                <span class="gif-badge">GIF</span>
                <?php endif; ?>
                <div class="image-overlay">
                    <label for="foto_profil" class="edit-image-btn">
                        <i class="fas fa-camera"></i>
                        Ubah Foto
                    </label>
                </div>
            </div>

            <?php if ($is_secret_role && $is_gif_profile): ?>
            <div class="gif-controls">
                <button id="pause-gif" class="btn btn-sm">
                    <i class="fas fa-pause"></i> Pause GIF
                </button>
                <button id="play-gif" class="btn btn-sm" style="display:none;">
                    <i class="fas fa-play"></i> Play GIF
                </button>
            </div>
            <?php endif; ?>

            <?php if ($is_secret_role): ?>
            <div class="gif-info">
                <i class="fas fa-info-circle"></i> Sebagai pengguna <strong>Secret</strong>, Anda dapat mengunggah
                foto profil berformat GIF animasi dan menikmati fitur khusus UI seperti efek partikel dan animasi keren
                lainnya.
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="foto_profil" id="foto_profil"
                    accept="<?php echo $is_secret_role ? 'image/*' : 'image/jpeg,image/png,image/jpg'; ?>"
                    style="display: none;">

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
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Nomor WhatsApp</label>
                    <input type="text" name="no_whatsapp"
                        value="<?php echo htmlspecialchars($user['no_whatsapp'] ?? ''); ?>"
                        placeholder="Contoh: 081234567890">
                </div>

                <button type="submit" class="btn btn-block">Simpan Perubahan</button>
            </form>
        </div>
    </div>

    <script>
    document.getElementById('foto_profil').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewImage = document.getElementById('preview-image');
                previewImage.src = e.target.result;

                // Check if it's a GIF and add the badge if needed
                const isSecret = <?php echo $is_secret_role ? 'true' : 'false'; ?>;
                const fileName = file.name.toLowerCase();

                // Remove existing GIF badge if any
                const existingBadge = document.querySelector('.gif-badge');
                if (existingBadge) {
                    existingBadge.remove();
                }

                // Add GIF badge if applicable
                if (isSecret && fileName.endsWith('.gif')) {
                    const badge = document.createElement('span');
                    badge.className = 'gif-badge';
                    badge.textContent = 'GIF';
                    previewImage.parentElement.appendChild(badge);
                }
            }
            reader.readAsDataURL(file);
        }
    });

    // GIF control functionality for secret role users
    const pauseGifBtn = document.getElementById('pause-gif');
    const playGifBtn = document.getElementById('play-gif');

    if (pauseGifBtn && playGifBtn) {
        // Store the original GIF source
        const gifImage = document.getElementById('preview-image');
        let originalSrc = gifImage.src;

        pauseGifBtn.addEventListener('click', function() {
            // Create a canvas to capture the current frame
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = gifImage.naturalWidth;
            canvas.height = gifImage.naturalHeight;
            ctx.drawImage(gifImage, 0, 0);

            // Replace GIF with static image
            gifImage.src = canvas.toDataURL('image/png');

            // Toggle buttons
            pauseGifBtn.style.display = 'none';
            playGifBtn.style.display = 'inline-block';
        });

        playGifBtn.addEventListener('click', function() {
            // Restore original animated GIF
            gifImage.src = originalSrc;

            // Toggle buttons
            playGifBtn.style.display = 'none';
            pauseGifBtn.style.display = 'inline-block';
        });
    }

    <?php if ($is_secret_role): ?>
    // Script untuk efek cursor trail (khusus role secret)
    document.addEventListener('DOMContentLoaded', function() {
        // Cursor trail effect
        const body = document.querySelector('body');
        let mouseX = 0,
            mouseY = 0;
        let trailElements = [];
        const trailLength = 20;

        for (let i = 0; i < trailLength; i++) {
            const trail = document.createElement('div');
            trail.className = 'trail';
            trail.style.opacity = (1 - i / trailLength) * 0.7;
            document.body.appendChild(trail);
            trailElements.push({
                element: trail,
                x: 0,
                y: 0
            });
        }

        window.addEventListener('mousemove', function(e) {
            mouseX = e.clientX;
            mouseY = e.clientY;
        });

        function updateTrail() {
            trailElements.forEach((trail, index) => {
                // Follow mouse with delay based on index
                const nextTrail = trailElements[index - 1] || {
                    x: mouseX,
                    y: mouseY
                };
                trail.x += (nextTrail.x - trail.x) * 0.3;
                trail.y += (nextTrail.y - trail.y) * 0.3;

                trail.element.style.left = trail.x + 'px';
                trail.element.style.top = trail.y + 'px';
            });

            requestAnimationFrame(updateTrail);
        }

        updateTrail();
    });
    <?php endif; ?>
    </script>

    <?php if ($is_secret_role): ?>
    <!-- Load particles.js library for secret role users -->
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
    // Configure particles.js
    document.addEventListener('DOMContentLoaded', function() {
        particlesJS('particles-js', {
            "particles": {
                "number": {
                    "value": 80,
                    "density": {
                        "enable": true,
                        "value_area": 800
                    }
                },
                "color": {
                    "value": "#673ab7"
                },
                "shape": {
                    "type": "circle",
                    "stroke": {
                        "width": 0,
                        "color": "#000000"
                    },
                    "polygon": {
                        "nb_sides": 5
                    }
                },
                "opacity": {
                    "value": 0.5,
                    "random": false,
                    "anim": {
                        "enable": false,
                        "speed": 1,
                        "opacity_min": 0.1,
                        "sync": false
                    }
                },
                "size": {
                    "value": 3,
                    "random": true,
                    "anim": {
                        "enable": false,
                        "speed": 40,
                        "size_min": 0.1,
                        "sync": false
                    }
                },
                "line_linked": {
                    "enable": true,
                    "distance": 150,
                    "color": "#673ab7",
                    "opacity": 0.4,
                    "width": 1
                },
                "move": {
                    "enable": true,
                    "speed": 2,
                    "direction": "none",
                    "random": false,
                    "straight": false,
                    "out_mode": "out",
                    "bounce": false,
                    "attract": {
                        "enable": false,
                        "rotateX": 600,
                        "rotateY": 1200
                    }
                }
            },
            "interactivity": {
                "detect_on": "canvas",
                "events": {
                    "onhover": {
                        "enable": true,
                        "mode": "repulse"
                    },
                    "onclick": {
                        "enable": true,
                        "mode": "push"
                    },
                    "resize": true
                },
                "modes": {
                    "grab": {
                        "distance": 140,
                        "line_linked": {
                            "opacity": 1
                        }
                    },
                    "bubble": {
                        "distance": 400,
                        "size": 40,
                        "duration": 2,
                        "opacity": 8,
                        "speed": 3
                    },
                    "repulse": {
                        "distance": 100,
                        "duration": 0.4
                    },
                    "push": {
                        "particles_nb": 4
                    },
                    "remove": {
                        "particles_nb": 2
                    }
                }
            },
            "retina_detect": true
        });
    });

    // Easter egg: Konami code for secret role users
    let konamiCode = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft',
        'ArrowRight', 'b', 'a'
    ];
    let konamiPosition = 0;

    document.addEventListener('keydown', function(e) {
        // Check if the pressed key matches the next key in the Konami Code
        if (e.key === konamiCode[konamiPosition]) {
            konamiPosition++;

            // If the entire code is entered correctly
            if (konamiPosition === konamiCode.length) {
                // Reset the position for future use
                konamiPosition = 0;

                // Trigger the Easter egg - matrix rain effect
                activateMatrixRain();
            }
        } else {
            // Reset if a wrong key is pressed
            konamiPosition = 0;
        }
    });

    function activateMatrixRain() {
        alert('Kode Rahasia Diaktifkan: Matrix Mode!');

        // Create canvas for matrix effect
        const canvas = document.createElement('canvas');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        canvas.style.position = 'fixed';
        canvas.style.top = '0';
        canvas.style.left = '0';
        canvas.style.zIndex = '-1';
        canvas.style.opacity = '0.8';
        document.body.appendChild(canvas);

        const ctx = canvas.getContext('2d');

        // Matrix characters
        const matrix = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ123456789@#$%^&*()*&^%+-/~{[|`]}";

        // Making the canvas full screen
        canvas.height = window.innerHeight;
        canvas.width = window.innerWidth;

        // Characters on the canvas
        const columns = canvas.width / 20; // Font size

        // Array for drops - one per column
        const drops = [];

        // x below is the x coordinate
        // 1 = y coordinate of the drop (same for every drop initially)
        for (let x = 0; x < columns; x++) {
            drops[x] = 1;
        }

        // Drawing the characters
        function draw() {
            // Translucent BG to show trail
            ctx.fillStyle = "rgba(0, 0, 0, 0.04)";
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            ctx.fillStyle = "#673ab7"; // Purple text
            ctx.font = "15px monospace";

            // Loop over drops
            for (let i = 0; i < drops.length; i++) {
                // Random character to print
                const text = matrix[Math.floor(Math.random() * matrix.length)];

                // x = i*20, y = value of drops[i]*20
                ctx.fillText(text, i * 20, drops[i] * 20);

                // Incrementing Y coordinate
                if (drops[i] * 20 > canvas.height && Math.random() > 0.975) {
                    drops[i] = 0;
                }

                // Incrementing Y coordinate
                drops[i]++;
            }
        }

        setInterval(draw, 35);

        // Auto-remove matrix effect after 20 seconds
        setTimeout(function() {
            canvas.remove();
        }, 20000);
    }
    </script>
    <?php endif; ?>
</body>

</html>