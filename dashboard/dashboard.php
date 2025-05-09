<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';

// Cek login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Cek loading
if (!isset($_SESSION['loading_shown']) || $_SESSION['loading_shown'] !== true) {
    header("Location: ../loading.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Periksa dan bersihkan sanksi kadaluarsa untuk pengguna saat ini
checkAndClearExpiredSanctions($conn, $user_id);

// Get user data
try {
    $user_query = "SELECT * FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $user_result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($user_result);
    mysqli_stmt_close($stmt);

    // Pengecekan status ban
    if ($user['ban_status'] === 'banned') {
        if ($user['ban_expiry'] === NULL) {
            $_SESSION['error'] = "Akun Anda telah diban permanen. Silakan hubungi admin.";
        } elseif (strtotime($user['ban_expiry']) > time()) {
            $_SESSION['error'] = "Akun Anda diban hingga " . date('d-m-Y H:i:s', strtotime($user['ban_expiry'])) . ". Silakan hubungi admin.";
        } else {
            $_SESSION['success'] = "Status ban Anda telah dihapus karena waktu kadaluarsa telah tercapai.";
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
            $warning_message .= "Anda tidak dapat menggunakan fitur transaksi dan laporan sampai peringatan ini dihapus atau kadaluarsa. Waktu kadaluarsa: ";
            $warning_message .= "<span class='timer' data-expiry='" . htmlspecialchars($user['warning_expiry']) . "'>" . getTimeRemaining($user['warning_expiry']) . "</span>";
        } elseif ($warning_count == 1) {
            $warning_message .= "Jika mencapai 3 peringatan, Anda tidak akan bisa menggunakan fitur tertentu dan akun Anda akan diban. Waktu kadaluarsa: ";
            $warning_message .= "<span class='timer' data-expiry='" . htmlspecialchars($user['warning_expiry']) . "'>" . getTimeRemaining($user['warning_expiry']) . "</span>";
        }
    }

    $can_access_features = canAccessFeature($warning_count);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Get summary untuk semua transaksi hingga hari ini (3 Maret 2025)
try {
    $today = date('Y-m-d');
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
    $icons = ['owner' => 'crown', 'coder' => 'code', 'admin' => 'user-shield', 'user' => 'user'];
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
        <th colspan="5" style="background: #f0f0f0; text-align: center; font-weight: bold; font-size: 14pt;">DASHBOARD
            KEUANGAN</th>
    </tr>
    <tr>
        <th colspan="5" style="text-align: center;">Periode: Hingga <?php echo date('d F Y', strtotime($today)); ?></th>
    </tr>
    <tr>
        <td colspan="5"></td>
    </tr>
    <tr>
        <th colspan="5" style="background: #f0f0f0;">RINGKASAN</th>
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
        <td colspan="5"></td>
    </tr>
    <tr>
        <th colspan="5" style="background: #f0f0f0;">TRANSAKSI TERAKHIR</th>
    </tr>
    <tr>
        <th style="background: #f0f0f0;">Tanggal</th>
        <th style="background: #f0f0f0;">Kategori</th>
        <th style="background: #f0f0f0;">Deskripsi</th>
        <th style="background: #f0f0f0;">Jenis</th>
        <th style="background: #f0f0f0;">Jumlah</th>
    </tr>
    <?php 
    mysqli_data_seek($transaksi_result, 0);
    while($transaksi = mysqli_fetch_assoc($transaksi_result)): 
    ?>
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

    <script>
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
    window.onload = function() {
        updateTimer();
    };
    </script>
</head>

<body>
    <div class="sidebar">
        <div class="profile">
            <a href="profile.php">
                <img src="<?php echo !empty($user['foto_profil']) ? '../uploads/profil/' . $user['foto_profil'] : './images/default-profil.png'; ?>"
                    alt="Profile">
            </a>
            <h3><?php echo htmlspecialchars($user['nama_lengkap']) . ' (' . ucfirst($user['role']) . ') ' . getRoleIcon($user['role']); ?>
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

    <div id="calculator-logo" style="position: absolute; cursor: move;">
        <img src="../images/calculator.png?v=1" alt="Calculator" width="50" height="50"
            onerror="this.src='./images/default-calculator.png';">
    </div>

    <?php include 'calcu.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1>Dashboard Keuangan</h1>
                <p>Selamat datang kembali, <?php echo htmlspecialchars($user['nama_lengkap']); ?></p>
            </div>
            <div class="btn-group">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="export" value="excel">
                    <button type="submit" class="btn btn-success"
                        <?php echo !$can_access_features ? 'disabled' : ''; ?>>
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </form>
            </div>
        </div>

        <?php if (isset($warning_message)): ?>
        <div class="alert alert-warning"><?php echo $warning_message; ?></div>
        <?php endif; ?>

        <div class="summary-cards">
            <div class="card">
                <div class="card-title"><i class="fas fa-arrow-up"></i> Total Pemasukan Bulan Ini</div>
                <div class="card-amount pemasukan">Rp <?php echo number_format($pemasukan['total'], 0, ',', '.'); ?>
                </div>
            </div>
            <div class="card">
                <div class="card-title"><i class="fas fa-arrow-down"></i> Total Pengeluaran Bulan Ini</div>
                <div class="card-amount pengeluaran">Rp <?php echo number_format($pengeluaran['total'], 0, ',', '.'); ?>
                </div>
            </div>
            <div class="card">
                <div class="card-title"><i class="fas fa-wallet"></i> Saldo</div>
                <div class="card-amount">Rp <?php echo number_format($saldo, 0, ',', '.'); ?></div>
            </div>
        </div>

        <div class="recent-transactions">
            <h2>Transaksi Terakhir</h2>
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
                    <tr>
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

    <script>
    let isDraggingLogo = false;
    let offsetXLogo, offsetYLogo;

    const logo = document.getElementById('calculator-logo');

    // Load saved position from localStorage
    const savedLeft = localStorage.getItem('calculatorLeft');
    const savedTop = localStorage.getItem('calculatorTop');
    if (savedLeft && savedTop) {
        logo.style.left = savedLeft + 'px';
        logo.style.top = savedTop + 'px';
    } else {
        // Default position if no saved position
        logo.style.left = '20px';
        logo.style.top = '20px';
    }

    logo.addEventListener('mousedown', function(e) {
        isDraggingLogo = true;
        const rect = logo.getBoundingClientRect();
        offsetXLogo = e.clientX - rect.left;
        offsetYLogo = e.clientY - rect.top;
    });

    document.addEventListener('mousemove', function(e) {
        if (isDraggingLogo) {
            let newLeft = e.clientX - offsetXLogo;
            let newTop = e.clientY - offsetYLogo;
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;
            const logoWidth = logo.offsetWidth;
            const logoHeight = logo.offsetHeight;

            if (newLeft < 0) newLeft = 0;
            if (newTop < 0) newTop = 0;
            if (newLeft + logoWidth > viewportWidth) newLeft = viewportWidth - logoWidth;
            if (newTop + logoHeight > viewportHeight) newTop = viewportHeight - logoHeight;

            logo.style.left = newLeft + 'px';
            logo.style.top = newTop + 'px';
        }
    });

    document.addEventListener('mouseup', function() {
        if (isDraggingLogo) {
            // Save the current position to localStorage
            localStorage.setItem('calculatorLeft', logo.style.left.replace('px', ''));
            localStorage.setItem('calculatorTop', logo.style.top.replace('px', ''));
        }
        isDraggingLogo = false;
    });

    logo.addEventListener('click', function() {
        openCalculator();
    });

    // Fallback image if calculator.png fails to load
    document.querySelector('#calculator-logo img').addEventListener('error', function() {
        this.src = './images/default-calculator.png';
    });
    </script>
</body>

</html>