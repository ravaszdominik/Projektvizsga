<nav class="navbar">
    <div class="container">
        <a href="index.php" class="logo"><i class="fas fa-tools"></i> BaTech<span>.</span></a>
        <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index.php" <?= ($active_page??'')==='index' ? 'class="active"' : '' ?>>Főoldal</a></li>
            <li><a href="arak.php" <?= ($active_page??'')==='arak' ? 'class="active"' : '' ?>>Szolgáltatások & Árak</a></li>
            <li><a href="referenciak.php" <?= ($active_page??'')==='referenciak' ? 'class="active"' : '' ?>>Referenciák</a></li>
            <li><a href="ertekelesek.php" <?= ($active_page??'')==='ertekelesek' ? 'class="active"' : '' ?>>Értékelések</a></li>
            <li><a href="kapcsolat.php" <?= ($active_page??'')==='kapcsolat' ? 'class="active"' : '' ?>>Kapcsolat</a></li>
            <?php if ($logged_in ?? false): ?>
                <?php if ($is_admin ?? false): ?><li><a href="admin.php" class="btn-admin">Admin</a></li><?php endif; ?>
                <li><a href="profil.php" class="btn-login"><i class="fas fa-user"></i> <?= e($user_name ?? '') ?></a></li>
                <?php $bell_count = get_unread_notifications(); ?>
                <li><a href="profil.php#notifications" class="btn-login" style="position:relative;" title="Értesítések">
                    <i class="fas fa-bell"></i>
                    <?php if ($bell_count > 0): ?><span style="position:absolute;top:-4px;right:-4px;background:#ef4444;color:white;border-radius:50%;width:18px;height:18px;font-size:0.7rem;display:flex;align-items:center;justify-content:center;font-weight:bold;"><?= $bell_count ?></span><?php endif; ?>
                </a></li>
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
