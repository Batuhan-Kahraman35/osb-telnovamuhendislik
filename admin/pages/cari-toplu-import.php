<?php
/**
 * Ortak Toplu Import Sayfasi
 * Isletme (Cari) icin tek noktadan Excel import
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
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erisim yetkiniz bulunmamaktadir.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Toplu Import';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? 'Excel dosyasindan toplu veri yukleme';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';
// Referans veriler
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");
$ilceler = $db->fetchAll("SELECT ilceId, IlceAdi, SehirId FROM Adres_Ilceler ORDER BY IlceAdi");
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_id");
$cariSektorler = $db->fetchAll("SELECT CariSektor_Id, CariSektor_Adi FROM Cari_Sektorler WHERE Durum = 1 ORDER BY CariSektor_Sira, CariSektor_Adi");

// Turkce İ/I karakter sorununu cozen lowercase fonksiyonu
function turkishLower($str) {
    $str = str_replace(['İ', 'I'], ['i', 'ı'], $str);
    return mb_strtolower($str);
}

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $modul = $_POST['modul'] ?? '';

    try {
        switch ($action) {

            // ============================================
            // ANALIZ (tum moduller)
            // ============================================
            case 'analyze':
                $rawData = $_POST['data'] ?? '';
                $mapping = json_decode($_POST['mapping'] ?? '{}', true);
                $duplicateField = $_POST['duplicate_field'] ?? '';

                if (empty($rawData)) {
                    echo json_encode(['success' => false, 'message' => 'Veri alinamadi']);
                    break;
                }

                $data = json_decode($rawData, true);
                if (empty($data) || !is_array($data)) {
                    echo json_encode(['success' => false, 'message' => 'Dosyada gecerli veri bulunamadi']);
                    break;
                }

                $validatedData = [];
                $excelDuplicates = [];

                // ---- ISLETME MODUL ----
                if ($modul === 'isletme') {
                    $cariTipiId = intval($_POST['cari_tipi_id'] ?? 1);
                    $ssaAktif = intval($_POST['ssa_aktif'] ?? 1);

                    if (empty($mapping) || !isset($mapping['cari_adi'])) {
                        echo json_encode(['success' => false, 'message' => 'En az "Cari Adi" sutunu eslestirilmelidir']);
                        break;
                    }

                    $excelDuplicates = [];
                    foreach ($data as $i => $row) {
                        $rowNum = $i + 2;
                        $validated = [
                            'row_num' => $rowNum,
                            'mapped_data' => [],
                            'status' => 'new',
                            'has_error' => false,
                            'error_message' => '',
                            'duplicate_id' => null,
                            'sehir_id' => null,
                            'ilce_id' => null,
                            'sektor_id' => null
                        ];

                        foreach ($mapping as $dbField => $excelCol) {
                            if ($excelCol === '' || $excelCol === null) continue;
                            $validated['mapped_data'][$dbField] = isset($row[$dbField]) ? trim((string)$row[$dbField]) : '';
                        }

                        if (empty($validated['mapped_data']['cari_adi'])) {
                            $validated['has_error'] = true;
                            $validated['error_message'] = 'Cari Adi bos olamaz';
                            $validated['status'] = 'error';
                        }

                        if (!empty($validated['mapped_data']['sehir'])) {
                            foreach ($sehirler as $s) {
                                if (turkishLower(trim($s['SehirAdi'])) === turkishLower(trim($validated['mapped_data']['sehir']))) {
                                    $validated['sehir_id'] = $s['SehirId'];
                                    break;
                                }
                            }
                        }

                        if (!empty($validated['mapped_data']['ilce']) && $validated['sehir_id']) {
                            foreach ($ilceler as $il) {
                                if ($il['SehirId'] == $validated['sehir_id'] &&
                                    turkishLower(trim($il['IlceAdi'])) === turkishLower(trim($validated['mapped_data']['ilce']))) {
                                    $validated['ilce_id'] = $il['ilceId'];
                                    break;
                                }
                            }
                        }

                        // Sektor eslestir: ID veya sektor adi gelebilir
                        if (!empty($validated['mapped_data']['cari_sektor_id'])) {
                            $sektorVal = trim((string)$validated['mapped_data']['cari_sektor_id']);
                            if (ctype_digit($sektorVal)) {
                                $sektorId = intval($sektorVal);
                                foreach ($cariSektorler as $cs) {
                                    if (intval($cs['CariSektor_Id']) === $sektorId) {
                                        $validated['sektor_id'] = $sektorId;
                                        break;
                                    }
                                }
                            } else {
                                foreach ($cariSektorler as $cs) {
                                    if (turkishLower(trim($cs['CariSektor_Adi'])) === turkishLower($sektorVal)) {
                                        $validated['sektor_id'] = intval($cs['CariSektor_Id']);
                                        break;
                                    }
                                }
                            }
                        }

                        if (!$validated['has_error'] && !empty($duplicateField) && !empty($validated['mapped_data'][$duplicateField] ?? '')) {
                            $isletmeDbMap = [
                                'cari_adi' => 'cari_adi',
                                'cari_unvan' => 'cari_unvan',
                                'cari_vergi_no' => 'cari_vergi_no',
                                'cari_telefon' => 'cari_telefon',
                                'cari_email' => 'cari_email'
                            ];

                            if (isset($isletmeDbMap[$duplicateField])) {
                                $dbCol = $isletmeDbMap[$duplicateField];
                                $dupVal = $validated['mapped_data'][$duplicateField];
                                $existing = $db->fetchOne("SELECT TOP 1 cari_id FROM Cari WHERE $dbCol = ?", [$dupVal]);
                                if ($existing) {
                                    $validated['status'] = 'duplicate';
                                    $validated['duplicate_id'] = $existing['cari_id'];
                                }
                            }
                        }

                        if (!$validated['has_error'] && $validated['status'] === 'new' && !empty($duplicateField)) {
                            $fieldVal = $validated['mapped_data'][$duplicateField] ?? '';
                            if (!empty($fieldVal)) {
                                $checkVal = mb_strtolower(trim($fieldVal));
                                if (isset($excelDuplicates[$checkVal])) {
                                    $validated['status'] = 'excel_duplicate';
                                    $validated['error_message'] = 'Excel icinde mukerrer (Satir ' . $excelDuplicates[$checkVal] . ')';
                                } else {
                                    $excelDuplicates[$checkVal] = $rowNum;
                                }
                            }
                        }

                        $validatedData[] = $validated;
                    }

                    $_SESSION['import_settings'] = [
                        'modul' => 'isletme',
                        'mapping' => $mapping,
                        'duplicate_field' => $duplicateField,
                        'cari_tipi_id' => $cariTipiId,
                        'ssa_aktif' => $ssaAktif
                    ];
                }

                // Istatistikler
                $summary = [
                    'total' => count($validatedData),
                    'new' => count(array_filter($validatedData, fn($r) => $r['status'] === 'new')),
                    'duplicate' => count(array_filter($validatedData, fn($r) => $r['status'] === 'duplicate')),
                    'excel_duplicate' => count(array_filter($validatedData, fn($r) => $r['status'] === 'excel_duplicate')),
                    'errors' => count(array_filter($validatedData, fn($r) => $r['has_error']))
                ];


                $_SESSION['import_data'] = $validatedData;

                $responseData = [
                    'success' => true,
                    'data' => $validatedData,
                    'summary' => $summary
                ];

                // Isletme: statik alan bilgilerini ekle
                if ($modul === 'isletme') {
                    $cariTipiAdi = '';
                    foreach ($cariTipleri as $ct) {
                        if ($ct['cari_tipi_id'] == $cariTipiId) { $cariTipiAdi = $ct['cari_tipi_ad']; break; }
                    }
                    $responseData['static_fields'] = [
                        ['label' => 'Cari Tipi', 'value' => $cariTipiAdi ?: '-'],
                        ['label' => 'SSA Durum', 'value' => $ssaAktif == 1 ? 'Aktif' : 'Pasif']
                    ];
                }

                echo json_encode($responseData);
                break;
            // ============================================
            // IMPORT (tum moduller)
            // ============================================
            case 'import':
                if (!isset($_SESSION['import_data']) || empty($_SESSION['import_data'])) {
                    echo json_encode(['success' => false, 'message' => 'Import verisi bulunamadi. Lutfen once dosyayi analiz edin.']);
                    break;
                }

                $importData = $_SESSION['import_data'];
                $settings = $_SESSION['import_settings'];
                $duplicateAction = $_POST['duplicate_action'] ?? 'skip';
                $imported = 0;
                $updated = 0;
                $skipped = 0;

                // ---- ISLETME IMPORT ----
                if ($settings['modul'] === 'isletme') {
                    foreach ($importData as $row) {
                        if ($row['has_error'] || $row['status'] === 'excel_duplicate') { $skipped++; continue; }
                        $d = $row['mapped_data'];

                        if ($row['status'] === 'duplicate') {
                            if ($duplicateAction !== 'update' || empty($row['duplicate_id'])) {
                                $skipped++;
                                continue;
                            }

                            $updFields = [
                                'cari_tipi_id = ?',
                                'cari_SSAAktif = ?',
                                'cari_aktif = 1',
                                'cari_ulke = 1',
                                'cari_sehirler = ?',
                                'cari_ilceler = ?',
                                'cari_guncelleyen_kullanici = ?',
                                'cari_guncelleme_tarihi = GETDATE()'
                            ];
                            $updParams = [
                                $settings['cari_tipi_id'] ?? 1,
                                $settings['ssa_aktif'] ?? 1,
                                $row['sehir_id'],
                                $row['ilce_id'],
                                $user['kullanici_id']
                            ];

                            foreach (['cari_adi','cari_unvan','cari_vergi_dairesi','cari_vergi_no','cari_telefon','cari_email','cari_yetkili_adi','cari_yetkili_telefon','cari_adres','cari_web_sitesi'] as $cf) {
                                if (!empty($d[$cf])) {
                                    $updFields[] = "$cf = ?";
                                    $updParams[] = $d[$cf];
                                }
                            }

                            if (array_key_exists('cari_sektor_id', $d) && !empty($row['sektor_id'])) {
                                $updFields[] = 'cari_sektor_id = ?';
                                $updParams[] = $row['sektor_id'];
                            }

                            $updParams[] = $row['duplicate_id'];
                            $db->execute("UPDATE Cari SET " . implode(', ', $updFields) . " WHERE cari_id = ?", $updParams);
                            $updated++;
                            continue;
                        }

                        $cariInsFields = ['cari_adi','cari_tipi_id','cari_aktif','cari_SSAAktif','cari_ulke','cari_sehirler','cari_ilceler','cari_olusturan_kullanici','cari_olusturma_tarihi'];
                        $cariInsPlc = ['?','?','1','?','1','?','?','?','GETDATE()'];
                        $cariInsParams = [
                            $d['cari_adi'],
                            $settings['cari_tipi_id'] ?? 1,
                            $settings['ssa_aktif'] ?? 1,
                            $row['sehir_id'],
                            $row['ilce_id'],
                            $user['kullanici_id']
                        ];

                        foreach (['cari_unvan','cari_vergi_dairesi','cari_vergi_no','cari_telefon','cari_email','cari_yetkili_adi','cari_yetkili_telefon','cari_adres','cari_web_sitesi'] as $cf) {
                            if (!empty($d[$cf])) {
                                $cariInsFields[] = $cf;
                                $cariInsPlc[] = '?';
                                $cariInsParams[] = $d[$cf];
                            }
                        }

                        if (!empty($row['sektor_id'])) {
                            $cariInsFields[] = 'cari_sektor_id';
                            $cariInsPlc[] = '?';
                            $cariInsParams[] = $row['sektor_id'];
                        }

                        $db->execute("INSERT INTO Cari (" . implode(', ', $cariInsFields) . ") VALUES (" . implode(', ', $cariInsPlc) . ")", $cariInsParams);

                        $imported++;
                    }
                }

                unset($_SESSION['import_data']);
                unset($_SESSION['import_settings']);

                $msg = "Import tamamlandi. $imported yeni kayit eklendi";
                if ($updated > 0) $msg .= ", $updated kayit guncellendi";
                if ($skipped > 0) $msg .= ", $skipped kayit atlandi";
                $msg .= ".";

                echo json_encode(['success' => true, 'message' => $msg, 'imported' => $imported, 'updated' => $updated, 'skipped' => $skipped]);
                break;

            case 'clear':
                unset($_SESSION['import_data']);
                unset($_SESSION['import_settings']);
                echo json_encode(['success' => true, 'message' => 'Veriler temizlendi']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Tarih format donusturme
function convertDateFormat($dateStr) {
    $dateStr = trim($dateStr);
    if (empty($dateStr)) return null;
    // Excel seri numarasi kontrolu (ornegin 45678)
    if (is_numeric($dateStr) && $dateStr > 25000 && $dateStr < 100000) {
        $unixTs = ($dateStr - 25569) * 86400;
        return date('Y-m-d', intval($unixTs));
    }
    $formats = ['d.m.y', 'd.m.Y', 'd/m/y', 'd/m/Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateStr);
        if ($date) return $date->format('Y-m-d');
    }
    // ISO formatinda ise direkt dondur
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) return substr($dateStr, 0, 10);
    return null;
}
?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <style>
        .step-card { transition: all 0.3s ease; }
        .step-card.disabled-step { opacity: 0.5; pointer-events: none; }
        .step-number { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #0d6efd; color: #fff; font-weight: bold; font-size: 14px; margin-right: 8px; }
        .step-number.completed { background: #198754; }
        .modul-card { cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
        .modul-card:hover { border-color: #0d6efd; transform: translateY(-2px); }
        .modul-card.selected { border-color: #0d6efd; background: rgba(13,110,253,0.05); }
        .modul-settings { display: none; }
        .modul-settings.active { display: block; }
        .preview-badge { font-size: 0.8em; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="anasayfa.php">Ana Sayfa</a></li>
                                <li class="breadcrumb-item active">Toplu Import</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">
                    <!-- ADIM 1: Modul Secimi & Dosya Yukleme -->
                    <div class="card card-primary card-outline mb-3 step-card" id="step1Card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step1Badge">1</span>
                                <i class="bi bi-gear"></i> Import Ayarlari
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm" id="downloadTemplateBtn">
                                    <i class="bi bi-download"></i> Sablon Indir
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Modul Secimi -->
                            <label class="form-label fw-bold mb-2">Import Modulu Secin</label>
                            <div class="row mb-3 g-3">
                                <div class="col-md-6 mx-auto">
                                    <div class="card modul-card text-center p-3 selected" data-modul="isletme">
                                        <i class="bi bi-buildings-fill text-success" style="font-size: 2em;"></i>
                                        <h6 class="mt-2 mb-0">Isletme Import</h6>
                                        <small class="text-muted">Isletme (Cari) kaydi yukleme</small>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <div class="row">
                                <!-- Dosya Secimi -->
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Excel / CSV Dosyasi</label>
                                        <input type="file" class="form-control" id="excelFile" accept=".csv,.xlsx,.xls">
                                        <div class="form-text">Desteklenen: .xlsx, .xls, .csv</div>
                                    </div>
                                </div>

                                <!-- Modul Ozel Ayarlar -->
                                <div class="col-md-6">
                                    <!-- Isletme Ayarlar -->
                                    <div class="modul-settings" id="isletmeSettings">
                                        <div class="alert alert-info mb-0 small">
                                            <i class="bi bi-info-circle"></i> Cari Tipi ve SSA Durum secimi <strong>Sutun Eslestirme</strong> adiminda yapilacaktir.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="button" class="btn btn-primary" id="readFileBtn" disabled>
                                    <i class="bi bi-file-earmark-spreadsheet"></i> Dosyayi Oku
                                </button>
                                <button type="button" class="btn btn-secondary" id="resetAllBtn" style="display:none;">
                                    <i class="bi bi-arrow-counterclockwise"></i> Sifirla
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- ADIM 2: Sutun Eslestirme -->
                    <div class="card card-warning card-outline mb-3 step-card disabled-step" id="step2Card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step2Badge">2</span>
                                <i class="bi bi-arrows-angle-expand"></i> Sutun Eslestirme
                            </h3>
                            <div class="card-tools">
                                <span class="badge bg-info" id="excelColCount">0 sutun</span>
                                <span class="badge bg-primary" id="excelRowCount">0 satir</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                <i class="bi bi-lightbulb"></i>
                                Excel sutunlarini veritabani alanlarina eslestirin. <strong class="text-danger">Zorunlu alanlar</strong> isaretlidir.
                            </p>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                        <table class="table table-sm table-bordered mb-0" id="mappingTable">
                                            <thead class="table-light sticky-top">
                                                <tr>
                                                    <th width="5%">#</th>
                                                    <th width="25%">Veritabani Alani</th>
                                                    <th width="30%">Excel Sutunu</th>
                                                    <th width="25%">On Izleme</th>
                                                    <th width="15%" class="text-center">Mukerrer</th>
                                                </tr>
                                            </thead>
                                            <tbody id="mappingBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-header py-2">
                                            <h6 class="card-title mb-0"><i class="bi bi-eye"></i> Excel On Izleme (Ilk 5 Satir)</h6>
                                        </div>
                                        <div class="card-body p-0" style="max-height: 400px; overflow: auto;">
                                            <table class="table table-sm table-bordered table-striped mb-0">
                                                <thead class="table-dark sticky-top" id="excelPreviewHead"></thead>
                                                <tbody id="excelPreviewBody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Statik Alanlar (Isletme) -->
                            <div class="mt-3" id="staticFieldsSection" style="display:none;">
                                <hr>
                                <h6 class="fw-bold"><i class="bi bi-sliders"></i> Tum Satirlara Uygulanacak Sabit Alanlar</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Cari Tipi</label>
                                        <select class="form-select form-select-sm" id="cariTipiSelect">
                                            <?php foreach ($cariTipleri as $ct): ?>
                                                <option value="<?= $ct['cari_tipi_id'] ?>"<?= $ct['cari_tipi_id'] == 1 ? ' selected' : '' ?>><?= htmlspecialchars($ct['cari_tipi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">SSA Durum</label>
                                        <select class="form-select form-select-sm" id="ssaAktifSelect">
                                            <option value="1" selected>Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="button" class="btn btn-outline-secondary me-2" onclick="backToStep1()">
                                    <i class="bi bi-arrow-left"></i> Geri
                                </button>
                                <button type="button" class="btn btn-warning" id="analyzeBtn">
                                    <i class="bi bi-search"></i> Analiz Et
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="autoMatchBtn">
                                    <i class="bi bi-magic"></i> Otomatik Eslestir
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ADIM 3: Analiz Sonucu -->
                    <div class="card card-info card-outline mb-3" id="summaryCard" style="display:none;">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step3Badge">3</span>
                                <i class="bi bi-bar-chart"></i> Analiz Sonucu
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center g-3" id="summaryBoxes">
                                <!-- JS ile doldurulacak -->
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6" id="duplicateActionDiv">
                                    <label class="form-label fw-bold">Mukerrer Kayitlar Icin:</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="duplicateAction" id="dupSkip" value="skip" checked>
                                        <label class="form-check-label" for="dupSkip">Atla (ekleme)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="duplicateAction" id="dupUpdate" value="update">
                                        <label class="form-check-label" for="dupUpdate">Guncelle (mevcut kayitlari guncelle)</label>
                                    </div>
                                </div>
                                <div class="col-md-6 text-end d-flex align-items-end justify-content-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary" onclick="backToStep2()">
                                        <i class="bi bi-arrow-left"></i> Geri
                                    </button>
                                    <button type="button" class="btn btn-success btn-lg" id="importBtn">
                                        <i class="bi bi-database-add"></i> Import Et
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Onizleme Tablosu -->
                    <div class="card card-primary card-outline" id="previewCard" style="display:none;">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-table"></i> Veri Onizleme</h3>
                            <div class="card-tools">
                                <span class="badge bg-success me-1"><i class="bi bi-plus"></i> Yeni</span>
                                <span class="badge bg-warning me-1"><i class="bi bi-exclamation"></i> Mukerrer</span>
                                <span class="badge bg-danger"><i class="bi bi-x"></i> Hata</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                <table class="table table-bordered table-sm table-hover mb-0">
                                    <thead class="table-dark sticky-top" id="previewHead"></thead>
                                    <tbody id="previewBody"></tbody>
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
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script>
    // ==========================================
    // MODUL TANIMLARI
    // ==========================================
    const modulConfigs = {
        isletme: {
            label: 'Isletme Import',
            requiredField: 'cari_adi',
            requiredLabel: 'Cari Adi',
            fields: [
                { key: 'cari_adi',              label: 'Cari Adi',              required: true },
                { key: 'cari_unvan',            label: 'Unvan',                 required: false },
                { key: 'cari_sektor_id',        label: 'Sektor [cari_sektor_id]', required: false },
                { key: 'cari_vergi_dairesi',    label: 'Vergi Dairesi',         required: false },
                { key: 'cari_vergi_no',         label: 'Vergi No',              required: false },
                { key: 'cari_telefon',          label: 'Telefon',               required: false },
                { key: 'cari_email',            label: 'E-Posta',               required: false },
                { key: 'cari_yetkili_adi',      label: 'Yetkili Adi Soyadi',    required: false },
                { key: 'cari_yetkili_telefon',  label: 'Yetkili Telefon',       required: false },
                { key: 'sehir',                 label: 'Il',                    required: false },
                { key: 'ilce',                  label: 'Ilce',                  required: false },
                { key: 'cari_adres',            label: 'Adres',                 required: false },
                { key: 'cari_web_sitesi',       label: 'Web Sitesi',            required: false }
            ],
            autoMatch: {
                'cari_adi': ['cari', 'cari adi', 'firma', 'isletme', 'isletme adi', 'musteri', 'musteri adi', 'isim', 'ad', 'organisation', 'organization'],
                'cari_unvan': ['unvan'],
                'cari_sektor_id': ['sektor', 'sektor id', 'cari sektor', 'cari_sektor_id'],
                'cari_vergi_dairesi': ['vergi dairesi'],
                'cari_vergi_no': ['vergi no', 'vergi numarasi'],
                'cari_telefon': ['telefon', 'tel'],
                'cari_email': ['email', 'e-posta', 'eposta', 'mail'],
                'cari_yetkili_adi': ['yetkili', 'yetkili adi', 'yetkili ad soyad'],
                'cari_yetkili_telefon': ['yetkili telefon', 'yetkili tel'],
                'sehir': ['sehir', 'il', 'city'],
                'ilce': ['ilce'],
                'cari_adres': ['adres'],
                'cari_web_sitesi': ['web', 'web sitesi', 'website', 'internet', 'site']
            },
            templateHeaders: ['Cari Adi', 'Unvan', 'Sektor [cari_sektor_id]', 'Vergi Dairesi', 'Vergi No', 'Telefon', 'E-Posta', 'Yetkili Adi', 'Yetkili Telefon', 'Il', 'Ilce', 'Adres', 'Web Sitesi'],
            templateSample: [
                ['ABC Teknoloji Ltd Sti', 'ABC Teknoloji', '1', 'Kadikoy VD', '9876543210', '0212 111 2233', 'info@abcteknoloji.com', 'Ali Veli', '0533 444 5566', 'Istanbul', 'Kadikoy', 'Bagdat Cad. No:10', 'www.abcteknoloji.com']
            ]
        }
    };

    let selectedModul = null;
    let excelHeaders = [];
    let excelData = [];
    let currentMapping = {};
    let analysisData = null;
    // ==========================================
    // MODUL SECIM
    // ==========================================
    $(document).on('click', '.modul-card', function() {
        const modul = $(this).data('modul');
        if (selectedModul === modul) return;
        selectedModul = modul;

        $('.modul-card').removeClass('border-primary border-3').addClass('border');
        $(this).removeClass('border').addClass('border-primary border-3');

        // Modul ayarlarini goster/gizle
        $('#isletmeSettings').addClass('d-none');
        if (modul === 'isletme') $('#isletmeSettings').removeClass('d-none');

        // Mapping tablosu varsa yeniden olustur
        if (excelHeaders.length > 0) {
            buildMappingTable();
        }

        // Buton kontrolu
        const hasFile = $('#excelFile').val() !== '';
        $('#readFileBtn').prop('disabled', !hasFile);
        $('#resetAllBtn').show();
    });

    // ==========================================
    // DOSYA OKUMA
    // ==========================================
    function readExcelFile() {
        if (!selectedModul) {
            showToast('Lutfen once bir modul secin!', 'warning');
            return;
        }
        const fileInput = document.getElementById('excelFile');
        if (!fileInput.files.length) {
            showToast('Lutfen bir Excel dosyasi secin!', 'warning');
            return;
        }
        const file = fileInput.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'xls', 'csv'].includes(ext)) {
            showToast('Desteklenen formatlar: .xlsx, .xls, .csv', 'error');
            return;
        }

        showLoading();
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array', cellDates: false, cellText: true, raw: true });
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                const jsonData = XLSX.utils.sheet_to_json(firstSheet, { header: 1, raw: true, defval: '' });

                if (jsonData.length < 2) {
                    showToast('Dosyada yeterli veri yok! En az 1 baslik + 1 veri satiri olmali.', 'error');
                    hideLoading();
                    return;
                }

                excelHeaders = jsonData[0].map(h => (h || '').toString().trim());
                excelData = jsonData.slice(1).filter(row => row.some(cell => cell !== '' && cell !== null && cell !== undefined));

                if (excelData.length === 0) {
                    showToast('Dosyada veri satiri bulunamadi!', 'error');
                    hideLoading();
                    return;
                }

                buildMappingTable();
                buildExcelPreview();
                autoMatch();

                $('#excelColCount').text(excelHeaders.length + ' sutun');
                $('#excelRowCount').text(excelData.length + ' satir');
                $('#step1Card').addClass('d-none');
                $('#step2Card').removeClass('d-none disabled-step');

                // Isletme ise statik alanlar bolumunu goster
                if (selectedModul === 'isletme') {
                    $('#staticFieldsSection').show();
                } else {
                    $('#staticFieldsSection').hide();
                }

                showToast(`${excelHeaders.length} sutun ve ${excelData.length} satir okundu.`, 'success');
            } catch(err) {
                console.error('Excel okuma hatasi:', err);
                showToast('Dosya okunamadi: ' + err.message, 'error');
            }
            hideLoading();
        };
        reader.readAsArrayBuffer(file);
    }
    // ==========================================
    // MAPPING TABLOSU
    // ==========================================
    function buildMappingTable() {
        const config = modulConfigs[selectedModul];
        let html = '';
        config.fields.forEach((field, idx) => {
            let options = '<option value="">-- Secim Yapilmadi --</option>';
            excelHeaders.forEach((h, i) => {
                options += `<option value="${i}">${escapeHtml(h)}</option>`;
            });
            const reqBadge = field.required ? ' <span class="badge bg-danger">Zorunlu</span>' : '';
            html += `<tr>
                <td>${idx + 1}</td>
                <td><strong>${escapeHtml(field.label)}</strong>${reqBadge}</td>
                <td>
                    <select class="form-select form-select-sm mapping-select" data-field="${field.key}" id="map_${field.key}">
                        ${options}
                    </select>
                </td>
                <td class="preview-cell" id="prev_${field.key}"><span class="text-muted">-</span></td>
                <td class="text-center">
                    <input type="radio" class="form-check-input dup-radio" name="duplicateField" value="${field.key}" id="dup_${field.key}" disabled>
                </td>
            </tr>`;
        });
        $('#mappingBody').html(html);

        // Mapping degistiginde preview guncelle + radio aktiflik guncelle
        $('.mapping-select').off('change').on('change', function() {
            const field = $(this).data('field');
            const colIndex = $(this).val();
            if (colIndex !== '') {
                let sample = excelData[0] ? (excelData[0][parseInt(colIndex)] || '-') : '-';
                if (dateFields.includes(field)) sample = excelDateToStr(sample);
                $(`#prev_${field}`).html(`<span class="text-success">${escapeHtml(truncate(String(sample), 50))}</span>`);
                $(`#dup_${field}`).prop('disabled', false);
            } else {
                $(`#prev_${field}`).html('<span class="text-muted">-</span>');
                if ($(`#dup_${field}`).is(':checked')) {
                    $(`#dup_${field}`).prop('checked', false);
                }
                $(`#dup_${field}`).prop('disabled', true);
            }
        });
    }

    // ==========================================
    // EXCEL ON IZLEME (ilk 5 satir)
    // ==========================================
    function buildExcelPreview() {
        let headHtml = '<tr><th>#</th>';
        excelHeaders.forEach(h => {
            headHtml += `<th>${escapeHtml(h)}</th>`;
        });
        headHtml += '</tr>';
        $('#excelPreviewHead').html(headHtml);

        let bodyHtml = '';
        const previewRows = excelData.slice(0, 5);
        previewRows.forEach((row, idx) => {
            bodyHtml += `<tr><td>${idx + 1}</td>`;
            excelHeaders.forEach((h, ci) => {
                bodyHtml += `<td>${escapeHtml(truncate(String(row[ci] || ''), 40))}</td>`;
            });
            bodyHtml += '</tr>';
        });
        if (excelData.length > 5) {
            bodyHtml += `<tr><td colspan="${excelHeaders.length + 1}" class="text-center text-muted">... toplam ${excelData.length} satir (ilk 5 gosteriliyor)</td></tr>`;
        }
        $('#excelPreviewBody').html(bodyHtml);
    }

    // ==========================================
    // OTOMATIK ESLESTIRME
    // ==========================================
    function autoMatch() {
        const config = modulConfigs[selectedModul];
        let matchCount = 0;
        config.fields.forEach(field => {
            const keywords = config.autoMatch[field.key] || [];
            let bestMatch = -1;
            for (let i = 0; i < excelHeaders.length; i++) {
                const hNorm = normalizeStr(excelHeaders[i]);
                for (const kw of keywords) {
                    if (hNorm === normalizeStr(kw) || hNorm.includes(normalizeStr(kw))) {
                        bestMatch = i;
                        break;
                    }
                }
                if (bestMatch >= 0) break;
            }
            if (bestMatch >= 0) {
                $(`#map_${field.key}`).val(bestMatch).trigger('change');
                matchCount++;
            }
        });
        if (matchCount > 0) {
            showToast(`${matchCount} sutun otomatik eslestirildi`, 'info');
        }
    }

    // Excel tarih seri numarasini YYYY-MM-DD string'e cevir
    const dateFields = ['aktivasyon_tarihi', 'taahut_bitis', 'dosya_acilis_tarihi'];
    function excelDateToStr(val) {
        if (val === '' || val === null || val === undefined) return '';
        // Sayisal seri numarasi kontrolu
        const num = Number(val);
        if (!isNaN(num) && num > 25000 && num < 100000) {
            // Excel epoch: 1900-01-01 (1 tabanli, 29 Subat 1900 bug'i)
            const utcDays = Math.floor(num) - 25569;
            const d = new Date(utcDays * 86400 * 1000);
            if (!isNaN(d.getTime())) {
                const yy = d.getUTCFullYear();
                const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                const dd = String(d.getUTCDate()).padStart(2, '0');
                return `${yy}-${mm}-${dd}`;
            }
        }
        return String(val).trim();
    }

    function normalizeStr(str) {
        return str.toLowerCase()
            .replace(/\u00e7/g, 'c').replace(/\u011f/g, 'g').replace(/\u0131/g, 'i')
            .replace(/\u00f6/g, 'o').replace(/\u015f/g, 's').replace(/\u00fc/g, 'u')
            .replace(/\u00c7/g, 'c').replace(/\u011e/g, 'g').replace(/\u0130/g, 'i')
            .replace(/\u00d6/g, 'o').replace(/\u015e/g, 's').replace(/\u00dc/g, 'u')
            .replace(/[^a-z0-9]/g, ' ').trim();
    }
    // ==========================================
    // ANALIZ - SUNUCUYA GONDER
    // ==========================================
    function analyzeData() {
        const config = modulConfigs[selectedModul];

        // Mapping topla
        currentMapping = {};
        let requiredMissing = false;
        config.fields.forEach(field => {
            const val = $(`#map_${field.key}`).val();
            if (val !== '' && val !== null) {
                currentMapping[field.key] = parseInt(val);
            }
            if (field.required && (val === '' || val === null)) {
                requiredMissing = true;
                showToast(`"${field.label}" alani zorunlu, lutfen bir sutun eslestiriniz!`, 'error');
            }
        });
        if (requiredMissing) return;

        // Eslestirilen verileri duz objelere cevir
        const mappedData = excelData.map(row => {
            const obj = {};
            for (const [fieldKey, colIdx] of Object.entries(currentMapping)) {
                let val = row[colIdx];
                if (val === undefined || val === null) val = '';
                // Tarih alanlari icin Excel seri numarasi donusumu
                if (dateFields.includes(fieldKey)) {
                    obj[fieldKey] = excelDateToStr(val);
                } else {
                    obj[fieldKey] = String(val).trim();
                }
            }
            return obj;
        });

        // Ek parametreler
        const params = {
            action: 'analyze',
            modul: selectedModul,
            duplicate_field: $('input[name="duplicateField"]:checked').val() || '',
            data: JSON.stringify(mappedData),
            mapping: JSON.stringify(currentMapping)
        };

        if (selectedModul === 'isletme') {
            params.cari_tipi_id = $('#cariTipiSelect').val() || '1';
            params.ssa_aktif = $('#ssaAktifSelect').val() || '1';
        }

        showLoading();
        $.ajax({
            url: '',
            method: 'POST',
            data: params,
            dataType: 'json',
            success: function(response) {
                hideLoading();
                if (response.success) {
                    analysisData = response;
                    updateSummary(response);
                    renderPreview(response);
                    $('#step2Card').addClass('d-none');
                    $('#summaryCard, #previewCard').removeClass('d-none').show();
                    showToast('Analiz tamamlandi!', 'success');
                } else {
                    showToast(response.message || 'Analiz hatasi', 'error');
                }
            },
            error: function(xhr) {
                hideLoading();
                console.error('Analiz hatasi:', xhr.responseText);
                showToast('Sunucu hatasi olustu!', 'error');
            }
        });
    }

    // ==========================================
    // OZET GUNCELLE
    // ==========================================
    function updateSummary(response) {
        const s = response.summary || {};
        let html = `
            <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-file-earmark-spreadsheet"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Satir</span><span class="info-box-number">${s.total || 0}</span></div></div></div>
            <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-plus-circle"></i></span><div class="info-box-content"><span class="info-box-text">Yeni Kayit</span><span class="info-box-number">${s.new || 0}</span></div></div></div>
            <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-arrow-repeat"></i></span><div class="info-box-content"><span class="info-box-text">Mukerrer</span><span class="info-box-number">${s.duplicate || 0}</span></div></div></div>
            <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-x-circle"></i></span><div class="info-box-content"><span class="info-box-text">Hata</span><span class="info-box-number">${s.errors || 0}</span></div></div></div>`;
        $('#summaryBoxes').html(html);
    }
    // ==========================================
    // ON IZLEME TABLOSU RENDER
    // ==========================================
    function renderPreview(response) {
        const rows = response.data || [];
        const config = modulConfigs[selectedModul];

        // Statik alanlar (isletme icin)
        const staticFields = response.static_fields || [];

        // Tablo baslik
        let headHtml = '<tr><th>#</th><th>Durum</th>';
        config.fields.forEach(f => {
            if (currentMapping.hasOwnProperty(f.key)) {
                headHtml += `<th>${escapeHtml(f.label)}</th>`;
            }
        });
        staticFields.forEach(sf => {
            headHtml += `<th class="text-center"><span class="text-primary">${escapeHtml(sf.label)}</span></th>`;
        });
        headHtml += '</tr>';
        $('#previewHead').html(headHtml);

        // Tablo body
        let bodyHtml = '';
        rows.forEach((row, idx) => {
            const md = row.mapped_data || {};
            let rowClass = '';
            let statusBadge = '';
            if (row.status === 'error' || row.has_error) {
                rowClass = 'table-danger';
                statusBadge = `<span class="badge bg-danger" title="${escapeHtml(row.error_message || '')}">${escapeHtml(row.error_message || 'Hata')}</span>`;
            } else if (row.status === 'duplicate') {
                rowClass = 'table-warning';
                statusBadge = '<span class="badge bg-warning">Mukerrer (DB)</span>';
            } else if (row.status === 'excel_duplicate') {
                rowClass = 'table-warning';
                statusBadge = `<span class="badge bg-warning" title="${escapeHtml(row.error_message || '')}">Mukerrer (Excel)</span>`;
            } else if (row.status === 'new') {
                rowClass = 'table-success';
                statusBadge = '<span class="badge bg-success">Yeni</span>';
            } else if (row.status === 'update') {
                rowClass = 'table-info';
                statusBadge = '<span class="badge bg-info">Guncellenecek</span>';
            }


            bodyHtml += `<tr class="${rowClass}"><td>${idx + 1}</td><td>${statusBadge}</td>`;
            config.fields.forEach(f => {
                if (currentMapping.hasOwnProperty(f.key)) {
                    let cellVal = md[f.key] || '-';
                    bodyHtml += `<td>${escapeHtml(truncate(String(cellVal), 50))}</td>`;
                }
            });
            staticFields.forEach(sf => {
                bodyHtml += `<td class="text-center"><small class="text-muted">${escapeHtml(sf.value)}</small></td>`;
            });
            bodyHtml += '</tr>';
        });
        $('#previewBody').html(bodyHtml);
    }

    // ==========================================
    // IMPORT ISLEMLERI
    // ==========================================
    function importData() {
        if (!analysisData) {
            showToast('Lutfen once analiz yapin!', 'warning');
            return;
        }

        const duplicateAction = $('input[name="duplicateAction"]:checked').val() || 'skip';
        const summary = analysisData.summary || {};
        let importCount = (summary.total || 0) - (summary.errors || 0) - (summary.excel_duplicate || 0);
        if (duplicateAction === 'skip') importCount -= (summary.duplicate || 0);

        if (importCount === 0) {
            showToast('Aktarilacak kayit bulunamadi!', 'warning');
            return;
        }

        confirmAction(
            `${importCount} kayit aktarilacak. Devam etmek istiyor musunuz?`,
            'Bu islem geri alinamaz!',
            function() {
                showLoading();
                const params = {
                    action: 'import',
                    modul: selectedModul,
                    duplicate_action: duplicateAction
                };

                $.ajax({
                    url: '',
                    method: 'POST',
                    data: params,
                    dataType: 'json',
                    success: function(response) {
                        hideLoading();
                        if (response.success) {
                            showSuccess('Import Tamamlandi!', response.message);
                            // Temizle
                            $.post('', { action: 'clear' });
                        } else {
                            showError('Import Hatasi!', response.message || 'Bilinmeyen hata');
                        }
                    },
                    error: function(xhr) {
                        hideLoading();
                        console.error('Import hatasi:', xhr.responseText);
                        showError('Sunucu Hatasi!', 'Islem sirasinda bir hata olustu.');
                    }
                });
            }
        );
    }
    // ==========================================
    // SIFIRLA
    // ==========================================
    function resetAll() {
        confirmAction('Tum verileri sifirlamak istiyor musunuz?', null, function() {
            selectedModul = null;
            excelHeaders = [];
            excelData = [];
            currentMapping = {};
            analysisData = null;

            $('.modul-card').removeClass('border-primary border-3').addClass('border');
            $('#excelFile').val('');
            $('#duplicateField').empty().append('<option value="">Modul secin</option>');
            $('#isletmeSettings').addClass('d-none');
            $('#step2Card, #summaryCard, #previewCard').addClass('d-none').hide();
            $('#step1Card').removeClass('d-none');
            $('#mappingBody, #excelPreviewHead, #excelPreviewBody, #previewTableHead, #previewTableBody, #summaryBoxes').empty();

            $.post('', { action: 'clear' });
            showToast('Sifirlandiyor...', 'info');
        });
    }

    // ==========================================
    // ADIM ARASI GEC
    // ==========================================
    function backToStep1() {
        $('#step2Card').addClass('d-none');
        $('#step1Card').removeClass('d-none');
    }

    function backToStep2() {
        $('#summaryCard, #previewCard').addClass('d-none').hide();
        $('#step2Card').removeClass('d-none');
    }

    // ==========================================
    // SABLON INDIR
    // ==========================================
    function downloadTemplate() {
        if (!selectedModul) {
            showToast('Lutfen once bir modul secin!', 'warning');
            return;
        }
        const config = modulConfigs[selectedModul];
        const wsData = [config.templateHeaders, ...config.templateSample];
        const ws = XLSX.utils.aoa_to_sheet(wsData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Sablon');

        // Sutun genislikleri
        ws['!cols'] = config.templateHeaders.map(h => ({ wch: Math.max(h.length + 5, 15) }));

        XLSX.writeFile(wb, `${selectedModul}_import_sablonu.xlsx`);
        showToast('Sablon dosyasi indirildi', 'success');
    }

    // ==========================================
    // YARDIMCI FONKSIYONLAR
    // ==========================================
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    // ==========================================
    // SAYFA HAZIR
    // ==========================================
    $(document).ready(function() {
        selectedModul = 'isletme';
        $('#isletmeSettings').removeClass('d-none');

        // Select2 baslat
        if (typeof $.fn.select2 !== 'undefined') {
            $('#cariTipiSelect, #ssaAktifSelect').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seciniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuc bulunamadi"; },
                    searching: function() { return "Araniyor..."; }
                }
            });
        }

        // Dosya secildiginde ismi goster ve butonu aktif et
        $('#excelFile').on('change', function() {
            const f = this.files[0];
            if (f) {
                showToast(`Dosya secildi: ${f.name}`, 'info');
                $('#readFileBtn').prop('disabled', !selectedModul);
            }
        });

        // Modul secildiginde buton kontrolu (modul-card handler zaten yukarda)
        // readFileBtn aktif/pasif durumu modul-card handler icinde yonetiliyor

        // Buton click handler'lari
        $('#readFileBtn').on('click', readExcelFile);
        $('#analyzeBtn').on('click', analyzeData);
        $('#importBtn').on('click', importData);
        $('#resetAllBtn').on('click', resetAll);
        $('#autoMatchBtn').on('click', autoMatch);
        $('#downloadTemplateBtn').on('click', downloadTemplate);
    });
    </script>
</body>
</html>