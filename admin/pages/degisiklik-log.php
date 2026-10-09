<?php
/**
 * Sistem Değişiklik Logları - Read Only
 * [dbo].[Sistem_DegisiklikLog] tablosunu görüntüleme sayfası
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Değişiklik Logları';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// URL parametreleri (dış sayfalardan link ile gelme)
$urlTablo = $_GET['tablo'] ?? '';
$urlKayitId = $_GET['kayit_id'] ?? '';
$urlSayfa = $_GET['sayfa'] ?? '';
// Benzersiz tablolar ve sayfalar listesi (filtre için)
$tablolar = $db->fetchAll("SELECT DISTINCT log_tablo FROM Sistem_DegisiklikLog ORDER BY log_tablo");
$sayfalar = $db->fetchAll("SELECT DISTINCT log_sayfa FROM Sistem_DegisiklikLog ORDER BY log_sayfa");
$islemTipleri = ['INSERT', 'UPDATE', 'DELETE'];

// Kullanıcı listesi
$kullanicilar = $db->fetchAll("SELECT kullanici_id, kullanici_ad, kullanici_soyad FROM Kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Sistem_DegisiklikLog")['sayi'] ?? 0,
                    'insert' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Sistem_DegisiklikLog WHERE log_islem_tipi = 'INSERT'")['sayi'] ?? 0,
                    'update' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Sistem_DegisiklikLog WHERE log_islem_tipi = 'UPDATE'")['sayi'] ?? 0,
                    'delete' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Sistem_DegisiklikLog WHERE log_islem_tipi = 'DELETE'")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametreleri
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $tablo = $_POST['tablo'] ?? '';
                $sayfa = $_POST['sayfa'] ?? '';
                $islemTipi = $_POST['islem_tipi'] ?? '';
                $kullaniciId = $_POST['kullanici_id'] ?? '';
                $search = $_POST['search'] ?? '';
                $kayitId = $_POST['kayit_id'] ?? '';
                
                // SQL oluştur
                $sql = "SELECT 
                            l.log_id,
                            l.log_sayfa,
                            l.log_tablo,
                            l.log_kayit_id,
                            l.log_islem_tipi,
                            l.log_kullanici_id,
                            CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih,
                            l.log_degisiklikler,
                            l.log_aciklama,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
                        FROM Sistem_DegisiklikLog l
                        LEFT JOIN Kullanicilar k ON l.log_kullanici_id = k.kullanici_id
                        WHERE 1=1";
                $params = [];
                
                if ($startDate) {
                    $sql .= " AND CONVERT(date, l.log_tarih) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, l.log_tarih) <= ?";
                    $params[] = $endDate;
                }
                if ($tablo) {
                    $sql .= " AND l.log_tablo = ?";
                    $params[] = $tablo;
                }
                if ($sayfa) {
                    $sql .= " AND l.log_sayfa = ?";
                    $params[] = $sayfa;
                }
                if ($islemTipi) {
                    $sql .= " AND l.log_islem_tipi = ?";
                    $params[] = $islemTipi;
                }
                if ($kullaniciId) {
                    $sql .= " AND l.log_kullanici_id = ?";
                    $params[] = $kullaniciId;
                }
                if ($search) {
                    $sql .= " AND (l.log_tablo LIKE ? OR l.log_sayfa LIKE ? OR CAST(l.log_kayit_id AS VARCHAR) LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($kayitId) {
                    $sql .= " AND l.log_kayit_id = ?";
                    $params[] = $kayitId;
                }
                
                $sql .= " ORDER BY l.log_id DESC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
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
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .badge-insert { background-color: #198754; }
        .badge-update { background-color: #0d6efd; }
        .badge-delete { background-color: #dc3545; }
        td.dt-control { cursor: pointer; text-align: center; }
        tr.shown { background-color: #f8f9fa !important; }
        .table-warning td { background-color: #fff3cd !important; }
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
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-list-ul"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Log</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-plus-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">INSERT</span>
                                    <span class="info-box-number" id="stat-insert">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-pencil"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">UPDATE</span>
                                    <span class="info-box-number" id="stat-update">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-trash"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">DELETE</span>
                                    <span class="info-box-number" id="stat-delete">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                        </div>
                        <div class="card-body">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tablo</label>
                                        <select class="form-select" id="filter_tablo" name="tablo">
                                            <option value="">Tümü</option>
                                            <?php foreach ($tablolar as $t): ?>
                                                <option value="<?= htmlspecialchars($t['log_tablo']) ?>"><?= htmlspecialchars($t['log_tablo']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sayfa</label>
                                        <select class="form-select" id="filter_sayfa" name="sayfa">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sayfalar as $s): ?>
                                                <option value="<?= htmlspecialchars($s['log_sayfa']) ?>"><?= htmlspecialchars($s['log_sayfa']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İşlem Tipi</label>
                                        <select class="form-select" id="filter_islem_tipi" name="islem_tipi">
                                            <option value="">Tümü</option>
                                            <?php foreach ($islemTipleri as $tip): ?>
                                                <option value="<?= $tip ?>"><?= $tip ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Kullanıcı</label>
                                        <select class="form-select" id="filter_kullanici" name="kullanici_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kullanicilar as $k): ?>
                                                <option value="<?= $k['kullanici_id'] ?>"><?= htmlspecialchars($k['kullanici_ad'] . ' ' . $k['kullanici_soyad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Arama</label>
                                        <input type="text" class="form-control" id="filter_search" name="search" placeholder="Tablo, Sayfa...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Kayıt ID</label>
                                        <input type="number" class="form-control" id="filter_kayit_id" name="kayit_id" placeholder="Kayıt ID">
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-clock-history"></i> Log Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th width="30"></th>
                                            <th width="60">#</th>
                                            <th>Tarih</th>
                                            <th>Kullanıcı</th>
                                            <th>Sayfa</th>
                                            <th>Tablo</th>
                                            <th>Kayıt ID</th>
                                            <th>İşlem</th>
                                            <th>Açıklama</th>
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
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let table = null;
        let currentFilters = {};
        
        // URL parametreleri
        const urlParams = {
            tablo: '<?= addslashes($urlTablo) ?>',
            kayit_id: '<?= addslashes($urlKayitId) ?>',
            sayfa: '<?= addslashes($urlSayfa) ?>'
        };
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam.toLocaleString('tr-TR'));
                    $('#stat-insert').text(response.data.insert.toLocaleString('tr-TR'));
                    $('#stat-update').text(response.data.update.toLocaleString('tr-TR'));
                    $('#stat-delete').text(response.data.delete.toLocaleString('tr-TR'));
                }
            });
        }
        
        // DataTable başlat
        function initDataTable() {
            table = $('#dataTable').DataTable({
                processing: true,
                scrollX: true,
                autoWidth: false,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return { action: 'list', ...currentFilters };
                    },
                    dataSrc: function(json) {
                        return json.success ? json.data : [];
                    }
                },
                columns: [
                    {
                        className: 'dt-control',
                        orderable: false,
                        data: null,
                        defaultContent: '<i class="bi bi-plus-circle text-primary" style="cursor:pointer"></i>'
                    },
                    { data: null, render: (data, type, row, meta) => meta.row + 1 },
                    { 
                        data: 'log_tarih',
                        render: data => formatDate(data)
                    },
                    { data: 'kullanici_adi', defaultContent: '-' },
                    { data: 'log_sayfa', defaultContent: '-' },
                    { data: 'log_tablo', defaultContent: '-' },
                    { data: 'log_kayit_id', defaultContent: '-' },
                    { 
                        data: 'log_islem_tipi',
                        render: data => {
                            const colors = {
                                'INSERT': 'badge-insert',
                                'UPDATE': 'badge-update',
                                'DELETE': 'badge-delete'
                            };
                            return `<span class="badge ${colors[data] || 'bg-secondary'}">${data}</span>`;
                        }
                    },
                    { data: 'log_aciklama', defaultContent: '-' }
                ],
                order: [[1, 'desc']],
                pageLength: 25
            });
            
            // Satır detay toggle
            $('#dataTable tbody').on('click', 'td.dt-control', function() {
                const tr = $(this).closest('tr');
                const row = table.row(tr);
                
                if (row.child.isShown()) {
                    row.child.hide();
                    tr.removeClass('shown');
                    $(this).find('i').removeClass('bi-dash-circle text-danger').addClass('bi-plus-circle text-primary');
                } else {
                    row.child(formatDetails(row.data())).show();
                    tr.addClass('shown');
                    $(this).find('i').removeClass('bi-plus-circle text-primary').addClass('bi-dash-circle text-danger');
                }
            });
        }
        
        // Tarih formatlama
        function formatDate(dateString) {
            if (!dateString) return '-';
            try {
                const date = new Date(dateString.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return '-';
            }
        }
        
        // Kolon adları → Türkçe etiket mapping
        const fieldLabels = {
            // Sozlesme_Odemeler tablosu
            'odeme_id': 'Ödeme ID',
            'odeme_tipi_id': 'Ödeme Tipi',
            'odeme_durum_id': 'Ödeme Durumu',
            'odeme_belge_no': 'Belge No',
            'odeme_tarih': 'Ödeme Tarihi',
            'odeme_vade_tarih': 'Vade Tarihi',
            'odeme_tutar': 'Tutar',
            'odeme_yapildi': 'Yapıldı mı',
            'odeme_personel_id': 'Personel',
            'odeme_guncel_personel_id': 'Güncel Personel',
            'odeme_dosyalar': 'Dosyalar',
            'odeme_aciklama': 'Açıklama',
            'odeme_olusturma_tarihi': 'Oluşturma Tarihi',
            'odeme_guncelleme_tarihi': 'Güncelleme Tarihi',
            
            // Cariler tablosu
            'cari_id': 'Cari ID',
            'cari_adi': 'Cari Adı',
            'cari_ad': 'Cari Adı',
            'cari_unvan': 'Ünvan',
            'cari_vergi_no': 'Vergi No',
            'cari_vergi_dairesi': 'Vergi Dairesi',
            'cari_mersis_no': 'Mersis No',
            'cari_ticaret_sicil_no': 'Ticaret Sicil No',
            'cari_telefon': 'Telefon',
            'cari_email': 'E-posta',
            'cari_adres': 'Adres',
            'cari_posta_kodu': 'Posta Kodu',
            'cari_Yetkili_adi': 'Yetkili Adı',
            'cari_Yetkili_telefon': 'Yetkili Telefon',
            'cari_Yetkili_email': 'Yetkili E-posta',
            'cari_ulke': 'Ülke',
            'cari_sehirler': 'Şehir',
            'cari_ilceler': 'İlçe',
            'cari_tipi_id': 'Cari Tipi',
            'cari_vade_odeme_tipi_id': 'Vade Ödeme Şekli',
            'cari_vade_gun_id': 'Vade Günü',
            'cari_aktif': 'Aktif',
            'cari_durum': 'Durum',
            'cari_olusturma_tarihi': 'Oluşturma Tarihi',
            'cari_guncelleme_tarihi': 'Güncelleme Tarihi',
            'cari_olusturan_kullanici': 'Oluşturan Kullanıcı',
            'cari_guncelleyen_kullanici': 'Güncelleyen Kullanıcı',
            'cari_devredilen_kullanici': 'Devredilen Personel',
            'cari_devreden_kullanici': 'Devreden Personel',
            'cari_devir_tarihi': 'Devir Tarihi',
            
            // Personel tablosu
            'personel_id': 'Personel ID',
            'personel_ad': 'Ad',
            'personel_soyad': 'Soyad',
            'personel_telefon': 'Telefon',
            'personel_email': 'E-posta',
            'personel_durum': 'Durum',
            
            // Genel alanlar
            'durum': 'Durum',
            'aciklama': 'Açıklama',
            'olusturma_tarihi': 'Oluşturma Tarihi',
            'guncelleme_tarihi': 'Güncelleme Tarihi',
            'olusturan_kullanici_id': 'Oluşturan Kullanıcı',
            'guncelleyen_kullanici_id': 'Güncelleyen Kullanıcı'
        };
        
        // Alan adını Türkçe etikete çevir
        function getFieldLabel(fieldName) {
            return fieldLabels[fieldName] || fieldName;
        }
        
        // Değişiklik detaylarını formatla
        function formatDetails(data) {
            if (!data.log_degisiklikler) {
                return '<div class="p-3 text-muted">Detay bilgisi bulunamadı</div>';
            }
            
            try {
                const changes = JSON.parse(data.log_degisiklikler);
                let html = '<div class="p-3 bg-light"><table class="table table-sm table-bordered mb-0"><thead class="table-secondary"><tr><th width="25%">Alan</th><th width="37%">Önceki Değer</th><th width="38%">Yeni Değer</th></tr></thead><tbody>';
                
                for (const [field, values] of Object.entries(changes)) {
                    const eskiVal = values.eski !== null ? values.eski : '<span class="text-muted">-</span>';
                    const yeniVal = values.yeni !== null ? values.yeni : '<span class="text-muted">-</span>';
                    
                    // Değişen alanları vurgula
                    const isChanged = values.eski !== values.yeni;
                    const rowClass = isChanged ? 'table-warning' : '';
                    
                    // Kolon adı yerine Türkçe etiket kullan
                    const fieldLabel = getFieldLabel(field);
                    
                    html += `<tr class="${rowClass}"><td><strong>${fieldLabel}</strong></td><td>${eskiVal}</td><td>${yeniVal}</td></tr>`;
                }
                
                html += '</tbody></table></div>';
                return html;
            } catch (e) {
                return '<div class="p-3 text-danger">JSON parse hatası: ' + e.message + '</div>';
            }
        }
        
        // Select2 başlat
        function initSelect2() {
            $('#filter_tablo, #filter_sayfa, #filter_kullanici').select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                placeholder: 'Seçiniz...',
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; }
                }
            });
        }
        
        $(document).ready(function() {
            loadStats();
            initSelect2();
            
            // URL parametrelerini filtrelere uygula
            if (urlParams.tablo) {
                $('#filter_tablo').val(urlParams.tablo).trigger('change.select2');
                currentFilters.tablo = urlParams.tablo;
            }
            if (urlParams.kayit_id) {
                $('#filter_kayit_id').val(urlParams.kayit_id);
                currentFilters.kayit_id = urlParams.kayit_id;
            }
            if (urlParams.sayfa) {
                $('#filter_sayfa').val(urlParams.sayfa).trigger('change.select2');
                currentFilters.sayfa = urlParams.sayfa;
            }
            
            // URL'den parametre geldiyse filtre kartını aç
            if (urlParams.tablo || urlParams.kayit_id || urlParams.sayfa) {
                $('#filterCard').addClass('show');
            }
            
            initDataTable();
            
            // Filtre uygula
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentFilters = {
                    start_date: $('#filter_start_date').val(),
                    end_date: $('#filter_end_date').val(),
                    tablo: $('#filter_tablo').val(),
                    sayfa: $('#filter_sayfa').val(),
                    islem_tipi: $('#filter_islem_tipi').val(),
                    kullanici_id: $('#filter_kullanici').val(),
                    search: $('#filter_search').val(),
                    kayit_id: $('#filter_kayit_id').val()
                };
                Object.keys(currentFilters).forEach(k => {
                    if (currentFilters[k] === '') delete currentFilters[k];
                });
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtre temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tablo').val('').trigger('change.select2');
                $('#filter_sayfa').val('').trigger('change.select2');
                $('#filter_kullanici').val('').trigger('change.select2');
                $('#filter_kayit_id').val('');
                currentFilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
                // URL parametrelerini temizle
                window.history.replaceState({}, document.title, window.location.pathname);
            });
            
            // Sidebar toggle - DataTable genişliğini yeniden hesapla
            $('[data-lte-toggle="sidebar"]').on('click', function() {
                setTimeout(function() {
                    if (table) {
                        $(window).trigger('resize');
                        table.columns.adjust().draw();
                    }
                }, 350);
            });
        });
    </script>
</body>
</html>
