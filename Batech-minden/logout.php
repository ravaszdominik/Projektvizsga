<?php
session_start();
require_once 'config.php';

// Clear remember me cookie and token from DB
if (isset($_COOKIE['remember_token'])) {
    $conn = db();
    if ($conn) {
        $stmt = $conn->prepare("DELETE FROM user_tokens WHERE token = ?");
        $stmt->execute([$_COOKIE['remember_token']]);
    }
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

// Destroy session
$_SESSION = [];
session_destroy();

header("Location: login.php");
exit();
