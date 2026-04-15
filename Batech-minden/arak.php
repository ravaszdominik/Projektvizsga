<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'config.php';

$conn = db();
$page_title = "Szolgáltatások és Árak | BaTech.hu";

$logged_in = bejelentkezve();
$user_name = $logged_in ? $_SESSION['user_name'] : '';
$is_admin = admin_e();

// ============================================
// SZOLGÁLTATÁSOK BETÖLTÉSE
// ============================================
function getServicesByCategory($conn, $category) {
    $services = [];
    $stmt = $conn->prepare("SELECT * FROM services WHERE category = ? AND active = 1 ORDER BY display_order");
    $stmt->execute([$category]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as $row) {
        $services[] = [
            'name' => e($row['name']),
            'description' => e($row['description']),
            'price' => e($row['price_range'] ?? 'Ár egyeztetés alapján'),
            'duration' => $row['estimated_duration'] ?? null,
            'priority' => $row['priority'] ?? 'normal'
        ];
    }
    return $services;
}

// DEMO adatok (ha nincs adatbázis) - ki van kapcsolva
/*
function getDemoServices() {
    return [
        'vízszerelés' => [
            ['name' => 'Csapcsere', 'description' => 'Mosogató vagy fürdőszobai csap cseréje', 'price' => '8.000 - 15.000', 'priority' => 'normal'],
            ['name' => 'Vízvezeték javítás', 'description' => 'Szivárgó cső javítása vagy cseréje', 'price' => '12.000 - 25.000', 'priority' => 'normal'],
            ['name' => 'Vízmelegítő telepítés', 'description' => 'Új vízmelegítő telepítése', 'price' => '25.000 - 40.000', 'priority' => 'normal'],
            ['name' => 'WC javítás', 'description' => 'Öblítő rendszer javítása', 'price' => '10.000 - 18.000', 'priority' => 'normal']
        ],
        'gázerősítés' => [
            ['name' => 'Gázbojler telepítés', 'description' => 'Új gázbojler telepítése', 'price' => '35.000 - 50.000', 'priority' => 'normal'],
            ['name' => 'Gázvezeték ellenőrzés', 'description' => 'Biztonsági ellenőrzés', 'price' => '15.000', 'priority' => 'high'],
            ['name' => 'Gázkonvektor javítás', 'description' => 'Gázkonvektor javítása', 'price' => '20.000 - 30.000', 'priority' => 'normal']
        ],
        'sürgősségi' => [
            ['name' => 'Vészhelyzeti kijövetel', 'description' => '0-24 óra', 'price' => '20.000', 'priority' => 'urgent'],
            ['name' => 'Azonnali javítás', 'description' => '2 órán belül', 'price' => '30.000 + anyag', 'priority' => 'urgent']
        ]
    ];
}
*/

// Kategóriák
$cat_names = [
    'vízszerelés' => 'Vízszerelési munkák',
    'gázerősítés' => 'Gázerősítési munkák',
    'sürgősségi' => 'Sürgősségi szolgáltatások'
];

$priority_names = [
    'normal' => 'Normál',
    'high' => 'Magas',
    'urgent' => 'Sürgős'
];

// Adatok betöltése
$services_data = [];

if ($conn) {
    foreach (array_keys($cat_names) as $cat) {
        $services_data[$cat] = getServicesByCategory($conn, $cat);
    }
}

// Ha nincs adatbázis vagy üres, demo adatok - ki van kapcsolva
/*
if (empty($services_data) || !$conn) {
    $services_data = getDemoServices();
}

// Üres kategóriák pótlása
foreach (array_keys($cat_names) as $cat) {
    if (empty($services_data[$cat])) {
        $demo = getDemoServices();
        $services_data[$cat] = $demo[$cat] ?? [];
    }
}
*/

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
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="logo">
                <i class="fas fa-tools"></i> BaTech<span>.</span>
            </a>
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <ul class="nav-links" id="navLinks">
                <li><a href="index.php">Főoldal</a></li>
                <li><a href="arak.php" class="active">Szolgáltatások & Árak</a></li>
                <li><a href="referenciak.php">Referenciák</a></li>
                <li><a href="ertekelesek.php">Értékelések</a></li>
                
                <?php if ($logged_in): ?>
                    <?php if ($is_admin): ?>
                        <li><a href="admin.php" class="btn-admin">Admin</a></li>
                    <?php endif; ?>
                    <li><a href="profil.php" class="btn-login">
                        <i class="fas fa-user"></i> <?= e($user_name) ?>
                    </a></li>
                    <?php $bell_count = get_unread_notifications(); ?><li><a href="profil.php#notifications" class="btn-login" style="position:relative;" title="Értesítések"><i class="fas fa-bell"></i><?php if ($bell_count > 0): ?><span style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:white;border-radius:50%;width:18px;height:18px;font-size:0.7rem;display:flex;align-items:center;justify-content:center;font-weight:bold;"><?= $bell_count ?></span><?php endif; ?></a></li>
                    <li><a href="logout.php" class="btn-register">Kijelentkezés</a></li>
                <?php else: ?>
                    <li><a href="login.php" class="btn-login">Bejelentkezés</a></li>
                    <li><a href="register.php" class="btn-register">Regisztráció</a></li>
                <?php endif; ?>
                
                <li>
                    <a href="?dark=<?= tema_beallitas() ? '0' : '1' ?>" class="theme-toggle" id="themeToggle">
                        <i class="fas fa-<?= tema_beallitas() ? 'sun' : 'moon' ?>"></i>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- ===== FŐ TARTALOM ===== -->
    <main class="main-content">
        <div class="container">
            <h1 class="page-title">Szolgáltatások és Árak</h1>
            
            <?php foreach ($messages as $msg): ?>
                <div class="message <?= $msg['type'] ?>"><?= e($msg['text']) ?></div>
            <?php endforeach; ?>
            
            <?php if ($is_admin): ?>
            <div class="admin-notice">
                <p><i class="fas fa-edit"></i> Szolgáltatások kezelése: <a href="admin.php#services">Admin felület →</a></p>
            </div>
            <?php endif; ?>
            
            <?php foreach ($cat_names as $cat => $cat_name): ?>
                <?php if (!empty($services_data[$cat])): ?>
                <div class="pricing-section" id="<?= $cat ?>">
                    <h2><?= $cat_name ?></h2>
                    <div class="table-container">
                        <table class="pricing-table">
                            <thead>
                                <tr>
                                    <th>Szolgáltatás</th>
                                    <th>Leírás</th>
                                    <th>Ár (Ft)</th>
                                    <?php if ($is_admin): ?><th>Prioritás</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($services_data[$cat] as $s): ?>
                                <tr>
                                    <td><strong><?= e($s['name']) ?></strong></td>
                                    <td><?= e($s['description']) ?></td>
                                    <td class="price-cell"><?= e($s['price']) ?></td>
                                    <?php if ($is_admin): ?>
                                    <td>
                                        <span class="priority-badge priority-<?= e($s['priority'] ?? 'normal') ?>">
                                            <?= $priority_names[$s['priority'] ?? 'normal'] ?>
                                        </span>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <div class="note-box">
                <p><strong>Megjegyzés:</strong> Az árak tájékoztató jellegűek, 1 év garancia minden munkára.</p>
                
                <p class="booking-cta">
                    <?php if ($logged_in): ?>
                    <a href="index.php#booking" class="btn btn-primary">
                        <i class="fas fa-calendar-plus"></i> Időpontfoglalás
                    </a>
                    <?php else: ?>
                    <a href="login.php" class="btn btn-primary">
                        <i class="fas fa-calendar-check"></i> Bejelentkezés foglaláshoz
                    </a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </main>

    <!-- ===== LÁBLÉC ===== -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-info">
                    <h3>Elérhetőségeink</h3>
                    <p><i class="fas fa-map-marker-alt"></i> 1234 Budapest, Víz út 5.</p>
                    <p><i class="fas fa-phone"></i> +36 1 234 5678</p>
                    <p><i class="fas fa-envelope"></i> info@vizmuvek.hu</p>
                </div>
                <div class="footer-links">
                    <h3>Gyors linkek</h3>
                    <ul>
                        <li><a href="index.php">Főoldal</a></li>
                        <li><a href="arak.php">Árak</a></li>
                        <li><a href="referenciak.php">Referenciák</a></li>
                        <li><a href="ertekelesek.php">Értékelések</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> BaTech Kft. - Minden jog fenntartva.</p>
            </div>
        </div>
    </footer>

    <script src="main.js"></script>
</body>
</html>