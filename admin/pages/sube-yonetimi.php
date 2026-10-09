<?php
/**
 * Admin Panel - Şube tanimlama
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Mevcut sayfanin bilgilerini al
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOiN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LiKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

// Sayfa bilgileri
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Şube tanimlama';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['id'], $user['departman_id'], $currentPagefile);

// Erişim Yetkisi yoksa hata sayfasi gçster
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim Yetkiniz yok!');
}

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // istatistikler
                $toplam = $db->fetchOne("SELECT count(*) as total FROM firmalarSubeler")['total'] ?? 0;
                $aktif = $db->fetchOne("SELECT count(*) as total FROM firmalarSubeler WHERE sube_durum = 1")['total'] ?? 0;
                $pasif = $db->fetchOne("SELECT count(*) as total FROM firmalarSubeler WHERE sube_durum = 0")['total'] ?? 0;
                $yeni = $db->fetchOne("SELECT count(*) as total FROM firmalarSubeler WHERE DATEDiFF(day, sube_olusturma_tarihi, GETDATE()) <= 30")['total'] ?? 0;
                
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'toplam' => $toplam,
                        'aktif' => $aktif,
                        'pasif' => $pasif,
                        'yeni' => $yeni
                    ]
                ]);
                break;
            
            case 'list':
                // Filtreleri al
                $search = $_POST['search'] ?? '';
                $status = $_POST['status'] ?? '';
                $firma_id = $_POST['firma_id'] ?? '';
                
                // WHERE koşullari
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($search) {
                    $whereConditions[] = "(s.sube_adi LiKE ? OR s.sube_kod LiKE ? OR s.sube_Yetkili_adi LiKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                if ($status !== '') {
                    $whereConditions[] = "s.sube_durum = ?";
                    $params[] = $status;
                }
                
                if ($firma_id) {
                    $whereConditions[] = "s.sube_firma_id = ?";
                    $params[] = $firma_id;
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                // Şube listesini getir
                $subeList = $db->fetchAll("
                    SELECT 
                        s.sube_id,
                        s.sube_adi,
                        s.sube_kod,
                        s.sube_telefon,
                        s.sube_email,
                        s.sube_Yetkili_adi,
                        s.sube_Yetkili_telefon,
                        s.sube_durum,
                        CONVERT(VARCHAR(19), s.sube_olusturma_tarihi, 120) as sube_olusturma_tarihi,
                        f.firma_adi,
                        sh.SehirAdi as sehir_adi,
                        i.ilceAdi as ilce_adi,
                        ko.kullanici_ad + ' ' + ko.kullanici_soyad as olusturan_kullanici,
                        kg.kullanici_ad + ' ' + kg.kullanici_soyad as guncelleyen_kullanici,
                        CONVERT(VARCHAR(19), s.sube_guncelleme_tarihi, 120) as sube_guncelleme_tarihi
                    FROM firmalarSubeler s
                    LEFT JOiN firmalar f ON s.sube_firma_id = f.firma_id
                    LEFT JOiN kullanicilar ko ON s.sube_olusturan_kullanici_id = ko.kullanici_id
                    LEFT JOiN kullanicilar kg ON s.sube_guncelleyen_kullanici_id = kg.kullanici_id
                    LEFT JOiN Adres_Sehirler sh ON s.sube_sehir_id = sh.Sehirid
                    LEFT JOiN Adres_ilceler i ON s.sube_ilce_id = i.ilceid
                    WHERE $whereClause
                    ORDER BY s.sube_olusturma_tarihi DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $subeList]);
                break;
                
            case 'get':
                // Tek bir şube kaydini getir
                $subeid = $_POST['sube_id'] ?? 0;
                $sube = $db->fetchOne("SELECT * FROM firmalarSubeler WHERE sube_id = ?", [$subeid]);
                echo json_encode(['success' => true, 'data' => $sube]);
                break;
                
            case 'save':
                // Yeni şube kaydet veya gçncelle
                $subeid = $_POST['sube_id'] ?? 0;
                
                // ilçe ve şehir boş ise NULL yap (foreign key ihlali olmasin)
                $sehirid = !empty($_POST['sube_sehir_id']) ? $_POST['sube_sehir_id'] : null;
                $ilceid = !empty($_POST['sube_ilce_id']) ? $_POST['sube_ilce_id'] : null;
                
                $data = [
                    'sube_firma_id' => $_POST['sube_firma_id'] ?? null,
                    'sube_adi' => $_POST['sube_adi'] ?? '',
                    'sube_kod' => $_POST['sube_kod'] ?? null,
                    'sube_telefon' => $_POST['sube_telefon'] ?? null,
                    'sube_email' => $_POST['sube_email'] ?? null,
                    'sube_adres' => $_POST['sube_adres'] ?? null,
                    'sube_sehir_id' => $sehirid,
                    'sube_ilce_id' => $ilceid,
                    'sube_Yetkili_adi' => $_POST['sube_Yetkili_adi'] ?? null,
                    'sube_Yetkili_telefon' => $_POST['sube_Yetkili_telefon'] ?? null,
                    'sube_durum' => isset($_POST['sube_durum']) ? 1 : 0,
                ];
                
                if ($subeid > 0) {
                    // gçncelleme
                    $data['sube_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['sube_guncelleyen_kullanici_id'] = $user['id'];
                    
                    $result = $db->update('firmalarSubeler', $data, ['sube_id' => $subeid]);
                    echo json_encode([
                        'success' => $result, 
                        'message' => $result ? 'Şube basariyla gçncellendi' : 'gçncelleme sirasinda hata oluştu'
                    ]);
                } else {
                    // Yeni kayit
                    $data['sube_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['sube_olusturan_kullanici_id'] = $user['id'];
                    
                    $result = $db->insert('firmalarSubeler', $data);
                    echo json_encode([
                        'success' => $result, 
                        'message' => $result ? 'Şube basariyla eklendi' : 'Kayit eklenirken hata oluştu'
                    ]);
                }
                break;
                
            case 'delete':
                // Şube sil
                $subeid = $_POST['sube_id'] ?? 0;
                $result = $db->delete('firmalarSubeler', ['sube_id' => $subeid]);
                echo json_encode(['success' => $result, 'message' => $result ? 'Şube basariyla silindi' : 'Silme hatasi']);
                break;
                
            case 'getilceler':
                // Şehire gçre ilçeleri getir
                $sehirid = $_POST['sehir_id'] ?? 0;
                $ilceler = $db->fetchAll("SELECT ilceid, ilceAdi FROM Adres_ilceler WHERE Sehirid = ? ORDER BY ilceAdi", [$sehirid]);
                echo json_encode(['success' => true, 'data' => $ilceler]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// firma listesini çek
$firmaList = $db->fetchAll("SELECT firma_id, firma_adi FROM firmalar WHERE firma_durum = 1 ORDER BY firma_adi");

// Şehir listesini çek
$sehirList = $db->fetchAll("SELECT Sehirid, SehirAdi FROM Adres_Sehirler ORDER BY SehirAdi");

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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başliği -->
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
                    
                    <!-- info Boxes (istatistikler) -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-building"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Şube</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Şube</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif Şube</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-clock-history"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Yeni Şube (30 Gçn)</span>
                                    <span class="info-box-number" id="stat-yeni">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Karti -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">firma</label>
                                        <select class="form-select" name="firma_id" id="filter_firma_id">
                                            <option value="">Tçmç</option>
                                            <?php foreach ($firmaList as $firma): ?>
                                                <option value="<?= $firma['firma_id'] ?>"><?= htmlspecialchars($firma['firma_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Şube adi, kodu, Yetkili...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="status" id="filter_status">
                                            <option value="">Tçmç</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearfilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Şube Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Şube Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#subeModal" onclick="resetForm()">
                                    <i class="bi bi-plus-circle"></i> Yeni Şube Ekle
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="subeTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>firma</th>
                                        <th>Şube Adi</th>
                                        <th>Şube Kodu</th>
                                        <th>Şehir/ilçe</th>
                                        <th>Yetkili</th>
                                        <th>Telefon</th>
                                        <th>Durum</th>
                                        <th>Oluşturma</th>
                                        <th>işlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- DataTable ile doldurulacak -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Şube Modal -->
    <div class="modal fade" id="subeModal" tabindex="-1" aria-labelledby="subeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="subeModalLabel">Yeni Şube Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="subeForm">
                    <div class="modal-body">
                        <input type="hidden" id="sube_id" name="sube_id" value="">
                        
                        <div class="row">
                            <!-- firma -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_firma_id" class="form-label">firma <span class="text-danger">*</span></label>
                                <select class="form-select" id="sube_firma_id" name="sube_firma_id" required>
                                    <option value="">firma Seçiniz...</option>
                                    <?php foreach ($firmaList as $firma): ?>
                                        <option value="<?= $firma['firma_id'] ?>"><?= htmlspecialchars($firma['firma_adi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Şube Adi -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_adi" class="form-label">Şube Adi <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="sube_adi" name="sube_adi" required>
                            </div>
                            
                            <!-- Şube Kodu -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_kod" class="form-label">Şube Kodu</label>
                                <input type="text" class="form-control" id="sube_kod" name="sube_kod">
                            </div>
                            
                            <!-- Telefon -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_telefon" class="form-label">Telefon</label>
                                <input type="text" class="form-control" id="sube_telefon" name="sube_telefon">
                            </div>
                            
                            <!-- E-posta -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_email" class="form-label">E-posta</label>
                                <input type="email" class="form-control" id="sube_email" name="sube_email">
                            </div>
                            
                            <!-- Şehir -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_sehir_id" class="form-label">Şehir</label>
                                <select class="form-select" id="sube_sehir_id" name="sube_sehir_id" onchange="loadilceler()">
                                    <option value="">Şehir Seçiniz...</option>
                                    <?php foreach ($sehirList as $sehir): ?>
                                        <option value="<?= $sehir['Sehirid'] ?>"><?= htmlspecialchars($sehir['SehirAdi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- ilçe -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_ilce_id" class="form-label">ilçe</label>
                                <select class="form-select" id="sube_ilce_id" name="sube_ilce_id" disabled>
                                    <option value="">çnce Şehir Seçiniz...</option>
                                </select>
                            </div>
                            
                            <!-- Adres -->
                            <div class="col-md-12 mb-3">
                                <label for="sube_adres" class="form-label">Adres</label>
                                <textarea class="form-control" id="sube_adres" name="sube_adres" rows="2"></textarea>
                            </div>
                            
                            <!-- Yetkili Adi -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_Yetkili_adi" class="form-label">Yetkili Adi</label>
                                <input type="text" class="form-control" id="sube_Yetkili_adi" name="sube_Yetkili_adi">
                            </div>
                            
                            <!-- Yetkili Telefon -->
                            <div class="col-md-6 mb-3">
                                <label for="sube_Yetkili_telefon" class="form-label">Yetkili Telefon</label>
                                <input type="text" class="form-control" id="sube_Yetkili_telefon" name="sube_Yetkili_telefon">
                            </div>
                            
                            <!-- Durum -->
                            <div class="col-md-12 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="sube_durum" name="sube_durum" checked>
                                    <label class="form-check-label" for="sube_durum">
                                        Aktif
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> iptal
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        let subeModal;
        let dataTable;
        
        // tarih formatlama Fonksiyonu (MSSQL uyumlu)
        function formatDate(datestring) {
            if (!datestring) return '-';
            
            try {
                // MSSQL datetime object ise date property'sini al
                if (typeof datestring === 'object' && datestring.date) {
                    datestring = datestring.date;
                }
                
                // string değilse string'e çevir
                if (typeof datestring !== 'string') {
                    datestring = string(datestring);
                }
                
                // MSSQL datetime formatini JavaScript Date'e çevir
                const date = new Date(datestring.replace(' ', 'T'));
                
                if (isNaN(date.gettime())) {
                    return '-';
                }
                
                return date.toLocaleDatestring('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                console.error('tarih formatlama hatasi:', datestring, e);
                return '-';
            }
        }
        
        // showToast(), confirmaction(), showSuccess(), showError() artik custom.js'den geliyor
        
        let currentfilters = {};
        
        // Sayfa yçklendiğinde
        $(document).ready(function() {
            subeModal = new bootstrap.Modal(document.getElementByid('subeModal'));
            
            // Select2 başlat (arama çzellikli dropdown)
            $('.form-select').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadi"; },
                    searching: function() { return "Araniyor..."; }
                }
            });
            
            // Modal içindeki select'ler için dropdownParent ekle
            $('#sube_firma_id, #sube_sehir_id, #sube_ilce_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $('#subeModal'),
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadi"; },
                    searching: function() { return "Araniyor..."; }
                }
            });
            
            // DataTable başlat
            dataTable = $('#subeTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'
                },
                order: [[7, 'desc']], // Oluşturma tarihine gçre sirala
                columnDefs: [
                    { orderable: false, targets: [8] } // işlemler kolonu siralama kapali
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tçmç"]]
            });
            
            // Şube listesini yçkle
            loadSubeList();
            loadStats();
            
            // Form submit
            $('#subeForm').on('submit', function(e) {
                e.preventdefault();
                saveSube();
            });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventdefault();
                
                currentfilters = {
                    firma_id: $('#filter_firma_id').val(),
                    search: $('#filter_search').val(),
                    status: $('#filter_status').val()
                };
                
                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });
                
                loadSubeList();
                showToast('Filtre uygulandi', 'info');
            });
            
            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_firma_id').val('').trigger('change.select2');
                $('#filter_status').val('').trigger('change.select2');
                currentfilters = {};
                loadSubeList();
                showToast('Filtreler temizlendi', 'info');
            });
        });
        
        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                    $('#stat-yeni').text(response.data.yeni);
                }
            });
        }
        
        // ilçeleri yçkle
        function loadilceler() {
            const sehirid = document.getElementByid('sube_sehir_id').value;
            const ilceSelect = document.getElementByid('sube_ilce_id');
            
            if (!sehirid) {
                ilceSelect.innerHTML = '<option value="">çnce Şehir Seçiniz...</option>';
                ilceSelect.disabled = true;
                // Select2'yi yeniden başlat
                $('#sube_ilce_id').select2('destroy').select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $('#subeModal'),
                    placeholder: 'çnce Şehir Seçiniz...',
                    allowClear: true,
                    language: {
                        noResults: function() { return "Sonuç bulunamadi"; },
                        searching: function() { return "Araniyor..."; }
                    }
                });
                return;
            }
            
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'getilceler', sehir_id: sehirid },
                dataType: 'json',
                success: function(data) {
                    ilceSelect.innerHTML = '<option value="">ilçe Seçiniz...</option>';
                    
                    if (data.success && data.data.length > 0) {
                        data.data.forEach(ilce => {
                            const option = document.createElement('option');
                            option.value = ilce.ilceid;
                            option.textContent = ilce.ilceAdi;
                            ilceSelect.appendChild(option);
                        });
                        ilceSelect.disabled = false;
                        
                        // Select2'yi yeniden başlat (yeni seçeneklerle)
                        $('#sube_ilce_id').select2('destroy').select2({
                            theme: 'bootstrap-5',
                            width: '100%',
                            dropdownParent: $('#subeModal'),
                            placeholder: 'ilçe Seçiniz...',
                            allowClear: true,
                            language: {
                                noResults: function() { return "Sonuç bulunamadi"; },
                                searching: function() { return "Araniyor..."; }
                            }
                        });
                    } else {
                        showToast('Bu Şehir için ilçe bulunamadi', 'warning');
                        ilceSelect.disabled = true;
                        // Select2'yi yeniden başlat
                        $('#sube_ilce_id').select2('destroy').select2({
                            theme: 'bootstrap-5',
                            width: '100%',
                            dropdownParent: $('#subeModal'),
                            placeholder: 'çnce Şehir Seçiniz...',
                            allowClear: true,
                            language: {
                                noResults: function() { return "Sonuç bulunamadi"; },
                                searching: function() { return "Araniyor..."; }
                            }
                        });
                    }
                },
                error: function(error) {
                    showToast('ilçeler yçklenirken hata oluştu', 'error');
                    ilceSelect.disabled = true;
                }
            });
        }
        
        // Şube listesini yçkle
        function loadSubeList() {
            $.ajax({
                url: '',
                method: 'POST',
                data: { 
                    action: 'list',
                    ...currentfilters
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        renderSubeTable(data.data);
                    } else {
                        showToast('Liste yçklenirken hata oluştu', 'error');
                    }
                },
                error: function(error) {
                    showToast('Sunucu hatasi oluştu', 'error');
                }
            });
        }
        
        // Şube tablosunu render et
        function renderSubeTable(firmalarSubeler) {
            dataTable.clear();
            
            firmalarSubeler.forEach(sube => {
                const durumBadge = sube.sube_durum 
                    ? '<span class="status-badge status-active">Aktif</span>' 
                    : '<span class="status-badge status-inactive">Pasif</span>';
                
                const lokasyon = [sube.sehir_adi, sube.ilce_adi].filter(Boolean).join(' / ') || '-';
                const olusturmatarihi = formatDate(sube.sube_olusturma_tarihi);
                
                dataTable.row.add([
                    sube.firma_adi || '-',
                    sube.sube_adi,
                    sube.sube_kod || '-',
                    lokasyon,
                    sube.sube_Yetkili_adi || '-',
                    sube.sube_telefon || '-',
                    durumBadge,
                    olusturmatarihi,
                    `
                        <button class="btn btn-sm btn-warning" onclick="editSube(${sube.sube_id})" title="Dçzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteSube(${sube.sube_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `
                ]);
            });
            
            dataTable.draw();
        }
        
        // Formu sifirla
        function resetForm() {
            document.getElementByid('subeForm').reset();
            document.getElementByid('sube_id').value = '';
            document.getElementByid('subeModalLabel').textContent = 'Yeni Şube Ekle';
            document.getElementByid('sube_ilce_id').disabled = true;
            document.getElementByid('sube_ilce_id').innerHTML = '<option value="">çnce Şehir Seçiniz...</option>';
        }
        
        // Şube kaydet
        function saveSube() {
            const formData = new FormData(document.getElementByid('subeForm'));
            formData.append('action', 'save');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        subeModal.hide();
                        loadSubeList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function(error) {
                    showToast('Kayit sirasinda hata oluştu', 'error');
                }
            });
        }
        
        // Şube dçzenle
        function editSube(subeid) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', sube_id: subeid },
                dataType: 'json',
                success: function(data) {
                    if (data.success && data.data) {
                        const sube = data.data;
                        
                        document.getElementByid('sube_id').value = sube.sube_id;
                        document.getElementByid('sube_firma_id').value = sube.sube_firma_id || '';
                        document.getElementByid('sube_adi').value = sube.sube_adi || '';
                        document.getElementByid('sube_kod').value = sube.sube_kod || '';
                        document.getElementByid('sube_telefon').value = sube.sube_telefon || '';
                        document.getElementByid('sube_email').value = sube.sube_email || '';
                        document.getElementByid('sube_adres').value = sube.sube_adres || '';
                        document.getElementByid('sube_Yetkili_adi').value = sube.sube_Yetkili_adi || '';
                        document.getElementByid('sube_Yetkili_telefon').value = sube.sube_Yetkili_telefon || '';
                        document.getElementByid('sube_durum').checked = sube.sube_durum == 1;
                        
                        // Şehir ve ilçe
                        if (sube.sube_sehir_id) {
                            document.getElementByid('sube_sehir_id').value = sube.sube_sehir_id;
                            
                            // ilçeleri yçkle ve sonra ilçeyi seç
                            $.ajax({
                                url: '',
                                method: 'POST',
                                data: { action: 'getilceler', sehir_id: sube.sube_sehir_id },
                                dataType: 'json',
                                success: function(ilceData) {
                                    const ilceSelect = document.getElementByid('sube_ilce_id');
                                    ilceSelect.innerHTML = '<option value="">ilçe Seçiniz...</option>';
                                    
                                    if (ilceData.success && ilceData.data.length > 0) {
                                        ilceData.data.forEach(ilce => {
                                            const option = document.createElement('option');
                                            option.value = ilce.ilceid;
                                            option.textContent = ilce.ilceAdi;
                                            ilceSelect.appendChild(option);
                                        });
                                        ilceSelect.disabled = false;
                                        
                                        if (sube.sube_ilce_id) {
                                            ilceSelect.value = sube.sube_ilce_id;
                                        }
                                    }
                                }
                            });
                        }
                        
                        document.getElementByid('subeModalLabel').textContent = 'Şube Dçzenle';
                        subeModal.show();
                    }
                },
                error: function(error) {
                    showToast('Kayit yçklenirken hata oluştu', 'error');
                }
            });
        }
        
        // Şube sil
        function deleteSube(subeid) {
            confirmaction(
                'Bu şubeyi silmek istediğinize emin misiniz?',
                'Bu işlem geri alinamaz!',
                function() {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'delete', sube_id: subeid },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success) {
                                showSuccess('Silindi!', data.message);
                                loadSubeList();
                                loadStats();
                            } else {
                                showError('Hata!', data.message);
                            }
                        },
                        error: function(error) {
                            showError('Bağlanti Hatasi!', 'Sunucuya ulaşilamiyor.');
                        }
                    });
                }
            );
        }
    </script>
</body>
</html>
