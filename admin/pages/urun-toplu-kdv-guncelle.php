<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = 'urun-yonetimi.php'; // Yetkiyi urun-yonetimi üzerinden kontrol ediyoruz
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$permissions['has_access'] || !$permissions['can_edit']) {
    PageAuth::accessDenied('Bu sayfada güncelleme yetkiniz bulunmamaktadır.');
}

$pageTitle = 'Ürün/Hizmet Toplu KDV Güncelleme';
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

$kdvler = $db->fetchAll("SELECT kdv_id, kdv_adi, kdv_oran FROM UrunHizmet_KDVTanimlari WHERE kdv_durum = 1 ORDER BY kdv_sira_no, kdv_oran DESC");

function trLower($value) {
    $value = str_replace(['I', 'İ'], ['ı', 'i'], (string)$value);
    return mb_strtolower(trim($value), 'UTF-8');
}

function resolveKdvId($value, array $kdvler) {
    if ($value === null || $value === '') return null;
    $value = trim((string)$value);
    
    // Yüzde işareti varsa kaldır
    $value = str_replace('%', '', $value);
    $value = trim($value);
    
    // Tam eşleşme (kdv_oran)
    if (is_numeric($value)) {
        $oran = (float)$value;
        foreach ($kdvler as $k) {
            if ((float)$k['kdv_oran'] === $oran) return (int)$k['kdv_id'];
        }
    }
    
    // İsim eşleşmesi (kdv_adi)
    $needle = trLower($value);
    foreach ($kdvler as $k) {
        if (trLower((string)$k['kdv_adi']) === $needle) {
            return (int)$k['kdv_id'];
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'clear') {
            unset($_SESSION['kdv_guncelle_data'], $_SESSION['kdv_guncelle_settings']);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'analyze') {
            $rawData = $_POST['data'] ?? '';
            $matchColumn = $_POST['match_column'] ?? ''; // 'urun_hizmet_kodu' or 'urun_hizmet_adi'
            $kdvColumn = $_POST['kdv_column'] ?? '';
            $useWildcard = (int)($_POST['use_wildcard'] ?? 0);

            if ($rawData === '' || $matchColumn === '' || $kdvColumn === '') {
                echo json_encode(['success' => false, 'message' => 'Eksik veri veya eşleştirme.']);
                exit;
            }

            $data = json_decode($rawData, true);
            if (!is_array($data) || count($data) === 0) {
                echo json_encode(['success' => false, 'message' => 'Dosyada geçerli veri bulunamadı.']);
                exit;
            }

            $validatedData = [];

            foreach ($data as $i => $row) {
                $rowNum = $i + 2;
                $matchVal = trim((string)($row[$matchColumn] ?? ''));
                $kdvVal = trim((string)($row[$kdvColumn] ?? ''));

                $item = [
                    'row_num' => $rowNum,
                    'match_val' => $matchVal,
                    'kdv_val' => $kdvVal,
                    'status' => 'ready',
                    'has_error' => false,
                    'error_message' => '',
                    'target_id' => null,
                    'target_name' => '',
                    'new_kdv_id' => null,
                    'new_kdv_oran' => ''
                ];

                if ($matchVal === '') {
                    $item['has_error'] = true;
                    $item['status'] = 'error';
                    $item['error_message'] = 'Karşılaştırma kolonu boş.';
                }

                if (!$item['has_error']) {
                    $kdvId = resolveKdvId($kdvVal, $kdvler);
                    if ($kdvId === null) {
                        $item['has_error'] = true;
                        $item['status'] = 'error';
                        $item['error_message'] = 'KDV sistemde bulunamadı.';
                    } else {
                        $item['new_kdv_id'] = $kdvId;
                        foreach ($kdvler as $k) {
                            if ($k['kdv_id'] == $kdvId) {
                                $item['new_kdv_oran'] = $k['kdv_oran'];
                                break;
                            }
                        }
                    }
                }

                if (!$item['has_error']) {
                    $existing = null;
                    if ($useWildcard && strpos($matchVal, '*') !== false) {
                        $likeValue = str_replace('*', '%', $matchVal);
                        $existing = $db->fetchOne("SELECT TOP 1 urun_hizmet_id, urun_hizmet_adi FROM Urun_Hizmet WHERE $matchColumn LIKE ?", [$likeValue]);
                    } else {
                        $existing = $db->fetchOne("SELECT TOP 1 urun_hizmet_id, urun_hizmet_adi FROM Urun_Hizmet WHERE $matchColumn = ?", [$matchVal]);
                    }

                    if ($existing) {
                        $item['target_id'] = (int)$existing['urun_hizmet_id'];
                        $item['target_name'] = $existing['urun_hizmet_adi'];
                    } else {
                        $item['has_error'] = true;
                        $item['status'] = 'error';
                        $item['error_message'] = 'Ürün bulunamadı.';
                    }
                }

                $validatedData[] = $item;
            }

            $_SESSION['kdv_guncelle_data'] = $validatedData;
            $_SESSION['kdv_guncelle_settings'] = [
                'match_column' => $matchColumn,
                'kdv_column' => $kdvColumn
            ];

            $summary = [
                'total' => count($validatedData),
                'ready' => count(array_filter($validatedData, fn($r) => $r['status'] === 'ready')),
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
            $importData = $_SESSION['kdv_guncelle_data'] ?? [];
            if (empty($importData)) {
                echo json_encode(['success' => false, 'message' => 'Güncelleme verisi bulunamadı.']);
                exit;
            }

            $updated = 0;
            $skipped = 0;

            foreach ($importData as $row) {
                if (!empty($row['has_error']) || empty($row['target_id']) || empty($row['new_kdv_id'])) {
                    $skipped++;
                    continue;
                }

                $db->execute("UPDATE Urun_Hizmet SET urun_hizmet_kdv_id = ?, urun_hizmet_guncelleme_tarihi = GETDATE(), urun_hizmet_guncelleyen_kullanici_id = ? WHERE urun_hizmet_id = ?", [
                    $row['new_kdv_id'],
                    $user['kullanici_id'],
                    $row['target_id']
                ]);
                $updated++;
            }

            unset($_SESSION['kdv_guncelle_data'], $_SESSION['kdv_guncelle_settings']);
            
            $msg = "İşlem tamamlandı. $updated ürünün KDV'si güncellendi";
            if ($skipped > 0) $msg .= ", $skipped satır atlandı";
            $msg .= '.';

            echo json_encode(['success' => true, 'message' => $msg, 'updated' => $updated, 'skipped' => $skipped]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
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
                        <li class="breadcrumb-item"><a href="urun-yonetimi.php">Ürün Yönetimi</a></li>
                        <li class="breadcrumb-item active">Toplu KDV Güncelle</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                Bu ekran, Excel dosyanızdaki KDV oranlarını okuyarak, seçtiğiniz baz kolona (Ürün Kodu veya Adı) göre sistemdeki ürünlerin KDV'sini günceller.<br>
                <strong>Not:</strong> KDV sütunu eşleşmesinde, <code>UrunHizmet_KDVTanimlari</code> tablosundaki <strong>kdv_oran</strong> değeri veya <strong>kdv_adi</strong> kullanılır. (Örnek Excel verisi: 18, 20 veya %20)
            </div>

            <div class="card card-primary card-outline mb-3 step-card" id="step1Card">
                <div class="card-header">
                    <h3 class="card-title"><span class="step-number">1</span>Dosya Seçimi</h3>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Excel / CSV Dosyası</label>
                            <input type="file" class="form-control" id="excelFile" accept=".csv,.xlsx,.xls">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="button" class="btn btn-primary" id="readFileBtn"><i class="bi bi-file-earmark-spreadsheet"></i> Dosyayı Oku</button>
                    </div>
                </div>
            </div>

            <div class="card card-warning card-outline mb-3 step-card disabled-step" id="step2Card">
                <div class="card-header">
                    <h3 class="card-title"><span class="step-number">2</span>Sütun Eşleştirme</h3>
                    <div class="card-tools">
                        <span class="badge bg-primary" id="excelRowCount">0 satır</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Sistem Alanı</th>
                                            <th>Excel Sütunu</th>
                                            <th>Önizleme</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mappingBody">
                                        <tr>
                                            <td>1</td>
                                            <td>
                                                <select class="form-select form-select-sm fw-bold border-0 bg-light" id="dbMatchColumn">
                                                    <option value="urun_hizmet_kodu">Eşleştirme Kolonu: Ürün Kodu</option>
                                                    <option value="urun_hizmet_adi">Eşleştirme Kolonu: Ürün Adı</option>
                                                </select>
                                            </td>
                                            <td><select class="form-select form-select-sm excel-col-select" id="excelMatchColumn"></select></td>
                                            <td id="prev_match" class="text-muted align-middle">-</td>
                                        </tr>
                                        <tr>
                                            <td>2</td>
                                            <td class="align-middle"><strong>Yeni KDV Değeri</strong> <span class="badge bg-danger ms-1">Zorunlu</span></td>
                                            <td><select class="form-select form-select-sm excel-col-select" id="excelKdvColumn"></select></td>
                                            <td id="prev_kdv" class="text-muted align-middle">-</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="card bg-light h-100">
                                <div class="card-header py-2"><h6 class="card-title mb-0"><i class="bi bi-eye"></i> Excel Önizleme (İlk 5 Satır)</h6></div>
                                <div class="card-body p-0" style="max-height:200px; overflow:auto;">
                                    <table class="table table-sm table-bordered table-striped mb-0">
                                        <thead class="table-dark sticky-top" id="excelPreviewHead"></thead>
                                        <tbody id="excelPreviewBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="useWildcard">
                                <label class="form-check-label fw-bold" for="useWildcard">
                                    Joker Karakter (*) Desteği
                                    <small class="text-muted d-block">Örn: Excel'de '630122*' değeri, sistemdeki '630122-ABC' ile eşleşir.</small>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="button" class="btn btn-warning" id="analyzeBtn"><i class="bi bi-search"></i> Analiz Et</button>
                    </div>
                </div>
            </div>

            <div class="card card-info card-outline mb-3" id="summaryCard" style="display:none;">
                <div class="card-header"><h3 class="card-title"><span class="step-number">3</span>Analiz Sonucu ve Güncelleme</h3></div>
                <div class="card-body">
                    <div class="row text-center g-3 mb-4" id="summaryBoxes"></div>
                    
                    <div class="table-responsive" style="max-height:400px; overflow:auto;">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th>#</th>
                                    <th>Durum</th>
                                    <th>Eşleşme Değeri (Excel)</th>
                                    <th>Bulunan Ürün (Sistem)</th>
                                    <th>Okunan KDV (Excel)</th>
                                    <th>Yeni KDV (Sistem)</th>
                                </tr>
                            </thead>
                            <tbody id="previewBody"></tbody>
                        </table>
                    </div>

                    <div class="mt-4 text-center">
                        <button type="button" class="btn btn-success btn-lg" id="importBtn"><i class="bi bi-database-add"></i> KDV'leri Güncelle</button>
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
let excelHeaders = [];
let excelData = [];
let analysisData = null;

function escapeHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

function truncate(v, len) {
    const t = String(v || '');
    return t.length > len ? t.substring(0, len) + '...' : t;
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
        body += `<tr><td colspan="${excelHeaders.length + 1}" class="text-center text-muted">... toplam ${excelData.length} satır (ilk 5 gösteriliyor)</td></tr>`;
    }
    $('#excelPreviewBody').html(body);
}

function readExcelFile() {
    const file = $('#excelFile')[0].files[0];
    if (!file) {
        showToast('Lütfen bir dosya seçin.', 'warning');
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
                showToast('En az 1 başlık ve 1 veri satırı olmalı.', 'error');
                return;
            }

            excelHeaders = json[0].map(h => String(h || '').trim());
            excelData = json.slice(1).filter(r => r.some(c => c !== '' && c !== null && c !== undefined));

            let opts = '<option value="">-- Seçiniz --</option>';
            excelHeaders.forEach((h, i) => {
                opts += `<option value="${i}">${escapeHtml(h)}</option>`;
            });

            $('.excel-col-select').html(opts).select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Sütun Seçin', allowClear: true });
            
            buildExcelPreview();

            // Sütun seçildiğinde önizleme alanını güncelle
            $('#excelMatchColumn').on('change', function() {
                const val = $(this).val();
                if (val !== '') {
                    const sample = excelData[0] && excelData[0][val] !== undefined ? excelData[0][val] : '-';
                    $('#prev_match').html('<span class="text-success">' + escapeHtml(truncate(sample, 40)) + '</span>');
                } else {
                    $('#prev_match').text('-');
                }
            });

            $('#excelKdvColumn').on('change', function() {
                const val = $(this).val();
                if (val !== '') {
                    const sample = excelData[0] && excelData[0][val] !== undefined ? excelData[0][val] : '-';
                    $('#prev_kdv').html('<span class="text-success">' + escapeHtml(truncate(sample, 40)) + '</span>');
                } else {
                    $('#prev_kdv').text('-');
                }
            });

            $('#excelRowCount').text(excelData.length + ' satır');
            $('#step2Card').removeClass('disabled-step');
            showToast(`${excelHeaders.length} sütun, ${excelData.length} satır okundu.`, 'success');
        } catch (err) {
            showToast('Dosya okunamadı: ' + err.message, 'error');
        }
        hideLoading();
    };
    reader.readAsArrayBuffer(file);
}

function analyzeData() {
    const dbMatchColumn = $('#dbMatchColumn').val();
    const excelMatchIdx = $('#excelMatchColumn').val();
    const excelKdvIdx = $('#excelKdvColumn').val();

    if (excelMatchIdx === '' || excelKdvIdx === '') {
        showToast('Lütfen gerekli sütun eşleştirmelerini yapın.', 'warning');
        return;
    }

    const mappedData = excelData.map(row => {
        return {
            match: row[excelMatchIdx] !== undefined ? String(row[excelMatchIdx]).trim() : '',
            kdv: row[excelKdvIdx] !== undefined ? String(row[excelKdvIdx]).trim() : ''
        };
    });

    showLoading();
    $.ajax({
        url: '',
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'analyze',
            match_column: dbMatchColumn,
            kdv_column: 'kdv',
            use_wildcard: $('#useWildcard').is(':checked') ? 1 : 0,
            data: JSON.stringify(mappedData.map(r => ({ [dbMatchColumn]: r.match, kdv: r.kdv })))
        },
        success: function(resp) {
            hideLoading();
            if (!resp.success) {
                showToast(resp.message || 'Analiz hatası', 'error');
                return;
            }
            analysisData = resp;
            renderSummary(resp.summary || {});
            renderPreview(resp.data || []);
            $('#summaryCard').show();
            showToast('Analiz tamamlandı.', 'success');
        },
        error: function(xhr) {
            hideLoading();
            showToast('Sunucu hatası oluştu.', 'error');
        }
    });
}

function renderSummary(s) {
    $('#summaryBoxes').html(`
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-file-earmark"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Satır</span><span class="info-box-number">${s.total || 0}</span></div></div></div>
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-check-circle"></i></span><div class="info-box-content"><span class="info-box-text">Güncellenmeye Hazır</span><span class="info-box-number">${s.ready || 0}</span></div></div></div>
        <div class="col-md-4"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-x-circle"></i></span><div class="info-box-content"><span class="info-box-text">Hata</span><span class="info-box-number">${s.errors || 0}</span></div></div></div>
    `);
}

function renderPreview(rows) {
    let body = '';
    rows.forEach((r, i) => {
        let cls = 'table-success';
        let badge = '<span class="badge bg-success">Hazır</span>';
        if (r.has_error) {
            cls = 'table-danger';
            badge = `<span class="badge bg-danger" title="${escapeHtml(r.error_message)}">${escapeHtml(r.error_message)}</span>`;
        }

        body += `<tr class="${cls}">
            <td>${r.row_num}</td>
            <td>${badge}</td>
            <td>${escapeHtml(r.match_val)}</td>
            <td>${escapeHtml(r.target_name || '-')}</td>
            <td>${escapeHtml(r.kdv_val)}</td>
            <td>${r.new_kdv_oran !== '' ? '%' + escapeHtml(r.new_kdv_oran) : '-'}</td>
        </tr>`;
    });
    $('#previewBody').html(body);
}

function importData() {
    if (!analysisData || !analysisData.summary || analysisData.summary.ready === 0) {
        showToast('Güncellenecek kayıt yok.', 'warning');
        return;
    }

    confirmAction(`${analysisData.summary.ready} ürünün KDV'si güncellenecek. Devam edilsin mi?`, 'Bu işlem geri alınamaz.', function() {
        showLoading();
        $.ajax({
            url: '',
            method: 'POST',
            dataType: 'json',
            data: { action: 'import' },
            success: function(resp) {
                hideLoading();
                if (resp.success) {
                    showSuccess('Güncelleme Tamamlandı', resp.message);
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showError('Hata', resp.message);
                }
            },
            error: function() {
                hideLoading();
                showError('Sunucu Hatası', 'İşlem sırasında hata oluştu.');
            }
        });
    });
}

$(function() {
    $('#readFileBtn').on('click', readExcelFile);
    $('#analyzeBtn').on('click', analyzeData);
    $('#importBtn').on('click', importData);
});
</script>
</body>
</html>
