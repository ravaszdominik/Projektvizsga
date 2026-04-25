<?php
session_start();
require_once 'config.php';

// Admin ellenőrzés
$is_admin = admin_e();

if (!$is_admin) {
    uzenet('error', 'Hozzáférés megtagadva! Csak adminisztrátorok léphetnek be.');
    atiranyit('login.php');
}

$page_title = "Admin Felület | BaTech";
$csrf_token = csrf_token();

// ============================================
// ADATOK BETÖLTÉSE 
// ============================================
$conn = db();

// Foglalások
$bookings = [];
$bookings_page  = max(1, (int)($_GET['bookings_page'] ?? 1));
$bookings_limit = 15;
$bookings_offset = ($bookings_page - 1) * $bookings_limit;
$bookings_total = 0;
if ($conn) {
    $r = $conn->query("SELECT COUNT(*) FROM bookings");
    if ($r) $bookings_total = (int)$r->fetchColumn();
    $result = $conn->query("SELECT b.*, u.name, u.email FROM bookings b LEFT JOIN users u ON b.user_id = u.id ORDER BY b.created_at DESC LIMIT $bookings_limit OFFSET $bookings_offset");
    if ($result) $bookings = $result->fetchAll(PDO::FETCH_ASSOC);
}

// Szolgáltatások
$services = [];
if ($conn) {
    $result = $conn->query("SELECT * FROM services ORDER BY category, display_order");
    if ($result) $services = $result->fetchAll(PDO::FETCH_ASSOC);
}

// Értékelések
$reviews = [];
if ($conn) {
    $result = $conn->query("SELECT r.*, u.name as user_name FROM reviews r LEFT JOIN users u ON r.user_id = u.id ORDER BY r.created_at DESC");
    if ($result) $reviews = $result->fetchAll(PDO::FETCH_ASSOC);
}

// Referenciák
$references = [];
if ($conn) {
    $result = $conn->query(
        "SELECT r.*, u.name as user_name,
                GROUP_CONCAT(ri.image_url ORDER BY ri.sort_order SEPARATOR '|') as ref_images
         FROM `references` r
         LEFT JOIN users u ON r.user_id = u.id
         LEFT JOIN reference_images ri ON ri.reference_id = r.id
         GROUP BY r.id
         ORDER BY r.created_at DESC"
    );
    if ($result) {
        $references = $result->fetchAll(PDO::FETCH_ASSOC);
        foreach ($references as &$ref) {
            $imgs = !empty($ref['ref_images']) ? explode('|', $ref['ref_images']) : [];
            if (empty($imgs) && !empty($ref['image_url'])) {
                $imgs = [$ref['image_url']];
            }
            $ref['first_image'] = $imgs[0] ?? null;
            $ref['image_count'] = count($imgs);
        }
        unset($ref);
    }
}

// Felhasználók
$users = [];
$users_page   = max(1, (int)($_GET['users_page'] ?? 1));
$users_limit  = 15;
$users_offset = ($users_page - 1) * $users_limit;
$users_total  = 0;
if ($conn) {
    $r = $conn->query("SELECT COUNT(*) FROM users");
    if ($r) $users_total = (int)$r->fetchColumn();
    $result = $conn->query("SELECT id, name, email, phone, user_type, status, created_at FROM users ORDER BY created_at DESC LIMIT $users_limit OFFSET $users_offset");
    if ($result) $users = $result->fetchAll(PDO::FETCH_ASSOC);
}

// Statisztikák
$pending_bookings   = count(array_filter($bookings,    fn($b) => ($b['status'] ?? '') === 'pending'));
$pending_reviews    = count(array_filter($reviews,     fn($r) => !($r['approved'] ?? false)));
$pending_references = count(array_filter($references,  fn($r) => !($r['approved'] ?? false)));

// Audit log
$audit_logs = [];
if ($conn) {
    $result = $conn->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 100");
    if ($result) $audit_logs = $result->fetchAll(PDO::FETCH_ASSOC);
}

// ===== KAPCSOLATFELVÉTELI ÜZENETEK =====
$contact_messages = [];
if ($conn) {
    try {
        $result = $conn->query("SELECT * FROM contact_messages ORDER BY `read` ASC, created_at DESC LIMIT 100");
        if ($result) {
            $contact_messages = $result->fetchAll(PDO::FETCH_ASSOC);
            foreach ($contact_messages as &$cm) {
                $r = $conn->prepare("SELECT r.*, u.name as admin_name FROM contact_replies r LEFT JOIN users u ON r.admin_id = u.id WHERE r.message_id = ? ORDER BY r.created_at ASC");
                $r->execute([$cm['id']]);
                $cm['replies']     = $r->fetchAll(PDO::FETCH_ASSOC);
                $cm['reply_count'] = count($cm['replies']);
            }
            unset($cm);
        }
    } catch (PDOException $e) {}
}

// ============================================
// ÜZENETKÜLDŐ FÜGGVÉNY
// ============================================
function kuldUzenet($conn, $user_id, $tipus, $cim, $uzenet, $referencia_id = null) {
    if (!$conn) return false;
    try {
        $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, type, title, message, reference_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        return $stmt->execute([$user_id, $tipus, $cim, $uzenet, $referencia_id]);
    } catch (Exception $e) {
        error_log("kuldUzenet failed: " . $e->getMessage());
        return false;
    }
}

// ============================================
// ŰRLAPOK FELDOLGOZÁSA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        uzenet('error', 'Érvénytelen token!');
        atiranyit('admin.php');
    }
    
    $action = $_POST['action'] ?? '';
    
    // VALÓS műveletek (ha van adatbázis)
    if ($conn) {
        
        // ===== FOGLALÁS STÁTUSZ FRISSÍTÉSE =====
        if ($action === 'update_booking_status') {
            $id = (int)($_POST['booking_id'] ?? 0);
            $status = $_POST['booking_status'] ?? '';
            $allowed = ['pending', 'confirmed', 'completed', 'cancelled'];
            
            if ($id > 0 && in_array($status, $allowed)) {
                $stmt = $conn->prepare("SELECT user_id, service_type, booking_date FROM bookings WHERE id = ?");
                $stmt->execute([$id]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                $stmt->execute([$status, $id]);
                
                if ($booking && $booking['user_id']) {
                    $status_labels = [
                        'confirmed' => 'Elfogadva',
                        'cancelled' => 'Lemondva',
                        'completed' => 'Teljesítve',
                        'pending'   => 'Függőben',
                    ];
                    $status_label = $status_labels[$status] ?? $status;
                    $cim   = "Foglalás státusz változás";
                    $uzenet = "A foglalása ({$booking['service_type']} - {$booking['booking_date']}) {$status_label} státuszra változott.";
                    kuldUzenet($conn, $booking['user_id'], 'booking', $cim, $uzenet, $id);
                }

                $status_label_log = $status_labels[$status] ?? $status;
                audit_log('booking_status_update', 'booking', $id, "Új státusz: " . $status_label_log);
                uzenet('success', 'Foglalás státusza frissítve!');
            }
        }
        
        // ===== ÉRTÉKELÉS KEZELÉS =====
        if ($action === 'approve_review') {
            $id = (int)($_POST['review_id'] ?? 0);
            
            $stmt = $conn->prepare("SELECT user_id, comment FROM reviews WHERE id = ?");
            $stmt->execute([$id]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $conn->prepare("UPDATE reviews SET approved = 1 WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($review && $review['user_id']) {
                $cim = "Értékelés jóváhagyva";
                $uzenet = "Az értékelése jóváhagyásra került és megjelenik az oldalon.";
                kuldUzenet($conn, $review['user_id'], 'review', $cim, $uzenet, $id);
            }
            
            audit_log('review_approve', 'review', $id);
            uzenet('success', 'Értékelés jóváhagyva!');
        }
        
        if ($action === 'reject_review') {
            $id = (int)($_POST['review_id'] ?? 0);
            
            $stmt = $conn->prepare("SELECT user_id, comment FROM reviews WHERE id = ?");
            $stmt->execute([$id]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($review && $review['user_id']) {
                $cim = "Értékelés elutasítva";
                $uzenet = "Az értékelése elutasításra került. Kérjük, ellenőrizze, hogy megfelel-e a közösségi irányelveknek.";
                kuldUzenet($conn, $review['user_id'], 'review', $cim, $uzenet, $id);
            }
            
            $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
            $stmt->execute([$id]);
            audit_log('review_reject', 'review', $id);
            uzenet('success', 'Értékelés elutasítva és törölve!');
        }
        
        if ($action === 'delete_review') {
            $id = (int)($_POST['review_id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
            $stmt->execute([$id]);
            audit_log('review_delete', 'review', $id);
            uzenet('success', 'Értékelés törölve!');
        }
        
        // ===== REFERENCIA KEZELÉS =====
        if ($action === 'approve_reference') {
            $id = (int)($_POST['reference_id'] ?? 0);
            
            $stmt = $conn->prepare("SELECT user_id, title FROM `references` WHERE id = ?");
            $stmt->execute([$id]);
            $ref = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $conn->prepare("UPDATE `references` SET approved = 1 WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($ref && $ref['user_id']) {
                $cim = "Referencia jóváhagyva";
                $uzenet = "A '{$ref['title']}' című referenciája jóváhagyásra került és megjelenik az oldalon.";
                kuldUzenet($conn, $ref['user_id'], 'reference', $cim, $uzenet, $id);
            }
            
            audit_log('reference_approve', 'reference', $id);
            uzenet('success', 'Referencia jóváhagyva!');
            atiranyit('admin.php#references');
        }
        
        if ($action === 'reject_reference') {
            $id = (int)($_POST['reference_id'] ?? 0);
            
            $stmt = $conn->prepare("SELECT user_id, title FROM `references` WHERE id = ?");
            $stmt->execute([$id]);
            $ref = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ref && $ref['user_id']) {
                $cim = "Referencia elutasítva";
                $uzenet = "A '{$ref['title']}' című referenciája elutasításra került.";
                kuldUzenet($conn, $ref['user_id'], 'reference', $cim, $uzenet, $id);
            }
            
            $stmt = $conn->prepare("DELETE FROM `references` WHERE id = ?");
            $stmt->execute([$id]);
            audit_log('reference_reject', 'reference', $id);
            uzenet('success', 'Referencia elutasítva és törölve!');
            atiranyit('admin.php#references');
        }
        
        if ($action === 'delete_reference') {
            $id = (int)($_POST['reference_id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM `references` WHERE id = ?");
            $stmt->execute([$id]);
            uzenet('success', 'Referencia törölve!');
            atiranyit('admin.php#references');
        }
        
        // ===== FELHASZNÁLÓ KEZELÉS =====
        if ($action === 'make_admin') {
            $id = (int)($_POST['user_id'] ?? 0);
            
            $stmt = $conn->prepare("UPDATE users SET user_type = 'admin' WHERE id = ?");
            $stmt->execute([$id]);
            
            $cim = "Admin jogosultság";
            $uzenet = "Admin jogosultságot kapott a rendszerben.";
            kuldUzenet($conn, $id, 'user', $cim, $uzenet);
            
            audit_log('user_make_admin', 'user', $id);
            uzenet('success', 'Felhasználó adminná téve!');
            atiranyit('admin.php#users');
        }
        
        if ($action === 'remove_admin') {
            $id = (int)($_POST['user_id'] ?? 0);

            if ($id === (int)$_SESSION['user_id']) {
                uzenet('error', 'Nem távolíthatja el saját admin jogosultságát!');
                atiranyit('admin.php#users');
            }
            
            $stmt = $conn->prepare("UPDATE users SET user_type = 'user' WHERE id = ?");
            $stmt->execute([$id]);
            
            $cim = "Admin jogosultság eltávolítva";
            $uzenet = "Admin jogosultsága eltávolításra került.";
            kuldUzenet($conn, $id, 'user', $cim, $uzenet);
            
            audit_log('user_remove_admin', 'user', $id);
            uzenet('success', 'Admin jogosultság eltávolítva!');
            atiranyit('admin.php#users');
        }
        
        if ($action === 'delete_user') {
            $id     = (int)($_POST['user_id'] ?? 0);
            $reason = trim($_POST['delete_reason'] ?? 'Nem megadott ok');
            if ($reason === 'Egyéb') {
                $custom = trim($_POST['delete_reason_custom'] ?? '');
                $reason = !empty($custom) ? $custom : 'Egyéb ok';
            }

            if ($id == $_SESSION['user_id']) {
                uzenet('error', 'Nem törölheti saját magát!');
                atiranyit('admin.php');
            }

            // Felhasználó adatainak lekérése törlés előtt
            $stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $del_user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($del_user) {
                // Mentés a deleted_users táblába
                try {
                    $conn->prepare("INSERT INTO deleted_users (user_id, name, email, reason, deleted_by) VALUES (?, ?, ?, ?, ?)")
                         ->execute([$id, $del_user['name'], $del_user['email'], $reason, $_SESSION['user_id']]);
                } catch (PDOException $e) { error_log("deleted_users insert failed: " . $e->getMessage()); }

                // Email küldés
                $email_body = "
                    <h2>Fiókja törlésre került</h2>
                    <p>Kedves <strong>" . htmlspecialchars($del_user['name']) . "</strong>!</p>
                    <p>Értesítjük, hogy BaTech fiókja törlésre került.</p>
                    <p><strong>Ok:</strong> " . htmlspecialchars($reason) . "</p>
                    <p>Ha kérdése van, kérjük vegye fel velünk a kapcsolatot: <a href='mailto:support@batech.hu'>support@batech.hu</a></p>
                    <br><p>BaTech csapata</p>
                ";
                kuldEmail($del_user['email'], 'BaTech - Fiókja törlésre került', $email_body);
            }

            $conn->prepare("DELETE FROM bookings WHERE user_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM reviews WHERE user_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM `references` WHERE user_id = ?")->execute([$id]);
            try { $conn->prepare("DELETE FROM user_notifications WHERE user_id = ?")->execute([$id]); } catch (PDOException $e) {}
            $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

            audit_log('user_delete', 'user', $id, $del_user ? $del_user['name'] . ' (' . $del_user['email'] . ')' : 'Ismeretlen');
            uzenet('success', 'Felhasználó törölve és értesítve!');
            atiranyit('admin.php#users');
        }
        
        if ($action === 'send_broadcast') {
            $title   = trim($_POST['broadcast_title'] ?? '');
            $message = trim($_POST['broadcast_message'] ?? '');

            if (!empty($title) && !empty($message)) {
                try {
                    $user_ids = $conn->query("SELECT id FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                    $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, type, title, message, created_at) VALUES (?, 'broadcast', ?, ?, NOW())");
                    foreach ($user_ids as $uid) {
                        $stmt->execute([$uid, $title, $message]);
                    }
                    audit_log('broadcast_notification', 'users', count($user_ids), $title);
                    uzenet('success', 'Értesítés elküldve ' . count($user_ids) . ' felhasználónak!');
                } catch (PDOException $e) {
                    error_log("Broadcast failed: " . $e->getMessage());
                    uzenet('error', 'Hiba történt az értesítés küldésekor!');
                }
            } else {
                uzenet('error', 'A cím és az üzenet megadása kötelező!');
            }
            atiranyit('admin.php#users');
        }

        // ===== SZOLGÁLTATÁS KEZELÉS =====
        if ($action === 'add_service') {
            $name     = trim($_POST['service_name'] ?? '');
            $category = trim($_POST['service_category'] ?? '');
            $price    = trim($_POST['service_price'] ?? '');
            $desc     = trim($_POST['service_description'] ?? '');
            $duration = trim($_POST['service_duration'] ?? '');
            $priority = $_POST['service_priority'] ?? 'normal';
            $icon     = trim($_POST['service_icon'] ?? 'fa-tools');
            $order    = (int)($_POST['service_order'] ?? 0);
            $active   = isset($_POST['service_active']) ? 1 : 0;

            $stmt = $conn->prepare(
                "INSERT INTO services (name, title, category, price_range, description, estimated_duration, priority, icon, display_order, active, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $name, $category, $price, $desc, $duration, $priority, $icon, $order, $active, $_SESSION['user_id']]);
            audit_log('service_add', 'service', $conn->lastInsertId(), $name);
            uzenet('success', 'Szolgáltatás hozzáadva!');
            atiranyit('admin.php#services');
        }

        if ($action === 'edit_service') {
            $id       = (int)($_POST['service_id'] ?? 0);
            $name     = trim($_POST['service_name'] ?? '');
            $category = trim($_POST['service_category'] ?? '');
            $price    = trim($_POST['service_price'] ?? '');
            $desc     = trim($_POST['service_description'] ?? '');
            $duration = trim($_POST['service_duration'] ?? '');
            $priority = $_POST['service_priority'] ?? 'normal';
            $icon     = trim($_POST['service_icon'] ?? 'fa-tools');
            $order    = (int)($_POST['service_order'] ?? 0);
            $active   = isset($_POST['service_active']) ? 1 : 0;

            $stmt = $conn->prepare(
                "UPDATE services SET name=?, title=?, category=?, price_range=?, description=?,
                 estimated_duration=?, priority=?, icon=?, display_order=?, active=?
                 WHERE id=?"
            );
            $stmt->execute([$name, $name, $category, $price, $desc, $duration, $priority, $icon, $order, $active, $id]);
            audit_log('service_edit', 'service', $id, $name);
            uzenet('success', 'Szolgáltatás frissítve!');
            atiranyit('admin.php#services');
        }

        if ($action === 'delete_service') {
            $id = (int)($_POST['service_id'] ?? 0);
            $conn->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
            audit_log('service_delete', 'service', $id);
            uzenet('success', 'Szolgáltatás törölve!');
            atiranyit('admin.php#services');
        }

        if ($action === 'add_reference') {
            $client   = trim($_POST['ref_client_name'] ?? '');
            $category = trim($_POST['ref_category'] ?? '');
            $desc     = trim($_POST['ref_description'] ?? '');
            $duration = trim($_POST['ref_duration'] ?? '');
            $price    = trim($_POST['ref_price'] ?? '');

            $image_urls = [];
            $target_dir = 'uploads/references/';
            if (!file_exists($target_dir)) mkdir($target_dir, 0755, true);

            if (isset($_FILES['ref_images']) && !empty($_FILES['ref_images']['name'][0])) {
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                $files   = $_FILES['ref_images'];

                for ($i = 0; $i < min(count($files['name']), 10); $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed)) continue;

                    $new_filename = 'ref_' . time() . '_' . $i . '.webp';
                    $target_file  = $target_dir . $new_filename;

                    try {
                        $source = \Tinify\fromFile($files['tmp_name'][$i]);
                        $source->convert(["type" => "image/webp"])->toFile($target_file);
                        $image_urls[] = $target_file;
                    } catch (Exception $e) {
                        error_log("Tinify error: " . $e->getMessage());
                        move_uploaded_file($files['tmp_name'][$i], $target_dir . 'ref_' . time() . '_' . $i . '.' . $ext);
                        $image_urls[] = $target_dir . 'ref_' . time() . '_' . $i . '.' . $ext;
                    }
                }
            }

            $first_image = $image_urls[0] ?? 'assets/img/default-reference.jpg';

            if (empty($image_urls)) {
                uzenet('error', 'Legalább egy kép feltöltése kötelező!');
                atiranyit('admin.php#references');
            }

            $stmt = $conn->prepare("INSERT INTO `references` (user_id, title, category, description, duration, price, image_url, approved, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$_SESSION['user_id'], $client, $category, $desc, $duration, $price, $first_image]);
            $ref_id = $conn->lastInsertId();

            if (!empty($image_urls)) {
                $img_stmt = $conn->prepare("INSERT INTO `reference_images` (reference_id, image_url, sort_order) VALUES (?, ?, ?)");
                foreach ($image_urls as $order => $url) {
                    $img_stmt->execute([$ref_id, $url, $order]);
                }
            }

            audit_log('reference_add', 'reference', $ref_id, $client);
            uzenet('success', 'Referencia hozzáadva!');
            atiranyit('admin.php#references');
        }

        // ===== KAPCSOLATFELVÉTELI ÜZENETRE VÁLASZOLÁS =====
        if ($action === 'reply_contact') {
            $message_id = (int)($_POST['message_id'] ?? 0);
            $reply_text = trim($_POST['reply_text'] ?? '');

            if (empty($reply_text)) {
                uzenet('error', 'A válasz megadása kötelező!');
            } elseif ($message_id > 0) {
                try {
                    $stmt = $conn->prepare("SELECT name, email, user_id FROM contact_messages WHERE id = ?");
                    $stmt->execute([$message_id]);
                    $msg = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($msg) {
                        // Check last reply — block if admin replied last
                        $last = $conn->prepare("SELECT admin_id FROM contact_replies WHERE message_id = ? ORDER BY created_at DESC LIMIT 1");
                        $last->execute([$message_id]);
                        $last_reply = $last->fetch(PDO::FETCH_ASSOC);

                        if ($last_reply && $last_reply['admin_id'] !== null) {
                            uzenet('error', 'Már válaszolt erre az üzenetre. Várja meg a felhasználó válaszát!');
                            atiranyit('admin.php#contacts');
                        }
                        // Find user by user_id first, then by email
                        $user = null;
                        if (!empty($msg['user_id'])) {
                            $user_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                            $user_stmt->execute([$msg['user_id']]);
                            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        if (!$user && !empty($msg['email'])) {
                            $user_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                            $user_stmt->execute([$msg['email']]);
                            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                        }

                        if ($user) {
                            kuldUzenet($conn, $user['id'], 'contact_reply', 'Válasz érkezett üzenetére',
                                'Az adminisztrátor válaszolt a kapcsolatfelvételi üzenetére. <a href="kapcsolat.php#message-' . $message_id . '">Kattintson ide a megtekintéshez és válaszoláshoz.</a>');
                        }

                        $conn->prepare("INSERT INTO contact_replies (message_id, admin_id, reply_text, created_at) VALUES (?, ?, ?, NOW())")
                             ->execute([$message_id, $_SESSION['user_id'], $reply_text]);
                        $conn->prepare("UPDATE contact_messages SET `read` = 1, replied = 1 WHERE id = ?")->execute([$message_id]);
                        audit_log('contact_reply', 'contact_message', $message_id, substr($reply_text, 0, 100));
                        uzenet('success', 'Válasz elküldve!');
                    }
                } catch (PDOException $e) {
                    error_log("Contact reply error: " . $e->getMessage());
                    uzenet('error', 'Hiba történt!');
                }
            }
            atiranyit('admin.php#contacts');
        }

        // ===== KAPCSOLATFELVÉTELI ÜZENET TÖRLÉSE =====
        if ($action === 'delete_contact_message') {
            $message_id = (int)($_POST['message_id'] ?? 0);
            if ($message_id > 0) {
                try {
                    $stmt = $conn->prepare("SELECT name, subject FROM contact_messages WHERE id = ?");
                    $stmt->execute([$message_id]);
                    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
                    $conn->prepare("DELETE FROM contact_messages WHERE id = ?")->execute([$message_id]);
                    audit_log('contact_message_delete', 'contact_message', $message_id, ($msg['name'] ?? '') . ' - ' . ($msg['subject'] ?? ''));
                    uzenet('success', 'Üzenet törölve!');
                } catch (PDOException $e) {
                    uzenet('error', 'Hiba történt!');
                }
            }
            atiranyit('admin.php#contacts');
        }

        // ===== ÖSSZES KAPCSOLATFELVÉTELI ÜZENET TÖRLÉSE =====
        if ($action === 'delete_all_contact_messages') {
            try {
                $count = (int)$conn->query("SELECT COUNT(*) FROM contact_messages")->fetchColumn();
                $conn->exec("DELETE FROM contact_messages");
                audit_log('contact_messages_delete_all', 'contact_message', 0, "Összes üzenet törölve: $count db");
                uzenet('success', "Összes üzenet törölve! ($count db)");
            } catch (PDOException $e) {
                uzenet('error', 'Hiba történt!');
            }
            atiranyit('admin.php#contacts');
        }

    }
    
    atiranyit('admin.php');
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="<?= tema_osztaly() ?>">
    <!-- ===== NAVIGÁCIÓ ===== -->
    <nav class="navbar admin-navbar">
        <div class="container">
            <a href="index.php" class="logo">
                <i class="fas fa-tools"></i> BaTech<span>Admin</span>
            </a>
            <div class="admin-nav-controls">
                
                <span id="adminUserName"><i class="fas fa-user-circle"></i> <?= e($_SESSION['user_name'] ?? 'Admin') ?></span>
                <button id="logoutBtn" class="btn btn-logout"><i class="fas fa-sign-out-alt"></i> Kijelentkezés</button>
            </div>
        </div>
    </nav>

    <div class="admin-container">
        <!-- ===== OLDALSÁV ===== -->
        <aside class="admin-sidebar">
            <div class="admin-profile">
                <div class="profile-image">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="profile-info">
                    <h3><?= e($_SESSION['user_name'] ?? 'Admin') ?></h3>
                    <p>Adminisztrátor</p>
                </div>
            </div>
            
            <ul class="admin-menu">
                <li><a href="#dashboard" class="active" data-tab="dashboard"><i class="fas fa-tachometer-alt"></i> Irányítópult</a></li>
                <li><a href="#bookings" data-tab="bookings"><i class="fas fa-calendar-check"></i> Foglalások <span class="menu-badge" id="pendingBookingsCount"><?= $pending_bookings ?></span></a></li>
                <li><a href="#services" data-tab="services"><i class="fas fa-tools"></i> Szolgáltatások</a></li>
                <li><a href="#reviews" data-tab="reviews"><i class="fas fa-star"></i> Értékelések <span class="menu-badge" id="pendingReviewsCount"><?= $pending_reviews ?></span></a></li>
                <li><a href="#references" data-tab="references"><i class="fas fa-images"></i> Referenciák <span class="menu-badge" id="pendingReferencesCount"><?= $pending_references ?></span></a></li>
                <li><a href="#users" data-tab="users"><i class="fas fa-users"></i> Felhasználók</a></li>
                <li><a href="#auditlog" data-tab="auditlog"><i class="fas fa-history"></i> Admin napló</a></li>
                <li><a href="#contacts" data-tab="contacts"><i class="fas fa-envelope"></i> Üzenetek <span class="menu-badge" id="pendingContactsCount"><?= count(array_filter($contact_messages, fn($c) => $c['status'] === 'new')) ?></span></a></li>
            </ul>
        </aside>

        <!-- ===== FŐ TARTALOM ===== -->
        <main class="admin-content">
            
            <?php foreach ($messages as $msg): ?>
            <div class="message <?= $msg['type'] ?>"><i class="fas fa-info-circle"></i> <?= e($msg['text']) ?></div>
            <?php endforeach; ?>
            
            <!-- ===== DASHBOARD ===== -->
            <div id="dashboard" class="admin-tab active">
                <h1><i class="fas fa-tachometer-alt"></i> Irányítópult</h1>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-calendar-check fa-2x"></i></div>
                        <div class="stat-details">
                            <h3><?= count($bookings) ?></h3>
                            <p>Összes foglalás</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-clock fa-2x"></i></div>
                        <div class="stat-details">
                            <h3><?= $pending_bookings ?></h3>
                            <p>Függőben lévő foglalások</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-star fa-2x"></i></div>
                        <div class="stat-details">
                            <h3><?= count($reviews) ?></h3>
                            <p>Értékelések</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-images fa-2x"></i></div>
                        <div class="stat-details">
                            <h3><?= $pending_references ?></h3>
                            <p>Új referenciák</p>
                        </div>
                    </div>
                </div>
                
                <!-- CHARTS -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:2rem;">
                    <div style="background:var(--bg-surface);border-radius:16px;padding:1rem;box-shadow:var(--shadow);">
                        <h3 style="margin-bottom:0.5rem;color:var(--text-primary);font-size:0.95rem;">Foglalások státusz szerint</h3>
                        <div style="height:180px;"><canvas id="bookingStatusChart"></canvas></div>
                    </div>
                    <div style="background:var(--bg-surface);border-radius:16px;padding:1rem;box-shadow:var(--shadow);">
                        <h3 style="margin-bottom:0.5rem;color:var(--text-primary);font-size:0.95rem;">Felhasználók és értékelések</h3>
                        <div style="height:180px;"><canvas id="overviewChart"></canvas></div>
                    </div>
                </div>

                <div class="recent-activities">
                    <h2><i class="fas fa-history"></i> Legutóbbi tevékenységek</h2>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Típus</th>
                                <th>Leírás</th>
                                <th>Dátum</th>
                                <th>Státusz</th>
                             </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $activities = [];
                            $hu_status = ['pending'=>'Függőben','confirmed'=>'Elfogadva','completed'=>'Teljesítve','cancelled'=>'Lemondva'];
                            foreach (array_slice($bookings, 0, 2) as $b) {
                                $activities[] = ['type' => 'Foglalás', 'desc' => $b['name'] . ' - ' . ($b['service_type'] ?? ''), 'date' => $b['booking_date'] ?? '', 'status' => $hu_status[$b['status'] ?? 'pending'] ?? 'Függőben'];
                            }
                            foreach (array_slice($reviews, 0, 2) as $r) {
                                $activities[] = ['type' => 'Értékelés', 'desc' => ($r['user_name'] ?? 'Ismeretlen') . ' - ' . substr($r['comment'] ?? '', 0, 30) . '...', 'date' => date('Y-m-d', strtotime($r['created_at'] ?? '')), 'status' => $r['approved'] ? 'Jóváhagyva' : 'Függőben'];
                            }
                            foreach (array_slice($references, 0, 2) as $ref) {
                                $activities[] = ['type' => 'Referencia', 'desc' => ($ref['user_name'] ?? 'Ismeretlen') . ' - ' . ($ref['title'] ?? ''), 'date' => date('Y-m-d', strtotime($ref['created_at'] ?? '')), 'status' => $ref['approved'] ? 'Jóváhagyva' : 'Függőben'];
                            }
                            usort($activities, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
                            $activities = array_slice($activities, 0, 5);
                            ?>
                            <?php foreach ($activities as $act): ?>
                            <tr>
                                <td><?= e($act['type']) ?></td>
                                <td><?= e($act['desc']) ?></td>
                                <td><?= e($act['date']) ?></td>
                                <td><span class="status-<?= in_array($act['status'], ['Jóváhagyva','Elfogadva']) ? 'confirmed' : (in_array($act['status'], ['Függőben']) ? 'pending' : (in_array($act['status'], ['Teljesítve']) ? 'completed' : 'cancelled')) ?>"><?= e($act['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- ===== FOGLALÁSOK ===== -->
            <div id="bookings" class="admin-tab">
                <h1><i class="fas fa-calendar-check"></i> Időpontfoglalások</h1>
                <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Név</th>
                            <th><i class="fas fa-envelope"></i> Email</th>
                            <th><i class="fas fa-tools"></i> Szolgáltatás</th>
                            <th><i class="fas fa-calendar-alt"></i> Dátum</th>
                            <th><i class="fas fa-clock"></i> Idő</th>
                            <th><i class="fas fa-sticky-note"></i> Megjegyzés</th>
                            <th><i class="fas fa-tag"></i> Státusz</th>
                            <th><i class="fas fa-cog"></i> Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bookings)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fas fa-calendar-times"></i> Még nincs foglalás.?</p>
                        <?php endif; ?>
                        <?php foreach ($bookings as $b): ?>
                        <?php $st = $b['status'] ?? 'pending'; ?>
                        <tr>
                            <td><i class="fas fa-user-circle"></i> <?= e($b['name'] ?? 'Vendég') ?></td>
                            <td><i class="fas fa-envelope"></i> <?= e($b['email'] ?? '') ?></td>
                            <td><?= e($b['service_type'] ?? '') ?></td>
                            <td><?= e($b['booking_date'] ?? '') ?></td>
                            <td><?= e($b['booking_time'] ?? '') ?></td>
                            <td><?= e($b['note'] ?? '-') ?></td>
                            <td>
                                <?php
                                $labels = ['pending'=>'Függőben','confirmed'=>'Elfogadva','completed'=>'Teljesítve','cancelled'=>'Lemondva'];
                                $icons = ['pending'=>'fa-clock','confirmed'=>'fa-check-circle','completed'=>'fa-flag-checkered','cancelled'=>'fa-times-circle'];
                                ?>
                                <span class="status-<?= e($st) ?>"><i class="fas <?= $icons[$st] ?? 'fa-clock' ?>"></i> <?= $labels[$st] ?? e($st) ?></span>
                            </td>
                            <td class="action-cell">
                                <?php if ($st === 'pending'): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="update_booking_status">
                                        <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                        <input type="hidden" name="booking_status" value="confirmed">
                                        <button type="submit" class="btn-action btn-approve" title="Jóváhagyás">
                                            <i class="fas fa-check"></i> Jóváhagyás
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="update_booking_status">
                                        <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                        <input type="hidden" name="booking_status" value="cancelled">
                                        <button type="submit" class="btn-action btn-delete" title="Elutasítás">
                                            <i class="fas fa-times"></i> Elutasítás
                                        </button>
                                    </form>
                                <?php elseif ($st === 'confirmed'): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="update_booking_status">
                                        <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                        <input type="hidden" name="booking_status" value="completed">
                                        <button type="submit" class="btn-action btn-complete" title="Teljesítve">
                                            <i class="fas fa-flag-checkered"></i> Teljesítve
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="update_booking_status">
                                        <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                        <input type="hidden" name="booking_status" value="cancelled">
                                        <button type="submit" class="btn-action btn-delete" title="Lemondás">
                                            <i class="fas fa-times"></i> Lemondás
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php
                $bookings_pages = ceil($bookings_total / $bookings_limit);
                if ($bookings_pages > 1): ?>
                <div style="display:flex;gap:0.5rem;justify-content:center;margin-top:1rem;flex-wrap:wrap;">
                    <?php for ($p = 1; $p <= $bookings_pages; $p++): ?>
                    <a href="admin.php?bookings_page=<?= $p ?>#bookings"
                       style="padding:0.4rem 0.8rem;border-radius:8px;border:1px solid var(--border-color);background:<?= $p === $bookings_page ? 'var(--accent-blue)' : 'var(--bg-surface)' ?>;color:<?= $p === $bookings_page ? 'white' : 'var(--text-primary)' ?>;text-decoration:none;">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ===== SZOLGÁLTATÁSOK ===== -->
            <div id="services" class="admin-tab">
                <h1><i class="fas fa-tools"></i> Szolgáltatások</h1>

                <!-- ADD FORM -->
                <div class="admin-form">
                    <h2><i class="fas fa-plus-circle"></i> Új szolgáltatás hozzáadása</h2>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="add_service">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Név *</label>
                                <input type="text" name="service_name" required placeholder="pl. Csapcsere">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-folder"></i> Kategória *</label>
                                <select name="service_category" required>
                                    <option value="vízszerelés">Vízszerelés</option>
                                    <option value="gázerősítés">Gázerősítés</option>
                                    <option value="sürgősségi">Sürgősségi</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-money-bill-wave"></i> Ár (Ft)</label>
                                <input type="text" name="service_price" placeholder="pl. 8.000 - 15.000">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hourglass-half"></i> Becsült időtartam</label>
                                <input type="text" name="service_duration" placeholder="pl. 1-2 óra">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-arrow-up"></i> Prioritás</label>
                                <select name="service_priority">
                                    <option value="normal">Normál</option>
                                    <option value="high">Magas</option>
                                    <option value="urgent">Sürgős</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-icons"></i> Ikon (Font Awesome osztály)</label>
                                <input type="text" name="service_icon" placeholder="fa-tools" value="fa-tools">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-sort-numeric-down"></i> Sorrend</label>
                                <input type="number" name="service_order" value="0" min="0">
                            </div>
                            <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:0.2rem;">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="service_active" checked>
                                    <span><i class="fas fa-check-circle"></i> Aktív (megjelenik az árak oldalon)</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Leírás</label>
                            <textarea name="service_description" rows="2" placeholder="Rövid leírás..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Hozzáadás</button>
                    </form>
                </div>

                <!-- SERVICES TABLE -->
                <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-tag"></i> Név</th>
                            <th><i class="fas fa-folder"></i> Kategória</th>
                            <th><i class="fas fa-money-bill-wave"></i> Ár (Ft)</th>
                            <th><i class="fas fa-hourglass-half"></i> Időtartam</th>
                            <th><i class="fas fa-arrow-up"></i> Prioritás</th>
                            <th><i class="fas fa-sort-numeric-down"></i> Sorrend</th>
                            <th><i class="fas fa-power-off"></i> Státusz</th>
                            <th><i class="fas fa-cog"></i> Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($services as $s): ?>
                        <tr>
                            <td>
                                <strong><i class="fas <?= e($s['icon'] ?? 'fa-tools') ?>"></i> <?= e($s['name'] ?? '') ?></strong>
                                <?php if (!empty($s['description'])): ?>
                                <br><small style="color:var(--text-muted)"><i class="fas fa-align-left"></i> <?= e(mb_substr($s['description'], 0, 50)) ?>…</small>
                                <?php endif; ?>
                              </td>
                              <td><?= e($s['category'] ?? '') ?></td>
                              <td><?= e($s['price_range'] ?? '-') ?></td>
                              <td><?= e($s['estimated_duration'] ?? '-') ?></td>
                              <td>
                                <span class="priority-badge priority-<?= e($s['priority'] ?? 'normal') ?>">
                                    <i class="fas fa-flag"></i> <?= ['normal'=>'Normál','high'=>'Magas','urgent'=>'Sürgős'][$s['priority'] ?? 'normal'] ?>
                                </span>
                              </td>
                              <td><?= (int)($s['display_order'] ?? 0) ?></td>
                              <td>
                                <span class="status-<?= ($s['active'] ?? 1) ? 'confirmed' : 'pending' ?>">
                                    <i class="fas <?= ($s['active'] ?? 1) ? 'fa-check-circle' : 'fa-pause-circle' ?>"></i>
                                    <?= ($s['active'] ?? 1) ? 'Aktív' : 'Inaktív' ?>
                                </span>
                              </td>
                            <td class="action-cell">
                                <button class="btn-action btn-edit"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)">
                                    <i class="fas fa-edit"></i> Szerkesztés
                                </button>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Biztosan törli ezt a szolgáltatást?')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="delete_service">
                                    <input type="hidden" name="service_id" value="<?= (int)$s['id'] ?>">
                                    <button type="submit" class="btn-action btn-delete">
                                        <i class="fas fa-trash"></i> Törlés
                                    </button>
                                </form>
                             </td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (empty($services)): ?>
                          <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fas fa-tools"></i> Még nincs szolgáltatás.</td></tr>
                        <?php endif; ?>
                    </tbody>
                 </table>
                </div>
            </div>

            <!-- ===== EDIT MODAL ===== -->
            <div id="editModal" class="modal-overlay" style="display:none">
                <div class="modal-box">
                    <div class="modal-header">
                        <h2><i class="fas fa-edit"></i> Szolgáltatás szerkesztése</h2>
                        <button class="modal-close" onclick="closeEditModal()">&times;</button>
                    </div>
                    <form method="POST" id="editForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="edit_service">
                        <input type="hidden" name="service_id" id="edit_id">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Név *</label>
                                <input type="text" name="service_name" id="edit_name" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-folder"></i> Kategória *</label>
                                <select name="service_category" id="edit_category" required>
                                    <option value="vízszerelés">Vízszerelés</option>
                                    <option value="gázerősítés">Gázerősítés</option>
                                    <option value="sürgősségi">Sürgősségi</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-money-bill-wave"></i> Ár (Ft)</label>
                                <input type="text" name="service_price" id="edit_price">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hourglass-half"></i> Becsült időtartam</label>
                                <input type="text" name="service_duration" id="edit_duration">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-arrow-up"></i> Prioritás</label>
                                <select name="service_priority" id="edit_priority">
                                    <option value="normal">Normál</option>
                                    <option value="high">Magas</option>
                                    <option value="urgent">Sürgős</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-icons"></i> Ikon (Font Awesome osztály)</label>
                                <input type="text" name="service_icon" id="edit_icon">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-sort-numeric-down"></i> Sorrend</label>
                                <input type="number" name="service_order" id="edit_order" min="0">
                            </div>
                            <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:0.2rem;">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="service_active" id="edit_active">
                                    <span><i class="fas fa-check-circle"></i> Aktív</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Leírás</label>
                            <textarea name="service_description" id="edit_description" rows="3"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeEditModal()"><i class="fas fa-times"></i> Mégse</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mentés</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- ===== ÉRTÉKELÉSEK ===== -->
            <div id="reviews" class="admin-tab">
                <h1><i class="fas fa-star"></i> Értékelések</h1>
                <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Név</th>
                            <th><i class="fas fa-star"></i> Értékelés</th>
                            <th><i class="fas fa-align-left"></i> Szöveg</th>
                            <th><i class="fas fa-calendar-alt"></i> Dátum</th>
                            <th><i class="fas fa-tag"></i> Státusz</th>
                            <th><i class="fas fa-cog"></i> Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviews as $r): ?>
                        <?php $stars = str_repeat('★', (int)($r['rating'] ?? 0)) . str_repeat('☆', 5 - (int)($r['rating'] ?? 0)); ?>
                        <tr>
                            <td><i class="fas fa-user-circle"></i> <?= e($r['user_name'] ?? $r['guest_name'] ?? 'Névtelen') ?></td>
                            <td style="color: #fbbf24; font-size: 1.2rem;"><?= $stars ?></td>
                            <td><?= e(substr($r['comment'] ?? '', 0, 50)) ?>...</td>
                            <td><?= date('Y-m-d', strtotime($r['created_at'] ?? '')) ?></td>
                            <td>
                                <span class="status-<?= ($r['approved'] ?? 0) ? 'confirmed' : 'pending' ?>">
                                    <i class="fas <?= ($r['approved'] ?? 0) ? 'fa-check-circle' : 'fa-clock' ?>"></i>
                                    <?= ($r['approved'] ?? 0) ? 'Jóváhagyva' : 'Függőben' ?>
                                </span>
                            </td>
                            <td class="action-cell">
                                <?php if (!($r['approved'] ?? 0)): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="approve_review">
                                        <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn-action btn-approve" title="Jóváhagyás">
                                            <i class="fas fa-check"></i> Jóváhagyás
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline"
                                          onsubmit="return confirm('Biztosan elutasítja és törli ezt az értékelést?')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="reject_review">
                                        <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn-action btn-delete" title="Elutasítás">
                                            <i class="fas fa-times"></i> Elutasítás
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Biztosan törli ezt az értékelést?')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="delete_review">
                                    <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn-action btn-delete" title="Törlés">
                                        <i class="fas fa-trash"></i> Törlés
                                    </button>
                                </form>
                              </td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reviews)): ?>
                          <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fas fa-star"></i> Még nincs értékelés.</td></tr>
                        <?php endif; ?>
                    </tbody>
                 </table>
                </div>
            </div>
            
            <!-- ===== REFERENCIÁK ===== -->
            <div id="references" class="admin-tab">
                <h1><i class="fas fa-images"></i> Referenciák kezelése
                    <button class="btn btn-primary" style="float:right;font-size:0.9rem;" onclick="document.getElementById('addReferenceForm').style.display = document.getElementById('addReferenceForm').style.display === 'none' ? 'block' : 'none'">
                        <i class="fas fa-plus"></i> Referencia készítése
                    </button>
                </h1>

                <!-- ÚJ REFERENCIA HOZZÁADÁSA -->
                <div class="admin-form" id="addReferenceForm" style="display:none;margin-bottom:2rem;margin-top:1rem;">
                    <h2><i class="fas fa-plus-circle"></i> Új referencia hozzáadása</h2>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="add_reference">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Ügyfél neve</label>
                                <input type="text" name="ref_client_name" placeholder="Pl. Kovács János" required>
                            </div>
                            <div class="form-group">
                                <label>Kategória</label>
                                <select name="ref_category">
                                    <option value="vízszerelés">Vízszerelés</option>
                                    <option value="gázerősítés">Gázerősítés</option>
                                    <option value="sürgősségi">Sürgősségi</option>
                                    <option value="egyéb">Egyéb</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Időtartam</label>
                                <input type="text" name="ref_duration" placeholder="Pl. 2 nap">
                            </div>
                            <div class="form-group">
                                <label>Ár</label>
                                <input type="text" name="ref_price" placeholder="Pl. 45.000 Ft">
                            </div>
                            <div class="form-group">
                                <label>Képek (több is választható)</label>
                                <input type="file" name="ref_images[]" accept="image/*" multiple>
                                <small class="form-hint">JPG, PNG — automatikusan WebP-re konvertálva és tömörítve</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Leírás</label>
                            <textarea name="ref_description" rows="3" placeholder="Munka leírása..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Hozzáadás</button>
                    </form>
                </div>

                <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Felhasználó</th>
                            <th><i class="fas fa-image"></i> Kép(ek)</th>
                            <th><i class="fas fa-heading"></i> Cím</th>
                            <th><i class="fas fa-align-left"></i> Leírás</th>
                            <th><i class="fas fa-tag"></i> Kategória</th>
                            <th><i class="fas fa-calendar-alt"></i> Dátum</th>
                            <th><i class="fas fa-tag"></i> Státusz</th>
                            <th><i class="fas fa-cog"></i> Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($references)): ?>
                          <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fas fa-images"></i> Még nincs referencia.</td></tr>
                        <?php else: ?>
                            <?php foreach ($references as $ref): ?>
                              <tr>
                                  <td><i class="fas fa-user-circle"></i> <?= e($ref['user_name'] ?? 'Ismeretlen') ?></td>
                                  <td>
                                    <?php if (!empty($ref['first_image'])): ?>
                                        <div style="display:flex; align-items:center; gap:0.5rem;">
                                            <img src="<?= e($ref['first_image']) ?>" style="width:50px; height:50px; object-fit:cover; border-radius:4px;" alt="kép">
                                            <?php if (($ref['image_count'] ?? 0) > 1): ?>
                                                <span class="badge" style="background:#3498db; color:white; border-radius:12px; padding:0.2rem 0.5rem; font-size:0.7rem;">+<?= $ref['image_count'] - 1 ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <i class="fas fa-image" style="color:var(--text-muted); font-size:1.5rem;"></i>
                                    <?php endif; ?>
                                  </td>
                                  <td><strong><?= e($ref['title'] ?? '') ?></strong></td>
                                  <td><?= e(substr($ref['description'] ?? '', 0, 50)) ?>...</td>
                                  <td><?= e($ref['category'] ?? '-') ?></td>
                                  <td><?= date('Y-m-d', strtotime($ref['created_at'] ?? '')) ?></td>
                                  <td>
                                    <span class="status-<?= ($ref['approved'] ?? 0) ? 'confirmed' : 'pending' ?>">
                                        <i class="fas <?= ($ref['approved'] ?? 0) ? 'fa-check-circle' : 'fa-clock' ?>"></i>
                                        <?= ($ref['approved'] ?? 0) ? 'Jóváhagyva' : 'Jóváhagyásra vár' ?>
                                    </span>
                                  </td>
                                <td class="action-cell">
                                    <?php if (!($ref['approved'] ?? 0)): ?>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="approve_reference">
                                            <input type="hidden" name="reference_id" value="<?= (int)$ref['id'] ?>">
                                            <button type="submit" class="btn-action btn-approve" title="Jóváhagyás">
                                                <i class="fas fa-check"></i> Jóváhagyás
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline"
                                              onsubmit="return confirm('Biztosan elutasítja és törli ezt a referenciát?')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="reject_reference">
                                            <input type="hidden" name="reference_id" value="<?= (int)$ref['id'] ?>">
                                            <button type="submit" class="btn-action btn-delete" title="Elutasítás">
                                                <i class="fas fa-times"></i> Elutasítás
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline"
                                          onsubmit="return confirm('Biztosan törli ezt a referenciát?')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="delete_reference">
                                        <input type="hidden" name="reference_id" value="<?= (int)$ref['id'] ?>">
                                        <button type="submit" class="btn-action btn-delete" title="Törlés">
                                            <i class="fas fa-trash"></i> Törlés
                                        </button>
                                    </form>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            
            <!-- ===== FELHASZNÁLÓK ===== -->
            <div id="users" class="admin-tab">
                <h1><i class="fas fa-users"></i> Felhasználók</h1>

                <!-- ÉRTESÍTÉS KÜLDÉSE MINDENKINEK -->
                <div class="admin-form" style="margin-bottom:2rem;">
                    <h2><i class="fas fa-bullhorn"></i> Értesítés küldése minden felhasználónak</h2>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="send_broadcast">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Cím *</label>
                                <input type="text" name="broadcast_title" placeholder="Pl. Akciós ajánlat!" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Üzenet *</label>
                            <textarea name="broadcast_message" rows="3" placeholder="Az üzenet szövege..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Biztosan elküldi ezt az értesítést minden felhasználónak?')">
                            <i class="fas fa-paper-plane"></i> Küldés mindenkinek
                        </button>
                    </form>
                </div>

                <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Név</th>
                            <th><i class="fas fa-envelope"></i> Email</th>
                            <th><i class="fas fa-phone"></i> Telefon</th>
                            <th><i class="fas fa-calendar-alt"></i> Regisztráció</th>
                            <th><i class="fas fa-user-tag"></i> Típus</th>
                            <th><i class="fas fa-cog"></i> Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                         <tr>
                             <td><i class="fas fa-user-circle"></i> <?= e($u['name'] ?? '') ?></td>
                             <td><i class="fas fa-envelope"></i> <?= e($u['email'] ?? '') ?></td>
                             <td><i class="fas fa-phone"></i> <?= e($u['phone'] ?? '-') ?></td>
                             <td><?= date('Y-m-d', strtotime($u['created_at'] ?? '')) ?></td>
                             <td>
                                <span class="status-<?= ($u['user_type'] ?? '') === 'admin' ? 'confirmed' : 'pending' ?>">
                                    <i class="fas <?= ($u['user_type'] ?? '') === 'admin' ? 'fa-crown' : 'fa-user' ?>"></i>
                                    <?= ($u['user_type'] ?? '') === 'admin' ? 'Adminisztrátor' : 'Felhasználó' ?>
                                </span>
                             </td>
                            <td class="action-cell">
                                <?php if (($u['user_type'] ?? '') === 'admin'): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="remove_admin">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn-action btn-edit" title="Admin jog elvétele"
                                            <?= $u['id'] == $_SESSION['user_id'] ? 'onclick="alert(\'Nem távolíthatja el saját admin jogosultságát!\'); return false;"' : 'onclick="return confirm(\'Biztosan elveszi az admin jogosultságot?\')"' ?>>
                                            <i class="fas fa-user-minus"></i> Admin eltávolítás
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="make_admin">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn-action btn-approve" title="Adminná tesz">
                                            <i class="fas fa-crown"></i> Adminná tesz
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <button type="button" class="btn-action btn-delete"
                                    onclick="openDeleteUserModal(<?= (int)$u['id'] ?>, '<?= e($u['name']) ?>')">
                                    <i class="fas fa-trash"></i> Törlés
                                </button>
                                <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                          <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fas fa-users"></i> Még nincs felhasználó.?</p>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <?php
                $users_pages = ceil($users_total / $users_limit);
                if ($users_pages > 1): ?>
                <div style="display:flex;gap:0.5rem;justify-content:center;margin-top:1rem;flex-wrap:wrap;">
                    <?php for ($p = 1; $p <= $users_pages; $p++): ?>
                    <a href="admin.php?users_page=<?= $p ?>#users"
                       style="padding:0.4rem 0.8rem;border-radius:8px;border:1px solid var(--border-color);background:<?= $p === $users_page ? 'var(--accent-blue)' : 'var(--bg-surface)' ?>;color:<?= $p === $users_page ? 'white' : 'var(--text-primary)' ?>;text-decoration:none;">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ===== ADMIN NAPLÓ ===== -->
            <div id="auditlog" class="admin-tab">
                <h1><i class="fas fa-history"></i> Admin napló</h1>
                <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-calendar-alt"></i> Dátum</th>
                            <th><i class="fas fa-user"></i> Admin</th>
                            <th><i class="fas fa-bolt"></i> Művelet</th>
                            <th><i class="fas fa-tag"></i> Típus</th>
                            <th><i class="fas fa-info-circle"></i> Részletek</th>
                            <th><i class="fas fa-network-wired"></i> IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($audit_logs)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fas fa-history"></i> Még nincs naplóbejegyzés.?</p>
                        <?php else: ?>
                        <?php 
                        $action_names = [
                            'user_delete'           => 'Felhasználó törölve',
                            'user_make_admin'       => 'Admin jogosultság adva',
                            'user_remove_admin'     => 'Admin jogosultság elvéve',
                            'booking_status_update' => 'Foglalás frissítve',
                            'review_approve'        => 'Értékelés jóváhagyva',
                            'review_reject'         => 'Értékelés elutasítva',
                            'review_delete'         => 'Értékelés törölve',
                            'reference_approve'     => 'Referencia jóváhagyva',
                            'reference_reject'      => 'Referencia elutasítva',
                            'reference_add'         => 'Referencia hozzáadva',
                            'service_add'           => 'Szolgáltatás hozzáadva',
                            'service_edit'          => 'Szolgáltatás szerkesztve',
                            'service_delete'        => 'Szolgáltatás törölve',
                            'broadcast_notification'=> 'Értesítés küldve mindenkinek',
                            'contact_reply'         => 'Válasz küldve üzenetre',
                            'contact_message_delete' => 'Kapcsolatüzenet törölve',
                            'contact_messages_delete_all' => 'Összes üzenet törölve',
                        ];
                        foreach ($audit_logs as $log): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></td>
                            <td><?= e($log['user_name'] ?? '-') ?></td>
                            <td><?= e($action_names[$log['action']] ?? $log['action']) ?></td>
                            <td>
                                <?= e($log['target_type'] ?? '-') ?><?= $log['target_id'] ? ' #' . $log['target_id'] : '' ?>
                            </td>
                            <td><?= e($log['details'] ?? '-') ?></td>
                            <td><?= e($log['ip_address'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- ===== KAPCSOLATFELVÉTELI ÜZENETEK VÁLASZOKKAL ===== -->
            <!-- ===== KAPCSOLATFELVÉTELI ÜZENETEK VÁLASZOKKAL ===== -->
<div id="contacts" class="admin-tab">
    <h1><i class="fas fa-envelope"></i> Kapcsolatfelvételi üzenetek
        <button class="btn btn-secondary" style="float:right;font-size:0.9rem; background:#ef4444; border-color:#ef4444;" 
                onclick="if(confirm('Biztosan törölni szeretné az ÖSSZES üzenetet? Ez a művelet nem visszavonható!')) document.getElementById('deleteAllMessagesForm').submit();">
            <i class="fas fa-trash-alt"></i> Összes törlése
        </button>
    </h1>
    
    <form id="deleteAllMessagesForm" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="action" value="delete_all_contact_messages">
    </form>
    
    <style>
        .admin-contact-thread {
            background: var(--bg-surface);
            border-radius: 12px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--border-color);
            position: relative;
        }
        .admin-contact-header {
            padding: 1rem 1.5rem;
            background: var(--bg-surface-hover);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            cursor: pointer;
            padding-right: 3.5rem;
        }
        .admin-contact-header:hover {
            background: var(--bg-surface-active);
        }
        .admin-contact-subject {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .admin-contact-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .admin-contact-body {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        .admin-contact-original {
            background: var(--bg-surface-light);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .admin-contact-reply-item {
            background: var(--bg-input);
            margin: 0.75rem 0 0.75rem 1.5rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border-left: 3px solid var(--accent-blue);
        }
        .admin-contact-reply-admin {
            border-left-color: #f59e0b;
        }
        .admin-contact-reply-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
        }
        .admin-contact-reply-text {
            color: var(--text-secondary);
        }
        .admin-reply-form {
            padding: 1rem 1.5rem 1.5rem;
            background: var(--bg-surface);
            border-top: 1px solid var(--border-color);
        }
        .admin-reply-form textarea {
            width: 100%;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            resize: vertical;
            margin-bottom: 0.75rem;
        }
        .admin-reply-form button {
            padding: 0.5rem 1rem;
            background: var(--accent-blue);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
        .contact-collapsed .admin-contact-body,
        .contact-collapsed .admin-reply-form {
            display: none;
        }
        .contact-collapsed .toggle-icon {
            transform: rotate(-90deg);
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
        .delete-message-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0.3rem;
            border-radius: 6px;
            transition: all 0.2s;
            z-index: 10;
        }
        .delete-message-btn:hover {
            background: #fee2e2;
            color: #dc2626;
            transform: scale(1.1);
        }
        .dark-theme .delete-message-btn:hover {
            background: #450a0a;
        }
        .btn-danger {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
        }
        .btn-danger:hover {
            background: #dc2626;
        }
    </style>
    
    <div>
        <?php if (empty($contact_messages)): ?>
            <div class="no-messages" style="text-align:center;padding:3rem;background:var(--bg-surface);border-radius:12px;">
                <i class="fas fa-envelope" style="font-size:3rem;opacity:0.5;margin-bottom:1rem;display:block;"></i>
                <p>Még nincs kapcsolatfelvételi üzenet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($contact_messages as $cm): ?>
                <div class="admin-contact-thread" data-message-id="<?= $cm['id'] ?>">
                    <!-- TÖRLÉS GOMB -->
                    <form method="POST" onsubmit="return confirm('Biztosan törölni szeretné ezt az üzenetet és az összes hozzá tartozó választ? A művelet nem visszavonható!')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="delete_contact_message">
                        <input type="hidden" name="message_id" value="<?= $cm['id'] ?>">
                        <button type="submit" class="delete-message-btn" title="Üzenet törlése">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </form>
                    
                    <div class="admin-contact-header" onclick="toggleContactThread(this.closest('.admin-contact-thread'))">
                        <div class="admin-contact-subject">
                            <i class="fas fa-user-circle"></i>
                            <strong><?= e($cm['name']) ?></strong>
                            <span style="font-size:0.9rem; color:var(--text-muted);">(<?= e($cm['email']) ?>)</span>
                            <?php if ($cm['status'] === 'new'): ?>
                                <span class="message-status status-new"><i class="fas fa-envelope"></i> Új, válaszra vár</span>
                            <?php else: ?>
                                <span class="message-status status-replied"><i class="fas fa-reply-all"></i> Válaszolva (<?= (int)$cm['reply_count'] ?> válasz)</span>
                            <?php endif; ?>
                            <?php if (!empty($cm['subject'])): ?>
                                <span class="badge" style="background:var(--bg-input); padding:0.2rem 0.6rem; border-radius:20px; font-size:0.75rem;">
                                    <i class="fas fa-tag"></i> <?= e($cm['subject']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="admin-contact-meta">
                            <span><i class="fas fa-calendar-alt"></i> <?= date('Y-m-d H:i', strtotime($cm['created_at'])) ?></span>
                            <?php if ($cm['phone']): ?>
                                <span><i class="fas fa-phone"></i> <?= e($cm['phone']) ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-comments"></i> <?= (int)($cm['reply_count'] ?? 0) + 1 ?></span>
                            <i class="fas fa-chevron-down toggle-icon"></i>
                        </div>
                    </div>
                    
                    <div class="admin-contact-body">
                        <div class="admin-contact-reply-item" style="margin-left:0;">
                            <div class="admin-contact-reply-header">
                                <span><i class="fas fa-user"></i> <?= e($cm['name']) ?></span>
                                <span><?= date('Y-m-d H:i', strtotime($cm['created_at'])) ?></span>
                            </div>
                            <div class="admin-contact-reply-text"><?= nl2br(e($cm['message'])) ?></div>
                        </div>
                        
                        <?php if (!empty($cm['replies'])): ?>
                            <div style="margin-top: 1rem;">
                                <strong><i class="fas fa-reply-all"></i> Válaszok:</strong>
                                <?php foreach ($cm['replies'] as $reply): ?>
                                    <div class="admin-contact-reply-item <?= $reply['admin_id'] ? 'admin-contact-reply-admin' : '' ?>">
                                        <div class="admin-contact-reply-header">
                                            <span>
                                                <i class="fas <?= $reply['admin_id'] ? 'fa-user-shield' : 'fa-user' ?>"></i>
                                                <?= $reply['admin_id'] ? e($reply['admin_name'] ?? 'Admin') . ' (Admin)' : ($reply['admin_name'] ?? 'Felhasználó') ?>
                                            </span>
                                            <span><?= date('Y-m-d H:i', strtotime($reply['created_at'])) ?></span>
                                        </div>
                                        <div class="admin-contact-reply-text"><?= nl2br(e($reply['reply_text'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Admin válasz űrlap -->
                    <div class="admin-reply-form">
                        <form method="POST" onsubmit="return validateAdminReply(this)">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="reply_contact">
                            <input type="hidden" name="message_id" value="<?= $cm['id'] ?>">
                            <textarea name="reply_text" rows="2" placeholder="Válasz írása ennek az üzenetnek..."></textarea>
                            <button type="submit"><i class="fas fa-paper-plane"></i> Válasz küldése</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>


        </main>
    </div>

    <style>
    .admin-tab { display: none; }
    .admin-tab.active { display: block; }
    .admin-menu a.active { background: rgba(255,255,255,0.1); border-left: 4px solid #3498db; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 1rem; margin-bottom: 2rem; }
    .stat-card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: var(--shadow); display: flex; align-items: center; gap: 1rem; }
    .dark-theme .stat-card { background: #1e293b; }
    .stat-icon { width: 60px; height: 60px; background: linear-gradient(135deg, #3498db, #0ea5e9); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem; }
    .admin-form { background: white; padding: 2rem; border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 2rem; }
    .dark-theme .admin-form { background: #1e293b; }
    .admin-form h2 { margin-bottom: 1.5rem; color: var(--primary-dark); }
    .dark-theme .admin-form h2 { color: var(--text-light); }
    .status-pending { background: #fef3c7; color: #92400e; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.3rem; }
    .status-confirmed { background: #d1fae5; color: #065f46; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.3rem; }
    .status-completed { background: #dbeafe; color: #1e40af; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.3rem; }
    .status-cancelled { background: #fee2e2; color: #991b1b; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.3rem; }
    .dark-theme .status-pending { background: #451a03; color: #fcd34d; }
    .dark-theme .status-confirmed { background: #064e3b; color: #6ee7b7; }
    .dark-theme .status-completed { background: #1e3a5f; color: #93c5fd; }
    .dark-theme .status-cancelled { background: #450a0a; color: #fca5a5; }
    .btn-logout { background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 0.4rem 1rem; border-radius: 8px; cursor: pointer; transition: background 0.2s; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 0.3rem; }
    .btn-logout:hover { background: rgba(255,255,255,0.25); text-decoration: none; }
    .action-cell { white-space: nowrap; }
    .btn-action { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.7rem; border-radius: 6px; border: none; cursor: pointer; font-size: 0.82rem; font-weight: 600; transition: opacity 0.2s; }
    .btn-edit { background: #dbeafe; color: #1e40af; }
    .btn-delete { background: #fee2e2; color: #991b1b; }
    .btn-approve { background: #d1fae5; color: #065f46; }
    .btn-complete { background: #ede9fe; color: #5b21b6; }
    .dark-theme .btn-edit { background: #1e3a5f; color: #93c5fd; }
    .dark-theme .btn-delete { background: #450a0a; color: #fca5a5; }
    .dark-theme .btn-approve { background: #064e3b; color: #6ee7b7; }
    .dark-theme .btn-complete { background: #2e1065; color: #c4b5fd; }
    .btn-action:hover { opacity: 0.8; }
    .table-responsive { overflow-x: auto; }
    .priority-badge { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.3rem; }
    .priority-normal { background: #e8f5e9; color: #2e7d32; }
    .priority-high { background: #fff3e0; color: #f57c00; }
    .priority-urgent { background: #ffebee; color: #d32f2f; }
    .dark-theme .priority-normal { background: #1e3a2f; color: #86efac; }
    .dark-theme .priority-high { background: #422c1a; color: #fdba74; }
    .dark-theme .priority-urgent { background: #3b1c1c; color: #fca5a5; }
    .badge { display: inline-flex; align-items: center; justify-content: center; font-weight: 600; }
    
    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .modal-box { background: white; border-radius: 16px; box-shadow: var(--shadow-lg); width: 100%; max-width: 680px; max-height: 90vh; overflow-y: auto; }
    .dark-theme .modal-box { background: #1e293b; border: 1px solid #334155; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--medium-gray); }
    .modal-header h2 { color: var(--primary-dark); font-size: 1.2rem; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .dark-theme .modal-header h2 { color: var(--text-light); }
    .modal-close { background: none; border: none; font-size: 1.6rem; cursor: pointer; color: var(--dark-gray); line-height: 1; padding: 0 0.25rem; }
    .modal-close:hover { color: var(--primary-dark); }
    #editForm { padding: 1.5rem; }
    .modal-footer { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--medium-gray); }
    .checkbox-label { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; }
    </style>

    <script>
    // Tab váltás
    document.querySelectorAll('.admin-menu a').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('.admin-menu a').forEach(l => l.classList.remove('active'));
            this.classList.add('active');
            document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
            document.getElementById(this.dataset.tab).classList.add('active');
        });
    });

    // Charts
    const bookingStatusCtx = document.getElementById('bookingStatusChart');
    if (bookingStatusCtx) {
        new Chart(bookingStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Függőben', 'Elfogadva', 'Teljesítve', 'Lemondva'],
                datasets: [{
                    data: [
                        <?= count(array_filter($bookings, fn($b) => ($b['status']??'') === 'pending')) ?>,
                        <?= count(array_filter($bookings, fn($b) => ($b['status']??'') === 'confirmed')) ?>,
                        <?= count(array_filter($bookings, fn($b) => ($b['status']??'') === 'completed')) ?>,
                        <?= count(array_filter($bookings, fn($b) => ($b['status']??'') === 'cancelled')) ?>
                    ],
                    backgroundColor: ['#f59e0b','#3498db','#10b981','#ef4444'],
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }

    const overviewCtx = document.getElementById('overviewChart');
    if (overviewCtx) {
        new Chart(overviewCtx, {
            type: 'bar',
            data: {
                labels: ['Foglalások', 'Értékelések', 'Referenciák', 'Felhasználók'],
                datasets: [{
                    label: 'Összesen',
                    data: [<?= $bookings_total ?>, <?= count($reviews) ?>, <?= count($references) ?>, <?= $users_total ?>],
                    backgroundColor: ['#3498db','#f59e0b','#10b981','#8b5cf6'],
                    borderRadius: 8,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    // Restore active tab from URL hash on page load
    const hash = window.location.hash.replace('#', '');
    if (hash) {
        const tabEl = document.getElementById(hash);
        const linkEl = document.querySelector(`.admin-menu a[data-tab="${hash}"]`);
        if (tabEl && linkEl) {
            document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.admin-menu a').forEach(l => l.classList.remove('active'));
            tabEl.classList.add('active');
            linkEl.classList.add('active');
        }
    }

    // Kijelentkezés
    document.getElementById('logoutBtn')?.addEventListener('click', () => {
        window.location.href = 'logout.php';
    });

    // Felhasználó törlés modal
    function openDeleteUserModal(id, name) {
        document.getElementById('deleteUserId').value = id;
        document.getElementById('deleteUserName').textContent = name;
        document.getElementById('deleteUserModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeDeleteUserModal() {
        document.getElementById('deleteUserModal').style.display = 'none';
        document.body.style.overflow = '';
    }

    // Edit modal
    function openEditModal(s) {
        document.getElementById('edit_id').value           = s.id           ?? '';
        document.getElementById('edit_name').value         = s.name         ?? '';
        document.getElementById('edit_category').value     = s.category     ?? 'vízszerelés';
        document.getElementById('edit_price').value        = s.price_range  ?? '';
        document.getElementById('edit_duration').value     = s.estimated_duration ?? '';
        document.getElementById('edit_priority').value     = s.priority     ?? 'normal';
        document.getElementById('edit_icon').value         = s.icon         ?? 'fa-tools';
        document.getElementById('edit_order').value        = s.display_order ?? 0;
        document.getElementById('edit_description').value  = s.description  ?? '';
        document.getElementById('edit_active').checked     = s.active == 1;
        document.getElementById('editModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
        document.body.style.overflow = '';
    }

    document.getElementById('editModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeEditModal();
    });
    
    // Contact thread toggle
    function toggleContactThread(thread) {
        thread.classList.toggle('contact-collapsed');
    }

    function validateAdminReply(form) {
        const textarea = form.querySelector('textarea[name="reply_text"]');
        if (!textarea.value.trim()) {
            alert('Kérjük, írja be a válaszát!');
            textarea.focus();
            return false;
        }
        return confirm('Biztosan elküldi ezt a választ? A felhasználó értesítést kap.');
    }

    // Alapértelmezetten csak a legújabb 3 üzenet legyen kinyitva
    document.querySelectorAll('.admin-contact-thread').forEach((thread, index) => {
        if (index >= 3) {
            thread.classList.add('contact-collapsed');
        }
    });
    </script>

    <!-- FELHASZNÁLÓ TÖRLÉS MODAL -->
    <div id="deleteUserModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--bg-surface);border-radius:16px;padding:2rem;max-width:480px;width:90%;position:relative;">
            <h2 style="margin-bottom:0.5rem;color:var(--text-primary);"><i class="fas fa-trash"></i> Felhasználó törlése</h2>
            <p style="color:var(--text-secondary);margin-bottom:1.5rem;">
                Biztosan törli <strong id="deleteUserName"></strong> fiókját? A felhasználó e-mailben értesítve lesz.
            </p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId">
                <div class="form-group" style="margin-bottom:1rem;">
                    <label>Törlés oka (kötelező)</label>
                    <select name="delete_reason" id="deleteReasonSelect" required style="width:100%;padding:0.6rem;border-radius:8px;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);" onchange="document.getElementById('customReasonBox').style.display=this.value==='Egyéb'?'block':'none'">
                        <option value="">Válasszon okot...</option>
                        <option value="Szabálysértés">Szabálysértés</option>
                        <option value="Visszaélés">Visszaélés</option>
                        <option value="Kérésre törölve">Kérésre törölve</option>
                        <option value="Inaktív fiók">Inaktív fiók</option>
                        <option value="Spam tevékenység">Spam tevékenység</option>
                        <option value="Egyéb">Egyéb...</option>
                    </select>
                    <div id="customReasonBox" style="display:none;margin-top:0.5rem;">
                        <input type="text" name="delete_reason_custom" placeholder="Írja le a pontos okot..." style="width:100%;padding:0.6rem;border-radius:8px;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);">
                    </div>
                </div>
                <div style="display:flex;gap:1rem;justify-content:flex-end;">
                    <button type="button" onclick="closeDeleteUserModal()" class="btn btn-secondary">Mégse</button>
                    <button type="submit" class="btn btn-primary" style="background:#ef4444;border-color:#ef4444;">
                        <i class="fas fa-trash"></i> Törlés és értesítés
                    </button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>