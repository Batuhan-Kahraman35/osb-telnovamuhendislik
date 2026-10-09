<?php
/**
 * Admin Panel - Ticket Detay & Mesajlaşma
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$kullaniciDetay = $db->fetchOne(
    "SELECT TOP 1 kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon FROM dbo.kullanicilar WHERE kullanici_id = ?",
    [(int)$user['kullanici_id']]
);

$ticketId = intval($_GET['id'] ?? 0);
if ($ticketId <= 0) {
    header('Location: /admin/pages/ticket-liste.php');
    exit;
}

$ticket = $db->fetchOne("
    SELECT * FROM dbo.V_Tickets_Liste WHERE Tickets_id = ? AND Tickets_kullanici_id = ?
", [$ticketId, $user['kullanici_id']]);

// Kendi talebi degilse bilgilendirme (CC) listesi uzerinden erisimi var mi bak.
// API'ye yalnizca bu durumda gidilir; kendi talebinde ek istek olusmaz.
$ccErisimi = false;
if (!$ticket) {
    $ccApiIdler = [];
    try {
        $ccHelper = new TicketHelper($db);
        $ccApiIdler = $ccHelper->ccTicketApiIdleri(
            trim((string)($kullaniciDetay['kullanici_email'] ?? ($user['email'] ?? '')))
        );
    } catch (Exception $e) {
        error_log('CC erisim kontrolu basarisiz: ' . $e->getMessage());
    }

    if (!empty($ccApiIdler)) {
        $yerTutucu = implode(',', array_fill(0, count($ccApiIdler), '?'));
        $ticket = $db->fetchOne(
            "SELECT * FROM dbo.V_Tickets_Liste
             WHERE Tickets_id = ? AND Tickets_api_id IN ($yerTutucu)",
            array_merge([$ticketId], $ccApiIdler)
        );
        $ccErisimi = (bool)$ticket;
    }
}

if (!$ticket) {
    header('Location: /admin/pages/ticket-liste.php');
    exit;
}

$ticketAciklamaSatir = $ccErisimi
    ? $db->fetchOne(
        "SELECT TOP 1 Tickets_aciklama FROM dbo.Tickets WHERE Tickets_id = ? AND Durum = 1",
        [$ticketId]
    )
    : $db->fetchOne(
        "SELECT TOP 1 Tickets_aciklama FROM dbo.Tickets WHERE Tickets_id = ? AND Tickets_kullanici_id = ? AND Durum = 1",
        [$ticketId, $user['kullanici_id']]
    );

$ticketAciklama = trim((string)($ticketAciklamaSatir['Tickets_aciklama'] ?? ''));

$pageTitle = 'Ticket Detayı: ' . htmlspecialchars($ticket['Tickets_konu']);
$menuAdi   = 'Destek Sistemi';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─── AJAX ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        $helper = new TicketHelper($db);

        $ad = trim((string)($kullaniciDetay['kullanici_ad'] ?? ''));
        $soyad = trim((string)($kullaniciDetay['kullanici_soyad'] ?? ''));
        $eposta = trim((string)($kullaniciDetay['kullanici_email'] ?? ($user['email'] ?? '')));
        $telefon = trim((string)($kullaniciDetay['kullanici_telefon'] ?? ''));

        $apiKullanici = [
            'kaynak_kullanici_id' => (int)$user['kullanici_id'],
            'ad' => $ad,
            'soyad' => $soyad,
            'eposta' => $eposta,
            'telefon' => $telefon,
        ];

        $aktifKullaniciAdSoyad = mb_strtolower(trim($ad . ' ' . $soyad), 'UTF-8');
        $appTimezoneId = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Istanbul';
        $appTimezone = new DateTimeZone($appTimezoneId);

        switch ($action) {
            case 'ticket_detail':
                $detay = $helper->ticketDetay((int)$ticket['Tickets_api_id'], $apiKullanici);
                $ticketData = $detay['data'] ?? $detay;
                
                if (is_array($ticketData)) {
                    $kategoriId = (int)($ticketData['kategori_id'] ?? 0);
                    $oncelikId = (int)($ticketData['oncelik_id'] ?? 0);
                    $durumId = (int)($ticketData['durum_id'] ?? 0);
                    
                    if ($kategoriId > 0) {
                        $kat = $db->fetchOne("SELECT Ticket_Kategoriler_ad, Ticket_Kategoriler_renk FROM dbo.Ticket_Kategoriler WHERE Ticket_Kategoriler_id = ?", [$kategoriId]);
                        if ($kat) {
                            $ticketData['kategori_adi'] = $kat['Ticket_Kategoriler_ad'];
                            $ticketData['kategori_renk'] = $kat['Ticket_Kategoriler_renk'];
                        }
                    }
                    
                    if ($oncelikId > 0) {
                        $onc = $db->fetchOne("SELECT Ticket_Oncelikler_ad, Ticket_Oncelikler_renk FROM dbo.Ticket_Oncelikler WHERE Ticket_Oncelikler_id = ?", [$oncelikId]);
                        if ($onc) {
                            $ticketData['oncelik_adi'] = $onc['Ticket_Oncelikler_ad'];
                            $ticketData['oncelik_renk'] = $onc['Ticket_Oncelikler_renk'];
                        }
                    }
                    
                    if ($durumId > 0) {
                        $dur = $db->fetchOne("SELECT Ticket_Durumlar_ad, Ticket_Durumlar_renk FROM dbo.Ticket_Durumlar WHERE Ticket_Durumlar_id = ?", [$durumId]);
                        if ($dur) {
                            $ticketData['durum_adi'] = $dur['Ticket_Durumlar_ad'];
                            $ticketData['durum_renk'] = $dur['Ticket_Durumlar_renk'];
                        }
                    }
                    
                    $atananAd = trim((string)($ticketData['atanan_ad'] ?? ''));
                    $atananSoyad = trim((string)($ticketData['atanan_soyad'] ?? ''));
                    if ($atananAd !== '' || $atananSoyad !== '') {
                        $ticketData['atanan_adi'] = trim($atananAd . ' ' . $atananSoyad);
                    }
                    
                    $tarih = $ticketData['acilis_tarihi'] ?? '';
                    $ticketData['OlusturmaTarihi'] = $tarih;

                    // Yerel DB'yi API'den gelen güncel durumla senkronize et
                    if ($kategoriId > 0 && $oncelikId > 0 && $durumId > 0) {
                        try {
                            $db->execute(
                                "UPDATE dbo.Tickets SET
                                    Tickets_kategori_id    = ?,
                                    Tickets_oncelik_id     = ?,
                                    Tickets_durum_id       = ?,
                                    GuncelleyenKullanici   = ?,
                                    GuncellemeTarihi       = GETDATE()
                                 WHERE Tickets_id = ?",
                                [$kategoriId, $oncelikId, $durumId, $user['kullanici_id'], $ticketId]
                            );
                        } catch (Exception $e) {
                            // Sync hatası ana yanıtı engellemesin
                        }
                    }

                    echo json_encode([
                        'success' => true,
                        'data' => $ticketData,
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Ticket detayı boş dönüş.',
                    ]);
                }
                break;

            case 'mesajlar':
                if ($ad === '' || $eposta === '') {
                    echo json_encode(['success' => false, 'message' => 'Kullanıcı ad veya e-posta bilgisi eksik. Profili güncelleyin.']);
                    break;
                }
                $extractList = static function (array $payload): array {
                    $data = $payload['data'] ?? [];
                    if (isset($data[0]) && is_array($data[0])) {
                        return $data;
                    }

                    $candidates = [
                        $data['messages'] ?? null,
                        $data['mesajlar'] ?? null,
                        $data['data'] ?? null,
                        $payload['messages'] ?? null,
                        $payload['mesajlar'] ?? null,
                    ];

                    foreach ($candidates as $list) {
                        if (is_array($list) && isset($list[0]) && is_array($list[0])) {
                            return $list;
                        }
                    }

                    return [];
                };

                $normalizeMsg = static function (array $m): array {
                    return [
                        'gonderen_tip' => (string)($m['gonderen_tip'] ?? $m['tip'] ?? $m['sender_type'] ?? ''),
                        'kaynak' => (string)($m['kaynak'] ?? $m['source'] ?? ''),
                        'gonderen_ad' => (string)($m['gonderen_ad'] ?? $m['ad'] ?? $m['sender_name'] ?? ''),
                        'tarih' => (string)($m['tarih'] ?? $m['created_at'] ?? $m['mesaj_tarih'] ?? ''),
                        'mesaj' => (string)($m['mesaj'] ?? $m['message'] ?? $m['icerik'] ?? ''),
                    ];
                };

                $normalizeDate = static function (?string $value) use ($appTimezone): array {
                    $raw = trim((string)$value);
                    if ($raw === '') {
                        return ['ts' => 0, 'text' => ''];
                    }

                    // ISO8601 ve timezone offset iceren tarihleri dogrudan parse et.
                    if (preg_match('/(Z|[+\-]\d{2}:\d{2})$/', $raw) === 1) {
                        try {
                            $dt = new DateTimeImmutable($raw);
                            $dt = $dt->setTimezone($appTimezone);
                            return [
                                'ts' => $dt->getTimestamp(),
                                'text' => $dt->format('d.m.Y H:i'),
                            ];
                        } catch (Exception $e) {
                            // Asagidaki fallback akisi denenir.
                        }
                    }

                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'd.m.Y H:i:s',
                        'd.m.Y H:i',
                        'Y-m-d\\TH:i:sP',
                        'Y-m-d\\TH:i:s',
                    ];

                    foreach ($formats as $fmt) {
                        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $appTimezone);
                        if ($dt instanceof DateTimeImmutable) {
                            return [
                                'ts' => $dt->getTimestamp(),
                                'text' => $dt->format('d.m.Y H:i'),
                            ];
                        }
                    }

                    try {
                        $dt = new DateTimeImmutable($raw, $appTimezone);
                        return [
                            'ts' => $dt->getTimestamp(),
                            'text' => $dt->format('d.m.Y H:i'),
                        ];
                    } catch (Exception $e) {
                        // Ham degeri metin olarak birak.
                    }

                    return ['ts' => 0, 'text' => $raw];
                };

                $extractDosyalar = static function (array $m): array {
                    $dosyalar = [];
                    
                    $ekler = $m['ekler'] ?? [];
                    if (!is_array($ekler)) {
                        return $dosyalar;
                    }
                    
                    foreach ($ekler as $ek) {
                        if (!is_array($ek)) {
                            continue;
                        }
                        
                        $dosya_yolu = trim((string)($ek['dosya_url'] ?? $ek['dosya_yolu'] ?? ''));
                        if ($dosya_yolu === '') {
                            continue;
                        }
                        
                        if (strpos($dosya_yolu, 'http://') !== 0 && strpos($dosya_yolu, 'https://') !== 0) {
                            $dosya_yolu = 'https://destek.ornekyazilim.com/' . ltrim((string)$dosya_yolu, '/');
                        }
                        
                        $dosya_adi = trim((string)($ek['dosya_adi'] ?? ''));
                        if ($dosya_adi === '') {
                            $pathPart = parse_url($dosya_yolu, PHP_URL_PATH);
                            $dosya_adi = $pathPart ? basename((string)$pathPart) : 'Dosya';
                        }
                        
                        $dosyalar[] = [
                            'ad' => $dosya_adi,
                            'url' => $dosya_yolu,
                        ];
                    }
                    
                    return $dosyalar;
                };

                $mesajlar = [];
                $apiWarning = '';

                if (!empty($ticket['Tickets_api_id'])) {
                    $apiList = [];

                    // ticket_messages dene; çalışmıyorsa ticket_detail'den mesajlar al
                    try {
                        $resp    = $helper->ticketMesajlar((int)$ticket['Tickets_api_id'], $apiKullanici);
                        $apiList = $extractList(is_array($resp) ? $resp : []);
                    } catch (Exception $e) {
                        // API bu action'ı desteklemiyor, ticket_detail'e düş
                    }

                    if (empty($apiList)) {
                        try {
                            $detayResp = $helper->ticketDetay((int)$ticket['Tickets_api_id'], $apiKullanici);
                            $apiList   = $extractList(is_array($detayResp) ? $detayResp : []);
                        } catch (Exception $e) {
                            $apiWarning = $e->getMessage();
                        }
                    }

                    foreach ($apiList as $m) {
                        if (!is_array($m)) {
                            continue;
                        }

                        $mesajMetni = trim((string)($m['Ticket_Mesajlar_mesaj'] ?? $m['mesaj'] ?? $m['message'] ?? $m['icerik'] ?? ''));
                        // API yanıtında yazan_ad/yazan_soyad veya kullanici_ad/kullanici_soyad olabilir
                        $gonderenAd = trim((string)(
                            ($m['kullanici_ad'] ?? $m['yazan_ad'] ?? '') . ' ' .
                            ($m['kullanici_soyad'] ?? $m['yazan_soyad'] ?? '')
                        ));
                        if ($gonderenAd === '') {
                            $gonderenAd = (string)($m['gonderen_ad'] ?? $m['ad'] ?? $m['sender_name'] ?? 'Kullanıcı');
                        }

                        $yazanId       = (int)($m['Ticket_Mesajlar_yazan_kul_id'] ?? 0);
                        $gonderenAdNorm = mb_strtolower(trim((string)$gonderenAd), 'UTF-8');
                        $bizMesajMi    = ($yazanId === (int)$user['kullanici_id'])
                            || ($aktifKullaniciAdSoyad !== '' && $gonderenAdNorm === $aktifKullaniciAdSoyad);
                        $hamTarih   = (string)($m['Ticket_Mesajlar_tarih'] ?? $m['tarih'] ?? $m['created_at'] ?? $m['mesaj_tarih'] ?? '');
                        $mesajTarih = $normalizeDate($hamTarih);
                        $dosyalar   = $extractDosyalar($m);

                        if ($mesajMetni === '' && empty($dosyalar)) {
                            continue;
                        }

                        $nm = [
                            'gonderen_tip' => $bizMesajMi ? 'musteri' : 'destek',
                            'mesaj_yonu'   => $bizMesajMi ? 'sag' : 'sol',
                            'kaynak'       => 'OSB',
                            'gonderen_ad'  => $gonderenAd,
                            'tarih'        => $mesajTarih['text'] !== '' ? $mesajTarih['text'] : $hamTarih,
                            'tarih_ts'     => $mesajTarih['ts'],
                            'mesaj'        => $mesajMetni,
                            'dosyalar'     => $dosyalar,
                        ];

                        if (trim($nm['mesaj']) === '') {
                            continue;
                        }

                        $mesajlar[] = $nm;
                    }
                }

                usort($mesajlar, static function (array $a, array $b): int {
                    $at = (int)($a['tarih_ts'] ?? 0);
                    $bt = (int)($b['tarih_ts'] ?? 0);
                    if ($at === $bt) {
                        return 0;
                    }

                    if ($at === 0) {
                        return 1;
                    }
                    if ($bt === 0) {
                        return -1;
                    }

                    return $at <=> $bt;
                });

                echo json_encode([
                    'success' => true,
                    'data' => $mesajlar,
                    'message' => $apiWarning,
                ]);
                break;

            case 'yanit':
                // Mükerrer gönderim koruması: form anahtarı yalnız bir kez kullanılabilir.
                // PHP session kilidi aynı oturumdaki eşzamanlı istekleri sıraya sokar;
                // ikinci istek, ilki bittikten sonra anahtarı dolu bulur.
                $formAnahtar = (string)($_POST['form_anahtar'] ?? '');
                if (!preg_match('/^[a-f0-9]{32}$/', $formAnahtar)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz form anahtarı. Sayfayı yenileyip tekrar deneyin.']);
                    break;
                }
                if (isset($_SESSION['ticket_yanit_anahtarlar'][$formAnahtar])) {
                    echo json_encode([
                        'success'           => true,
                        'mukerrer'          => true,
                        'message'           => 'Bu yanıt zaten gönderildi.',
                        'yeni_form_anahtar' => bin2hex(random_bytes(16)),
                    ]);
                    break;
                }

                $mesaj = trim($_POST['mesaj'] ?? '');
                if (empty($mesaj)) {
                    echo json_encode(['success' => false, 'message' => 'Mesaj boş olamaz.']);
                    break;
                }
                if (!$ticket['Tickets_api_id']) {
                    echo json_encode(['success' => false, 'message' => 'Bu ticket API ile eşleştirilmemiş.']);
                    break;
                }

                if ($ad === '' || $eposta === '') {
                    echo json_encode(['success' => false, 'message' => 'Kullanici ad veya eposta bilgisi eksik. Profil/kullanici kaydini guncelleyin.']);
                    break;
                }

                $result = $helper->ticketYanit(
                    (int)$ticket['Tickets_api_id'],
                    $apiKullanici,
                    $mesaj,
                    $_FILES ?? []
                );

                if (!empty($result['success'])) {
                    // CC ile erisilen talepte sahiplik sarti aranmaz; erisim yukarida dogrulandi.
                    if ($ccErisimi) {
                        $db->execute("UPDATE dbo.Tickets SET GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE Tickets_id = ?", [$user['kullanici_id'], $ticketId]);
                    } else {
                        $db->execute("UPDATE dbo.Tickets SET GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE Tickets_id = ? AND Tickets_kullanici_id = ?", [$user['kullanici_id'], $ticketId, $user['kullanici_id']]);
                    }

                    $anahtarlar = $_SESSION['ticket_yanit_anahtarlar'] ?? [];
                    $anahtarlar[$formAnahtar] = $ticketId;
                    $_SESSION['ticket_yanit_anahtarlar'] = array_slice($anahtarlar, -20, null, true);

                    // Aynı sayfada ikinci meşru mesaj için yeni anahtar
                    $result['yeni_form_anahtar'] = bin2hex(random_bytes(16));
                }
                echo json_encode($result);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
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
    <style>
        .mesaj-balonu { max-width: 80%; }
        .mesaj-biz { margin-left: auto; }
        .mesaj-kart { border-radius: 12px; }
        .mesaj-biz .mesaj-kart { background: #d1e7dd; border-color: #a3cfbb; }
        .mesaj-karsi .mesaj-kart { background: #f8f9fa; border-color: #dee2e6; }
        .mesaj-metni { overflow-wrap: anywhere; }
        .mesaj-metni a { text-decoration: underline; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6"><h3 class="mb-0">Ticket Detayı</h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <li class="breadcrumb-item"><a href="/admin/pages/ticket-liste.php">Ticket Listesi</a></li>
                            <li class="breadcrumb-item active">Detay</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">
                <div class="row">
                    <!-- Sol: Mesajlar -->
                    <div class="col-lg-8">
                        <div class="card mb-3">
                            <div class="card-header">
                                <i class="bi bi-chat-dots me-1"></i>
                                <strong><?= htmlspecialchars($ticket['Tickets_konu']) ?></strong>
                            </div>
                            <div class="card-body" id="mesajlar" style="min-height:300px; max-height:500px; overflow-y:auto;">
                                <div class="text-center text-muted py-4" id="mesajYukleniyor">
                                    <i class="bi bi-arrow-repeat spin"></i> Mesajlar yükleniyor...
                                </div>
                            </div>
                        </div>

                        <!-- Yanıt Formu -->
                        <div class="card">
                            <div class="card-header"><i class="bi bi-reply me-1"></i>Yanıt Yaz</div>
                            <div class="card-body">
                                <form id="formYanit" enctype="multipart/form-data">
                                    <input type="hidden" name="form_anahtar" id="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">
                                    <div class="mb-3">
                                        <textarea id="mesajMetin" class="form-control" rows="4" placeholder="Mesajınızı yazın..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Dosya Ekle (opsiyonel)</label>
                                        <input type="file" name="dosyalar[]" class="form-control" multiple accept="image/*,.pdf,.doc,.docx,.txt,.zip">
                                    </div>
                                    <button type="submit" class="btn btn-primary" id="btnYanit">
                                        <i class="bi bi-send me-1"></i>Gönder
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Sağ: Ticket Bilgileri -->
                    <div class="col-lg-4">
                        <div class="mb-3">
                            <a href="/admin/pages/ticket-liste.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left me-1"></i>Taleplere Dön
                            </a>
                        </div>
                        <div class="card mb-3">
                            <div class="card-header"><i class="bi bi-info-circle me-1"></i>Ticket Bilgileri</div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <th class="text-muted" style="width:40%">Ticket No</th>
                                        <td id="tdTicketNo"><?= $ticket['Tickets_no'] ? '<code>' . htmlspecialchars($ticket['Tickets_no']) . '</code>' : '-' ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Kategori</th>
                                        <td id="tdKategori"><?= $ticket['kategori_adi'] ? '<span class="badge" style="background-color:' . htmlspecialchars($ticket['kategori_renk']) . ';color:#fff">' . htmlspecialchars($ticket['kategori_adi']) . '</span>' : '-' ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Öncelik</th>
                                        <td id="tdOncelik"><?= $ticket['oncelik_adi'] ? '<span class="badge" style="background-color:' . htmlspecialchars($ticket['oncelik_renk']) . ';color:#fff">' . htmlspecialchars($ticket['oncelik_adi']) . '</span>' : '-' ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Durum</th>
                                        <td id="tdDurum"><?= $ticket['durum_adi'] ? '<span class="badge" style="background-color:' . htmlspecialchars($ticket['durum_renk']) . ';color:#fff">' . htmlspecialchars($ticket['durum_adi']) . '</span>' : '-' ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Kullanıcı</th>
                                        <td>
                                            <?= htmlspecialchars($ticket['kullanici_adi'] ?? '-') ?>
                                            <?php if ($ccErisimi): ?>
                                                <i class="bi bi-people-fill text-secondary ms-1" title="Bu talebe bilgilendirme (CC) amacıyla eklendiniz"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Atanan</th>
                                        <td id="tdAtanan">-</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Açılma</th>
                                        <td id="tdAcilma"><?= $ticket['OlusturmaTarihi'] ? date('d.m.Y H:i', strtotime($ticket['OlusturmaTarihi'])) : '-' ?></td>
                                    </tr>
                                </table>
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
    var pageUrl = window.location.pathname + window.location.search;

    // ── Ticket Detayını Güncelle (API'den) ──────
    function loadTicketDetay() {
        $.post(pageUrl, { action: 'ticket_detail' }, function (r) {
            if (r.success && r.data) {
                var t = r.data;
                
                var ticketNo = t['Tickets_no'] || t['ticket_no'] || t['no'] || '-';
                if (ticketNo !== '-') {
                    ticketNo = '<code>' + escHtml(ticketNo) + '</code>';
                }
                $('#tdTicketNo').html(ticketNo);
                
                var kategoriAdi = t['kategori_adi'] || t['category'] || t['kategori'] || '';
                var kategoriRenk = t['kategori_renk'] || t['category_color'] || '#6c757d';
                if (kategoriAdi !== '') {
                    $('#tdKategori').html('<span class="badge" style="background-color:' + escHtml(kategoriRenk) + ';color:#fff">' + escHtml(kategoriAdi) + '</span>');
                }

                var oncelikAdi = t['oncelik_adi'] || t['priority'] || t['oncelik'] || '';
                var oncelikRenk = t['oncelik_renk'] || t['priority_color'] || '#6c757d';
                if (oncelikAdi !== '') {
                    $('#tdOncelik').html('<span class="badge" style="background-color:' + escHtml(oncelikRenk) + ';color:#fff">' + escHtml(oncelikAdi) + '</span>');
                }

                var durumAdi = t['durum_adi'] || t['status'] || t['durum'] || '';
                var durumRenk = t['durum_renk'] || t['status_color'] || '#6c757d';
                if (durumAdi !== '') {
                    $('#tdDurum').html('<span class="badge" style="background-color:' + escHtml(durumRenk) + ';color:#fff">' + escHtml(durumAdi) + '</span>');
                }
                
                var atananAdi = t['atanan_adi'] || '';
                if (atananAdi !== '') {
                    $('#tdAtanan').html(escHtml(atananAdi));
                }
                
                var acilma = t['OlusturmaTarihi'] || t['created_at'] || t['tarih'] || '';
                if (acilma !== '') {
                    $('#tdAcilma').html(escHtml(acilma));
                }
            }
        });
    }
    loadTicketDetay();

    // ── Mesajları Yükle ──────────────────────────
    function loadMesajlar() {
        $.post(pageUrl, { action: 'mesajlar' }, function (r) {
            var html = '';
            if (r.success && r.data && r.data.length > 0) {
                var siraliMesajlar = [].concat(r.data).sort(function (a, b) {
                    return Number(a.tarih_ts || 0) - Number(b.tarih_ts || 0);
                });

                siraliMesajlar.forEach(function (m) {
                    var biz = (String(m.mesaj_yonu || '').toLowerCase() === 'sag') || (String(m.gonderen_tip || '').toLowerCase() === 'musteri');
                    var side = biz ? 'mesaj-biz' : 'mesaj-karsi';
                    var etiket = (m.yerel_ilk_mesaj == 1) ? '<span class="badge text-bg-primary ms-2">Talep Metni</span>' : '';
                    var dosyalarHtml = '';
                    if (Array.isArray(m.dosyalar) && m.dosyalar.length > 0) {
                        dosyalarHtml = '<div class="mt-2"><div class="small text-muted mb-1">Ek Dosyalar</div>';
                        m.dosyalar.forEach(function (d) {
                            var ad = escHtml(d.ad || 'Dosya');
                            var url = escAttr(d.url || '#');
                            if (url === '#') return;
                            dosyalarHtml += '<div class="small"><i class="bi bi-paperclip me-1"></i><a href="' + url + '" target="_blank" rel="noopener noreferrer">' + ad + '</a></div>';
                        });
                        dosyalarHtml += '</div>';
                    }
                    html += `
                        <div class="d-flex mb-3 mesaj-balonu ${side}">
                            <div class="mesaj-kart card border p-3 w-100">
                                <div class="d-flex justify-content-between mb-1">
                                    <strong class="small">${escHtml(m.gonderen_ad ?? (biz ? 'Biz' : 'Destek'))}${etiket}</strong>
                                    <span class="text-muted small">${escHtml(m.tarih ?? '')}</span>
                                </div>
                                <div class="mesaj-metni">${metinHtml(m.mesaj ?? '')}</div>
                                ${dosyalarHtml}
                            </div>
                        </div>`;
                });
            } else if (!r.success) {
                html = '<div class="alert alert-warning">' + escHtml(r.message ?? 'Mesaj bulunamadı.') + '</div>';
            } else {
                html = '<p class="text-muted text-center">Henüz mesaj yok.</p>';
            }
            $('#mesajYukleniyor').hide();
            $('#mesajlar').html(html);
            var cont = document.getElementById('mesajlar');
            cont.scrollTop = cont.scrollHeight;
        }).fail(function () {
            $('#mesajYukleniyor').html('<div class="alert alert-danger">Mesajlar yüklenemedi.</div>');
        });
    }
    loadMesajlar();

    // ── Yanıt Gönder ────────────────────────────
    var gonderiliyor = false;
    $('#formYanit').on('submit', function (e) {
        e.preventDefault();
        if (gonderiliyor) return;
        var mesaj = $('#mesajMetin').val().trim();
        if (!mesaj) { Swal.fire('Uyarı', 'Mesaj boş olamaz.', 'warning'); return; }

        var fd = new FormData(this);
        fd.append('action', 'yanit');
        fd.append('mesaj', mesaj);

        gonderiliyor = true;
        $('#btnYanit').prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Gönderiliyor...');

        $.ajax({
            url: pageUrl,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function (r) {
                if (r.success) {
                    $('#mesajMetin').val('');
                    $('#formYanit')[0].reset();
                    // Form temizlendi: sonraki meşru mesaj için yeni tek kullanımlık anahtar
                    $('#form_anahtar').val(r.yeni_form_anahtar || yeniFormAnahtari());
                    loadMesajlar();
                    Swal.fire({ icon: 'success', title: 'Gönderildi!', timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire('Hata!', r.message ?? 'Gönderilemedi.', 'error');
                }
            },
            error: function () { Swal.fire('Hata!', 'Sunucu hatası.', 'error'); },
            complete: function () {
                gonderiliyor = false;
                $('#btnYanit').prop('disabled', false).html('<i class="bi bi-send me-1"></i>Gönder');
            }
        });
    });

    function yeniFormAnahtari() {
        var b = new Uint8Array(16);
        window.crypto.getRandomValues(b);
        return Array.from(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Düz metni güvenli HTML'e çevirir: önce kaçış, sonra URL'leri link yapar, satır sonlarını korur
    function metinHtml(str) {
        return escHtml(str)
            .replace(/\b((?:https?:\/\/|www\.)[^\s<]+)/gi, function (url) {
                // Cümle sonundaki noktalama linke dahil edilmez
                var son = url.match(/[.,;:!?)\]]+$/);
                var kuyruk = son ? son[0] : '';
                if (kuyruk) url = url.slice(0, -kuyruk.length);
                var href = /^www\./i.test(url) ? 'https://' + url : url;
                return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + url + '</a>' + kuyruk;
            })
            .replace(/\n/g, '<br>');
    }

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
});
</script>
</body>
</html>
