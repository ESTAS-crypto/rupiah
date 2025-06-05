<?php
require_once '../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Initialize variables
$error = null;
$success = null;

if (isset($_POST['recover'])) {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    if (empty($username) || empty($email)) {
        $error = "Username dan alamat email harus diisi.";
    } else {
        // Check if user with the given username and email exists
        $query = "SELECT * FROM users WHERE username = '$username' AND email = '$email'";
        $result = mysqli_query($conn, $query);

        if (mysqli_num_rows($result) == 1) {
            $user = mysqli_fetch_assoc($result);
            
            // Delete any old pending password reset requests
            $query = "DELETE FROM password_reset_requests WHERE user_id = '{$user['user_id']}' AND status = 'pending'";
            mysqli_query($conn, $query);
            
            // Create a new reset request
            $query = "INSERT INTO password_reset_requests (user_id, status, created_at) 
                      VALUES ('{$user['user_id']}', 'pending', NOW())";
            if (mysqli_query($conn, $query)) {
                // Set session variables for the reset process, including WhatsApp number
                $_SESSION['reset_step'] = 1;
                $_SESSION['reset_user_id'] = $user['user_id'];
                $_SESSION['reset_username'] = $user['username'];
                $_SESSION['reset_email'] = $user['email'];
                $_SESSION['no_whatsapp'] = $user['no_whatsapp'] ?? ''; // Fetch WhatsApp from database, default to empty if null
                
                // Redirect to evidence of ownership page
                header("Location: evidence_of_ownership.php");
                exit();
            } else {
                $error = "Gagal membuat permintaan reset kata sandi: " . mysqli_error($conn);
            }
        } else {
            $error = "Username atau alamat email tidak ditemukan.";
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Lupa Password - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="../css/styles.css">
    <link rel="icon" href="../uploads/iconLogo.png" type="jpg/png" />
    <style>
    .alert {
        padding: 10px 15px;
        margin-bottom: 15px;
        border-radius: 4px;
    }

    .alert-danger {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .btn {
        display: inline-block;
        padding: 8px 15px;
        background-color: #4CAF50;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        border: none;
        cursor: pointer;
    }

    .btn:hover {
        background-color: #45a049;
    }
    </style>
</head>

<body>
    <div class="container">
        <h2>Lupa Password</h2>

        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>

        <?php if (isset($success)) { ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
        <?php } ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>USERNAME</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>ALAMAT EMAIL</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <button type="submit" name="recover" class="btn">LANJUTKAN</button>
            </div>
        </form>
        <p>Kembali ke <a href="../login.php">Login</a></p>
    </div>
</body>

</html>