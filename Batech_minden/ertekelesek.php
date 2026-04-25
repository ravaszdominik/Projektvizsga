<?php
session_start();
require_once 'config.php';

$page_title = "Értékelések | BaTech.hu";

$logged_in = bejelentkezve();
$user_name = $logged_in ? $_SESSION['user_name'] : '';
$user_id = $logged_in ? $_SESSION['user_id'] : null;
$is_admin = admin_e();
$csrf_token = csrf_token();

// ============================================
// ÉRTÉKELÉSEK BETÖLTÉSE ADATBÁZISBÓL - CSAK JÓVÁHAGYOTT
// ============================================
$reviews = [];
$conn = db();

if ($conn) {
    $sql = "SELECT r.*, u.name as user_name 
            FROM reviews r 
            LEFT JOIN users u ON r.user_id = u.id 
            WHERE r.approved = 1 
            ORDER BY r.created_at DESC";
    $result = $conn->query($sql);
    
    foreach ($result->fetchAll() as $row) {
        $reviews[] = [
            'id' => $row['id'],
            'name' => e($row['user_name'] ?? $row['guest_name'] ?? 'Névtelen'),
            'rating' => (int)$row['rating'],
            'comment' => e($row['comment']),
            'date' => $row['created_at']
        ];
    }
}

// Átlagos értékelés számítása
$total_reviews = count($reviews);
$average_rating = 0;

if ($total_reviews > 0) {
    $sum = 0;
    foreach ($reviews as $r) {
        $sum += $r['rating'];
    }
    $average_rating = round($sum / $total_reviews, 1);
}

// ============================================
// ÉRTÉKELÉS BEKÜLDÉSE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        uzenet('error', 'Érvénytelen token!');
        atiranyit('ertekelesek.php');
    }
    
    $name = trim($_POST['reviewer_name'] ?? '');
    $rating = (int)($_POST['review_rating'] ?? 0);
    $comment = trim($_POST['review_text'] ?? '');
    
    $errors = [];
    if (empty($name)) $errors[] = 'A név kötelező!';
    if ($rating < 1 || $rating > 5) $errors[] = 'Az értékelés 1-5 között legyen!';
    if (empty($comment)) $errors[] = 'A szöveg kötelező!';
    
    if (empty($errors)) {
        $conn = db();
        if ($conn) {
            $guest_name = $logged_in ? null : $name;
            $user_id_value = $logged_in ? $user_id : null;
            
            $stmt = $conn->prepare("INSERT INTO reviews (user_id, guest_name, rating, comment, approved, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            $stmt->execute([$user_id_value, $guest_name, $rating, $comment]);
            uzenet('success', 'Köszönjük értékelését! Az adminisztrátor jóváhagyása után megjelenik.');
        } else {
            // DEMO - ki van kapcsolva
            // uzenet('success', 'Köszönjük értékelését! (DEMO)');
            uzenet('error', 'Az adatbázis jelenleg nem elérhető.');
        }
    } else {
        foreach ($errors as $e) {
            uzenet('error', $e);
        }
    }
    
    atiranyit('ertekelesek.php');
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
    <?php $active_page = 'ertekelesek'; include 'includes/navbar.php'; ?>

    <!-- ===== FŐ TARTALOM ===== -->
    <main class="main-content">
        <div class="container">
            <h1 class="page-title">Ügyfeleink véleménye</h1>
            <p class="page-subtitle">Olvassa el, mit mondanak ügyfeleink szolgáltatásainkról</p>
            
            <?php foreach ($messages as $msg): ?>
            <div class="message <?= $msg['type'] ?>"><?= e($msg['text']) ?></div>
            <?php endforeach; ?>
            
            <?php if ($is_admin): ?>
            <div class="admin-notice">
                <p><i class="fas fa-edit"></i> Értékelések kezelése: <a href="admin.php#reviews">Admin felület →</a></p>
            </div>
            <?php endif; ?>
            
            <?php if ($total_reviews > 0): ?>
            <div class="average-rating">
                <h2>Átlagos értékelés</h2>
                <div class="rating-stars">
                    <?php
                    $full = floor($average_rating);
                    $half = ($average_rating - $full) >= 0.5;
                    for ($i = 1; $i <= 5; $i++):
                        if ($i <= $full) echo '<i class="fas fa-star"></i>';
                        elseif ($i == $full + 1 && $half) echo '<i class="fas fa-star-half-alt"></i>';
                        else echo '<i class="far fa-star"></i>';
                    endfor;
                    ?>
                </div>
                <p class="rating-number"><?= $average_rating ?> / 5 - <?= $total_reviews ?> értékelés</p>
            </div>
            <?php endif; ?>
            
            <div class="reviews-container">
                <?php if (empty($reviews)): ?>
                <div class="no-reviews">
                    <i class="fas fa-star" style="font-size: 4rem; color: var(--dark-gray);"></i>
                    <h2>Még nincsenek értékelések</h2>
                    <p>Legyen Ön az első, aki megosztja velünk tapasztalatait!</p>
                    <?php if ($is_admin): ?>
                    <p style="margin-top: 1rem;"><a href="admin.php#reviews" class="btn btn-primary">Értékelések kezelése</a></p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                    <?php foreach ($reviews as $r): ?>
                    <div class="review-card">
                        <div class="review-header">
                            <div>
                                <h3><?= e($r['name']) ?></h3>
                                <div class="review-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="<?= $i <= $r['rating'] ? 'fas' : 'far' ?> fa-star"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <span class="review-date"><?= date('Y. m. d.', strtotime($r['date'])) ?></span>
                        </div>
                        <p class="review-text">"<?= e($r['comment']) ?>"</p>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="add-review-section">
                <h2>Írja meg véleményét</h2>
                
                <?php if (!$logged_in): ?>
                <div class="message info">
                    <p>Értékelés írásához <a href="login.php">jelentkezzen be</a> vagy <a href="register.php">regisztráljon</a>!</p>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" class="review-form" <?= !$logged_in ? 'style="opacity:0.6;pointer-events:none"' : '' ?>>
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    
                    <div class="form-group">
                        <label for="reviewerName">Név</label>
                        <input type="text" id="reviewerName" name="reviewer_name" required 
                               value="<?= $logged_in ? e($user_name) : '' ?>" 
                               <?= $logged_in ? 'readonly' : '' ?>
                               placeholder="Az Ön neve">
                    </div>
                    
                    <div class="form-group">
                        <label for="reviewRating">Értékelés</label>
                        <select id="reviewRating" name="review_rating" required <?= !$logged_in ? 'disabled' : '' ?>>
                            <option value="" disabled selected>Válasszon értékelést</option>
                            <option value="5">★★★★★ - Kitűnő</option>
                            <option value="4">★★★★☆ - Nagyon jó</option>
                            <option value="3">★★★☆☆ - Jó</option>
                            <option value="2">★★☆☆☆ - Átlagos</option>
                            <option value="1">★☆☆☆☆ - Gyenge</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="reviewText">Vélemény</label>
                        <textarea id="reviewText" name="review_text" rows="4" required 
                                  <?= !$logged_in ? 'disabled' : '' ?>
                                  placeholder="Írja le tapasztalatait..."></textarea>
                    </div>
                    
                    <button type="submit" name="submit_review" class="btn btn-primary" <?= !$logged_in ? 'disabled' : '' ?>>
                        <i class="fas fa-paper-plane"></i> Értékelés elküldése
                    </button>
                    
                    <p class="form-note">
                        <i class="fas fa-info-circle"></i> 
                        Az értékelés csak adminisztrátori jóváhagyás után jelenik meg.
                    </p>
                </form>
            </div>
        </div>
    </main>

    <!-- ===== LÁBLÉC ===== -->
    <?php include 'includes/footer.php'; ?>

    <script src="main.js"></script>
</body>
</html>