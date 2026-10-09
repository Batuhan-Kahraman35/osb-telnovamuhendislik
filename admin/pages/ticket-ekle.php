<?php
/**
 * Admin Panel - Yeni Ticket Oluştur
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$pageTitle = 'Yeni Ticket';
$menuAdi   = 'Destek Sistemi';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$kullaniciDetay = $db->fetchOne(
    "SELECT TOP 1 kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon FROM dbo.kullanicilar WHERE kullanici_id = ?",
    [(int)$user['kullanici_id']]
);

$kategoriler = [];
$oncelikler  = [];
$apiSecenekHata = '';

try {
    $ticketHelper = new TicketHelper($db);
    $ticketHelper->senkronizeMetaTanimlari((int)$user['kullanici_id']);
    $kategoriler = $db->fetchAll("SELECT Ticket_Kategoriler_id AS id, Ticket_Kategoriler_ad AS ad FROM dbo.Ticket_Kategoriler WHERE Durum = 1 ORDER BY Ticket_Kategoriler_sira, Ticket_Kategoriler_id");
    $oncelikler  = $db->fetchAll("SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad FROM dbo.Ticket_Oncelikler WHERE Durum = 1 ORDER BY Ticket_Oncelikler_sira, Ticket_Oncelikler_id");
} catch (Exception $e) {
    $apiSecenekHata = $e->getMessage();
}

$tanimlarHazir = !empty($kategoriler) && !empty($oncelikler);

// ─── AJAX ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        if ($action !== 'kaydet') {
            echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
            exit;
        }

        // Mükerrer gönderim koruması: form anahtarı yalnız bir kez kullanılabilir.
        // PHP session kilidi aynı oturumdaki eşzamanlı istekleri sıraya sokar;
        // ikinci istek, ilki bittikten sonra anahtarı dolu bulur.
        $formAnahtar = (string)($_POST['form_anahtar'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $formAnahtar)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz form anahtarı. Sayfayı yenileyip tekrar deneyin.']);
            exit;
        }
        $oncekiKayit = $_SESSION['ticket_ekle_anahtarlar'][$formAnahtar] ?? null;
        if ($oncekiKayit) {
            echo json_encode([
                'success'   => true,
                'mukerrer'  => true,
                'message'   => 'Bu ticket zaten oluşturuldu.',
                'ticket_id' => $oncekiKayit['ticket_id'],
            ]);
            exit;
        }

        $konu      = trim($_POST['konu']       ?? '');
        $aciklama   = trim($_POST['aciklama']   ?? '');
        $kategoriId = intval($_POST['kategori_id'] ?? 0);
        $oncelikId  = intval($_POST['oncelik_id']  ?? 0);

            if (!$tanimlarHazir) {
                echo json_encode(['success' => false, 'message' => 'Kategori ve oncelik tanimlari eksik.']);
                exit;
            }

        if (empty($konu) || empty($aciklama) || !$kategoriId || !$oncelikId) {
            echo json_encode(['success' => false, 'message' => 'Zorunlu alanlar eksik.']);
            exit;
        }

        $ad = trim((string)($kullaniciDetay['kullanici_ad'] ?? ''));
        $soyad = trim((string)($kullaniciDetay['kullanici_soyad'] ?? ''));
        $eposta = trim((string)($kullaniciDetay['kullanici_email'] ?? ($user['email'] ?? '')));
        $telefon = trim((string)($kullaniciDetay['kullanici_telefon'] ?? ''));

        if ($ad === '' || $eposta === '') {
            echo json_encode(['success' => false, 'message' => 'Kullanici ad veya eposta bilgisi eksik. Profil/kullanici kaydini guncelleyin.']);
            exit;
        }

        $kullanici = [
            'kaynak_kullanici_id' => $user['kullanici_id'],
            'ad'                  => $ad,
            'soyad'               => $soyad,
            'eposta'              => $eposta,
            'telefon'             => $telefon,
        ];

        $data = [
            'konu'        => $konu,
            'aciklama'    => $aciklama,
            'kategori_id' => $kategoriId,
            'oncelik_id'  => $oncelikId,
        ];

        $helper = $ticketHelper ?? new TicketHelper($db);
        $result = $helper->ticketOlustur($kullanici, $data, $_FILES ?? []);

        if (!empty($result['success'])) {
            // Yerel kayıt
            $apiId = $result['data']['ticket_id']  ?? null;
            $apiNo = $result['data']['ticket_no']  ?? null;

            $db->execute("
                INSERT INTO dbo.Tickets (
                    Tickets_api_id, Tickets_no, Tickets_konu, Tickets_aciklama,
                    Tickets_kategori_id, Tickets_oncelik_id, Tickets_durum_id,
                    Tickets_kullanici_id, OlusturanKullanici, OlusturmaTarihi,
                    GuncelleyenKullanici, GuncellemeTarihi, Durum
                ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, GETDATE(), ?, GETDATE(), 1)
            ", [
                $apiId, $apiNo, $konu, $aciklama,
                $kategoriId, $oncelikId,
                $user['kullanici_id'],
                $user['kullanici_id'],
                $user['kullanici_id'],
            ]);

            $newId = $db->getLastInsertId();

            $anahtarlar = $_SESSION['ticket_ekle_anahtarlar'] ?? [];
            $anahtarlar[$formAnahtar] = ['ticket_id' => $newId];
            $_SESSION['ticket_ekle_anahtarlar'] = array_slice($anahtarlar, -20, null, true);

            echo json_encode(['success' => true, 'message' => 'Ticket oluşturuldu.', 'ticket_id' => $newId]);
        } else {
            echo json_encode(['success' => false, 'message' => $result['message'] ?? 'API hatası.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <li class="breadcrumb-item"><a href="/admin/pages/ticket-liste.php">Ticket Listesi</a></li>
                            <li class="breadcrumb-item active">Yeni Ticket</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header"><i class="bi bi-plus-circle me-1"></i>Yeni Destek Talebi</div>
                            <div class="card-body">
                                <?php if (!$tanimlarHazir): ?>
                                <div class="alert alert-warning">
                                    ticket_meta ile kategori/oncelik senkronizasyonu yapilamadi.
                                    <?php if (!empty($apiSecenekHata)): ?>
                                        <div class="small mt-1">Detay: <?= htmlspecialchars($apiSecenekHata) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <form id="formTicket" enctype="multipart/form-data">
                                    <input type="hidden" name="form_anahtar" id="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">
                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <label class="form-label fw-semibold">Konu <span class="text-danger">*</span></label>
                                            <input type="text" name="konu" id="konu" class="form-control" placeholder="Ticket konusunu yazın..." maxlength="255" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Kategori <span class="text-danger">*</span></label>
                                            <select name="kategori_id" id="kategori_id" class="form-select select2-form" required>
                                                <option value="">Seçiniz...</option>
                                                <?php foreach ($kategoriler as $k): ?>
                                                <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Öncelik <span class="text-danger">*</span></label>
                                            <select name="oncelik_id" id="oncelik_id" class="form-select select2-form" required>
                                                <option value="">Seçiniz...</option>
                                                <?php foreach ($oncelikler as $o): ?>
                                                <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['ad']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label fw-semibold">Açıklama <span class="text-danger">*</span></label>
                                            <textarea name="aciklama" id="aciklama" class="form-control" rows="6" placeholder="Sorununuzu veya talebinizi detaylı açıklayın..." required></textarea>
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label fw-semibold">Dosya Ekle <span class="text-muted small">(opsiyonel)</span></label>
                                            <input type="file" name="dosyalar[]" class="form-control" multiple accept="image/*,.pdf,.doc,.docx,.txt,.zip">
                                            <div class="form-text">Maks. 5 dosya. Desteklenen: resim, PDF, Word, ZIP</div>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary" id="btnKaydet">
                                            <i class="bi bi-send me-1"></i>Gönder
                                        </button>
                                        <a href="/admin/pages/ticket-liste.php" class="btn btn-secondary">
                                            <i class="bi bi-arrow-left me-1"></i>Geri
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script>
$(function () {
    $('.select2-form').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...' });

    var tanimlarHazir = <?= $tanimlarHazir ? 'true' : 'false' ?>;
    if (!tanimlarHazir) {
        $('#btnKaydet').prop('disabled', true);
    }

    var gonderiliyor = false;
    $('#formTicket').on('submit', function (e) {
        e.preventDefault();
        if (gonderiliyor) return;

        if (!tanimlarHazir) {
            Swal.fire('Uyari', 'Kategori ve oncelik tanimlari eksik.', 'warning');
            return;
        }

        var fd = new FormData(this);
        fd.append('action', 'kaydet');

        gonderiliyor = true;
        $('#btnKaydet').prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Gönderiliyor...');

        $.ajax({
            url: '',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function (r) {
                if (r.success) {
                    // Başarılı: buton kilitli kalır, sayfadan çıkılana kadar tekrar gönderilemez
                    Swal.fire({
                        icon: 'success',
                        title: 'Ticket oluşturuldu!',
                        text: r.message,
                        confirmButtonText: 'Detaya Git'
                    }).then(function () {
                        window.location.href = '/admin/pages/ticket-detay.php?id=' + r.ticket_id;
                    });
                } else {
                    kilidiAc();
                    Swal.fire('Hata!', r.message ?? 'Oluşturulamadı.', 'error');
                }
            },
            error: function () { kilidiAc(); Swal.fire('Hata!', 'Sunucu hatası.', 'error'); }
        });
    });

    function kilidiAc() {
        gonderiliyor = false;
        $('#btnKaydet').prop('disabled', false).html('<i class="bi bi-send me-1"></i>Gönder');
    }
});
</script>
</body>
</html>
