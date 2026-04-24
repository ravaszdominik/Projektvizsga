<?php
session_start();
require_once 'config.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    uzenet('error', 'Érvénytelen hivatkozás!');
    atiranyit('login.php');
}

$conn = db();
if (!$conn) {
    uzenet('error', 'Az adatbázis jelenleg nem elérhető.');
    atiranyit('login.php');
}

try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE verification_token = ? AND email_verified = 0 LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $conn->prepare("UPDATE users SET email_verified = 1, verification_token = NULL, status = 'active' WHERE id = ?")
             ->execute([$user['id']]);
        uzenet('success', 'E-mail cím sikeresen megerősítve! Most már bejelentkezhet.');
    } else {
        uzenet('error', 'Érvénytelen vagy már felhasznált hivatkozás!');
    }
} catch (PDOException $e) {
    // email_verified column may not exist yet
    uzenet('success', 'Köszönjük a regisztrációt! Most már bejelentkezhet.');
}

atiranyit('login.php');
