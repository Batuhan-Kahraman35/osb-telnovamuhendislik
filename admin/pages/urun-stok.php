<?php
/**
 * Admin Panel - Ürün Stok Listesi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

// AJAX istekleri için özel auth kontrolü
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::check()) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Oturum süresi doldu. Lütfen tekrar giriş yapın.', 'redirect' => '/Admin/login.php']));
    }
} else {
    requireAuth();
}

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
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

// Sayfa bilgileri
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Ürün Stok';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

// Erişim Yetkisi yoksa hata sayfası göster
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim Yetkiniz yok!');
}

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'OrnekFirma Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'list':
                // Filtreleri al
                $kategoriid = $_POST['kategori_id'] ?? '';
                $markaid = $_POST['marka_id'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $search = $_POST['search'] ?? '';
                
                // WHERE koşullari (Sadece Ürünleri Listele, Hizmetlerde stok olmaz)
                $whereConditions = ["uh.urun_hizmet_tip = 1"];
                $params = [];
                
                if ($kategoriid) {
                    $whereConditions[] = "uh.urun_hizmet_kategori_id = ?";
                    $params[] = $kategoriid;
                }
                
                if ($markaid) {
                    $whereConditions[] = "uh.urun_hizmet_marka_id = ?";
                    $params[] = $markaid;
                }
                
                if ($durum !== '') {
                    $whereConditions[] = "uh.urun_hizmet_durum = ?";
                    $params[] = $durum;
                }
                
                if ($search) {
                    $whereConditions[] = "(uh.urun_hizmet_kodu LIKE ? OR uh.urun_hizmet_adi LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                $sql = "SELECT 
                            uh.urun_hizmet_id,
                            uh.urun_hizmet_kodu,
                            uh.urun_hizmet_adi,
                            uh.urun_hizmet_model,
                            uh.urun_hizmet_kategori_id,
                            uh.urun_hizmet_marka_id,
                            ISNULL(uh.Urun_Stok_Miktari, 0) as StokAdedi,
                            ISNULL(uh.Urun_Hizmet_AlisFiyati, 0) as AlisFiyati,
                            uh.urun_hizmet_satis_fiyati as SatisFiyati,
                            ISNULL(pb.ParaBirimi_Kod, 'TRY') as ParaBirimi,
                            uh.urun_hizmet_kdv_id,
                            uh.urun_hizmet_gorsel_url,
                            uh.urun_hizmet_sira_no,
                            uh.urun_hizmet_durum,
                            k.kategori_adi,
                            m.marka_adi,
                            kdv.kdv_oran
                        FROM Urun_Hizmet uh
                        LEFT JOIN UrunHizmet_Kategoriler k ON k.kategori_id = uh.urun_hizmet_kategori_id
                        LEFT JOIN UrunHizmet_Markalar m ON m.marka_id = uh.urun_hizmet_marka_id
                        LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                        LEFT JOIN tanim_ParaBirimi pb ON pb.ParaBirimi_id = uh.urun_hizmet_para_birimi_id
                        WHERE $whereClause
                        ORDER BY uh.urun_hizmet_sira_no, uh.urun_hizmet_adi";
                
                $urunler = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $urunler]);
                break;
                
            case 'stats':
                // istatistikleri getir
                $stats = [
                    'toplam_urun' => $db->fetchOne("SELECT count(*) as sayi FROM Urun_Hizmet WHERE urun_hizmet_tip = 1")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT count(*) as sayi FROM Urun_Hizmet WHERE urun_hizmet_tip = 1 AND urun_hizmet_durum = 1")['sayi'] ?? 0,
                    'kritik_stok' => $db->fetchOne("SELECT count(*) as sayi FROM Urun_Hizmet WHERE urun_hizmet_tip = 1 AND urun_hizmet_kritik_stok > 0")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'get_kategoriler':
                $sql = "SELECT kategori_id, kategori_adi FROM UrunHizmet_Kategoriler WHERE kategori_durum = 1 ORDER BY kategori_sira_no, kategori_adi";
                $UrunHizmet_Kategoriler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $UrunHizmet_Kategoriler]);
                break;
                
            case 'get_markalar':
                $sql = "SELECT marka_id, marka_adi FROM UrunHizmet_Markalar WHERE marka_durum = 1 ORDER BY marka_sira_no, marka_adi";
                $UrunHizmet_Markalar = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $UrunHizmet_Markalar]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Hata oluştu. Veritabanı kolonları uyuşmuyor olabilir: ' . $e->getMessage()]);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .urun-img-preview {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 4px;
            border: 1px solid #ddd;
            padding: 2px;
            background: white;
        }
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
                    <!-- info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-box-seam"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Ürün</span>
                                    <span class="info-box-number" id="stat-urun">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kritik Stok</span>
                                    <span class="info-box-number" id="stat-kritik">0</span>
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
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="true">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse show" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <!-- Kategori -->
                                    <div class="col-md-3">
                                        <label class="form-label">Kategori</label>
                                        <select class="form-select" name="kategori_id" id="filter_kategori_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Marka -->
                                    <div class="col-md-3">
                                        <label class="form-label">Marka</label>
                                        <select class="form-select" name="marka_id" id="filter_marka_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Arama -->
                                    <div class="col-md-6">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kod veya ad...">
                                    </div>
                                    
                                    <!-- Butonlar -->
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
                    
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Ürün Stok Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm" onclick="exportToExcel()" id="btnExcelIndir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="urunTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Görsel</th>
                                        <th>Kod</th>
                                        <th>Ad</th>
                                        <th>Kategori</th>
                                        <th>Marka</th>
                                        <th>Stok Adedi</th>
                                        <th>Satış Fiyatı</th>
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
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentfilters = {};
        
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-urun').text(response.data.toplam_urun);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-kritik').text(response.data.kritik_stok);
                }
            });
        }
        
        let hasSearched = false;

        function exportToExcel() {
            const btn = $('#btnExcelIndir');
            btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Hazırlanıyor...');

            $.post('', { action: 'list', ...currentfilters }, function(response) {
                if (!response.success || !response.data.length) {
                    showToast('Dışa aktarılacak veri bulunamadı.', 'warning');
                    btn.prop('disabled', false).html('<i class="bi bi-file-earmark-excel"></i> Excel İndir');
                    return;
                }

                const rows = response.data.map(r => ({
                    'Kod'           : r.urun_hizmet_kodu || '',
                    'Ad'            : r.urun_hizmet_adi || '',
                    'Model'         : r.urun_hizmet_model || '',
                    'Kategori'      : r.kategori_adi || '',
                    'Marka'         : r.marka_adi || '',
                    'Stok Adedi'    : parseFloat(r.StokAdedi || 0),
                    'Alış Fiyatı'   : parseFloat(r.AlisFiyati || 0),
                    'Satış Fiyatı'  : parseFloat(r.SatisFiyati || 0),
                    'Para Birimi'   : r.ParaBirimi || 'TRY',
                    'KDV Oranı (%)' : parseFloat(r.kdv_oran || 0),
                    'Durum'         : r.urun_hizmet_durum == 1 ? 'Aktif' : 'Pasif'
                }));

                const ws = XLSX.utils.json_to_sheet(rows);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Ürün Stok');

                ws['!cols'] = Object.keys(rows[0]).map(k => ({ wch: Math.max(k.length, 14) }));

                const tarih = new Date().toISOString().slice(0, 10);
                XLSX.writeFile(wb, `urun-stok-listesi_${tarih}.xlsx`);

                showToast(`${rows.length} kayıt Excel'e aktarıldı.`, 'success');
            }, 'json')
            .fail(() => showToast('Veri alınamadı.', 'error'))
            .always(() => {
                btn.prop('disabled', false).html('<i class="bi bi-file-earmark-excel"></i> Excel İndir');
            });
        }

        function initDataTable() {
            table = $('#urunTable').DataTable({
                processing: true,
                language: { 
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json',
                    emptyTable: "Ürünleri görüntülemek için arama veya filtreleme yapınız."
                },
                ajax: function(data, callback, settings) {
                    if (!hasSearched) {
                        callback({ data: [] });
                        return;
                    }
                    $.ajax({
                        url: '',
                        type: 'POST',
                        data: { action: 'list', ...currentfilters },
                        success: function(json) {
                            if (json.success) {
                                callback({ data: json.data });
                            } else {
                                console.error(json.message);
                                callback({ data: [] });
                            }
                        },
                        error: function() {
                            callback({ data: [] });
                        }
                    });
                },
                columns: [
                    { 
                        data: 'urun_hizmet_gorsel_url',
                        orderable: false,
                        render: data => {
                            if (!data) return '<i class="bi bi-image text-muted" style="font-size: 2rem;"></i>';
                            const imgPath = data.startsWith('assets/') ? '/Admin/' + data : data;
                            return `<img src="${imgPath}" class="urun-img-preview">`;
                        }
                    },
                    { data: 'urun_hizmet_kodu', defaultContent: '-' },
                    { data: 'urun_hizmet_adi' },
                    { data: 'kategori_adi', defaultContent: '-' },
                    { data: 'marka_adi', defaultContent: '-' },
                    { 
                        data: 'StokAdedi', 
                        render: function(data, type) {
                            let miktar = Number(data) || 0;
                            if (type === 'display') {
                                return `<span class="badge bg-info" style="font-size:14px">${miktar}</span>`;
                            }
                            return miktar;
                        }
                    },
                    { 
                        data: 'SatisFiyati',
                        render: function(data, type, row) {
                            if (type === 'display' || type === 'filter') {
                                let formatted = new Intl.NumberFormat('tr-TR', { 
                                    style: 'decimal', 
                                    minimumFractionDigits: 2
                                }).format(data);
                                return formatted + ' ' + (row.ParaBirimi || 'TRY');
                            }
                            return data;
                        }
                    }
                ],
                order: [[2, 'asc']]
            });
        }
        
        function loadKategoriler(targetSelect = '#urun_hizmet_kategori_id') {
            $.post('', { action: 'get_kategoriler' }, response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(k => select.append(`<option value="${k.kategori_id}">${k.kategori_adi}</option>`));
                    select.select2({ theme: 'bootstrap-5' });
                }
            });
        }
        
        function loadMarkalar(targetSelect = '#urun_hizmet_marka_id') {
            $.post('', { action: 'get_markalar' }, response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(m => select.append(`<option value="${m.marka_id}">${m.marka_adi}</option>`));
                    select.select2({ theme: 'bootstrap-5' });
                }
            });
        }
        
        $(document).ready(() => {
            loadStats();
            initDataTable();
            
            // Filtre dropdown'larini doldur
            loadKategoriler('#filter_kategori_id');
            loadMarkalar('#filter_marka_id');
            $('#filter_durum').select2({ theme: 'bootstrap-5' });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                // Filtreleri topla
                currentfilters = {
                    kategori_id: $('#filter_kategori_id').val(),
                    marka_id: $('#filter_marka_id').val(),
                    search: $('#filter_search').val()
                };
                
                // Boş değerleri kaldir
                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });
                
                hasSearched = true;
                table.ajax.reload();
            });
            
            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_kategori_id').val('').trigger('change.select2');
                $('#filter_marka_id').val('').trigger('change.select2');
                currentfilters = {};
                hasSearched = true;
                table.ajax.reload();
            });
        });
    </script>
</body>
</html>
