<?php
// ============================================
// config.php - XAMPP KÉSZ, TELJESEN MŰKÖDIK!
// ============================================
// FIGYELEM: ITT NINCS session_start() !!!
// A session_start() MINDEN PHP fájl ELSŐ SORA!
// ============================================

date_default_timezone_set('Europe/Budapest');

// ============================================
// KÖRNYEZETI VÁLTOZÓK BETÖLTÉSE (.env)
// ============================================
$env_file = dirname(__DIR__, 2) . '/.env';
if (file_exists($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        if (!empty($key)) $_ENV[$key] = $val;
    }
}

// ============================================
// WEBOLDAL BEÁLLÍTÁSOK
// ============================================
define('SITE_URL',      $_ENV['SITE_URL']      ?? 'https://batech.hu');
define('SITE_NAME',     'Vízművek');
define('UPLOAD_PATH',   'uploads/');
define('AVATAR_PATH',   'uploads/avatars/');
define('REFERENCE_PATH','uploads/references/');
define('TINIFY_API_KEY',$_ENV['TINIFY_KEY']    ?? 'tg5Mm6m4FwSQyzynJ5G0wPWdKFcmLn0D');

// Tinify
require_once __DIR__ . '/tinify/Tinify/Exception.php';
require_once __DIR__ . '/tinify/Tinify/ResultMeta.php';
require_once __DIR__ . '/tinify/Tinify/Result.php';
require_once __DIR__ . '/tinify/Tinify/Source.php';
require_once __DIR__ . '/tinify/Tinify/Client.php';
require_once __DIR__ . '/tinify/Tinify.php';
\Tinify\setKey(TINIFY_API_KEY);

// ============================================
// ADATBÁZIS - KI VAN KAPCSOLVA (DEMO MÓD)
// ============================================
function db() {
    static $pdo = null;

    if ($pdo === null) {
        $host    = $_ENV['DB_HOST']    ?? 'localhost';
        $dbname  = $_ENV['DB_NAME']    ?? 'rh64410_batech_minden';
        $user    = $_ENV['DB_USER']    ?? 'rh64410_Ravaszdominik';
        $pass    = $_ENV['DB_PASS']    ?? 'Ravasz.dominik';
        $charset = "utf8mb4";

        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log("DB connection failed: " . $e->getMessage());
            return null;
        }
    }
    return $pdo;
}

// ============================================
// XSS VÉDELEM (htmlspecialchars rövidítve)
// ============================================
function e($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

// ============================================
// CSRF TOKEN VÉDELEM
// ============================================
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify($token) {
    return isset($_SESSION['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $token ?? '');
}

// ============================================
// FELHASZNÁLÓ ELLENŐRZÉS
// ============================================
function bejelentkezve() {
    return isset($_SESSION['user_id']);
}

function admin_e() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function demo_aktiv() {
    return isset($_SESSION['demo_mode']) && $_SESSION['demo_mode'] === true;
}

// ============================================
// ÁTIRÁNYÍTÁS
// ============================================
function atiranyit($url) {
    header("Location: " . $url);
    exit();
}

// ============================================
// ÜZENETKEZELÉS (TELJES)
// ============================================
function addMessage($type, $text) {
    if (!isset($_SESSION['messages'])) {
        $_SESSION['messages'] = [];
    }
    $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
}

function getMessages() {
    if (isset($_SESSION['messages'])) {
        $messages = $_SESSION['messages'];
        unset($_SESSION['messages']);
        return $messages;
    }
    return [];
}

// RÖVIDÍTETT VÁLTOZATOK (a fájlok ezeket használják!)
function uzenet($type, $text) {
    addMessage($type, $text);
}

function uzenetek() {
    return getMessages();
}

// ============================================
// SÖTÉT TÉMA
// ============================================
if (isset($_GET['dark'])) {
    $dark = $_GET['dark'] === '1' ? '1' : '0';
    setcookie('dark_mode', $dark, time() + (365 * 24 * 60 * 60), '/');
    $_COOKIE['dark_mode'] = $dark;
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: " . $redirect);
    exit();
}

function tema_beallitas() {
    return isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === '1';
}

function tema_osztaly() {
    return tema_beallitas() ? 'dark-theme' : '';
}

// ============================================
// EMLÉKEZZ RÁM - AUTO BEJELENTKEZÉS
// ============================================
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $conn_r = db();
    if ($conn_r) {
        $stmt_r = $conn_r->prepare(
            "SELECT u.id, u.name, u.email, u.user_type
             FROM user_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ? AND t.expires_at > NOW() AND u.status = 'active'
             LIMIT 1"
        );
        $stmt_r->execute([$_COOKIE['remember_token']]);
        $auto_user = $stmt_r->fetch(PDO::FETCH_ASSOC);
        if ($auto_user) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = $auto_user['id'];
            $_SESSION['user_name']  = $auto_user['name'];
            $_SESSION['user_email'] = $auto_user['email'];
            $_SESSION['user_type']  = $auto_user['user_type'];
            $_SESSION['is_admin']   = ($auto_user['user_type'] === 'admin');
        } else {
            // Token expired or invalid — clear the cookie
            setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        }
    }
}

// ============================================
// DÁTUM MAGYARUL
// ============================================
function datum_magyar($date) {
    $d = new DateTime($date);
    $honapok = ['', 'január', 'február', 'március', 'április', 'május', 'június',
                'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
    return $d->format('Y. ') . $honapok[(int)$d->format('m')] . ' ' . $d->format('d.');
}

// ============================================
// FÁJL FELTÖLTÉS - MAPPA LÉTREHOZÁS
// ============================================
function mappa_letrehozas($path) {
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
    return $path;
}

// ============================================
// EMAIL KÜLDÉS
// ============================================
function kuldEmail($to, $subject, $body) {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: BaTech <support@batech.hu>\r\n";
    return mail($to, $subject, $body, $headers);
}

// ============================================
// ÉRTESÍTÉSEK SZÁMA (MINDEN OLDALON)
// ============================================
function get_unread_notifications() {
    if (!bejelentkezve()) return 0;
    $conn = db();
    if (!$conn) return 0;
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND `read` = 0");
        $stmt->execute([$_SESSION['user_id']]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

// ============================================
// DEMO MÓD ELLENŐRZÉS
// ============================================
function demo_mod() {
    return demo_aktiv();
}

// ============================================
// FELHASZNÁLÓ TÍPUS SZÖVEGESEN
// ============================================
function felhasznalo_tipus($user_type) {
    $tipusok = [
        'user' => 'Felhasználó',
        'admin' => 'Adminisztrátor'
    ];
    return $tipusok[$user_type] ?? $user_type;
}

// ============================================
// AUDIT LOG
// ============================================
function audit_log($action, $target_type = null, $target_id = null, $details = null) {
    $conn = db();
    if (!$conn) return;
    $user_id   = $_SESSION['user_id'] ?? null;
    $user_name = $_SESSION['user_name'] ?? null;
    $ip        = $_SERVER['REMOTE_ADDR'] ?? null;
    $conn->prepare(
        "INSERT INTO audit_log (user_id, user_name, action, target_type, target_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([$user_id, $user_name, $action, $target_type, $target_id, $details, $ip]);
}

// ============================================
// STÁTUSZ KONSTANSOK
// ============================================
define('STATUS_PENDING',   'pending');
define('STATUS_CONFIRMED', 'confirmed');
define('STATUS_COMPLETED', 'completed');
define('STATUS_CANCELLED', 'cancelled');

const STATUS_LABELS = [
    'pending'   => 'Függőben',
    'confirmed' => 'Elfogadva',
    'completed' => 'Teljesítve',
    'cancelled' => 'Lemondva',
];

// ============================================
// STÁTUSZ SZÖVEGESEN
// ============================================
function statusz_szoveg($status) {
    $statusok = [
        'pending'   => 'Függőben',
        'confirmed' => 'Elfogadva',
        'completed' => 'Teljesítve',
        'cancelled' => 'Lemondva',
        'active' => 'Aktív',
        'inactive' => 'Inaktív'
    ];
    return $statusok[$status] ?? $status;
}
?>