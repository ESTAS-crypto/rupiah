<?php
require_once 'config/config.php';
require_once 'config/session_check.php';
require_once 'config/auth_check.php';  // Add this line

redirectIfLoggedIn();
header("Location: login.php");
exit();
?>