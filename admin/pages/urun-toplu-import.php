<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erisim yetkiniz bulunmamaktadir.');
}

$pageInfo = $db->fetchOne(
    "SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
     FROM Menu_Sayfalar s
     LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1",
    ['%' . $currentPageFile]
);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Urun/Hizmet Toplu Import';
$menuAdi = $pageInfo['menu_adi'] ?? null;
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

$kategoriler = $db->fetchAll("SELECT kategori_id, kategori_adi FROM UrunHizmet_Kategoriler WHERE kategori_durum = 1 ORDER BY kategori_sira_no, kategori_adi");
$markalar = $db->fetchAll("SELECT marka_id, marka_adi FROM UrunHizmet_Markalar WHERE marka_durum = 1 ORDER BY marka_sira_no, marka_adi");
$kdvler = $db->fetchAll("SELECT kdv_id, kdv_adi, kdv_oran FROM UrunHizmet_KDVTanimlari WHERE kdv_durum = 1 ORDER BY kdv_sira_no, kdv_oran DESC");
$paraBirimleri = $db->fetchAll("SELECT ParaBirimi_id, ParaBirimi_Kod, ParaBirimi_Ad, ParaBirimi_Simge FROM tanim_ParaBirimi WHERE Durum = 1 ORDER BY ParaBirimi_id");

function trLower($value) {
    $value = str_replace(['I', 'İ'], ['ı', 'i'], (string)$value);
    return mb_strtolower(trim($value), 'UTF-8');
}

function parseBoolInt($value, $default = 0) {
    if ($value === null || $value === '') return $default;
    $value = trLower((string)$value);
    if (in_array($value, ['1', 'true', 'evet', 'aktif', 'var', 'yes', 'on'], true)) return 1;
    if (in_array($value, ['0', 'false', 'hayir', 'pasif', 'yok', 'no', 'off'], true)) return 0;
    return is_numeric($value) ? ((int)$value > 0 ? 1 : 0) : $default;
}

function parseTip($value) {
    if ($value === null || $value === '') return null;
    $value = trLower((string)$value);
    if (in_array($value, ['1', 'urun', 'ürün'], true)) return 1;
    if (in_array($value, ['2', 'hizmet'], true)) return 2;
    return null;
}

function parseFloatValue($value) {
    if ($value === null || $value === '') return null;
    $value = str_replace([' ', ','], ['', '.'], trim((string)$value));
    return is_numeric($value) ? (float)$value : null;
}

function parseIntValue($value) {
    if ($value === null || $value === '') return null;
    return is_numeric($value) ? (int)$value : null;
}

function resolveLookupId($value, array $rows, string $idKey, string $nameKey) {
    if ($value === null || $value === '') return null;
    $value = trim((string)$value);
    if (ctype_digit($value)) {
        $id = (int)$value;
        foreach ($rows as $row) {
            if ((int)$row[$idKey] === $id) return $id;
        }
        return null;
    }
    $needle = trLower($value);
    foreach ($rows as $row) {
        if (trLower((string)$row[$nameKey]) === $needle) {
            return (int)$row[$idKey];
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'clear') {
            unset($_SESSION['urun_import_data'], $_SESSION['urun_import_settings']);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'analyze') {
            $rawData = $_POST['data'] ?? '';
            $mapping = json_decode($_POST['mapping'] ?? '{}', true);
            $duplicateField = trim((string)($_POST['duplicate_field'] ?? ''));
            $useWildcard = (int)($_POST['use_wildcard'] ?? 0);

            if ($rawData === '') {
                echo json_encode(['success' => false, 'message' => 'Veri alinamadi']);
                exit;
            }

            $data = json_decode($rawData, true);
            if (!is_array($data) || count($data) === 0) {
                echo json_encode(['success' => false, 'message' => 'Dosyada gecerli veri bulunamadi']);
                exit;
            }

            if ($duplicateField === '') {
                echo json_encode(['success' => false, 'message' => 'Mukerrer kontrol kolonu seciniz.']);
                exit;
            }

            $allowedDuplicateCols = [
                'urun_hizmet_kodu',
                'urun_hizmet_adi',
                'urun_hizmet_barkod',
                'urun_hizmet_model'
            ];

            if (!in_array($duplicateField, $allowedDuplicateCols, true)) {
                echo json_encode(['success' => false, 'message' => 'Mukerrer kontrol kolonu gecersiz.']);
                exit;
            }

            $staticFields = [
                'urun_hizmet_tip' => $_POST['static_urun_hizmet_tip'] ?? '',
                'urun_hizmet_kategori_id' => $_POST['static_urun_hizmet_kategori_id'] ?? '',
                'urun_hizmet_birim' => trim((string)($_POST['static_urun_hizmet_birim'] ?? '')),
                'urun_hizmet_kdv_id' => $_POST['static_urun_hizmet_kdv_id'] ?? '',
                'urun_hizmet_para_birimi_id' => $_POST['static_urun_hizmet_para_birimi_id'] ?? ''
            ];

            $excelDupMap = [];
            $validatedData = [];

            foreach ($data as $i => $row) {
                $rowNum = $i + 2;
                $mapped = [];
                foreach ($mapping as $field => $colIndex) {
                    if (!array_key_exists($field, $row)) continue;
                    $mapped[$field] = trim((string)$row[$field]);
                }

                foreach ($staticFields as $field => $staticVal) {
                    if (($mapped[$field] ?? '') === '' && $staticVal !== '' && $staticVal !== null) {
                        $mapped[$field] = (string)$staticVal;
                    }
                }

                $item = [
                    'row_num' => $rowNum,
                    'mapped_data' => $mapped,
                    'status' => 'new',
                    'has_error' => false,
                    'error_message' => '',
                    'duplicate_id' => null,
                    'normalized' => []
                ];

                $norm = [
                    'urun_hizmet_tip' => parseTip($mapped['urun_hizmet_tip'] ?? '') ?? 1,
                    'urun_hizmet_kodu' => trim((string)($mapped['urun_hizmet_kodu'] ?? '')),
                    'urun_hizmet_adi' => trim((string)($mapped['urun_hizmet_adi'] ?? '')),
                    'urun_hizmet_model' => trim((string)($mapped['urun_hizmet_model'] ?? '')),
                    'urun_hizmet_aciklama' => trim((string)($mapped['urun_hizmet_aciklama'] ?? '')),
                    'urun_hizmet_kategori_id' => resolveLookupId($mapped['urun_hizmet_kategori_id'] ?? '', $kategoriler, 'kategori_id', 'kategori_adi'),
                    'urun_hizmet_marka_id' => resolveLookupId($mapped['urun_hizmet_marka_id'] ?? '', $markalar, 'marka_id', 'marka_adi'),
                    'urun_hizmet_birim' => trim((string)($mapped['urun_hizmet_birim'] ?? '')),
                    'urun_hizmet_kritik_stok' => parseIntValue($mapped['urun_hizmet_kritik_stok'] ?? ''),
                    'urun_hizmet_satis_fiyati' => parseFloatValue($mapped['urun_hizmet_satis_fiyati'] ?? ''),
                    'urun_hizmet_taban_fiyati' => parseFloatValue($mapped['urun_hizmet_taban_fiyati'] ?? ''),
                    'Urun_Hizmet_AlisFiyati' => parseFloatValue($mapped['Urun_Hizmet_AlisFiyati'] ?? ''),
                    'urun_hizmet_kdv_id' => resolveLookupId($mapped['urun_hizmet_kdv_id'] ?? '', $kdvler, 'kdv_id', 'kdv_adi'),
                    'urun_hizmet_barkod' => trim((string)($mapped['urun_hizmet_barkod'] ?? '')),
                    'urun_hizmet_gorsel_url' => trim((string)($mapped['urun_hizmet_gorsel_url'] ?? '')),
                    'urun_hizmet_sira_no' => parseIntValue($mapped['urun_hizmet_sira_no'] ?? ''),
                    'urun_hizmet_durum' => parseBoolInt($mapped['urun_hizmet_durum'] ?? '', 1),
                    'urun_hizmet_serino' => parseBoolInt($mapped['urun_hizmet_serino'] ?? '', 0),
                    'hizmetSuresi' => parseIntValue($mapped['hizmetSuresi'] ?? ''),
                    'urun_hizmet_para_birimi_id' => resolveLookupId($mapped['urun_hizmet_para_birimi_id'] ?? '', $paraBirimleri, 'ParaBirimi_id', 'ParaBirimi_Kod')
                ];

                if ($norm['urun_hizmet_para_birimi_id'] === null && !empty($mapped['urun_hizmet_para_birimi_id'])) {
                    $norm['urun_hizmet_para_birimi_id'] = resolveLookupId($mapped['urun_hizmet_para_birimi_id'], $paraBirimleri, 'ParaBirimi_id', 'ParaBirimi_Ad');
                }

                if ($norm['urun_hizmet_kdv_id'] === null) {
                    $item['has_error'] = true;
                    $item['status'] = 'error';
                    $item['error_message'] = 'KDV alani bulunamadi ya da eslesmedi.';
                }

                if ($norm['hizmetSuresi'] !== null && !in_array($norm['hizmetSuresi'], [3, 6, 9, 12, 24], true)) {
                    $item['has_error'] = true;
                    $item['status'] = 'error';
                    $item['error_message'] = 'Hizmet suresi yalnizca 3, 6, 9, 12, 24 olabilir.';
                }

                $dupValue = trim((string)($mapped[$duplicateField] ?? ''));
                if (!$item['has_error'] && $dupValue === '') {
                    $item['has_error'] = true;
                    $item['status'] = 'error';
                    $item['error_message'] = 'Mukerrer kontrol kolonu bos olamaz.';
                }

                if (!$item['has_error']) {
                    $excelKey = trLower($dupValue);
                    if (isset($excelDupMap[$excelKey])) {
                        $item['status'] = 'excel_duplicate';
                        $item['error_message'] = 'Excel icinde mukerrer (Satir ' . $excelDupMap[$excelKey] . ')';
                    } else {
                        $excelDupMap[$excelKey] = $rowNum;
                    }
                }

                if (!$item['has_error'] && $item['status'] === 'new') {
                    $existing = null;
                    if ($useWildcard && strpos($dupValue, '*') !== false) {
                        $likeValue = str_replace('*', '%', $dupValue);
                        $existing = $db->fetchOne("SELECT TOP 1 urun_hizmet_id FROM Urun_Hizmet WHERE $duplicateField LIKE ?", [$likeValue]);
                    } else {
                        $existing = $db->fetchOne("SELECT TOP 1 urun_hizmet_id FROM Urun_Hizmet WHERE $duplicateField = ?", [$dupValue]);
                    }

                    if ($existing) {
                        $item['status'] = 'duplicate';
                        $item['duplicate_id'] = (int)$existing['urun_hizmet_id'];
                    }
                }

                if (!$item['has_error'] && $item['status'] === 'new' && $norm['urun_hizmet_adi'] === '') {
                    $item['has_error'] = true;
                    $item['status'] = 'error';
                    $item['error_message'] = 'Yeni kayit icin Urun/Hizmet Adi zorunludur.';
                }

                $item['normalized'] = $norm;
                $validatedData[] = $item;
            }

            $_SESSION['urun_import_data'] = $validatedData;
            $_SESSION['urun_import_settings'] = [
                'mapping' => $mapping,
                'duplicate_field' => $duplicateField,
                'use_wildcard' => $useWildcard,
                'static_fields' => $staticFields
            ];

            $summary = [
                'total' => count($validatedData),
                'new' => count(array_filter($validatedData, fn($r) => $r['status'] === 'new')),
                'duplicate' => count(array_filter($validatedData, fn($r) => $r['status'] === 'duplicate')),
                'excel_duplicate' => count(array_filter($validatedData, fn($r) => $r['status'] === 'excel_duplicate')),
                'errors' => count(array_filter($validatedData, fn($r) => $r['has_error']))
            ];

            echo json_encode([
                'success' => true,
                'data' => $validatedData,
                'summary' => $summary
            ]);
            exit;
        }

        if ($action === 'import') {
            $importData = $_SESSION['urun_import_data'] ?? [];
            $settings = $_SESSION['urun_import_settings'] ?? [];
            if (empty($importData) || empty($settings)) {
                echo json_encode(['success' => false, 'message' => 'Import verisi bulunamadi.']);
                exit;
            }

            $duplicateAction = $_POST['duplicate_action'] ?? 'update';
            $duplicateField = $settings['duplicate_field'];

            $imported = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($importData as $row) {
                if (!empty($row['has_error']) || ($row['status'] ?? '') === 'excel_duplicate') {
                    $skipped++;
                    continue;
                }

                $norm = $row['normalized'] ?? [];

                if (($row['status'] ?? '') === 'duplicate') {
                    if ($duplicateAction !== 'update' || empty($row['duplicate_id'])) {
                        $skipped++;
                        continue;
                    }

                    $updFields = [];
                    $updParams = [];

                    $fieldMap = [
                        'urun_hizmet_tip' => $norm['urun_hizmet_tip'] ?? null,
                        'urun_hizmet_kodu' => $norm['urun_hizmet_kodu'] ?? '',
                        'urun_hizmet_adi' => $norm['urun_hizmet_adi'] ?? '',
                        'urun_hizmet_model' => $norm['urun_hizmet_model'] ?? '',
                        'urun_hizmet_aciklama' => $norm['urun_hizmet_aciklama'] ?? '',
                        'urun_hizmet_kategori_id' => $norm['urun_hizmet_kategori_id'] ?? null,
                        'urun_hizmet_marka_id' => $norm['urun_hizmet_marka_id'] ?? null,
                        'urun_hizmet_birim' => $norm['urun_hizmet_birim'] ?? '',
                        'urun_hizmet_kritik_stok' => $norm['urun_hizmet_kritik_stok'] ?? null,
                        'urun_hizmet_satis_fiyati' => $norm['urun_hizmet_satis_fiyati'] ?? null,
                        'urun_hizmet_taban_fiyati' => $norm['urun_hizmet_taban_fiyati'] ?? null,
                        'Urun_Hizmet_AlisFiyati' => $norm['Urun_Hizmet_AlisFiyati'] ?? null,
                        'urun_hizmet_kdv_id' => $norm['urun_hizmet_kdv_id'] ?? null,
                        'urun_hizmet_barkod' => $norm['urun_hizmet_barkod'] ?? '',
                        'urun_hizmet_gorsel_url' => $norm['urun_hizmet_gorsel_url'] ?? '',
                        'urun_hizmet_sira_no' => $norm['urun_hizmet_sira_no'] ?? null,
                        'urun_hizmet_durum' => $norm['urun_hizmet_durum'] ?? 1,
                        'urun_hizmet_serino' => $norm['urun_hizmet_serino'] ?? 0,
                        'hizmetSuresi' => $norm['hizmetSuresi'] ?? null,
                        'urun_hizmet_para_birimi_id' => $norm['urun_hizmet_para_birimi_id'] ?? null
                    ];

                    foreach ($fieldMap as $field => $value) {
                        if ($field === $duplicateField) continue;
                        if ($value === '' || $value === null) continue;
                        $updFields[] = "$field = ?";
                        $updParams[] = $value;
                    }

                    $updFields[] = 'urun_hizmet_guncelleme_tarihi = GETDATE()';
                    $updFields[] = 'urun_hizmet_guncelleyen_kullanici_id = ?';
                    $updParams[] = $user['kullanici_id'];
                    $updParams[] = $row['duplicate_id'];

                    $db->execute("UPDATE Urun_Hizmet SET " . implode(', ', $updFields) . " WHERE urun_hizmet_id = ?", $updParams);
                    $updated++;
                    continue;
                }

                $code = $norm['urun_hizmet_kodu'] ?? '';
                if ($code === '') {
                    $code = 'UH-' . time() . '-' . random_int(100, 999);
                }

                if (($norm['urun_hizmet_adi'] ?? '') === '') {
                    $skipped++;
                    continue;
                }

                $db->execute(
                    "INSERT INTO Urun_Hizmet (
                        urun_hizmet_tip, urun_hizmet_kodu, urun_hizmet_adi, urun_hizmet_model,
                        urun_hizmet_aciklama, urun_hizmet_kategori_id, urun_hizmet_marka_id,
                        urun_hizmet_birim, urun_hizmet_kritik_stok, urun_hizmet_satis_fiyati,
                        urun_hizmet_taban_fiyati, Urun_Hizmet_AlisFiyati, urun_hizmet_kdv_id, urun_hizmet_barkod,
                        urun_hizmet_gorsel_url, urun_hizmet_sira_no, urun_hizmet_durum,
                        urun_hizmet_serino, hizmetSuresi, urun_hizmet_para_birimi_id,
                        urun_hizmet_olusturma_tarihi, urun_hizmet_olusturan_kullanici_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)",
                    [
                        $norm['urun_hizmet_tip'] ?? 1,
                        $code,
                        $norm['urun_hizmet_adi'] ?? '',
                        $norm['urun_hizmet_model'] ?: null,
                        $norm['urun_hizmet_aciklama'] ?: null,
                        $norm['urun_hizmet_kategori_id'] ?? null,
                        $norm['urun_hizmet_marka_id'] ?? null,
                        $norm['urun_hizmet_birim'] ?: null,
                        $norm['urun_hizmet_kritik_stok'] ?? 0,
                        $norm['urun_hizmet_satis_fiyati'] ?? 0,
                        $norm['urun_hizmet_taban_fiyati'] ?? 0,
                        $norm['Urun_Hizmet_AlisFiyati'] ?? 0,
                        $norm['urun_hizmet_kdv_id'],
                        $norm['urun_hizmet_barkod'] ?: null,
                        $norm['urun_hizmet_gorsel_url'] ?: null,
                        $norm['urun_hizmet_sira_no'] ?? 0,
                        $norm['urun_hizmet_durum'] ?? 1,
                        $norm['urun_hizmet_serino'] ?? 0,
                        $norm['hizmetSuresi'] ?? null,
                        $norm['urun_hizmet_para_birimi_id'] ?? null,
                        $user['kullanici_id']
                    ]
                );
                $imported++;
            }

            unset($_SESSION['urun_import_data'], $_SESSION['urun_import_settings']);
            $msg = "Import tamamlandi. $imported yeni kayit eklendi";
            if ($updated > 0) $msg .= ", $updated kayit guncellendi";
            if ($skipped > 0) $msg .= ", $skipped kayit atlandi";
            $msg .= '.';

            echo json_encode(['success' => true, 'message' => $msg, 'imported' => $imported, 'updated' => $updated, 'skipped' => $skipped]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Gecersiz islem']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <style>
        .step-card.disabled-step { opacity: .55; pointer-events: none; }
        .step-number { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #0d6efd; color: #fff; font-weight: 700; margin-right: 8px; }
        .sticky-top { top: 0; z-index: 1; }
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
                        <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                        <li class="breadcrumb-item"><a href="urun-yonetimi.php">Urun Yonetimi</a></li>
                        <li class="breadcrumb-item active">Toplu Import</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="card card-primary card-outline mb-3 step-card" id="step1Card">
                <div class="card-header">
                    <h3 class="card-title"><span class="step-number">1</span>Dosya ve Eslestirme</h3>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Excel / CSV Dosyasi</label>
                            <input type="file" class="form-control" id="excelFile" accept=".csv,.xlsx,.xls">
                            <div class="form-text">Desteklenen formatlar: .xlsx, .xls, .csv</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tum Satirlara Uygulanacak Sabit Alanlar (Istege Bagli)</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <select id="static_urun_hizmet_tip" class="form-select form-select-sm static-select">
                                        <option value="">Tip (Bos gec)</option>
                                        <option value="1">Urun</option>
                                        <option value="2">Hizmet</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <select id="static_urun_hizmet_kategori_id" class="form-select form-select-sm static-select">
                                        <option value="">Kategori (Bos gec)</option>
                                        <?php foreach ($kategoriler as $k): ?>
                                            <option value="<?= (int)$k['kategori_id'] ?>"><?= htmlspecialchars($k['kategori_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <input type="text" id="static_urun_hizmet_birim" class="form-control form-control-sm" placeholder="Birim (or: Adet)">
                                </div>
                                <div class="col-6">
                                    <select id="static_urun_hizmet_kdv_id" class="form-select form-select-sm static-select">
                                        <option value="">KDV (Bos gec)</option>
                                        <?php foreach ($kdvler as $k): ?>
                                            <option value="<?= (int)$k['kdv_id'] ?>"><?= htmlspecialchars($k['kdv_adi']) ?> (%<?= (int)$k['kdv_oran'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <select id="static_urun_hizmet_para_birimi_id" class="form-select form-select-sm static-select">
                                        <option value="">Para Birimi (Bos gec)</option>
                                        <?php foreach ($paraBirimleri as $p): ?>
                                            <option value="<?= (int)$p['ParaBirimi_id'] ?>"><?= htmlspecialchars($p['ParaBirimi_Kod']) ?> - <?= htmlspecialchars($p['ParaBirimi_Ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="button" class="btn btn-primary" id="readFileBtn"><i class="bi bi-file-earmark-spreadsheet"></i> Dosyayi Oku</button>
                        <button type="button" class="btn btn-secondary" id="resetAllBtn"><i class="bi bi-arrow-counterclockwise"></i> Sifirla</button>
                    </div>
                </div>
            </div>

            <div class="card card-warning card-outline mb-3 step-card disabled-step" id="step2Card">
                <div class="card-header">
                    <h3 class="card-title"><span class="step-number">2</span>Sutun Eslestirme</h3>
                    <div class="card-tools">
                        <span class="badge bg-info" id="excelColCount">0 sutun</span>
                        <span class="badge bg-primary" id="excelRowCount">0 satir</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="table-responsive" style="max-height: 520px; overflow:auto;">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th>#</th>
                                            <th>Veritabani Alani</th>
                                            <th>Excel Sutunu</th>
                                            <th>On Izleme</th>
                                            <th>Mukerrer</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mappingBody"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-header py-2"><h6 class="card-title mb-0"><i class="bi bi-eye"></i> Excel On Izleme (Ilk 5 Satir)</h6></div>
                                <div class="card-body p-0" style="max-height:400px; overflow:auto;">
                                    <table class="table table-sm table-bordered table-striped mb-0">
                                        <thead class="table-dark sticky-top" id="excelPreviewHead"></thead>
                                        <tbody id="excelPreviewBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="useWildcard">
                            <label class="form-check-label fw-bold" for="useWildcard">
                                Joker Karakter (*) Desteği
                                <small class="text-muted d-block">Örn: Excel'de '630122*' değeri, sistemdeki '630122-ABC' ile eşleşir.</small>
                            </label>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" id="autoMatchBtn"><i class="bi bi-magic"></i> Otomatik Eslestir</button>
                        <button type="button" class="btn btn-warning" id="analyzeBtn"><i class="bi bi-search"></i> Analiz Et</button>
                    </div>
                </div>
            </div>

            <div class="card card-info card-outline mb-3" id="summaryCard" style="display:none;">
                <div class="card-header"><h3 class="card-title"><span class="step-number">3</span>Analiz Sonucu</h3></div>
                <div class="card-body">
                    <div class="row text-center g-3" id="summaryBoxes"></div>
                    <div class="mt-3">
                        <label class="form-label fw-bold">Mukerrer kayitlar:</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="duplicateAction" id="dupUpdate" value="update" checked>
                            <label class="form-check-label" for="dupUpdate">Guncelle (yalnizca diger kolonlar)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="duplicateAction" id="dupSkip" value="skip">
                            <label class="form-check-label" for="dupSkip">Atla</label>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="button" class="btn btn-success btn-lg" id="importBtn"><i class="bi bi-database-add"></i> Import Et</button>
                    </div>
                </div>
            </div>

            <div class="card card-primary card-outline" id="previewCard" style="display:none;">
                <div class="card-header"><h3 class="card-title"><i class="bi bi-table"></i> Veri Onizleme</h3></div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:500px; overflow:auto;">
                        <table class="table table-bordered table-sm mb-0">
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
const fields = [
    { key: 'urun_hizmet_tip', label: 'Tip', required: false },
    { key: 'urun_hizmet_kodu', label: 'Kod', required: false },
    { key: 'urun_hizmet_adi', label: 'Urun/Hizmet Adi', required: false },
    { key: 'urun_hizmet_model', label: 'Model', required: false },
    { key: 'urun_hizmet_aciklama', label: 'Aciklama', required: false },
    { key: 'urun_hizmet_kategori_id', label: 'Kategori (id/ad)', required: false },
    { key: 'urun_hizmet_marka_id', label: 'Marka (id/ad)', required: false },
    { key: 'urun_hizmet_birim', label: 'Birim', required: false },
    { key: 'urun_hizmet_kritik_stok', label: 'Kritik Stok', required: false },
    { key: 'urun_hizmet_satis_fiyati', label: 'Satis Fiyati', required: false },
    { key: 'urun_hizmet_taban_fiyati', label: 'Taban Fiyat', required: false },
    { key: 'Urun_Hizmet_AlisFiyati', label: 'Alis Fiyati', required: false },
    { key: 'urun_hizmet_kdv_id', label: 'KDV (id/ad)', required: false },
    { key: 'urun_hizmet_barkod', label: 'Barkod', required: false },
    { key: 'urun_hizmet_gorsel_url', label: 'Gorsel URL', required: false },
    { key: 'urun_hizmet_sira_no', label: 'Sira No', required: false },
    { key: 'urun_hizmet_durum', label: 'Durum (1/0, aktif/pasif)', required: false },
    { key: 'urun_hizmet_serino', label: 'Serino Takibi (1/0)', required: false },
    { key: 'hizmetSuresi', label: 'Hizmet Suresi (3/6/9/12/24)', required: false },
    { key: 'urun_hizmet_para_birimi_id', label: 'Para Birimi (id/kod/ad)', required: false }
];

const autoMatchMap = {
    urun_hizmet_tip: ['tip', 'tur', 'turu', 'urun tip', 'hizmet tip'],
    urun_hizmet_kodu: ['kod', 'stok kodu', 'urun kodu'],
    urun_hizmet_adi: ['ad', 'adi', 'urun adi', 'hizmet adi', 'urun hizmet adi', 'isim'],
    urun_hizmet_model: ['model'],
    urun_hizmet_aciklama: ['aciklama', 'acıklama', 'description'],
    urun_hizmet_kategori_id: ['kategori', 'kategori id'],
    urun_hizmet_marka_id: ['marka', 'marka id'],
    urun_hizmet_birim: ['birim', 'unit'],
    urun_hizmet_kritik_stok: ['kritik stok', 'min stok'],
    urun_hizmet_satis_fiyati: ['satis', 'satıs', 'fiyat', 'satis fiyat'],
    urun_hizmet_taban_fiyati: ['taban fiyat'],
    Urun_Hizmet_AlisFiyati: ['alis fiyat', 'alis fiyati', 'alis', 'alıs fiyat', 'alıs fiyati'],
    urun_hizmet_kdv_id: ['kdv', 'kdv id', 'kdv oran'],
    urun_hizmet_barkod: ['barkod', 'barcode'],
    urun_hizmet_gorsel_url: ['gorsel', 'görsel', 'resim', 'image', 'url'],
    urun_hizmet_sira_no: ['sira', 'sıra', 'sira no'],
    urun_hizmet_durum: ['durum', 'aktif', 'pasif'],
    urun_hizmet_serino: ['serino', 'seri no', 'seri'],
    hizmetSuresi: ['hizmet suresi', 'sure', 'süre', 'ay'],
    urun_hizmet_para_birimi_id: ['para birimi', 'doviz', 'döviz', 'currency']
};

let excelHeaders = [];
let excelData = [];
let currentMapping = {};
let analysisData = null;

function normalize(v) {
    return String(v || '').toLowerCase()
        .replace(/ı/g, 'i').replace(/İ/g, 'i').replace(/ç/g, 'c').replace(/ğ/g, 'g').replace(/ö/g, 'o').replace(/ş/g, 's').replace(/ü/g, 'u')
        .replace(/[^a-z0-9]/g, ' ').trim();
}

function truncate(v, len) {
    const t = String(v || '');
    return t.length > len ? t.substring(0, len) + '...' : t;
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

function buildMappingTable() {
    let html = '';
    fields.forEach((f, idx) => {
        let opts = '<option value="">-- Secim Yapilmadi --</option>';
        excelHeaders.forEach((h, i) => {
            opts += `<option value="${i}">${escapeHtml(h)}</option>`;
        });
        const req = f.required ? '<span class="badge bg-danger ms-1">Zorunlu</span>' : '';
        html += `<tr>
            <td>${idx + 1}</td>
            <td><strong>${escapeHtml(f.label)}</strong>${req}</td>
            <td><select class="form-select form-select-sm mapping-select" data-field="${f.key}" id="map_${f.key}">${opts}</select></td>
            <td id="prev_${f.key}" class="text-muted">-</td>
            <td class="text-center"><input class="form-check-input dup-radio" type="radio" name="duplicateField" id="dup_${f.key}" value="${f.key}" disabled></td>
        </tr>`;
    });
    $('#mappingBody').html(html);

    $('.mapping-select').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Excel kolonu secin',
        allowClear: true
    });

    $('.mapping-select').on('change', function() {
        const field = $(this).data('field');
        const col = $(this).val();
        if (col !== '') {
            const sample = excelData[0] && excelData[0][parseInt(col, 10)] !== undefined ? excelData[0][parseInt(col, 10)] : '-';
            $(`#prev_${field}`).html(`<span class="text-success">${escapeHtml(truncate(sample, 40))}</span>`);
            $(`#dup_${field}`).prop('disabled', false);
        } else {
            $(`#prev_${field}`).text('-');
            if ($(`#dup_${field}`).is(':checked')) $(`#dup_${field}`).prop('checked', false);
            $(`#dup_${field}`).prop('disabled', true);
        }
    });
}

function buildExcelPreview() {
    let head = '<tr><th>#</th>';
    excelHeaders.forEach(h => head += `<th>${escapeHtml(h)}</th>`);
    head += '</tr>';
    $('#excelPreviewHead').html(head);

    let body = '';
    excelData.slice(0, 5).forEach((row, i) => {
        body += `<tr><td>${i + 1}</td>`;
        excelHeaders.forEach((_, ci) => body += `<td>${escapeHtml(truncate(String(row[ci] || ''), 40))}</td>`);
        body += '</tr>';
    });
    if (excelData.length > 5) {
        body += `<tr><td colspan="${excelHeaders.length + 1}" class="text-center text-muted">... toplam ${excelData.length} satir (ilk 5 gosteriliyor)</td></tr>`;
    }
    $('#excelPreviewBody').html(body);
}

function autoMatch() {
    let count = 0;
    fields.forEach(f => {
        const keys = autoMatchMap[f.key] || [];
        let found = -1;
        for (let i = 0; i < excelHeaders.length; i++) {
            const h = normalize(excelHeaders[i]);
            for (const k of keys) {
                const nk = normalize(k);
                if (h === nk || h.includes(nk)) {
                    found = i;
                    break;
                }
            }
            if (found >= 0) break;
        }
        if (found >= 0) {
            $(`#map_${f.key}`).val(String(found)).trigger('change');
            count++;
        }
    });
    if (count > 0) showToast(`${count} sutun otomatik eslestirildi`, 'info');
}

function readExcelFile() {
    const file = $('#excelFile')[0].files[0];
    if (!file) {
        showToast('Lutfen bir dosya secin.', 'warning');
        return;
    }

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
            const wb = XLSX.read(data, { type: 'array', cellDates: false, raw: true });
            const sheet = wb.Sheets[wb.SheetNames[0]];
            const json = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: true, defval: '' });
            if (json.length < 2) {
                hideLoading();
                showToast('En az 1 baslik ve 1 veri satiri olmali.', 'error');
                return;
            }

            excelHeaders = json[0].map(h => String(h || '').trim());
            excelData = json.slice(1).filter(r => r.some(c => c !== '' && c !== null && c !== undefined));

            buildMappingTable();
            buildExcelPreview();
            autoMatch();

            $('#excelColCount').text(excelHeaders.length + ' sutun');
            $('#excelRowCount').text(excelData.length + ' satir');
            $('#step2Card').removeClass('disabled-step');
            showToast(`${excelHeaders.length} sutun, ${excelData.length} satir okundu.`, 'success');
        } catch (err) {
            showToast('Dosya okunamadi: ' + err.message, 'error');
        }
        hideLoading();
    };
    reader.readAsArrayBuffer(file);
}

function analyzeData() {
    currentMapping = {};
    let hasMissing = false;
    fields.forEach(f => {
        const v = $(`#map_${f.key}`).val();
        if (v !== '') currentMapping[f.key] = parseInt(v, 10);
        if (f.required && v === '') {
            hasMissing = true;
            showToast(`${f.label} zorunlu alandir.`, 'error');
        }
    });
    if (hasMissing) return;

    const dupField = $('input[name="duplicateField"]:checked').val() || '';
    if (!dupField) {
        showToast('Mukerrer kontrol kolonu seciniz.', 'warning');
        return;
    }

    const mappedData = excelData.map(row => {
        const obj = {};
        Object.entries(currentMapping).forEach(([field, col]) => {
            obj[field] = row[col] === undefined || row[col] === null ? '' : String(row[col]).trim();
        });
        return obj;
    });

    showLoading();
    $.ajax({
        url: '',
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'analyze',
            mapping: JSON.stringify(currentMapping),
            duplicate_field: dupField,
            use_wildcard: $('#useWildcard').is(':checked') ? 1 : 0,
            data: JSON.stringify(mappedData),
            static_urun_hizmet_tip: $('#static_urun_hizmet_tip').val(),
            static_urun_hizmet_kategori_id: $('#static_urun_hizmet_kategori_id').val(),
            static_urun_hizmet_birim: $('#static_urun_hizmet_birim').val(),
            static_urun_hizmet_kdv_id: $('#static_urun_hizmet_kdv_id').val(),
            static_urun_hizmet_para_birimi_id: $('#static_urun_hizmet_para_birimi_id').val()
        },
        success: function(resp) {
            hideLoading();
            if (!resp.success) {
                showToast(resp.message || 'Analiz hatasi', 'error');
                return;
            }
            analysisData = resp;
            renderSummary(resp.summary || {});
            renderPreview(resp.data || []);
            $('#summaryCard, #previewCard').show();
            showToast('Analiz tamamlandi.', 'success');
        },
        error: function(xhr) {
            hideLoading();
            console.error(xhr.responseText);
            showToast('Sunucu hatasi olustu.', 'error');
        }
    });
}

function renderSummary(s) {
    $('#summaryBoxes').html(`
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-file-earmark"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Satir</span><span class="info-box-number">${s.total || 0}</span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-plus-circle"></i></span><div class="info-box-content"><span class="info-box-text">Yeni</span><span class="info-box-number">${s.new || 0}</span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-arrow-repeat"></i></span><div class="info-box-content"><span class="info-box-text">Mukerrer</span><span class="info-box-number">${s.duplicate || 0}</span></div></div></div>
        <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-x-circle"></i></span><div class="info-box-content"><span class="info-box-text">Hata</span><span class="info-box-number">${s.errors || 0}</span></div></div></div>
    `);
}

function renderPreview(rows) {
    let head = '<tr><th>#</th><th>Durum</th>';
    fields.forEach(f => {
        if (Object.prototype.hasOwnProperty.call(currentMapping, f.key)) head += `<th>${escapeHtml(f.label)}</th>`;
    });
    head += '</tr>';
    $('#previewHead').html(head);

    let body = '';
    rows.forEach((r, i) => {
        const md = r.mapped_data || {};
        let cls = 'table-success';
        let badge = '<span class="badge bg-success">Yeni</span>';
        if (r.status === 'duplicate') {
            cls = 'table-warning';
            badge = '<span class="badge bg-warning">Mukerrer (DB)</span>';
        } else if (r.status === 'excel_duplicate') {
            cls = 'table-warning';
            badge = '<span class="badge bg-warning">Mukerrer (Excel)</span>';
        } else if (r.status === 'error' || r.has_error) {
            cls = 'table-danger';
            badge = `<span class="badge bg-danger" title="${escapeHtml(r.error_message || 'Hata')}">${escapeHtml(r.error_message || 'Hata')}</span>`;
        }

        body += `<tr class="${cls}"><td>${i + 1}</td><td>${badge}</td>`;
        fields.forEach(f => {
            if (Object.prototype.hasOwnProperty.call(currentMapping, f.key)) {
                body += `<td>${escapeHtml(truncate(String(md[f.key] || '-'), 50))}</td>`;
            }
        });
        body += '</tr>';
    });
    $('#previewBody').html(body);
}

function importData() {
    if (!analysisData) {
        showToast('Lutfen once analiz yapin.', 'warning');
        return;
    }

    const s = analysisData.summary || {};
    const duplicateAction = $('input[name="duplicateAction"]:checked').val() || 'update';
    let count = (s.total || 0) - (s.errors || 0) - (s.excel_duplicate || 0);
    if (duplicateAction === 'skip') count -= (s.duplicate || 0);
    if (count <= 0) {
        showToast('Aktarilacak kayit bulunamadi.', 'warning');
        return;
    }

    confirmAction(`${count} kayit aktarilacak. Devam edilsin mi?`, 'Bu islem geri alinamaz.', function() {
        showLoading();
        $.ajax({
            url: '',
            method: 'POST',
            dataType: 'json',
            data: { action: 'import', duplicate_action: duplicateAction },
            success: function(resp) {
                hideLoading();
                if (resp.success) {
                    showSuccess('Import Tamamlandi', resp.message || 'Islem tamamlandi.');
                    $.post('', { action: 'clear' });
                } else {
                    showError('Import Hatasi', resp.message || 'Bilinmeyen hata');
                }
            },
            error: function(xhr) {
                hideLoading();
                console.error(xhr.responseText);
                showError('Sunucu Hatasi', 'Islem sirasinda hata olustu.');
            }
        });
    });
}

function resetAll() {
    excelHeaders = [];
    excelData = [];
    currentMapping = {};
    analysisData = null;
    $('#excelFile').val('');
    $('#mappingBody, #excelPreviewHead, #excelPreviewBody, #summaryBoxes, #previewHead, #previewBody').empty();
    $('#summaryCard, #previewCard').hide();
    $('#step2Card').addClass('disabled-step');
    $('input[name="duplicateField"]').prop('checked', false);
    $.post('', { action: 'clear' });
    showToast('Ekran sifirlandi.', 'info');
}

$(function() {
    $('.static-select').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Seciniz',
        allowClear: true
    });

    $('#readFileBtn').on('click', readExcelFile);
    $('#autoMatchBtn').on('click', autoMatch);
    $('#analyzeBtn').on('click', analyzeData);
    $('#importBtn').on('click', importData);
    $('#resetAllBtn').on('click', resetAll);
});
</script>
</body>
</html>
