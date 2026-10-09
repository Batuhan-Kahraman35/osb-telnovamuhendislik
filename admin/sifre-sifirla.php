<?php
/**
 * Admin Panel - Şifre Sıfırlama
 * Portal Örnek Yazılım
 */

require_once __DIR__ . '/auth.php';

// Zaten giriş yapılmışsa ana sayfaya yönlendir
if (Auth::check()) {
    redirect('index.php');
}

$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title 
    FROM dbo.tanim_site_ayarlari 
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
$siteTitle = 'Örnek Yazılım Portal';

if ($siteAyarlari) {
    if (!empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
        $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
    }
    if (!empty($siteAyarlari['site_ayarlari_site_title'])) {
        $siteTitle = htmlspecialchars($siteAyarlari['site_ayarlari_site_title']);
    }
}

$error = '';
$success = '';
$tokenGeçerli = false;

$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Geçersiz bağlantı.';
} else {
    // Token kontrolü
    $user = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_sifre_token = ? AND kullanici_sifre_token_tarih >= GETDATE() AND kullanici_durum = 1", [$token]);
    
    if ($user) {
        $tokenGeçerli = true;
    } else {
        $error = 'Şifre sıfırlama bağlantısının süresi dolmuş veya geçersiz.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenGeçerli) {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    if (empty($password) || empty($passwordConfirm)) {
        $error = 'Lütfen tüm alanları doldurun.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Şifreler eşleşmiyor.';
    } elseif (strlen($password) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Şifreyi güncelle ve token'ı temizle
        $db->execute("UPDATE kullanicilar SET kullanici_sifre_hash = ?, kullanici_sifre_token = NULL, kullanici_sifre_token_tarih = NULL WHERE kullanici_id = ?", [$hashedPassword, $user['kullanici_id']]);
        
        $success = 'Şifreniz başarıyla güncellendi. Giriş yapabilirsiniz.';
        $tokenGeçerli = false; // Formu gizlemek için
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifre Sıfırla - <?= $siteTitle ?></title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    
    <!-- AdminLTE -->
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
</head>
<body class="login-page bg-body-secondary">
    <div class="login-box">
        <div class="login-logo">
            <a href="index.php"><b><?= $siteTitle ?></b></a>
        </div>
        
        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">Yeni şifrenizi belirleyin.</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($tokenGeçerli): ?>
                <form method="POST" action="">
                    <div class="input-group mb-3">
                        <input 
                            type="password" 
                            class="form-control" 
                            name="password"
                            placeholder="Yeni Şifre" 
                            required
                        >
                        <div class="input-group-text">
                            <span class="bi bi-lock-fill"></span>
                        </div>
                    </div>
                    
                    <div class="input-group mb-3">
                        <input 
                            type="password" 
                            class="form-control" 
                            name="password_confirm"
                            placeholder="Yeni Şifre (Tekrar)" 
                            required
                        >
                        <div class="input-group-text">
                            <span class="bi bi-lock-fill"></span>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Şifreyi Değiştir</button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
                
                <p class="mt-3 mb-0 text-center">
                    <a href="login.php" class="text-center">Giriş sayfasına dön</a>
                </p>
                
                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= $footerYazi ?></small>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- AdminLTE -->
    <script src="assets/js/adminlte.min.js"></script>
</body>
</html>
