<?php
// login.php, register.php, logout.php
require_once 'config/config.php';
require_once 'config/auth_check.php';
session_start();
session_destroy();
header("Location: login.php");
exit();
?>