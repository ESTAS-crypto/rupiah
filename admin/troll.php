<?php
require_once '../config/config.php';
require_once '../config/auth_check.php';
require_once '../config/session_check.php';

startSession();
requireLogin();

if (!in_array($_SESSION['role'], ['admin', 'coder', 'owner'])) {
    $_SESSION['error'] = "Anda tidak memiliki izin untuk mengakses halaman ini.";
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Daftar jenis troll yang tersedia
$troll_types = [
    'message' => 'Pesan Lucu',
    'theme' => 'Ubah Tema',
    'redirect' => 'Redirect Acak',
    'invert' => 'Invert Warna',
    'slow' => 'Lambat',
    'sound' => 'Suara Lucu',
    'cursor' => 'Jejak Kursor'
];

// Ambil daftar pengguna yang bisa ditroll (kecuali admin, coder, owner)
$users_query = "SELECT user_id, nama_lengkap FROM users WHERE role NOT IN ('admin', 'owner', 'coder')";
$users_result = mysqli_query($conn, $users_query);

// Proses pengiriman troll
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_user_id = filter_input(INPUT_POST, 'target_user_id', FILTER_VALIDATE_INT);
    $troll_type = $_POST['troll_type'];
    $troll_message = trim($_POST['troll_message']);
    $expiry_days = filter_input(INPUT_POST, 'expiry_days', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1, 'max_range' => 7]]);
    $notification_duration = filter_input(INPUT_POST, 'notification_duration', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 3600]]); // Maks 1 jam

    if (!$target_user_id || empty($troll_type) || ($troll_type === 'message' && empty($troll_message))) {
        $_SESSION['error'] = "Data troll tidak lengkap.";
    } else {
        $expiry = date('Y-m-d H:i:s', strtotime("+$expiry_days days"));
        $insert_query = "INSERT INTO trolls (target_user_id, troll_type, troll_message, created_at, expiry, notification_duration) VALUES (?, ?, ?, NOW(), ?, ?)";
        $stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($stmt, "isssi", $target_user_id, $troll_type, $troll_message, $expiry, $notification_duration);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Troll berhasil dikirim!";
        } else {
            $_SESSION['error'] = "Gagal mengirim troll: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
    header("Location: troll.php");
    exit();
}

$user_id = $_SESSION['user_id'];
try {
    $user_query = "SELECT * FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $user_result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($user_result);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$is_secret_role = strtolower($user['role']) === 'secret';

function getRoleIcon($role) {
    $icons = [
        'owner' => 'crown',
        'coder' => 'code',
        'admin' => 'user-shield',
        'user' => 'user',
        'secret' => 'user-secret'
    ];
    return '<i class="fas fa-' . ($icons[strtolower($role)] ?? 'user') . '"></i>';
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Troll Pengguna</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/troll.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="image/png" />
    <?php if ($is_secret_role): ?>
    <link rel="stylesheet" href="../css/secret-role.css">
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="../js/secret-role.js"></script>
    <?php endif; ?>
</head>

<body class="<?php echo $is_secret_role ? 'secret-role-body' : ''; ?>">
    <?php if ($is_secret_role): ?>
    <div id="particles-js"></div>
    <?php endif; ?>

    <div class="sidebar">
        <div class="profile">
            <a href="profile.php">
                <img src="<?php echo !empty($user['foto_profil']) ? '../uploads/profil/' . $user['foto_profil'] : './images/default-profil.png'; ?>"
                    alt="Profile">
            </a>
            <h3>
                <?php 
                $role_class = $is_secret_role ? 'secret-role' : '';
                echo htmlspecialchars($user['nama_lengkap']) . ' <span class="' . $role_class . '">(' . ucfirst($user['role']) . ')</span> ' . getRoleIcon($user['role']); 
                ?>
            </h3>
        </div>
        <div class="menu">
            <a href="../dashboard/dashboard.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="../dashboard/katagori.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'katagori.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-tags"></i> Kategori
            </a>
            <a href="../dashboard/transaksi.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'transaksi.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-exchange-alt"></i> Transaksi
            </a>
            <a href="../dashboard/laporan.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'laporan.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-chart-bar"></i> Laporan
            </a>
            <?php if (in_array($user['role'], ['admin', 'coder', 'owner'])): ?>
            <a href="../admin/approve_reset.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'approve_reset.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-check-circle"></i> Persetujuan Reset
            </a>
            <?php endif; ?>
            <?php if (in_array($user['role'], ['coder', 'owner'])): ?>
            <a href="../admin/manage_users.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-users-cog"></i> Manajemen Pengguna
            </a>
            <a href="troll.php" <?php echo basename($_SERVER['PHP_SELF']) == 'troll.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-skull-crossbones"></i> Troll
            </a>
            <?php endif; ?>
        </div>
        <a href="../logout.php" class="btn logout-btn <?php echo $is_secret_role ? 'secret-role-btn' : ''; ?>">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <div class="content-wrapper">
            <h2>Troll Pengguna</h2>
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <form method="POST">
                <label for="target_user_id">Pilih Pengguna:</label>
                <select name="target_user_id" id="target_user_id" required>
                    <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                    <option value="<?php echo $user['user_id']; ?>">
                        <?php echo htmlspecialchars($user['nama_lengkap']); ?></option>
                    <?php endwhile; ?>
                </select>
                <label for="troll_type">Jenis Troll:</label>
                <select name="troll_type" id="troll_type" required>
                    <?php foreach ($troll_types as $key => $label): ?>
                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="troll_message">Pesan Troll (opsional):</label>
                <textarea name="troll_message" id="troll_message"></textarea>
                <label for="troll_duration_value">Durasi Troll:</label>
                <input type="number" name="troll_duration_value" id="troll_duration_value" min="1" placeholder="Nilai"
                    required>
                <select name="troll_duration_unit" id="troll_duration_unit" required>
                    <option value="seconds">Detik</option>
                    <option value="minutes">Menit</option>
                    <option value="hours">Jam</option>
                    <option value="days">Hari</option>
                </select>
                <button type="submit" class="btn">Kirim Troll</button>
            </form>
        </div>
    </div>
</body>

</html>