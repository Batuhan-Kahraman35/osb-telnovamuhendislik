<?php
/**
 * Ödenecek Senet Detay Sayfası
 * Aylık satış raporundan tıklanan ay için ödenmemiş senet detayları
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü (aylik-satis-raporu yetkisini kontrol et)
$currentPageFile = 'aylik-satis-raporu.php';
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Site ayarları
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// URL parametreleri
$yil = intval($_GET['yil'] ?? date('Y'));
$ay = intval($_GET['ay'] ?? date('n'));
$sezonId = $_GET['sezon_id'] ?? '';
$senetTipiId = intval($_GET['senet_tipi_id'] ?? 0);

// Senet tipi ID'si yoksa veritabanından bul
if (!$senetTipiId) {
    $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
    $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
}

$aylar = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
];

$pageTitle = ($aylar[$ay] ?? $ay) . ' ' . $yil . ' - Ödenecek Senet Detayı';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                $yil = intval($_POST['yil'] ?? 0);
                $ay = intval($_POST['ay'] ?? 0);
                $sezonId = $_POST['sezon_id'] ?? '';
                $senetTipiId = intval($_POST['senet_tipi_id'] ?? 0);
                
                // Senet tipi ID'si yoksa veritabanından bul
                if (!$senetTipiId) {
                    $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                    $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
                }
                
                $whereClause = "WHERE s.sozlesme_durum = 1 
                                AND o.odeme_tipi_id = ?
                                AND o.odeme_yapildi = 0
                                AND YEAR(s.sozlesme_tarih) = ? 
                                AND MONTH(s.sozlesme_tarih) = ?";
                $params = [$senetTipiId, $yil, $ay];
                
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                
                // Toplam ödenecek tutar
                $toplamTutar = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    $whereClause
                ", $params);
                
                // Toplam senet sayısı
                $toplamKayit = $db->fetchOne("
                    SELECT COUNT(*) as adet
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    $whereClause
                ", $params);
                
                // Vadesi geçmiş senet sayısı
                $vadesiGecmis = $db->fetchOne("
                    SELECT COUNT(*) as adet, ISNULL(SUM(o.odeme_tutar), 0) as tutar
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    $whereClause AND o.odeme_vade_tarih < GETDATE()
                ", $params);
                
                // Bu ay vadesi dolan
                $buAyVade = $db->fetchOne("
                    SELECT COUNT(*) as adet, ISNULL(SUM(o.odeme_tutar), 0) as tutar
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    $whereClause 
                    AND YEAR(o.odeme_vade_tarih) = YEAR(GETDATE()) 
                    AND MONTH(o.odeme_vade_tarih) = MONTH(GETDATE())
                ", $params);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'toplam_tutar' => floatval($toplamTutar['toplam'] ?? 0),
                        'toplam_kayit' => intval($toplamKayit['adet'] ?? 0),
                        'vadesi_gecmis_adet' => intval($vadesiGecmis['adet'] ?? 0),
                        'vadesi_gecmis_tutar' => floatval($vadesiGecmis['tutar'] ?? 0),
                        'bu_ay_vade_adet' => intval($buAyVade['adet'] ?? 0),
                        'bu_ay_vade_tutar' => floatval($buAyVade['tutar'] ?? 0)
                    ]
                ]);
                break;
                
            case 'list':
                $yil = intval($_POST['yil'] ?? 0);
                $ay = intval($_POST['ay'] ?? 0);
                $sezonId = $_POST['sezon_id'] ?? '';
                $senetTipiId = intval($_POST['senet_tipi_id'] ?? 0);
                
                // Senet tipi ID'si yoksa veritabanından bul
                if (!$senetTipiId) {
                    $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                    $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
                }
                
                // Stats ile BİREBİR AYNI where clause
                $whereClause = "WHERE s.sozlesme_durum = 1 
                                AND o.odeme_tipi_id = ?
                                AND o.odeme_yapildi = 0
                                AND YEAR(s.sozlesme_tarih) = ? 
                                AND MONTH(s.sozlesme_tarih) = ?";
                $params = [$senetTipiId, $yil, $ay];
                
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                
                // Ödeme ID'lerini çek
                $idResult = $db->fetchAll("
                    SELECT o.odeme_id
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    $whereClause
                ", $params);
                
                // Her ID için detay bilgilerini çek
                $data = [];
                foreach ($idResult as $idRow) {
                    $odemeId = intval($idRow['odeme_id']);
                    
                    // Ödeme bilgisini çek
                    $odeme = $db->fetchOne("SELECT * FROM Sozlesme_Odemeler WHERE odeme_id = $odemeId");
                    
                    if ($odeme) {
                        // Sözleşme bilgisi
                        $sozlesmeId = intval($odeme['odeme_sozlesme_id'] ?? 0);
                        $sozlesme = $sozlesmeId ? $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = $sozlesmeId") : null;
                        
                        // Cari bilgisi
                        $cariId = intval($sozlesme['sozlesme_cari_id'] ?? 0);
                        $cari = $cariId ? $db->fetchOne("SELECT * FROM Cari WHERE cari_id = $cariId") : null;
                        
                        // Firma bilgisi
                        $firmaId = intval($sozlesme['sozlesme_firma_id'] ?? 0);
                        $firma = $firmaId ? $db->fetchOne("SELECT * FROM Firmalar WHERE firma_id = $firmaId") : null;
                        
                        // Tarih formatlama - PHP tarafında
                        $odemeTarih = $odeme['odeme_tarih'] ?? null;
                        $vadeTarih = $odeme['odeme_vade_tarih'] ?? null;
                        $sozlesmeTarih = $sozlesme['sozlesme_tarih'] ?? null;
                        
                        // DateTime object kontrolü
                        if ($odemeTarih instanceof DateTime) $odemeTarih = $odemeTarih->format('d.m.Y');
                        elseif ($odemeTarih) $odemeTarih = date('d.m.Y', strtotime($odemeTarih));
                        
                        if ($vadeTarih instanceof DateTime) $vadeTarih = $vadeTarih->format('d.m.Y');
                        elseif ($vadeTarih) $vadeTarih = date('d.m.Y', strtotime($vadeTarih));
                        
                        if ($sozlesmeTarih instanceof DateTime) $sozlesmeTarih = $sozlesmeTarih->format('d.m.Y');
                        elseif ($sozlesmeTarih) $sozlesmeTarih = date('d.m.Y', strtotime($sozlesmeTarih));
                        
                        // Vade kontrolü
                        $vadesiGecti = 0;
                        if ($odeme['odeme_vade_tarih']) {
                            $vadeTime = ($odeme['odeme_vade_tarih'] instanceof DateTime) 
                                ? $odeme['odeme_vade_tarih']->getTimestamp() 
                                : strtotime($odeme['odeme_vade_tarih']);
                            $vadesiGecti = ($vadeTime < time()) ? 1 : 0;
                        }
                        
                        $data[] = [
                            'odeme_id' => $odeme['odeme_id'],
                            'odeme_belge_no' => $odeme['odeme_belge_no'] ?? '',
                            'odeme_tutar' => $odeme['odeme_tutar'] ?? 0,
                            'odeme_tarih' => $odemeTarih,
                            'vade_tarih' => $vadeTarih,
                            'odeme_aciklama' => $odeme['odeme_aciklama'] ?? '',
                            'sozlesme_id' => $sozlesme['sozlesme_id'] ?? null,
                            'sozlesme_no' => $sozlesme['sozlesme_no'] ?? '',
                            'sozlesme_tarih' => $sozlesmeTarih,
                            'cari_id' => $cari['cari_id'] ?? null,
                            'cari_adi' => $cari['cari_adi'] ?? '',
                            'cari_kodu' => $cari['cari_kodu'] ?? '',
                            'cari_telefon' => $cari['cari_telefon'] ?? '',
                            'cari_email' => $cari['cari_email'] ?? '',
                            'firma_adi' => $firma['firma_adi'] ?? '',
                            'vadesi_gecti' => $vadesiGecti
                        ];
                    }
                }
                
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
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
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .table-danger td { background-color: #f8d7da !important; }
        .badge-vade-gecti { background-color: #dc3545; color: white; }
        .badge-vade-yaklasik { background-color: #ffc107; color: black; }
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
                            <h3 class="mb-0"><i class="bi bi-file-earmark-text"></i> <?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="aylik-satis-raporu.php">Aylık Satış Raporu</a></li>
                                <li class="breadcrumb-item active">Ödenecek Senet Detayı</li>
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
                                    <i class="bi bi-file-earmark-text"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Senet</span>
                                    <span class="info-box-number" id="stat-toplam-kayit">0 Adet</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-cash-stack"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Tutar</span>
                                    <span class="info-box-number" id="stat-toplam-tutar">₺0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Vadesi Geçmiş</span>
                                    <span class="info-box-number" id="stat-vadesi-gecmis">0 Adet</span>
                                    <small class="text-muted" id="stat-vadesi-gecmis-tutar">₺0</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-calendar-event"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ay Vadeli</span>
                                    <span class="info-box-number" id="stat-bu-ay-vade">0 Adet</span>
                                    <small class="text-muted" id="stat-bu-ay-vade-tutar">₺0</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Senet Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-list-ul"></i> Ödenecek Senet Listesi</h3>
                            <div class="card-tools">
                                <a href="aylik-satis-raporu.php" class="btn btn-sm btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Geri Dön
                                </a>
                                <button type="button" class="btn btn-sm btn-success" id="btnExport">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th>Senet No</th>
                                            <th>Sözleşme No</th>
                                            <th>İşletme (Cari)</th>
                                            <th>Firma</th>
                                            <th>Telefon</th>
                                            <th class="text-end">Tutar</th>
                                            <th>Sözleşme Tarihi</th>
                                            <th>Vade Tarihi</th>
                                            <th>Durum</th>
                                            <th>Açıklama</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <tr>
                                            <td colspan="10" class="text-center">Yükleniyor...</td>
                                        </tr>
                                    </tbody>
                                    <tfoot id="tableFoot"></tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        const params = {
            yil: <?= $yil ?>,
            ay: <?= $ay ?>,
            sezon_id: '<?= htmlspecialchars($sezonId) ?>',
            senet_tipi_id: <?= $senetTipiId ?>
        };
        
        let tableData = [];
        
        function loadStats() {
            $.post('', { action: 'stats', ...params }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#stat-toplam-kayit').text(d.toplam_kayit + ' Adet');
                    $('#stat-toplam-tutar').text(formatCurrency(d.toplam_tutar));
                    $('#stat-vadesi-gecmis').text(d.vadesi_gecmis_adet + ' Adet');
                    $('#stat-vadesi-gecmis-tutar').text(formatCurrency(d.vadesi_gecmis_tutar));
                    $('#stat-bu-ay-vade').text(d.bu_ay_vade_adet + ' Adet');
                    $('#stat-bu-ay-vade-tutar').text(formatCurrency(d.bu_ay_vade_tutar));
                    
                }
            });
        }
        
        function loadList() {
            $.post('', { action: 'list', ...params }, function(response) {
                if (!response.success) {
                    $('#tableBody').html('<tr><td colspan="10" class="text-center text-danger">' + (response.message || 'Hata oluştu') + '</td></tr>');
                    return;
                }
                
                tableData = response.data;
                
                if (!tableData || tableData.length === 0) {
                    $('#tableBody').html('<tr><td colspan="10" class="text-center text-muted">Bu dönem için ödenecek senet bulunamadı.</td></tr>');
                    $('#tableFoot').html('');
                    return;
                }
                
                let html = '';
                let toplamTutar = 0;
                
                tableData.forEach(row => {
                    const tutar = parseFloat(row.odeme_tutar) || 0;
                    toplamTutar += tutar;
                    
                    const rowClass = row.vadesi_gecti == 1 ? 'table-danger' : '';
                    const durumBadge = row.vadesi_gecti == 1 
                        ? '<span class="badge badge-vade-gecti">Vadesi Geçti</span>' 
                        : '<span class="badge bg-success">Bekliyor</span>';
                    
                    html += `<tr class="${rowClass}">
                        <td><strong>${row.odeme_belge_no || '-'}</strong></td>
                        <td><a href="sozlesme-form.php?id=${row.sozlesme_id}" target="_blank">${row.sozlesme_no || '-'}</a></td>
                        <td>
                            <strong>${row.cari_adi || '-'}</strong>
                            ${row.cari_kodu ? '<br><small class="text-muted">' + row.cari_kodu + '</small>' : ''}
                        </td>
                        <td>${row.firma_adi || '-'}</td>
                        <td>${row.cari_telefon || '-'}</td>
                        <td class="text-end fw-bold">${formatCurrency(tutar)}</td>
                        <td>${row.sozlesme_tarih || '-'}</td>
                        <td><strong>${row.vade_tarih || '-'}</strong></td>
                        <td>${durumBadge}</td>
                        <td><small>${row.odeme_aciklama || '-'}</small></td>
                    </tr>`;
                });
                
                $('#tableBody').html(html);
                $('#tableFoot').html(`
                    <tr class="fw-bold">
                        <td colspan="5"><strong>TOPLAM (${tableData.length} kayıt)</strong></td>
                        <td class="text-end"><strong>${formatCurrency(toplamTutar)}</strong></td>
                        <td colspan="4"></td>
                    </tr>
                `);
                
                // DataTables'ı yeniden başlat
                if ($.fn.DataTable.isDataTable('#dataTable')) {
                    $('#dataTable').DataTable().destroy();
                }
                $('#dataTable').DataTable({
                    language: {
                        url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'
                    },
                    pageLength: 25,
                    order: [[7, 'asc']], // Vade tarihine göre sırala
                    columnDefs: [
                        { orderable: false, targets: [9] }
                    ]
                });
            });
        }
        
        // Excel export
        $('#btnExport').on('click', function() {
            if (!tableData || tableData.length === 0) {
                showToast('İndirilecek veri yok', 'warning');
                return;
            }
            
            let csv = [];
            csv.push('"Senet No";"Sözleşme No";"İşletme";"Cari Kodu";"Firma";"Telefon";"E-posta";"Tutar";"Sözleşme Tarihi";"Vade Tarihi";"Durum";"Açıklama"');
            
            let toplam = 0;
            tableData.forEach(row => {
                const tutar = parseFloat(row.odeme_tutar) || 0;
                toplam += tutar;
                const durum = row.vadesi_gecti == 1 ? 'Vadesi Geçti' : 'Bekliyor';
                csv.push(`"${row.odeme_belge_no || ''}";"${row.sozlesme_no || ''}";"${row.cari_adi || ''}";"${row.cari_kodu || ''}";"${row.firma_adi || ''}";"${row.cari_telefon || ''}";"${row.cari_email || ''}";"${tutar.toFixed(2).replace('.', ',')}";"${row.sozlesme_tarih || ''}";"${row.vade_tarih || ''}";"${durum}";"${row.odeme_aciklama || ''}"`);
            });
            csv.push(`"TOPLAM";"";"";"";"";"";"";"${toplam.toFixed(2).replace('.', ',')}";"";"";"";""`);
            
            const csvContent = '\uFEFF' + csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'odenecek-senet-detay-<?= $yil ?>-<?= $ay ?>.csv';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Excel dosyası indirildi', 'success');
        });
        
        $(document).ready(function() {
            loadStats();
            loadList();
        });
    </script>
</body>
</html>
