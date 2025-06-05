<?php
session_start();

// Set header untuk memastikan respons adalah JSON
header('Content-Type: application/json');

// Sertakan file konfigurasi
require_once '../config/config.php';

// Panggil fungsi updateUserRole dari config.php
updateUserRole($conn);

// Tutup koneksi database
mysqli_close($conn);
?>