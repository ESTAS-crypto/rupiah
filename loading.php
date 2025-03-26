<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek jika belum login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Jika sudah melalui loading, redirect ke dashboard
if (isset($_SESSION['loading_shown']) && $_SESSION['loading_shown'] === true) {
    header("Location: dashboard.php");
    exit();
}

// Set flag loading sudah ditampilkan
$_SESSION['loading_shown'] = true;
?>

<!DOCTYPE html>
<html>

<head>
    <title>Loading - Manajemen Keuangan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/loading.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
</head>

<body>
    <div class="money-particles" id="particles"></div>
    <div class="loading-container">
        <div class="loading-decoration decoration-1">₹</div>
        <div class="loading-decoration decoration-2">$</div>

        <div class="loading-title">
            <i class="fas fa-wallet"></i> Manajemen Keuangan
        </div>

        <div class="loading-coin">Rp</div>

        <div class="loading-text">
            <i class="fas fa-sync-alt fa-spin"></i> Memuat data keuangan Anda...
        </div>

        <div class="loading-bar">
            <div class="loading-progress"></div>
        </div>

        <div class="loading-status">Menyiapkan dashboard...</div>
    </div>

    <script>
    function createParticles() {
        const container = document.getElementById('particles');
        const symbols = ['💰', '💸', '₹', 'Rp', '$'];

        for (let i = 0; i < 20; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.left = Math.random() * 100 + '%';
            particle.style.animationDelay = Math.random() * 3 + 's';
            particle.innerHTML = symbols[Math.floor(Math.random() * symbols.length)];
            container.appendChild(particle);
        }
    }

    createParticles();

    // Redirect ke dashboard setelah loading
    setTimeout(() => {
        window.location.href = 'dashboard.php';
    }, 5500);
    </script>
</body>

</html>