<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$can_access_features = false; // Nilai default

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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log('POST Data: ' . print_r($_POST, true));
}

try {
    $user_query = "SELECT * FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $user_result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($user_result);
} catch (Exception $e) {
    error_log("Error getting user data: " . $e->getMessage());
    $error = "Terjadi kesalahan saat mengambil data user";
}

$is_secret_role = strtolower($user['role']) === 'secret';

if (isset($_GET['hapus'])) {
    try {
        $kategori_id = mysqli_real_escape_string($conn, $_GET['hapus']);
        
        $check_query = "SELECT COUNT(*) as total FROM transaksi WHERE kategori_id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($stmt, "ii", $kategori_id, $user_id);
        mysqli_stmt_execute($stmt);
        $check_result = mysqli_stmt_get_result($stmt);
        $total_transaksi = mysqli_fetch_assoc($check_result)['total'];
        
        if ($total_transaksi > 0) {
            throw new Exception("Tidak dapat menghapus kategori karena masih ada transaksi yang terkait.");
        }
        
        error_log("Menghapus kategori ID: " . $kategori_id);

        $query = "DELETE FROM kategori WHERE kategori_id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $kategori_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Kategori berhasil dihapus!";
            header("Location: katagori.php");
            exit();
        } else {
            throw new Exception("Gagal menghapus kategori: " . mysqli_error($conn));
        }
    } catch (Exception $e) {
        error_log("Error deleting category: " . $e->getMessage());
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_kategori'])) {
    try {
        $nama_kategori = trim(mysqli_real_escape_string($conn, $_POST['nama_kategori']));
        $jenis = trim(mysqli_real_escape_string($conn, $_POST['jenis']));
        
        if (empty($nama_kategori) || empty($jenis)) {
            throw new Exception("Nama kategori dan jenis harus diisi");
        }
        
        $query = "INSERT INTO kategori (nama_kategori, jenis, user_id) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ssi", $nama_kategori, $jenis, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Kategori berhasil ditambahkan!";
            header("Location: katagori.php");
            exit();
        } else {
            throw new Exception("Gagal menambahkan kategori: " . mysqli_error($conn));
        }
    } catch (Exception $e) {
        error_log("Error adding category: " . $e->getMessage());
        $error = $e->getMessage();
    }
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
    $kategori_query = "SELECT * FROM kategori WHERE user_id = ? ORDER BY nama_kategori ASC";
    $stmt = mysqli_prepare($conn, $kategori_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $kategori_result = mysqli_stmt_get_result($stmt);
} catch (Exception $e) {
    error_log("Error getting categories: " . $e->getMessage());
    $error = "Terjadi kesalahan saat mengambil data kategori";
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kategori - Manajemen Keuangan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/kategori.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="image/png" />
    <?php if ($is_secret_role): ?>
    <link rel="stylesheet" href="../css/secret-role.css">
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="../js/secret-role.js"></script>
    <?php endif; ?>
    <style>
    /* Reset default styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: Arial, sans-serif;
        line-height: 1.6;
        background-color: #f4f4f4;
        overflow-x: hidden;
    }

    .sidebar {
        width: 250px;
        height: 100vh;
        position: fixed;
        background-color: #2c3e50;
        color: white;
        padding: 20px;
        transition: transform 0.3s ease;
    }

    .main-content {
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    .kategori-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    th,
    td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }

    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
    }

    .modal-content {
        background-color: white;
        margin: 15% auto;
        padding: 20px;
        width: 90%;
        max-width: 500px;
        border-radius: 5px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    input,
    select {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-250px);
            width: 200px;
        }

        .main-content {
            margin-left: 0;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        table {
            display: block;
            overflow-x: auto;
        }

        .kategori-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .btn-tambah {
            margin-top: 10px;
        }
    }

    /* Particle JS Fix */
    #particles-js {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: -1;
        pointer-events: none;
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
            <?php if (in_array($user['role'], ['admin', 'coder', 'owner'])): ?>
            <a href="../admin/approve_reset.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'approve_reset.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-check-circle"></i> Persetujuan Reset</a>
            <?php endif; ?>
            <?php if (in_array($user['role'], ['coder', 'owner'])): ?>
            <a href="../admin/manage_users.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-users-cog"></i> Manajemen Pengguna</a>
            <?php endif; ?>
            <?php if (in_array($user['role'], ['coder', 'owner', 'admin'])): ?>
            <a href="../admin/troll.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'troll.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-skull-crossbones"></i> Admin Troll</a>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="btn logout-btn <?php echo $is_secret_role ? 'secret-role-btn' : ''; ?>"><i
                class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div id="calculator-logo" style="position: absolute; top: 20px; left: 20px; cursor: move;">
        <img src="../images/calculator.png?v=1" alt="Calculator" width="50" height="50"
            onerror="this.src='./images/default-calculator.png';">
    </div>

    <?php include 'calcu.php'; ?>

    <div class="main-content">
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="kategori-header">
            <h1 class="<?php echo $is_secret_role ? 'secret-role' : ''; ?>">Manajemen Kategori</h1>
            <button onclick="openModal()"
                class="btn-tambah <?php echo $is_secret_role ? 'secret-role-btn glow-on-hover' : ''; ?>">
                <i class="fas fa-plus"></i> Tambah Kategori
            </button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nama Kategori</th>
                    <th>Jenis</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                while ($kategori = mysqli_fetch_assoc($kategori_result)): 
                ?>
                <tr class="<?php echo $is_secret_role ? 'glow-on-hover' : ''; ?>">
                    <td><?php echo $no++; ?></td>
                    <td><?php echo htmlspecialchars($kategori['nama_kategori']); ?></td>
                    <td><?php echo ucfirst($kategori['jenis']); ?></td>
                    <td>
                        <a href="katagori.php?hapus=<?php echo $kategori['kategori_id']; ?>"
                            onclick="return confirm('Yakin ingin menghapus kategori ini?')"
                            class="btn-hapus <?php echo $is_secret_role ? 'secret-role-btn' : ''; ?>">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <div id="modalKategori" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Tambah Kategori</h2>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Nama Kategori</label>
                        <input type="text" name="nama_kategori" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis</label>
                        <select name="jenis" required>
                            <option value="">Pilih Jenis</option>
                            <option value="pemasukan">Pemasukan</option>
                            <option value="pengeluaran">Pengeluaran</option>
                        </select>
                    </div>
                    <input type="hidden" name="tambah_kategori" value="1">
                    <button type="submit"
                        class="btn-tambah <?php echo $is_secret_role ? 'secret-role-btn' : ''; ?>">Simpan</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openModal() {
        document.getElementById('modalKategori').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('modalKategori').style.display = 'none';
    }

    window.onclick = function(event) {
        var modal = document.getElementById('modalKategori');
        if (event.target == modal) closeModal();
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
            localStorage.setItem('calculatorLeft', logo.style.left.replace('px', ''));
            localStorage.setItem('calculatorTop', logo.style.top.replace('px', ''));
        }
        isDraggingLogo = false;
    });

    logo.addEventListener('click', function() {
        openCalculator();
    });

    document.querySelector('#calculator-logo img').addEventListener('error', function() {
        this.src = './images/default-calculator.png';
    });
    </script>
</body>

</html>