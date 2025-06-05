<?php
// File: logout.php
require_once 'config/config.php';

// Mulai sesi jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Hapus semua data sesi
session_unset();
session_destroy();

// Redirect ke halaman login dengan pesan sukses
header("Location: login.php?msg=logout");
exit();
?>