<?php
session_start();
require_once 'config.php';

$page_title = "Kapcsolat | BaTech";
$logged_in  = bejelentkezve();
$user_name  = $logged_in ? $_SESSION['user_name'] : '';
$is_admin   = admin_e();
$csrf_token = csrf_token();
$errors     = [];
$success    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Érvénytelen kérés!';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (empty($name))    $errors[] = 'A név megadása kötelező!';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Érvényes e-mail cím szükséges!';
        if (empty($message)) $errors[] = 'Az üzenet megadása kötelező!';

        if (empty($errors)) {
            $conn = db();
            if ($conn) {
                try {
                    $conn->prepare("INSERT INTO contact_messages (name, email, phone, subject, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
                         ->execute([$name, $email, $phone, $subject, $message]);
                    // Email értesítés az adminnak
                    kuldEmail('info@batech.hu', 'Új kapcsolatfelvételi üzenet: ' . ($subject ?: 'Nincs tárgy'),
                        "<h2>Új üzenet érkezett</h2>
                        <p><strong>Feladó:</strong> " . htmlspecialchars($name) . " (" . htmlspecialchars($email) . ")</p>
                        <p><strong>Telefon:</strong> " . htmlspecialchars($phone ?: '-') . "</p>
                        <p><strong>Tárgy:</strong> " . htmlspecialchars($subject ?: '-') . "</p>
                        <p><strong>Üzenet:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>"
                    );
                    $success = true;
                } catch (PDOException $e) {
                    $errors[] = 'Hiba történt, kérjük próbálja újra!';
                }
            } else {
                $errors[] = 'Az adatbázis jelenleg nem elérhető.';
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
            <a href="index.php" class="logo"><i class="fas fa-tools"></i> BaTech<span>.</span></a>
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            <ul class="nav-links" id="navLinks">
                <li><a href="index.php">Főoldal</a></li>
                <li><a href="arak.php">Szolgáltatások & Árak</a></li>
                <li><a href="referenciak.php">Referenciák</a></li>
                <li><a href="ertekelesek.php">Értékelések</a></li>
                <li><a href="kapcsolat.php" class="active">Kapcsolat</a></li>
                <?php if ($logged_in): ?>
                    <?php if ($is_admin): ?><li><a href="admin.php" class="btn-admin">Admin</a></li><?php endif; ?>
                    <li><a href="profil.php" class="btn-login"><i class="fas fa-user"></i> <?= e($user_name) ?></a></li>
                    <?php $bell_count = get_unread_notifications(); ?><li><a href="profil.php#notifications" class="btn-login" style="position:relative;" title="Értesítések"><i class="fas fa-bell"></i><?php if ($bell_count > 0): ?><span style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:white;border-radius:50%;width:18px;height:18px;font-size:0.7rem;display:flex;align-items:center;justify-content:center;font-weight:bold;"><?= $bell_count ?></span><?php endif; ?></a></li>
                    <li><a href="logout.php" class="btn-register">Kijelentkezés</a></li>
                <?php else: ?>
                    <li><a href="login.php" class="btn-login">Bejelentkezés</a></li>
                    <li><a href="register.php" class="btn-register">Regisztráció</a></li>
                <?php endif; ?>
                <li>
                    <a href="?dark=<?= tema_beallitas() ? '0' : '1' ?>" class="theme-toggle">
                        <i class="fas fa-<?= tema_beallitas() ? 'sun' : 'moon' ?>"></i>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <main class="main-content">
        <div class="container">
            <h1 class="page-title">Kapcsolat</h1>
            <p class="page-subtitle">Vegye fel velünk a kapcsolatot, hamarosan válaszolunk!</p>

            <?php foreach ($messages as $msg): ?>
                <div class="message <?= $msg['type'] ?>"><?= e($msg['text']) ?></div>
            <?php endforeach; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:3rem;margin-top:2rem;">

                <!-- KAPCSOLATI ADATOK -->
                <div>
                    <h2 style="margin-bottom:1.5rem;color:var(--text-primary);">Elérhetőségeink</h2>
                    <div style="display:flex;flex-direction:column;gap:1.25rem;">
                        <div style="display:flex;align-items:center;gap:1rem;">
                            <div style="width:48px;height:48px;background:var(--accent-blue);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.2rem;flex-shrink:0;">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div>
                                <strong style="color:var(--text-primary);">Cím</strong>
                                <p style="color:var(--text-secondary);margin:0;">1234 Budapest, Víz út 5.</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:1rem;">
                            <div style="width:48px;height:48px;background:var(--accent-blue);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.2rem;flex-shrink:0;">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div>
                                <strong style="color:var(--text-primary);">Telefon</strong>
                                <p style="color:var(--text-secondary);margin:0;"><a href="tel:+3612345678">+36 1 234 5678</a></p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:1rem;">
                            <div style="width:48px;height:48px;background:var(--accent-blue);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.2rem;flex-shrink:0;">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div>
                                <strong style="color:var(--text-primary);">E-mail</strong>
                                <p style="color:var(--text-secondary);margin:0;"><a href="mailto:info@batech.hu">info@batech.hu</a></p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:1rem;">
                            <div style="width:48px;height:48px;background:#10b981;border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.2rem;flex-shrink:0;">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <strong style="color:var(--text-primary);">Nyitvatartás</strong>
                                <p style="color:var(--text-secondary);margin:0;">H–P: 8:00–18:00<br>Sürgősségi: 0–24</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KAPCSOLATI ŰR LAP -->
                <div>
                    <?php if ($success): ?>
                    <div class="message success">
                        <i class="fas fa-check-circle"></i> Üzenetét megkaptuk! Hamarosan felvesszük Önnel a kapcsolatot.
                    </div>
                    <?php else: ?>
                    <?php foreach ($errors as $err): ?>
                        <div class="message error"><?= e($err) ?></div>
                    <?php endforeach; ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Név *</label>
                                <input type="text" name="name" value="<?= e($_POST['name'] ?? ($user_name ?: '')) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>E-mail *</label>
                                <input type="email" name="email" value="<?= e($_POST['email'] ?? ($_SESSION['user_email'] ?? '')) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Telefon</label>
                                <input type="tel" name="phone" value="<?= e($_POST['phone'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Tárgy</label>
                                <input type="text" name="subject" value="<?= e($_POST['subject'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Üzenet *</label>
                            <textarea name="message" rows="5" required placeholder="Írja le kérdését vagy kérését..."><?= e($_POST['message'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Üzenet küldése</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-info">
                    <h3>Elérhetőségeink</h3>
                    <p><i class="fas fa-map-marker-alt"></i> 1234 Budapest, Víz út 5.</p>
                    <p><i class="fas fa-phone"></i> +36 1 234 5678</p>
                    <p><i class="fas fa-envelope"></i> info@batech.hu</p>
                </div>
                <div class="footer-links">
                    <h3>Gyors linkek</h3>
                    <ul>
                        <li><a href="index.php">Főoldal</a></li>
                        <li><a href="arak.php">Árak</a></li>
                        <li><a href="referenciak.php">Referenciák</a></li>
                        <li><a href="kapcsolat.php">Kapcsolat</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> BaTech. - Minden jog fenntartva.</p>
            </div>
        </div>
    </footer>

    <script src="main.js"></script>
</body>
</html>
