<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['loading_shown']) || $_SESSION['loading_shown'] !== true) {
    header("Location: ../loading.php");
    exit();
}

$user_id = $_SESSION['user_id'];
checkAndClearExpiredSanctions($conn, $user_id);

try {
    $user_query = "SELECT * FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $user_result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($user_result);
    mysqli_stmt_close($stmt);

    if ($user['ban_status'] === 'banned') {
        if ($user['ban_expiry'] === NULL) {
            $_SESSION['error'] = "Akun Anda telah diban permanen.";
        } elseif (strtotime($user['ban_expiry']) > time()) {
            $_SESSION['error'] = "Akun Anda diban hingga " . date('d-m-Y H:i:s', strtotime($user['ban_expiry'])) . ".";
        } else {
            $_SESSION['success'] = "Status ban Anda telah dihapus.";
        }
        if (isset($_SESSION['error'])) {
            header("Location: login.php");
            exit();
        }
    }

    $warning_count = $user['warning_count'];
    $warning_message = null;
    if ($warning_count > 0) {
        $warning_message = "Anda memiliki " . $warning_count . " peringatan. ";
        if ($warning_count >= 3) {
            $warning_message .= "Akun Anda telah diban permanen.";
            header("Location: login.php?msg=account_banned");
            exit();
        } elseif ($warning_count >= 2) {
            $warning_message .= "Fitur transaksi dan laporan dibatasi hingga: ";
            $warning_message .= "<span class='timer' data-expiry='" . htmlspecialchars($user['warning_expiry']) . "'>" . getTimeRemaining($user['warning_expiry']) . "</span>";
        } elseif ($warning_count == 1) {
            $warning_message .= "Jika mencapai 3 peringatan, fitur akan dibatasi: ";
            $warning_message .= "<span class='timer' data-expiry='" . htmlspecialchars($user['warning_expiry']) . "'>" . getTimeRemaining($user['warning_expiry']) . "</span>";
        }
    }

    $can_access_features = canAccessFeature($warning_count);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$is_secret_role = strtolower($user['role']) === 'secret';

// Notifikasi khusus untuk role admin, coder, owner, dan secret
$notification = '';
$notification_title = '';
$notification_message = '';
$notification_details = '';

if (in_array($user['role'], ['admin', 'coder', 'owner', 'secret', 'user'])) {
    $notification_title = ucfirst($user['role']) . " Role";
    $notification_message = "Selamat datang, " . strtolower($user['role']) . "! Anda memiliki hak akses khusus sebagai " . ucfirst($user['role']) . ".";

    switch ($user['role']) {
        case 'admin':
            $notification_details = "Sebagai Admin, Anda dapat mengelola persetujuan reset akun dan memantau aktivitas pengguna.";
            break;
        case 'coder':
            $notification_details = "Sebagai Coder, Anda memiliki akses untuk mengelola pengguna dan mengembangkan fitur baru.";
            break;
        case 'owner':
            $notification_details = "Sebagai Owner, Anda memiliki kontrol penuh atas sistem.";
            break;
        case 'secret':
            $notification_details = "Sebagai Secret, Anda dapat menikmati fitur eksklusif.";
            break;
        case 'user':
            $notification_details = "Sebagai User, Anda memiliki akses penuh untuk mengelola keuangan pribadi Anda.";
            break;
    }
}

// Cek apakah notifikasi sudah ditampilkan
if (!isset($_SESSION['notification_shown']) || $_SESSION['notification_shown'] !== true) {
    $_SESSION['notification_shown'] = true;
    $show_notification = true;
} else {
    $show_notification = false;
}

// Ambil notifikasi terbaru
$notif_query = "SELECT message, created_at FROM notifications WHERE user_id = ? AND is_read = 0 AND (expiry IS NULL OR expiry > NOW()) ORDER BY created_at DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $notif_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$notif_result = mysqli_stmt_get_result($stmt);
$latest_notif = mysqli_fetch_assoc($notif_result);
mysqli_stmt_close($stmt);

try {
    $today = date('Y-m-d', strtotime('2025-05-14'));
    $pemasukan_query = "SELECT COALESCE(SUM(jumlah), 0) as total FROM transaksi WHERE user_id = ? AND jenis_transaksi = 'pemasukan' AND tanggal <= ?";
    $stmt = mysqli_prepare($conn, $pemasukan_query);
    mysqli_stmt_bind_param($stmt, "is", $user_id, $today);
    mysqli_stmt_execute($stmt);
    $pemasukan_result = mysqli_stmt_get_result($stmt);
    $pemasukan = mysqli_fetch_assoc($pemasukan_result);

    $pengeluaran_query = "SELECT COALESCE(SUM(jumlah), 0) as total FROM transaksi WHERE user_id = ? AND jenis_transaksi = 'pengeluaran' AND tanggal <= ?";
    $stmt = mysqli_prepare($conn, $pengeluaran_query);
    mysqli_stmt_bind_param($stmt, "is", $user_id, $today);
    mysqli_stmt_execute($stmt);
    $pengeluaran_result = mysqli_stmt_get_result($stmt);
    $pengeluaran = mysqli_fetch_assoc($pengeluaran_result);

    $saldo = $pemasukan['total'] - $pengeluaran['total'];
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

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

try {
    $transaksi_query = "SELECT t.*, k.nama_kategori FROM transaksi t LEFT JOIN kategori k ON t.kategori_id = k.kategori_id WHERE t.user_id = ? AND t.tanggal <= ? ORDER BY t.tanggal DESC LIMIT 5";
    $stmt = mysqli_prepare($conn, $transaksi_query);
    mysqli_stmt_bind_param($stmt, "is", $user_id, $today);
    mysqli_stmt_execute($stmt);
    $transaksi_result = mysqli_stmt_get_result($stmt);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename=Dashboard_Keuangan_' . date('d-m-Y') . '.xls');
    header('Cache-Control: max-age=0');
?>
<table border="1">
    <tr>
        <th colspan="5">DASHBOARD KEUANGAN</th>
    </tr>
    <tr>
        <th colspan="5">Periode: Hingga <?php echo date('d F Y', strtotime($today)); ?></th>
    </tr>
    <tr>
        <th>Total Pemasukan</th>
        <td colspan="4">Rp <?php echo number_format($pemasukan['total'], 0, ',', '.'); ?></td>
    </tr>
    <tr>
        <th>Total Pengeluaran</th>
        <td colspan="4">Rp <?php echo number_format($pengeluaran['total'], 0, ',', '.'); ?></td>
    </tr>
    <tr>
        <th>Saldo</th>
        <td colspan="4">Rp <?php echo number_format($saldo, 0, ',', '.'); ?></td>
    </tr>
    <tr>
        <th colspan="5">TRANSAKSI TERAKHIR</th>
    </tr>
    <tr>
        <th>Tanggal</th>
        <th>Kategori</th>
        <th>Deskripsi</th>
        <th>Jenis</th>
        <th>Jumlah</th>
    </tr>
    <?php while($transaksi = mysqli_fetch_assoc($transaksi_result)): ?>
    <tr>
        <td><?php echo date('d/m/Y', strtotime($transaksi['tanggal'])); ?></td>
        <td><?php echo $transaksi['nama_kategori'] ?? 'Tanpa Kategori'; ?></td>
        <td><?php echo $transaksi['deskripsi'] ?? '-'; ?></td>
        <td><?php echo ucfirst($transaksi['jenis_transaksi']); ?></td>
        <td><?php if($transaksi['jenis_transaksi'] == 'pengeluaran') echo '-'; ?>Rp
            <?php echo number_format($transaksi['jumlah'], 0, ',', '.'); ?></td>
    </tr>
    <?php endwhile; ?>
</table>
<?php
    exit;
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Dashboard - Manajemen Keuangan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="image/png" />
    <?php if ($is_secret_role): ?>
    <link rel="stylesheet" href="../css/secret-role.css">
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="../js/secret-role.js"></script>
    <?php endif; ?>
    <style>
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
    }

    .modal-content {
        background-color: #fefefe;
        margin: 15% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 80%;
        max-width: 500px;
        border-radius: 10px;
        text-align: center;
    }

    .modal-header {
        background-color: #f0ad4e;
        color: white;
        padding: 10px;
        border-top-left-radius: 10px;
        border-top-right-radius: 10px;
    }

    .modal-body {
        padding: 20px;
    }

    .modal-footer {
        padding: 10px;
    }

    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
    }

    .close:hover,
    .close:focus {
        color: black;
        text-decoration: none;
        cursor: pointer;
    }

    .btn-primary {
        background-color: #007bff;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }

    .btn-primary:hover {
        background-color: #0056b3;
    }

    .notification-box {
        background: #fff;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        position: relative;
    }

    .notification-box h3 {
        color: #2c3e50;
        margin-bottom: 10px;
    }

    .notification-box p {
        margin: 0;
        color: #34495e;
    }

    .notification-box .timestamp {
        font-size: 12px;
        color: #7f8c8d;
        margin-top: 5px;
    }

    .notification-box button {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #e67e22;
        color: #fff;
        border: none;
        padding: 5px 10px;
        border-radius: 5px;
        cursor: pointer;
    }

    .notification-box button:hover {
        background: #d35400;
    }

    .alert-success {
        background: #27ae60;
        color: #fff;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }

    .alert-danger {
        background: #c0392b;
        color: #fff;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }

    .troll-message {
        background: #ffeb3b;
        padding: 10px;
        border: 2px solid #f57f17;
        color: #d84315;
        font-weight: bold;
        text-align: center;
        border-radius: 5px;
        margin: 10px 0;
    }
    </style>
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
            <a href="dashboard.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="katagori.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'katagori.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>>
                <i class="fas fa-tags"></i> Kategori
            </a>
            <a href="transaksi.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'transaksi.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>>
                <i class="fas fa-exchange-alt"></i> Transaksi
            </a>
            <a href="laporan.php" <?php echo basename($_SERVER['PHP_SELF']) == 'laporan.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>>
                <i class="fas fa-chart-bar"></i> Laporan
            </a>
            <?php if (in_array($user['role'], ['admin', 'coder', 'owner'])): ?>
            <a href="../admin/approve_reset.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'approve_reset.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>>
                <i class="fas fa-check-circle"></i> Persetujuan Reset
            </a>
            <?php endif; ?>
            <?php if (in_array($user['role'], ['coder', 'owner'])): ?>
            <a href="../admin/manage_users.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'class="active"' : ''; ?>
                <?php echo !$can_access_features ? 'class="disabled-link"' : ''; ?>>
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

    <div id="calculator-logo" style="position: absolute; cursor: move;">
        <img src="../images/calculator.png?v=1" alt="Calculator" width="50" height="50"
            onerror="this.src='./images/default-calculator.png';">
    </div>

    <?php include 'calcu.php'; ?>

    <?php
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $current_time = date('Y-m-d H:i:s');

        // Hapus troll yang sudah kadaluarsa
        $delete_query = "DELETE FROM trolls WHERE target_user_id = ? AND expiry < ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "is", $user_id, $current_time);
        mysqli_stmt_execute($delete_stmt);
        mysqli_stmt_close($delete_stmt);

        // Ambil troll aktif
        $troll_query = "SELECT troll_type, troll_message, expiry, notification_duration FROM trolls WHERE target_user_id = ? AND (expiry IS NULL OR expiry > ?)";
        $stmt = mysqli_prepare($conn, $troll_query);
        mysqli_stmt_bind_param($stmt, "is", $user_id, $current_time);
        mysqli_stmt_execute($stmt);
        $troll_result = mysqli_stmt_get_result($stmt);
        $active_trolls = [];
        while ($troll = mysqli_fetch_assoc($troll_result)) {
            $active_trolls[] = $troll;
        }
        mysqli_stmt_close($stmt);

        // Logging untuk debugging
        error_log("Active trolls for user $user_id: " . print_r($active_trolls, true));

        foreach ($active_trolls as $troll) {
            $troll_type = $troll['troll_type'];
            $troll_message = $troll['troll_message'];
            $notification_duration = $troll['notification_duration'] ?? 10; // Default 10 detik

            switch ($troll_type) {
                case 'message':
                    if (!empty($troll_message)) {
                        echo "<div class='troll-message' data-duration='$notification_duration'>" . htmlspecialchars($troll_message) . "</div>";
                    }
                    break;
                case 'theme':
                    echo "<style>body { background-color: #000; color: #fff; }</style>";
                    break;
                case 'redirect':
                    if (rand(1, 10) == 1 && (!isset($_SESSION['last_redirect']) || time() - $_SESSION['last_redirect'] > 60)) {
                        $_SESSION['last_redirect'] = time();
                        header("Location: https://www.example.com/funny-page");
                        exit();
                    }
                    break;
                case 'invert':
                    echo "<style>body { filter: invert(1); }</style>";
                    break;
                case 'slow':
                    usleep(500000); // Delay 0.5 detik
                    break;
                case 'sound':
                    echo "<audio autoplay><source src='../sounds/funny-sound.mp3' type='audio/mpeg'></audio>";
                    break;
                case 'cursor':
                    echo "<script>document.body.style.cursor = 'url(../images/cursor-trail.png), auto';</script>";
                    break;
            }
        }
    }
    ?>

    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1 class="<?php echo $is_secret_role ? 'secret-role' : ''; ?>">Dashboard Keuangan</h1>
                <p>Selamat datang kembali, <span
                        class="<?php echo $is_secret_role ? 'secret-role' : ''; ?>"><?php echo htmlspecialchars($user['nama_lengkap']); ?></span>
                </p>
            </div>
            <div class="btn-group">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="export" value="excel">
                    <button type="submit"
                        class="btn btn-success <?php echo $is_secret_role ? 'secret-role-btn glow-on-hover' : ''; ?>"
                        <?php echo !$can_access_features ? 'disabled' : ''; ?>>
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </form>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <?php if (isset($warning_message)): ?>
        <div class="alert alert-warning"><?php echo $warning_message; ?></div>
        <?php endif; ?>

        <?php if ($latest_notif): ?>
        <div class="notification-box">
            <h3>Pemberitahuan</h3>
            <p><?php echo htmlspecialchars($latest_notif['message']); ?></p>
            <div class="timestamp"><?php echo date('d-m-Y H:i', strtotime($latest_notif['created_at'])); ?></div>
            <button onclick="markAsRead()">Tandai sebagai Dibaca</button>
        </div>
        <?php endif; ?>

        <div class="summary-cards">
            <div class="card <?php echo $is_secret_role ? 'glow-on-hover' : ''; ?>">
                <div class="card-title"><i class="fas fa-arrow-up"></i> Total Pemasukan Bulan Ini</div>
                <div class="card-amount pemasukan">Rp <?php echo number_format($pemasukan['total'], 0, ',', '.'); ?>
                </div>
            </div>
            <div class="card <?php echo $is_secret_role ? 'glow-on-hover' : ''; ?>">
                <div class="card-title"><i class="fas fa-arrow-down"></i> Total Pengeluaran Bulan Ini</div>
                <div class="card-amount pengeluaran">Rp <?php echo number_format($pengeluaran['total'], 0, ',', '.'); ?>
                </div>
            </div>
            <div class="card <?php echo $is_secret_role ? 'glow-on-hover' : ''; ?>">
                <div class="card-title"><i class="fas fa-wallet"></i> Saldo</div>
                <div class="card-amount">Rp <?php echo number_format($saldo, 0, ',', '.'); ?></div>
            </div>
        </div>

        <div class="recent-transactions">
            <h2 class="<?php echo $is_secret_role ? 'secret-role' : ''; ?>">Transaksi Terakhir</h2>
            <table class="transaction-list">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Deskripsi</th>
                        <th>Jenis</th>
                        <th>Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($transaksi_result) > 0): ?>
                    <?php while ($transaksi = mysqli_fetch_assoc($transaksi_result)): ?>
                    <tr class="<?php echo $is_secret_role ? 'glow-on-hover' : ''; ?>">
                        <td><?php echo date('d/m/Y', strtotime($transaksi['tanggal'])); ?></td>
                        <td><?php echo htmlspecialchars($transaksi['nama_kategori'] ?? 'Tanpa Kategori'); ?></td>
                        <td><?php echo htmlspecialchars($transaksi['deskripsi'] ?? '-'); ?></td>
                        <td><span
                                class="<?php echo $transaksi['jenis_transaksi']; ?>"><?php echo ucfirst($transaksi['jenis_transaksi']); ?></span>
                        </td>
                        <td>Rp <?php echo number_format($transaksi['jumlah'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">Belum ada transaksi</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Notifikasi Role -->
    <div id="roleModal" class="modal" <?php if ($show_notification): ?>style="display:block;" <?php endif; ?>>
        <div class="modal-content">
            <div class="modal-header">
                <span class="close">&times;</span>
                <h2><?php echo $notification_title; ?></h2>
            </div>
            <div class="modal-body">
                <p><?php echo $notification_message; ?></p>
                <p><?php echo $notification_details; ?></p>
            </div>
            <div class="modal-footer">
                <button id="closeModal" class="btn-primary">Tutup</button>
            </div>
        </div>
    </div>

    <script>
    const modal = document.getElementById("roleModal");
    const span = document.getElementsByClassName("close")[0];
    const closeBtn = document.getElementById("closeModal");

    span.onclick = function() {
        modal.style.display = "none";
    }
    closeBtn.onclick = function() {
        modal.style.display = "none";
    }
    window.onclick = function(event) {
        if (event.target == modal) modal.style.display = "none";
    }

    function updateTimer() {
        const timers = document.querySelectorAll('.timer');
        timers.forEach(timer => {
            const expiry = timer.getAttribute('data-expiry');
            if (expiry) {
                let timeRemaining = Math.max(0, Math.floor((new Date(expiry) - new Date()) / 1000));
                if (timeRemaining <= 0) {
                    timer.textContent = 'Kadaluarsa';
                    window.location.reload();
                    return;
                }
                const days = Math.floor(timeRemaining / (24 * 60 * 60));
                const hours = Math.floor((timeRemaining % (24 * 60 * 60)) / (60 * 60));
                const minutes = Math.floor((timeRemaining % (60 * 60)) / 60);
                const seconds = Math.floor(timeRemaining % 60);
                timer.textContent = `${days} hari, ${hours} jam, ${minutes} menit, ${seconds} detik`;
            }
        });
        setTimeout(updateTimer, 1000);
    }

    function markAsRead() {
        fetch('dashboard/mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'user_id=<?php echo $user_id; ?>'
        }).then(response => response.json()).then(data => {
            if (data.success) document.querySelector('.notification-box').style.display = 'none';
        }).catch(error => console.error('Error:', error));
    }

    let isDraggingLogo = false;
    let offsetXLogo, offsetYLogo;
    const logo = document.getElementById('calculator-logo');
    const savedLeft = localStorage.getItem('calculatorLeft');
    const savedTop = localStorage.getItem('calculatorTop');
    if (savedLeft && savedTop) {
        logo.style.left = savedLeft + 'px';
        logo.style.top = savedTop + 'px';
    } else {
        logo.style.left = '20px';
        logo.style.top = '20px';

        document.addEventListener('DOMContentLoaded', function() {
            const trollMessages = document.querySelectorAll('.troll-message');
            trollMessages.forEach(message => {
                const duration = parseInt(message.getAttribute('data-duration')) *
                    1000; // Convert detik ke milidetik
                setTimeout(() => {
                    message.style.display = 'none';
                }, duration);
            });
        });

        window.onload = function() {
            updateTimer();
        };
    }
    </script>
</body>

</html>