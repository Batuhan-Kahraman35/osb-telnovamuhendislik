<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/PazaryeriFabrika.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok.');
}

$pageInfo  = $db->fetchOne("SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1", ['%' . $currentPageFile]);
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri Ürünler';
$menuAdi   = $pageInfo['menu_adi'] ?? 'Pazaryeri';
$siteTitle = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC")['site_ayarlari_site_title'] ?? 'Portal';

// Pazaryeri kanalları — sabit liste yok; tanim_Pazaryerleri + aktif kanallardan üretilir.
// Arayüz Kanal_Id / Kanal_Adi / Entegrasyon_Adi / Entegrasyon_Renk anahtarlarını bekler.
$kanallar = array_map(fn($k) => $k + [
    'Kanal_Id'         => $k['kanal_id'],
    'Kanal_Adi'        => $k['kanal_adi'],
    'Entegrasyon_Adi'  => $k['Pazaryerleri_Ad'],
    'Entegrasyon_Renk' => $k['Pazaryerleri_Renk'],
], PazaryeriFabrika::kanallar());

/** Onay durumu (approved | waiting | rejected) — yeni kolonlardan türetilir */
const ONAY_SQL = "CASE WHEN pu.PazaryeriUrunleri_Onayli = 1 THEN 'approved'
                       WHEN pu.PazaryeriUrunleri_HamDurum = 'rejected' THEN 'rejected'
                       ELSE 'waiting' END";

/** Eski arayüz alan adlarıyla ürün kolonları */
const URUN_KOLONLARI = "
    pu.PazaryeriUrunleri_id            AS pu_id,
    pu.kanal_id                        AS pu_kanal_id,
    pu.PazaryeriUrunleri_DisUrunId     AS pu_platform_urun_id,
    pu.PazaryeriUrunleri_Barkod        AS pu_barkod,
    pu.PazaryeriUrunleri_StokKodu      AS pu_stok_kodu,
    pu.PazaryeriUrunleri_AnaUrunKodu   AS pu_model_kodu,
    pu.PazaryeriUrunleri_Renk          AS pu_renk,
    pu.PazaryeriUrunleri_Adi           AS pu_adi,
    pu.PazaryeriUrunleri_KategoriAdi   AS pu_kategori_adi,
    pu.PazaryeriUrunleri_MarkaAdi      AS pu_marka_adi,
    pu.PazaryeriUrunleri_ListeFiyat    AS pu_fiyat,
    pu.PazaryeriUrunleri_SatisFiyat    AS pu_indirimli_fiyat,
    pu.PazaryeriUrunleri_Stok          AS pu_stok,
    pu.PazaryeriUrunleri_KdvOran       AS pu_kdv_orani,
    pu.PazaryeriUrunleri_GorselUrl     AS pu_gorsel_url,
    pu.PazaryeriUrunleri_Aciklama      AS pu_aciklama,
    pu.urun_hizmet_id                  AS pu_urun_hizmet_id,
    CONVERT(VARCHAR(16), pu.PazaryeriUrunleri_SonSenkronTarihi, 120) AS son_guncelleme
";

// ── AJAX ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // Özet kutular — tek tablodan tek sorgu
            case 'stats':
                $kId = (int)($_POST['kanal_id'] ?? 0);
                $r = $db->fetchOne("
                    SELECT COUNT(*) AS toplam,
                           SUM(CASE WHEN o = 'approved' THEN 1 ELSE 0 END) AS onayli,
                           SUM(CASE WHEN o = 'waiting'  THEN 1 ELSE 0 END) AS bekleyen,
                           SUM(CASE WHEN o = 'rejected' THEN 1 ELSE 0 END) AS reddedilen
                    FROM (SELECT " . ONAY_SQL . " AS o FROM PazaryeriUrunleri pu
                          WHERE pu.Durum = 1" . ($kId ? ' AND pu.kanal_id = ?' : '') . ") t
                ", $kId ? [$kId] : []);
                echo json_encode(['success' => true, 'data' => [
                    'toplam'     => (int)($r['toplam'] ?? 0),
                    'onaylı'     => (int)($r['onayli'] ?? 0),
                    'bekleyen'   => (int)($r['bekleyen'] ?? 0),
                    'reddedilen' => (int)($r['reddedilen'] ?? 0),
                ]]);
                break;

            // Client-side liste (tanım niteliğinde, yavaş büyüyen tablo — karar 23.09.2026)
            case 'list':
                $where  = ['pu.Durum = 1'];
                $params = [];

                $kId = (int)($_POST['filtre_kanal'] ?? 0);
                if ($kId) { $where[] = 'pu.kanal_id = ?'; $params[] = $kId; }

                $onay = trim($_POST['filtre_onay'] ?? '');
                if (in_array($onay, ['approved', 'waiting', 'rejected'], true)) { $where[] = ONAY_SQL . ' = ?'; $params[] = $onay; }

                $arama = trim($_POST['filtre_arama'] ?? '');
                if ($arama !== '') {
                    $where[] = '(pu.PazaryeriUrunleri_Adi LIKE ? OR pu.PazaryeriUrunleri_Barkod LIKE ? OR pu.PazaryeriUrunleri_StokKodu LIKE ?)';
                    array_push($params, "%$arama%", "%$arama%", "%$arama%");
                }

                $rows = $db->fetchAll("
                    SELECT " . URUN_KOLONLARI . ",
                           " . ONAY_SQL . " AS pu_onay_durumu,
                           ek.Kanal_Adi,
                           p.Pazaryerleri_Ad   AS Entegrasyon_Adi,
                           p.Pazaryerleri_Renk AS Entegrasyon_Renk
                    FROM PazaryeriUrunleri pu
                    INNER JOIN EntegrasyonKanallari ek ON ek.Kanal_Id = pu.kanal_id
                    INNER JOIN tanim_Pazaryerleri p    ON p.Pazaryerleri_id = pu.Pazaryerleri_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY pu.PazaryeriUrunleri_SonSenkronTarihi DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'cek_urunler':
                // Tüm kanallar (Trendyol ~1000 birim + HB katalog) 60 sn'yi aşar; istemci zaman aşımı 5 dk
                set_time_limit(300);
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                // Kanal seçilmediyse ürün listeleme yapabilen tüm aktif kanallar
                $hedefler = $kanalId ? [$kanalId] : array_column($kanallar, 'kanal_id');

                $t = ['toplam' => 0, 'yeni' => 0, 'guncellenen' => 0, 'hata' => 0];
                $hatalar = [];
                foreach ($hedefler as $kid) {
                    $api = PazaryeriFabrika::olusturKanaldan((int)$kid, $user['kullanici_id']);
                    if (!$api || !$api->hazirMi()) { $hatalar[] = PazaryeriFabrika::sonHata(); continue; }
                    $r = $api->urunleriSenkronla();
                    if (!$r['basarili']) {
                        // Desteklemeyen pazaryeri sayılmaz, gerçek hata raporlanır
                        if (strpos($r['hata'], 'desteklemiyor') === false) $hatalar[] = $api->getKanalAdi() . ': ' . $r['hata'];
                        continue;
                    }
                    $t['toplam']      += (int)$r['adet'];
                    $t['yeni']        += (int)($r['yeni'] ?? 0);
                    $t['guncellenen'] += (int)($r['guncellenen'] ?? 0);
                    $t['hata']        += (int)($r['hatali'] ?? 0);
                }
                if ($hatalar && $t['toplam'] === 0) throw new Exception(implode(' | ', $hatalar));
                $t['hata'] += count($hatalar);

                echo json_encode(['success' => true] + $t);
                break;

            case 'urun_ara':
                $q = trim($_POST['q'] ?? '');
                if (mb_strlen($q) < 2) { echo json_encode(['success' => true, 'data' => []]); break; }
                $rows = $db->fetchAll("
                    SELECT TOP 30
                           uh.urun_hizmet_id, uh.urun_hizmet_adi, uh.urun_hizmet_kodu,
                           uh.urun_hizmet_barkod, uh.urun_hizmet_satis_fiyati,
                           uh.urun_hizmet_taban_fiyati, uh.Urun_Stok_Miktari, uh.urun_hizmet_gorsel_url,
                           kdv.kdv_oran
                    FROM Urun_Hizmet uh
                    LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                    WHERE uh.urun_hizmet_durum = 1
                      AND (uh.urun_hizmet_adi LIKE ? OR uh.urun_hizmet_kodu LIKE ? OR uh.urun_hizmet_barkod LIKE ?)
                    ORDER BY uh.urun_hizmet_adi
                ", ["%$q%", "%$q%", "%$q%"]);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            // Yerel ürünü pazaryeri listesine ekle (onay bekliyor); pazaryerine gönderim ayrı iştir
            case 'urun_ekle':
                $kanalId   = (int)($_POST['kanal_id'] ?? 0);
                $uhId      = (int)($_POST['urun_hizmet_id'] ?? 0);
                $listFiyat = (float)($_POST['list_fiyat'] ?? 0);
                $satFiyat  = (float)($_POST['sat_fiyat'] ?? 0);
                $stok      = (int)($_POST['stok'] ?? 0);
                $modelKodu = trim($_POST['model_kodu'] ?? '');
                $renk      = trim($_POST['renk']       ?? '');
                $aciklama  = trim($_POST['aciklama']   ?? '');

                if (!$kanalId || !$uhId) throw new Exception('Kanal veya ürün seçilmedi.');

                // KDV elle girilmez: sistem ürününün KDV tanımından gelir
                $kdvRow   = $db->fetchOne("SELECT kdv.kdv_oran FROM Urun_Hizmet uh
                                           INNER JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                                           WHERE uh.urun_hizmet_id = ?", [$uhId]);
                $kdvOrani = $kdvRow['kdv_oran'] ?? null;

                $kanal = current(array_filter($kanallar, fn($k) => (int)$k['kanal_id'] === $kanalId));
                if (!$kanal) throw new Exception('Kanal bulunamadı veya aktif değil.');

                $uh = $db->fetchOne("SELECT * FROM Urun_Hizmet WHERE urun_hizmet_id = ?", [$uhId]);
                if (!$uh) throw new Exception('Ürün bulunamadı.');

                $barkod = $uh['urun_hizmet_barkod'] ?: $uh['urun_hizmet_kodu'];

                // Aynı kanalda bu yerel ürün başka bir pazaryeri ürününe bağlıysa (tekil kural) uyar
                $baska = $db->fetchOne("SELECT PazaryeriUrunleri_Barkod AS b FROM PazaryeriUrunleri
                                        WHERE kanal_id = ? AND urun_hizmet_id = ? AND PazaryeriUrunleri_Barkod <> ?", [$kanalId, $uhId, $barkod]);
                if ($baska) throw new Exception("Bu ürün bu kanalda zaten {$baska['b']} barkodlu pazaryeri ürününe bağlı.");

                $veri = [
                    'urun_hizmet_id'                => $uhId,
                    'PazaryeriUrunleri_StokKodu'    => $uh['urun_hizmet_kodu'],
                    'PazaryeriUrunleri_AnaUrunKodu' => $modelKodu ?: null,
                    'PazaryeriUrunleri_Adi'         => $uh['urun_hizmet_adi'],
                    'PazaryeriUrunleri_Renk'        => $renk ?: null,
                    'PazaryeriUrunleri_Aciklama'    => $aciklama ?: null,
                    'PazaryeriUrunleri_KdvOran'     => $kdvOrani,
                    'PazaryeriUrunleri_ListeFiyat'  => $listFiyat ?: $uh['urun_hizmet_taban_fiyati'],
                    'PazaryeriUrunleri_SatisFiyat'  => $satFiyat  ?: $uh['urun_hizmet_satis_fiyati'],
                    'PazaryeriUrunleri_Stok'        => $stok,
                    'PazaryeriUrunleri_GorselUrl'   => $uh['urun_hizmet_gorsel_url'],
                    'PazaryeriUrunleri_Onayli'      => 0,
                    'PazaryeriUrunleri_HamDurum'    => 'pendingApproval',
                    'GuncelleyenKullanici'          => $user['kullanici_id'],
                    'GuncellemeTarihi'              => date('Y-m-d H:i:s'),
                ];

                $mevcut = $db->fetchOne("SELECT PazaryeriUrunleri_id AS id FROM PazaryeriUrunleri WHERE kanal_id = ? AND PazaryeriUrunleri_Barkod = ?", [$kanalId, $barkod]);
                if ($mevcut) {
                    $db->update('PazaryeriUrunleri', $veri, ['PazaryeriUrunleri_id' => $mevcut['id']]);
                } else {
                    $db->insert('PazaryeriUrunleri', $veri + [
                        'kanal_id'                       => $kanalId,
                        'Pazaryerleri_id'                => $kanal['Pazaryerleri_id'],
                        'PazaryeriUrunleri_Barkod'       => $barkod,
                        'PazaryeriUrunleri_SenkronDurum' => 'beklemede',
                        'OlusturanKullanici'             => $user['kullanici_id'],
                        'OlusturmaTarihi'                => date('Y-m-d H:i:s'),
                        'Durum'                          => 1,
                    ]);
                }
                echo json_encode(['success' => true, 'message' => 'Ürün kaydedildi.']);
                break;

            case 'urun_guncelle':
                $puId      = (int)($_POST['pu_id'] ?? 0);
                $listFiyat = (float)($_POST['list_fiyat'] ?? 0);
                $satFiyat  = (float)($_POST['sat_fiyat'] ?? 0);
                $stok      = (int)($_POST['stok'] ?? 0);
                $gonder    = (int)($_POST['trendyol_gonder'] ?? 0);   // form alanı adı korunur (pazaryerine gönder)
                $modelKodu = trim($_POST['model_kodu'] ?? '');
                $stokKodu  = trim($_POST['stok_kodu']  ?? '');
                $renk      = trim($_POST['renk']       ?? '');
                $aciklama  = trim($_POST['aciklama']   ?? '');
                $uhId      = (int)($_POST['urun_hizmet_id'] ?? 0) ?: null;

                $pu = $db->fetchOne("SELECT PazaryeriUrunleri_id AS id, kanal_id, PazaryeriUrunleri_Barkod AS barkod,
                                            PazaryeriUrunleri_KdvOran AS kdv
                                     FROM PazaryeriUrunleri WHERE PazaryeriUrunleri_id = ?", [$puId]);
                if (!$pu) throw new Exception('Kayıt bulunamadı.');

                // KDV elle girilmez: eşli sistem ürününün KDV tanımından gelir, eşleşme yoksa mevcut değer korunur
                $kdvOrani = $pu['kdv'];
                if ($uhId) {
                    $kdvRow = $db->fetchOne("SELECT kdv.kdv_oran FROM Urun_Hizmet uh
                                             INNER JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                                             WHERE uh.urun_hizmet_id = ?", [$uhId]);
                    if ($kdvRow) $kdvOrani = $kdvRow['kdv_oran'];
                }

                // Stok kodu yoksa Urun_Hizmet tablosundan bul
                if ($uhId && !$stokKodu) {
                    $uhRow = $db->fetchOne("SELECT urun_hizmet_kodu FROM Urun_Hizmet WHERE urun_hizmet_id = ?", [$uhId]);
                    $stokKodu = $uhRow['urun_hizmet_kodu'] ?? '';
                }

                // Tekil kural: bir yerel ürün aynı kanalda tek pazaryeri ürününe bağlanabilir
                if ($uhId) {
                    $baska = $db->fetchOne("SELECT PazaryeriUrunleri_Barkod AS b FROM PazaryeriUrunleri
                                            WHERE kanal_id = ? AND urun_hizmet_id = ? AND PazaryeriUrunleri_id <> ?", [$pu['kanal_id'], $uhId, $puId]);
                    if ($baska) throw new Exception("Seçilen yerel ürün bu kanalda zaten {$baska['b']} barkodlu ürüne bağlı.");
                }

                $db->update('PazaryeriUrunleri', [
                    'PazaryeriUrunleri_ListeFiyat'    => $listFiyat,
                    'PazaryeriUrunleri_SatisFiyat'    => $satFiyat,
                    'PazaryeriUrunleri_Stok'          => $stok,
                    'PazaryeriUrunleri_AnaUrunKodu'   => $modelKodu ?: null,
                    'PazaryeriUrunleri_StokKodu'      => $stokKodu ?: null,
                    'PazaryeriUrunleri_Renk'          => $renk ?: null,
                    'PazaryeriUrunleri_Aciklama'      => $aciklama ?: null,
                    'PazaryeriUrunleri_KdvOran'       => $kdvOrani,
                    'urun_hizmet_id'                  => $uhId,
                    'GuncelleyenKullanici'            => $user['kullanici_id'],
                    'GuncellemeTarihi'                => date('Y-m-d H:i:s'),
                ], ['PazaryeriUrunleri_id' => $puId]);

                // Pazaryerine stok/fiyat gönderimi — batch ile takip edilir (sonuç cron'da işlenir)
                $apiSonuc = null;
                if ($gonder) {
                    $api = PazaryeriFabrika::olusturKanaldan((int)$pu['kanal_id'], $user['kullanici_id']);
                    if (!$api || !$api->hazirMi()) {
                        $apiSonuc = ['success' => false, 'message' => $api ? $api->getHata() : PazaryeriFabrika::sonHata()];
                    } else {
                        $s = $api->fiyatStokGuncelle([[
                            'barcode'   => $pu['barkod'],
                            'quantity'  => $stok,
                            'salePrice' => $satFiyat,
                            'listPrice' => $listFiyat,
                        ]]);
                        $apiSonuc = [
                            'success' => $s['basarili'],
                            'message' => $s['basarili'] ? 'İşlem sıraya alındı (batch ' . ($s['batchId'] ?? '') . ')' : $s['hata'],
                        ];
                        if ($s['basarili']) {
                            $db->update('PazaryeriUrunleri', [
                                'PazaryeriUrunleri_SenkronDurum' => 'gonderildi',
                                'PazaryeriUrunleri_SonBatchId'   => $s['batchId'],
                            ], ['PazaryeriUrunleri_id' => $puId]);
                        }
                    }
                }
                echo json_encode(['success' => true, 'api' => $apiSonuc]);
                break;

            case 'urun_detay':
                $puId = (int)($_POST['pu_id'] ?? 0);
                $row  = $db->fetchOne("
                    SELECT " . URUN_KOLONLARI . ",
                           " . ONAY_SQL . " AS pu_onay_durumu,
                           uh.urun_hizmet_adi          AS uh_adi,
                           uh.urun_hizmet_satis_fiyati AS uh_satis,
                           uh.urun_hizmet_taban_fiyati AS uh_taban,
                           uh.Urun_Stok_Miktari        AS uh_stok,
                           kdv.kdv_oran                AS uh_kdv_oran
                    FROM PazaryeriUrunleri pu
                    LEFT JOIN Urun_Hizmet uh ON uh.urun_hizmet_id = pu.urun_hizmet_id
                    LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                    WHERE pu.PazaryeriUrunleri_id = ?
                ", [$puId]);

                echo json_encode(['success' => (bool)$row, 'data' => $row]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .urun-gorsel { width:40px; height:40px; object-fit:cover; border-radius:4px; border:1px solid #dee2e6; }
        .onay-approved { background:#d1e7dd; color:#0a3622; }
        .onay-waiting  { background:#fff3cd; color:#856404; }
        .onay-rejected { background:#f8d7da; color:#842029; }
        .onay-badge { font-size:.73rem; padding:3px 8px; border-radius:20px; font-weight:500; }
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-box-seam"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Ürün</span>
                                <span class="info-box-number" id="stat-toplam">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Onaylı</span>
                                <span class="info-box-number" id="stat-onaylı">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bekleyen</span>
                                <span class="info-box-number" id="stat-bekleyen">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Reddedilen</span>
                                <span class="info-box-number" id="stat-reddedilen">–</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card mb-3">
                    <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePaneli">
                        <h3 class="card-title"><i class="bi bi-funnel me-2"></i>Filtrele</h3>
                        <div class="card-tools"><button type="button" class="btn btn-tool"><i class="bi bi-chevron-down"></i></button></div>
                    </div>
                    <div class="collapse" id="filtrePaneli">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label small">Platform</label>
                                    <select class="form-select form-select-sm select2" id="filtre_kanal">
                                        <option value="">Tüm Platformlar</option>
                                        <?php foreach ($kanallar as $k): ?>
                                        <option value="<?= $k['Kanal_Id'] ?>"><?= htmlspecialchars($k['Entegrasyon_Adi'] . ' — ' . $k['Kanal_Adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Onay Durumu</label>
                                    <select class="form-select form-select-sm select2" id="filtre_onay">
                                        <option value="">Tümü</option>
                                        <option value="approved">Onaylı</option>
                                        <option value="waiting">Bekleyen</option>
                                        <option value="rejected">Reddedilen</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Ara (ürün adı, barkod, stok kodu)</label>
                                    <input type="text" class="form-control form-control-sm" id="filtre_arama" placeholder="Ara…">
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-primary btn-sm" onclick="listeYukle()"><i class="bi bi-search me-1"></i>Filtrele</button>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="filtreTemizle()"><i class="bi bi-x-circle me-1"></i>Temizle</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h3 class="card-title me-auto"><i class="bi bi-table me-2"></i>Ürünler</h3>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm" onclick="urunEkleAc()">
                                <i class="bi bi-plus-circle me-1"></i>Ürün Ekle
                            </button>
                            <button class="btn btn-success btn-sm" id="btnCekUrunler" onclick="cekUrunler()">
                                <i class="bi bi-cloud-download me-1"></i>Ürünleri Çek
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table id="urunTable" class="table table-bordered table-striped table-sm mb-0">
                            <thead>
                            <tr>
                                    <th style="width:50px">Görsel</th>
                                    <th>Platform</th>
                                    <th>Ürün Adı</th>
                                    <th>Barkod</th>
                                    <th>Stok Kodu</th>
                                    <th>Model Kodu</th>
                                    <th>Renk</th>
                                    <th>Kategori</th>
                                    <th>Marka</th>
                                    <th>Fiyat</th>
                                    <th>Stok</th>
                                    <th>Onay</th>
                                    <th>Güncelleme</th>
                                    <th style="width:80px"></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- MODAL: Ürün Ekle -->
<div class="modal fade" id="urunEkleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Pazaryerine Ürün Ekle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Platform <span class="text-danger">*</span></label>
            <select class="form-select select2m" id="ekle_kanal">
              <option value="">Seçin…</option>
              <?php foreach ($kanallar as $k): ?>
              <option value="<?= $k['Kanal_Id'] ?>"><?= htmlspecialchars($k['Entegrasyon_Adi'].' — '.$k['Kanal_Adi']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Ürün Ara (Urun_Hizmet) <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="ekle_urun_ara" placeholder="Ürün adı, barkod veya kod…" autocomplete="off">
            <input type="hidden" id="ekle_urun_id">
            <div id="ekle_urun_sonuclar" class="list-group mt-1" style="display:none;max-height:180px;overflow-y:auto;position:absolute;z-index:9999;width:calc(50% - 24px)"></div>
          </div>
          <div class="col-12" id="ekle_urun_bilgi" style="display:none">
            <div class="alert alert-info py-2 mb-0 small">
              <strong id="ekle_adi"></strong> &mdash; Barkod: <span id="ekle_barkod"></span><br>
              Sistem Stok: <strong id="ekle_uh_stok"></strong> &nbsp;|&nbsp;
              Liste Fiyat: <strong id="ekle_uh_taban"></strong> ₺ &nbsp;|&nbsp;
              Satış Fiyat: <strong id="ekle_uh_satis"></strong> ₺
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Liste Fiyatı (₺)</label>
            <input type="number" step="0.01" class="form-control" id="ekle_list_fiyat" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label class="form-label">Satış Fiyatı (₺)</label>
            <input type="number" step="0.01" class="form-control" id="ekle_sat_fiyat" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label class="form-label">Stok Miktarı</label>
            <input type="number" class="form-control" id="ekle_stok" placeholder="0">
          </div>
          <div class="col-md-6">
            <label class="form-label">Model Kodu</label>
            <input type="text" class="form-control" id="ekle_model_kodu" placeholder="örn. MODEL-001">
          </div>
          <div class="col-md-6">
            <label class="form-label">Renk</label>
            <input type="text" class="form-control" id="ekle_renk" placeholder="örn. Siyah">
          </div>
          <div class="col-md-6">
            <label class="form-label">KDV <small class="text-muted">(otomatik)</small></label>
            <input type="text" class="form-control bg-light" id="ekle_kdv_orani" readonly placeholder="Ürün seçince dolacak"
                   title="KDV, seçilen sistem ürününün KDV tanımından gelir">
          </div>
          <div class="col-12">
            <label class="form-label">Açıklama <small class="text-muted">(isteğe bağlı)</small></label>
            <textarea class="form-control" id="ekle_aciklama" rows="3" placeholder="Ürün açıklaması..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
        <button type="button" class="btn btn-primary" onclick="urunEkleKaydet()"><i class="bi bi-save me-1"></i>Kaydet</button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: Ürün Güncelle -->
<div class="modal fade" id="urunGuncelleModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Ürün Güncelle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="gunc_pu_id">
        <div id="gunc_uh_bilgi" class="alert alert-secondary py-2 mb-3 small" style="display:none">
          <i class="bi bi-link-45deg me-1"></i>Sistem (Urun_Hizmet) değerleri —
          Satış: <strong id="gunc_uh_satis"></strong> ₺ &nbsp;|&nbsp;
          Liste (satış+%20): <strong id="gunc_uh_taban"></strong> ₺ &nbsp;|&nbsp;
          Stok: <strong id="gunc_uh_stok"></strong>
        </div>
        <div class="row g-3">
          <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
              <strong id="gunc_adi" class="d-block mb-1"></strong>
              <code id="gunc_barkod" class="small"></code>
            </div>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="sistemdenDoldur()" id="btnSistemdenDoldur" style="display:none">
              <i class="bi bi-arrow-down-circle me-1"></i>Sistemden Doldur
            </button>
          </div>
          <div class="col-12">
            <label class="form-label">Sistem Ürünü <small class="text-muted">(Urun_Hizmet ile eşleştir)</small></label>
            <select id="gunc_uh_select" class="form-select"></select>
            <input type="hidden" id="gunc_urun_hizmet_id">
          </div>
          <div class="col-md-6">
            <label class="form-label">Stok Kodu <small class="text-muted">(otomatik)</small></label>
            <input type="text" class="form-control bg-light" id="gunc_stok_kodu" readonly placeholder="Ürün seçince dolacak">
          </div>
          <div class="col-md-6">
            <label class="form-label">Model Kodu</label>
            <input type="text" class="form-control" id="gunc_model_kodu">
          </div>
          <div class="col-md-4">
            <label class="form-label">Liste Fiyatı (₺)</label>
            <input type="number" step="0.01" class="form-control" id="gunc_list_fiyat">
          </div>
          <div class="col-md-4">
            <label class="form-label">Satış Fiyatı (₺)</label>
            <input type="number" step="0.01" class="form-control" id="gunc_sat_fiyat">
          </div>
          <div class="col-md-4">
            <label class="form-label">Stok</label>
            <input type="number" class="form-control" id="gunc_stok">
          </div>
          <div class="col-md-4">
            <label class="form-label">Renk</label>
            <input type="text" class="form-control" id="gunc_renk" placeholder="örn. Siyah">
          </div>
          <div class="col-md-4">
            <label class="form-label">KDV <small class="text-muted">(otomatik)</small></label>
            <input type="text" class="form-control bg-light" id="gunc_kdv_orani" readonly placeholder="—"
                   title="KDV, eşleşen sistem ürününün KDV tanımından gelir">

          </div>
          <div class="col-12">
            <label class="form-label">Açıklama <small class="text-muted">(pazaryerine gönderilmez, yerel kayıt)</small></label>
            <textarea class="form-control" id="gunc_aciklama" rows="4" placeholder="Ürün açıklaması..."></textarea>
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="gunc_trendyol_gonder" checked>
              <label class="form-check-label" for="gunc_trendyol_gonder">Pazaryerine de gönder</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
        <button type="button" class="btn btn-warning" onclick="urunGuncelleKaydet()"><i class="bi bi-send me-1"></i>Güncelle</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
let table;

const onayMap = {
    'approved': ['Onaylı',    'onay-approved'],
    'waiting':  ['Bekleyen',  'onay-waiting'],
    'rejected': ['Reddedilen','onay-rejected'],
};

function onayBadge(d) {
    const [label, cls] = onayMap[d] || [d ?? '—', 'onay-waiting'];
    return `<span class="onay-badge ${cls}">${escHtml(label)}</span>`;
}

function statYukle() {
    $.post('', { action: 'stats', kanal_id: $('#filtre_kanal').val() }, function(r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-onaylı').text(r.data['onaylı']);
        $('#stat-bekleyen').text(r.data.bekleyen);
        $('#stat-reddedilen').text(r.data.reddedilen);
    });
}

function listeYukle() {
    const payload = {
        action:       'list',
        filtre_kanal: $('#filtre_kanal').val(),
        filtre_onay:  $('#filtre_onay').val(),
        filtre_arama: $('#filtre_arama').val(),
    };

    if (table) table.destroy();

    $.post('', payload, function(r) {
        if (!r.success) { showToast('Veriler yüklenemedi.', 'danger'); return; }

        table = $('#urunTable').DataTable({
            data: r.data,
            destroy: true,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            order: [[9, 'desc']],
            pageLength: 25,
            columns: [
                { data: 'pu_gorsel_url', orderable: false, render: d =>
                    d ? `<img src="${escHtml(d)}" class="urun-gorsel" loading="lazy">` : '<i class="bi bi-image text-muted fs-5"></i>'
                },
                { data: 'Entegrasyon_Adi', render: (d, t, row) =>
                    `<span class="badge" style="background:${escHtml(row.Entegrasyon_Renk ?? '#666')}">${escHtml(d)}</span>`
                },
                { data: 'pu_adi', render: d => `<span title="${escHtml(d ?? '')}" class="d-block text-truncate" style="max-width:200px">${escHtml(d ?? '—')}</span>` },
                { data: 'pu_barkod', render: d => `<code class="small">${escHtml(d ?? '—')}</code>` },
                { data: 'pu_stok_kodu', render: d => `<small class="text-muted">${escHtml(d ?? '—')}</small>` },
                { data: 'pu_model_kodu', render: d => `<small class="text-muted">${escHtml(d ?? '—')}</small>` },
                { data: 'pu_renk', render: d => d ? `<span class="badge bg-secondary">${escHtml(d)}</span>` : '—' },
                { data: 'pu_kategori_adi', render: d => `<small class="text-muted">${escHtml(d ?? '—')}</small>` },
                { data: 'pu_marka_adi', render: d => escHtml(d ?? '—') },
                { data: 'pu_fiyat', render: (d, t, row) => {
                    if (!d) return '—';
                    let html = `<strong>${parseFloat(d).toLocaleString('tr-TR', {minimumFractionDigits:2})} ₺</strong>`;
                    if (row.pu_indirimli_fiyat && row.pu_indirimli_fiyat < d) {
                        html += `<br><small class="text-success">${parseFloat(row.pu_indirimli_fiyat).toLocaleString('tr-TR', {minimumFractionDigits:2})} ₺</small>`;
                    }
                    return html;
                }},
                { data: 'pu_stok', render: d => {
                    const n = parseInt(d ?? 0);
                    const cls = n <= 0 ? 'text-danger' : n <= 5 ? 'text-warning' : 'text-success';
                    return `<strong class="${cls}">${n}</strong>`;
                }},
                { data: 'pu_onay_durumu', render: d => onayBadge(d) },
                { data: 'son_guncelleme', render: d => `<small class="text-muted">${escHtml(d ?? '—')}</small>` },
                { data: 'pu_id', orderable: false, render: d =>
                    `<button class="btn btn-xs btn-outline-warning btn-sm" title="Güncelle" onclick="urunGuncelleAc(${d})">
                        <i class="bi bi-pencil"></i>
                    </button>`
                },
            ]
        });
    });
}

function filtreTemizle() {
    $('#filtre_kanal, #filtre_onay').val('').trigger('change');
    $('#filtre_arama').val('');
    listeYukle();
    statYukle();
}

function cekUrunler() {
    const btn     = $('#btnCekUrunler');
    const kanalId = $('#filtre_kanal').val();

    Swal.fire({
        title: 'Ürünleri Çek',
        text:  'Aktif pazaryerlerinden tüm ürünler çekilecek. Bu işlem birkaç dakika sürebilir.',
        icon:  'question',
        showCancelButton: true,
        confirmButtonText: 'Evet, Çek',
        cancelButtonText:  'İptal',
    }).then(result => {
        if (!result.isConfirmed) return;

        btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Çekiliyor…');

        $.ajax({
            url:     '',
            method:  'POST',
            data:    { action: 'cek_urunler', kanal_id: kanalId },
            timeout: 300000, // 5 dk timeout
            success: function(r) {
                btn.prop('disabled', false).html('<i class="bi bi-cloud-download me-1"></i>Ürünleri Çek');

                if (!r.success) { showToast('Hata: ' + r.message, 'danger'); return; }

                Swal.fire({
                    icon: 'success',
                    title: 'Tamamlandı',
                    html: `<table class="table table-sm text-start mt-2">
                        <tr><td>Toplam İşlenen</td><td><strong>${r.toplam}</strong></td></tr>
                        <tr><td>Yeni Eklenen</td><td class="text-success"><strong>${r.yeni}</strong></td></tr>
                        <tr><td>Güncellenen</td><td class="text-info"><strong>${r.guncellenen}</strong></td></tr>
                        <tr><td>Hatalı</td><td class="text-danger"><strong>${r.hata}</strong></td></tr>
                    </table>`,
                    confirmButtonText: 'Tamam',
                }).then(() => { statYukle(); listeYukle(); });
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="bi bi-cloud-download me-1"></i>Ürünleri Çek');
                showToast('Sunucu hatası oluştu.', 'danger');
            }
        });
    });
}

// ── EKLE MODAL ───────────────────────────────────────────────────────────
const ekleModal   = new bootstrap.Modal(document.getElementById('urunEkleModal'));
const guncModal   = new bootstrap.Modal(document.getElementById('urunGuncelleModal'));
let   ekleTimer   = null;
let   seciliUrun  = null;

function urunEkleAc() {
    $('#ekle_kanal').val('').trigger('change');
    $('#ekle_urun_ara').val('');
    $('#ekle_urun_id').val('');
    $('#ekle_urun_bilgi').hide();
    $('#ekle_urun_sonuclar').hide();
    $('#ekle_list_fiyat,#ekle_sat_fiyat,#ekle_stok').val('');
    $('#ekle_model_kodu,#ekle_renk').val('');
    $('#ekle_aciklama,#ekle_kdv_orani').val('');
    seciliUrun = null;
    ekleModal.show();
}

$('#ekle_urun_ara').on('input', function() {
    clearTimeout(ekleTimer);
    const q = $(this).val().trim();
    if (q.length < 2) { $('#ekle_urun_sonuclar').hide(); return; }
    ekleTimer = setTimeout(() => {
        $.post('', { action:'urun_ara', q }, function(r) {
            const $s = $('#ekle_urun_sonuclar').empty().show();
            if (!r.success || !r.data.length) { $s.append('<a class="list-group-item list-group-item-action disabled small">Sonuç bulunamadı</a>'); return; }
            r.data.forEach(u => {
                $('<a class="list-group-item list-group-item-action small">').text(u.urun_hizmet_adi + ' — ' + (u.urun_hizmet_barkod || u.urun_hizmet_kodu))
                    .on('click', function() {
                        seciliUrun = u;
                        $('#ekle_urun_ara').val(u.urun_hizmet_adi);
                        $('#ekle_urun_id').val(u.urun_hizmet_id);
                        $('#ekle_adi').text(u.urun_hizmet_adi);
                        $('#ekle_barkod').text(u.urun_hizmet_barkod || u.urun_hizmet_kodu);
                        $('#ekle_uh_stok').text(u.Urun_Stok_Miktari ?? 0);
                        $('#ekle_uh_taban').text(parseFloat(u.urun_hizmet_taban_fiyati || 0).toFixed(2));
                        $('#ekle_uh_satis').text(parseFloat(u.urun_hizmet_satis_fiyati || 0).toFixed(2));
                        $('#ekle_list_fiyat').val(parseFloat(u.urun_hizmet_taban_fiyati || 0).toFixed(2));
                        $('#ekle_sat_fiyat').val(parseFloat(u.urun_hizmet_satis_fiyati || 0).toFixed(2));
                        $('#ekle_stok').val(Math.floor(u.Urun_Stok_Miktari || 0));
                        $('#ekle_kdv_orani').val(kdvGoster(u.kdv_oran));
                        $('#ekle_urun_bilgi').show();
                        $s.hide();
                    }).appendTo($s);
            });
        });
    }, 300);
});

function urunEkleKaydet() {
    const kanalId = $('#ekle_kanal').val();
    const uhId    = $('#ekle_urun_id').val();
    if (!kanalId || !uhId) { showToast('Platform ve ürün seçimi zorunludur.', 'warning'); return; }
    $.post('', {
        action:'urun_ekle', kanal_id:kanalId, urun_hizmet_id:uhId,
        list_fiyat:$('#ekle_list_fiyat').val(),
        sat_fiyat:$('#ekle_sat_fiyat').val(),
        stok:$('#ekle_stok').val(),
        model_kodu:$('#ekle_model_kodu').val(),
        renk:$('#ekle_renk').val(),
        aciklama:$('#ekle_aciklama').val(),
    }, function(r) {
        if (!r.success) { showToast('Hata: '+(r.message||''), 'danger'); return; }
        showToast('Ürün kaydedildi.', 'success');
        ekleModal.hide();
        statYukle(); listeYukle();
    });
}

// ── GÜNCELLE MODAL ───────────────────────────────────────────────────────
function urunGuncelleAc(puId) {
    $.post('', { action:'urun_detay', pu_id:puId }, function(r) {
        if (!r.success || !r.data) { showToast('Kayıt bulunamadı.','danger'); return; }
        const d = r.data;
        $('#gunc_pu_id').val(d.pu_id);
        $('#gunc_adi').text(d.pu_adi || d.uh_adi || '—');
        $('#gunc_barkod').text(d.pu_barkod ?? '—');
        $('#gunc_list_fiyat').val(parseFloat(d.pu_fiyat || 0).toFixed(2));
        $('#gunc_sat_fiyat').val(parseFloat(d.pu_indirimli_fiyat || 0).toFixed(2));
        $('#gunc_stok').val(d.pu_stok ?? 0);
        $('#gunc_stok_kodu').val(d.pu_stok_kodu ?? '');
        $('#gunc_model_kodu').val(d.pu_model_kodu ?? '');
        $('#gunc_renk').val(d.pu_renk ?? '');
        $('#gunc_aciklama').val(d.pu_aciklama ?? '');
        // KDV salt okunur: eşli sistem ürününün KDV tanımı, yoksa pazaryerinden gelen oran
        const kdvKaynak = (d.uh_kdv_oran !== null && d.uh_kdv_oran !== undefined) ? d.uh_kdv_oran : d.pu_kdv_orani;
        $('#gunc_kdv_orani').data('pazaryeri', d.pu_kdv_orani).val(kdvGoster(kdvKaynak));

        // Select2 sıfırla
        $('#gunc_uh_select').val(null).trigger('change');
        $('#gunc_urun_hizmet_id').val('');

        // Mevcut Urun_Hizmet bağlantısı varsa Select2'ye ön yükle
        if (d.pu_urun_hizmet_id && d.uh_adi) {
            const label = d.uh_adi + (d.pu_stok_kodu ? ' — ' + d.pu_stok_kodu : '');
            if (!$('#gunc_uh_select').find('option[value="' + d.pu_urun_hizmet_id + '"]').length) {
                $('#gunc_uh_select').append(new Option(label, d.pu_urun_hizmet_id, true, true));
            }
            $('#gunc_uh_select').val(d.pu_urun_hizmet_id).trigger('change');
            $('#gunc_urun_hizmet_id').val(d.pu_urun_hizmet_id);
        }

        // Urun_Hizmet bağlantısı varsa sistem değerlerini göster
        if (d.uh_adi) {
            const uhSatis = parseFloat(d.uh_satis || 0);
            const uhTaban = (uhSatis * 1.20).toFixed(2);
            $('#gunc_uh_satis').text(uhSatis.toFixed(2));
            $('#gunc_uh_taban').text(uhTaban);
            $('#gunc_uh_stok').text(d.uh_stok ?? 0);
            $('#gunc_uh_bilgi').show();
            $('#btnSistemdenDoldur').show()
                .data('uh_satis', uhSatis)
                .data('uh_taban', uhTaban)
                .data('uh_stok', d.uh_stok ?? 0);
        } else {
            $('#gunc_uh_bilgi').hide();
            $('#btnSistemdenDoldur').hide();
        }
        guncModal.show();
    });
}

function sistemdenDoldur() {
    const btn = $('#btnSistemdenDoldur');
    $('#gunc_list_fiyat').val(parseFloat(btn.data('uh_taban')).toFixed(2));
    $('#gunc_sat_fiyat').val(parseFloat(btn.data('uh_satis')).toFixed(2));
    $('#gunc_stok').val(btn.data('uh_stok'));
    showToast('Sistem değerleri dolduruldu.', 'info');
}

function urunGuncelleKaydet() {
    const puId   = $('#gunc_pu_id').val();
    const gonder = $('#gunc_trendyol_gonder').is(':checked') ? 1 : 0;
    $.post('', {
        action:'urun_guncelle', pu_id:puId,
        list_fiyat:$('#gunc_list_fiyat').val(),
        sat_fiyat:$('#gunc_sat_fiyat').val(),
        stok:$('#gunc_stok').val(),
        trendyol_gonder:gonder,
        model_kodu:$('#gunc_model_kodu').val(),
        stok_kodu:$('#gunc_stok_kodu').val(),
        renk:$('#gunc_renk').val(),
        aciklama:$('#gunc_aciklama').val(),
        urun_hizmet_id:$('#gunc_urun_hizmet_id').val(),
    }, function(r) {
        if (!r.success) { showToast('Hata: '+(r.message||''), 'danger'); return; }
        const apiMsg = r.api ? (r.api.success ? ' Pazaryeri: ✅' : ' Pazaryeri: ❌ '+r.api.message) : '';
        showToast('Güncellendi.' + apiMsg, r.api && !r.api.success ? 'warning' : 'success');
        guncModal.hide();
        statYukle(); listeYukle();
    });
}

function kdvGoster(oran) {
    return (oran === null || oran === undefined || oran === '') ? '' : '%' + parseInt(oran);
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

$(function() {
    // custom.js tüm .form-select'leri otomatik Select2 yapar; sayfaya özgü ayarla yeniden
    // başlatırken ikinci bir kutu oluşmasın diye önce otomatik olan kaldırılır.
    const select2Temizle = $el => $el.each(function() {
        const $s = $(this);
        if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
        $s.nextAll('.select2-container').remove();
    });

    select2Temizle($('.select2')).select2({ theme: 'bootstrap-5', width: '100%' });
    select2Temizle($('.select2m')).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#urunEkleModal') });

    // Güncelle modal — Urun_Hizmet AJAX arama
    select2Temizle($('#gunc_uh_select')).select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#urunGuncelleModal'),
        minimumInputLength: 1,
        placeholder: 'Ürün adı veya kodu ile ara…',
        allowClear: true,
        language: { inputTooShort: () => 'En az 1 karakter girin' },
        ajax: {
            url: '',
            type: 'POST',
            dataType: 'json',
            delay: 300,
            data: params => ({ action: 'urun_ara', q: params.term }),
            processResults: data => ({
                results: (data.data || []).map(u => ({
                    id: u.urun_hizmet_id,
                    text: u.urun_hizmet_adi + ' — ' + (u.urun_hizmet_kodu || '—'),
                    kodu: u.urun_hizmet_kodu || '',
                    satis: u.urun_hizmet_satis_fiyati,
                    taban: u.urun_hizmet_taban_fiyati,
                    stok: parseInt(u.Urun_Stok_Miktari || 0),
                    kdvOran: u.kdv_oran !== null && u.kdv_oran !== undefined ? String(parseInt(u.kdv_oran)) : '',
                }))
            }),
            cache: true
        },
        templateResult: function(item) {
            if (!item.id) return item.text; // placeholder/loading
            const stok = parseInt(item.stok || 0);
            const stokColor  = stok > 0 ? '#198754' : '#dc3545';
            const stokBg     = stok > 0 ? '#d1e7dd' : '#f8d7da';
            const stokLabel  = stok > 0 ? '✔ ' + stok + ' adet' : '✘ Stok yok';
            return $(`<div class="d-flex justify-content-between align-items-center py-1">
                <span>${escHtml(item.text)}</span>
                <span style="font-size:.78em;font-weight:600;color:${stokColor};background:${stokBg};padding:2px 7px;border-radius:10px;white-space:nowrap;margin-left:8px">${stokLabel}</span>
            </div>`);
        },
        templateSelection: function(item) {
            if (!item.id) return item.text;
            const stok = parseInt(item.stok || 0);
            const icon = stok > 0 ? '🟢' : '🔴';
            return item.text + ' ' + icon + ' (' + stok + ' adet)';
        },
    }).on('select2:select', function(e) {
        const d = e.params.data;
        $('#gunc_urun_hizmet_id').val(d.id);
        $('#gunc_stok_kodu').val(d.kodu);
        // KDV sistem ürününün tanımından gelir (değiştirilemez)
        $('#gunc_kdv_orani').val(kdvGoster(d.kdvOran));
        // Sistem fiyat/stok bilgisini güncelle
        const uhSatis = parseFloat(d.satis || 0);
        const uhTaban = (uhSatis * 1.20).toFixed(2);
        $('#gunc_uh_satis').text(uhSatis.toFixed(2));
        $('#gunc_uh_taban').text(uhTaban);
        $('#gunc_uh_stok').text(d.stok ?? 0);
        $('#gunc_uh_bilgi').show();
        $('#btnSistemdenDoldur').show()
            .data('uh_satis', uhSatis)
            .data('uh_taban', uhTaban)
            .data('uh_stok', d.stok ?? 0);
    }).on('select2:clear', function() {
        $('#gunc_urun_hizmet_id').val('');
        $('#gunc_stok_kodu').val('');
        $('#gunc_kdv_orani').val(kdvGoster($('#gunc_kdv_orani').data('pazaryeri')));
        $('#gunc_uh_bilgi').hide();
        $('#btnSistemdenDoldur').hide();
    });

    statYukle();
    listeYukle();
    $('#filtre_arama').on('keypress', function(e) { if (e.which === 13) listeYukle(); });
    $('#filtre_kanal').on('change', function() { statYukle(); });
});
</script>
</body>
</html>
