<?php
session_start();
require_once 'config.php';

if (bejelentkezve()) atiranyit('index.php');

$page_title = "Jelszó visszaállítás | BaTech";
$csrf_token = csrf_token();
$token      = trim($_GET['token'] ?? '');
$errors     = [];
$success    = false;
$valid      = false;

$conn = db();
if ($conn && $token) {
    try {
        $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        $valid = (bool)$reset;
    } catch (PDOException $e) {
        $valid = false;
    }
}

if (!$valid && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    uzenet('error', 'Érvénytelen vagy lejárt visszaállítási link!');
    atiranyit('elfelejtett_jelszo.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Érvénytelen kérés!';
    } else {
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($new) < 6) {
            $errors[] = 'A jelszó legalább 6 karakter legyen!';
        } elseif (!preg_match('/[A-Z]/', $new)) {
            $errors[] = 'A jelszó tartalmazzon legalább egy nagybetűt!';
        } elseif ($new !== $confirm) {
            $errors[] = 'A két jelszó nem egyezik!';
        } elseif ($reset) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $conn->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE email = ?")
                 ->execute([$hash, $reset['email']]);
            $conn->prepare("DELETE FROM password_resets WHERE email = ?")
                 ->execute([$reset['email']]);
            $success = true;
        } else {
            $errors[] = 'Érvénytelen vagy lejárt link!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="main.css">
</head>
<body class="<?= tema_osztaly() ?>">
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="logo"><i class="fas fa-tools"></i> BaTech<span>.</span></a>
        </div>
    </nav>

    <main class="main-content auth-page">
        <div class="container">
            <div class="auth-container">
                <div class="auth-header">
                    <i class="fas fa-key auth-logo-icon"></i>
                    <h1>Új jelszó beállítása</h1>
                </div>

                <?php if ($success): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i> Jelszava sikeresen megváltozott!
                </div>
                <p style="text-align:center;margin-top:1rem;"><a href="login.php" class="btn btn-primary">Bejelentkezés</a></p>
                <?php else: ?>
                <?php foreach ($errors as $err): ?>
                    <div class="message error"><?= e($err) ?></div>
                <?php endforeach; ?>
                <form method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="form-group">
                        <label>Új jelszó</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="new_password" placeholder="••••••••" required minlength="6">
                        </div>
                        <small class="form-hint">Min. 6 karakter, 1 nagybetű</small>
                    </div>
                    <div class="form-group">
                        <label>Jelszó megerősítése</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="confirm_password" placeholder="••••••••" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> Jelszó mentése
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script src="main.js"></script>
</body>
</html>
