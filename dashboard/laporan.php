<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/config.php';
require_once '../config/auth_check.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
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

$bulan = isset($_GET['bulan']) ? $_GET['bulan'] : date('m');
$tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');
$tanggal_mulai = isset($_GET['tanggal_mulai']) ? $_GET['tanggal_mulai'] : date('Y-m-01', strtotime('2025-02-01')); // Default ke Februari 2025
$tanggal_akhir = isset($_GET['tanggal_akhir']) ? $_GET['tanggal_akhir'] : date('Y-m-d'); // Default ke hari ini: 2025-03-03

$trend_period = isset($_GET['trend_period']) ? $_GET['trend_period'] : '6months';
$allowed_periods = ['1week', '1month', '2months', '6months', '1year'];
if (!in_array($trend_period, $allowed_periods)) {
    $trend_period = '6months';
}

function getRoleIcon($role) {
    $icons = ['owner' => 'crown', 'coder' => 'code', 'admin' => 'user-shield', 'user' => 'user'];
    return '<i class="fas fa-' . ($icons[strtolower($role)] ?? 'user') . '"></i>';
}

switch ($trend_period) {
    case '1week':
        $interval = '7 DAY';
        $label_expr = 'DATE(tanggal)';
        $group_by = 'DATE(tanggal)';
        break;
    case '1month':
        $interval = '1 MONTH';
        $label_expr = 'CONCAT(YEAR(tanggal), \'-W\', LPAD(WEEK(tanggal), 2, \'0\'))';
        $group_by = 'YEAR(tanggal), WEEK(tanggal)';
        break;
    case '2months':
        $interval = '2 MONTH';
        $label_expr = 'CONCAT(YEAR(tanggal), \'-W\', LPAD(WEEK(tanggal), 2, \'0\'))';
        $group_by = 'YEAR(tanggal), WEEK(tanggal)';
        break;    
    case '6months':
    case '1year':
        $interval = ($trend_period == '6months') ? '6 MONTH' : '1 YEAR';
        $label_expr = 'DATE_FORMAT(tanggal, \'%Y-%m\')';
        $group_by = 'YEAR(tanggal), MONTH(tanggal)';
        break;
}

$query = "SELECT 
            SUM(CASE WHEN jenis_transaksi = 'pemasukan' THEN jumlah ELSE 0 END) as total_pemasukan,
            SUM(CASE WHEN jenis_transaksi = 'pengeluaran' THEN jumlah ELSE 0 END) as total_pengeluaran,
            SUM(CASE WHEN jenis_transaksi = 'pemasukan' THEN jumlah ELSE -jumlah END) as saldo,
            COUNT(CASE WHEN jenis_transaksi = 'pemasukan' THEN 1 END) as jumlah_pemasukan,
            COUNT(CASE WHEN jenis_transaksi = 'pengeluaran' THEN 1 END) as jumlah_pengeluaran
          FROM transaksi 
          WHERE user_id = ? 
          AND tanggal BETWEEN ? AND ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "iss", $user_id, $tanggal_mulai, $tanggal_akhir);
mysqli_stmt_execute($stmt);
$ringkasan = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$query_kategori = "SELECT 
                    k.nama_kategori,
                    t.jenis_transaksi,
                    COUNT(*) as jumlah_transaksi,
                    SUM(t.jumlah) as total
                  FROM transaksi t
                  JOIN kategori k ON t.kategori_id = k.kategori_id
                  WHERE t.user_id = ?
                  AND t.tanggal BETWEEN ? AND ?
                  GROUP BY k.kategori_id, t.jenis_transaksi
                  ORDER BY t.jenis_transaksi, k.nama_kategori";

$stmt = mysqli_prepare($conn, $query_kategori);
mysqli_stmt_bind_param($stmt, "iss", $user_id, $tanggal_mulai, $tanggal_akhir);
mysqli_stmt_execute($stmt);
$kategori_result = mysqli_stmt_get_result($stmt);

$query_trend = "SELECT 
                 $label_expr as label,
                 SUM(CASE WHEN jenis_transaksi = 'pemasukan' THEN jumlah ELSE 0 END) as pemasukan,
                 SUM(CASE WHEN jenis_transaksi = 'pengeluaran' THEN jumlah ELSE 0 END) as pengeluaran
               FROM transaksi 
               WHERE user_id = ?
               AND tanggal >= DATE_SUB(?, INTERVAL $interval)
               GROUP BY $group_by
               ORDER BY label";

$stmt = mysqli_prepare($conn, $query_trend);
mysqli_stmt_bind_param($stmt, "is", $user_id, $tanggal_akhir);
mysqli_stmt_execute($stmt);
$trend_result = mysqli_stmt_get_result($stmt);
$trend_data = [];
while ($row = mysqli_fetch_assoc($trend_result)) {
    $trend_data[] = $row;
}

if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename=Laporan_Keuangan_'.date('d-m-Y').'.xls');
    header('Cache-Control: max-age=0');
    ?>
<table border="1">
    <tr>
        <th colspan="4" style="background: #f0f0f0; text-align: center; font-weight: bold; font-size: 14pt;">
            LAPORAN KEUANGAN
        </th>
    </tr>
    <tr>
        <th colspan="4" style="text-align: center;">
            Periode:
            <?php echo date('d F Y', strtotime($tanggal_mulai)) . ' s/d ' . date('d F Y', strtotime($tanggal_akhir)); ?>
        </th>
    </tr>
    <tr>
        <td colspan="4"></td>
    </tr>

    <!-- Ringkasan -->
    <tr>
        <th colspan="4" style="background: #f0f0f0;">RINGKASAN</th>
    </tr>
    <tr>
        <th>Total Pemasukan</th>
        <td>Rp <?php echo number_format($ringkasan['total_pemasukan'], 0, ',', '.'); ?></td>
        <th>Jumlah Transaksi Masuk</th>
        <td><?php echo $ringkasan['jumlah_pemasukan']; ?></td>
    </tr>
    <tr>
        <th>Total Pengeluaran</th>
        <td>Rp <?php echo number_format($ringkasan['total_pengeluaran'], 0, ',', '.'); ?></td>
        <th>Jumlah Transaksi Keluar</th>
        <td><?php echo $ringkasan['jumlah_pengeluaran']; ?></td>
    </tr>
    <tr>
        <th>Saldo</th>
        <td colspan="3">Rp <?php echo number_format($ringkasan['saldo'], 0, ',', '.'); ?></td>
    </tr>
    <tr>
        <td colspan="4"></td>
    </tr>

    <!-- Detail per Kategori -->
    <tr>
        <th colspan="4" style="background: #f0f0f0;">DETAIL PER KATEGORI</th>
    </tr>
    <tr>
        <th style="background: #f0f0f0;">Kategori</th>
        <th style="background: #f0f0f0;">Jenis</th>
        <th style="background: #f0f0f0;">Jumlah Transaksi</th>
        <th style="background: #f0f0f0;">Total</th>
    </tr>
    <?php 
        mysqli_data_seek($kategori_result, 0);
        while($kategori = mysqli_fetch_assoc($kategori_result)): 
        ?>
    <tr>
        <td><?php echo $kategori['nama_kategori']; ?></td>
        <td><?php echo ucfirst($kategori['jenis_transaksi']); ?></td>
        <td><?php echo $kategori['jumlah_transaksi']; ?></td>
        <td>Rp <?php echo number_format($kategori['total'], 0, ',', '.'); ?></td>
    </tr>
    <?php endwhile; ?>
    <tr>
        <td colspan="4"></td>
    </tr>

    <!-- Detail Transaksi -->
    <tr>
        <th colspan="4" style="background: #f0f0f0;">DETAIL TRANSAKSI</th>
    </tr>
    <tr>
        <th style="background: #f0f0f0;">Tanggal</th>
        <th style="background: #f0f0f0;">Kategori</th>
        <th style="background: #f0f0f0;">Keterangan</th>
        <th style="background: #f0f0f0;">Jumlah</th>
    </tr>
    <?php
        $query_detail = "SELECT t.*, k.nama_kategori 
                        FROM transaksi t
                        JOIN kategori k ON t.kategori_id = k.kategori_id
                        WHERE t.user_id = ? 
                        AND t.tanggal BETWEEN ? AND ?
                        ORDER BY t.tanggal DESC";
        $stmt = mysqli_prepare($conn, $query_detail);
        mysqli_stmt_bind_param($stmt, "iss", $user_id, $tanggal_mulai, $tanggal_akhir);
        mysqli_stmt_execute($stmt);
        $detail_result = mysqli_stmt_get_result($stmt);

        while($transaksi = mysqli_fetch_assoc($detail_result)): 
        ?>
    <tr>
        <td><?php echo date('d/m/Y', strtotime($transaksi['tanggal'])); ?></td>
        <td><?php echo $transaksi['nama_kategori']; ?></td>
        <td><?php echo $transaksi['deskripsi']; ?></td>
        <td>
            <?php if($transaksi['jenis_transaksi'] == 'pengeluaran') echo '-'; ?>
            Rp <?php echo number_format($transaksi['jumlah'], 0, ',', '.'); ?>
        </td>
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
    <title>Laporan - Manajemen Keuangan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/laporan.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="jpg/png" />
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
                <?php echo basename($_SERVER['PHP_SELF']) == 'katagori.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-tags"></i> Kategori</a>
            <a href="transaksi.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'transaksi.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-exchange-alt"></i> Transaksi</a>
            <a href="laporan.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'laporan.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-chart-bar"></i> Laporan</a>
            <?php if (in_array(strtolower($user['role']), ['admin', 'coder', 'owner'])): ?>
            <a href="../admin/approve_reset.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'approve_reset.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-check-circle"></i> Persetujuan Reset</a>
            <?php endif; ?>
            <?php if (in_array(strtolower($user['role']), ['coder', 'owner'])): ?>
            <a href="../admin/manage_users.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-users-cog"></i> Manajemen Pengguna</a>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="btn logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
    <div id="calculator-logo" style="position: absolute; cursor: move;">
        <img src="../images/calculator.png?v=1" alt="Calculator" width="50" height="50"
            onerror="this.src='./images/default-calculator.png';">
    </div>

    <?php include 'calcu.php'; ?>

    <div class="content-wrapper">
        <div class="report-container">
            <h2>Laporan Keuangan</h2>

            <form method="GET" class="filter-form">
                <input type="date" name="tanggal_mulai" value="<?php echo $tanggal_mulai; ?>">
                <input type="date" name="tanggal_akhir" value="<?php echo $tanggal_akhir; ?>">
                <select name="trend_period">
                    <option value="1week" <?php if($trend_period == '1week') echo 'selected'; ?>>1 Minggu</option>
                    <option value="1month" <?php if($trend_period == '1month') echo 'selected'; ?>>1 Bulan</option>
                    <option value="6months" <?php if($trend_period == '6months') echo 'selected'; ?>>6 Bulan</option>
                    <option value="1year" <?php if($trend_period == '1year') echo 'selected'; ?>>1 Tahun</option>
                </select>
                <button type="submit" class="btn btn-primary">Filter</button>
            </form>

            <div class="btn-group">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="export" value="excel">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </form>
            </div>

            <div class="summary-cards">
                <div class="card">
                    <div class="card-title">Total Pemasukan</div>
                    <div class="card-amount pemasukan">
                        Rp <?php echo number_format($ringkasan['total_pemasukan'], 0, ',', '.'); ?>
                    </div>
                    <div class="card-subtitle">
                        <?php echo $ringkasan['jumlah_pemasukan']; ?> transaksi
                    </div>
                </div>
                <div class="card">
                    <div class="card-title">Total Pengeluaran</div>
                    <div class="card-amount pengeluaran">
                        Rp <?php echo number_format($ringkasan['total_pengeluaran'], 0, ',', '.'); ?>
                    </div>
                    <div class="card-subtitle">
                        <?php echo $ringkasan['jumlah_pengeluaran']; ?> transaksi
                    </div>
                </div>
                <div class="card">
                    <div class="card-title">Saldo</div>
                    <div class="card-amount">
                        Rp <?php echo number_format($ringkasan['saldo'], 0, ',', '.'); ?>
                    </div>
                </div>
            </div>

            <div class="chart-container">
                <h3>Trend
                    <?php echo ($trend_period == '1week') ? '1 Minggu' : (($trend_period == '1month') ? '1 Bulan' : (($trend_period == '6months') ? '6 Bulan' : '1 Tahun')); ?>
                    Terakhir</h3>
                <canvas id="trendChart"></canvas>
            </div>

            <div class="table-container">
                <h3>Detail Per Kategori</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Jenis</th>
                            <th>Jumlah Transaksi</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        mysqli_data_seek($kategori_result, 0);
                        while($kategori = mysqli_fetch_assoc($kategori_result)): 
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($kategori['nama_kategori']); ?></td>
                            <td>
                                <span class="<?php echo $kategori['jenis_transaksi']; ?>">
                                    <?php echo ucfirst($kategori['jenis_transaksi']); ?>
                                </span>
                            </td>
                            <td><?php echo $kategori['jumlah_transaksi']; ?></td>
                            <td>Rp <?php echo number_format($kategori['total'], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($trend_data, 'label')); ?>,
            datasets: [{
                label: 'Pemasukan',
                data: <?php echo json_encode(array_column($trend_data, 'pemasukan')); ?>,
                borderColor: '#2ecc71',
                tension: 0.1
            }, {
                label: 'Pengeluaran',
                data: <?php echo json_encode(array_column($trend_data, 'pengeluaran')); ?>,
                borderColor: '#e74c3c',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

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