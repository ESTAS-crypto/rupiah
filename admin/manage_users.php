<?php
require_once '../config/config.php';

date_default_timezone_set('Asia/Jakarta');

startSession();
requireLogin();

if (!isset($_SESSION['role'])) {
    $_SESSION['error'] = "Role tidak terdefinisi. Silakan login kembali.";
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = strtolower(trim($_SESSION['role']));

checkAndClearExpiredSanctions($conn, $user_id);

$query = "SELECT role, nama_lengkap, foto_profil, warning_count, ban_status, ban_expiry, warning_expiry, last_online, created_at FROM users WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $query);
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

$db_role = strtolower(trim($user['role']));
if ($db_role !== $role) {
    $_SESSION['role'] = $db_role;
    $role = $db_role;
}

$can_access_features = in_array($role, ['owner', 'coder']);
if (!$can_access_features) {
    $_SESSION['error'] = "Anda tidak memiliki izin untuk mengakses halaman ini.";
    header("Location: dashboard.php");
    exit();
}

function checkAndAssignVeteranRole($conn, $user_id) {
    $query = "SELECT created_at FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    $created_at = strtotime($user['created_at']);
    $now = time();
    $diff = $now - $created_at;
    $years = floor($diff / (365 * 24 * 60 * 60));

    if ($years >= 1) {
        $update_query = "UPDATE users SET role = 'veteran' WHERE user_id = ? AND role NOT IN ('owner', 'coder')";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "i", $user_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
    }
}

function updateLastOnline($conn, $user_id) {
    $update_query = "UPDATE users SET last_online = CURRENT_TIMESTAMP WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

checkAndAssignVeteranRole($conn, $user_id);
updateLastOnline($conn, $user_id);

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'nama_lengkap';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'ASC';

$valid_sort_columns = ['nama_lengkap', 'role', 'saldo', 'created_at'];
if (!in_array($sort_by, $valid_sort_columns)) $sort_by = 'nama_lengkap';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'ASC';

$users_query = "SELECT u.user_id, u.nama_lengkap, u.role, u.warning_count, u.ban_status, u.ban_expiry, u.warning_expiry,
                COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'pemasukan' THEN t.jumlah ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'pengeluaran' THEN t.jumlah ELSE 0 END), 0) AS saldo,
                u.last_online, u.created_at
                FROM users u LEFT JOIN transaksi t ON u.user_id = t.user_id
                WHERE u.role != 'owner' AND u.user_id != ?";
$params = [$user_id];
$types = "i";

if (!empty($search)) {
    $users_query .= " AND (u.nama_lengkap LIKE ? OR u.role LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($status_filter)) {
    if ($status_filter === 'aktif') $users_query .= " AND u.ban_status = 'active' AND u.warning_count = 0";
    elseif ($status_filter === 'diban') $users_query .= " AND u.ban_status = 'banned'";
    elseif ($status_filter === 'peringatan') $users_query .= " AND u.warning_count > 0";
}

$users_query .= " GROUP BY u.user_id ORDER BY $sort_by $sort_order";
$stmt = mysqli_prepare($conn, $users_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $users_result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
} else {
    $_SESSION['error'] = "Gagal menyiapkan query: " . mysqli_error($conn);
    header("Location: dashboard.php");
    exit();
}

$stats_query = "SELECT 
    COUNT(CASE WHEN ban_status = 'active' AND warning_count = 0 THEN 1 END) as active_users,
    COUNT(CASE WHEN ban_status = 'banned' THEN 1 END) as banned_users,
    COUNT(CASE WHEN warning_count > 0 THEN 1 END) as warned_users
    FROM users WHERE role != 'owner' AND user_id != ?";
$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, "i", $user_id);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$stats = mysqli_fetch_assoc($stats_result);
mysqli_stmt_close($stats_stmt);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle mass notification
    if (isset($_POST['send_notification'])) {
    if (!isset($_POST['selected_users']) || empty($_POST['selected_users'])) {
        $_SESSION['error'] = "Silakan pilih setidaknya satu pengguna untuk mengirim notifikasi.";
        header("Location: manage_users.php");
        exit();
    }

    $selected_users = $_POST['selected_users'];
    $notification_message = trim($_POST['notification_message']);
    $duration_value = filter_input(INPUT_POST, 'notification_duration_value', FILTER_VALIDATE_INT);
    $duration_unit = $_POST['notification_duration_unit'] ?? '';

    if (empty($notification_message)) {
        $_SESSION['error'] = "Pesan notifikasi tidak boleh kosong.";
    } elseif (!$duration_value || !in_array($duration_unit, ['seconds', 'minutes', 'hours', 'days'])) {
        $_SESSION['error'] = "Durasi notifikasi tidak valid.";
    } else {
        $seconds = 0;
        switch ($duration_unit) {
            case 'seconds': $seconds = $duration_value; break;
            case 'minutes': $seconds = $duration_value * 60; break;
            case 'hours': $seconds = $duration_value * 3600; break;
            case 'days': $seconds = $duration_value * 86400; break;
        }
        $expiry = date('Y-m-d H:i:s', time() + $seconds);

        foreach ($selected_users as $target_user_id) {
            $target_user_id = filter_var($target_user_id, FILTER_VALIDATE_INT);
            if ($target_user_id === false || $target_user_id <= 0) {
                $_SESSION['error'] = "Salah satu ID pengguna tidak valid.";
                header("Location: manage_users.php");
                exit();
            }

            $check_query = "SELECT user_id FROM users WHERE user_id = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "i", $target_user_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            if (mysqli_num_rows($check_result) === 0) {
                $_SESSION['error'] = "Pengguna dengan ID $target_user_id tidak ditemukan.";
                header("Location: manage_users.php");
                exit();
            }
            mysqli_stmt_close($check_stmt);

            $insert_query = "INSERT INTO notifications (user_id, message, created_at, is_read, expiry) VALUES (?, ?, NOW(), 0, ?)";
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, "iss", $target_user_id, $notification_message, $expiry);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $_SESSION['success'] = "Notifikasi berhasil dikirim kepada pengguna yang dipilih.";
    }
    header("Location: manage_users.php");
    exit();
    }   

    // Handle unban users
    if (isset($_POST['unban_users']) && isset($_POST['selected_users'])) {
        $selected_users = $_POST['selected_users'];
        foreach ($selected_users as $target_user_id) {
            $target_user_id = filter_var($target_user_id, FILTER_VALIDATE_INT);
            if ($target_user_id === false || $target_user_id <= 0) {
                $_SESSION['error'] = "Salah satu ID pengguna tidak valid.";
                header("Location: manage_users.php");
                exit();
            }

            $update_query = "UPDATE users SET ban_status = 'active', ban_expiry = NULL, warning_count = 0, warning_expiry = NULL WHERE user_id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "i", $target_user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $_SESSION['success'] = "Pengguna yang dipilih telah di-unban.";
        header("Location: manage_users.php");
        exit();
    }

    // Handle bulk actions (warn or ban)
    if (isset($_POST['bulk_action']) && isset($_POST['selected_users'])) {
        $action = $_POST['bulk_action'];
        $selected_users = $_POST['selected_users'];
        $duration_value = filter_input(INPUT_POST, 'duration_value', FILTER_VALIDATE_INT);
        $duration_unit = $_POST['duration_unit'] ?? '';

        if ($action === 'warn') {
            if (!$duration_value || !$duration_unit) {
                $_SESSION['error'] = "Durasi peringatan harus diisi.";
            } else {
                $units = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];
                if (!array_key_exists($duration_unit, $units)) {
                    $_SESSION['error'] = "Unit durasi tidak valid.";
                    header("Location: manage_users.php");
                    exit();
                }
                $warning_duration = $duration_value * $units[$duration_unit];
                $warning_expiry = date('Y-m-d H:i:s', time() + $warning_duration);

                foreach ($selected_users as $target_user_id) {
                    $target_user_id = filter_var($target_user_id, FILTER_VALIDATE_INT);
                    if ($target_user_id === false || $target_user_id <= 0) {
                        $_SESSION['error'] = "Salah satu ID pengguna tidak valid.";
                        header("Location: manage_users.php");
                        exit();
                    }

                    $fetch_query = "SELECT warning_count FROM users WHERE user_id = ?";
                    $fetch_stmt = mysqli_prepare($conn, $fetch_query);
                    mysqli_stmt_bind_param($fetch_stmt, "i", $target_user_id);
                    mysqli_stmt_execute($fetch_stmt);
                    $fetch_result = mysqli_stmt_get_result($fetch_stmt);
                    $target_user = mysqli_fetch_assoc($fetch_result);
                    mysqli_stmt_close($fetch_stmt);

                    $new_warning_count = $target_user['warning_count'] + 1;

                    if ($new_warning_count >= 3) {
                        $update_query = "UPDATE users SET warning_count = ?, ban_status = 'banned', ban_expiry = NULL, warning_expiry = NULL WHERE user_id = ?";
                        $stmt = mysqli_prepare($conn, $update_query);
                        mysqli_stmt_bind_param($stmt, "ii", $new_warning_count, $target_user_id);
                    } else {
                        $update_query = "UPDATE users SET warning_count = ?, warning_expiry = ? WHERE user_id = ?";
                        $stmt = mysqli_prepare($conn, $update_query);
                        mysqli_stmt_bind_param($stmt, "isi", $new_warning_count, $warning_expiry, $target_user_id);
                    }
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                $_SESSION['success'] = "Peringatan berhasil diterapkan kepada pengguna yang dipilih.";
            }
        } elseif ($action === 'ban') {
            foreach ($selected_users as $target_user_id) {
                $target_user_id = filter_var($target_user_id, FILTER_VALIDATE_INT);
                if ($target_user_id === false || $target_user_id <= 0) {
                    $_SESSION['error'] = "Salah satu ID pengguna tidak valid.";
                    header("Location: manage_users.php");
                    exit();
                }

                $update_query = "UPDATE users SET ban_status = 'banned', ban_expiry = NULL WHERE user_id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $target_user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $_SESSION['success'] = "Ban berhasil diterapkan kepada pengguna yang dipilih.";
        }
        header("Location: manage_users.php");
        exit();
    }

    // Handle change role action
    if (isset($_POST['change_role'])) {
        $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if (!$target_user_id) {
            $_SESSION['error'] = "ID pengguna tidak valid.";
            header("Location: manage_users.php");
            exit();
        }

        $new_role = strtolower(trim($_POST['new_role'] ?? ''));
        $valid_roles = ['user', 'admin', 'coder', 'veteran'];
        if (!in_array($new_role, $valid_roles)) {
            $_SESSION['error'] = "Role tidak valid.";
        } else {
            $update_query = "UPDATE users SET role = ? WHERE user_id = ? AND role != 'owner'";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "si", $new_role, $target_user_id);
            mysqli_stmt_execute($stmt);
            $_SESSION['success'] = "Role diubah menjadi $new_role.";
            mysqli_stmt_close($stmt);
        }
        header("Location: manage_users.php");
        exit();
    }
}

if (!function_exists('getTimeRemaining')) {
    function getTimeRemaining($expiry) {
        if (!$expiry) return "Tidak ada batas waktu";
        $now = time();
        $expiry_time = strtotime($expiry);
        if ($expiry_time <= $now) return "Kadaluarsa";

        $diff = $expiry_time - $now;
        $days = floor($diff / (24 * 60 * 60));
        $hours = floor(($diff % (24 * 60 * 60)) / (60 * 60));
        $minutes = floor(($diff % (60 * 60)) / 60);
        $seconds = $diff % 60;
        return sprintf("%d hari, %d jam, %d menit, %d detik", $days, $hours, $minutes, $seconds);
    }
}

function getRoleIcon($role) {
    $icons = ['owner' => 'crown', 'coder' => 'code', 'admin' => 'user-shield', 'user' => 'user', 'veteran' => 'medal'];
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
    <link rel="stylesheet" href="../css/manage.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="image/png" />
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #34495e;
        --accent-color: #e67e22;
        --light-gray: #ecf0f1;
        --dark-gray: #7f8c8d;
        --success-color: #27ae60;
        --danger-color: #c0392b;
        --white: #ffffff;
    }

    body {
        background: var(--light-gray);
        color: var(--secondary-color);
        font-family: "Arial", sans-serif;
        transition: all 0.3s;
    }

    body.dark-mode {
        background: #1a1a1a;
        color: #ffffff;
    }

    body.dark-mode .table-container,
    body.dark-mode .card {
        background: #2e2e2e;
        color: #ffffff;
    }

    body.dark-mode .data-table th {
        background: #3a3a3a;
    }

    .content-wrapper {
        margin-left: 250px;
        padding: 20px;
    }

    .table-container {
        overflow-x: auto;
        width: 100%;
        background: var(--white);
        border-radius: 10px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .data-table th,
    .data-table td {
        padding: 15px;
        text-align: left;
        border-bottom: 1px solid var(--light-gray);
    }

    .data-table th {
        background: var(--primary-color);
        color: var(--white);
    }

    .data-table tr:hover {
        background: rgba(236, 240, 241, 0.3);
    }

    .btn {
        padding: 8px 15px;
        background: var(--accent-color);
        color: var(--white);
        border: none;
        border-radius: 5px;
        cursor: pointer;
        transition: background 0.3s;
    }

    .btn:hover {
        background: #d35400;
    }

    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }

    .alert-success {
        background: var(--success-color);
        color: var(--white);
    }

    .alert-danger {
        background: var(--danger-color);
        color: var(--white);
    }

    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
    }

    .modal-content {
        background: var(--white);
        margin: 10% auto;
        padding: 20px;
        width: 50%;
        border-radius: 10px;
    }

    .card {
        background: var(--white);
        padding: 15px;
        margin: 10px 0;
        border-radius: 5px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .search-suggestions {
        position: absolute;
        background: var(--white);
        border: 1px solid var(--light-gray);
        max-height: 200px;
        overflow-y: auto;
        width: 300px;
    }

    .search-suggestions div {
        padding: 10px;
        cursor: pointer;
    }

    .search-suggestions div:hover {
        background: var(--light-gray);
    }

    .notification-section {
        margin-top: 20px;
        padding: 15px;
        background: var(--white);
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .notification-section h3 {
        color: var(--primary-color);
        margin-bottom: 10px;
    }

    .notification-section textarea {
        width: 100%;
        height: 120px;
        padding: 10px;
        border: 1px solid var(--dark-gray);
        border-radius: 5px;
        resize: vertical;
        font-family: "Arial", sans-serif;
        font-size: 14px;
    }

    .notification-section textarea:focus {
        border-color: var(--accent-color);
        outline: none;
    }

    body.dark-mode .notification-section {
        background: #2e2e2e;
        color: #ffffff;
    }

    body.dark-mode .notification-section textarea {
        background: #3a3a3a;
        color: #ffffff;
        border-color: #555;
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
                if (timeRemaining <= 0) timer.textContent = 'Kadaluarsa';
                else {
                    const days = Math.floor(timeRemaining / (24 * 60 * 60));
                    const hours = Math.floor((timeRemaining % (24 * 60 * 60)) / (60 * 60));
                    const minutes = Math.floor((timeRemaining % (60 * 60)) / 60);
                    const seconds = timeRemaining % 60;
                    timer.textContent = `${days} hari, ${hours} jam, ${minutes} menit, ${seconds} detik`;
                }
            }
        });
        setTimeout(updateTimer, 1000);
    }

    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
    }

    function showActivityLog(userId) {
        fetch(`get_activity_log.php?user_id=${userId}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('modal-content').innerHTML =
                    `<h3>Log Aktivitas</h3><ul>${data.logs.map(log => `<li>${log}</li>`).join('')}</ul>`;
                document.getElementById('activity-modal').style.display = 'block';
            });
    }

    function closeModal() {
        document.getElementById('activity-modal').style.display = 'none';
    }

    function showSearchSuggestions() {
        const input = document.getElementById('search-input').value;
        if (input.length < 2) {
            document.getElementById('search-suggestions').innerHTML = '';
            return;
        }
        fetch(`search_suggestions.php?query=${encodeURIComponent(input)}`)
            .then(response => response.json())
            .then(data => {
                const suggestions = document.getElementById('search-suggestions');
                suggestions.innerHTML = data.map(item =>
                    `<div onclick="document.getElementById('search-input').value='${item}';suggestions.innerHTML=''">${item}</div>`
                ).join('');
            });
    }

    window.onload = function() {
        updateTimer();
        if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
        document.getElementById('select_all').addEventListener('change', function() {
            const checkboxes = document.getElementsByName('selected_users[]');
            checkboxes.forEach(checkbox => checkbox.checked = this.checked);
        });
    };

    function syncSelectedUsers() {
        const selectedUsers = document.querySelectorAll('input[name="selected_users[]"]:checked');
        const hiddenInputs = document.getElementById('unban_selected_users_container');
        hiddenInputs.innerHTML = '';
        selectedUsers.forEach(user => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_users[]';
            input.value = user.value;
            hiddenInputs.appendChild(input);
        });
    }
    </script>
</head>

<body>
    <div class="sidebar">
        <div class="profile">
            <a href="profile.php">
                <img src="<?php echo $user['foto_profil'] ? '../uploads/profil/' . $user['foto_profil'] : './images/default-profil.png'; ?>"
                    alt="Profile">
            </a>
            <h3><?php echo htmlspecialchars($user['nama_lengkap']) . ' (' . ucfirst($role) . ') ' . getRoleIcon($role); ?>
            </h3>
        </div>
        <div class="menu">
            <a href="../dashboard/dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="../dashboard/katagori.php"><i class="fas fa-tags"></i> Kategori</a>
            <a href="../dashboard/transaksi.php"><i class="fas fa-exchange-alt"></i> Transaksi</a>
            <a href="../dashboard/laporan.php"><i class="fas fa-chart-bar"></i> Laporan</a>
            <?php if (in_array($role, ['coder', 'owner',"admin"])): ?>
            <a href="approve_reset.php" class="active"><i class="fas fa-check-circle"></i> Persetujuan Reset</a>
            <?php endif; ?>


            <?php if (in_array($role, ['coder', 'owner'])): ?>
            <a href="manage_users.php" class="active"><i class="fas fa-users-cog"></i> Manajemen Pengguna</a>
            <?php endif; ?>

            <?php if (in_array($role, ['coder', 'owner'])): ?>
            <a href="troll.php" class="active"><i class="fas fa-skull-crossbones"></i> Troll</a>
            <?php endif; ?>

        </div>
        <a href="logout.php" class="btn logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <div class="content-wrapper">
        <h2>Manajemen Pengguna</h2>
        <button class="btn" onclick="toggleDarkMode()">Toggle Dark Mode</button>
        <div style="display: flex; gap: 20px; margin-bottom: 20px;">
            <div class="card">Pengguna Aktif: <?php echo $stats['active_users']; ?></div>
            <div class="card">Pengguna Diban: <?php echo $stats['banned_users']; ?></div>
            <div class="card">Pengguna dengan Peringatan: <?php echo $stats['warned_users']; ?></div>
        </div>
        <form method="GET" action="manage_users.php" style="position: relative;">
            <input type="text" id="search-input" name="search" placeholder="Cari nama atau role"
                value="<?php echo htmlspecialchars($search); ?>" onkeyup="showSearchSuggestions()">
            <div id="search-suggestions" class="search-suggestions"></div>
            <select name="status">
                <option value="">Semua Status</option>
                <option value="aktif" <?php echo $status_filter === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                <option value="diban" <?php echo $status_filter === 'diban' ? 'selected' : ''; ?>>Diban</option>
                <option value="peringatan" <?php echo $status_filter === 'peringatan' ? 'selected' : ''; ?>>Peringatan
                </option>
            </select>
            <button type="submit" class="btn">Cari</button>
        </form>
        <div>
            <a
                href="manage_users.php?sort_by=nama_lengkap&sort_order=<?php echo $sort_order === 'ASC' ? 'DESC' : 'ASC'; ?>">Sort
                by Nama</a> |
            <a href="manage_users.php?sort_by=role&sort_order=<?php echo $sort_order === 'ASC' ? 'DESC' : 'ASC'; ?>">Sort
                by Role</a>
        </div>
        <a href="export_users.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>"
            class="btn">Export to CSV</a>
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

        <!-- Form untuk Notifikasi dan Tabel Pengguna -->
        <form method="POST" action="manage_users.php" id="notification_form">
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select_all"></th>
                            <th>Nama</th>
                            <th>Role</th>
                            <th>Saldo</th>
                            <th>Peringatan</th>
                            <th>Status Ban</th>
                            <th>Terakhir Online</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($users_result)): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_users[]" value="<?php echo $row['user_id']; ?>">
                            </td>
                            <td><?php echo htmlspecialchars($row['nama_lengkap'] ?? 'N/A'); ?></td>
                            <td><?php echo ucfirst($row['role'] ?? 'N/A'); ?></td>
                            <td>Rp <?php echo number_format($row['saldo'] ?? 0, 0, ',', '.'); ?></td>
                            <td><?php echo $row['warning_count'] ?? 0; ?>/3</td>
                            <td><?php echo ($row['ban_status'] ?? 'active') === 'banned' ? "Diban" : "Aktif"; ?></td>
                            <td><?php echo $row['last_online'] ? date('d-m-Y H:i', strtotime($row['last_online'])) : 'Tidak pernah'; ?>
                            </td>
                            <td>
                                <button type="button" class="btn"
                                    onclick="showActivityLog(<?php echo $row['user_id']; ?>)">Log Aktivitas</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                                    <select name="new_role">
                                        <option value="user"
                                            <?php echo ($row['role'] ?? '') === 'user' ? 'selected' : ''; ?>>User
                                        </option>
                                        <option value="admin"
                                            <?php echo ($row['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin
                                        </option>
                                        <option value="coder"
                                            <?php echo ($row['role'] ?? '') === 'coder' ? 'selected' : ''; ?>>Coder
                                        </option>
                                        <option value="veteran"
                                            <?php echo ($row['role'] ?? '') === 'veteran' ? 'selected' : ''; ?>>Veteran
                                        </option>
                                    </select>
                                    <button type="submit" name="change_role" class="btn">Ubah Role</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <div class="notification-section">
                <h3>Kirim Notifikasi Massal</h3>
                <textarea name="notification_message" placeholder="Masukkan pesan notifikasi di sini..."
                    required></textarea>
                <label for="notification_duration_value">Durasi Notifikasi:</label>
                <input type="number" name="notification_duration_value" id="notification_duration_value" min="1"
                    placeholder="Nilai" required>
                <select name="notification_duration_unit" id="notification_duration_unit" required>
                    <option value="seconds">Detik</option>
                    <option value="minutes">Menit</option>
                    <option value="hours">Jam</option>
                    <option value="days">Hari</option>
                </select>
                <button type="submit" name="send_notification" class="btn">Kirim Notifikasi</button>
            </div>
        </form>

        <!-- Form untuk Aksi Massal -->
        <form method="POST" action="manage_users.php" id="bulk_action_form" onsubmit="syncSelectedUsers()">
            <div style="margin-top: 10px;">
                <select name="bulk_action">
                    <option value="warn">Peringatan Massal</option>
                    <option value="ban">Ban Massal</option>
                </select>
                <input type="number" name="duration_value" min="1" placeholder="Durasi" style="width: 80px;">
                <select name="duration_unit">
                    <option value="minutes" selected>Menit</option>
                    <option value="hours">Jam</option>
                    <option value="days">Hari</option>
                </select>
                <button type="submit" class="btn">Terapkan Aksi Massal</button>
            </div>
        </form>

        <!-- Form untuk Unban -->
        <form method="POST" action="manage_users.php" id="unban_form" onsubmit="syncSelectedUsers()">
            <div id="unban_selected_users_container"></div>
            <div style="margin-top: 10px;">
                <button type="submit" name="unban_users" class="btn">Unban Pengguna</button>
            </div>
        </form>

        <div id="activity-modal" class="modal">
            <div class="modal-content" id="modal-content">
                <span onclick="closeModal()" style="float:right;cursor:pointer;">×</span>
            </div>
        </div>
    </div>
</body>

</html> <?php if (in_array($role, ['coder', 'owner'])): ?>
<a href="manage_users.php" class="active"><i class="fas fa-users-cog"></i> Manajemen Pengguna</a>
<?php endif; ?>