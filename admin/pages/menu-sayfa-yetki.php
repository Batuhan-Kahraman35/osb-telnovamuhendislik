<?php
/**
 * Admin Panel - Menü Sayfa Yetkileri Yönetimi
 * 
 * Bu sayfa menü ve sayfa bazlı kullanıcı grubu yetkilerini yönetir
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
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim Yetkiniz bulunmamaktadır.');
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

// Sayfa bilgileri
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Menü Sayfa Yetkileri';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? 'menü ve sayfa bazlı kullanıcı grubu yetkilerini yönetin';
$menuAdi = $pageinfo['menu_adi'] ?? 'Sistem Ayarları';

// site title'i çek
$siteAyarları = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarları['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'list':
                // Yetki listesini getir
                $yetkiler = $db->fetchAll("
                    SELECT 
                        y.menu_sayfa_yetki_id AS menu_sayfa_Yetki_id,
                        m.menuler_menu_adi,
                        s.sayfalar_sayfa_adi,
                        d.departman_adi,
                        y.gor,
                        y.kendi_kullanicini_gor,
                        y.firma_gor,
                        y.sube_gor,
                        y.bolge_gor,
                        y.ekle,
                        y.duzenle,
                        y.sil,
                        y.durum,
                        CONVERT(VARCHAR(19), y.created_at, 120) as created_at,
                        CONVERT(VARCHAR(19), y.updated_at, 120) as updated_at
                    FROM Menu_SayfaYetkileri y
                    LEFT JOIN Menuler m ON m.menuler_id = y.menu_id
                    LEFT JOIN Menu_Sayfalar s ON s.sayfalar_id = y.sayfa_id
                    LEFT JOIN kullanici_Departmanlar d ON d.departman_id = y.departman_id
                    ORDER BY m.menuler_menu_adi, s.sayfalar_sayfa_adi, d.departman_adi
                ");
                echo json_encode(['success' => true, 'data' => $yetkiler]);
                break;
                
            case 'get':
                $id = $_POST['id'] ?? 0;
                $Yetki = $db->fetchOne("
                    SELECT 
                        menu_sayfa_yetki_id AS menu_sayfa_Yetki_id,
                        menu_id, sayfa_id, departman_id,
                        gor, kendi_kullanicini_gor, firma_gor, sube_gor, bolge_gor,
                        ekle, duzenle, sil, durum
                    FROM Menu_SayfaYetkileri WHERE menu_sayfa_yetki_id = ?
                ", [$id]);
                
                if ($Yetki) {
                    echo json_encode(['success' => true, 'data' => $Yetki]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Yetki kaydı bulunamadı']);
                }
                break;
                
            case 'save':
                // Yetki kontrolü
                $id = $_POST['menu_sayfa_Yetki_id'] ?? 0;
                if ($id > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme Yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme Yetkiniz yok!']);
                    break;
                }
                
                // Düzenleme modu kontrolü
                if ($id > 0) {
                    // Düzenleme: Tek kayıt
                    $menuid = !empty($_POST['menu_id']) ? $_POST['menu_id'] : null;
                    $sayfaid = !empty($_POST['sayfa_id']) ? $_POST['sayfa_id'] : null;
                    $departmanid = !empty($_POST['departman_id']) ? $_POST['departman_id'] : null;
                    
                    if (!$menuid && !$sayfaid) {
                        echo json_encode(['success' => false, 'message' => 'menü veya Sayfa seçmelisiniz!']);
                        break;
                    }
                    
                    $data = [
                        'sayfa_id' => $sayfaid,
                        'menu_id' => $menuid,
                        'departman_id' => $departmanid,
                        'gor' => isset($_POST['gor']) ? 1 : 0,
                        'kendi_kullanicini_gor' => isset($_POST['kendi_kullanicini_gor']) ? 1 : 0,
                        'firma_gor' => isset($_POST['firma_gor']) ? 1 : 0,
                        'sube_gor' => isset($_POST['sube_gor']) ? 1 : 0,
                        'bolge_gor' => isset($_POST['bolge_gor']) ? 1 : 0,
                        'ekle' => isset($_POST['ekle']) ? 1 : 0,
                        'duzenle' => isset($_POST['duzenle']) ? 1 : 0,
                        'sil' => isset($_POST['sil']) ? 1 : 0,
                        'durum' => isset($_POST['durum']) ? 1 : 0,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $db->update('Menu_SayfaYetkileri', $data, ['menu_sayfa_Yetki_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Yetki başarıyla güncellendi']);
                    break;
                }
                
                // Yeni ekleme: Multiselect
                $menuids = isset($_POST['menu_id']) && is_array($_POST['menu_id']) ? array_filter($_POST['menu_id']) : [];
                $sayfaids = isset($_POST['sayfa_id']) && is_array($_POST['sayfa_id']) ? array_filter($_POST['sayfa_id']) : [];
                $departmanid = !empty($_POST['departman_id']) ? $_POST['departman_id'] : null;
                
                // Validasyon: En az bir menü veya sayfa seçilmeli
                if (empty($menuids) && empty($sayfaids)) {
                    echo json_encode(['success' => false, 'message' => 'En az bir menü veya Sayfa seçmelisiniz!']);
                    break;
                }
                
                if (!$departmanid) {
                    echo json_encode(['success' => false, 'message' => 'Departman seçmelisiniz!']);
                    break;
                }
                
                // Yetki bilgileri
                $permissions = [
                    'departman_id' => $departmanid,
                    'gor' => isset($_POST['gor']) ? 1 : 0,
                    'kendi_kullanicini_gor' => isset($_POST['kendi_kullanicini_gor']) ? 1 : 0,
                    'firma_gor' => isset($_POST['firma_gor']) ? 1 : 0,
                    'sube_gor' => isset($_POST['sube_gor']) ? 1 : 0,
                    'bolge_gor' => isset($_POST['bolge_gor']) ? 1 : 0,
                    'ekle' => isset($_POST['ekle']) ? 1 : 0,
                    'duzenle' => isset($_POST['duzenle']) ? 1 : 0,
                    'sil' => isset($_POST['sil']) ? 1 : 0,
                    'durum' => isset($_POST['durum']) ? 1 : 0
                ];
                
                $successcount = 0;
                $skipcount = 0;
                $errorMessages = [];
                
                try {
                    // Menüler için kaydet
                    foreach ($menuids as $menuid) {
                        // Aynı kayıt var mı kontrol et
                        $existing = $db->fetchOne("
                            SELECT menu_sayfa_Yetki_id 
                            FROM Menu_SayfaYetkileri 
                            WHERE departman_id = ? AND menu_id = ? AND sayfa_id IS NULL
                        ", [$departmanid, $menuid]);
                        
                        if ($existing) {
                            $skipcount++;
                            continue;
                        }
                        
                        $data = array_merge($permissions, [
                            'menu_id' => $menuid,
                            'sayfa_id' => null,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        $db->insert('Menu_SayfaYetkileri', $data);
                        $successcount++;
                    }
                    
                    // Sayfalar için kaydet
                    foreach ($sayfaids as $sayfaid) {
                        // Aynı kayıt var mı kontrol et
                        $existing = $db->fetchOne("
                            SELECT menu_sayfa_Yetki_id 
                            FROM Menu_SayfaYetkileri 
                            WHERE departman_id = ? AND sayfa_id = ? AND menu_id IS NULL
                        ", [$departmanid, $sayfaid]);
                        
                        if ($existing) {
                            $skipcount++;
                            continue;
                        }
                        
                        $data = array_merge($permissions, [
                            'menu_id' => null,
                            'sayfa_id' => $sayfaid,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        $db->insert('Menu_SayfaYetkileri', $data);
                        $successcount++;
                    }
                    
                    $message = "$successcount yetki başarıyla eklendi";
                    if ($skipcount > 0) {
                        $message .= ", $skipcount yetki zaten mevcut (atlandı)";
                    }
                    
                    echo json_encode(['success' => true, 'message' => $message]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
                }
                break;
                
            case 'copy':
                // Yetki kontrolü
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                $sourceid = $_POST['source_departman_id'] ?? 0;
                $targetid = $_POST['target_departman_id'] ?? 0;
                $overwrite = isset($_POST['overwrite']) ? 1 : 0;
                
                if (!$sourceid || !$targetid) {
                    echo json_encode(['success' => false, 'message' => 'Kaynak ve hedef departman seçmelisiniz!']);
                    break;
                }
                if ($sourceid == $targetid) {
                    echo json_encode(['success' => false, 'message' => 'Kaynak ve hedef departman aynı olamaz!']);
                    break;
                }
                
                try {
                    // Overwrite if checked
                    if ($overwrite) {
                        $db->delete('Menu_SayfaYetkileri', ['departman_id' => $targetid]);
                    }
                    
                    // Get source permissions
                    $sourceYetkiler = $db->fetchAll("SELECT * FROM Menu_SayfaYetkileri WHERE departman_id = ?", [$sourceid]);
                    
                    $successcount = 0;
                    $skipcount = 0;
                    
                    foreach ($sourceYetkiler as $Yetki) {
                        // Check if exists for target
                        $existing = null;
                        if ($Yetki['menu_id']) {
                            $existing = $db->fetchOne("SELECT menu_sayfa_Yetki_id FROM Menu_SayfaYetkileri WHERE departman_id = ? AND menu_id = ? AND sayfa_id IS NULL", [$targetid, $Yetki['menu_id']]);
                        } elseif ($Yetki['sayfa_id']) {
                            $existing = $db->fetchOne("SELECT menu_sayfa_Yetki_id FROM Menu_SayfaYetkileri WHERE departman_id = ? AND sayfa_id = ? AND menu_id IS NULL", [$targetid, $Yetki['sayfa_id']]);
                        }
                        
                        if ($existing) {
                            $skipcount++;
                            continue;
                        }
                        
                        // Insert new
                        $data = [
                            'departman_id' => $targetid,
                            'menu_id' => $Yetki['menu_id'],
                            'sayfa_id' => $Yetki['sayfa_id'],
                            'gor' => $Yetki['gor'],
                            'kendi_kullanicini_gor' => $Yetki['kendi_kullanicini_gor'],
                            'firma_gor' => $Yetki['firma_gor'],
                            'sube_gor' => $Yetki['sube_gor'],
                            'bolge_gor' => $Yetki['bolge_gor'],
                            'ekle' => $Yetki['ekle'],
                            'duzenle' => $Yetki['duzenle'],
                            'sil' => $Yetki['sil'],
                            'durum' => $Yetki['durum'],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $db->insert('Menu_SayfaYetkileri', $data);
                        $successcount++;
                    }
                    
                    $message = "$successcount yetki başarıyla kopyalandı.";
                    if ($skipcount > 0) {
                        $message .= " $skipcount yetki zaten mevcut olduğu için atlandı.";
                    }
                    
                    echo json_encode(['success' => true, 'message' => $message]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
                }
                break;
                
            case 'bulk_delete':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $departmanid = $_POST['departman_id'] ?? 0;
                
                if (!$departmanid) {
                    echo json_encode(['success' => false, 'message' => 'Departman seçmelisiniz!']);
                    break;
                }
                
                try {
                    $db->delete('Menu_SayfaYetkileri', ['departman_id' => $departmanid]);
                    echo json_encode(['success' => true, 'message' => 'Departmana ait tüm yetkiler başarıyla silindi.']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
                }
                break;
                
            case 'delete_selected':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
                
                if (empty($ids)) {
                    echo json_encode(['success' => false, 'message' => 'Silinecek kayıt seçilmedi!']);
                    break;
                }
                
                try {
                    $deletedCount = 0;
                    foreach ($ids as $id) {
                        $db->delete('Menu_SayfaYetkileri', ['menu_sayfa_Yetki_id' => $id]);
                        $deletedCount++;
                    }
                    echo json_encode(['success' => true, 'message' => $deletedCount . ' yetki başarıyla silindi.']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme Yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->delete('Menu_SayfaYetkileri', ['menu_sayfa_Yetki_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Yetki başarıyla silindi']);
                break;
                
            case 'toggle_status':
                // Yetki kontrolü
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme Yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $currentStatus = $db->fetchOne("SELECT durum FROM Menu_SayfaYetkileri WHERE menu_sayfa_Yetki_id = ?", [$id]);
                $newStatus = $currentStatus['durum'] == 1 ? 0 : 1;
                $db->update('Menu_SayfaYetkileri', 
                    ['durum' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 
                    ['menu_sayfa_Yetki_id' => $id]
                );
                echo json_encode(['success' => true, 'message' => 'Durum güncellendi']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Sayfalar listesi
$sayfalar = $db->fetchAll("SELECT sayfalar_id, sayfalar_sayfa_adi FROM Menu_Sayfalar WHERE sayfalar_durum = 1 ORDER BY sayfalar_sayfa_adi");

// menüler listesi
$menuler = $db->fetchAll("SELECT menuler_id, menuler_menu_adi FROM Menuler WHERE menuler_durum = 1 ORDER BY menuler_menu_adi");

// kullanici_Departmanlar listesi
$kullanici_Departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM kullanici_Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");

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
    
    <style>
        .table-actions {
            white-space: nowrap;
        }
        .permission-badge {
            display: inline-block;
            margin: 2px;
            font-size: 0.75rem;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 20px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #28a745;
        }
        input:checked + .slider:before {
            transform: translateX(20px);
        }
    </style>
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
            
            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- İstatistik Kutuları -->
                    <div class="row mb-4">
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-info">
                                <div class="inner">
                                    <h3 id="totalYetkiler">0</h3>
                                    <p>Toplam Yetki</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-shield-lock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-success">
                                <div class="inner">
                                    <h3 id="activeYetkiler">0</h3>
                                    <p>Aktif Yetki</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-warning">
                                <div class="inner">
                                    <h3 id="totalDepartmanlar">0</h3>
                                    <p>Departman</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-people"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-danger">
                                <div class="inner">
                                    <h3 id="totalSayfalar">0</h3>
                                    <p>Sayfa</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-file-earmark-text"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Karti -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterMenu">menü</label>
                                        <select class="form-select" id="filterMenu">
                                            <option value="">Tümü</option>
                                            <?php foreach ($menuler as $menu): ?>
                                                <option value="<?= htmlspecialchars($menu['menuler_menu_adi']) ?>">
                                                    <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterSayfa">Sayfa</label>
                                        <select class="form-select" id="filterSayfa">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sayfalar as $sayfa): ?>
                                                <option value="<?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>">
                                                    <?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterDepartman">Departman</label>
                                        <select class="form-select" id="filterDepartman">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                                <option value="<?= htmlspecialchars($departman['departman_adi']) ?>">
                                                    <?= htmlspecialchars($departman['departman_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterDurum">Durum</label>
                                        <select class="form-select" id="filterDurum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <button type="button" class="btn btn-primary" onclick="applyfilters()">
                                        <i class="bi bi-search"></i> Filtrele
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="clearfilters()">
                                        <i class="bi bi-x-circle"></i> Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Yetki Tablosu -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-shield-lock"></i> Menü Sayfa Yetkileri
                            </h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_delete']): ?>
                                <button type="button" class="btn btn-warning btn-sm me-1 d-none" id="btnDeleteSelected" onclick="deleteSelected()">
                                    <i class="bi bi-trash-fill"></i> Seçilenleri Sil (<span id="selectedCount">0</span>)
                                </button>
                                <button type="button" class="btn btn-danger btn-sm me-1" onclick="openBulkDeleteModal()">
                                    <i class="bi bi-trash"></i> Toplu Sil
                                </button>
                                <?php endif; ?>
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-info btn-sm text-white me-1" onclick="openCopyModal()">
                                    <i class="bi bi-files"></i> Yetki Kopyala
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Yetki Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="YetkiTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px" class="text-center">
                                                <div class="form-check d-flex justify-content-center">
                                                    <input class="form-check-input" type="checkbox" id="selectAllCheckbox" onchange="toggleAllCheckboxes()">
                                                </div>
                                            </th>
                                            <th style="width: 50px">#</th>
                                            <th>menü</th>
                                            <th>Sayfa</th>
                                            <th>Departman</th>
                                            <th style="width: 300px">Yetkiler</th>
                                            <th style="width: 80px">Durum</th>
                                            <th style="width: 120px">işlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td colspan="8" class="text-center">
                                                <div class="spinner-border" role="status">
                                                    <span class="visually-hidden">Yükleniyor...</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Yetki Ekleme/Düzenleme Modal -->
    <div class="modal fade" id="YetkiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Yetki Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="YetkiForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="menu_sayfa_Yetki_id" id="menu_sayfa_Yetki_id">
                    
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> <strong>Not:</strong> menü veya Sayfa'dan <strong>birini veya birden fazlasını</strong> seçebilirsiniz. Toplu Yetki ataması yapılacaktır.
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="menu_id" class="form-label">Menü (Çoklu Seçim)</label>
                                <select class="form-select" id="menu_id" name="menu_id[]" multiple size="8">
                                    <?php foreach ($menuler as $menu): ?>
                                        <option value="<?= $menu['menuler_id'] ?>">
                                            <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Ctrl+Click ile birden fazla menü seçin</small>
                            </div>
                            <div class="col-md-6">
                                <label for="sayfa_id" class="form-label">Sayfa (Çoklu Seçim)</label>
                                <select class="form-select" id="sayfa_id" name="sayfa_id[]" multiple size="8">
                                    <?php foreach ($sayfalar as $sayfa): ?>
                                        <option value="<?= $sayfa['sayfalar_id'] ?>">
                                            <?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Ctrl+Click ile birden fazla sayfa seçin</small>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="departman_id" class="form-label">Departman</label>
                                <select class="form-select" id="departman_id" name="departman_id" required>
                                    <option value="">Departman Seçiniz</option>
                                    <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                        <option value="<?= $departman['departman_id'] ?>">
                                            <?= htmlspecialchars($departman['departman_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Yetkiler</label>
                                <div class="card">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="gor" name="gor" value="1">
                                                    <label class="form-check-label" for="gor">
                                                        <i class="bi bi-eye text-info"></i> Görüntüleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="kendi_kullanicini_gor" name="kendi_kullanicini_gor" value="1">
                                                    <label class="form-check-label" for="kendi_kullanicini_gor">
                                                        <i class="bi bi-person text-secondary"></i> Kendi Kullanıcısını Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="firma_gor" name="firma_gor" value="1">
                                                    <label class="form-check-label" for="firma_gor">
                                                        <i class="bi bi-building text-primary"></i> Firma Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="sube_gor" name="sube_gor" value="1">
                                                    <label class="form-check-label" for="sube_gor">
                                                        <i class="bi bi-shop text-info"></i> Şube Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="bolge_gor" name="bolge_gor" value="1">
                                                    <label class="form-check-label" for="bolge_gor">
                                                        <i class="bi bi-geo-alt text-success"></i> Bölge Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="ekle" name="ekle" value="1">
                                                    <label class="form-check-label" for="ekle">
                                                        <i class="bi bi-plus-circle text-success"></i> Ekleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="duzenle" name="duzenle" value="1">
                                                    <label class="form-check-label" for="duzenle">
                                                        <i class="bi bi-pencil text-warning"></i> Düzenleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="sil" name="sil" value="1">
                                                    <label class="form-check-label" for="sil">
                                                        <i class="bi bi-trash text-danger"></i> Silme
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="durum" name="durum" value="1" checked>
                                    <label class="form-check-label" for="durum">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Yetki Kopyalama Modal -->
    <div class="modal fade" id="CopyYetkiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-files"></i> Yetki Kopyala</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="CopyYetkiForm">
                    <input type="hidden" name="action" value="copy">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="source_departman_id" class="form-label">Kaynak Departman (Yetkileri alınacak)</label>
                            <select class="form-select" id="source_departman_id" name="source_departman_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                    <option value="<?= $departman['departman_id'] ?>">
                                        <?= htmlspecialchars($departman['departman_adi']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="target_departman_id" class="form-label">Hedef Departman (Yetkileri kopyalanacak)</label>
                            <select class="form-select" id="target_departman_id" name="target_departman_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                    <option value="<?= $departman['departman_id'] ?>">
                                        <?= htmlspecialchars($departman['departman_adi']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite" value="1">
                                <label class="form-check-label" for="overwrite">Hedef departmanın mevcut yetkilerini sil ve üzerine yaz</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-info text-white"><i class="bi bi-check-circle"></i> Kopyala</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Toplu Yetki Silme Modal -->
    <div class="modal fade" id="BulkDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash"></i> Toplu Yetki Sil</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="BulkDeleteForm">
                    <input type="hidden" name="action" value="bulk_delete">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Seçilen departmana ait <strong>tüm menü ve sayfa yetkileri</strong> kalıcı olarak silinecektir.
                        </div>
                        <div class="mb-3">
                            <label for="bulk_delete_departman_id" class="form-label">Departman Seçiniz</label>
                            <select class="form-select" id="bulk_delete_departman_id" name="departman_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                    <option value="<?= $departman['departman_id'] ?>">
                                        <?= htmlspecialchars($departman['departman_adi']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Tüm Yetkileri Sil</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    
    <script>
        // Sayfa yetkileri
        const pagePermissions = {
            can_add: <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>,
            can_edit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>,
            can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        let YetkiModal;
        let CopyYetkiModal;
        let BulkDeleteModal;
        let menuSelect;
        let sayfaSelect;
        let allYetkiler = [];
        let filteredYetkiler = [];
        
        document.addEventListener('DOMContentLoaded', function() {
            YetkiModal = new bootstrap.Modal(document.getElementById('YetkiModal'));
            CopyYetkiModal = new bootstrap.Modal(document.getElementById('CopyYetkiModal'));
            BulkDeleteModal = new bootstrap.Modal(document.getElementById('BulkDeleteModal'));
            menuSelect = document.getElementById('menu_id');
            sayfaSelect = document.getElementById('sayfa_id');
            
            loadYetkiler();
            
            // Form submit
            document.getElementById('YetkiForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validasyon: En az bir menü veya Sayfa seçilmeli
                const selectedMenus = Array.from(menuSelect.selectedOptions).map(opt => opt.value);
                const selectedPages = Array.from(sayfaSelect.selectedOptions).map(opt => opt.value);
                
                if (selectedMenus.length === 0 && selectedPages.length === 0) {
                    showAlert('Lütfen en az bir menü veya Sayfa seçiniz!', 'warning');
                    return;
                }
                
                saveYetki();
            });
            
            // Kopya form submit
            document.getElementById('CopyYetkiForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const sourceId = document.getElementById('source_departman_id').value;
                const targetId = document.getElementById('target_departman_id').value;
                
                if (sourceId === targetId) {
                    showAlert('Kaynak ve hedef departman aynı olamaz!', 'warning');
                    return;
                }
                
                const formData = new FormData(this);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        CopyYetkiModal.hide();
                        loadYetkiler();
                    } else {
                        showAlert('Hata: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Kopyalama sırasında hata oluştu', 'danger');
                });
            });
            
            // Toplu Sil form submit
            document.getElementById('BulkDeleteForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!confirm('Seçilen departmanın tüm yetkileri silinecektir. Onaylıyor musunuz?')) {
                    return;
                }
                
                const formData = new FormData(this);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        BulkDeleteModal.hide();
                        loadYetkiler();
                    } else {
                        showAlert('Hata: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Silme sırasında hata oluştu', 'danger');
                });
            });
        });
        
        // Yetkileri yükle
        function loadYetkiler() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=list'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    allYetkiler = data.data;
                    filteredYetkiler = data.data;
                    updateinfoBoxes();
                    renderTable(filteredYetkiler);
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Veriler yüklenirken hata oluştu', 'danger');
            });
        }
        
        // Info box'ları güncelle
        function updateinfoBoxes() {
            const activeYetkiler = allYetkiler.filter(y => y.durum == 1);
            const uniqueDepartmanlar = [...new Set(allYetkiler.map(y => y.departman_adi))];
            const uniqueSayfalar = [...new Set(allYetkiler.filter(y => y.sayfalar_sayfa_adi).map(y => y.sayfalar_sayfa_adi))];
            
            document.getElementById('totalYetkiler').textContent = allYetkiler.length;
            document.getElementById('activeYetkiler').textContent = activeYetkiler.length;
            document.getElementById('totalDepartmanlar').textContent = uniqueDepartmanlar.length;
            document.getElementById('totalSayfalar').textContent = uniqueSayfalar.length;
        }
        
        // Filtreleri uygula
        function applyfilters() {
            const filterMenu = document.getElementById('filterMenu').value.toLowerCase();
            const filterSayfa = document.getElementById('filterSayfa').value.toLowerCase();
            const filterDepartman = document.getElementById('filterDepartman').value.toLowerCase();
            const filterDurum = document.getElementById('filterDurum').value;
            
            filteredYetkiler = allYetkiler.filter(Yetki => {
                const menuMatch = !filterMenu || (Yetki.menuler_menu_adi && Yetki.menuler_menu_adi.toLowerCase().includes(filterMenu));
                const sayfaMatch = !filterSayfa || (Yetki.sayfalar_sayfa_adi && Yetki.sayfalar_sayfa_adi.toLowerCase().includes(filterSayfa));
                const departmanMatch = !filterDepartman || (Yetki.departman_adi && Yetki.departman_adi.toLowerCase().includes(filterDepartman));
                const durumMatch = filterDurum === '' || Yetki.durum == filterDurum;
                
                return menuMatch && sayfaMatch && departmanMatch && durumMatch;
            });
            
            renderTable(filteredYetkiler);
            
            showAlert(`${filteredYetkiler.length} kayıt bulundu`, 'info');
        }
        
        // Filtreleri temizle
        function clearfilters() {
            document.getElementById('filterMenu').value = '';
            document.getElementById('filterSayfa').value = '';
            document.getElementById('filterDepartman').value = '';
            document.getElementById('filterDurum').value = '';
            
            filteredYetkiler = allYetkiler;
            renderTable(filteredYetkiler);
            
            showAlert('Filtreler temizlendi', 'info');
        }
        
        // Tabloyu render et
        function renderTable(yetkiler) {
            const tbody = document.querySelector('#YetkiTable tbody');
            
            if (yetkiler.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center">Henüz yetki kaydı bulunmuyor</td></tr>';
                updateDeleteSelectedButton();
                return;
            }
            
            let html = '';
            yetkiler.forEach((Yetki, index) => {
                const permissions = [];
                if (Yetki.gor == 1) permissions.push('<span class="badge bg-info permission-badge"><i class="bi bi-eye"></i> Gör</span>');
                if (Yetki.kendi_kullanicini_gor == 1) permissions.push('<span class="badge bg-secondary permission-badge"><i class="bi bi-person"></i> Kendi</span>');
                if (Yetki.firma_gor == 1) permissions.push('<span class="badge bg-primary permission-badge"><i class="bi bi-building"></i> firma</span>');
                if (Yetki.sube_gor == 1) permissions.push('<span class="badge bg-info permission-badge"><i class="bi bi-shop"></i> Şube</span>');
                if (Yetki.bolge_gor == 1) permissions.push('<span class="badge bg-success permission-badge"><i class="bi bi-geo-alt"></i> Bölge</span>');
                if (Yetki.ekle == 1) permissions.push('<span class="badge bg-success permission-badge"><i class="bi bi-plus"></i> Ekle</span>');
                if (Yetki.duzenle == 1) permissions.push('<span class="badge bg-warning permission-badge"><i class="bi bi-pencil"></i> Dçzenle</span>');
                if (Yetki.sil == 1) permissions.push('<span class="badge bg-danger permission-badge"><i class="bi bi-trash"></i> Sil</span>');
                
                const statusChecked = Yetki.durum == 1 ? 'checked' : '';
                const statusClass = Yetki.durum == 1 ? 'bg-success' : 'bg-secondary';
                
                html += `
                    <tr>
                        <td class="text-center">
                            <div class="form-check d-flex justify-content-center">
                                <input class="form-check-input row-checkbox" type="checkbox" value="${Yetki.menu_sayfa_Yetki_id}" onchange="updateDeleteSelectedButton()">
                            </div>
                        </td>
                        <td>${index + 1}</td>
                        <td>${Yetki.menuler_menu_adi || '-'}</td>
                        <td>${Yetki.sayfalar_sayfa_adi || '-'}</td>
                        <td><span class="badge ${statusClass}">${Yetki.departman_adi || '-'}</span></td>
                        <td>${permissions.join(' ')}</td>
                        <td class="text-center">
                            ${pagePermissions.can_edit ? `
                            <label class="switch">
                                <input type="checkbox" ${statusChecked} onchange="toggleStatus(${Yetki.menu_sayfa_Yetki_id})">
                                <span class="slider"></span>
                            </label>
                            ` : `<span class="badge ${statusClass}">${Yetki.durum == 1 ? 'Aktif' : 'Pasif'}</span>`}
                        </td>
                        <td class="table-actions">
                            ${pagePermissions.can_edit ? `
                            <button class="btn btn-sm btn-warning" onclick="editYetki(${Yetki.menu_sayfa_Yetki_id})" title="Dçzenle">
                                <i class="bi bi-pencil"></i>
                            </button>
                            ` : ''}
                            ${pagePermissions.can_delete ? `
                            <button class="btn btn-sm btn-danger" onclick="deleteYetki(${Yetki.menu_sayfa_Yetki_id})" title="Sil">
                                <i class="bi bi-trash"></i>
                            </button>
                            ` : ''}
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
            document.getElementById('selectAllCheckbox').checked = false;
            updateDeleteSelectedButton();
        }
        
        // Checkbox yönetimi
        function toggleAllCheckboxes() {
            const isChecked = document.getElementById('selectAllCheckbox').checked;
            const checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(cb => cb.checked = isChecked);
            updateDeleteSelectedButton();
        }
        
        function updateDeleteSelectedButton() {
            const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
            const btn = document.getElementById('btnDeleteSelected');
            const countSpan = document.getElementById('selectedCount');
            
            if (btn && countSpan) {
                if (checkedCount > 0) {
                    btn.classList.remove('d-none');
                    countSpan.textContent = checkedCount;
                } else {
                    btn.classList.add('d-none');
                }
            }
            
            const selectAll = document.getElementById('selectAllCheckbox');
            if (selectAll) {
                const totalCheckboxes = document.querySelectorAll('.row-checkbox').length;
                selectAll.checked = totalCheckboxes > 0 && checkedCount === totalCheckboxes;
            }
        }
        
        function deleteSelected() {
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            if (checkboxes.length === 0) return;
            
            if (!confirm(`${checkboxes.length} adet yetki kaydını silmek istediğinizden emin misiniz?`)) {
                return;
            }
            
            const ids = Array.from(checkboxes).map(cb => cb.value);
            const formData = new URLSearchParams();
            formData.append('action', 'delete_selected');
            ids.forEach(id => formData.append('ids[]', id));
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Silme sırasında hata oluştu', 'danger');
            });
        }
        
        // Toplu Silme Modalı aç
        function openBulkDeleteModal() {
            document.getElementById('BulkDeleteForm').reset();
            BulkDeleteModal.show();
        }

        // Kopyalama Modalı aç
        function openCopyModal() {
            document.getElementById('CopyYetkiForm').reset();
            CopyYetkiModal.show();
        }

        // Modal aç
        function openModal(id = null) {
            document.getElementById('YetkiForm').reset();
            document.getElementById('menu_sayfa_Yetki_id').value = '';
            document.getElementById('modalTitle').textContent = id ? 'Yetki Dçzenle' : 'Toplu Yetki Ekle';
            document.getElementById('durum').checked = true;
            
            // Multiselect seçimlerini temizle
            menuSelect.value = '';
            sayfaSelect.value = '';
            $('#menu_id').val(null).trigger('change');
            $('#sayfa_id').val(null).trigger('change');
            
            // Multiselect'i göster/gizle
            if (id) {
                // Düzenlemede multiselect gizle, single select göster
                menuSelect.removeAttribute('multiple');
                sayfaSelect.removeAttribute('multiple');
                menuSelect.removeAttribute('size');
                sayfaSelect.removeAttribute('size');
                loadYetkidata(id);
            } else {
                // Yeni eklemede multiselect göster
                menuSelect.setAttribute('multiple', 'multiple');
                sayfaSelect.setAttribute('multiple', 'multiple');
                menuSelect.setAttribute('size', '8');
                sayfaSelect.setAttribute('size', '8');
            }
            
            YetkiModal.show();
        }
        
        // Yetki verilerini yükle (Düzenleme için)
        function loadYetkidata(id) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const Yetki = data.data;
                    document.getElementById('menu_sayfa_Yetki_id').value = Yetki.menu_sayfa_Yetki_id;
                    menuSelect.value = Yetki.menu_id || '';
                    sayfaSelect.value = Yetki.sayfa_id || '';
                    document.getElementById('departman_id').value = Yetki.departman_id || '';
                    document.getElementById('gor').checked = Yetki.gor == 1;
                    document.getElementById('kendi_kullanicini_gor').checked = Yetki.kendi_kullanicini_gor == 1;
                    document.getElementById('firma_gor').checked = Yetki.firma_gor == 1;
                    document.getElementById('sube_gor').checked = Yetki.sube_gor == 1;
                    document.getElementById('bolge_gor').checked = Yetki.bolge_gor == 1;
                    document.getElementById('ekle').checked = Yetki.ekle == 1;
                    document.getElementById('duzenle').checked = Yetki.duzenle == 1;
                    document.getElementById('sil').checked = Yetki.sil == 1;
                    document.getElementById('durum').checked = Yetki.durum == 1;
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Yetki kaydet
        function saveYetki() {
            const formData = new FormData(document.getElementById('YetkiForm'));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    YetkiModal.hide();
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Kayıt sırasında hata oluştu', 'danger');
            });
        }
        
        // Dçzenle
        function editYetki(id) {
            openModal(id);
        }
        
        // Yetki sil
        function deleteYetki(id) {
            if (!confirm('Bu Yetkiyi silmek istediğinizden emin misiniz?')) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Durum değiştir
        function toggleStatus(id) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=toggle_status&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Alert göster
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
            alertDiv.style.zIndex = '9999';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
    </script>
</body>
</html>

