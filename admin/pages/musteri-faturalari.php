<?php
/**
 * Admin Panel - Müşteri Faturaları Raporu
 * Giden faturaların (FaturalarGiden) kalem bazlı listelenmesi
 * Kalemler FaturalarGidenKalemleri tablosundan gelir (fatura detayı çekildiğinde dolar)
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadir.');
}

$pageTitle = 'Müşteri Faturaları';
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX İşlemleri
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');

    $draw = $_POST['draw'] ?? 1;
    $start = $_POST['start'] ?? 0;
    $length = $_POST['length'] ?? 10;
    $search = $_POST['search']['value'] ?? '';

    // Filtreler
    $vkn = $_POST['vkn'] ?? '';
    $baslangic_tarih = $_POST['baslangic_tarih'] ?? '';
    $bitis_tarih = $_POST['bitis_tarih'] ?? '';
    $fatura_no = $_POST['fatura_no'] ?? '';
    $stok_ara = $_POST['stok_ara'] ?? '';
    $kdv_oran = $_POST['kdv_oran'] ?? '';
    $kategori_id = $_POST['kategori_id'] ?? '';
    $marka_id = $_POST['marka_id'] ?? '';

    $where = " WHERE fd.Durum = 1 AND fd.FaturalarGiden_FaturaNo LIKE 'TLN%' ";
    $params = [];

    if (!empty($vkn)) {
        $where .= " AND fd.FaturalarGiden_VKN_TCKN = ? ";
        $params[] = $vkn;
    }
    if (!empty($baslangic_tarih)) {
        $where .= " AND fd.FaturalarGiden_Tarih >= ? ";
        $params[] = $baslangic_tarih;
    }
    if (!empty($bitis_tarih)) {
        $where .= " AND fd.FaturalarGiden_Tarih <= ? ";
        $params[] = $bitis_tarih;
    }
    if (!empty($fatura_no)) {
        $where .= " AND fd.FaturalarGiden_FaturaNo LIKE ? ";
        $params[] = "%$fatura_no%";
    }
    if (!empty($stok_ara)) {
        $where .= " AND (fdk.FaturalarGidenKalemleri_StokKodu LIKE ? OR fdk.FaturalarGidenKalemleri_UrunAdi LIKE ?) ";
        $params[] = "%$stok_ara%";
        $params[] = "%$stok_ara%";
    }
    if ($kdv_oran !== '') {
        $where .= " AND fdk.FaturalarGidenKalemleri_KDVOrani = ? ";
        $params[] = $kdv_oran;
    }
    if (!empty($kategori_id)) {
        $where .= " AND up.urun_hizmet_kategori_id = ? ";
        $params[] = $kategori_id;
    }
    if (!empty($marka_id)) {
        $where .= " AND up.urun_hizmet_marka_id = ? ";
        $params[] = $marka_id;
    }
    if (!empty($search)) {
        $where .= " AND (fd.FaturalarGiden_FaturaNo LIKE ? OR fdk.FaturalarGidenKalemleri_UrunAdi LIKE ? OR fd.FaturalarGiden_Unvan LIKE ?) ";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql = "
        SELECT
            fd.FaturalarGiden_FaturaNo as fatura_no,
            CONVERT(VARCHAR(10), fd.FaturalarGiden_Tarih, 120) as fatura_tarih,
            fd.FaturalarGiden_Unvan as unvan,
            fdk.FaturalarGidenKalemleri_StokKodu as stok_kodu,
            fdk.FaturalarGidenKalemleri_UrunAdi as urun_adi,
            fdk.FaturalarGidenKalemleri_Miktar as miktar,
            fdk.FaturalarGidenKalemleri_Birim as birim,
            fdk.FaturalarGidenKalemleri_BirimFiyat as birim_fiyat,
            fdk.FaturalarGidenKalemleri_KDVOrani as kdv_oran,
            fdk.FaturalarGidenKalemleri_NetTutar as net_tutar,
            fd.FaturalarGiden_ParaBirimi as para_birimi,
            up.urun_hizmet_id as urun_id,
            up.urun_hizmet_kategori_id as kategori_id,
            up.urun_hizmet_marka_id as marka_id,
            kat.kategori_adi as kategori_adi,
            mar.marka_adi as marka_adi
        FROM dbo.FaturalarGidenKalemleri fdk
        INNER JOIN dbo.FaturalarGiden fd ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
        OUTER APPLY (
            SELECT TOP 1 u.urun_hizmet_id, u.urun_hizmet_kategori_id, u.urun_hizmet_marka_id
            FROM dbo.Urun_Hizmet u
            WHERE u.urun_hizmet_kodu = fdk.FaturalarGidenKalemleri_StokKodu
              AND fdk.FaturalarGidenKalemleri_StokKodu IS NOT NULL
              AND fdk.FaturalarGidenKalemleri_StokKodu <> ''
            ORDER BY u.urun_hizmet_id
        ) up
        LEFT JOIN dbo.UrunHizmet_Kategoriler kat ON kat.kategori_id = up.urun_hizmet_kategori_id
        LEFT JOIN dbo.UrunHizmet_Markalar mar ON mar.marka_id = up.urun_hizmet_marka_id
        $where
        ORDER BY fd.FaturalarGiden_Tarih DESC, fd.FaturalarGiden_id DESC, fdk.FaturalarGidenKalemleri_Sira ASC
        OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
    ";

    $items = $db->fetchAll($sql, $params);

    $totalSql = "
        SELECT COUNT(*) as toplam
        FROM dbo.FaturalarGidenKalemleri fdk
        INNER JOIN dbo.FaturalarGiden fd ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
        OUTER APPLY (
            SELECT TOP 1 u.urun_hizmet_kategori_id, u.urun_hizmet_marka_id
            FROM dbo.Urun_Hizmet u
            WHERE u.urun_hizmet_kodu = fdk.FaturalarGidenKalemleri_StokKodu
              AND fdk.FaturalarGidenKalemleri_StokKodu IS NOT NULL
              AND fdk.FaturalarGidenKalemleri_StokKodu <> ''
            ORDER BY u.urun_hizmet_id
        ) up
        $where
    ";
    $totalRecords = $db->fetchOne($totalSql, $params)['toplam'] ?? 0;

    echo json_encode([
        "draw" => intval($draw),
        "recordsTotal" => $totalRecords,
        "recordsFiltered" => $totalRecords,
        "data" => $items
    ]);
    exit;
}

// Excel İndirme İşlemi
if (isset($_GET['excel']) && $_GET['excel'] == '1') {
    // Filtreler
    $vkn = $_POST['vkn'] ?? '';
    $baslangic_tarih = $_POST['baslangic_tarih'] ?? '';
    $bitis_tarih = $_POST['bitis_tarih'] ?? '';
    $fatura_no = $_POST['fatura_no'] ?? '';
    $stok_ara = $_POST['stok_ara'] ?? '';
    $kdv_oran = $_POST['kdv_oran'] ?? '';
    $kategori_id = $_POST['kategori_id'] ?? '';
    $marka_id = $_POST['marka_id'] ?? '';

    $where = " WHERE fd.Durum = 1 AND fd.FaturalarGiden_FaturaNo LIKE 'TLN%' ";
    $params = [];

    if (!empty($vkn)) {
        $where .= " AND fd.FaturalarGiden_VKN_TCKN = ? ";
        $params[] = $vkn;
    }
    if (!empty($baslangic_tarih)) {
        $where .= " AND fd.FaturalarGiden_Tarih >= ? ";
        $params[] = $baslangic_tarih;
    }
    if (!empty($bitis_tarih)) {
        $where .= " AND fd.FaturalarGiden_Tarih <= ? ";
        $params[] = $bitis_tarih;
    }
    if (!empty($fatura_no)) {
        $where .= " AND fd.FaturalarGiden_FaturaNo LIKE ? ";
        $params[] = "%$fatura_no%";
    }
    if (!empty($stok_ara)) {
        $where .= " AND (fdk.FaturalarGidenKalemleri_StokKodu LIKE ? OR fdk.FaturalarGidenKalemleri_UrunAdi LIKE ?) ";
        $params[] = "%$stok_ara%";
        $params[] = "%$stok_ara%";
    }
    if ($kdv_oran !== '') {
        $where .= " AND fdk.FaturalarGidenKalemleri_KDVOrani = ? ";
        $params[] = $kdv_oran;
    }
    if (!empty($kategori_id)) {
        $where .= " AND up.urun_hizmet_kategori_id = ? ";
        $params[] = $kategori_id;
    }
    if (!empty($marka_id)) {
        $where .= " AND up.urun_hizmet_marka_id = ? ";
        $params[] = $marka_id;
    }

    $sql = "
        SELECT
            fd.FaturalarGiden_FaturaNo as fatura_no,
            CONVERT(VARCHAR(10), fd.FaturalarGiden_Tarih, 104) as fatura_tarih,
            fd.FaturalarGiden_Unvan as unvan,
            fdk.FaturalarGidenKalemleri_StokKodu as stok_kodu,
            fdk.FaturalarGidenKalemleri_UrunAdi as urun_adi,
            fdk.FaturalarGidenKalemleri_Miktar as miktar,
            fdk.FaturalarGidenKalemleri_Birim as birim,
            fdk.FaturalarGidenKalemleri_BirimFiyat as birim_fiyat,
            fdk.FaturalarGidenKalemleri_KDVOrani as kdv_oran,
            fdk.FaturalarGidenKalemleri_NetTutar as net_tutar,
            fd.FaturalarGiden_ParaBirimi as para_birimi,
            kat.kategori_adi as kategori_adi,
            mar.marka_adi as marka_adi
        FROM dbo.FaturalarGidenKalemleri fdk
        INNER JOIN dbo.FaturalarGiden fd ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
        OUTER APPLY (
            SELECT TOP 1 u.urun_hizmet_kategori_id, u.urun_hizmet_marka_id
            FROM dbo.Urun_Hizmet u
            WHERE u.urun_hizmet_kodu = fdk.FaturalarGidenKalemleri_StokKodu
              AND fdk.FaturalarGidenKalemleri_StokKodu IS NOT NULL
              AND fdk.FaturalarGidenKalemleri_StokKodu <> ''
            ORDER BY u.urun_hizmet_id
        ) up
        LEFT JOIN dbo.UrunHizmet_Kategoriler kat ON kat.kategori_id = up.urun_hizmet_kategori_id
        LEFT JOIN dbo.UrunHizmet_Markalar mar ON mar.marka_id = up.urun_hizmet_marka_id
        $where
        ORDER BY fd.FaturalarGiden_Tarih DESC, fd.FaturalarGiden_id DESC, fdk.FaturalarGidenKalemleri_Sira ASC
    ";

    $items = $db->fetchAll($sql, $params);

    $filename = 'musteri_faturalari_' . date('Y-m-d_H-i-s') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<thead>';
    echo '<tr style="background-color: #0d6efd; color: white; font-weight: bold;">';
    echo '<th>Fatura No</th>';
    echo '<th>Tarih</th>';
    echo '<th>Müşteri</th>';
    echo '<th>Stok Kodu</th>';
    echo '<th>Ürün Adı</th>';
    echo '<th>Kategori</th>';
    echo '<th>Marka</th>';
    echo '<th>Miktar</th>';
    echo '<th>Birim</th>';
    echo '<th>Birim Fiyatı</th>';
    echo '<th>KDV Oranı (%)</th>';
    echo '<th>Net Tutar</th>';
    echo '<th>Para Birimi</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach ($items as $item) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($item['fatura_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['fatura_tarih'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['unvan'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['stok_kodu'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['urun_adi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['kategori_adi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['marka_adi'] ?? '') . '</td>';
        echo '<td style="text-align:center">' . number_format($item['miktar'] ?? 0, 2, ',', '.') . '</td>';
        echo '<td style="text-align:center">' . htmlspecialchars($item['birim'] ?? '') . '</td>';
        echo '<td style="text-align:right">' . number_format($item['birim_fiyat'] ?? 0, 2, ',', '.') . '</td>';
        echo '<td style="text-align:center">' . (is_numeric($item['kdv_oran'] ?? null) ? '%' . number_format($item['kdv_oran'], 0) : '-') . '</td>';
        echo '<td style="text-align:right">' . number_format($item['net_tutar'] ?? 0, 2, ',', '.') . '</td>';
        echo '<td style="text-align:center">' . htmlspecialchars($item['para_birimi'] ?? 'TRY') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// Kategori / Marka Güncelleme İşlemi
if (isset($_POST['action']) && $_POST['action'] === 'update_kategori_marka') {
    header('Content-Type: application/json; charset=utf-8');

    $urun_id = (int)($_POST['urun_id'] ?? 0);
    $kategori_id = $_POST['kategori_id'] !== '' ? (int)$_POST['kategori_id'] : null;
    $marka_id = $_POST['marka_id'] !== '' ? (int)$_POST['marka_id'] : null;

    if ($urun_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Bu kalem ürün kartında bulunamadı, güncellenemez.']);
        exit;
    }

    try {
        $db->execute(
            "UPDATE dbo.Urun_Hizmet
             SET urun_hizmet_kategori_id = ?,
                 urun_hizmet_marka_id = ?,
                 urun_hizmet_guncelleme_tarihi = GETDATE(),
                 urun_hizmet_guncelleyen_kullanici_id = ?
             WHERE urun_hizmet_id = ?",
            [$kategori_id, $marka_id, $user['kullanici_id'], $urun_id]
        );
        echo json_encode(['success' => true, 'message' => 'Kategori ve marka güncellendi.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Güncelleme hatası: ' . $e->getMessage()]);
    }
    exit;
}

// İstatistikler için veriler
$stats = $db->fetchOne("
    SELECT
        COUNT(DISTINCT fd.FaturalarGiden_id) as toplam_fatura,
        COUNT(*) as toplam_kalem,
        SUM(fdk.FaturalarGidenKalemleri_Miktar * fdk.FaturalarGidenKalemleri_BirimFiyat) as toplam_tutar
    FROM dbo.FaturalarGidenKalemleri fdk
    INNER JOIN dbo.FaturalarGiden fd ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
    WHERE fd.Durum = 1 AND fd.FaturalarGiden_FaturaNo LIKE 'TLN%'
");

$son30Gun = $db->fetchOne("
    SELECT COUNT(DISTINCT fd.FaturalarGiden_id) as sayi
    FROM dbo.FaturalarGiden fd
    INNER JOIN dbo.FaturalarGidenKalemleri fdk ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
    WHERE fd.Durum = 1 AND fd.FaturalarGiden_FaturaNo LIKE 'TLN%' AND fd.FaturalarGiden_Tarih >= DATEADD(day, -30, GETDATE())
")['sayi'] ?? 0;

// Müşteri Listesi (Filtre için) - sadece kalemi olan giden faturalarda geçen carilerden dinamik
$musteriler = $db->fetchAll("
    SELECT DISTINCT fd.FaturalarGiden_VKN_TCKN as vkn, fd.FaturalarGiden_Unvan as unvan
    FROM dbo.FaturalarGiden fd
    INNER JOIN dbo.FaturalarGidenKalemleri fdk ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
    WHERE fd.Durum = 1 AND fd.FaturalarGiden_FaturaNo LIKE 'TLN%' AND fd.FaturalarGiden_VKN_TCKN IS NOT NULL
    ORDER BY fd.FaturalarGiden_Unvan
");

// KDV Oran Listesi (Filtre için)
$kdvler = $db->fetchAll("SELECT DISTINCT kdv_oran FROM dbo.UrunHizmet_KDVTanimlari WHERE kdv_durum = 1 ORDER BY kdv_oran");

// Kategori Listesi (Filtre + Modal için)
$kategoriler = $db->fetchAll("SELECT kategori_id, kategori_adi FROM dbo.UrunHizmet_Kategoriler WHERE kategori_durum = 1 ORDER BY kategori_sira_no, kategori_adi");

// Marka Listesi (Filtre + Modal için)
$markalar = $db->fetchAll("SELECT marka_id, marka_adi FROM dbo.UrunHizmet_Markalar WHERE marka_durum = 1 ORDER BY marka_sira_no, marka_adi");

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .info-box-icon { width: 50px; height: 50px; font-size: 1.5rem; }
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
                            <li class="breadcrumb-item"><a href="anasayfa.php">Anasayfa</a></li>
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
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-file-earmark-text"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Fatura</span>
                                <span class="info-box-number"><?= number_format($stats['toplam_fatura'] ?? 0, 0, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-boxes"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kalem</span>
                                <span class="info-box-number"><?= number_format($stats['toplam_kalem'] ?? 0, 0, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-currency-lira"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Tutar (TL)</span>
                                <span class="info-box-number"><?= number_format($stats['toplam_tutar'] ?? 0, 2, ',', '.') ?> ₺</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-calendar-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Son 30 Gün</span>
                                <span class="info-box-number"><?= number_format($son30Gun, 0, ',', '.') ?> Fatura</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
                <div class="card mb-3">
                    <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <i class="bi bi-funnel me-1"></i> Filtrele
                    </div>
                    <div class="collapse show" id="filtrePanel">
                        <div class="card-body">
                            <form id="filtreForm">
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="form-label">Müşteri</label>
                                        <select id="vkn" class="form-select select2-searchable">
                                            <option value="">Tümü</option>
                                            <?php foreach ($musteriler as $m): ?>
                                                <option value="<?= htmlspecialchars($m['vkn']) ?>"><?= htmlspecialchars($m['unvan'] ?: $m['vkn']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" id="baslangic_tarih" class="form-control">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" id="bitis_tarih" class="form-control">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Fatura No</label>
                                        <input type="text" id="fatura_no" class="form-control" placeholder="Fatura No...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Stok / Ürün Ara</label>
                                        <input type="text" id="stok_ara" class="form-control" placeholder="Kod veya Ad...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">KDV Oranı</label>
                                        <select id="kdv_oran" class="form-select select2-searchable">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kdvler as $k): ?>
                                                <option value="<?= htmlspecialchars($k['kdv_oran']) ?>">%<?= number_format($k['kdv_oran'], 0) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Kategori</label>
                                        <select id="kategori_id" class="form-select select2-searchable">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kategoriler as $kat): ?>
                                                <option value="<?= $kat['kategori_id'] ?>"><?= htmlspecialchars($kat['kategori_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Marka</label>
                                        <select id="marka_id" class="form-select select2-searchable">
                                            <option value="">Tümü</option>
                                            <?php foreach ($markalar as $mar): ?>
                                                <option value="<?= $mar['marka_id'] ?>"><?= htmlspecialchars($mar['marka_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-1 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Rapor Tablosu -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="bi bi-list-ul me-1"></i> Sonuçlar</h3>
                        <button type="button" id="btnExcelIndir" class="btn btn-success btn-sm">
                            <i class="bi bi-file-earmark-excel me-1"></i> Excel İndir
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="raporTable" class="table table-striped table-bordered align-middle" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Fatura No</th>
                                        <th>Tarih</th>
                                        <th>Müşteri</th>
                                        <th>Stok Kodu</th>
                                        <th>Ürün Adı</th>
                                        <th>Kategori</th>
                                        <th>Marka</th>
                                        <th class="text-center">Miktar</th>
                                        <th class="text-end">Birim Fiyatı</th>
                                        <th class="text-center">KDV (%)</th>
                                        <th class="text-end">Net Tutar</th>
                                        <th class="text-center">Para Birimi</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Kategori / Marka Güncelleme Modalı -->
<div class="modal fade" id="kategoriMarkaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-tags me-1"></i> Kategori / Marka Güncelle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modal_urun_id">
                <p class="mb-3 text-muted small"><i class="bi bi-info-circle me-1"></i> Ürün kartındaki kategori ve marka bilgisi güncellenir, fatura değişmez.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Ürün</label>
                    <input type="text" id="modal_urun_adi" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kategori</label>
                    <select id="modal_kategori_id" class="form-select">
                        <option value="">Seçiniz</option>
                        <?php foreach ($kategoriler as $kat): ?>
                            <option value="<?= $kat['kategori_id'] ?>"><?= htmlspecialchars($kat['kategori_adi']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Marka</label>
                    <select id="modal_marka_id" class="form-select">
                        <option value="">Seçiniz</option>
                        <?php foreach ($markalar as $mar): ?>
                            <option value="<?= $mar['marka_id'] ?>"><?= htmlspecialchars($mar['marka_adi']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" id="btnKategoriMarkaKaydet"><i class="bi bi-save me-1"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
$(function() {
    // Select2
    $('.select2-searchable').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });

    // DataTable
    const table = $('#raporTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '?ajax=1',
            type: 'POST',
            data: function(d) {
                d.vkn = $('#vkn').val();
                d.baslangic_tarih = $('#baslangic_tarih').val();
                d.bitis_tarih = $('#bitis_tarih').val();
                d.fatura_no = $('#fatura_no').val();
                d.stok_ara = $('#stok_ara').val();
                d.kdv_oran = $('#kdv_oran').val();
                d.kategori_id = $('#kategori_id').val();
                d.marka_id = $('#marka_id').val();
            }
        },
        columns: [
            { data: 'fatura_no' },
            {
                data: 'fatura_tarih',
                render: function(data) {
                    if (!data) return '-';
                    const p = data.split('-');
                    return p[2] + '.' + p[1] + '.' + p[0];
                }
            },
            { data: 'unvan' },
            { data: 'stok_kodu' },
            {
                data: 'urun_adi',
                render: function(data, type, row) {
                    const ad = data ? $('<div>').text(data).html() : '-';
                    if (!row.urun_id) {
                        return ad;
                    }
                    return '<a href="javascript:void(0)" class="btn-kategori-marka text-decoration-none" '
                        + 'data-urun-id="' + row.urun_id + '" '
                        + 'data-urun-adi="' + (row.urun_adi ? $('<div>').text(row.urun_adi).html() : '') + '" '
                        + 'data-kategori-id="' + (row.kategori_id || '') + '" '
                        + 'data-marka-id="' + (row.marka_id || '') + '">'
                        + ad + ' <i class="bi bi-pencil-square text-muted small"></i></a>';
                }
            },
            { data: 'kategori_adi', defaultContent: '-', render: function(d){ return d || '-'; } },
            { data: 'marka_adi', defaultContent: '-', render: function(d){ return d || '-'; } },
            {
                data: 'miktar',
                className: 'text-center',
                render: function(data) {
                    return parseFloat(data).toLocaleString('tr-TR');
                }
            },
            {
                data: 'birim_fiyat',
                className: 'text-end',
                render: function(data) {
                    return parseFloat(data).toLocaleString('tr-TR', {minimumFractionDigits: 2});
                }
            },
            {
                data: 'kdv_oran',
                className: 'text-center',
                render: function(data) {
                    // null/undefined kontrolu yetmiyor: bos string de %NaN uretiyordu
                    const oran = parseFloat(data);
                    return isNaN(oran) ? '-' : '%' + oran.toFixed(0);
                }
            },
            {
                data: 'net_tutar',
                className: 'text-end font-weight-bold',
                render: function(data) {
                    return '<strong>' + parseFloat(data).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + '</strong>';
                }
            },
            {
                data: 'para_birimi',
                className: 'text-center',
                render: function(data) {
                    return data || 'TRY';
                }
            }
        ],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        order: [[1, 'desc']],
        pageLength: 25,
        dom: '<"d-flex justify-content-between align-items-center mb-3"lf>rtip'
    });

    $('#filtreForm').on('submit', function(e) {
        e.preventDefault();
        table.ajax.reload();
    });

    // Modal içi select'leri searchable yap
    const kmModal = new bootstrap.Modal(document.getElementById('kategoriMarkaModal'));
    $('#modal_kategori_id, #modal_marka_id').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#kategoriMarkaModal')
    });

    // Ürün Adı'na tıklayınca modal aç
    $('#raporTable tbody').on('click', '.btn-kategori-marka', function() {
        const urunId = $(this).data('urun-id');
        if (!urunId) {
            showToast('Bu kalem ürün kartında bulunamadı.', 'warning');
            return;
        }
        $('#modal_urun_id').val(urunId);
        $('#modal_urun_adi').val($(this).data('urun-adi'));
        $('#modal_kategori_id').val(String($(this).data('kategori-id') || '')).trigger('change');
        $('#modal_marka_id').val(String($(this).data('marka-id') || '')).trigger('change');
        kmModal.show();
    });

    // Kaydet
    $('#btnKategoriMarkaKaydet').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true);
        $.post('', {
            action: 'update_kategori_marka',
            urun_id: $('#modal_urun_id').val(),
            kategori_id: $('#modal_kategori_id').val(),
            marka_id: $('#modal_marka_id').val()
        }, null, 'json').done(function(res) {
            if (res.success) {
                showToast(res.message, 'success');
                kmModal.hide();
                table.ajax.reload(null, false);
            } else {
                showToast(res.message, 'error');
            }
        }).fail(function() {
            showToast('Sunucu hatası oluştu.', 'error');
        }).always(function() {
            btn.prop('disabled', false);
        });
    });

    // Excel İndir
    $('#btnExcelIndir').on('click', function() {
        const form = $('<form>', {
            action: '?excel=1',
            method: 'POST'
        });

        const inputs = {
            vkn: $('#vkn').val(),
            baslangic_tarih: $('#baslangic_tarih').val(),
            bitis_tarih: $('#bitis_tarih').val(),
            fatura_no: $('#fatura_no').val(),
            stok_ara: $('#stok_ara').val(),
            kdv_oran: $('#kdv_oran').val(),
            kategori_id: $('#kategori_id').val(),
            marka_id: $('#marka_id').val()
        };

        $.each(inputs, function(name, value) {
            form.append($('<input>', {
                type: 'hidden',
                name: name,
                value: value
            }));
        });

        $('body').append(form);
        form.submit();
        form.remove();
    });
});
</script>
</body>
</html>
