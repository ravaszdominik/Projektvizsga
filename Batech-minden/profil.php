<?php
session_start();
require_once 'config.php';

// Csak bejelentkezett felhasználóknak
if (!bejelentkezve()) {
    uzenet('error', 'Kérjük, jelentkezzen be!');
    atiranyit('login.php');
}

$page_title = "Profilom | Vízművek";
$user_id = $_SESSION['user_id'];
$is_admin = admin_e();
$is_demo = demo_aktiv();
$csrf_token = csrf_token();

// ============================================
// KÉP TÖMÖRÍTŐ FÜGGVÉNYEK
// ============================================
function compressImage($source, $destination, $quality = 80) {
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
        imagejpeg($image, $destination, $quality);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagepng($image, $destination, (int)floor(($quality / 100) * 9));
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
        imagegif($image, $destination);
    } else { return false; }
    imagedestroy($image);
    return true;
}

function resizeImage($source, $destination, $maxWidth = 800, $maxHeight = 800, $quality = 80) {
    $info   = getimagesize($source);
    $width  = $info[0];
    $height = $info[1];
    if ($width <= $maxWidth && $height <= $maxHeight) return compressImage($source, $destination, $quality);
    $ratio     = min($maxWidth / $width, $maxHeight / $height);
    $newWidth  = (int)round($width  * $ratio);
    $newHeight = (int)round($height * $ratio);
    $newImage  = imagecreatetruecolor($newWidth, $newHeight);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } else { return false; }
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    if ($info['mime'] == 'image/jpeg') {
        imagejpeg($newImage, $destination, $quality);
    } elseif ($info['mime'] == 'image/png') {
        imagepng($newImage, $destination, (int)floor(($quality / 100) * 9));
    } elseif ($info['mime'] == 'image/gif') {
        imagegif($newImage, $destination);
    }
    imagedestroy($image);
    imagedestroy($newImage);
    return true;
}

// ============================================
// FELHASZNÁLÓ ADATOK BETÖLTÉSE
// ============================================
$user = null;
$conn = db();

if ($conn) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// FELHASZNÁLÓ FOGLALÁSAI
// ============================================
$user_bookings = [];
if ($conn) {
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE user_id = ? ORDER BY booking_date DESC, booking_time DESC");
    $stmt->execute([$user_id]);
    $user_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FELHASZNÁLÓ ÉRTÉKELÉSEI
// ============================================
$user_reviews = [];
if ($conn) {
    $stmt = $conn->prepare("SELECT * FROM reviews WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $user_reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FELHASZNÁLÓ REFERENCIÁI
// ============================================
$user_references = [];
if ($conn) {
    $stmt = $conn->prepare("SELECT * FROM `references` WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $user_references = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// ÉRTESÍTÉSEK
// ============================================
$notifications = [];
$unread_count  = 0;
if ($conn) {
    try {
        $stmt = $conn->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$user_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread_count  = count(array_filter($notifications, fn($n) => !$n['read']));
    } catch (PDOException $e) {}
}

// Értesítések olvasottnak jelölése
if (isset($_GET['mark_read']) && $conn) {
    try {
        $conn->prepare("UPDATE user_notifications SET `read` = 1 WHERE user_id = ?")->execute([$user_id]);
    } catch (PDOException $e) {}
    atiranyit('profil.php');
}
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        uzenet('error', 'Érvénytelen token!');
        atiranyit('profil.php');
    }
    
    $action = $_POST['action'] ?? '';
    
    // ===== PROFIL ADATOK MÓDOSÍTÁSA =====
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        $errors = [];
        if (empty($name)) $errors[] = 'A név kötelező!';
        
        if (empty($errors)) {
            $conn = db();
            if ($conn) {
                $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?");
                $stmt->execute([$name, $phone, $address, $user_id]);
                
                $_SESSION['user_name'] = $name;
                uzenet('success', 'Profil sikeresen frissítve!');
            } else {
                uzenet('error', 'Az adatbázis jelenleg nem elérhető.');
            }
        } else {
            foreach ($errors as $e) uzenet('error', $e);
        }
        atiranyit('profil.php');
    }
    
    // ===== PROFILKÉP FELTÖLTÉSE (TÖMÖRÍTÉSSEL) =====
    if ($action === 'upload_avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $target_dir = "uploads/avatars/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $file_ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_ext, $allowed)) {
                $temp_file = $_FILES['avatar']['tmp_name'];
                $new_filename = $user_id . '_' . time() . '.' . $file_ext;
                $target_file = $target_dir . $new_filename;
                
                $compress_result = resizeImage($temp_file, $target_file, 400, 400, 85);
                
                if ($compress_result) {
                    $conn = db();
                    if ($conn) {
                        $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                        $stmt->execute([$new_filename, $user_id]);
                        uzenet('success', 'Profilkép feltöltve és optimalizálva!');
                    } else {
                        uzenet('error', 'Az adatbázis jelenleg nem elérhető.');
                    }
                } else {
                    uzenet('error', 'Hiba a kép tömörítése során!');
                }
            } else {
                uzenet('error', 'Csak JPG, PNG, GIF fájlok engedélyezettek!');
            }
        } else {
            uzenet('error', 'Válasszon ki egy képet!');
        }
        atiranyit('profil.php');
    }
    
    // ===== FOGLALÁS LEMONDÁSA =====
    if ($action === 'cancel_booking') {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $conn = db();
        if ($conn && $booking_id > 0) {
            // Only allow cancelling own pending/confirmed bookings
            $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status IN ('pending','confirmed')");
            $stmt->execute([$booking_id, $user_id]);
            if ($stmt->rowCount() > 0) {
                uzenet('success', 'Foglalás sikeresen lemondva!');
            } else {
                uzenet('error', 'A foglalás nem mondható le!');
            }
        }
        atiranyit('profil.php');
    }

    // ===== JELSZÓ MÓDOSÍTÁSA =====
    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) {
            uzenet('error', 'Minden mező kitöltése kötelező!');
        } elseif ($new !== $confirm) {
            uzenet('error', 'Az új jelszavak nem egyeznek!');
        } elseif (strlen($new) < 6) {
            uzenet('error', 'Az új jelszó legalább 6 karakter legyen!');
        } elseif (!preg_match('/[A-Z]/', $new)) {
            uzenet('error', 'Az új jelszó tartalmazzon legalább egy nagybetűt!');
        } else {
            $conn = db();
            if ($conn) {
                $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && password_verify($current, $row['password_hash'])) {
                    $new_hash = password_hash($new, PASSWORD_DEFAULT);
                    $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$new_hash, $user_id]);
                    uzenet('success', 'Jelszó sikeresen módosítva!');
                } else {
                    uzenet('error', 'A jelenlegi jelszó helytelen!');
                }
            }
        }
        atiranyit('profil.php');
    }

    // ===== ÚJ ÉRTÉKELÉS =====
    if ($action === 'add_review') {
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        
        if ($rating < 1 || $rating > 5) {
            uzenet('error', 'Az értékelés 1-5 között legyen!');
        } elseif (empty($comment)) {
            uzenet('error', 'Az értékelés szövege kötelező!');
        } else {
            $conn = db();
            if ($conn) {
                $stmt = $conn->prepare("INSERT INTO reviews (user_id, rating, comment, approved, created_at) VALUES (?, ?, ?, 0, NOW())");
                $stmt->execute([$user_id, $rating, $comment]);
                uzenet('success', 'Köszönjük értékelését! ⏳ Értékelése jóváhagyásra vár — amint az admin jóváhagyja, megjelenik az oldalon.');
            } else {
                uzenet('error', 'Az adatbázis jelenleg nem elérhető.');
            }
        }
        atiranyit('profil.php');
    }
    
    // ===== ÚJ REFERENCIA (TÖBB KÉP TÖMÖRÍTÉSSEL) =====
    if ($action === 'add_reference') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? '');
        
        if (empty($title) || empty($description)) {
            uzenet('error', 'A cím és a leírás kötelező!');
        } else {
            $image_urls = [];
            $target_dir = "uploads/references/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            // TÖBB KÉP FELDOLGOZÁSA
            if (isset($_FILES['reference_images']) && !empty($_FILES['reference_images']['name'][0])) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $files = $_FILES['reference_images'];
                
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $file_ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                        
                        if (in_array($file_ext, $allowed)) {
                            $temp_file = $files['tmp_name'][$i];
                            $new_filename = 'ref_' . $user_id . '_' . time() . '_' . $i . '.' . $file_ext;
                            $target_file = $target_dir . $new_filename;
                            
                            // Tömörítés
                            if (resizeImage($temp_file, $target_file, 1200, 1200, 85)) {
                                $image_urls[] = $target_file;
                            }
                        }
                    }
                }
            }
            
            // Ha nincs kép, alapértelmezett
            if (empty($image_urls)) {
                $image_urls[] = 'assets/img/default-reference.jpg';
            }

            $conn = db();
            if ($conn) {
                // Referencia mentése
                $stmt = $conn->prepare("INSERT INTO `references` (user_id, title, description, category, image_url, approved, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
                $stmt->execute([$user_id, $title, $description, $category, $image_urls[0]]);
                $ref_id = $conn->lastInsertId();

                // Képek mentése a reference_images táblába
                $img_stmt = $conn->prepare("INSERT INTO `reference_images` (reference_id, image_url, sort_order) VALUES (?, ?, ?)");
                foreach ($image_urls as $order => $url) {
                    $img_stmt->execute([$ref_id, $url, $order]);
                }

                uzenet('success', 'Referencia sikeresen feltöltve! ⏳ Jóváhagyásra vár — amint az admin jóváhagyja, megjelenik az oldalon.');
            } else {
                uzenet('error', 'Az adatbázis jelenleg nem elérhető.');
            }
        }
        atiranyit('profil.php');
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
                <li><a href="arak.php">Szolgáltatások & Árak</a></li>
                <li><a href="referenciak.php">Referenciák</a></li>
                <li><a href="ertekelesek.php">Értékelések</a></li>
                
                <?php if ($is_admin): ?>
                    <li><a href="admin.php" class="btn-admin">Admin</a></li>
                <?php endif; ?>
                
                <li><a href="profil.php" class="btn-login active">
                    <i class="fas fa-user"></i> <?= e($_SESSION['user_name'] ?? 'Profil') ?>
                </a></li>
                <li>
                    <a href="profil.php?mark_read=1" class="btn-login" style="position:relative;" title="Értesítések">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                        <span style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:white;border-radius:50%;width:18px;height:18px;font-size:0.7rem;display:flex;align-items:center;justify-content:center;font-weight:bold;"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="logout.php" class="btn-register">Kijelentkezés</a></li>
                
                <li>
                    <a href="?dark=<?= tema_beallitas() ? '0' : '1' ?>" class="theme-toggle">
                        <i class="fas fa-<?= tema_beallitas() ? 'sun' : 'moon' ?>"></i>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- ===== FŐ TARTALOM ===== -->
    <main class="main-content">
        <div class="container">
            <h1 class="page-title">Profilom</h1>
            
            <?php foreach ($messages as $msg): ?>
                <div class="message <?= $msg['type'] ?>"><?= e($msg['text']) ?></div>
            <?php endforeach; ?>
            
            <!-- ===== PROFIL FEJLÉC ===== -->
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="uploads/avatars/<?= e($user['avatar']) ?>" alt="Profilkép" id="avatarPreview">
                    <?php else: ?>
                        <div class="avatar-placeholder" id="avatarPlaceholder">
                            <i class="fas fa-user-circle"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="profile-title">
                    <h2><?= e($user['name'] ?? '') ?></h2>
                    <p><?= e($user['email'] ?? '') ?></p>
                    <p><small>Regisztráció: <?= !empty($user['created_at']) ? date('Y. m. d.', strtotime($user['created_at'])) : '-' ?></small></p>
                </div>
            </div>
            
            <!-- ===== PROFIL TABS ===== -->
            <div class="profile-tabs">
                <button class="profile-tab-btn active" data-tab="info">Személyes adatok</button>
                <button class="profile-tab-btn" data-tab="bookings">Foglalásaim</button>
                <button class="profile-tab-btn" data-tab="reviews">Értékeléseim</button>
                <button class="profile-tab-btn" data-tab="references">Referenciáim</button>
                <button class="profile-tab-btn" data-tab="new-review">Új értékelés</button>
                <button class="profile-tab-btn" data-tab="new-reference">Új referencia</button>
                <button class="profile-tab-btn" data-tab="change-password">Jelszó módosítása</button>
                <button class="profile-tab-btn" data-tab="notifications" style="position:relative;">
                    Értesítések
                    <?php if ($unread_count > 0): ?>
                    <span style="background:#ef4444;color:white;border-radius:50%;width:18px;height:18px;font-size:0.7rem;display:inline-flex;align-items:center;justify-content:center;font-weight:bold;margin-left:4px;"><?= $unread_count ?></span>
                    <?php endif; ?>
                </button>
            </div>
            
            <!-- ===== PROFIL ADATOK ===== -->
            <div id="info" class="profile-tab-content active">
                <h3>Személyes adatok módosítása</h3>
                
                <form method="POST" class="profile-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-group">
                        <label>Teljes név</label>
                        <input type="text" name="name" value="<?= e($user['name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>E-mail cím</label>
                        <input type="email" value="<?= e($user['email'] ?? '') ?>" readonly disabled>
                        <small class="form-hint">E-mail cím nem módosítható</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Telefonszám</label>
                        <input type="text" name="phone" value="<?= e($user['phone'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Lakcím</label>
                        <input type="text" name="address" value="<?= e($user['address'] ?? '') ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Módosítások mentése</button>
                </form>
                
                <hr>
                
                <h3>Profilkép feltöltése</h3>
                <form method="POST" enctype="multipart/form-data" class="avatar-form" id="avatarForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="upload_avatar">
                    
                    <div class="form-group">
                        <label for="avatar">Válasszon képet (JPG, PNG, GIF)</label>
                        <input type="file" id="avatar" name="avatar" accept="image/*" required>
                        <small class="form-hint">A kép automatikusan tömörítésre kerül (max. 400x400px)</small>
                    </div>
                    
                    <div id="avatarPreviewContainer" style="margin: 1rem 0; display: none;">
                        <img id="avatarPreviewImg" style="max-width: 100px; border-radius: 50%;">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Feltöltés</button>
                </form>
            </div>
            
            <!-- ===== FOGLALÁSAIM ===== -->
            <div id="bookings" class="profile-tab-content">
                <h3>Foglalásaim</h3>
                
                <?php if (empty($user_bookings)): ?>
                    <p class="no-data">Még nincs egyetlen foglalása sem.</p>
                <?php else: ?>
                    <table class="profile-table">
                        <thead>
                            <tr>
                                <th>Szolgáltatás</th>
                                <th>Dátum</th>
                                <th>Időpont</th>
                                <th>Státusz</th>
                                <th>Művelet</th>
                              </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_bookings as $b): ?>
                              <tr>
                                  <td><?= e($b['service_type'] ?? '') ?></td>
                                  <td><?= e($b['booking_date'] ?? '') ?></td>
                                  <td><?= e($b['booking_time'] ?? '') ?></td>
                                  <td><span class="status-<?= $b['status'] ?? 'pending' ?>"><?= ['pending'=>'Függőben','confirmed'=>'Elfogadva','completed'=>'Teljesítve','cancelled'=>'Lemondva'][$b['status'] ?? 'pending'] ?? 'Függőben' ?></span></td>
                                  <td>
                                      <?php if (in_array($b['status'] ?? '', ['pending','confirmed'])): ?>
                                      <form method="POST" style="display:inline" onsubmit="return confirm('Biztosan lemondja ezt a foglalást?')">
                                          <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                          <input type="hidden" name="action" value="cancel_booking">
                                          <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                          <button type="submit" class="btn-action btn-delete" style="font-size:0.8rem;">
                                              <i class="fas fa-times"></i> Lemondás
                                          </button>
                                      </form>
                                      <?php else: ?>
                                      -
                                      <?php endif; ?>
                                  </td>
                              </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- ===== ÉRTÉKELÉSEIM ===== -->
            <div id="reviews" class="profile-tab-content">
                <h3>Értékeléseim</h3>
                
                <?php if (empty($user_reviews)): ?>
                    <p class="no-data">Még nem írtál egyetlen értékelést sem.</p>
                <?php else: ?>
                    <?php foreach ($user_reviews as $r): ?>
                    <div class="profile-review-card">
                        <div class="review-rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="<?= $i <= ($r['rating'] ?? 0) ? 'fas' : 'far' ?> fa-star"></i>
                            <?php endfor; ?>
                        </div>
                        <p><?= e($r['comment'] ?? '') ?></p>
                        <small><?= date('Y. m. d.', strtotime($r['created_at'] ?? '')) ?></small>
                        <small class="status-<?= ($r['approved'] ?? 0) ? 'confirmed' : 'pending' ?>">
                            <?= ($r['approved'] ?? 0) ? 'Jóváhagyva' : 'Jóváhagyásra vár' ?>
                        </small>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- ===== REFERENCIÁIM ===== -->
            <div id="references" class="profile-tab-content">
                <h3>Referenciáim</h3>
                
                <?php if (empty($user_references)): ?>
                    <p class="no-data">Még nem töltöttél fel egyetlen referenciát sem.</p>
                <?php else: ?>
                    <div class="references-grid">
                        <?php foreach ($user_references as $ref): ?>
                        <div class="reference-card">
                            <?php if (!empty($ref['images'])): 
                                $images = json_decode($ref['images'], true);
                                if (!empty($images) && is_array($images)): ?>
                                    <img src="<?= e($images[0]) ?>" alt="Referencia" class="reference-thumb">
                                <?php endif; 
                            elseif (!empty($ref['image_url'])): ?>
                                <img src="<?= e($ref['image_url']) ?>" alt="Referencia" class="reference-thumb">
                            <?php endif; ?>
                            <h4><?= e($ref['title'] ?? '') ?></h4>
                            <p><?= e(substr($ref['description'] ?? '', 0, 100)) ?>...</p>
                            <small class="status-<?= ($ref['approved'] ?? 0) ? 'confirmed' : 'pending' ?>">
                                <?= ($ref['approved'] ?? 0) ? 'Jóváhagyva' : 'Jóváhagyásra vár' ?>
                            </small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- ===== ÚJ ÉRTÉKELÉS ===== -->
            <div id="new-review" class="profile-tab-content">
                <h3>Új értékelés írása</h3>
                
                <form method="POST" class="review-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="add_review">
                    
                    <div class="form-group">
                        <label>Értékelés (1-5 csillag)</label>
                        <select name="rating" required>
                            <option value="" disabled selected>Válasszon</option>
                            <option value="5">★★★★★ - Kitűnő</option>
                            <option value="4">★★★★☆ - Nagyon jó</option>
                            <option value="3">★★★☆☆ - Jó</option>
                            <option value="2">★★☆☆☆ - Átlagos</option>
                            <option value="1">★☆☆☆☆ - Gyenge</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Vélemény</label>
                        <textarea name="comment" rows="4" required placeholder="Írja le tapasztalatait..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Értékelés elküldése</button>
                </form>
            </div>
            
            <!-- ===== ÚJ REFERENCIA (TÖBB KÉP FELTÖLTÉS) ===== -->
            <div id="new-reference" class="profile-tab-content">
                <h3>Új referencia feltöltése</h3>
                
                <form method="POST" enctype="multipart/form-data" class="reference-form" id="referenceForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="add_reference">
                    
                    <div class="form-group">
                        <label>Cím *</label>
                        <input type="text" name="title" required placeholder="Pl. Fürdőszoba felújítás">
                    </div>
                    
                    <div class="form-group">
                        <label>Kategória</label>
                        <select name="category">
                            <option value="vízszerelés">Vízszerelés</option>
                            <option value="gázerősítés">Gázerősítés</option>
                            <option value="teljes">Teljes felújítás</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Leírás *</label>
                        <textarea name="description" rows="4" required placeholder="Részletes leírás..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Képek feltöltése (több is választható)</label>
                        <input type="file" name="reference_images[]" accept="image/*" id="referenceImages" multiple>
                        <small class="form-hint">Több kép is kiválasztható, automatikusan tömörítve lesznek (max. 1200x1200px)</small>
                    </div>
                    
                    <div id="referenceImagesPreview" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem;"></div>
                    
                    <button type="submit" class="btn btn-primary">Referencia feltöltése</button>
                </form>
            </div>

            <!-- ===== JELSZÓ MÓDOSÍTÁSA ===== -->
            <div id="change-password" class="profile-tab-content">
                <h3>Jelszó módosítása</h3>
                <form method="POST" class="profile-form" style="max-width:480px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label>Jelenlegi jelszó</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label>Új jelszó</label>
                        <input type="password" name="new_password" required>
                        <small class="form-hint">Legalább 6 karakter, egy nagybetűvel</small>
                    </div>
                    <div class="form-group">
                        <label>Új jelszó megerősítése</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Jelszó módosítása</button>
                </form>
            </div>

            <!-- ===== ÉRTESÍTÉSEK ===== -->
            <div id="notifications" class="profile-tab-content">
                <h3>Értesítések
                    <?php if ($unread_count > 0): ?>
                    <a href="profil.php?mark_read=1" style="font-size:0.8rem;font-weight:400;margin-left:1rem;color:var(--accent-blue);">
                        <i class="fas fa-check-double"></i> Összes olvasottnak jelöl
                    </a>
                    <?php endif; ?>
                </h3>
                <?php if (empty($notifications)): ?>
                    <p class="no-data">Nincsenek értesítések.</p>
                <?php else: ?>
                    <?php foreach ($notifications as $n): ?>
                    <div style="padding:1rem;border-radius:10px;margin-bottom:0.75rem;background:<?= $n['read'] ? 'var(--bg-surface-2)' : 'var(--bg-surface)' ?>;border:1px solid <?= $n['read'] ? 'var(--border-color)' : 'var(--accent-blue)' ?>;border-left:4px solid <?= $n['read'] ? 'var(--border-color)' : 'var(--accent-blue)' ?>;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                            <strong style="color:var(--text-primary);"><?= e($n['title'] ?? '') ?></strong>
                            <small style="color:var(--text-muted);"><?= date('Y. m. d. H:i', strtotime($n['created_at'])) ?></small>
                        </div>
                        <p style="margin-top:0.4rem;color:var(--text-secondary);font-size:0.95rem;"><?= e($n['message'] ?? '') ?></p>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- ===== LÁBLÉC ===== -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-info">
                    <h3>Vízművek Kft.</h3>
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
                <p>&copy; <?= date('Y') ?> Vízművek Kft. - Minden jog fenntartva.</p>
            </div>
        </div>
    </footer>

    <script src="main.js"></script>
    <script>
    // Profil tabok
    function switchProfileTab(tabId) {
        document.querySelectorAll('.profile-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.profile-tab-content').forEach(c => c.classList.remove('active'));
        const btn = document.querySelector(`.profile-tab-btn[data-tab="${tabId}"]`);
        const content = document.getElementById(tabId);
        if (btn) btn.classList.add('active');
        if (content) content.classList.add('active');
    }

    document.querySelectorAll('.profile-tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            switchProfileTab(this.dataset.tab);
        });
    });

    // Open tab from URL hash (e.g. profil.php#notifications)
    const hash = window.location.hash.replace('#', '');
    if (hash && document.getElementById(hash)) {
        switchProfileTab(hash);
        history.replaceState(null, '', window.location.pathname);
    }
    
    // Profilkép előnézet
    document.getElementById('avatar')?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewContainer = document.getElementById('avatarPreviewContainer');
                const previewImg = document.getElementById('avatarPreviewImg');
                previewImg.src = e.target.result;
                previewContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });
    
    // TÖBB KÉP ELŐNÉZET
    document.getElementById('referenceImages')?.addEventListener('change', function(e) {
        const previewContainer = document.getElementById('referenceImagesPreview');
        previewContainer.innerHTML = '';
        previewContainer.style.display = 'flex';
        
        Array.from(e.target.files).forEach((file, index) => {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.width = '100px';
                    img.style.height = '100px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';
                    img.style.border = '1px solid #e2e8f0';
                    img.style.padding = '2px';
                    previewContainer.appendChild(img);
                };
                reader.readAsDataURL(file);
            }
        });
    });
    
    // Mobil menü
    const menuToggle = document.getElementById('menuToggle');
    const navLinks = document.getElementById('navLinks');
    
    if (menuToggle && navLinks) {
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            navLinks.classList.toggle('active');
        });
        
        document.addEventListener('click', function(e) {
            if (!menuToggle.contains(e.target) && !navLinks.contains(e.target)) {
                navLinks.classList.remove('active');
            }
        });
    }
    </script>
</body>
</html>