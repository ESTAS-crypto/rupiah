<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/config.php';

// Set timezone agar waktu PHP dan MySQL sinkron (sesuaikan dengan zona waktu Anda)
date_default_timezone_set('Asia/Jakarta');

// Cek apakah pengguna sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Ambil data pengguna termasuk role
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user === null) {
        $error = "Pengguna tidak ditemukan.";
    } else {
        $allowed_roles = ['admin', 'coder', 'owner'];
        if (!in_array($user['role'], $allowed_roles)) {
            $error = "Akses ditolak. Halaman ini hanya untuk Admin, Coder, dan Owner.";
        }
    }
} catch (Exception $e) {
    $error = "Error koneksi database: " . $e->getMessage();
}

// Fungsi untuk ikon berdasarkan role
function getRoleIcon($role) {
    switch ($role) {
        case 'admin': return '<i class="fas fa-user-shield"></i>';
        case 'coder': return '<i class="fas fa-code"></i>';
        case 'owner': return '<i class="fas fa-star"></i>';
        case 'user': return '<i class="fas fa-user"></i>';
        default: return '';
    }
}

// Fungsi untuk membuat link WhatsApp
function sendWhatsAppMessage($number, $message) {
    if (empty($number) || !preg_match('/^[0-9]+$/', $number)) {
        return false;
    }
    if (substr($number, 0, 2) !== '62') {
        $number = '62' . substr($number, 1);
    }
    return "https://wa.me/" . urlencode($number) . "?text=" . urlencode($message);
}

// Proses persetujuan atau penolakan
$success_message = '';
$debug_messages = [];
if (isset($_POST['approve']) || isset($_POST['reject'])) {
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    if ($request_id === false || $request_id === null) {
        $error = "ID Permintaan tidak valid.";
    } else {
        $status = isset($_POST['approve']) ? 'approved' : 'rejected';

        try {
            // Cek status permintaan saat ini
            $check_stmt = $conn->prepare("SELECT status FROM password_reset_requests WHERE id = ?");
            $check_stmt->bind_param("i", $request_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $request_data = $check_result->fetch_assoc();

            if ($request_data === null) {
                $error = "Permintaan tidak ditemukan.";
            } else {
                $current_status = $request_data['status'];
                if ($current_status !== 'pending') {
                    $error = "Permintaan ini sudah diproses sebelumnya.";
                } else {
                    if ($status === 'approved') {
                        $verification_code = rand(100000, 999999);
                        $expired_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
                        $update_stmt = $conn->prepare("UPDATE password_reset_requests SET status = ?, approved_at = NOW(), verification_code = ?, expired_at = ? WHERE id = ?");
                        $update_stmt->bind_param("sisi", $status, $verification_code, $expired_at, $request_id);
                        if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
                            // Debugging: Cek apa yang disimpan di database
                            $debug_stmt = $conn->prepare("SELECT status, expired_at FROM password_reset_requests WHERE id = ?");
                            $debug_stmt->bind_param("i", $request_id);
                            $debug_stmt->execute();
                            $debug_result = $debug_stmt->get_result();
                            $updated_data = $debug_result->fetch_assoc();
                            

                            // Ambil nomor WhatsApp
                            $whatsapp_stmt = $conn->prepare("SELECT u.no_whatsapp FROM users u JOIN password_reset_requests prr ON u.user_id = prr.user_id WHERE prr.id = ?");
                            $whatsapp_stmt->bind_param("i", $request_id);
                            $whatsapp_stmt->execute();
                            $whatsapp_result = $whatsapp_stmt->get_result();
                            $reset_user = $whatsapp_result->fetch_assoc();

                            if ($reset_user && !empty($reset_user['no_whatsapp'])) {
                                $message = "Kode verifikasi reset kata sandi Anda: $verification_code. Kode ini akan kadaluarsa dalam 5 menit.";
                                $whatsapp_link = sendWhatsAppMessage($reset_user['no_whatsapp'], $message);
                                if ($whatsapp_link) {
                                    $_SESSION['approved_request_id'] = $request_id;
                                    $_SESSION['verification_code_' . $request_id] = $verification_code;
                                    $_SESSION['whatsapp_link_' . $request_id] = $whatsapp_link;
                                    $success_message = "Permintaan disetujui. Kode verifikasi: <strong>$verification_code</strong> 
                                                        <button class='copy-btn' onclick='copyToClipboard(\"$verification_code\")'>Salin Kode</button><br>
                                                        Kirim via WhatsApp: <a href='$whatsapp_link' target='_blank' onclick='openWhatsApp(\"$whatsapp_link\")'>Kirim manual</a>";
                                } else {
                                    $error = "Nomor WhatsApp tidak valid.";
                                }
                            } else {
                                $error = "Nomor WhatsApp pengguna tidak tersedia.";
                            }
                        } else {
                            $error = "Gagal memperbarui permintaan: " . $conn->error;
                        }
                    } else {
                        $update_stmt = $conn->prepare("UPDATE password_reset_requests SET status = ?, approved_at = NOW() WHERE id = ?");
                        $update_stmt->bind_param("si", $status, $request_id);
                        if ($update_stmt->execute()) {
                            $success_message = "Permintaan ditolak.";
                        } else {
                            $error = "Gagal memproses penolakan: " . $conn->error;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error saat memproses permintaan: " . $e->getMessage());
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Ambil permintaan pending
$pending_query = "SELECT prr.id, prr.user_id, u.username, prr.evidence, prr.created_at FROM password_reset_requests prr JOIN users u ON prr.user_id = u.user_id WHERE prr.status = 'pending'";
$pending_result = mysqli_query($conn, $pending_query);
if (!$pending_result) {
    $error = "Gagal mengambil data pending: " . mysqli_error($conn);
}

// Ambil permintaan approved yang belum kadaluarsa
$approved_query = "SELECT prr.id, prr.user_id, u.username, prr.verification_code, prr.expired_at FROM password_reset_requests prr JOIN users u ON prr.user_id = u.user_id WHERE prr.status = 'approved' AND prr.expired_at > NOW()";
$approved_result = mysqli_query($conn, $approved_query); // Perbaikan: Hapus real_escape_string dari kueri
if (!$approved_result) {
    $error = "Gagal mengambil data approved: " . mysqli_error($conn);
}



?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persetujuan Reset Password - Admin/Coder/Owner</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="../css/approve.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="jpg/png" />
    <style>
    body {
        font-family: 'Inter', sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f4f4f4;
    }

    .sidebar {
        width: 250px;
        background-color: #2c3e50;
        color: white;
        position: fixed;
        height: 100%;
        padding-top: 20px;
    }

    .sidebar .profile img {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        display: block;
        margin: 0 auto;
    }

    .sidebar .menu a {
        display: block;
        padding: 10px 20px;
        color: white;
        text-decoration: none;
    }

    .sidebar .menu a.active {
        background-color: #34495e;
    }

    .container {
        margin-left: 270px;
        padding: 20px;
    }

    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }

    .alert-success {
        background-color: #d4edda;
        color: #155724;
    }

    .alert-danger {
        background-color: #f8d7da;
        color: #721c24;
    }

    .debug-info {
        background-color: #fff3cd;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .table th,
    .table td {
        padding: 10px;
        border: 1px solid #ddd;
        text-align: left;
    }

    .table th {
        background-color: #f2f2f2;
    }

    .btn {
        padding: 5px 10px;
        border: none;
        cursor: pointer;
        border-radius: 3px;
    }

    .btn-danger {
        background-color: #dc3545;
        color: white;
    }

    .copy-btn {
        background-color: #007bff;
        color: white;
        margin-left: 10px;
    }

    .text-center {
        text-align: center;
    }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="profile">
            <a href="profile.php">
                <img src="<?php echo !empty($user['foto_profil']) ? '../uploads/profil/' . htmlspecialchars($user['foto_profil']) : './images/default-profil.png'; ?>"
                    alt="Profile">
            </a>
            <h3><?php echo htmlspecialchars($user['nama_lengkap']) . ' (' . ucfirst($user['role']) . ') ' . getRoleIcon($user['role']); ?>
            </h3>
        </div>
        <div class="menu">
            <a href="../dashboard/dashboard.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-home"></i> Dashboard</a>
            <a href="../dashboard/katagori.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'katagori.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-tags"></i> Kategori</a>
            <a href="../dashboard/transaksi.php"
                <?php echo basename($_SERVER['PHP_SELF']) == 'transaksi.php' ? 'class="active"' : ''; ?>><i
                    class="fas fa-exchange-alt"></i> Transaksi</a>
            <a href="../dashboard/laporan.php"
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
        <a href="../logout.php" class="btn logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="container">
        <h2>Persetujuan Reset Password</h2>

        <!-- Tampilkan informasi debug -->
        <?php if (!empty($debug_messages)): ?>
        <div class="debug-info">
            <?php echo implode("<br>", $debug_messages); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Tabel Permintaan Pending -->
        <h3>Permintaan Pending</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>ID Permintaan</th>
                    <th>Username</th>
                    <th>Bukti</th>
                    <th>Dibuat Pada</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pending_result && mysqli_num_rows($pending_result) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($pending_result)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                    <td><?php echo htmlspecialchars($row['evidence']); ?></td>
                    <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                    <td>
                        <form method="POST" action="">
                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($row['id']); ?>">
                            <button type="submit" name="approve" class="btn">Setujui</button>
                            <button type="submit" name="reject" class="btn btn-danger">Tolak</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center">Tidak ada permintaan pending saat ini.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Tabel Permintaan Approved (Aktif) -->
        <h3>Permintaan Approved (Aktif)</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>ID Permintaan</th>
                    <th>Username</th>
                    <th>Kode Verifikasi</th>
                    <th>Sisa Waktu</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($approved_result && mysqli_num_rows($approved_result) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($approved_result)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                    <td><?php echo htmlspecialchars($row['verification_code']); ?></td>
                    <td>
                        <span class="timer" data-expiry="<?php echo htmlspecialchars($row['expired_at']); ?>">
                            Hitungan Mundur
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="4" class="text-center">Tidak ada permintaan approved aktif saat ini.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    // Fungsi untuk memperbarui timer
    function updateTimer() {
        const timers = document.querySelectorAll('.timer');
        timers.forEach(timer => {
            const expiry = timer.getAttribute('data-expiry');
            if (expiry) {
                const expiryTime = new Date(expiry).getTime();
                const now = new Date().getTime();
                const timeRemaining = Math.max(0, Math.floor((expiryTime - now) / 1000));
                if (timeRemaining <= 0) {
                    timer.textContent = 'Kadaluarsa';
                    timer.style.color = 'red';
                    // Refresh halaman setelah kadaluarsa untuk memperbarui tabel
                    setTimeout(() => location.reload(), 1000);
                } else {
                    const minutes = Math.floor(timeRemaining / 60);
                    const seconds = timeRemaining % 60;
                    timer.textContent = `${minutes} menit, ${seconds} detik`;
                    timer.style.color = 'black';
                }
            }
        });
    }

    // Jalankan timer setiap detik dan saat halaman dimuat
    setInterval(updateTimer, 1000);
    window.onload = updateTimer;

    // Fungsi untuk menyalin kode ke clipboard
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text)
                .then(() => alert('Kode disalin ke clipboard'))
                .catch(err => alert('Gagal menyalin kode: ' + err.message));
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                alert('Kode disalin ke clipboard');
            } catch (err) {
                alert('Gagal menyalin kode: ' + err.message);
            }
            document.body.removeChild(textarea);
        }
    }

    // Fungsi untuk membuka WhatsApp
    function openWhatsApp(url) {
        if (confirm('Buka WhatsApp untuk mengirim kode?')) {
            window.open(url, '_blank');
        }
        return false;
    }
    </script>
</body>

</html>