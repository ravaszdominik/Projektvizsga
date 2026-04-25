<?php
session_start();
require_once 'config.php';

if (bejelentkezve()) {
    atiranyit('index.php');
}

$page_title = "Regisztráció | BaTech";
$csrf_token = csrf_token();

$errors = [];
$saved  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $terms      = isset($_POST['terms']);
    $newsletter = isset($_POST['newsletter']) ? 1 : 0;

    $saved = compact('name', 'email', 'phone', 'address');

    if (empty($name) || strlen($name) < 3) {
        $errors[] = 'A teljes név legalább 3 karakter legyen!';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adjon meg egy érvényes e-mail címet!';
    }
    if (empty($password)) {
        $errors[] = 'Az összes mezőt töltsd ki!';
    } else {
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || strlen($password) < 6) {
            $errors[] = 'Válasszon erősebb jelszót! (min. 6 karakter, 1 nagybetű, 1 szám)';
        }
        if ($password !== $confirm) {
            $errors[] = 'A két jelszó nem egyezik!';
        }
    }
    if (!$terms) {
        $errors[] = 'Az ÁSZF elfogadása kötelező!';
    }

    if (empty($errors)) {
        $conn = db();

        if (!$conn) {
            $errors[] = 'Az adatbázis jelenleg nem elérhető. Regisztráció sikertelen.';
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $check->execute([$email]);
            if ($check->fetch()) {
                $errors[] = 'Ez az e-mail cím már regisztrálva van!';
            } else {
                $hash  = password_hash($password, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(32));
                try {
                    $stmt = $conn->prepare(
                        "INSERT INTO users (name, email, phone, address, password_hash, newsletter, user_type, status, email_verified, verification_token, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 'user', 'inactive', 0, ?, NOW())"
                    );
                    $ok = $stmt->execute([$name, $email, $phone, $address, $hash, $newsletter, $token]);

                    if ($ok) {
                        $new_user_id = $conn->lastInsertId();

                        // Welcome notification
                        try {
                            $conn->prepare("INSERT INTO user_notifications (user_id, type, title, message, created_at) VALUES (?, 'welcome', ?, ?, NOW())")
                                 ->execute([
                                     $new_user_id,
                                     'Üdvözöljük a BaTech-nél!',
                                     'Sikeresen regisztrált! Most már foglalhat időpontot, írhat értékelést és tölthet fel referenciákat. Ha kérdése van, vegye fel velünk a kapcsolatot.'
                                 ]);
                        } catch (PDOException $e) {}

                        // Verification email
                        $verify_url = rtrim(SITE_URL, '/') . '/verify_email.php?token=' . $token;
                        kuldEmail($email, 'BaTech - E-mail cím megerősítése',
                            "<h2>Üdvözöljük, " . htmlspecialchars($name) . "!</h2>
                            <p>Köszönjük a regisztrációt! Kérjük, erősítse meg e-mail címét az alábbi gombra kattintva:</p>
                            <p style='margin:2rem 0;'>
                                <a href='{$verify_url}' style='background:#3498db;color:white;padding:0.75rem 1.5rem;border-radius:8px;text-decoration:none;'>
                                    E-mail cím megerősítése
                                </a>
                            </p>
                            <p>Ha nem Ön regisztrált, hagyja figyelmen kívül ezt az e-mailt.</p>
                            <p>BaTech csapata</p>"
                        );
                        uzenet('success', 'Sikeres regisztráció! Kérjük, erősítse meg e-mail címét a kiküldött levélben.');
                        atiranyit('login.php');
                    } else {
                        $db_error = implode(' | ', $stmt->errorInfo());
                        $errors[] = 'INSERT sikertelen: ' . $db_error;
                    }
                } catch (PDOException $e) {
                    $errors[] = 'Adatbázis hiba: ' . $e->getMessage();
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
                <li><a href="login.php" class="btn-login">Bejelentkezés</a></li>
                <li><a href="register.php" class="btn-register active">Regisztráció</a></li>
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
                    <h1>Regisztráció</h1>
                    <p>Hozzon létre egy új fiókot</p>
                </div>

                <?php foreach ($messages as $msg): ?>
                    <div class="message <?= e($msg['type']) ?>"><?= e($msg['text']) ?></div>
                <?php endforeach; ?>
                <?php foreach ($errors as $err): ?>
                    <div class="message error"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="POST" action="" class="auth-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <div class="form-group">
                        <label for="name">Teljes név *</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-user"></i>
                            <input type="text" id="name" name="name"
                                   value="<?= e($saved['name'] ?? '') ?>"
                                   placeholder="Kovács János"
                                   autocomplete="name"
                                   minlength="3" maxlength="100"
                                   required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email">E-mail cím *</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email"
                                   value="<?= e($saved['email'] ?? '') ?>"
                                   placeholder="pelda@email.hu"
                                   autocomplete="email"
                                   required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Telefonszám</label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-phone"></i>
                                <input type="tel" id="phone" name="phone"
                                       value="<?= e($saved['phone'] ?? '') ?>"
                                       placeholder="+36 30 123 4567"
                                       autocomplete="tel">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="address">Lakcím</label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-home"></i>
                                <input type="text" id="address" name="address"
                                       value="<?= e($saved['address'] ?? '') ?>"
                                       placeholder="Budapest, Kossuth u. 1."
                                       autocomplete="street-address">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Jelszó *</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password"
                                   placeholder="••••••••"
                                   autocomplete="new-password"
                                   minlength="6"
                                   required>
                            <button type="button" class="toggle-password" aria-label="Jelszó megjelenítése">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="form-hint">Min. 6 karakter, 1 nagybetű és 1 szám szükséges</small>
                        <div class="strength-bar"><div class="strength-bar-fill" id="strengthFill"></div></div>
                        <span class="strength-label" id="strengthLabel"></span>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Jelszó megerősítése *</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   placeholder="••••••••"
                                   autocomplete="new-password"
                                   required>
                            <button type="button" class="toggle-password" aria-label="Jelszó megjelenítése">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="match-label" id="matchLabel"></span>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="newsletter">
                            <span>Feliratkozom a hírlevélre (opcionális)</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="terms" required>
                            <span>Elfogadom az <a href="#" target="_blank">általános szerződési feltételeket</a> *</span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-user-plus"></i> Regisztráció
                    </button>

                    <div class="auth-footer">
                        <p>Már van fiókja? <a href="login.php">Jelentkezzen be</a></p>
                    </div>
                </form>

            </div>
        </div>
    </main>
   <!-- <iframe name='kisablak' id='kisablak' style="width: 100%; height: 100vh;"></iframe>-->
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

    // Password strength
    const pwdInput    = document.getElementById('password');
    const strengthFill = document.getElementById('strengthFill');
    const strengthLabel = document.getElementById('strengthLabel');

    if (pwdInput) {
        pwdInput.addEventListener('input', () => {
            const p = pwdInput.value;
            let score = 0;
            if (p.length >= 6)  score++;
            if (p.length >= 10) score++;
            if (/[A-Z]/.test(p)) score++;
            if (/[0-9]/.test(p)) score++;
            if (/[^A-Za-z0-9]/.test(p)) score++;

            strengthFill.className = 'strength-bar-fill';
            if (p.length === 0) {
                strengthLabel.textContent = '';
                return;
            }
            if (score <= 2) {
                strengthFill.classList.add('strength-weak');
                strengthLabel.textContent = 'Gyenge';
                strengthLabel.style.color = '#ef4444';
            } else if (score <= 3) {
                strengthFill.classList.add('strength-medium');
                strengthLabel.textContent = 'Közepes';
                strengthLabel.style.color = '#f59e0b';
            } else {
                strengthFill.classList.add('strength-strong');
                strengthLabel.textContent = 'Erős';
                strengthLabel.style.color = '#10b981';
            }
        });
    }

    // Password match
    const confInput  = document.getElementById('confirm_password');
    const matchLabel = document.getElementById('matchLabel');

    function checkMatch() {
        if (!confInput.value) { matchLabel.textContent = ''; return; }
        if (pwdInput.value === confInput.value) {
            matchLabel.textContent = '✓ A jelszavak egyeznek';
            matchLabel.style.color = '#10b981';
        } else {
            matchLabel.textContent = '✗ A jelszavak nem egyeznek';
            matchLabel.style.color = '#ef4444';
        }
    }

    if (pwdInput && confInput) {
        pwdInput.addEventListener('input', checkMatch);
        confInput.addEventListener('input', checkMatch);
    }
    </script>
</body>
</html>
