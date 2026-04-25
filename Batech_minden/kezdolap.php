<?php

$services = [];
$conn = db();

if ($conn) {
    $stmt = $conn->query("SELECT * FROM services WHERE active = 1");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $services[] = [
            'icon' => $row['icon'] ?? 'fa-tools',
            'title' => e($row['name']),
            'description' => e($row['description'])
        ];
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_submit'])) {

    if (!$logged_in) {
        uzenet('error', 'Bejelentkezés szükséges!');
        atiranyit('index.php');
    }

    $service = $_POST['service_type'] ?? '';
    $date = $_POST['booking_date'] ?? '';
    $time = $_POST['booking_time'] ?? '';

    if ($service && $date && $time) {
        $conn = db();
        if ($conn) {
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO bookings (user_id, service_type, booking_date, booking_time, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$user_id, $service, $date, $time]);
            uzenet('success', 'Sikeres foglalás! Hamarosan visszajelzünk.');
        } else {
            uzenet('error', 'Az adatbázis jelenleg nem elérhető.');
        }
    } else {
        uzenet('error', 'Töltsön ki minden mezőt!');
    }

    atiranyit('index.php');
}

$messages = uzenetek();

?>
<!-- ===== HERO SZEKCIÓ ===== -->
    <header class="hero">
        <div class="container">
            <div class="hero-content">
                <h1>Víz- és gázszerelési szolgáltatások</h1>
                <p>Gyors, megbízható és professzionális víz- és gázszerelői munkák. 24 órás sürgősségi szolgáltatás</p>
                <div class="hero-buttons">
                    <a href="arak.php" class="btn btn-primary">Szolgáltatások</a>
                    <a href="tel:+3612345678" class="btn btn-secondary">
                        <i class="fas fa-phone"></i> Hívás most
                    </a>
                </div>
            </div>
            <div class="hero-image">
                <img src="assets/img/plumber.svg" alt="Vízműves" class="hero-img">
            </div>
        </div>
    </header>

    <!-- ===== SZOLGÁLTATÁSOK ===== -->
    <section class="services">
        <div class="container">
            <h2 class="section-title">Szolgáltatásaink</h2>
            <div class="services-grid">
                <?php foreach ($services as $service): ?>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas <?= e($service['icon']) ?>"></i>
                    </div>
                    <h3><?= e($service['title']) ?></h3>
                    <p><?= e($service['description']) ?></p>
                </div>
                <?php endforeach; ?>
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
                <button type="submit" name="booking_submit" class="btn btn-primary" <?= !$logged_in ? 'disabled' : '' ?>>
                    <i class="fas fa-calendar-check"></i> Időpont foglalása
                </button>
            </form>
        </div>
    </section>
