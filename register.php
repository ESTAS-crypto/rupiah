<?php
require_once 'config/config.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $nama_lengkap = mysqli_real_escape_string($conn, $_POST['nama_lengkap']);

    // Password validation
    $uppercase = preg_match('@[A-Z]@', $password);
    $lowercase = preg_match('@[a-z]@', $password);
    $number    = preg_match('@[0-9]@', $password);
    $specialChars = preg_match('@[^\w]@', $password);

    if(!$uppercase || !$lowercase || !$number || !$specialChars || strlen($password) < 8) {
        $error = "Password harus minimal 8 karakter dan harus mengandung huruf besar, huruf kecil, angka, dan simbol!";
    } else {
        $check_query = "SELECT * FROM users WHERE username = '$username' OR email = '$email'";
        $check_result = mysqli_query($conn, $check_query);

        if (mysqli_num_rows($check_result) > 0) {
            $error = "Username atau email sudah terdaftar!";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $query = "INSERT INTO users (username, password, email, nama_lengkap) 
                     VALUES ('$username', '$hashed_password', '$email', '$nama_lengkap')";
            
            if (mysqli_query($conn, $query)) {
                $_SESSION['register_success'] = true;
                $_SESSION['registered_username'] = $username;
                header("Location: login.php");
                exit();
            } else {
                $error = "Error: " . mysqli_error($conn);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Register - Manajemen Keuangan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/register.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" href="uploads/iconLogo.png" type="jpg/png" />
</head>

<body>
    <div class="container">
        <h2>Register</h2>
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php } ?>
        <form method="POST" id="registerForm">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" id="password" required>
                <div class="password-requirements">
                    Password harus memiliki:
                    <ul>
                        <li id="length"><i class="fa fa-circle"></i> Minimal 8 karakter</li>
                        <li id="uppercase"><i class="fa fa-circle"></i> Minimal 1 huruf besar</li>
                        <li id="lowercase"><i class="fa fa-circle"></i> Minimal 1 huruf kecil</li>
                        <li id="number"><i class="fa fa-circle"></i> Minimal 1 angka</li>
                        <li id="symbol"><i class="fa fa-circle"></i> Minimal 1 simbol</li>
                    </ul>
                </div>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" name="nama_lengkap" required
                    value="<?php echo isset($_POST['nama_lengkap']) ? htmlspecialchars($_POST['nama_lengkap']) : ''; ?>">
            </div>
            <div class="form-group">
                <button type="submit" class="btn">Register</button>
            </div>
            <p>Sudah punya akun? <a href="login.php">Login di sini</a></p>
        </form>
    </div>

    <script>
    const password = document.getElementById('password');
    const requirements = {
        length: document.getElementById('length'),
        uppercase: document.getElementById('uppercase'),
        lowercase: document.getElementById('lowercase'),
        number: document.getElementById('number'),
        symbol: document.getElementById('symbol')
    };

    password.addEventListener('input', function() {
        const value = this.value;

        // Check length
        if (value.length >= 8) {
            requirements.length.classList.add('valid');
        } else {
            requirements.length.classList.remove('valid');
        }

        // Check uppercase
        if (/[A-Z]/.test(value)) {
            requirements.uppercase.classList.add('valid');
        } else {
            requirements.uppercase.classList.remove('valid');
        }

        // Check lowercase
        if (/[a-z]/.test(value)) {
            requirements.lowercase.classList.add('valid');
        } else {
            requirements.lowercase.classList.remove('valid');
        }

        // Check number
        if (/[0-9]/.test(value)) {
            requirements.number.classList.add('valid');
        } else {
            requirements.number.classList.remove('valid');
        }

        // Check symbol
        if (/[^A-Za-z0-9]/.test(value)) {
            requirements.symbol.classList.add('valid');
        } else {
            requirements.symbol.classList.remove('valid');
        }
    });
    </script>
</body>

</html>