<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Pastikan sesi dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/config.php';
require_once 'config/auth_check.php';

// Periksa apakah pengguna sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$start = ($page - 1) * $per_page;

try {
    $user_query = "SELECT * FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $user_result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($user_result);
} catch (Exception $e) {
    error_log("Error getting user data: " . $e->getMessage());
    die("Error: " . $e->getMessage());
}

try {
    $kategori_query = "SELECT * FROM kategori WHERE user_id = ? ORDER BY nama_kategori ASC";
    $stmt = mysqli_prepare($conn, $kategori_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $kategori_result = mysqli_stmt_get_result($stmt);
} catch (Exception $e) {
    error_log("Error getting categories: " . $e->getMessage());
    $error = "Terjadi kesalahan saat mengambil data kategori";
}

if (isset($_GET['hapus'])) {
    try {
        $transaksi_id = mysqli_real_escape_string($conn, $_GET['hapus']);
        $query = "DELETE FROM transaksi WHERE transaksi_id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $transaksi_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Transaksi berhasil dihapus!";
            header("Location: transaksi.php");
            exit();
        } else {
            throw new Exception("Gagal menghapus transaksi: " . mysqli_error($conn));
        }
    } catch (Exception $e) {
        error_log("Error deleting transaction: " . $e->getMessage());
        $error = "Gagal menghapus transaksi: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transaksi_id = isset($_POST['transaksi_id']) ? trim($_POST['transaksi_id']) : null;
    $tanggal = trim($_POST['tanggal']);
    $kategori_id = trim($_POST['kategori_id']);
    $jumlah = trim($_POST['jumlah']);
    $deskripsi = trim($_POST['deskripsi']);
    $jenis_transaksi = trim($_POST['jenis_transaksi']);

    if (empty($tanggal) || empty($kategori_id) || empty($jumlah) || empty($jenis_transaksi)) {
        $error = "Semua field harus diisi!";
    } else {
        try {
            if ($transaksi_id) {
                $query = "UPDATE transaksi SET tanggal = ?, kategori_id = ?, jumlah = ?, deskripsi = ?, jenis_transaksi = ? 
                          WHERE transaksi_id = ? AND user_id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "siissii", $tanggal, $kategori_id, $jumlah, $deskripsi, $jenis_transaksi, $transaksi_id, $user_id);
                $success_message = "Transaksi berhasil diupdate!";
            } else {
                $query = "INSERT INTO transaksi (tanggal, kategori_id, jumlah, deskripsi, jenis_transaksi, user_id) 
                          VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "siissi", $tanggal, $kategori_id, $jumlah, $deskripsi, $jenis_transaksi, $user_id);
                $success_message = "Transaksi berhasil ditambahkan!";
            }
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = $success_message;
                header("Location: transaksi.php");
                exit();
            } else {
                throw new Exception("Gagal menyimpan transaksi: " . mysqli_error($conn));
            }
        } catch (Exception $e) {
            error_log("Error saving transaction: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

function getRoleIcon($role) {
    $icons = ['owner' => 'crown', 'coder' => 'code', 'admin' => 'user-shield', 'user' => 'user'];
    return '<i class="fas fa-' . ($icons[strtolower($role)] ?? 'user') . '"></i>';
}

$search = isset($_GET['search']) ? $_GET['search'] : '';
$tanggal_mulai = isset($_GET['tanggal_mulai']) ? $_GET['tanggal_mulai'] : '';
$tanggal_akhir = isset($_GET['tanggal_akhir']) ? $_GET['tanggal_akhir'] : '';
$jenis_filter = isset($_GET['jenis']) ? $_GET['jenis'] : '';

$where_conditions = ["t.user_id = ?"]; 
$params = [$user_id];
$param_types = "i";

if ($search) {
    $where_conditions[] = "(k.nama_kategori LIKE ? OR t.deskripsi LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

if ($tanggal_mulai) {
    $where_conditions[] = "t.tanggal >= ?";
    $params[] = $tanggal_mulai;
    $param_types .= "s";
}

if ($tanggal_akhir) {
    $where_conditions[] = "t.tanggal <= ?";
    $params[] = $tanggal_akhir;
    $param_types .= "s";
}

if ($jenis_filter) {
    $where_conditions[] = "t.jenis_transaksi = ?";
    $params[] = $jenis_filter;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);
$query = "SELECT t.*, k.nama_kategori 
          FROM transaksi t
          LEFT JOIN kategori k ON t.kategori_id = k.kategori_id
          WHERE $where_clause
          ORDER BY t.tanggal DESC
          LIMIT ?, ?";

$params[] = $start;
$params[] = $per_page;
$param_types .= "ii";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $param_types, ...$params);
mysqli_stmt_execute($stmt);
$transaksi_result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Transaksi - Manajemen Keuangan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/transksi.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
</head>

<body>
    <div class="sidebar">
        <div class="profile">
            <a href="profile.php">
                <img src="<?php echo !empty($user['foto_profil']) ? './uploads/profil/' . $user['foto_profil'] : './images/default-profil.png'; ?>"
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
            <a href="approve_reset.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'approve_reset.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-check-circle"></i> Persetujuan Reset</a>
            <?php endif; ?>
            <?php if (in_array(strtolower($user['role']), ['coder', 'owner'])): ?>
            <a href="manage_users.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-users-cog"></i> Manajemen Pengguna</a>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="btn logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="content-wrapper">
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
            ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <div class="content-header">
            <h1>Transaksi Keuangan</h1>
            <button class="btn" onclick="openModal('tambah')">
                <i class="fas fa-plus"></i> Tambah Transaksi
            </button>
        </div>

        <div class="filter-container">
            <form method="GET" class="filter-form">
                <input type="text" name="search" placeholder="Cari transaksi..."
                    value="<?php echo htmlspecialchars($search); ?>">
                <input type="date" name="tanggal_mulai" value="<?php echo $tanggal_mulai; ?>">
                <input type="date" name="tanggal_akhir" value="<?php echo $tanggal_akhir; ?>">
                <select name="jenis">
                    <option value="">Semua Jenis</option>
                    <option value="pemasukan" <?php echo $jenis_filter === 'pemasukan' ? 'selected' : ''; ?>>Pemasukan
                    </option>
                    <option value="pengeluaran" <?php echo $jenis_filter === 'pengeluaran' ? 'selected' : ''; ?>>
                        Pengeluaran</option>
                </select>
                <button type="submit">Filter</button>
                <a href="transaksi.php" class="btn">Reset</a>
            </form>
        </div>

        <div class="data-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Deskripsi</th>
                        <th>Jenis</th>
                        <th>Jumlah</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($transaksi_result) > 0): ?>
                    <?php while ($transaksi = mysqli_fetch_assoc($transaksi_result)): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($transaksi['tanggal'])); ?></td>
                        <td><?php echo htmlspecialchars($transaksi['nama_kategori']); ?></td>
                        <td><?php echo htmlspecialchars($transaksi['deskripsi']); ?></td>
                        <td>
                            <span class="badge <?php echo $transaksi['jenis_transaksi']; ?>">
                                <?php echo ucfirst($transaksi['jenis_transaksi']); ?>
                            </span>
                        </td>
                        <td>Rp <?php echo number_format($transaksi['jumlah'], 0, ',', '.'); ?></td>
                        <td>
                            <button onclick="editTransaksi(<?php echo $transaksi['transaksi_id']; ?>)" class="btn-edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="hapusTransaksi(<?php echo $transaksi['transaksi_id']; ?>)"
                                class="btn-delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center;">Tidak ada transaksi</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="formModal" class="modal">
            <div class="modal-wrapper">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2 class="modal-title">Tambah Transaksi</h2>
                <form method="POST" id="transaksiForm">
                    <input type="hidden" name="transaksi_id" id="transaksi_id">
                    <div class="form-group">
                        <label>Tanggal</label>
                        <input type="date" name="tanggal" id="tanggal" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis Transaksi</label>
                        <select name="jenis_transaksi" id="jenis_transaksi" required
                            onchange="filterKategori(this.value)">
                            <option value="">Pilih Jenis</option>
                            <option value="pemasukan">Pemasukan</option>
                            <option value="pengeluaran">Pengeluaran</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Kategori</label>
                        <select name="kategori_id" id="kategori_id" required>
                            <option value="">Pilih Kategori</option>
                            <?php 
                            mysqli_data_seek($kategori_result, 0);
                            while ($kategori = mysqli_fetch_assoc($kategori_result)): 
                            ?>
                            <option value="<?php echo $kategori['kategori_id']; ?>"
                                data-jenis="<?php echo $kategori['jenis']; ?>">
                                <?php echo htmlspecialchars($kategori['nama_kategori']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jumlah</label>
                        <input type="number" name="jumlah" id="jumlah" required min="0">
                    </div>
                    <div class="form-group">
                        <label>Deskripsi</label>
                        <textarea name="deskripsi" id="deskripsi"></textarea>
                    </div>
                    <button type="submit" class="btn-submit">Simpan</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openModal(action) {
        const modal = document.getElementById('formModal');
        const title = document.querySelector('.modal-title');
        const form = document.getElementById('transaksiForm');

        if (action === 'tambah') {
            title.textContent = 'Tambah Transaksi';
            form.reset();
            document.getElementById('transaksi_id').value = '';
        }
        modal.style.display = 'block';
    }

    function editTransaksi(id) {
        const modal = document.getElementById('formModal');
        const title = document.querySelector('.modal-title');
        title.textContent = 'Edit Transaksi';

        fetch(`get_transaksi.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Gagal mengambil data transaksi: ' + data.error);
                    // Jika sesi tidak valid, arahkan ke halaman login
                    if (data.error === 'Unauthorized' && data.redirect) {
                        window.location.href = data.redirect;
                    }
                    return;
                }
                document.getElementById('transaksi_id').value = data.transaksi_id;
                document.getElementById('tanggal').value = data.tanggal;
                document.getElementById('jenis_transaksi').value = data.jenis_transaksi;
                document.getElementById('kategori_id').value = data.kategori_id;
                document.getElementById('jumlah').value = data.jumlah;
                document.getElementById('deskripsi').value = data.deskripsi;
                filterKategori(data.jenis_transaksi);
                modal.style.display = 'block';
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat mengambil data transaksi');
            });
    }

    function closeModal() {
        document.getElementById('formModal').style.display = 'none';
    }

    function filterKategori(jenis) {
        const options = document.querySelectorAll('select[name="kategori_id"] option');
        options.forEach(option => {
            if (option.value === '') return;
            if (option.dataset.jenis === jenis) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    }

    function hapusTransaksi(id) {
        if (confirm('Apakah Anda yakin ingin menghapus transaksi ini?')) {
            window.location.href = `transaksi.php?hapus=${id}`;
        }
    }

    window.onclick = function(event) {
        const modal = document.getElementById('formModal');
        if (event.target === modal) {
            closeModal();
        }
    }
    </script>
</body>

</html>