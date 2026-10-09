<?php
/**
 * Admin Panel - Hesap Oluşturma Talebi
 * Portal Örnek Yazılım
 *
 * Kullanıcı doğrudan oluşturulmaz. Form verisi Destek API'ye ticket olarak
 * iletilir; yönetici ticketı gördükten sonra personel yönetiminden kullanıcıyı
 * manuel oluşturur.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/TicketHelper.php';

// Zaten giriş yapılmışsa ana sayfaya yönlendir
if (Auth::check()) {
    redirect('index.php');
}

$db = Database::getInstance();

// Site ayarlarını veritabanından çek
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
$siteTitle  = 'Örnek Yazılım Portal';

if ($siteAyarlari) {
    if (!empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
        $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
    }
    if (!empty($siteAyarlari['site_ayarlari_site_title'])) {
        $siteTitle = htmlspecialchars($siteAyarlari['site_ayarlari_site_title']);
    }
}

// Şehirleri çek
$sehirler = $db->fetchAll("
    SELECT Sehirid, SehirAdi
    FROM Sehirler
    ORDER BY SehirAdi
");

/**
 * Tanım listesinde ada göre arama yapar, bulamazsa ilk kaydın id'sini döner.
 */
function ticketTanimIdBul(array $liste, string $aranan): int
{
    foreach ($liste as $item) {
        if (mb_stripos((string)$item['ad'], $aranan) !== false) {
            return (int)$item['id'];
        }
    }

    return (int)($liste[0]['id'] ?? 0);
}

$error   = '';
$success = '';
$ticketNo = '';

// Form gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad          = trim($_POST['ad'] ?? '');
    $soyad       = trim($_POST['soyad'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $telefon     = preg_replace('/\D/', '', $_POST['telefon'] ?? '');
    $dogumTarihi = $_POST['dogum_tarihi'] ?? '';
    $sehirId     = $_POST['sehir_id'] ?? '';
    $aciklama    = trim($_POST['aciklama'] ?? '');

    // Mükerrer gönderim koruması: form anahtarı yalnız bir kez kullanılabilir.
    // PHP session kilidi aynı oturumdaki eşzamanlı istekleri sıraya sokar;
    // ikinci istek, ilki bittikten sonra anahtarı dolu bulur.
    $formAnahtar = (string)($_POST['form_anahtar'] ?? '');
    $oncekiKayit = $_SESSION['register_form_anahtarlar'][$formAnahtar] ?? null;

    // Validasyon
    if (!preg_match('/^[a-f0-9]{32}$/', $formAnahtar)) {
        $error = 'Geçersiz form anahtarı. Sayfayı yenileyip tekrar deneyin.';
    } elseif ($oncekiKayit) {
        // Aynı form ikinci kez gönderildi: yeni talep açmadan ilk sonucu göster
        $success  = 'Talebiniz alındı. Yönetici onayından sonra giriş bilgileriniz e-posta ile iletilecektir.';
        $ticketNo = (string)($oncekiKayit['ticket_no'] ?? '');
        $_POST = [];
    } elseif ($ad === '' || $soyad === '' || $email === '') {
        $error = 'Lütfen zorunlu alanları doldurun!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçersiz e-posta adresi!';
    } else {
        // E-posta zaten kayıtlı mı?
        $mevcutKullanici = $db->fetchOne("
            SELECT kullanici_id
            FROM kullanicilar
            WHERE kullanici_email = ?
        ", [$email]);

        if ($mevcutKullanici) {
            $error = 'Bu e-posta adresi zaten kullanılmaktadır!';
        } else {
            try {
                $kategoriler = $db->fetchAll("
                    SELECT Ticket_Kategoriler_id AS id, Ticket_Kategoriler_ad AS ad
                    FROM dbo.Ticket_Kategoriler
                    WHERE Durum = 1
                    ORDER BY Ticket_Kategoriler_sira, Ticket_Kategoriler_id
                ");
                $oncelikler = $db->fetchAll("
                    SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad
                    FROM dbo.Ticket_Oncelikler
                    WHERE Durum = 1
                    ORDER BY Ticket_Oncelikler_sira, Ticket_Oncelikler_id
                ");

                if (empty($kategoriler) || empty($oncelikler)) {
                    throw new Exception('Ticket kategori/öncelik tanımları bulunamadı.');
                }

                $kategoriId = ticketTanimIdBul($kategoriler, 'Diğer');
                $oncelikId  = ticketTanimIdBul($oncelikler, 'Normal');

                // Şehir adını çöz
                $sehirAdi = '';
                if ($sehirId !== '') {
                    $sehir = $db->fetchOne("SELECT SehirAdi FROM Sehirler WHERE Sehirid = ?", [$sehirId]);
                    $sehirAdi = $sehir['SehirAdi'] ?? '';
                }

                // Telefonu 905XXXXXXXXX formatına getir
                $telefonFormatli = '';
                if ($telefon !== '') {
                    $telefonFormatli = (strlen($telefon) === 10) ? '90' . $telefon : $telefon;
                }

                $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $personelUrl = $protocol . $_SERVER['HTTP_HOST'] . '/admin/pages/personel-yonetimi.php';

                $mesaj  = "Ad Soyad     : {$ad} {$soyad}\n";
                $mesaj .= "E-posta      : {$email}\n";
                $mesaj .= "Telefon      : " . ($telefonFormatli ?: '-') . "\n";
                $mesaj .= "Doğum Tarihi : " . ($dogumTarihi ?: '-') . "\n";
                $mesaj .= "Şehir        : " . ($sehirAdi ?: '-') . "\n\n";
                $mesaj .= "Açıklama     : " . ($aciklama ?: '-') . "\n\n";
                $mesaj .= "---\n";
                $mesaj .= "Hesap oluşturmak için: {$personelUrl}";

                $ticketHelper = new TicketHelper($db);
                $sonuc = $ticketHelper->ticketOlustur(
                    [
                        'kaynak_kullanici_id' => '0',
                        'ad'      => $ad,
                        'soyad'   => $soyad,
                        'eposta'  => $email,
                        'telefon' => $telefonFormatli,
                    ],
                    [
                        'konu'        => 'Hesap oluşturma talebi',
                        'aciklama'    => $mesaj,
                        'kategori_id' => $kategoriId,
                        'oncelik_id'  => $oncelikId,
                    ]
                );

                if (!empty($sonuc['success'])) {
                    $ticketNo = $sonuc['data']['ticket_no'] ?? ($sonuc['ticket_no'] ?? '');
                    $success  = 'Talebiniz alındı. Yönetici onayından sonra giriş bilgileriniz e-posta ile iletilecektir.';

                    $anahtarlar = $_SESSION['register_form_anahtarlar'] ?? [];
                    $anahtarlar[$formAnahtar] = ['ticket_no' => (string)$ticketNo];
                    $_SESSION['register_form_anahtarlar'] = array_slice($anahtarlar, -20, null, true);

                    $_POST = [];
                } else {
                    $error = 'Talep gönderilemedi: ' . ($sonuc['message'] ?? 'Bilinmeyen hata');
                }
            } catch (Exception $e) {
                $error = 'Talep gönderilirken bir hata oluştu: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hesap Oluştur - <?= $siteTitle ?></title>

    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">

    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <!-- AdminLTE -->
    <link rel="stylesheet" href="assets/css/adminlte.min.css">

    <style>
        .register-box {
            width: 450px;
        }
        @media (max-width: 576px) {
            .register-box {
                width: 90%;
            }
        }
    </style>
</head>
<body class="register-page bg-body-secondary">
    <div class="register-box">
        <div class="register-logo">
            <a href="login.php"><b><?= $siteTitle ?></b></a>
        </div>

        <div class="card">
            <div class="card-body register-card-body">
                <p class="login-box-msg">Hesap Oluşturma Talebi</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                        <?php if ($ticketNo): ?>
                            <hr class="my-2">
                            <small>Talep numaranız: <strong><?= htmlspecialchars($ticketNo) ?></strong></small>
                        <?php endif; ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Giriş Yap
                        </a>
                    </div>
                <?php else: ?>

                <div class="alert alert-info py-2">
                    <small>
                        <i class="bi bi-info-circle"></i>
                        Talebiniz destek ekibine iletilir. Hesabınız onaylandığında giriş
                        bilgileriniz e-posta ile gönderilir.
                    </small>
                </div>

                <form method="POST" action="" id="registerForm">
                    <input type="hidden" name="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">
                    <div class="row">
                        <!-- Ad -->
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input
                                    type="text"
                                    class="form-control"
                                    name="ad"
                                    placeholder="Ad *"
                                    required
                                    value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>"
                                >
                                <div class="input-group-text">
                                    <span class="bi bi-person"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Soyad -->
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input
                                    type="text"
                                    class="form-control"
                                    name="soyad"
                                    placeholder="Soyad *"
                                    required
                                    value="<?= htmlspecialchars($_POST['soyad'] ?? '') ?>"
                                >
                                <div class="input-group-text">
                                    <span class="bi bi-person-fill"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- E-posta -->
                    <div class="input-group mb-3">
                        <input
                            type="email"
                            class="form-control"
                            name="email"
                            placeholder="E-posta *"
                            required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-envelope"></span>
                        </div>
                    </div>

                    <!-- Telefon -->
                    <div class="input-group mb-3">
                        <input
                            type="tel"
                            class="form-control"
                            name="telefon"
                            placeholder="Telefon (5XX XXX XX XX)"
                            pattern="[0-9]{10}"
                            maxlength="10"
                            value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-telephone"></span>
                        </div>
                    </div>

                    <!-- Doğum Tarihi -->
                    <div class="input-group mb-3">
                        <input
                            type="date"
                            class="form-control"
                            name="dogum_tarihi"
                            value="<?= htmlspecialchars($_POST['dogum_tarihi'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-calendar"></span>
                        </div>
                    </div>

                    <!-- Şehir -->
                    <div class="input-group mb-3">
                        <select class="form-select" name="sehir_id" id="sehir_id">
                            <option value="">Şehir Seçiniz</option>
                            <?php foreach ($sehirler as $sehir): ?>
                                <option value="<?= $sehir['Sehirid'] ?>" <?= (($_POST['sehir_id'] ?? '') == $sehir['Sehirid']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sehir['SehirAdi']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="input-group-text">
                            <span class="bi bi-geo-alt"></span>
                        </div>
                    </div>

                    <!-- Açıklama -->
                    <div class="mb-3">
                        <textarea
                            class="form-control"
                            name="aciklama"
                            rows="3"
                            placeholder="Açıklama (görev, departman vb.)"
                        ><?= htmlspecialchars($_POST['aciklama'] ?? '') ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-person-plus"></i> Talep Gönder
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <p class="mt-3 mb-0 text-center">
                    <a href="login.php" class="text-center">
                        <i class="bi bi-box-arrow-in-right"></i> Zaten hesabım var
                    </a>
                </p>

                <?php endif; ?>

                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= $footerYazi ?></small>
                </p>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>

    <!-- Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>

    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- AdminLTE -->
    <script src="assets/js/adminlte.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#sehir_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Şehir Seçiniz',
                allowClear: true,
                language: {
                    noResults: function () { return 'Sonuç bulunamadı'; },
                    searching: function () { return 'Aranıyor...'; }
                }
            });

            // Çift tıklamada formun ikinci kez gönderilmesini engelle
            $('#registerForm').on('submit', function (e) {
                var $form = $(this);
                if ($form.data('gonderiliyor')) { e.preventDefault(); return; }
                $form.data('gonderiliyor', true);
                $form.find('button[type="submit"]').prop('disabled', true)
                     .html('<i class="bi bi-hourglass-split"></i> Gönderiliyor...');
            });
        });
    </script>
</body>
</html>
