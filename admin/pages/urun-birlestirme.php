<?php
/**
 * Admin Panel - Ürün Birleştirme
 * Mükerrer açılmış ürünlerin kontrollü olarak tek ürüne birleştirilmesi ve geri alınması
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UrunBirlestirmeHelper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::check()) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Oturum süresi doldu.', 'redirect' => '/Admin/login.php']));
    }
} else {
    requireAuth();
}

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ürün Birleştirme';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$permissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'OrnekFirma Portal';

$helper = new UrunBirlestirmeHelper($db);

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    $normExpr = UrunBirlestirmeHelper::normKodSql('u.urun_hizmet_kodu');

    try {
        switch ($action) {

            case 'stats':
                $grup = $db->fetchOne("
                    WITH N AS (
                        SELECT $normExpr AS NormKod
                        FROM dbo.Urun_Hizmet u
                        WHERE u.urun_hizmet_tip = 1 AND u.urun_hizmet_durum = 1 AND ISNULL(u.urun_hizmet_kodu, '') <> ''
                    )
                    SELECT COUNT(*) AS grup, ISNULL(SUM(Adet - 1), 0) AS bekleyen
                    FROM (SELECT NormKod, COUNT(*) AS Adet FROM N GROUP BY NormKod HAVING COUNT(*) > 1) g
                ");
                $birlestirme = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.UrunBirlestirmeLog WHERE Durum = 1 AND UrunBirlestirmeLog_GeriAlindi = 0");
                $negatif     = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Urun_Hizmet WHERE urun_hizmet_tip = 1 AND urun_hizmet_durum = 1 AND Urun_Stok_Miktari < 0");

                echo json_encode(['success' => true, 'data' => [
                    'grup'        => (int)($grup['grup'] ?? 0),
                    'bekleyen'    => (int)($grup['bekleyen'] ?? 0),
                    'birlestirme' => (int)($birlestirme['sayi'] ?? 0),
                    'negatif'     => (int)($negatif['sayi'] ?? 0),
                ]]);
                break;

            case 'list':
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25;

                $orderMap = [0 => 'NormKod', 1 => 'Adet', 3 => 'ToplamStok'];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
                $orderCol = $orderMap[$orderIdx] ?? 'NormKod';
                $orderDir = strtoupper($_POST['order'][0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

                $pasifDahil = !empty($_POST['pasif_dahil']);
                $search     = trim($_POST['search_text'] ?? '');
                $negatif    = !empty($_POST['negatif']);
                $kullanimli = !empty($_POST['kullanimli']);

                $cte = "
                    WITH N AS (
                        SELECT u.urun_hizmet_id, u.urun_hizmet_kodu, u.urun_hizmet_adi, u.urun_hizmet_durum,
                               ISNULL(u.Urun_Stok_Miktari, 0) AS Stok, $normExpr AS NormKod
                        FROM dbo.Urun_Hizmet u
                        WHERE u.urun_hizmet_tip = 1 AND ISNULL(u.urun_hizmet_kodu, '') <> ''" . ($pasifDahil ? '' : " AND u.urun_hizmet_durum = 1") . "
                    ),
                    G AS (
                        SELECT NormKod, COUNT(*) AS Adet, SUM(Stok) AS ToplamStok, MIN(Stok) AS MinStok
                        FROM N GROUP BY NormKod HAVING COUNT(*) > 1
                    )";

                $where  = [];
                $params = [];
                if ($search !== '') {
                    $where[] = "EXISTS (SELECT 1 FROM N x WHERE x.NormKod = G.NormKod AND (x.urun_hizmet_kodu LIKE ? OR x.urun_hizmet_adi LIKE ?))";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($negatif) {
                    $where[] = "G.MinStok < 0";
                }
                if ($kullanimli) {
                    $where[] = "EXISTS (SELECT 1 FROM N x WHERE x.NormKod = G.NormKod AND (
                                    EXISTS (SELECT 1 FROM dbo.Urun_Stok_Hareketleri h WHERE h.Urun_id = x.urun_hizmet_id)
                                 OR EXISTS (SELECT 1 FROM dbo.FaturalarGidenKalemleri fg WHERE fg.FaturalarGidenKalemleri_UrunHizmet_id = x.urun_hizmet_id)
                                 OR EXISTS (SELECT 1 FROM dbo.FaturalarGelenKalemleri fl WHERE fl.FaturalarGelenKalemleri_UrunHizmet_id = x.urun_hizmet_id)))";
                }
                $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

                $recordsTotal    = (int)($db->fetchOne("$cte SELECT COUNT(*) AS sayi FROM G")['sayi'] ?? 0);
                $recordsFiltered = $where ? (int)($db->fetchOne("$cte SELECT COUNT(*) AS sayi FROM G $whereSql", $params)['sayi'] ?? 0) : $recordsTotal;

                // 1. aşama: sayfadaki gruplar, 2. aşama: yalnız bu grupların ürünleri
                $rows = $db->fetchAll("
                    $cte,
                    P AS (
                        SELECT NormKod, Adet, ToplamStok,
                               ROW_NUMBER() OVER (ORDER BY $orderCol $orderDir, NormKod) AS Sira
                        FROM G $whereSql
                        ORDER BY $orderCol $orderDir, NormKod
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT P.NormKod, P.Adet, P.ToplamStok, P.Sira,
                           n.urun_hizmet_id, n.urun_hizmet_kodu, n.urun_hizmet_adi, n.urun_hizmet_durum, n.Stok
                    FROM P
                    INNER JOIN N n ON n.NormKod = P.NormKod
                    ORDER BY P.Sira, n.urun_hizmet_id
                ", array_merge($params, [$start, $length]));

                $gruplar = [];
                foreach ($rows as $r) {
                    $k = $r['NormKod'];
                    if (!isset($gruplar[$k])) {
                        $gruplar[$k] = [
                            'NormKod'    => $k,
                            'Adet'       => (int)$r['Adet'],
                            'ToplamStok' => (float)$r['ToplamStok'],
                            'Urunler'    => [],
                        ];
                    }
                    $gruplar[$k]['Urunler'][] = [
                        'id'    => (int)$r['urun_hizmet_id'],
                        'kod'   => $r['urun_hizmet_kodu'],
                        'ad'    => $r['urun_hizmet_adi'],
                        'durum' => (int)$r['urun_hizmet_durum'],
                        'stok'  => (float)$r['Stok'],
                    ];
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => array_values($gruplar),
                ]);
                break;

            case 'grup_detay':
                $normKod = trim($_POST['norm_kod'] ?? '');
                $ids     = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));

                if ($normKod !== '') {
                    $idRows = $db->fetchAll("SELECT u.urun_hizmet_id FROM dbo.Urun_Hizmet u WHERE u.urun_hizmet_tip = 1 AND $normExpr = ?", [$normKod]);
                    $ids = array_map(fn($r) => (int)$r['urun_hizmet_id'], $idRows);
                }
                if (count($ids) < 2) throw new Exception('Karşılaştırma için en az iki ürün gerekli.');
                if (count($ids) > 20) throw new Exception('Tek seferde en fazla 20 ürün karşılaştırılabilir.');

                $yer   = implode(',', array_fill(0, count($ids), '?'));
                $urunler = $db->fetchAll("
                    SELECT urun_hizmet_id, urun_hizmet_kodu, urun_hizmet_adi, urun_hizmet_durum,
                           ISNULL(Urun_Stok_Miktari, 0) AS stok, urun_hizmet_olusturma_tarihi
                    FROM dbo.Urun_Hizmet WHERE urun_hizmet_id IN ($yer)
                    ORDER BY urun_hizmet_id", $ids);

                $kullanim = $helper->kullanimSayilari($ids);
                foreach ($urunler as &$u) {
                    $u['kullanim'] = $kullanim[(int)$u['urun_hizmet_id']] ?? [];
                    $u['toplam_kullanim'] = array_sum($u['kullanim']);
                }
                unset($u);

                // Öneri: aktif > en çok kullanılan > en eski
                $sirali = $urunler;
                usort($sirali, fn($a, $b) =>
                    [(int)$b['urun_hizmet_durum'], $b['toplam_kullanim'], -(int)$b['urun_hizmet_id']]
                    <=> [(int)$a['urun_hizmet_durum'], $a['toplam_kullanim'], -(int)$a['urun_hizmet_id']]);

                echo json_encode(['success' => true, 'data' => [
                    'urunler'   => $urunler,
                    'oneri_id'  => (int)$sirali[0]['urun_hizmet_id'],
                    'etiketler' => array_map(fn($t) => $t['etiket'], UrunBirlestirmeHelper::TABLOLAR),
                ]]);
                break;

            case 'onizleme':
                $yeniId  = (int)($_POST['yeni_id'] ?? 0);
                $eskiIds = (array)($_POST['eski_ids'] ?? []);
                echo json_encode(['success' => true, 'data' => $helper->onizleme($yeniId, $eskiIds)]);
                break;

            case 'birlestir':
                if (!$permissions['can_delete']) throw new Exception('Birleştirme için silme yetkisi gerekli.');

                $yeniId   = (int)($_POST['yeni_id'] ?? 0);
                $eskiIds  = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['eski_ids'] ?? [])))));
                $aciklama = trim($_POST['aciklama'] ?? '');
                if (empty($eskiIds)) throw new Exception('Birleştirilecek ürün seçilmedi.');

                $basarili = 0;
                foreach ($eskiIds as $eskiId) {
                    try {
                        $helper->birlestir($yeniId, $eskiId, (int)$user['kullanici_id'], $aciklama);
                        $basarili++;
                    } catch (Throwable $ex) {
                        $mesaj = "#$eskiId birleştirilemedi: " . $ex->getMessage();
                        if ($basarili > 0) $mesaj = "$basarili ürün birleştirildi. " . $mesaj;
                        echo json_encode(['success' => false, 'message' => $mesaj, 'basarili' => $basarili]);
                        exit;
                    }
                }
                echo json_encode(['success' => true, 'message' => "$basarili ürün birleştirildi ve pasife alındı."]);
                break;

            case 'log_list':
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25;
                $orderDir = strtoupper($_POST['order'][0]['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

                $where  = ["l.Durum = 1"];
                $params = [];
                $logSearch = trim($_POST['log_search'] ?? '');
                if ($logSearch !== '') {
                    $where[] = "(l.UrunBirlestirmeLog_EskiKod LIKE ? OR l.UrunBirlestirmeLog_EskiAd LIKE ? OR l.UrunBirlestirmeLog_YeniKod LIKE ? OR l.UrunBirlestirmeLog_YeniAd LIKE ?)";
                    array_push($params, "%$logSearch%", "%$logSearch%", "%$logSearch%", "%$logSearch%");
                }
                if (!empty($_POST['log_start'])) { $where[] = "l.OlusturmaTarihi >= ?"; $params[] = $_POST['log_start'] . ' 00:00:00'; }
                if (!empty($_POST['log_end']))   { $where[] = "l.OlusturmaTarihi <= ?"; $params[] = $_POST['log_end'] . ' 23:59:59'; }
                $whereSql = implode(' AND ', $where);

                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.UrunBirlestirmeLog WHERE Durum = 1")['sayi'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.UrunBirlestirmeLog l WHERE $whereSql", $params)['sayi'] ?? 0);

                $rows = $db->fetchAll("
                    WITH P AS (
                        SELECT l.UrunBirlestirmeLog_id
                        FROM dbo.UrunBirlestirmeLog l
                        WHERE $whereSql
                        ORDER BY l.OlusturmaTarihi $orderDir, l.UrunBirlestirmeLog_id $orderDir
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT l.UrunBirlestirmeLog_id, l.OlusturmaTarihi,
                           l.UrunBirlestirmeLog_EskiUrunId, l.UrunBirlestirmeLog_EskiKod, l.UrunBirlestirmeLog_EskiAd,
                           l.UrunBirlestirmeLog_YeniUrunId, l.UrunBirlestirmeLog_YeniKod, l.UrunBirlestirmeLog_YeniAd,
                           l.UrunBirlestirmeLog_EskiStok, l.UrunBirlestirmeLog_YeniStokOnce, l.UrunBirlestirmeLog_YeniStokSonra,
                           l.UrunBirlestirmeLog_TasinanKayitlar, l.UrunBirlestirmeLog_Aciklama,
                           l.UrunBirlestirmeLog_GeriAlindi, l.UrunBirlestirmeLog_GeriAlmaTarihi,
                           k.kullanici_ad + ' ' + k.kullanici_soyad AS OlusturanAdi,
                           g.kullanici_ad + ' ' + g.kullanici_soyad AS GeriAlanAdi
                    FROM P
                    INNER JOIN dbo.UrunBirlestirmeLog l ON l.UrunBirlestirmeLog_id = P.UrunBirlestirmeLog_id
                    LEFT JOIN dbo.kullanicilar k ON k.kullanici_id = l.OlusturanKullanici
                    LEFT JOIN dbo.kullanicilar g ON g.kullanici_id = l.UrunBirlestirmeLog_GeriAlanKullanici
                    ORDER BY l.OlusturmaTarihi $orderDir, l.UrunBirlestirmeLog_id $orderDir
                ", array_merge($params, [$start, $length]));

                $etiketler = array_map(fn($t) => $t['etiket'], UrunBirlestirmeHelper::TABLOLAR);
                foreach ($rows as &$r) {
                    $veri = json_decode($r['UrunBirlestirmeLog_TasinanKayitlar'] ?? '', true) ?: [];
                    $ozet = [];
                    foreach (($veri['tablolar'] ?? []) as $anahtar => $idler) {
                        $ozet[] = ['etiket' => $etiketler[$anahtar] ?? $anahtar, 'sayi' => count($idler)];
                    }
                    $r['TasinanOzet'] = $ozet;
                    unset($r['UrunBirlestirmeLog_TasinanKayitlar']);
                }
                unset($r);

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $rows,
                ]);
                break;

            case 'geri_al':
                if (!$permissions['can_delete']) throw new Exception('Geri alma için silme yetkisi gerekli.');
                $helper->geriAl((int)($_POST['log_id'] ?? 0), (int)$user['kullanici_id']);
                echo json_encode(['success' => true, 'message' => 'Birleştirme geri alındı.']);
                break;

            case 'urun_ara':
                $q   = trim($_POST['q'] ?? '');
                $sql = "SELECT TOP 30 urun_hizmet_id AS id,
                               ISNULL(urun_hizmet_kodu, '') + ' - ' + urun_hizmet_adi + ' (Stok: ' + CAST(CAST(ISNULL(Urun_Stok_Miktari, 0) AS DECIMAL(18,2)) AS NVARCHAR(30)) + ')' AS text
                        FROM dbo.Urun_Hizmet
                        WHERE urun_hizmet_tip = 1 AND urun_hizmet_durum = 1";
                $params = [];
                if ($q !== '') {
                    // Öncelik: kod birebir > kod ile başlayan > içinde geçen
                    $sql   .= " AND (urun_hizmet_kodu LIKE ? OR urun_hizmet_adi LIKE ?)
                                ORDER BY CASE WHEN urun_hizmet_kodu = ? THEN 0 WHEN urun_hizmet_kodu LIKE ? THEN 1 ELSE 2 END, urun_hizmet_kodu";
                    $params = ["%$q%", "%$q%", $q, "$q%"];
                } else {
                    $sql .= " ORDER BY urun_hizmet_kodu";
                }
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Throwable $e) {
        error_log('urun-birlestirme: ' . $e->getMessage());
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

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .grup-urun { font-size: 13px; line-height: 1.5; }
        .grup-urun code { color: #0d6efd; }
        #detayTablo td, #detayTablo th { vertical-align: middle; font-size: 13px; white-space: nowrap; }
        #detayTablo td.urun-ad { white-space: normal; min-width: 220px; }
        #detayTablo tr.satir-kalacak { background: #d1e7dd !important; }
        #detayTablo tr.satir-birlesecek { background: #fff3cd !important; }
        .onizleme-kutu { border: 1px solid #dee2e6; border-radius: 6px; padding: 10px 12px; margin-bottom: 8px; background: #f8f9fa; }
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
                    <div class="col-sm-6">
                        <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-collection"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Mükerrer Grup</span>
                                <span class="info-box-number" id="stat-grup">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Birleştirme Bekleyen Ürün</span>
                                <span class="info-box-number" id="stat-bekleyen">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-intersect"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Yapılan Birleştirme</span>
                                <span class="info-box-number" id="stat-birlestirme">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Eksi Stoklu Ürün</span>
                                <span class="info-box-number" id="stat-negatif">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Elle Birleştirme -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-hand-index"></i> Elle Karşılaştır / Birleştir</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#elleCard">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="elleCard">
                        <p class="text-muted small mb-2">Kodu benzemeyen mükerrer ürünler için ürünleri seçip karşılaştırın.</p>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-10">
                                <label class="form-label">Ürünler (en az 2)</label>
                                <select class="form-select" id="elleUrunler" multiple></select>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-primary w-100" id="btnElleKarsilastir">
                                    <i class="bi bi-layout-split"></i> Karşılaştır
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mükerrer Gruplar -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-files"></i> Mükerrer Ürün Grupları</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtrele
                            </button>
                        </div>
                    </div>

                    <div class="collapse" id="filterCard">
                        <div class="card-body border-bottom bg-light">
                            <form id="filterForm">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label" for="filter_search">Arama</label>
                                        <input type="text" class="form-control form-control-sm" id="filter_search" placeholder="Ürün kodu veya adı...">
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="filter_negatif" name="negatif" value="1">
                                            <label class="form-check-label" for="filter_negatif">Eksi stoklu</label>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="filter_kullanimli" name="kullanimli" value="1">
                                            <label class="form-check-label" for="filter_kullanimli">Kullanımı olan</label>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="filter_pasif" name="pasif_dahil" value="1">
                                            <label class="form-check-label" for="filter_pasif">Pasifleri dahil et</label>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-flex gap-1">
                                        <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> Filtrele</button>
                                        <button type="button" class="btn btn-secondary btn-sm" id="clearFilters"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card-body">
                        <table id="grupTable" class="table table-bordered table-striped table-hover table-sm w-100">
                            <thead>
                                <tr>
                                    <th>Grup Kodu</th>
                                    <th>Ürün Sayısı</th>
                                    <th>Ürünler</th>
                                    <th>Toplam Stok</th>
                                    <th width="90">İşlem</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <!-- Birleştirme Geçmişi -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-clock-history"></i> Birleştirme Geçmişi</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#logFilterCard">
                                <i class="bi bi-funnel"></i> Filtrele
                            </button>
                        </div>
                    </div>

                    <div class="collapse" id="logFilterCard">
                        <div class="card-body border-bottom bg-light">
                            <form id="logFilterForm">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label" for="log_start">Başlangıç</label>
                                        <input type="date" class="form-control form-control-sm" id="log_start">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="log_end">Bitiş</label>
                                        <input type="date" class="form-control form-control-sm" id="log_end">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="log_search">Arama</label>
                                        <input type="text" class="form-control form-control-sm" id="log_search" placeholder="Kod veya ürün adı...">
                                    </div>
                                    <div class="col-md-2 d-flex gap-1">
                                        <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> Filtrele</button>
                                        <button type="button" class="btn btn-secondary btn-sm" id="clearLogFilters"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card-body">
                        <table id="logTable" class="table table-bordered table-striped table-hover table-sm w-100">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Birleştirilen (Pasif)</th>
                                    <th>Kalan Ürün</th>
                                    <th>Stok</th>
                                    <th>Taşınan Kayıtlar</th>
                                    <th>Yapan</th>
                                    <th>Durum</th>
                                    <th width="90">İşlem</th>
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

<!-- Grup Detay Modalı -->
<div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-intersect"></i> Ürün Birleştirme</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small">
                    <i class="bi bi-info-circle"></i>
                    <b>Kalacak</b> ürünü seçin, bu ürünle birleşecek ürünlerin <b>Birleştir</b> anahtarını açın.
                    Farklı ürünlere dokunmayın. Birleşen ürünün tüm kayıtları ve stoğu kalan ürüne taşınır, kendisi pasife alınır.
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="detayTablo">
                        <thead class="table-light"></thead>
                        <tbody></tbody>
                    </table>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="birlestirmeAciklama">Açıklama</label>
                    <input type="text" class="form-control form-control-sm" id="birlestirmeAciklama" maxlength="500" placeholder="İsteğe bağlı (ör. hatalı açılmış kod)">
                </div>

                <div id="onizlemeAlani"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                <button type="button" class="btn btn-info" id="btnOnizle"><i class="bi bi-eye"></i> Önizle</button>
                <?php if ($permissions['can_delete']): ?>
                <button type="button" class="btn btn-danger" id="btnBirlestir" disabled><i class="bi bi-intersect"></i> Birleştir</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
const permissions = <?= json_encode($permissions) ?>;
const detayModal  = new bootstrap.Modal('#detayModal');

let grupTable, logTable;
let grupFiltre = {};
let logFiltre  = {};
let etiketler  = {};
let onizlenenSecim = null;

const fmt = n => new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(n || 0);
const esc = s => $('<div>').text(s ?? '').html();
const fmtTarih = d => {
    if (!d) return '-';
    const dt = new Date(String(d).replace(' ', 'T'));
    return isNaN(dt) ? d : dt.toLocaleString('tr-TR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
};
const stokBadge = s => `<span class="badge ${s < 0 ? 'bg-danger' : (s > 0 ? 'bg-success' : 'bg-secondary')}">${fmt(s)}</span>`;

function loadStats() {
    $.post('', { action: 'stats' }, r => {
        if (!r.success) return;
        $('#stat-grup').text(fmt(r.data.grup));
        $('#stat-bekleyen').text(fmt(r.data.bekleyen));
        $('#stat-birlestirme').text(fmt(r.data.birlestirme));
        $('#stat-negatif').text(fmt(r.data.negatif));
    });
}

function initGrupTable() {
    grupTable = $('#grupTable').DataTable({
        serverSide: true,
        processing: true,
        dom: 'lrtip',
        scrollX: true,
        pageLength: 25,
        order: [[0, 'asc']],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: '', type: 'POST',
            data: d => Object.assign(d, { action: 'list' }, grupFiltre)
        },
        columns: [
            { data: 'NormKod', render: d => `<code>${esc(d)}</code>` },
            { data: 'Adet', className: 'text-center', render: d => `<span class="badge bg-primary">${d}</span>` },
            {
                data: 'Urunler', orderable: false,
                render: list => list.map(u => `
                    <div class="grup-urun">
                        <span class="text-muted">#${u.id}</span> <code>${esc(u.kod)}</code> ${esc(u.ad)}
                        ${u.durum ? '' : '<span class="badge bg-secondary">Pasif</span>'}
                        ${stokBadge(u.stok)}
                    </div>`).join('')
            },
            { data: 'ToplamStok', className: 'text-end', render: d => stokBadge(d) },
            {
                data: 'NormKod', orderable: false, className: 'text-center',
                render: d => `<button class="btn btn-sm btn-primary btn-grup-ac" data-kod="${esc(d)}"><i class="bi bi-box-arrow-up-right"></i> Aç</button>`
            }
        ]
    });
}

function initLogTable() {
    logTable = $('#logTable').DataTable({
        serverSide: true,
        processing: true,
        dom: 'lrtip',
        scrollX: true,
        pageLength: 25,
        order: [[0, 'desc']],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: '', type: 'POST',
            data: d => Object.assign(d, { action: 'log_list' }, logFiltre)
        },
        columns: [
            { data: 'OlusturmaTarihi', render: d => fmtTarih(d) },
            { data: 'UrunBirlestirmeLog_EskiKod', orderable: false, render: (d, t, r) => `<span class="text-muted">#${r.UrunBirlestirmeLog_EskiUrunId}</span> <code>${esc(d)}</code><br><small>${esc(r.UrunBirlestirmeLog_EskiAd)}</small>` },
            { data: 'UrunBirlestirmeLog_YeniKod', orderable: false, render: (d, t, r) => `<span class="text-muted">#${r.UrunBirlestirmeLog_YeniUrunId}</span> <code>${esc(d)}</code><br><small>${esc(r.UrunBirlestirmeLog_YeniAd)}</small>` },
            {
                data: 'UrunBirlestirmeLog_EskiStok', orderable: false,
                render: (d, t, r) => `<small>${fmt(r.UrunBirlestirmeLog_YeniStokOnce)} + ${fmt(d)} = <b>${fmt(r.UrunBirlestirmeLog_YeniStokSonra)}</b></small>`
            },
            {
                data: 'TasinanOzet', orderable: false,
                render: list => list.length ? list.map(x => `<span class="badge bg-light text-dark border me-1">${esc(x.etiket)}: ${x.sayi}</span>`).join('') : '<span class="text-muted">-</span>'
            },
            { data: 'OlusturanAdi', orderable: false, render: (d, t, r) => esc(d || '-') + (r.UrunBirlestirmeLog_Aciklama ? `<br><small class="text-muted">${esc(r.UrunBirlestirmeLog_Aciklama)}</small>` : '') },
            {
                data: 'UrunBirlestirmeLog_GeriAlindi', orderable: false,
                render: (d, t, r) => d == 1
                    ? `<span class="badge bg-secondary">Geri Alındı</span><br><small>${esc(r.GeriAlanAdi || '')} ${fmtTarih(r.UrunBirlestirmeLog_GeriAlmaTarihi)}</small>`
                    : '<span class="badge bg-success">Aktif</span>'
            },
            {
                data: 'UrunBirlestirmeLog_id', orderable: false, className: 'text-center',
                render: (d, t, r) => (permissions.can_delete && r.UrunBirlestirmeLog_GeriAlindi != 1)
                    ? `<button class="btn btn-sm btn-outline-warning btn-geri-al" data-id="${d}"><i class="bi bi-arrow-counterclockwise"></i> Geri Al</button>`
                    : ''
            }
        ]
    });
}

function grupAc(params) {
    $.post('', Object.assign({ action: 'grup_detay' }, params), r => {
        if (!r.success) { showToast(r.message, 'error'); return; }
        etiketler = r.data.etiketler;
        renderDetay(r.data.urunler, r.data.oneri_id);
        $('#birlestirmeAciklama').val('');
        detayModal.show();
    });
}

function renderDetay(urunler, oneriId) {
    const anahtarlar = Object.keys(etiketler);
    $('#detayTablo thead').html(`
        <tr>
            <th class="text-center">Kalacak</th>
            <th class="text-center">Birleştir</th>
            <th>Id</th><th>Kod</th><th>Ad</th><th>Durum</th><th>Stok</th>
            ${anahtarlar.map(k => `<th class="text-center">${esc(etiketler[k])}</th>`).join('')}
        </tr>`);

    $('#detayTablo tbody').html(urunler.map(u => {
        const id = u.urun_hizmet_id;
        const pasif = u.urun_hizmet_durum != 1;
        return `
        <tr data-id="${id}" data-pasif="${pasif ? 1 : 0}">
            <td class="text-center">
                <input class="form-check-input radio-kalacak" type="radio" name="kalacak" value="${id}" id="kalacak_${id}" ${id == oneriId ? 'checked' : ''} ${pasif ? 'disabled' : ''}>
            </td>
            <td>
                <div class="form-check form-switch d-flex justify-content-center">
                    <input class="form-check-input switch-birlestir" type="checkbox" role="switch" id="birlestir_${id}" name="birlestir[]" value="${id}" ${pasif ? 'disabled' : ''}>
                    <label class="form-check-label" for="birlestir_${id}"></label>
                </div>
            </td>
            <td>${id}</td>
            <td><code>${esc(u.urun_hizmet_kodu)}</code></td>
            <td class="urun-ad">${esc(u.urun_hizmet_adi)}<br><small class="text-muted">${fmtTarih(u.urun_hizmet_olusturma_tarihi)}</small></td>
            <td>${pasif ? '<span class="badge bg-secondary">Pasif</span>' : '<span class="badge bg-success">Aktif</span>'}</td>
            <td class="text-end">${stokBadge(parseFloat(u.stok))}</td>
            ${anahtarlar.map(k => `<td class="text-center">${u.kullanim[k] ? `<b>${u.kullanim[k]}</b>` : '<span class="text-muted">0</span>'}</td>`).join('')}
        </tr>`;
    }).join(''));

    secimGuncelle();
}

function mevcutSecim() {
    return {
        yeni_id: parseInt($('.radio-kalacak:checked').val() || 0),
        eski_ids: $('.switch-birlestir:checked').map((i, el) => parseInt(el.value)).get()
    };
}

function secimGuncelle() {
    const yeniId = $('.radio-kalacak:checked').val();
    $('#detayTablo tbody tr').each(function() {
        const $tr = $(this);
        const id = $tr.data('id');
        const $sw = $tr.find('.switch-birlestir');
        const kalacak = String(id) === String(yeniId);
        if (kalacak) $sw.prop('checked', false);
        $sw.prop('disabled', kalacak || $tr.data('pasif') == 1);
        $tr.toggleClass('satir-kalacak', kalacak).toggleClass('satir-birlesecek', $sw.is(':checked'));
    });
    onizlenenSecim = null;
    $('#btnBirlestir').prop('disabled', true);
    $('#onizlemeAlani').empty();
}

function renderOnizleme(d) {
    const kullanimHtml = k => {
        const list = Object.keys(k).filter(x => k[x] > 0).map(x => `<span class="badge bg-light text-dark border me-1">${esc(etiketler[x])}: ${k[x]}</span>`);
        return list.length ? list.join('') : '<span class="text-muted">Taşınacak kayıt yok</span>';
    };
    let html = `<h6 class="mt-3"><i class="bi bi-eye"></i> Önizleme</h6>`;
    d.eskiler.forEach(e => {
        html += `
        <div class="onizleme-kutu">
            <div><code>${esc(e.urun.urun_hizmet_kodu)}</code> ${esc(e.urun.urun_hizmet_adi)}
                <i class="bi bi-arrow-right mx-2 text-primary"></i>
                <code>${esc(d.yeni.urun_hizmet_kodu)}</code> ${esc(d.yeni.urun_hizmet_adi)}</div>
            <div class="mt-1 small">Taşınacak: ${kullanimHtml(e.kullanim)}</div>
            <div class="small">Stoğa eklenecek: ${stokBadge(parseFloat(e.urun.stok))}</div>
            ${e.uyarilar.map(u => `<div class="text-warning small"><i class="bi bi-exclamation-triangle"></i> ${esc(u)}</div>`).join('')}
        </div>`;
    });
    html += `<div class="alert alert-warning py-2 mb-0">
        Birleştirme sonrası <b>${esc(d.yeni.urun_hizmet_kodu)}</b> stoğu:
        ${stokBadge(parseFloat(d.yeni.stok))} → ${stokBadge(d.stok_sonra)}
        · ${d.eskiler.length} ürün pasife alınacak.
    </div>`;
    $('#onizlemeAlani').html(html);
}

function initElleSelect() {
    $('#elleUrunler').select2({
        theme: 'bootstrap-5',
        placeholder: 'Kod veya ad ile ürün arayın...',
        width: '100%',
        ajax: {
            url: '', type: 'POST', delay: 250,
            data: p => ({ action: 'urun_ara', q: p.term || '' }),
            processResults: r => ({ results: r.success ? r.data : [] }),
            cache: true
        },
        language: {
            noResults: () => 'Sonuç bulunamadı',
            searching: () => 'Aranıyor...'
        }
    });
}

$(document).ready(function() {
    loadStats();
    initGrupTable();
    initLogTable();
    initElleSelect();

    $('#grupTable').on('click', '.btn-grup-ac', function() {
        grupAc({ norm_kod: $(this).data('kod') });
    });

    $('#btnElleKarsilastir').on('click', function() {
        const ids = $('#elleUrunler').val() || [];
        if (ids.length < 2) { showToast('En az iki ürün seçin.', 'warning'); return; }
        grupAc({ ids: ids });
    });

    $('#detayTablo').on('change', '.radio-kalacak, .switch-birlestir', secimGuncelle);

    $('#btnOnizle').on('click', function() {
        const secim = mevcutSecim();
        if (!secim.yeni_id) { showToast('Kalacak ürünü seçin.', 'warning'); return; }
        if (!secim.eski_ids.length) { showToast('Birleştirilecek en az bir ürün seçin.', 'warning'); return; }

        $.post('', Object.assign({ action: 'onizleme' }, secim), r => {
            if (!r.success) { showToast(r.message, 'error'); return; }
            etiketler = r.data.etiketler;
            renderOnizleme(r.data);
            onizlenenSecim = JSON.stringify(secim);
            $('#btnBirlestir').prop('disabled', false);
        });
    });

    $('#btnBirlestir').on('click', function() {
        const secim = mevcutSecim();
        if (onizlenenSecim !== JSON.stringify(secim)) {
            showToast('Seçim değişti, önce tekrar önizleyin.', 'warning');
            $(this).prop('disabled', true);
            return;
        }
        confirmAction(
            `${secim.eski_ids.length} ürün birleştirilsin mi?`,
            'Kayıtlar ve stok kalan ürüne taşınacak, birleşen ürünler pasife alınacak. İşlem geçmişten geri alınabilir.',
            () => {
                const $btn = $('#btnBirlestir').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Birleştiriliyor...');
                $.post('', Object.assign({ action: 'birlestir', aciklama: $('#birlestirmeAciklama').val() }, secim), r => {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success || r.basarili > 0) {
                        detayModal.hide();
                        loadStats();
                        grupTable.ajax.reload(null, false);
                        logTable.ajax.reload();
                    }
                }).fail(() => showToast('Sunucu hatası oluştu.', 'error'))
                  .always(() => $btn.html('<i class="bi bi-intersect"></i> Birleştir'));
            }
        );
    });

    $('#logTable').on('click', '.btn-geri-al', function() {
        const id = $(this).data('id');
        confirmAction(
            'Birleştirme geri alınsın mı?',
            'Taşınan kayıtlar ve stok eski ürüne geri döner, eski ürün önceki durumuna alınır.',
            () => {
                $.post('', { action: 'geri_al', log_id: id }, r => {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) {
                        loadStats();
                        grupTable.ajax.reload(null, false);
                        logTable.ajax.reload(null, false);
                    }
                }).fail(() => showToast('Sunucu hatası oluştu.', 'error'));
            }
        );
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        grupFiltre = {
            search_text: $('#filter_search').val(),
            negatif:     $('#filter_negatif').is(':checked') ? 1 : '',
            kullanimli:  $('#filter_kullanimli').is(':checked') ? 1 : '',
            pasif_dahil: $('#filter_pasif').is(':checked') ? 1 : ''
        };
        Object.keys(grupFiltre).forEach(k => { if (!grupFiltre[k]) delete grupFiltre[k]; });
        grupTable.ajax.reload();
    });

    $('#clearFilters').on('click', function() {
        $('#filterForm')[0].reset();
        grupFiltre = {};
        grupTable.ajax.reload();
    });

    $('#logFilterForm').on('submit', function(e) {
        e.preventDefault();
        logFiltre = {
            log_search: $('#log_search').val(),
            log_start:  $('#log_start').val(),
            log_end:    $('#log_end').val()
        };
        Object.keys(logFiltre).forEach(k => { if (!logFiltre[k]) delete logFiltre[k]; });
        logTable.ajax.reload();
    });

    $('#clearLogFilters').on('click', function() {
        $('#logFilterForm')[0].reset();
        logFiltre = {};
        logTable.ajax.reload();
    });
});
</script>
</body>
</html>
