<?php
/**
 * Admin Panel - Header Component
 * Portal Örnek Yazılım
 */

// $user değişkeni ana sayfada tanımlanmalı
if (!isset($user)) {
    die('Header component requires $user variable to be set.');
}

// Bildirim yetkisi kontrolü
$hasNotificationAccess = false;
if (isset($user['kullanici_id']) && isset($user['departman_id'])) {
    require_once __DIR__ . '/PageAuth.php';
    $notificationPermissions = PageAuth::checkPagePermissions(
        $user['kullanici_id'],
        $user['departman_id'],
        'personel-yonetimi.php'
    );
    $hasNotificationAccess = $notificationPermissions['has_access'];
}

// Header menüleri — sadece departman_id=1 (Administrator) kullanıcılara göster
$headerMenuler = [];
$isAdmin = isset($user['departman_id']) && (int)$user['departman_id'] === 1;
if ($isAdmin) {
    try {
        if (!isset($db)) $db = Database::getInstance();
        $menus = $db->fetchAll("
            SELECT menuler_id, menuler_menu_adi, menuler_ikon
            FROM Menuler
            WHERE menuler_header_goster = 1 AND menuler_durum = 1
            ORDER BY menuler_sira_no ASC
        ");
        foreach ($menus as $m) {
            $hdrSayfalar = $db->fetchAll("
                SELECT sayfalar_sayfa_adi, sayfalar_sayfa_url, sayfalar_ikon
                FROM Menu_Sayfalar
                WHERE sayfalar_menu_id = ? AND sayfalar_durum = 1
                ORDER BY sayfalar_sira_no ASC
            ", [$m['menuler_id']]);
            $m['sayfalar'] = $hdrSayfalar;
            $headerMenuler[] = $m;
        }
    } catch (Exception $e) {
        $headerMenuler = [];
    }
}

// Impersonation durumu
$isImpersonating = Auth::isImpersonating();
$impersonator    = Auth::impersonator();
?>
<!--begin::Header-->
<nav class="app-header navbar navbar-expand bg-body">
    <!--begin::Container-->
    <div class="container-fluid">
        <!--begin::Start Navbar Links-->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                    <i class="bi bi-list"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-block">
                <a href="/admin/anasayfa" class="nav-link">Ana Sayfa</a>
            </li>
            <?php foreach ($headerMenuler as $hm): ?>
            <?php if (!empty($hm['sayfalar'])): ?>
            <li class="nav-item d-none d-md-block dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <?php if (!empty($hm['menuler_ikon'])): ?>
                    <i class="<?= htmlspecialchars($hm['menuler_ikon']) ?> me-1"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($hm['menuler_menu_adi']) ?>
                </a>
                <ul class="dropdown-menu">
                    <?php foreach ($hm['sayfalar'] as $sp):
                        $hdrCleanUrl = str_replace('.php', '', $sp['sayfalar_sayfa_url']);
                        $hdrCleanUrl = preg_replace('/^pages\//', '', $hdrCleanUrl);
                    ?>
                    <li>
                        <a class="dropdown-item" href="/admin/<?= htmlspecialchars($hdrCleanUrl) ?>">
                            <?php if (!empty($sp['sayfalar_ikon'])): ?>
                            <i class="<?= htmlspecialchars($sp['sayfalar_ikon']) ?> me-1"></i>
                            <?php endif; ?>
                            <?= htmlspecialchars($sp['sayfalar_sayfa_adi']) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php else: ?>
            <li class="nav-item d-none d-md-block">
                <a href="#" class="nav-link">
                    <?php if (!empty($hm['menuler_ikon'])): ?>
                    <i class="<?= htmlspecialchars($hm['menuler_ikon']) ?> me-1"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($hm['menuler_menu_adi']) ?>
                </a>
            </li>
            <?php endif; ?>
            <?php endforeach; ?>
        </ul>
        <!--end::Start Navbar Links-->
        
        <!--begin::End Navbar Links-->
        <ul class="navbar-nav ms-auto">
            <!--begin::Destek Talepleri Dropdown-->
            <li class="nav-item dropdown">
                <a class="nav-link destek-header-btn" data-bs-toggle="dropdown" href="#" id="destekDropdown" role="button">
                    <i class="bi bi-headset me-1"></i>
                    <span class="d-none d-md-inline">Destek</span>
                    <span class="destek-badge" id="destekBadge" style="display: none;">0</span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" id="destekMenu">
                    <span class="dropdown-item dropdown-header">
                        <i class="bi bi-headset me-1"></i> Destek Taleplerim
                    </span>
                    <div class="dropdown-divider"></div>
                    <div id="destekListe">
                        <div class="dropdown-item text-center text-muted py-3">Yükleniyor...</div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="/admin/pages/ticket-liste.php" class="dropdown-item dropdown-footer">
                        Tüm Taleplerim
                    </a>
                </div>
            </li>
            <!--end::Destek Talepleri Dropdown-->

            <?php if ($hasNotificationAccess): ?>
            <!--begin::Notifications Dropdown Menu-->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" id="notificationDropdown">
                    <i class="bi bi-bell"></i>
                    <span class="navbar-badge badge text-bg-warning" id="notificationBadge" style="display: none;">0</span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" id="notificationMenu">
                    <span class="dropdown-item dropdown-header" id="notificationHeader">
                        <i class="bi bi-bell"></i> Bildirimler yükleniyor...
                    </span>
                    <div class="dropdown-divider"></div>
                    <div id="notificationList">
                        <!-- Bildirimler buraya gelecek -->
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="/admin/pages/personel-yonetimi.php" class="dropdown-item dropdown-footer">
                        Tüm Bildirimleri Gör
                    </a>
                </div>
            </li>
            <!--end::Notifications Dropdown Menu-->
            <?php endif; ?>
            
            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
                <a class="nav-link" href="#" data-lte-toggle="fullscreen">
                    <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                    <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display: none"></i>
                </a>
            </li>
            <!--end::Fullscreen Toggle-->
            
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <img
                        src="/admin/assets/images/user-avatar.png"
                        class="user-image rounded-circle shadow"
                        alt="<?= htmlspecialchars($user['name']) ?>"
                        onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23667eea%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23fff%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                    />
                    <span class="d-none d-md-inline"><?= htmlspecialchars($user['name']) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <!--begin::User Image-->
                    <li class="user-header text-bg-primary">
                        <img
                            src="/admin/assets/images/user-avatar.png"
                            class="rounded-circle shadow"
                            alt="<?= htmlspecialchars($user['name']) ?>"
                            onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23ffffff%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23667eea%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                        />
                        <p>
                            <?= htmlspecialchars($user['name']) ?>
                            <small><?= htmlspecialchars($user['email']) ?></small>
                        </p>
                    </li>
                    <!--end::User Image-->
                    
                    <!--begin::Menu Body-->
                    <li class="user-body">
                        <div class="row">
                            <div class="col-12 text-center">
                                <small class="text-muted">
                                    <i class="bi bi-clock"></i> Son giriş: <?= date('d.m.Y H:i:s', $_SESSION['login_time']) ?>
                                </small>
                            </div>
                        </div>
                    </li>
                    <!--end::Menu Body-->
                    
                    <!--begin::Menu Footer-->
                    <li class="user-footer">
                        <a href="/admin/profil" class="btn btn-default btn-flat">Profil</a>
                        <a href="/admin/logout.php" class="btn btn-default btn-flat float-end">Çıkış Yap</a>
                    </li>
                    <!--end::Menu Footer-->
                    <?php if ($isImpersonating && $impersonator): ?>
                    <!--begin::Impersonate Info-->
                    <li style="background:#fff3e0; border-top:1px solid #ffe0b2; padding:8px 12px;">
                        <div style="font-size:0.78rem; color:#e65100; line-height:1.4;">
                            <i class="bi bi-person-fill-badge"></i>
                            <strong><?= htmlspecialchars($user['name']) ?></strong> olarak giriş yapılmış durumdasınız.<br>
                            <span class="text-muted">Orijinal: <strong><?= htmlspecialchars($impersonator['user_name']) ?></strong></span>
                        </div>
                        <a href="/admin/impersonate-cikis.php" class="btn btn-sm btn-warning w-100 mt-2" style="font-size:0.8rem;">
                            <i class="bi bi-box-arrow-left"></i> Kendi Hesabıma Dön
                        </a>
                    </li>
                    <!--end::Impersonate Info-->
                    <?php endif; ?>
                </ul>
            </li>
            <!--end::User Menu Dropdown-->
        </ul>
        <!--end::End Navbar Links-->
    </div>
    <!--end::Container-->
</nav>
<!--end::Header-->


