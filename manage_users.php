<?php
require_once 'config/config.php';

// Set zona waktu agar konsisten
date_default_timezone_set('Asia/Jakarta');

startSession();
requireLogin();

// Pastikan pengguna memiliki role yang valid
if (!isset($_SESSION['role'])) {
    $_SESSION['error'] = "Role tidak terdefinisi. Silakan login kembali.";
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = strtolower(trim($_SESSION['role'])); // Normalisasi role dari session

// Periksa dan bersihkan sanksi kadaluarsa untuk pengguna saat ini
checkAndClearExpiredSanctions($conn, $user_id);

// Verifikasi role dari database
$query = "SELECT role, nama_lengkap, foto_profil, warning_count, ban_status, ban_expiry, warning_expiry FROM users WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    $_SESSION['error'] = "Gagal menyiapkan query: " . mysqli_error($conn);
    header("Location: login.php");
    exit();
}
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    $_SESSION['error'] = "Data pengguna tidak valid.";
    header("Location: login.php");
    exit();
}

// Normalisasi role dari database
$db_role = strtolower(trim($user['role']));

// Jika role tidak cocok, update session agar konsisten
if ($db_role !== $role) {
    $_SESSION['role'] = $db_role; // Sinkronisasi session dengan database
    $role = $db_role;
}

// Tentukan akses fitur berdasarkan role
$can_access_features = in_array($role, ['owner', 'coder']);

// Cek apakah pengguna bisa mengakses fitur ini
if (!$can_access_features) {
    $_SESSION['error'] = "Anda tidak memiliki izin untuk mengakses halaman ini.";
    header("Location: dashboard.php");
    exit();
}

// Ambil data pengguna (kecuali owner) dengan waktu kadaluarsa
$users_query = "SELECT u.user_id, u.nama_lengkap, u.role, u.warning_count, u.ban_status, u.ban_expiry, u.warning_expiry,
                COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'pemasukan' THEN t.jumlah ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'pengeluaran' THEN t.jumlah ELSE 0 END), 0) AS saldo
                FROM users u LEFT JOIN transaksi t ON u.user_id = t.user_id
                WHERE u.role != 'owner'
                GROUP BY u.user_id";
$users_result = mysqli_query($conn, $users_query);
if (!$users_result) {
    $_SESSION['error'] = "Gagal mengambil data pengguna: " . mysqli_error($conn);
    header("Location: dashboard.php");
    exit();
}

// Proses POST request untuk owner/coder
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    if (!$target_user_id) {
        $_SESSION['error'] = "ID pengguna tidak valid.";
        header("Location: manage_users.php");
        exit();
    }

    if (isset($_POST['change_role'])) {
        $new_role = strtolower(trim($_POST['new_role'] ?? ''));
        $valid_roles = ['user', 'admin', 'coder'];
        if (!in_array($new_role, $valid_roles)) {
            $_SESSION['error'] = "Role tidak valid.";
        } else {
            $update_query = "UPDATE users SET role = ? WHERE user_id = ? AND role != 'owner'";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "si", $new_role, $target_user_id);
            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
                $_SESSION['success'] = "Role diubah menjadi $new_role.";
            } else {
                $_SESSION['error'] = "Gagal mengubah role atau pengguna adalah owner.";
            }
            mysqli_stmt_close($stmt);
        }
    } elseif (isset($_POST['manage_account'])) {
        $action = $_POST['action'] ?? '';
        $duration_value = filter_input(INPUT_POST, 'duration_value', FILTER_VALIDATE_INT);
        $duration_unit = $_POST['duration_unit'] ?? '';

        $check_query = "SELECT role, nama_lengkap, warning_count, ban_status, warning_expiry FROM users WHERE user_id = ? AND role != 'owner'";
        $stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($stmt, "i", $target_user_id);
        mysqli_stmt_execute($stmt);
        $target_user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$target_user) {
            $_SESSION['error'] = "Pengguna tidak ditemukan atau adalah owner.";
        } elseif ($action === 'warn') {
            if (!$duration_value || !$duration_unit) {
                $_SESSION['error'] = "Durasi peringatan harus diisi (nilai dan satuan).";
                header("Location: manage_users.php");
                exit();
            }

            $new_warning_count = $target_user['warning_count'] + 1;
            $message = "Peringatan ke-$new_warning_count diberikan kepada " . $target_user['nama_lengkap'] . ".";

            $units = ['minutes' => 60, 'hours' => 3600, 'days' => 86400, 'months' => 2592000, 'years' => 31536000];
            if (isset($units[$duration_unit])) {
                $warning_duration = $duration_value * $units[$duration_unit];
                $warning_expiry = date('Y-m-d H:i:s', time() + $warning_duration);
            } else {
                $_SESSION['error'] = "Satuan durasi tidak valid.";
                header("Location: manage_users.php");
                exit();
            }

            if ($new_warning_count >= 3) {
                $update_query = "UPDATE users SET warning_count = ?, ban_status = 'banned', ban_expiry = NULL, warning_expiry = NULL WHERE user_id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "ii", $new_warning_count, $target_user_id);
                $message = "Akun " . $target_user['nama_lengkap'] . " diban permanen karena 3 peringatan.";
            } else {
                $update_query = "UPDATE users SET warning_count = ?, warning_expiry = ? WHERE user_id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "isi", $new_warning_count, $warning_expiry, $target_user_id);
            }
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = $message . " Peringatan akan kadaluarsa pada " . date('d-m-Y H:i', strtotime($warning_expiry));
            } else {
                $_SESSION['error'] = "Gagal memberikan peringatan: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } elseif ($action === 'ban') {
            if (!$duration_value || !$duration_unit) {
                $_SESSION['error'] = "Durasi ban harus diisi (nilai dan satuan).";
                header("Location: manage_users.php");
                exit();
            }

            $units = ['minutes' => 60, 'hours' => 3600, 'days' => 86400, 'months' => 2592000, 'years' => 31536000];
            if (isset($units[$duration_unit])) {
                $seconds = $duration_value * $units[$duration_unit];
                $ban_expiry = date('Y-m-d H:i:s', time() + $seconds);
                $update_query = "UPDATE users SET ban_status = 'banned', ban_expiry = ?, warning_count = 0, warning_expiry = NULL WHERE user_id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "si", $ban_expiry, $target_user_id);
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['success'] = "Akun diban hingga " . date('d-m-Y H:i', strtotime($ban_expiry)) . ". Peringatan direset.";
                } else {
                    $_SESSION['error'] = "Gagal memban akun: " . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            } else {
                $_SESSION['error'] = "Satuan durasi tidak valid.";
                header("Location: manage_users.php");
                exit();
            }
        } elseif ($action === 'remove_warn') {
            $update_query = "UPDATE users SET warning_count = 0, warning_expiry = NULL WHERE user_id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "i", $target_user_id);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "Peringatan untuk " . $target_user['nama_lengkap'] . " telah dicabut.";
            } else {
                $_SESSION['error'] = "Gagal mencabut peringatan: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } elseif ($action === 'remove_ban') {
            $update_query = "UPDATE users SET ban_status = 'active', ban_expiry = NULL WHERE user_id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "i", $target_user_id);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "Ban untuk " . $target_user['nama_lengkap'] . " telah dicabut.";
            } else {
                $_SESSION['error'] = "Gagal mencabut ban: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
    header("Location: manage_users.php");
    exit();
}

// Hindari redefinisi fungsi getTimeRemaining jika sudah ada di config.php
if (!function_exists('getTimeRemaining')) {
    function getTimeRemaining($expiry) {
        $now = time();
        $expiryTime = strtotime($expiry);
        if ($expiryTime <= $now) {
            return 'Kadaluarsa';
        }
        $timeRemaining = $expiryTime - $now;
        $days = floor($timeRemaining / (24 * 60 * 60));
        $hours = floor(($timeRemaining % (24 * 60 * 60)) / (60 * 60));
        $minutes = floor(($timeRemaining % (60 * 60)) / 60);
        $seconds = $timeRemaining % 60;
        return "$days hari, $hours jam, $minutes menit, $seconds detik";
    }
}

function getRoleIcon($role) {
    $icons = ['owner' => 'crown', 'coder' => 'code', 'admin' => 'user-shield', 'user' => 'user'];
    return '<i class="fas fa-' . ($icons[strtolower($role)] ?? 'user') . '"></i>';
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Pengguna</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/manage_users.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
    <style>
    .content-wrapper {
        margin-left: 250px;
        padding: 20px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .data-table th,
    .data-table td {
        padding: 10px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }

    .data-table th {
        background: #f4f4f4;
    }

    .btn {
        padding: 5px 10px;
        background: #007bff;
        color: white;
        border: none;
        cursor: pointer;
    }

    .btn:hover {
        background: #0056b3;
    }

    .alert {
        padding: 10px;
        margin-bottom: 15px;
        border-radius: 5px;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
    }

    .alert-danger {
        background: #f8d7da;
        color: #721c24;
    }

    .alert-warning {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
    }

    select,
    input[type="number"] {
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 5px;
    }

    form {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .timer {
        font-weight: bold;
        color: #c0392b;
        margin-left: 10px;
    }

    .disabled-link {
        pointer-events: none;
        opacity: 0.5;
    }
    </style>
    <script>
    function updateTimer() {
        const timers = document.querySelectorAll('.timer');
        timers.forEach(timer => {
            const expiry = timer.getAttribute('data-expiry');
            if (expiry) {
                const expiryTime = new Date(expiry).getTime();
                const now = new Date().getTime();
                let timeRemaining = Math.max(0, Math.floor((expiryTime - now) / 1000));
                if (timeRemaining <= 0) {
                    timer.textContent = 'Kadaluarsa';
                    return;
                }
                const days = Math.floor(timeRemaining / (24 * 60 * 60));
                const hours = Math.floor((timeRemaining % (24 * 60 * 60)) / (60 * 60));
                const minutes = Math.floor((timeRemaining % (60 * 60)) / 60);
                const seconds = timeRemaining % 60;
                timer.textContent = `${days} hari, ${hours} jam, ${minutes} menit, ${seconds} detik`;
            }
        });
        setTimeout(updateTimer, 1000);
    }
    window.onload = function() {
        updateTimer();
    };
    </script>
</head>

<body>
    <div class="sidebar">
        <div class="profile">
            <a href="profile.php">
                <img src="<?php echo $user['foto_profil'] ? './uploads/profil/' . $user['foto_profil'] : './images/default-profil.png'; ?>"
                    alt="Profile">
            </a>
            <h3><?php echo htmlspecialchars($user['nama_lengkap']) . ' (' . ucfirst($role) . ') ' . getRoleIcon($role); ?>
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
            <a href="approve_reset.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'approve_reset.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>><i class="fas fa-check-circle"></i>
                Persetujuan Reset</a>
            <?php endif; ?>
            <?php if (in_array($user['role'], ['coder', 'owner'])): ?>
            <a href="manage_users.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>><i class="fas fa-users-cog"></i>
                Manajemen Pengguna</a>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="btn logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <div class="content-wrapper">
        <h2>Manajemen Pengguna</h2>
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Role</th>
                    <th>Saldo</th>
                    <th>Peringatan</th>
                    <th>Waktu Kadaluarsa Peringatan</th>
                    <th>Status Ban</th>
                    <th>Waktu Kadaluarsa Ban</th>
                    <th>Aksi Role</th>
                    <th>Aksi Ban/Peringatan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($users_result) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($users_result)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['nama_lengkap']); ?></td>
                    <td><?php echo ucfirst($row['role']); ?></td>
                    <td>Rp <?php echo number_format($row['saldo'], 0, ',', '.'); ?></td>
                    <td><?php echo $row['warning_count']; ?>/3</td>
                    <td>
                        <?php if ($row['warning_expiry']): ?>
                        <span class="timer" data-expiry="<?php echo htmlspecialchars($row['warning_expiry']); ?>">
                            <?php echo getTimeRemaining($row['warning_expiry']); ?>
                        </span>
                        <?php else: ?>
                        Tidak ada
                        <?php endif; ?>
                    </td>
                    <td><?php echo $row['ban_status'] === 'banned' ? "Diban" : "Aktif"; ?></td>
                    <td>
                        <?php if ($row['ban_expiry']): ?>
                        <span class="timer" data-expiry="<?php echo htmlspecialchars($row['ban_expiry']); ?>">
                            <?php echo getTimeRemaining($row['ban_expiry']); ?>
                        </span>
                        <?php else: ?>
                        Tidak ada
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                            <select name="new_role">
                                <option value="user" <?php echo $row['role'] === 'user' ? 'selected' : ''; ?>>User
                                </option>
                                <option value="admin" <?php echo $row['role'] === 'admin' ? 'selected' : ''; ?>>Admin
                                </option>
                                <option value="coder" <?php echo $row['role'] === 'coder' ? 'selected' : ''; ?>>Coder
                                </option>
                            </select>
                            <button type="submit" name="change_role" class="btn">Ubah</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                            <select name="action">
                                <option value="warn">Peringatan</option>
                                <option value="ban">Ban</option>
                                <option value="remove_warn">Cabut Peringatan</option>
                                <option value="remove_ban">Cabut Ban</option>
                            </select>
                            <input type="number" name="duration_value" min="1" placeholder="Durasi" required
                                style="width: 80px;">
                            <select name="duration_unit" required>
                                <option value="minutes">Menit</option>
                                <option value="hours">Jam</option>
                                <option value="days">Hari</option>
                                <option value="months">Bulan</option>
                                <option value="years">Tahun</option>
                            </select>
                            <button type="submit" name="manage_account" class="btn">Terapkan</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="9">Tidak ada pengguna.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>

</html>