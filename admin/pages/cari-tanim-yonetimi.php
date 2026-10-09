<?php
/**
 * Admin Panel - Cari Tanım Yönetimi
 * Sektörler ve Birimler yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolü
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Mevcut sayfanın bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Cari Tanım Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? 'Sektör ve Birim tanımları';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $type = $_POST['type'] ?? ''; // sektor veya birim
    
    try {
        // Tablo ve kolon bilgilerini belirle
        $tableConfig = [
            'sektor' => [
                'table' => 'Cari_Sektorler',
                'id_col' => 'CariSektor_Id',
                'name_col' => 'CariSektor_Adi',
                'desc_col' => 'CariSektor_Aciklama',
                'order_col' => 'CariSektor_Sira',
                'fk_table' => 'Cari',
                'fk_col' => 'cari_sektor_id',
                'label' => 'Sektör'
            ],
            'birim' => [
                'table' => 'Cari_Birimler',
                'id_col' => 'CariBirim_Id',
                'name_col' => 'CariBirim_Adi',
                'desc_col' => 'CariBirim_Aciklama',
                'order_col' => 'CariBirim_Sira',
                'fk_table' => 'CariYetkili',
                'fk_col' => 'CariYetkili_BirimId',
                'label' => 'Birim'
            ],
            'cari_tipi' => [
                'table' => 'Cari_CariTipleri',
                'id_col' => 'cari_tipi_id',
                'name_col' => 'cari_tipi_ad',
                'desc_col' => 'cari_tipi_ad',
                'order_col' => 'cari_tipi_sira',
                'icon_col' => 'cari_tipi_ikon',
                'color_col' => 'cari_tipi_renk',
                'fk_table' => 'Cari',
                'fk_col' => 'cari_tipi_id',
                'label' => 'Cari Tipi'
            ]
        ];
        
        $cfg = $tableConfig[$type] ?? null;
        
        switch ($action) {
            case 'stats':
                $sektorStats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Cari_Sektorler")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Cari_Sektorler WHERE Durum = 1")['sayi'] ?? 0,
                    'kullanilan' => $db->fetchOne("SELECT COUNT(DISTINCT cari_sektor_id) as sayi FROM Cari WHERE cari_sektor_id IS NOT NULL")['sayi'] ?? 0
                ];
                $birimStats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Cari_Birimler")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Cari_Birimler WHERE Durum = 1")['sayi'] ?? 0,
                    'kullanilan' => $db->fetchOne("SELECT COUNT(DISTINCT CariYetkili_BirimId) as sayi FROM CariYetkili WHERE CariYetkili_BirimId IS NOT NULL")['sayi'] ?? 0
                ];
                $cariTipiStats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Cari_CariTipleri")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Cari_CariTipleri WHERE cari_tipi_durum = 1")['sayi'] ?? 0,
                    'kullanilan' => $db->fetchOne("SELECT COUNT(DISTINCT cari_tipi_id) as sayi FROM Cari WHERE cari_tipi_id IS NOT NULL")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'sektor' => $sektorStats, 'birim' => $birimStats, 'cari_tipi' => $cariTipiStats]);
                break;
                
            case 'list':
                if (!$cfg) throw new Exception('Geçersiz tür!');

                $selectCols = "{$cfg['id_col']} as id, {$cfg['name_col']} as adi, {$cfg['desc_col']} as aciklama, {$cfg['order_col']} as sira";
                $selectCols .= isset($cfg['color_col']) ? ", {$cfg['color_col']} as renk" : "";
                $selectCols .= isset($cfg['icon_col']) ? ", {$cfg['icon_col']} as ikon" : "";

                if ($type === 'cari_tipi') {
                    $statusCol = 'cari_tipi_durum';
                    $sql = "SELECT
                                {$selectCols},
                                {$statusCol} as durum,
                                '' as olusturma_tarihi,
                                '' as guncelleme_tarihi,
                                (SELECT COUNT(*) FROM {$cfg['fk_table']} WHERE {$cfg['fk_col']} = t.{$cfg['id_col']}) as kullanim_sayisi
                            FROM {$cfg['table']} t
                            ORDER BY {$cfg['order_col']}, {$cfg['name_col']}";
                } else {
                    $statusCol = 'Durum';
                    $sql = "SELECT
                                {$selectCols},
                                {$statusCol} as durum,
                                CONVERT(VARCHAR(19), OlusturmaTarihi, 120) as olusturma_tarihi,
                                CONVERT(VARCHAR(19), GuncellemeTarihi, 120) as guncelleme_tarihi,
                                (SELECT COUNT(*) FROM {$cfg['fk_table']} WHERE {$cfg['fk_col']} = t.{$cfg['id_col']}) as kullanim_sayisi
                            FROM {$cfg['table']} t
                            ORDER BY {$cfg['order_col']}, {$cfg['name_col']}";
                }

                $data = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'get':
                if (!$cfg) throw new Exception('Geçersiz tür!');
                $id = intval($_POST['id'] ?? 0);

                $selectCols = "{$cfg['id_col']} as id, {$cfg['name_col']} as adi, {$cfg['desc_col']} as aciklama, {$cfg['order_col']} as sira";
                $selectCols .= isset($cfg['color_col']) ? ", ISNULL({$cfg['color_col']}, '') as renk" : "";
                $selectCols .= isset($cfg['icon_col']) ? ", ISNULL({$cfg['icon_col']}, '') as ikon" : "";

                if ($type === 'cari_tipi') {
                    $statusCol = 'cari_tipi_durum';
                } else {
                    $statusCol = 'Durum';
                }

                $sql = "SELECT
                            {$selectCols},
                            {$statusCol} as durum
                        FROM {$cfg['table']}
                        WHERE {$cfg['id_col']} = ?";

                $item = $db->fetchOne($sql, [$id]);
                if ($item) {
                    echo json_encode(['success' => true, 'data' => $item]);
                } else {
                    echo json_encode(['success' => false, 'message' => $cfg['label'] . ' bulunamadı!']);
                }
                break;
                
            case 'save':
                if (!$cfg) throw new Exception('Geçersiz tür!');

                $id = intval($_POST['id'] ?? 0);

                // Yetki kontrolü
                if ($id > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $adi = trim($_POST['adi'] ?? '');
                $aciklama = trim($_POST['aciklama'] ?? '');
                $sira = intval($_POST['sira'] ?? 0);
                $ikon = isset($_POST['ikon']) ? trim($_POST['ikon']) : null;
                $renk = isset($_POST['renk']) ? trim($_POST['renk']) : null;

                if ($type === 'cari_tipi') {
                    $durum = isset($_POST['durum']) ? 1 : 0;
                } else {
                    $durum = isset($_POST['durum']) ? 1 : 0;
                }

                if (empty($adi)) {
                    echo json_encode(['success' => false, 'message' => $cfg['label'] . ' adı zorunludur!']);
                    break;
                }

                // Mükerrer kontrol
                $checkSql = "SELECT {$cfg['id_col']} FROM {$cfg['table']} WHERE {$cfg['name_col']} = ?";
                $checkParams = [$adi];
                if ($id > 0) {
                    $checkSql .= " AND {$cfg['id_col']} <> ?";
                    $checkParams[] = $id;
                }
                $mevcut = $db->fetchOne($checkSql, $checkParams);
                if ($mevcut) {
                    echo json_encode(['success' => false, 'message' => 'Bu isimde bir ' . strtolower($cfg['label']) . ' zaten mevcut!']);
                    break;
                }

                if ($id > 0) {
                    // Güncelleme
                    if ($type === 'cari_tipi') {
                        $statusCol = 'cari_tipi_durum';
                        $sql = "UPDATE {$cfg['table']} SET
                                    {$cfg['name_col']} = ?,
                                    {$cfg['desc_col']} = ?,
                                    {$cfg['order_col']} = ?,
                                    {$statusCol} = ?,
                                    {$cfg['icon_col']} = ?,
                                    {$cfg['color_col']} = ?
                                WHERE {$cfg['id_col']} = ?";
                        $db->execute($sql, [$adi, $aciklama, $sira, $durum, $ikon, $renk, $id]);
                    } else {
                        $sql = "UPDATE {$cfg['table']} SET
                                    {$cfg['name_col']} = ?,
                                    {$cfg['desc_col']} = ?,
                                    {$cfg['order_col']} = ?,
                                    Durum = ?,
                                    GuncelleyenKullanici = ?,
                                    GuncellemeTarihi = GETDATE()
                                WHERE {$cfg['id_col']} = ?";
                        $db->execute($sql, [$adi, $aciklama, $sira, $durum, $user['kullanici_id'], $id]);
                    }
                    echo json_encode(['success' => true, 'message' => $cfg['label'] . ' başarıyla güncellendi!']);
                } else {
                    // Ekleme
                    if ($type === 'cari_tipi') {
                        $statusCol = 'cari_tipi_durum';
                        $sql = "INSERT INTO {$cfg['table']} (
                                    {$cfg['name_col']},
                                    {$cfg['desc_col']},
                                    {$cfg['order_col']},
                                    {$statusCol},
                                    {$cfg['icon_col']},
                                    {$cfg['color_col']}
                                ) VALUES (?, ?, ?, ?, ?, ?)";
                        $db->execute($sql, [$adi, $aciklama, $sira, $durum, $ikon, $renk]);
                    } else {
                        $sql = "INSERT INTO {$cfg['table']} (
                                    {$cfg['name_col']},
                                    {$cfg['desc_col']},
                                    {$cfg['order_col']},
                                    Durum,
                                    OlusturanKullanici,
                                    OlusturmaTarihi
                                ) VALUES (?, ?, ?, ?, ?, GETDATE())";
                        $db->execute($sql, [$adi, $aciklama, $sira, $durum, $user['kullanici_id']]);
                    }
                    echo json_encode(['success' => true, 'message' => $cfg['label'] . ' başarıyla eklendi!']);
                }
                break;
                
            case 'delete':
                if (!$cfg) throw new Exception('Geçersiz tür!');
                
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = intval($_POST['id'] ?? 0);
                
                // Kullanım kontrolü
                $usageCheck = $db->fetchOne("SELECT COUNT(*) as sayi FROM {$cfg['fk_table']} WHERE {$cfg['fk_col']} = ?", [$id]);
                if ($usageCheck['sayi'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu ' . strtolower($cfg['label']) . ' ' . $usageCheck['sayi'] . ' caride kullanılıyor! Önce carilerdeki bağlantıyı kaldırın.']);
                    break;
                }
                
                $sql = "DELETE FROM {$cfg['table']} WHERE {$cfg['id_col']} = ?";
                $db->execute($sql, [$id]);
                echo json_encode(['success' => true, 'message' => $cfg['label'] . ' başarıyla silindi!']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başlığı -->
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
            
            <!-- Sayfa içeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-2">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-building"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Sektör</span>
                                    <span class="info-box-number" id="stat-sektor-toplam">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-2">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Sektör</span>
                                    <span class="info-box-number" id="stat-sektor-aktif">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-2">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-people"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Birim</span>
                                    <span class="info-box-number" id="stat-birim-toplam">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-2">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-check2-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Birim</span>
                                    <span class="info-box-number" id="stat-birim-aktif">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-2">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-tags"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Cari Tipi</span>
                                    <span class="info-box-number" id="stat-cari-tipi-toplam">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-2">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm">
                                    <i class="bi bi-tag-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Cari Tipi</span>
                                    <span class="info-box-number" id="stat-cari-tipi-aktif">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tab Navigation -->
                    <div class="card">
                        <div class="card-header p-0 pt-1">
                            <ul class="nav nav-tabs" id="tanimTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="sektor-tab" data-bs-toggle="tab" data-bs-target="#sektor-content" type="button" role="tab">
                                        <i class="bi bi-building me-1"></i> Sektörler
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="birim-tab" data-bs-toggle="tab" data-bs-target="#birim-content" type="button" role="tab">
                                        <i class="bi bi-people me-1"></i> Birimler
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="cari-tipi-tab" data-bs-toggle="tab" data-bs-target="#cari-tipi-content" type="button" role="tab">
                                        <i class="bi bi-tags me-1"></i> Cari Tipleri
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="tanimTabContent">
                                
                                <!-- Sektörler Tab -->
                                <div class="tab-pane fade show active" id="sektor-content" role="tabpanel">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="mb-0">Sektör Listesi</h5>
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('sektor')">
                                            <i class="bi bi-plus-lg"></i> Yeni Sektör
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table id="sektorTable" class="table table-bordered table-striped table-hover w-100" style="width:100%">
                                            <thead>
                                                <tr>
                                                    <th width="60">Sıra</th>
                                                    <th>Sektör Adı</th>
                                                    <th>Açıklama</th>
                                                    <th width="100">Kullanım</th>
                                                    <th width="80">Durum</th>
                                                    <th width="120">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- Birimler Tab -->
                                <div class="tab-pane fade" id="birim-content" role="tabpanel">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="mb-0">Birim Listesi</h5>
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('birim')">
                                            <i class="bi bi-plus-lg"></i> Yeni Birim
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table id="birimTable" class="table table-bordered table-striped table-hover w-100" style="width:100%">
                                            <thead>
                                                <tr>
                                                    <th width="60">Sıra</th>
                                                    <th>Birim Adı</th>
                                                    <th>Açıklama</th>
                                                    <th width="100">Kullanım</th>
                                                    <th width="80">Durum</th>
                                                    <th width="120">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Cari Tipleri Tab -->
                                <div class="tab-pane fade" id="cari-tipi-content" role="tabpanel">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="mb-0">Cari Tipi Listesi</h5>
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('cari_tipi')">
                                            <i class="bi bi-plus-lg"></i> Yeni Cari Tipi
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table id="cariTipiTable" class="table table-bordered table-striped table-hover w-100" style="width:100%">
                                            <thead>
                                                <tr>
                                                    <th width="60">Sıra</th>
                                                    <th width="80">İkon</th>
                                                    <th>Adı</th>
                                                    <th width="100">Renk</th>
                                                    <th width="100">Kullanım</th>
                                                    <th width="80">Durum</th>
                                                    <th width="120">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Modal -->
    <div class="modal fade" id="itemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="itemForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Yeni Kayıt</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="item_id" name="id" value="0">
                        <input type="hidden" id="item_type" name="type" value="">

                        <div class="mb-3">
                            <label for="item_adi" class="form-label">Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="item_adi" name="adi" required>
                        </div>

                        <div class="mb-3">
                            <label for="item_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="item_aciklama" name="aciklama" rows="2"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="item_sira" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="item_sira" name="sira" value="0" min="0">
                                </div>
                            </div>

                            <div class="col-md-6" id="iconField" style="display: none;">
                                <div class="mb-3">
                                    <label for="item_ikon" class="form-label">İkon (Bootstrap Icons)</label>
                                    <input type="text" class="form-control" id="item_ikon" name="ikon" placeholder="Örn: bi-tag, bi-star">
                                    <small class="form-text text-muted">Bootstrap Icons sınıf adı (bi-... formatında)</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6" id="colorField" style="display: none;">
                                <div class="mb-3">
                                    <label for="item_renk" class="form-label">Renk</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" id="item_renk" name="renk" value="#000000" style="max-width: 60px;">
                                        <input type="text" class="form-control" id="item_renk_hex" readonly placeholder="#000000">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="item_durum" name="durum" checked>
                            <label class="form-check-label" for="item_durum">Aktif</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        let sektorTable, birimTable, cariTipiTable;
        const canEdit = <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>;
        const canDelete = <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>;

        $(document).ready(function() {
            // DataTables
            sektorTable = initDataTable('sektorTable', 'sektor');
            birimTable = initDataTable('birimTable', 'birim');
            cariTipiTable = initDataTable('cariTipiTable', 'cari_tipi');

            // İstatistikleri yükle
            loadStats();

            // Tab değişiminde tabloyu yeniden çiz
            $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
                $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
            });

            // Form submit
            $('#itemForm').on('submit', function(e) {
                e.preventDefault();
                saveItem();
            });

            // Renk input senkronizasyonu
            $('#item_renk').on('change', function() {
                $('#item_renk_hex').val($(this).val().toUpperCase());
            });
        });
        
        function initDataTable(tableId, type) {
            let columns = [];

            if (type === 'cari_tipi') {
                columns = [
                    { data: 'sira', className: 'text-center' },
                    {
                        data: 'ikon',
                        className: 'text-center',
                        render: function(data) {
                            return data ? '<i class="bi ' + data + ' fs-5"></i>' : '-';
                        }
                    },
                    { data: 'adi' },
                    {
                        data: 'renk',
                        className: 'text-center',
                        render: function(data) {
                            if (!data) return '-';
                            return '<div style="display:inline-block; width:30px; height:30px; background-color:' + data + '; border:1px solid #ccc; border-radius:3px;" title="' + data + '"></div>';
                        }
                    },
                    {
                        data: 'kullanim_sayisi',
                        className: 'text-center',
                        render: function(data) {
                            return '<span class="badge bg-secondary">' + data + ' cari</span>';
                        }
                    },
                    {
                        data: 'durum',
                        className: 'text-center',
                        render: function(data) {
                            return data == 1
                                ? '<span class="badge bg-success">Aktif</span>'
                                : '<span class="badge bg-danger">Pasif</span>';
                        }
                    },
                    {
                        data: 'id',
                        orderable: false,
                        className: 'text-center',
                        render: function(data, t, row) {
                            let btns = '';
                            if (canEdit) {
                                btns += '<button class="btn btn-sm btn-warning me-1" onclick="editItem(\'' + type + '\', ' + data + ')" title="Düzenle"><i class="bi bi-pencil"></i></button>';
                            }
                            if (canDelete) {
                                btns += '<button class="btn btn-sm btn-danger" onclick="deleteItem(\'' + type + '\', ' + data + ', \'' + row.adi + '\')" title="Sil"><i class="bi bi-trash"></i></button>';
                            }
                            return btns || '-';
                        }
                    }
                ];
            } else {
                columns = [
                    { data: 'sira', className: 'text-center' },
                    { data: 'adi' },
                    { data: 'aciklama', defaultContent: '-' },
                    {
                        data: 'kullanim_sayisi',
                        className: 'text-center',
                        render: function(data) {
                            return '<span class="badge bg-secondary">' + data + ' cari</span>';
                        }
                    },
                    {
                        data: 'durum',
                        className: 'text-center',
                        render: function(data) {
                            return data == 1
                                ? '<span class="badge bg-success">Aktif</span>'
                                : '<span class="badge bg-danger">Pasif</span>';
                        }
                    },
                    {
                        data: 'id',
                        orderable: false,
                        className: 'text-center',
                        render: function(data, t, row) {
                            let btns = '';
                            if (canEdit) {
                                btns += '<button class="btn btn-sm btn-warning me-1" onclick="editItem(\'' + type + '\', ' + data + ')" title="Düzenle"><i class="bi bi-pencil"></i></button>';
                            }
                            if (canDelete) {
                                btns += '<button class="btn btn-sm btn-danger" onclick="deleteItem(\'' + type + '\', ' + data + ', \'' + row.adi + '\')" title="Sil"><i class="bi bi-trash"></i></button>';
                            }
                            return btns || '-';
                        }
                    }
                ];
            }

            return $('#' + tableId).DataTable({
                processing: true,
                ajax: {
                    url: window.location.href,
                    type: 'POST',
                    data: { action: 'list', type: type },
                    dataSrc: function(json) {
                        return json.success ? json.data : [];
                    }
                },
                columns: columns,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
                },
                order: [[0, 'asc']],
                pageLength: 25,
                autoWidth: false
            });
        }
        
        function loadStats() {
            $.post(window.location.href, { action: 'stats' }, function(res) {
                if (res.success) {
                    $('#stat-sektor-toplam').text(res.sektor.toplam);
                    $('#stat-sektor-aktif').text(res.sektor.aktif);
                    $('#stat-birim-toplam').text(res.birim.toplam);
                    $('#stat-birim-aktif').text(res.birim.aktif);
                    $('#stat-cari-tipi-toplam').text(res.cari_tipi.toplam);
                    $('#stat-cari-tipi-aktif').text(res.cari_tipi.aktif);
                }
            }, 'json');
        }
        
        function openModal(type, id = 0) {
            const labels = { sektor: 'Sektör', birim: 'Birim', cari_tipi: 'Cari Tipi' };
            $('#item_type').val(type);
            $('#item_id').val(id);
            $('#modalTitle').text(id > 0 ? labels[type] + ' Düzenle' : 'Yeni ' + labels[type]);

            // Ikon ve renk alanlarını göster/gizle
            if (type === 'cari_tipi') {
                $('#iconField').show();
                $('#colorField').show();
            } else {
                $('#iconField').hide();
                $('#colorField').hide();
            }

            // Formu temizle
            $('#item_adi').val('');
            $('#item_aciklama').val('');
            $('#item_sira').val(0);
            $('#item_ikon').val('');
            $('#item_renk').val('#000000');
            $('#item_renk_hex').val('#000000');
            $('#item_durum').prop('checked', true);

            if (id > 0) {
                // Veriyi getir
                $.post(window.location.href, { action: 'get', type: type, id: id }, function(res) {
                    if (res.success) {
                        $('#item_adi').val(res.data.adi);
                        $('#item_aciklama').val(res.data.aciklama);
                        $('#item_sira').val(res.data.sira);
                        $('#item_durum').prop('checked', res.data.durum == 1);

                        if (type === 'cari_tipi') {
                            $('#item_ikon').val(res.data.ikon || '');
                            $('#item_renk').val(res.data.renk || '#000000');
                            $('#item_renk_hex').val(res.data.renk || '#000000');
                        }
                    }
                }, 'json');
            }

            new bootstrap.Modal($('#itemModal')[0]).show();
        }
        
        function editItem(type, id) {
            openModal(type, id);
        }
        
        function saveItem() {
            const formData = new FormData($('#itemForm')[0]);
            formData.append('action', 'save');

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        bootstrap.Modal.getInstance($('#itemModal')[0]).hide();
                        Swal.fire({ icon: 'success', title: 'Başarılı', text: res.message, timer: 1500, showConfirmButton: false });

                        // Tabloları ve istatistikleri yenile
                        const type = $('#item_type').val();
                        if (type === 'sektor') {
                            sektorTable.ajax.reload();
                        } else if (type === 'birim') {
                            birimTable.ajax.reload();
                        } else if (type === 'cari_tipi') {
                            cariTipiTable.ajax.reload();
                        }
                        loadStats();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Hata', text: res.message });
                    }
                }
            });
        }
        
        function deleteItem(type, id, name) {
            const labels = { sektor: 'Sektör', birim: 'Birim', cari_tipi: 'Cari Tipi' };

            Swal.fire({
                title: 'Emin misiniz?',
                html: '<strong>' + name + '</strong> adlı ' + labels[type].toLowerCase() + ' silinecek!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet, Sil!',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post(window.location.href, { action: 'delete', type: type, id: id }, function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', title: 'Silindi!', text: res.message, timer: 1500, showConfirmButton: false });
                            if (type === 'sektor') {
                                sektorTable.ajax.reload();
                            } else if (type === 'birim') {
                                birimTable.ajax.reload();
                            } else if (type === 'cari_tipi') {
                                cariTipiTable.ajax.reload();
                            }
                            loadStats();
                        } else {
                            Swal.fire({ icon: 'error', title: 'Hata', text: res.message });
                        }
                    }, 'json');
                }
            });
        }
    </script>
</body>
</html>
