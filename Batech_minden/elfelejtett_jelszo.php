<?php
session_start();
require_once 'config.php';

if (bejelentkezve()) atiranyit('index.php');

$page_title = "Elfelejtett jelszó | BaTech";
$csrf_token = csrf_token();
$success    = false;
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Érvénytelen kérés!';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adjon meg egy érvényes e-mail címet!';
        } else {
            $conn = db();
            if ($conn) {
                $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $token   = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    // Delete old tokens for this email
                    $conn->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
                    $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")
                         ->execute([$email, $token, $expires]);

                    $reset_url = rtrim(SITE_URL, '/') . '/jelszo_visszaallitas.php?token=' . $token;
                    kuldEmail($email, 'BaTech - Jelszó visszaállítás',
                        "<h2>Jelszó visszaállítás</h2>
                        <p>Kedves <strong>" . htmlspecialchars($user['name']) . "</strong>!</p>
                        <p>Jelszó visszaállítási kérelmet kaptunk. Kattintson az alábbi gombra (1 óráig érvényes):</p>
                        <p style='margin:2rem 0;'>
                            <a href='{$reset_url}' style='background:#3498db;color:white;padding:0.75rem 1.5rem;border-radius:8px;text-decoration:none;'>
                                Jelszó visszaállítása
                            </a>
                        </p>
                        <p>Ha nem Ön kérte, hagyja figyelmen kívül ezt az e-mailt.</p>
                        <p>BaTech csapata</p>"
                    );
                }
                // Always show success to prevent email enumeration
                $success = true;
            }
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
                    <i class="fas fa-lock auth-logo-icon"></i>
                    <h1>Elfelejtett jelszó</h1>
                    <p>Adja meg e-mail címét és küldünk egy visszaállítási linket</p>
                </div>

                <?php if ($success): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i> Ha az e-mail cím regisztrált, hamarosan megérkezik a visszaállítási link!
                </div>
                <p style="text-align:center;margin-top:1rem;"><a href="login.php">Vissza a bejelentkezéshez</a></p>
                <?php else: ?>
                <?php foreach ($errors as $err): ?>
                    <div class="message error"><?= e($err) ?></div>
                <?php endforeach; ?>
                <form method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="form-group">
                        <label for="email">E-mail cím</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email" placeholder="pelda@email.hu" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane"></i> Link küldése
                    </button>
                    <div class="auth-footer">
                        <p><a href="login.php">Vissza a bejelentkezéshez</a></p>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script src="main.js"></script>
</body>
</html>
