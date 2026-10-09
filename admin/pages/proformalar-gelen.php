<?php
/**
 * Admin Panel - Gelen Proforma Yönetimi
 * 
 * Tablo: ProformalarGelen, ProformalarGelenKalemleri
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Composer autoload eklendi
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

// Para birimlerini çek
$paraBirimleri = $db->fetchAll("SELECT ParaBirimi_id, ParaBirimi_Kod, ParaBirimi_Ad FROM tanim_ParaBirimi WHERE Durum = 1 ORDER BY ParaBirimi_id");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'list':
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $search = $_POST['search'] ?? '';
                $cari_id = $_POST['cari_id'] ?? '';

                $sql = "SELECT p.*, c.cari_adi, 
                        (SELECT COUNT(*) FROM ProformalarGelenKalemleri WHERE ProformalarGelenKalemleri_Proforma_id = p.ProformalarGelen_id) as kalem_sayisi
                        FROM ProformalarGelen p
                        LEFT JOIN Cari c ON p.ProformalarGelen_Cari_id = c.cari_id
                        WHERE p.Durum = 1";
                $params = [];

                if ($startDate) { $sql .= " AND p.ProformalarGelen_Tarih >= ?"; $params[] = $startDate; }
                if ($endDate) { $sql .= " AND p.ProformalarGelen_Tarih <= ?"; $params[] = $endDate; }
                if ($cari_id) { $sql .= " AND p.ProformalarGelen_Cari_id = ?"; $params[] = $cari_id; }
                if ($search) {
                    $sql .= " AND (p.ProformalarGelen_No LIKE ? OR c.cari_adi LIKE ?)";
                    $params[] = "%$search%"; $params[] = "%$search%";
                }

                $sql .= " ORDER BY p.ProformalarGelen_Tarih DESC, p.ProformalarGelen_id DESC";
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'get':
                $id = $_POST['id'] ?? 0;
                $proforma = $db->fetchOne("SELECT * FROM ProformalarGelen WHERE ProformalarGelen_id = ?", [$id]);
                if ($proforma) {
                    $kalemler = $db->fetchAll("SELECT * FROM ProformalarGelenKalemleri WHERE ProformalarGelenKalemleri_Proforma_id = ? ORDER BY ProformalarGelenKalemleri_Sira", [$id]);
                    echo json_encode(['success' => true, 'data' => $proforma, 'kalemler' => $kalemler]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı.']);
                }
                break;

            case 'save':
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) throw new Exception('Yetkiniz yok.');

                $id = intval($_POST['id'] ?? 0);
                $cari_id = intval($_POST['ProformalarGelen_Cari_id'] ?? 0);
                if (!$cari_id) throw new Exception('Lütfen bir cari seçin.');

                $data = [
                    'ProformalarGelen_No' => $_POST['ProformalarGelen_No'],
                    'ProformalarGelen_Tarih' => $_POST['ProformalarGelen_Tarih'],
                    'ProformalarGelen_Cari_id' => $cari_id,
                    'ProformalarGelen_Tutar' => floatval($_POST['ProformalarGelen_Tutar'] ?? 0),
                    'ProformalarGelen_KurUSD' => floatval($_POST['ProformalarGelen_KurUSD'] ?? 1),
                    'ProformalarGelen_KurEUR' => floatval($_POST['ProformalarGelen_KurEUR'] ?? 1),
                    'ProformalarGelen_Aciklama' => $_POST['ProformalarGelen_Aciklama'] ?? null,
                    'ProformalarGelen_Fatura_id' => !empty($_POST['ProformalarGelen_Fatura_id']) ? intval($_POST['ProformalarGelen_Fatura_id']) : null,
                ];

                // Dosya Yükleme
                if (isset($_FILES['dosya']) && $_FILES['dosya']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/proformalar/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $ext = pathinfo($_FILES['dosya']['name'], PATHINFO_EXTENSION);
                    $fileName = uniqid('prof_') . '.' . $ext;
                    if (move_uploaded_file($_FILES['dosya']['tmp_name'], $uploadDir . $fileName)) {
                        $data['ProformalarGelen_DosyaYolu'] = 'admin/uploads/proformalar/' . $fileName;
                    }
                }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi'] = date('Y-m-d H:i:s');
                    $db->update('ProformalarGelen', $data, ['ProformalarGelen_id' => $id]);
                    $proformaId = $id;
                } else {
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $data['OlusturmaTarihi'] = date('Y-m-d H:i:s');
                    $data['Durum'] = 1;
                    
                    // MSSQL'de ID alımını GARANTİLEYEN en sağlam yöntem: OUTPUT INSERTED
                    $columns = array_keys($data);
                    $placeholders = array_fill(0, count($data), '?');
                    $sql = "INSERT INTO ProformalarGelen (" . implode(', ', $columns) . ") 
                            OUTPUT INSERTED.ProformalarGelen_id 
                            VALUES (" . implode(', ', $placeholders) . ")";
                    
                    $resId = $db->fetchOne($sql, array_values($data));
                    $proformaId = $resId ? intval($resId['ProformalarGelen_id']) : 0;
                }

                if (!$proformaId) throw new Exception('Proforma ID alınamadı, kayıt durduruldu.');

                // Kalemleri Kaydet
                $kalemlerRaw = $_POST['kalemler'] ?? '';
                if (empty($kalemlerRaw)) throw new Exception('Hiç kalem verisi gönderilmedi.');
                
                $kalemler = json_decode($kalemlerRaw, true);
                if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Kalem verisi okunamadı: ' . json_last_error_msg());
                if (empty($kalemler)) throw new Exception('Kaydedilecek geçerli bir kalem bulunamadı.');

                $db->execute("DELETE FROM ProformalarGelenKalemleri WHERE ProformalarGelenKalemleri_Proforma_id = ?", [$proformaId]);
                
                foreach ($kalemler as $index => $kalem) {
                    if (empty(trim($kalem['UrunAdi'] ?? ''))) continue; 

                    $kData = [
                        'ProformalarGelenKalemleri_Proforma_id' => $proformaId,
                        'ProformalarGelenKalemleri_Sira' => $index + 1,
                        'ProformalarGelenKalemleri_StokKodu' => $kalem['StokKodu'] ?? null,
                        'ProformalarGelenKalemleri_UrunAdi' => $kalem['UrunAdi'],
                        'ProformalarGelenKalemleri_Miktar' => floatval($kalem['Miktar'] ?? 0),
                        'ProformalarGelenKalemleri_Birim' => $kalem['Birim'] ?? 'ADET',
                        'ProformalarGelenKalemleri_BirimFiyat' => floatval($kalem['BirimFiyat'] ?? 0),
                        'ProformalarGelenKalemleri_ParaBirimi_id' => intval($kalem['ParaBirimi_id'] > 0 ? $kalem['ParaBirimi_id'] : 1),
                        'ProformalarGelenKalemleri_Kur' => floatval($kalem['Kur'] ?? 1),
                        'ProformalarGelenKalemleri_KDVOrani' => floatval($kalem['KDVOrani'] ?? 0),
                        'ProformalarGelenKalemleri_KDVTutar' => floatval($kalem['KDVTutar'] ?? 0),
                        'ProformalarGelenKalemleri_NetTutar' => floatval($kalem['NetTutar'] ?? 0),
                        'ProformalarGelenKalemleri_GToplamTutar' => floatval($kalem['GToplamTutar'] ?? 0),
                        'OlusturanKullanici' => $user['kullanici_id'],
                        'OlusturmaTarihi' => date('Y-m-d H:i:s'),
                        'Durum' => 1
                    ];
                    $db->insert('ProformalarGelenKalemleri', $kData);
                }

                echo json_encode(['success' => true, 'message' => 'Başarıyla kaydedildi.']);
                break;

            case 'delete':
                if (!$pagePermissions['can_delete']) throw new Exception('Yetkiniz yok.');
                $id = $_POST['id'] ?? 0;
                $db->update('ProformalarGelen', ['Durum' => 0], ['ProformalarGelen_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Kayıt silindi.']);
                break;

            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM ProformalarGelen WHERE Durum = 1")['sayi'] ?? 0,
                    'bu_ay' => $db->fetchOne("SELECT COUNT(*) as sayi FROM ProformalarGelen WHERE Durum = 1 AND MONTH(ProformalarGelen_Tarih) = MONTH(GETDATE()) AND YEAR(ProformalarGelen_Tarih) = YEAR(GETDATE())")['sayi'] ?? 0,
                    'bekleyen' => $db->fetchOne("SELECT COUNT(*) as sayi FROM ProformalarGelen WHERE Durum = 1 AND ProformalarGelen_Fatura_id IS NULL")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'parse_excel':
                $rawData = $_POST['data'] ?? '';
                if (empty($rawData)) throw new Exception('Veri alınamadı.');
                
                $data = json_decode($rawData, true);
                if (!is_array($data)) throw new Exception('Geçersiz veri formatı.');

                $items = [];
                // Excel formatı: Stok Kodu, Ürün Adı, Miktar, Birim, Birim Fiyat, Döviz, Kur
                // Başlık satırını atlamak için ilk satırı kontrol edebiliriz veya kullanıcıya seçtirebiliriz.
                // Burada Batuhan'ın projelerinde genellikle ilk satır başlık olur.
                
                foreach ($data as $index => $row) {
                    if ($index === 0) continue; // Başlık satırını atla
                    
                    $stokKodu = trim((string)($row[0] ?? ''));
                    $urunAdi = trim((string)($row[1] ?? ''));
                    
                    if (empty($stokKodu) && empty($urunAdi)) continue;

                    // Veritabanında ürün ve KDV oranı var mı kontrol et
                    $urun = $db->fetchOne("
                        SELECT u.urun_hizmet_id, u.urun_hizmet_adi, k.kdv_oran
                        FROM Urun_Hizmet u
                        LEFT JOIN UrunHizmet_KDVTanimlari k ON u.urun_hizmet_kdv_id = k.kdv_id
                        WHERE u.urun_hizmet_kodu = ? AND u.urun_hizmet_durum = 1
                    ", [$stokKodu]);

                    $items[] = [
                        'StokKodu' => $stokKodu,
                        'UrunAdi' => $urunAdi,
                        'Miktar' => floatval($row[2] ?? 0),
                        'Birim' => trim((string)($row[3] ?? 'ADET')),
                        'BirimFiyat' => floatval($row[4] ?? 0),
                        'ParaBirimi_Kod' => strtoupper(trim((string)($row[6] ?? 'TL'))), // Index 6: DÖVİZ
                        'Kur' => floatval($row[7] ?? 1), // Index 7: KUR
                        'NetTutar' => floatval($row[2] ?? 0) * floatval($row[4] ?? 0),
                        'Matched' => $urun ? true : false,
                        'SystemUrunAdi' => $urun ? $urun['urun_hizmet_adi'] : null,
                        'KDVOrani' => $urun ? $urun['kdv_oran'] : 20
                    ];
                }

                if (empty($items)) throw new Exception('Excel dosyasında geçerli kalem bulunamadı.');
                
                echo json_encode(['success' => true, 'data' => $items]);
                break;

            case 'get_fatura_kalemleri':
                $faturaId = intval($_POST['fatura_id'] ?? 0);
                if (!$faturaId) throw new Exception('Fatura ID eksik.');
                $fKalemler = $db->fetchAll("
                    SELECT FaturalarGelenKalemleri_StokKodu, FaturalarGelenKalemleri_UrunAdi, 
                           FaturalarGelenKalemleri_Miktar, FaturalarGelenKalemleri_BirimFiyat, 
                           FaturalarGelenKalemleri_Birim
                    FROM FaturalarGelenKalemleri
                    WHERE FaturalarGelenKalemleri_Fatura_id = ? AND Durum = 1
                    ORDER BY FaturalarGelenKalemleri_Sira
                ", [$faturaId]);
                echo json_encode(['success' => true, 'data' => $fKalemler]);
                break;

            case 'search_products':
                $q = $_POST['q'] ?? '';
                $products = $db->fetchAll("
                    SELECT TOP 20 
                        u.urun_hizmet_kodu as kod, 
                        (ISNULL(u.urun_hizmet_kodu, '') + ' - ' + ISNULL(u.urun_hizmet_adi, '')) as text, 
                        u.urun_hizmet_adi as adi, 
                        k.kdv_oran
                    FROM Urun_Hizmet u
                    LEFT JOIN UrunHizmet_KDVTanimlari k ON u.urun_hizmet_kdv_id = k.kdv_id
                    WHERE u.urun_hizmet_durum = 1 AND (u.urun_hizmet_kodu LIKE ? OR u.urun_hizmet_adi LIKE ?)
                    ORDER BY u.urun_hizmet_kodu
                ", ["%$q%", "%$q%"]);
                echo json_encode(['success' => true, 'data' => $products]);
                break;

            case 'list_temp':
                $tempDir = __DIR__ . '/temp/';
                $files = [];
                if (is_dir($tempDir)) {
                    foreach (glob($tempDir . "*.pdf") as $filename) {
                        $files[] = [
                            'name' => basename($filename),
                            'size' => round(filesize($filename) / 1024, 2) . ' KB',
                            'date' => date("Y-m-d H:i:s", filemtime($filename))
                        ];
                    }
                }
                echo json_encode(['success' => true, 'data' => $files]);
                break;

            case 'import_temp':
                $fileName = $_POST['file_name'] ?? '';
                $source = __DIR__ . '/temp/' . $fileName;
                if (!file_exists($source)) throw new Exception('Dosya bulunamadı.');
                
                $uploadDir = __DIR__ . '/../uploads/proformalar/';
                $newFileName = uniqid('prof_') . '_' . $fileName;
                if (copy($source, $uploadDir . $newFileName)) {
                    echo json_encode(['success' => true, 'file_path' => 'admin/uploads/proformalar/' . $newFileName]);
                } else {
                    throw new Exception('Dosya kopyalanamadı.');
                }
                break;

            case 'download_template':
                require_once __DIR__ . '/../../vendor/autoload.php';
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                
                // Başlıkları ekle
                $sheet->setCellValue('A1', 'Stok Kodu');
                $sheet->setCellValue('B1', 'Ürün Adı');
                $sheet->setCellValue('C1', 'Miktar');
                $sheet->setCellValue('D1', 'Birim');
                $sheet->setCellValue('E1', 'Birim Fiyat');
                $sheet->setCellValue('F1', 'Tutar');
                $sheet->setCellValue('G1', 'Para Birimi');
                $sheet->setCellValue('H1', 'Kur');
                
                // Örnek veriler
                $sheet->setCellValue('A2', 'STK-001');
                $sheet->setCellValue('B2', 'Örnek Ürün 1');
                $sheet->setCellValue('C2', 10);
                $sheet->setCellValue('D2', 'Çift');
                $sheet->setCellValue('E2', 150.50);
                $sheet->setCellValue('F2', 1505.00);
                $sheet->setCellValue('G2', 'TL');
                $sheet->setCellValue('H2', 1);
                
                $sheet->setCellValue('A3', 'STK-002');
                $sheet->setCellValue('B3', 'Örnek Ürün 2');
                $sheet->setCellValue('C3', 5);
                $sheet->setCellValue('D3', 'Çift');
                $sheet->setCellValue('E3', 25.00);
                $sheet->setCellValue('F3', 125.00);
                $sheet->setCellValue('G3', 'USD');
                $sheet->setCellValue('H3', 32.45);
                
                $sheet->setCellValue('A4', 'STK-003');
                $sheet->setCellValue('B4', 'Örnek Hizmet');
                $sheet->setCellValue('C4', 1);
                $sheet->setCellValue('D4', 'Adet');
                $sheet->setCellValue('E4', 500.00);
                $sheet->setCellValue('F4', 500.00);
                $sheet->setCellValue('G4', 'EUR');
                $sheet->setCellValue('H4', 35.12);
                
                // Kolon genişliklerini ayarla
                $sheet->getColumnDimension('A')->setWidth(15);
                $sheet->getColumnDimension('B')->setWidth(30);
                $sheet->getColumnDimension('C')->setWidth(10);
                $sheet->getColumnDimension('D')->setWidth(10);
                $sheet->getColumnDimension('E')->setWidth(12);
                $sheet->getColumnDimension('F')->setWidth(12);
                $sheet->getColumnDimension('G')->setWidth(10);
                $sheet->getColumnDimension('H')->setWidth(10);
                
                // HTTP headers
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment;filename="proforma_yukleme_sablonu.xlsx"');
                header('Cache-Control: max-age=0');
                
                $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Cari listesini çek
$cariler = $db->fetchAll("SELECT cari_id, cari_adi, cari_unvan FROM Cari WHERE cari_aktif = 1 ORDER BY cari_adi");

// Fatura listesini çek (Proformaya bağlanabilecek faturalar)
$faturalar = $db->fetchAll("SELECT f.FaturalarGelen_id, f.FaturalarGelen_FaturaNo, f.FaturalarGelen_Tarih, c.cari_adi 
                            FROM FaturalarGelen f 
                            LEFT JOIN Cari c ON f.FaturalarGelen_VKN_TCKN = c.cari_vergi_no 
                            WHERE f.Durum = 1 
                            ORDER BY f.FaturalarGelen_Tarih DESC");

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proformalar - <?= htmlspecialchars($siteTitle ?? 'OrnekFirma') ?></title>
    
    <link rel="stylesheet" href="/admin/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/select2/css/select2.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/select2/css/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <script src="/admin/assets/vendor/sheetjs/xlsx.full.min.js"></script>
    <style>
        .kalem-row input { font-size: 0.85rem; padding: 0.25rem; }
        .kalem-row select { font-size: 0.85rem; padding: 0.25rem; }
        #itemsTable th { font-size: 0.8rem; background: #f8f9fa; }
        .total-box { background: #f4f6f9; padding: 10px; border-radius: 5px; margin-top: 10px; }
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
                        <div class="col-sm-6"><h3 class="mb-0">Proformalar</h3></div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="anasayfa.php">Anasayfa</a></li>
                                <li class="breadcrumb-item active">Proformalar</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3" id="statsRow">
                        <div class="col-md-4">
                            <div class="info-box bg-primary">
                                <span class="info-box-icon"><i class="bi bi-file-earmark-text"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Proforma</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-box bg-success">
                                <span class="info-box-icon"><i class="bi bi-calendar-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ayki</span>
                                    <span class="info-box-number" id="stat-bu-ay">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-box bg-warning">
                                <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Faturalaşmayan</span>
                                    <span class="info-box-number" id="stat-bekleyen">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Paneli -->
                    <div class="card card-outline card-primary mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse"><i class="bi bi-plus"></i></button>
                            </div>
                        </div>
                        <div class="card-body">
                            <form id="filterForm" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Cari Seçimi</label>
                                    <select class="form-select select2" name="cari_id" id="filter_cari_id">
                                        <option value="">Tümü</option>
                                        <?php foreach($cariler as $c): ?>
                                            <option value="<?= $c['cari_id'] ?>"><?= htmlspecialchars($c['cari_adi']) ?> / <?= htmlspecialchars($c['cari_unvan'] ?: '-') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç</label>
                                    <input type="date" class="form-control" name="start_date">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş</label>
                                    <input type="date" class="form-control" name="end_date">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" name="search" placeholder="No veya Cari...">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Listele</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Liste -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Proforma Listesi</h3>
                            <div class="card-tools">
                                <a href="proforma-duzenle.php" class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-plus"></i> Yeni Proforma Oluştur</a>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0" id="mainTable">
                                    <thead>
                                        <tr>
                                            <th>Tarih</th>
                                            <th>Proforma No</th>
                                            <th>Cari Ünvan</th>
                                            <th>Tutar</th>
                                            <th>Kalem</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="listBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="/admin/assets/vendor/jquery/jquery.min.js"></script>
    <script src="/admin/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="/admin/assets/vendor/select2/js/select2.min.js"></script>
    <script src="/admin/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        $(document).ready(function() {
            initSelect2();
            loadStats();
            loadList();

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                loadList();
            });
        });

        function loadList() {
            const filters = {};
            $('#filterForm').serializeArray().forEach(f => filters[f.name] = f.value);
            
            $.post('proformalar-gelen', { action: 'list', ...filters }, function(res) {
                if (res.success) {
                    let html = '';
                    res.data.forEach(item => {
                        html += `
                            <tr>
                                <td>${item.ProformalarGelen_Tarih}</td>
                                <td><b>${item.ProformalarGelen_No}</b></td>
                                <td>${item.cari_adi}</td>
                                <td>${parseFloat(item.ProformalarGelen_Tutar).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} TL</td>
                                <td>${item.kalem_sayisi} Kalem</td>
                                <td>${item.ProformalarGelen_Fatura_id ? '<span class="badge bg-success">Faturalaştı</span>' : '<span class="badge bg-warning text-dark">Bekliyor</span>'}</td>
                                <td>
                                    <div class="btn-group">
                                        <a href="proforma-duzenle?id=${item.ProformalarGelen_id}" class="btn btn-sm btn-info"><i class="bi bi-pencil"></i></a>
                                        ${item.ProformalarGelen_DosyaYolu ? `<a href="/${item.ProformalarGelen_DosyaYolu}" target="_blank" class="btn btn-sm btn-secondary"><i class="bi bi-file-earmark-spreadsheet"></i></a>` : ''}
                                        <button class="btn btn-sm btn-danger" onclick="deleteRecord(${item.ProformalarGelen_id})"><i class="bi bi-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });
                    $('#listBody').html(html || '<tr><td colspan="7" class="text-center">Kayıt bulunamadı.</td></tr>');
                }
            });
        }

        function deleteRecord(id) {
            Swal.fire({
                title: 'Emin misiniz?',
                text: 'Bu proformayı silmek istediğinize emin misiniz?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Evet, Sil',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('proformalar-gelen', { action: 'delete', id: id }, function(res) {
                        if (res.success) {
                            Swal.fire('Silindi!', res.message, 'success');
                            loadList();
                            loadStats();
                        } else {
                            Swal.fire('Hata!', res.message, 'error');
                        }
                    }, 'json');
                }
            });
        }

        function loadStats() {
            $.post('proformalar-gelen', { action: 'stats' }, function(res) {
                if (res.success) {
                    $('#stat-toplam').text(res.data.toplam);
                    $('#stat-bu-ay').text(res.data.bu_ay);
                    $('#stat-bekleyen').text(res.data.bekleyen);
                }
            });
        }

        function initSelect2() {
            $('.select2').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });
        }
    </script>
</body>
</body>
</html>
