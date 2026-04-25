<?php
session_start();
require_once 'config.php';

$page_title = "Kapcsolat | BaTech";
$logged_in  = bejelentkezve();
$user_name  = $logged_in ? $_SESSION['user_name'] : '';
$user_id    = $logged_in ? $_SESSION['user_id'] : null;
$is_admin   = admin_e();
$csrf_token = csrf_token();
$errors     = [];
$success    = false;

// Válasz küldése (csak bejelentkezett felhasználók)
$reply_success = false;
$reply_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Érvénytelen kérés!';
    } else {
        // ÚJ: Válasz küldése meglévő üzenetre
        if (isset($_POST['reply_to']) && is_numeric($_POST['reply_to'])) {
            $reply_to = (int)$_POST['reply_to'];
            $reply_text = trim($_POST['reply_text'] ?? '');
            
            if (!$logged_in) {
                $reply_errors[] = 'Csak bejelentkezett felhasználók válaszolhatnak!';
            } elseif (empty($reply_text)) {
                $reply_errors[] = 'A válasz megadása kötelező!';
            } else {
                $conn = db();
                if ($conn) {
                    try {
                        // Ellenőrizzük, hogy létezik-e az üzenet és a felhasználó sajátja-e
                        $stmt = $conn->prepare("SELECT id, user_id, email FROM contact_messages WHERE id = ?");
                        $stmt->execute([$reply_to]);
                        $original = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$original) {
                            $reply_errors[] = 'Az üzenet nem található!';
                        } elseif ($logged_in && $original['user_id'] != $user_id && !$is_admin) {
                            $reply_errors[] = 'Csak a saját üzeneteire válaszolhat!';
                        } else {
                            // Check last reply sender
                            $last = $conn->prepare("SELECT admin_id FROM contact_replies WHERE message_id = ? ORDER BY created_at DESC LIMIT 1");
                            $last->execute([$reply_to]);
                            $last_reply = $last->fetch(PDO::FETCH_ASSOC);
                            $last_was_user = $last_reply && $last_reply['admin_id'] === null;

                            if ($last_was_user && !$is_admin) {
                                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?error=consecutive');
                                exit;
                            } else {
                            // Válasz mentése
                            $stmt = $conn->prepare("INSERT INTO contact_replies (message_id, admin_id, reply_text, created_at) VALUES (?, ?, ?, NOW())");
                            $stmt->execute([$reply_to, $is_admin ? $user_id : null, $reply_text]);
                            
                            $conn->prepare("UPDATE contact_messages SET status = 'replied', last_reply_at = NOW(), reply_count = reply_count + 1 WHERE id = ?")->execute([$reply_to]);

                            $reply_success = true;
                            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?reply_sent=1#message-' . $reply_to);
                            exit;
                            } // end consecutive reply check
                        }
                    } catch (PDOException $e) {
                        $reply_errors[] = 'Hiba történt a válasz küldése közben!';
                        error_log("Reply error: " . $e->getMessage());
                    }
                }
            }
        } 
        // Új üzenet küldése
        else {
            if (!$logged_in) {
                $errors[] = 'Üzenet küldéséhez be kell jelentkeznie!';
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
                        $stmt = $conn->prepare("INSERT INTO contact_messages (user_id, name, email, phone, subject, message, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'new', NOW())");
                        $stmt->execute([$logged_in ? $user_id : null, $name, $email, $phone, $subject, $message]);
                        
                        // Email értesítés az adminnak
                        kuldEmail('support@batech.hu', 'Új kapcsolatfelvételi üzenet: ' . ($subject ?: 'Nincs tárgy'),
                            "<h2>Új üzenet érkezett</h2>
                            <p><strong>Feladó:</strong> " . htmlspecialchars($name) . " (" . htmlspecialchars($email) . ")</p>
                            <p><strong>Telefon:</strong> " . htmlspecialchars($phone ?: '-') . "</p>
                            <p><strong>Tárgy:</strong> " . htmlspecialchars($subject ?: '-') . "</p>
                            <p><strong>Üzenet:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>
                            <p><a href='https://" . $_SERVER['HTTP_HOST'] . "/admin.php#contacts'>Válaszadás az admin felületen</a></p>"
                        );
                        $success = true;
                        // ÁTIRÁNYÍTÁS HOGY NE KÜLDJE MÉG EGYSZER
                        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?sent=1');
                        exit;
                    } catch (PDOException $e) {
                        $errors[] = 'Hiba történt, kérjük próbálja újra!';
                        error_log("Message insert error: " . $e->getMessage());
                    }
                } else {
                    $errors[] = 'Az adatbázis jelenleg nem elérhető.';
                }
            }
            } // end logged_in check
        }
    }
}

// GET paraméterek kezelése a sikeres üzenetküldés jelzésére
if (isset($_GET['sent']) && $_GET['sent'] == 1) {
    $success = true;
}
if (isset($_GET['reply_sent']) && $_GET['reply_sent'] == 1) {
    $reply_success = true;
}

// Felhasználó üzeneteinek lekérése (ha be van jelentkezve)
$user_messages = [];
if ($logged_in && $user_id) {
    $conn = db();
    if ($conn) {
        try {
            $stmt = $conn->prepare("
                SELECT DISTINCT m.*,
                    (SELECT COUNT(*) FROM contact_replies WHERE message_id = m.id) as reply_count
                FROM contact_messages m
                WHERE m.user_id = ?
                ORDER BY m.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $user_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Válaszok betöltése minden üzenethez
            foreach ($user_messages as &$msg) {
                $reply_stmt = $conn->prepare("
                    SELECT r.*, u.name as admin_name 
                    FROM contact_replies r 
                    LEFT JOIN users u ON r.admin_id = u.id 
                    WHERE r.message_id = ? 
                    ORDER BY r.created_at ASC
                ");
                $reply_stmt->execute([$msg['id']]);
                $msg['replies'] = $reply_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log("User messages error: " . $e->getMessage());
        }
    }
}

$messages = uzenetek();

// Show error from redirect
if (isset($_GET['error']) && $_GET['error'] === 'consecutive') {
    $reply_errors[] = 'Már küldött választ. Várja meg az admin válaszát!';
}
if (isset($_GET['reply_sent'])) {
    $reply_success = true;
}
?>

<?php
// Auto-mark contact_reply notifications as read when visiting kapcsolat.php
if ($logged_in && $conn) {
    try {
        $conn->prepare("UPDATE user_notifications SET `read` = 1 WHERE user_id = ? AND type = 'contact_reply' AND `read` = 0")
             ->execute([$user_id]);
    } catch (PDOException $e) {}
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
    <style>
        /* Üzenetek listázása stílus */
        .user-messages-section {
            margin-top: 3rem;
            border-top: 2px solid var(--border-color);
            padding-top: 2rem;
        }
        
        .user-messages-section h2 {
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .message-thread {
            background: var(--bg-surface);
            border-radius: 16px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .message-header {
            padding: 1rem 1.5rem;
            background: var(--bg-surface-hover);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            cursor: pointer;
        }
        
        .message-header:hover {
            background: var(--bg-surface-active);
        }
        
        .message-subject {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 1.1rem;
        }
        
        .message-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .message-body {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-surface);
        }
        
        .message-text {
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }
        
        .reply-item {
            background: var(--bg-surface-light);
            margin: 0.75rem 0 0.75rem 2rem;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            border-left: 3px solid var(--accent-blue);
        }
        
        .reply-admin {
            border-left-color: #f59e0b;
        }
        
        .reply-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }
        
        .reply-author {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .reply-author i {
            margin-right: 0.3rem;
        }
        
        .reply-date {
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        
        .reply-text {
            color: var(--text-secondary);
            line-height: 1.4;
        }
        
        .reply-form {
            padding: 1rem 1.5rem 1.5rem;
            background: var(--bg-surface);
            border-top: 1px solid var(--border-color);
        }
        
        .reply-form textarea {
            width: 100%;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            resize: vertical;
            margin-bottom: 0.75rem;
        }
        
        .reply-form button {
            padding: 0.5rem 1rem;
            background: var(--accent-blue);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        
        .reply-form button:hover {
            opacity: 0.85;
        }
        
        .reply-form button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .message-status {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        .status-new {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-replied {
            background: #d1fae5;
            color: #065f46;
        }
        
        .dark-theme .status-new {
            background: #450a0a;
            color: #fca5a5;
        }
        
        .dark-theme .status-replied {
            background: #064e3b;
            color: #6ee7b7;
        }
        
        .toggle-icon {
            transition: transform 0.2s;
        }
        
        .message-thread.collapsed .message-body,
        .message-thread.collapsed .reply-form {
            display: none;
        }
        
        .message-thread.collapsed .toggle-icon {
            transform: rotate(-90deg);
        }
        
        .no-messages {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
            background: var(--bg-surface);
            border-radius: 16px;
        }
        
        .message.success {
            transition: opacity 0.5s ease;
        }
    </style>
</head>
<body class="<?= tema_osztaly() ?>">
    <?php $active_page = 'kapcsolat'; include 'includes/navbar.php'; ?>

    <main class="main-content">
        <div class="container">
            <h1 class="page-title">Kapcsolat</h1>
            <p class="page-subtitle">Vegye fel velünk a kapcsolatot, hamarosan válaszolunk!</p>

            <?php foreach ($messages as $msg): ?>
                <div class="message <?= $msg['type'] ?>"><?= e($msg['text']) ?></div>
            <?php endforeach; ?>
            
            <?php if ($success): ?>
                <div class="message success" id="success-msg">
                    <i class="fas fa-check-circle"></i> Üzenetét megkaptuk! Hamarosan felvesszük Önnel a kapcsolatot.
                    <script>
                        setTimeout(function() {
                            var msg = document.getElementById('success-msg');
                            if (msg) msg.style.opacity = '0.6';
                        }, 5000);
                    </script>
                </div>
            <?php endif; ?>
            
            <?php if ($reply_success): ?>
                <div class="message success" id="reply-success-msg">
                    <i class="fas fa-check-circle"></i> Válaszát elküldtük!
                    <script>
                        setTimeout(function() {
                            var msg = document.getElementById('reply-success-msg');
                            if (msg) msg.style.opacity = '0.6';
                        }, 3000);
                    </script>
                </div>
            <?php elseif (!empty($reply_errors)): ?>
                <?php foreach ($reply_errors as $err): ?>
                    <div class="message error"><?= e($err) ?></div>
                <?php endforeach; ?>
                <script>window.scrollTo({top: 0, behavior: 'smooth'});</script>
            <?php endif; ?>

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
                                <p style="color:var(--text-secondary);margin:0;"><a href="mailto:support@batech.hu">support@batech.hu</a></p>
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

                <!-- KAPCSOLATI ŰRLAP -->
                <div>
                    <?php if (!$logged_in): ?>
                    <div style="background:var(--bg-surface);border-radius:16px;padding:2rem;text-align:center;border:1px solid var(--border-color);">
                        <i class="fas fa-lock" style="font-size:2.5rem;color:var(--text-muted);margin-bottom:1rem;display:block;"></i>
                        <h3 style="color:var(--text-primary);margin-bottom:0.75rem;">Bejelentkezés szükséges</h3>
                        <p style="color:var(--text-secondary);margin-bottom:1.5rem;">Az üzenetküldéshez be kell jelentkeznie. Vendégként írhat nekünk e-mailt:</p>
                        <p style="margin-bottom:1.5rem;"><a href="mailto:support@batech.hu" class="btn btn-primary"><i class="fas fa-envelope"></i> support@batech.hu</a></p>
                        <p style="color:var(--text-muted);font-size:0.9rem;">vagy</p>
                        <div style="display:flex;gap:1rem;justify-content:center;margin-top:1rem;">
                            <a href="login.php" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Bejelentkezés</a>
                            <a href="register.php" class="btn btn-primary" style="background:var(--text-secondary);border-color:var(--text-secondary);"><i class="fas fa-user-plus"></i> Regisztráció</a>
                        </div>
                    </div>
                    <?php else: ?>
                    <?php if (!empty($errors)): ?>
                        <?php foreach ($errors as $err): ?>
                            <div class="message error"><?= e($err) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <form method="POST" id="contactForm">
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
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Üzenet küldése
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- FELHASZNÁLÓ KORÁBBI ÜZENETEI (csak bejelentkezett felhasználóknak) -->
            <?php if ($logged_in && !empty($user_messages)): ?>
            <div class="user-messages-section">
                <h2><i class="fas fa-history"></i> Korábbi üzeneteim és válaszok</h2>
                <?php foreach ($user_messages as $msg): ?>
                    <div class="message-thread" id="message-<?= $msg['id'] ?>">
                        <div class="message-header" onclick="toggleThread(this.closest('.message-thread'))">
                            <div>
                                <span class="message-subject"><?= e($msg['subject'] ?: 'Nincs tárgy') ?></span>
                                <span class="message-status status-<?= e($msg['status']) ?>">
                                    <i class="fas <?= $msg['status'] === 'new' ? 'fa-envelope' : 'fa-reply-all' ?>"></i>
                                    <?= $msg['status'] === 'new' ? 'Válaszra vár' : 'Válaszolva' ?>
                                </span>
                            </div>
                            <div class="message-meta">
                                <span><i class="fas fa-calendar-alt"></i> <?= date('Y-m-d H:i', strtotime($msg['created_at'])) ?></span>
                                <span><i class="fas fa-comments"></i> <?= (int)($msg['reply_count'] ?? 0) + 1 ?> üzenet</span>
                                <i class="fas fa-chevron-down toggle-icon"></i>
                            </div>
                        </div>
                        <div class="message-body">
                            <div class="reply-item">
                                <div class="reply-header">
                                    <span class="reply-author"><i class="fas fa-user"></i> <?= e($msg['name']) ?></span>
                                    <span class="reply-date"><?= date('Y-m-d H:i', strtotime($msg['created_at'])) ?></span>
                                </div>
                                <div class="reply-text"><?= nl2br(e($msg['message'])) ?></div>
                            </div>
                            
                            <!-- Válaszok megjelenítése -->
                            <?php if (!empty($msg['replies'])): ?>
                                <div style="margin-top: 1rem;">
                                    <strong><i class="fas fa-reply-all"></i> Válaszok:</strong>
                                    <?php foreach ($msg['replies'] as $reply): ?>
                                        <div class="reply-item <?= $reply['admin_id'] ? 'reply-admin' : '' ?>">
                                            <div class="reply-header">
                                                <span class="reply-author">
                                                    <i class="fas <?= $reply['admin_id'] ? 'fa-user-shield' : 'fa-user' ?>"></i>
                                                    <?= $reply['admin_id'] ? e($reply['admin_name'] ?? 'Admin') . ' (Admin)' : e($reply['admin_name'] ?? $_SESSION['user_name'] ?? 'Felhasználó') ?>
                                                </span>
                                                <span class="reply-date"><?= date('Y-m-d H:i', strtotime($reply['created_at'])) ?></span>
                                            </div>
                                            <div class="reply-text"><?= nl2br(e($reply['reply_text'])) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Válasz űrlap -->
                        <div class="reply-form">
                            <?php if (isset($_GET['error']) && $_GET['error'] === 'consecutive' && isset($_GET['mid']) && (int)$_GET['mid'] === $msg['id']): ?>
                            <div class="message error" style="margin-bottom:0.75rem;">
                                <i class="fas fa-exclamation-circle"></i> Már küldött választ. Várja meg az admin válaszát!
                            </div>
                            <?php endif; ?>
                            <form method="POST" class="replyForm" onsubmit="return validateReply(this)">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="reply_to" value="<?= $msg['id'] ?>">
                                <textarea name="reply_text" rows="3" placeholder="Válasz erre az üzenetre..."></textarea>
                                <button type="submit" class="replySubmitBtn"><i class="fas fa-paper-plane"></i> Válasz küldése</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($logged_in && empty($user_messages)): ?>
            <div class="user-messages-section">
                <div class="no-messages">
                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>Még nem küldött üzenetet. Használja a fenti űrlapot a kapcsolatfelvételhez.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script src="main.js"></script>
    <script>
        function toggleThread(thread) {
            thread.classList.toggle('collapsed');
        }
        
        function validateReply(form) {
            const textarea = form.querySelector('textarea[name="reply_text"]');
            if (!textarea.value.trim()) {
                alert('Kérjük, írja be a válaszát!');
                textarea.focus();
                return false;
            }
            
            // Dupla küldés megakadályozása
            const submitBtn = form.querySelector('.replySubmitBtn');
            if (submitBtn.disabled) {
                return false;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Küldés...';
            
            return true;
        }
        
        // Fő űrlap dupla küldés elleni védelem
        const contactForm = document.getElementById('contactForm');
        if (contactForm) {
            contactForm.addEventListener('submit', function(e) {
                const submitBtn = document.getElementById('submitBtn');
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return false;
                }
                
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Küldés folyamatban...';
                
                // 10 másodperc múlva újra engedélyezzük (ha valami hiba történne)
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Üzenet küldése';
                }, 10000);
                
                return true;
            });
        }
        
        // Hash alapján megnyitni a megfelelő üzenetet
        if (window.location.hash && window.location.hash.startsWith('#message-')) {
            const target = document.querySelector(window.location.hash);
            if (target) {
                target.classList.remove('collapsed');
                setTimeout(() => {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 100);
            }
        }
        
        // Alapértelmezetten a régebbi üzenetek összecsukva (az első kivételével)
        document.querySelectorAll('.message-thread').forEach((thread, index) => {
            if (index > 0) {
                thread.classList.add('collapsed');
            }
        });
        
        // Sikeres üzenet automatikus elhalványítása
        setTimeout(function() {
            const successMsg = document.getElementById('success-msg');
            if (successMsg) {
                successMsg.style.transition = 'opacity 1s ease';
                setTimeout(() => { successMsg.style.opacity = '0'; }, 3000);
                setTimeout(() => { if(successMsg) successMsg.style.display = 'none'; }, 4000);
            }
            
            const replySuccessMsg = document.getElementById('reply-success-msg');
            if (replySuccessMsg) {
                replySuccessMsg.style.transition = 'opacity 1s ease';
                setTimeout(() => { replySuccessMsg.style.opacity = '0'; }, 2000);
                setTimeout(() => { if(replySuccessMsg) replySuccessMsg.style.display = 'none'; }, 3000);
            }
        }, 100);
    </script>
</body>
</html>