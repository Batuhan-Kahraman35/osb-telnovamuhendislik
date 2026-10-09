<?php
/**
 * Admin Panel - Şifremi Unuttum
 * Portal Örnek Yazılım
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/EntegrasyonHelper.php';

// Zaten giriş yapılmışsa ana sayfaya yönlendir
if (Auth::check()) {
    redirect('index.php');
}

$db = Database::getInstance();
$helper = new EntegrasyonHelper($db);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kimlik = trim($_POST['kimlik'] ?? '');

    if ($kimlik === '') {
        $error = 'Lütfen e-posta adresinizi veya telefon numaranızı girin!';
    } else {
        // Girdi e-posta mı telefon mu?
        $telefonRakam = preg_replace('/\D/', '', $kimlik);
        $epostaMi = filter_var($kimlik, FILTER_VALIDATE_EMAIL) !== false;

        if ($epostaMi) {
            $user = $db->fetchOne("
                SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
                FROM kullanicilar
                WHERE kullanici_email = ? AND kullanici_durum = 1
            ", [$kimlik]);
        } else {
            // Telefon sonundaki 10 hane ile eşleştir (başındaki 0 / 90 farkını yok say)
            $son10 = substr($telefonRakam, -10);
            $user = $son10 === '' ? null : $db->fetchOne("
                SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
                FROM kullanicilar
                WHERE RIGHT(REPLACE(REPLACE(REPLACE(ISNULL(kullanici_telefon, ''), ' ', ''), '-', ''), '+', ''), 10) = ?
                  AND kullanici_durum = 1
            ", [$son10]);
        }

        if ($user) {
            $token      = bin2hex(random_bytes(32));
            $tokenTarih = date('Y-m-d H:i:s', strtotime('+1 hour')); // 1 saat geçerli

            $db->execute("UPDATE kullanicilar SET kullanici_sifre_token = ?, kullanici_sifre_token_tarih = ? WHERE kullanici_id = ?", [$token, $tokenTarih, $user['kullanici_id']]);

            // Site URL'sini dinamik al veya config'den çek
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domainName = $_SERVER['HTTP_HOST'];
            $dir = dirname($_SERVER['PHP_SELF']);
            $resetLink = $protocol . $domainName . $dir . "/sifre-sifirla.php?token=" . $token;

            $adSoyad = trim($user['kullanici_ad'] . ' ' . $user['kullanici_soyad']);

            // E-posta gönderimi
            if (!empty($user['kullanici_email'])) {
                $mailIcerik = "Merhaba {$adSoyad},<br><br>";
                $mailIcerik .= "Şifrenizi sıfırlamak için aşağıdaki bağlantıya tıklayın:<br>";
                $mailIcerik .= "<a href='{$resetLink}'>{$resetLink}</a><br><br>";
                $mailIcerik .= "Bu bağlantı 1 saat boyunca geçerlidir.<br>";
                $mailIcerik .= "Eğer bu isteği siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz.";

                try {
                    $helper->mailGonder($user['kullanici_email'], "Şifre Sıfırlama İsteği", $mailIcerik);
                } catch (Exception $e) {
                    error_log('Sifre sifirlama maili gonderilemedi: ' . $e->getMessage());
                }
            }

            // WhatsApp gönderimi
            $kullaniciTelefon = preg_replace('/\D/', '', $user['kullanici_telefon'] ?? '');
            if (strlen($kullaniciTelefon) >= 10) {
                $waNumara = '90' . substr($kullaniciTelefon, -10);
                $waMesaj  = "Merhaba {$adSoyad},\n\n";
                $waMesaj .= "Şifrenizi sıfırlamak için aşağıdaki bağlantıyı kullanın:\n";
                $waMesaj .= "{$resetLink}\n\n";
                $waMesaj .= "Bu bağlantı 1 saat boyunca geçerlidir.\n";
                $waMesaj .= "Eğer bu isteği siz yapmadıysanız bu mesajı dikkate almayın.";

                try {
                    $helper->whatsappGonder($waNumara, $waMesaj);
                } catch (Exception $e) {
                    error_log('Sifre sifirlama WhatsApp mesaji gonderilemedi: ' . $e->getMessage());
                }
            }
        }

        // Güvenlik: kullanıcı bulunsun ya da bulunmasın aynı mesaj gösterilir.
        $success = 'Kayıtlı bir hesap bulunduysa şifre sıfırlama bağlantısı e-posta ve/veya WhatsApp ile gönderildi.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifremi Unuttum - <?= $siteTitle ?></title>
    
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
                <p class="login-box-msg">Şifrenizi mi unuttunuz? Buradan yeni bir şifre talep edebilirsiniz.</p>
                
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
                
                <form method="POST" action="">
                    <div class="input-group mb-1">
                        <input
                            type="text"
                            class="form-control"
                            name="kimlik"
                            placeholder="E-posta veya Telefon"
                            required
                            value="<?= htmlspecialchars($_POST['kimlik'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-envelope-at"></span>
                        </div>
                    </div>
                    <p class="text-muted mb-3">
                        <small>Bağlantı e-posta ve kayıtlı telefonunuza WhatsApp ile gönderilir.</small>
                    </p>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Yeni Şifre İste</button>
                            </div>
                        </div>
                    </div>
                </form>
                
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
