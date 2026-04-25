<?php
session_start();
require_once 'config.php';

$page_title = "Főoldal | BaTech";
$logged_in = bejelentkezve();
$user_name = $logged_in ? $_SESSION['user_name'] : '';
$is_admin = admin_e();

// ============================================
// IDŐPONTFOGLALÁS KEZELÉSE (PDO)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_submit'])) {
    
    if (!$logged_in) {
        uzenet('error', 'Bejelentkezés szükséges!');
        atiranyit('index.php');
    }
    
    $service = $_POST['service_type'] ?? '';
    $date = $_POST['booking_date'] ?? '';
    $time = $_POST['booking_time'] ?? '';
    $note = trim($_POST['booking_note'] ?? '');
    
    if ($service && $date && $time) {
        $conn = db();
        if ($conn) {
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO bookings (user_id, service_type, booking_date, booking_time, note, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$user_id, $service, $date, $time, $note ?: null]);
            uzenet('success', 'Sikeres foglalás! Hamarosan visszajelzünk.');
        } else {
            uzenet('error', 'Az adatbázis jelenleg nem elérhető.');
        }
    } else {
        uzenet('error', 'Töltsön ki minden mezőt!');
    }
    
    atiranyit('index.php');
}

// ============================================
// SZOLGÁLTATÁSOK BETÖLTÉSE KATEGÓRIÁNKÉNT
// ============================================
$conn = db();

$cat_names = [
    'vízszerelés' => ['label' => 'Vízszerelés', 'icon' => 'fa-faucet'],
    'gázerősítés' => ['label' => 'Gázerősítés', 'icon' => 'fa-fire'],
    'sürgősségi'  => ['label' => 'Sürgősségi', 'icon' => 'fa-exclamation-triangle'],
];

$services_by_cat = [];
if ($conn) {
    try {
        $stmt = $conn->prepare("SELECT * FROM services WHERE active = 1 ORDER BY category, display_order");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $services_by_cat[$row['category']][] = $row;
        }
    } catch (PDOException $e) {
        $services_by_cat = [];
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

    <!-- ===== NAVIGÁCIÓ ===== -->
    <?php $active_page = 'index'; include 'includes/navbar.php'; ?>

    <!-- ===== HERO SZEKCIÓ ===== -->
    <header class="hero">
        <div class="container">
            <div class="hero-content">
                <div class="message info" style="margin-bottom:1.5rem;"><i class="fas fa-info-circle"></i> Rendszer frissítés miatt 2026. 04. 24.-én az összes adatot töröltük.</div>
                <h1>Víz- és gázszerelési szolgáltatások</h1>
                <p>Gyors, megbízható és professzionális víz- és gázszerelői munkák. 24 órás sürgősségi szolgáltatás.</p>
                <div class="hero-buttons">
                    <a href="arak.php" class="btn btn-primary">Szolgáltatások</a>
                    <a href="tel:+3612345678" class="btn btn-secondary">
                        <i class="fas fa-phone"></i> Hívás most
                    </a>
                </div>
            </div>
            <div class="hero-image">
                <img src="assets/Plumber.png" alt="Vízműves" class="hero-img">
            </div>
        </div>
    </header>

    <!-- ===== SZOLGÁLTATÁSOK ===== -->
    <section class="services">
        <div class="container">
            <h2 class="section-title">Szolgáltatásaink</h2>
            <div class="services-grid">
                <?php foreach ($cat_names as $cat => $meta): ?>
                <?php if (!empty($services_by_cat[$cat])): ?>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas <?= $meta['icon'] ?>"></i>
                    </div>
                    <h3><?= $meta['label'] ?></h3>
                    <ul class="service-list">
                        <?php foreach ($services_by_cat[$cat] as $s): ?>
                        <li>
                            <span class="service-list-name"><?= e($s['name']) ?></span>
                            <?php if (!empty($s['price_range'])): ?>
                            <span class="service-list-price"><?= e($s['price_range']) ?> Ft</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div style="text-align:center;margin-top:2rem">
                <a href="arak.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> Összes szolgáltatás és ár
                </a>
            </div>
        </div>
    </section>

    <!-- ===== IDŐPONTFOGLALÁS ===== -->
    <section class="booking">
        <div class="container">
            <h2 class="section-title">Foglaljon időpontot</h2>
            
            <?php foreach ($messages as $msg): ?>
                <div class="message <?= $msg['type'] ?>"><?= e($msg['text']) ?></div>
            <?php endforeach; ?>
            
            <?php if (!$logged_in): ?>
                <div class="message info">
                    <p>Időpontfoglaláshoz <a href="login.php">jelentkezzen be</a> vagy <a href="register.php">regisztráljon</a>!</p>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="booking-form" <?= !$logged_in ? 'style="opacity:0.6;pointer-events:none"' : '' ?>>
                <div class="form-group">
                    <label for="serviceType">Szolgáltatás típusa</label>
                    <select id="serviceType" name="service_type" required <?= !$logged_in ? 'disabled' : '' ?>>
                        <option value="" disabled selected>Válasszon szolgáltatást</option>
                        <option value="Vízszerelés">Vízszerelés</option>
                        <option value="Gázerősítés">Gázerősítés</option>
                        <option value="Sürgősségi">Sürgősségi</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="bookingDate">Dátum</label>
                    <input type="date" id="bookingDate" name="booking_date" required <?= !$logged_in ? 'disabled' : '' ?>>
                </div>
                <div class="form-group">
                    <label for="bookingTime">Időpont</label>
                    <select id="bookingTime" name="booking_time" required <?= !$logged_in ? 'disabled' : '' ?>>
                        <option value="" disabled selected>Válasszon időpontot</option>
                        <option value="08:00">08:00 - 10:00</option>
                        <option value="10:00">10:00 - 12:00</option>
                        <option value="13:00">13:00 - 15:00</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="bookingNote">Megjegyzés (opcionális)</label>
                    <textarea id="bookingNote" name="booking_note" rows="3" placeholder="Pl. pontos cím, probléma leírása..." <?= !$logged_in ? 'disabled' : '' ?>></textarea>
                </div>
                <button type="submit" name="booking_submit" class="btn btn-primary" <?= !$logged_in ? 'disabled' : '' ?>>
                    <i class="fas fa-calendar-check"></i> Időpont foglalása
                </button>
            </form>
        </div>
    </section>

    <!-- ===== LÁBLÉC ===== -->
    <?php include 'includes/footer.php'; ?>

    <script src="main.js"></script>
    <script>
    // Dátum beállítás
    const dateInput = document.getElementById('bookingDate');
    if (dateInput) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const yyyy = tomorrow.getFullYear();
        const mm = String(tomorrow.getMonth() + 1).padStart(2, '0');
        const dd = String(tomorrow.getDate()).padStart(2, '0');
        dateInput.min = `${yyyy}-${mm}-${dd}`;
        dateInput.value = dateInput.min;
    }
    
    </script>
</body>
</html>