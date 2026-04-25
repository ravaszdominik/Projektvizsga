<?php
session_start();
require_once 'config.php';

if (bejelentkezve()) {
    atiranyit('index.php');
}

$page_title = "Bejelentkezés | BaTech";
$csrf_token = csrf_token();

$errors      = [];
$saved_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember_me']);
    $saved_email = $email;

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adjon meg egy érvényes e-mail címet!';
    }
    if (empty($password)) {
        $errors[] = 'A jelszó megadása kötelező!';
    }

    if (empty($errors)) {
        $conn = db();

        if (!$conn) {
            $errors[] = 'Az adatbázis jelenleg nem elérhető. Kérjük, próbálja újra később.';
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            // Rate limiting
            try {
                $att = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                $att->execute([$ip]);
                if ((int)$att->fetchColumn() >= 5) {
                    $errors[] = 'Túl sok sikertelen kísérlet. Kérjük, várjon 15 percet!';
                }
            } catch (PDOException $e) {}

            if (empty($errors)) {
                // Törölt fiók ellenőrzése
                try {
                    $del = $conn->prepare("SELECT deleted_at FROM deleted_users WHERE email = ? ORDER BY deleted_at DESC LIMIT 1");
                    $del->execute([$email]);
                    $deleted = $del->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) { $deleted = null; }

                if ($deleted) {
                    $errors[] = '⚠️ Ez a fiók törlésre került (' . date('Y. m. d.', strtotime($deleted['deleted_at'])) . '). Kérjük, ellenőrizze e-mail fiókját!';
                } else {
                    $stmt = $conn->prepare("SELECT id, name, email, password_hash, user_type, status, email_verified FROM users WHERE email = ? AND status IN ('active','inactive') LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user && password_verify($password, $user['password_hash'])) {
                        if (isset($user['email_verified']) && $user['email_verified'] == 0) {
                            $errors[] = '⚠️ E-mail cím nincs megerősítve! Kérjük, ellenőrizze postaládáját.';
                        } elseif ($user['status'] !== 'active') {
                            $errors[] = 'A fiók inaktív. Kérjük, vegye fel velünk a kapcsolatot.';
                        } else {
                            session_regenerate_id(true);
                            $_SESSION['user_id']    = $user['id'];
                            $_SESSION['user_name']  = $user['name'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['user_type']  = $user['user_type'];
                            $_SESSION['is_admin']   = ($user['user_type'] === 'admin');

                            if ($remember) {
                                try {
                                    $token  = bin2hex(random_bytes(32));
                                    $expiry = time() + (30 * 24 * 60 * 60);
                                    $conn->prepare("INSERT INTO user_tokens (user_id, token, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))")
                                         ->execute([$user['id'], $token, $expiry]);
                                    setcookie('remember_token', $token, $expiry, '/', '', false, true);
                                } catch (PDOException $e) {}
                            }

                            uzenet('success', 'Sikeres bejelentkezés! Üdvözöljük, ' . e($user['name']) . '!');
                            atiranyit($user['user_type'] === 'admin' ? 'admin.php' : 'index.php');
                        }
                    } else {
                        try {
                            $conn->prepare("INSERT INTO login_attempts (ip, email) VALUES (?, ?)")->execute([$ip, $email]);
                        } catch (PDOException $e) {}
                        $errors[] = 'Hibás e-mail cím vagy jelszó!';
                    }
                }
            }
        }
    }
}

$messages = uzenetek();
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
            <a href="index.php" class="logo">
                <i class="fas fa-tools"></i> BaTech<span>.</span>
            </a>
            <button class="menu-toggle" id="menuToggle" aria-label="Menü">
                <i class="fas fa-bars"></i>
            </button>
            <ul class="nav-links" id="navLinks">
                <li><a href="index.php">Főoldal</a></li>
                <li><a href="arak.php">Szolgáltatások & Árak</a></li>
                <li><a href="referenciak.php">Referenciák</a></li>
                <li><a href="ertekelesek.php">Értékelések</a></li>
                <li><a href="login.php" class="btn-login active">Bejelentkezés</a></li>
                <li><a href="register.php" class="btn-register">Regisztráció</a></li>
                <li>
                    <a href="?dark=<?= tema_beallitas() ? '0' : '1' ?>" class="theme-toggle">
                        <i class="fas fa-<?= tema_beallitas() ? 'sun' : 'moon' ?>"></i>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <main class="main-content auth-page">
        <div class="container">
            <div class="auth-container">

                <div class="auth-header">
                    <i class="fas fa-tools auth-logo-icon"></i>
                    <h1>Bejelentkezés</h1>
                    <p>Üdvözöljük a BaTech oldalán</p>
                </div>

                <?php foreach ($messages as $msg): ?>
                    <div class="message <?= e($msg['type']) ?>"><?= e($msg['text']) ?></div>
                <?php endforeach; ?>
                <?php foreach ($errors as $err): ?>
                    <div class="message error"><?= e($err) ?></div>
                <?php endforeach; ?>

                <!-- JAVÍTVA: action="/login.php" helyett action="" (jelenlegi oldal) -->
                <form method="POST" action="" class="auth-form" novalidate>

                    <div class="form-group">
                        <label for="email">E-mail cím</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email"
                                   value="<?= e($saved_email) ?>"
                                   placeholder="pelda@email.hu"
                                   autocomplete="email"
                                   required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Jelszó</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password"
                                   placeholder="••••••••"
                                   autocomplete="current-password"
                                   required>
                            <button type="button" class="toggle-password" aria-label="Jelszó megjelenítése">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="checkbox-label">
                            <input type="checkbox" name="remember_me">
                            <span>Emlékezz rám 30 napig</span>
                        </label>
                        <a href="elfelejtett_jelszo.php" style="font-size:0.9rem;">Elfelejtett jelszó?</a>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i> Bejelentkezés
                    </button>

                    <div class="auth-footer">
                        <p>Nincs még fiókja? <a href="register.php">Regisztráljon itt</a></p>
                    </div>
                </form>

                <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border-color);text-align:center;">
                    <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:0.75rem;">Kipróbálná a rendszert?</p>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="email" value="test@example.com">
                        <input type="hidden" name="password" value="Test123">
                        <button type="submit" class="btn btn-secondary" style="width:100%;">
                            <i class="fas fa-user"></i> Bejelentkezés tesztfiókkal
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> BaTech Kft. - Minden jog fenntartva.</p>
            </div>
        </div>
    </footer>

    <script src="main.js"></script>
    <script>
    // Toggle password visibility
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.closest('.input-icon-wrap').querySelector('input');
            const icon  = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    });
    </script>
</body>
</html>