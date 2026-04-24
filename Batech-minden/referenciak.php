<?php
session_start();
require_once 'config.php';

$page_title = "Referenciák | BaTech.hu";

$logged_in = bejelentkezve();
$user_name = $logged_in ? $_SESSION['user_name'] : '';
$is_admin  = admin_e();

$references = [];
$conn = db();

if ($conn) {
    try {
        $result = $conn->query(
            "SELECT r.*, GROUP_CONCAT(ri.image_url ORDER BY ri.sort_order SEPARATOR '|') as images
             FROM `references` r
             LEFT JOIN `reference_images` ri ON ri.reference_id = r.id
             WHERE r.approved = 1
             GROUP BY r.id
             ORDER BY r.created_at DESC"
        );
        foreach ($result->fetchAll() as $row) {
            $images = !empty($row['images']) ? explode('|', $row['images']) : [];
            $references[] = [
                'id'          => $row['id'],
                'client_name' => e($row['title']),
                'description' => e($row['description']),
                'image_url'   => !empty($images) ? $images[0] : e($row['image_url'] ?? ''),
                'images'      => $images,
                'category'    => e($row['category']),
                'date'        => $row['date_completed'],
                'duration'    => e($row['duration']),
                'price'       => e($row['price']),
                'created_at'  => $row['created_at'],
            ];
        }
    } catch (PDOException $e) {}
}

$categories = [];
foreach ($references as $ref) {
    if (!empty($ref['category']) && !in_array($ref['category'], $categories)) {
        $categories[] = $ref['category'];
    }
}

$cat_names = [
    'vízszerelés' => 'Vízszerelés',
    'gázerősítés' => 'Gázerősítés',
    'teljes'      => 'Teljes felújítás',
    'sürgősségi'  => 'Sürgősségi',
    'egyéb'       => 'Egyéb',
];

$csrf_token = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        uzenet('error', 'Érvénytelen kérés!');
        atiranyit('referenciak.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_reference' && $is_admin) {
        $id = (int)($_POST['reference_id'] ?? 0);
        if ($id > 0) {
            $conn->prepare("DELETE FROM `reference_images` WHERE reference_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM `references` WHERE id = ?")->execute([$id]);
            uzenet('success', 'Referencia törölve!');
        }
        atiranyit('referenciak.php');
    }

    $client   = trim($_POST['ref_client_name'] ?? '');
    $category = trim($_POST['ref_category'] ?? '');
    $desc     = trim($_POST['ref_description'] ?? '');
    $duration = trim($_POST['ref_duration'] ?? '');
    $price    = trim($_POST['ref_price'] ?? '');

    if (empty($client) || empty($desc)) {
        uzenet('error', 'A cím és a leírás kötelező!');
        atiranyit('referenciak.php');
    }

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
            $fallback     = $target_dir . 'ref_' . time() . '_' . $i . '.' . $ext;
            if (class_exists('\Tinify\Source')) {
                try {
                    \Tinify\fromFile($files['tmp_name'][$i])->convert(["type" => "image/webp"])->toFile($target_file);
                    $image_urls[] = $target_file;
                } catch (Exception $e) {
                    error_log("Tinify error: " . $e->getMessage());
                    move_uploaded_file($files['tmp_name'][$i], $fallback);
                    $image_urls[] = $fallback;
                }
            } else {
                move_uploaded_file($files['tmp_name'][$i], $fallback);
                $image_urls[] = $fallback;
            }
        }
    }

    $first_image = $image_urls[0] ?? '';
    $approved    = $is_admin ? 1 : 0;

    if (empty($image_urls)) {
        uzenet('error', 'Legalább egy kép feltöltése kötelező!');
        atiranyit('referenciak.php');
    }

    $stmt = $conn->prepare("INSERT INTO `references` (user_id, title, category, description, duration, price, image_url, approved, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$_SESSION['user_id'], $client, $category, $desc, $duration, $price, $first_image, $approved]);
    $ref_id = $conn->lastInsertId();

    if (!empty($image_urls)) {
        $img_stmt = $conn->prepare("INSERT INTO `reference_images` (reference_id, image_url, sort_order) VALUES (?, ?, ?)");
        foreach ($image_urls as $order => $url) {
            $img_stmt->execute([$ref_id, $url, $order]);
        }
    }

    uzenet('success', $is_admin
        ? count($image_urls) . ' képpel referencia hozzáadva!'
        : 'Referencia sikeresen beküldve! ⏳ Jóváhagyásra vár — amint az admin jóváhagyja, megjelenik az oldalon.');
    atiranyit('referenciak.php');
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
    <?php $active_page = 'referenciak'; include 'includes/navbar.php'; ?>

    <main class="main-content">
        <div class="container">
            <div class="page-header">
                <h1 class="page-title">Referenciáink</h1>
                <p class="page-subtitle">Tekintse meg korábbi munkáinkat és elégedett ügyfeleink tapasztalatait</p>
            </div>

            <?php foreach ($messages as $msg): ?>
                <div class="message <?= $msg['type'] ?>"><?= e($msg['text']) ?></div>
            <?php endforeach; ?>

            <?php if ($logged_in): ?>
            <div class="admin-notice" style="display:flex;align-items:center;justify-content:space-between;">
                <?php if ($is_admin): ?>
                    <p><i class="fas fa-shield-alt"></i> Admin mód — referenciák kezelése</p>
                <?php else: ?>
                    <p><i class="fas fa-images"></i> Ossza meg saját tapasztalatát!</p>
                <?php endif; ?>
                <button class="btn btn-primary" onclick="document.getElementById('addRefForm').style.display=document.getElementById('addRefForm').style.display==='none'?'block':'none'">
                    <i class="fas fa-plus"></i> Referencia készítése
                </button>
            </div>

            <div id="addRefForm" style="display:none;background:var(--bg-surface);border-radius:16px;padding:2rem;margin-bottom:2rem;box-shadow:var(--shadow);">
                <h2 style="margin-bottom:1.5rem;"><i class="fas fa-plus-circle"></i> Új referencia
                    <?php if (!$is_admin): ?>
                        <small style="font-size:0.75rem;color:var(--text-secondary);font-weight:400;"> — jóváhagyás szükséges</small>
                    <?php endif; ?>
                </h2>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Referencia címe *</label>
                            <input type="text" name="ref_client_name" placeholder="Pl. Fürdőszoba felújítás" required>
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
                            <label>Képek (max. 10)</label>
                            <div id="imageInputs">
                                <div class="image-input-row" style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.5rem;">
                                    <input type="file" name="ref_images[]" accept="image/*" style="flex:1;" required>
                                    <button type="button" onclick="removeImageInput(this)" style="background:#ef4444;color:white;border:none;border-radius:8px;padding:0.4rem 0.75rem;cursor:pointer;">✕</button>
                                </div>
                            </div>
                            <button type="button" onclick="addImageInput()" id="addImageBtn" style="margin-top:0.5rem;background:none;border:2px dashed var(--border-color);border-radius:8px;padding:0.5rem 1rem;cursor:pointer;color:var(--text-secondary);width:100%;">
                                <i class="fas fa-plus"></i> Kép hozzáadása
                            </button>
                            <small class="form-hint">JPG, PNG — automatikusan WebP-re konvertálva. Max 10 kép.</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Leírás *</label>
                        <textarea name="ref_description" rows="3" placeholder="Munka leírása..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Hozzáadás</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!empty($references)): ?>
            <div class="references-filter">
                <div class="filter-options">
                    <button class="filter-btn active" data-filter="all">Összes</button>
                    <?php foreach ($categories as $cat): ?>
                    <button class="filter-btn" data-filter="<?= e($cat) ?>"><?= e($cat_names[$cat] ?? $cat) ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="search-container">
                    <i class="fas fa-search"></i>
                    <input type="text" class="search-input" placeholder="Keresés..." id="referenceSearch">
                </div>
            </div>

            <div class="references-grid" id="referencesGrid">
                <?php foreach ($references as $ref): ?>
                <?php $modal_data = htmlspecialchars(json_encode([
                    'title'       => $ref['client_name'],
                    'description' => $ref['description'],
                    'category'    => $cat_names[$ref['category']] ?? $ref['category'],
                    'duration'    => $ref['duration'],
                    'price'       => $ref['price'],
                    'date'        => $ref['date'] ? date('Y. F j.', strtotime($ref['date'])) : date('Y. F j.', strtotime($ref['created_at'])),
                    'images'      => $ref['images'],
                    'image_url'   => $ref['image_url'],
                ]), ENT_QUOTES); ?>
                <div class="reference-card" data-category="<?= e($ref['category']) ?>" data-id="<?= $ref['id'] ?>"
                     onclick="openRefModal(<?= $modal_data ?>)" style="cursor:pointer">
                    <?php if (!empty($ref['image_url']) && $ref['image_url'] !== 'assets/img/default-reference.jpg'): ?>
                    <div class="reference-image">
                        <img src="<?= e($ref['image_url']) ?>" alt="<?= e($ref['client_name']) ?>">
                        <div class="reference-overlay">
                            <span class="reference-category"><?= e($cat_names[$ref['category']] ?? $ref['category']) ?></span>
                            <span class="reference-date">
                                <?= $ref['date'] ? date('Y. F', strtotime($ref['date'])) : date('Y. F', strtotime($ref['created_at'])) ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="reference-info">
                        <h3><?= e($ref['client_name']) ?></h3>
                        <p class="reference-description"><?= e($ref['description']) ?></p>
                        <?php if (!empty($ref['duration']) || !empty($ref['price'])): ?>
                        <div class="reference-details">
                            <?php if (!empty($ref['duration'])): ?>
                            <span><i class="fas fa-clock"></i> <?= e($ref['duration']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ref['price'])): ?>
                            <span><i class="fas fa-euro-sign"></i> <?= e($ref['price']) ?> Ft</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                        <div class="reference-admin-actions">
                            <button class="btn-action btn-delete" onclick="event.stopPropagation();deleteReference(<?= $ref['id'] ?>)">
                                <i class="fas fa-trash"></i> Törlés
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else: ?>
            <div class="no-references">
                <i class="fas fa-images" style="font-size:4rem;color:var(--dark-gray);"></i>
                <h2>Még nincsenek referenciák</h2>
                <p>Kérjük, nézz vissza később!</p>
            </div>
            <?php endif; ?>

            <div class="references-cta">
                <h2>Itt tudsz időpontot foglalni!</h2>
                <p>Vegye fel velünk a kapcsolatot egy ingyenes helyszíni felmérésért.</p>
                <?php if ($logged_in): ?>
                <a href="index.php#booking" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Időpontfoglalás</a>
                <?php else: ?>
                <a href="login.php" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Bejelentkezés foglaláshoz</a>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>

    <!-- REFERENCIA MODAL -->
    <div id="refModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;overflow-y:auto;padding:2rem 1rem;" onclick="if(event.target===this)closeRefModal()">
        <div style="background:var(--bg-surface);border-radius:16px;max-width:800px;margin:auto;overflow:hidden;position:relative;">
            <button onclick="closeRefModal()" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--text-primary);z-index:1;">&times;</button>
            <div id="modalGallery" style="background:#000;position:relative;"></div>
            <div style="padding:2rem;">
                <h2 id="modalTitle" style="margin-bottom:0.5rem;color:var(--text-primary);"></h2>
                <span id="modalCategory" style="background:var(--accent-blue);color:white;padding:0.2rem 0.75rem;border-radius:20px;font-size:0.8rem;"></span>
                <p id="modalDescription" style="margin-top:1.25rem;color:var(--text-secondary);line-height:1.75;"></p>
                <div id="modalMeta" style="display:flex;gap:1.5rem;margin-top:1rem;flex-wrap:wrap;color:var(--text-secondary);"></div>
                <p id="modalDate" style="margin-top:0.75rem;font-size:0.85rem;color:var(--text-muted);"></p>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <form id="deleteRefForm" method="POST" style="display:none">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="action" value="delete_reference">
        <input type="hidden" name="reference_id" id="deleteRefId">
    </form>
    <?php endif; ?>

    <script src="main.js"></script>
    <script>
    // Add/remove image inputs
    function addImageInput() {
        const container = document.getElementById('imageInputs');
        if (container.children.length >= 10) {
            alert('Maximum 10 képet lehet hozzáadni.');
            return;
        }
        const row = document.createElement('div');
        row.className = 'image-input-row';
        row.style.cssText = 'display:flex;gap:0.5rem;align-items:center;margin-bottom:0.5rem;';
        row.innerHTML = `
            <input type="file" name="ref_images[]" accept="image/*" style="flex:1;">
            <button type="button" onclick="removeImageInput(this)" style="background:#ef4444;color:white;border:none;border-radius:8px;padding:0.4rem 0.75rem;cursor:pointer;">✕</button>
        `;
        container.appendChild(row);
        if (container.children.length >= 10) {
            document.getElementById('addImageBtn').style.display = 'none';
        }
    }

    function removeImageInput(btn) {
        const container = document.getElementById('imageInputs');
        if (container.children.length > 1) {
            btn.parentElement.remove();
            document.getElementById('addImageBtn').style.display = '';
        }
    }

    // Modal
    function openRefModal(data) {
        document.getElementById('modalTitle').textContent       = data.title;
        document.getElementById('modalCategory').textContent    = data.category;
        document.getElementById('modalDescription').textContent = data.description;
        document.getElementById('modalDate').textContent        = data.date ? '📅 ' + data.date : '';

        let meta = '';
        if (data.duration) meta += `<span><i class="fas fa-clock"></i> ${data.duration}</span>`;
        if (data.price)    meta += `<span><i class="fas fa-euro-sign"></i> ${data.price} Ft</span>`;
        document.getElementById('modalMeta').innerHTML = meta;

        const gallery = document.getElementById('modalGallery');
        const imgs = (data.images && data.images.length) ? data.images : (data.image_url ? [data.image_url] : []);

        if (imgs.length) {
            let current = 0;
            const render = () => {
                gallery.innerHTML = `
                    <img src="${imgs[current]}" style="width:100%;max-height:420px;object-fit:cover;display:block;">
                    ${imgs.length > 1 ? `
                        <button onclick="event.stopPropagation();changeImg(-1)" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);background:rgba(0,0,0,0.5);color:white;border:none;border-radius:50%;width:2.5rem;height:2.5rem;font-size:1.2rem;cursor:pointer;">&#8249;</button>
                        <button onclick="event.stopPropagation();changeImg(1)"  style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:rgba(0,0,0,0.5);color:white;border:none;border-radius:50%;width:2.5rem;height:2.5rem;font-size:1.2rem;cursor:pointer;">&#8250;</button>
                        <div style="position:absolute;bottom:0.5rem;width:100%;text-align:center;color:white;font-size:0.85rem;">${current+1} / ${imgs.length}</div>
                    ` : ''}
                `;
            };
            window.changeImg = (dir) => { current = (current + dir + imgs.length) % imgs.length; render(); };
            render();
            gallery.style.display = 'block';
        } else {
            gallery.style.display = 'none';
        }

        document.getElementById('refModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeRefModal() {
        document.getElementById('refModal').style.display = 'none';
        document.body.style.overflow = '';
    }

    // Search
    const searchInput = document.getElementById('referenceSearch');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const term = this.value.toLowerCase();
            document.querySelectorAll('.reference-card').forEach(card => {
                card.style.display = card.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    }

    // Filter
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const filter = this.dataset.filter;
            document.querySelectorAll('.reference-card').forEach(card => {
                card.style.display = (filter === 'all' || card.dataset.category === filter) ? '' : 'none';
            });
        });
    });

    <?php if ($is_admin): ?>
    function deleteReference(id) {
        if (confirm('Biztosan törölni szeretné ezt a referenciát?')) {
            document.getElementById('deleteRefId').value = id;
            document.getElementById('deleteRefForm').submit();
        }
    }
    <?php endif; ?>
    </script>
</body>
</html>
