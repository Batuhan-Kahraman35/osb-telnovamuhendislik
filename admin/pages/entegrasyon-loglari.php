<?php
/**
 * Entegrasyon Logları
 * Mail, WhatsApp ve SMS gönderim geçmişi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle  = $pageInfo['sayfalar_sayfa_adi'] ?? 'Entegrasyon Logları';
$menuAdi    = $pageInfo['menu_adi'] ?? null;
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle  = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Filtre seçenekleri
$kanallar   = $db->fetchAll("
    SELECT ek.Kanal_Id, ek.Kanal_Adi, e.Entegrasyon_Kod, e.Entegrasyon_Adi
    FROM EntegrasyonKanallari ek
    INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
    ORDER BY e.Entegrasyon_Sira, ek.Kanal_Adi
");

// ── AJAX ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'stats':
                echo json_encode(['success' => true, 'data' => [
                    'toplam'   => $db->fetchOne("SELECT COUNT(*) AS s FROM EntegrasyonLoglari")['s'] ?? 0,
                    'bugun'    => $db->fetchOne("SELECT COUNT(*) AS s FROM EntegrasyonLoglari WHERE CAST(Log_Tarih AS DATE) = CAST(GETDATE() AS DATE)")['s'] ?? 0,
                    'success'  => $db->fetchOne("SELECT COUNT(*) AS s FROM EntegrasyonLoglari WHERE Log_Durum = 'SUCCESS'")['s'] ?? 0,
                    'error'    => $db->fetchOne("SELECT COUNT(*) AS s FROM EntegrasyonLoglari WHERE Log_Durum = 'ERROR'")['s'] ?? 0,
                ]]);
                break;

            case 'list':
                // Filtreler
                $whereParts  = [];
                $params      = [];

                $kanalId     = (int)($_POST['filtre_kanal'] ?? 0);
                $durum       = strtoupper(trim($_POST['filtre_durum'] ?? ''));
                $islem       = trim($_POST['filtre_islem'] ?? '');
                $tarihBas    = trim($_POST['filtre_tarih_bas'] ?? '');
                $tarihBit    = trim($_POST['filtre_tarih_bit'] ?? '');
                $arama       = trim($_POST['filtre_arama'] ?? '');

                if ($kanalId > 0) {
                    $whereParts[] = 'l.Log_Kanal_Id = ?';
                    $params[]     = $kanalId;
                }
                if ($durum) {
                    $whereParts[] = "l.Log_Durum = ?";
                    $params[]     = $durum;
                }
                if ($islem) {
                    $whereParts[] = "l.Log_Islem LIKE ?";
                    $params[]     = '%' . $islem . '%';
                }
                if ($tarihBas) {
                    $whereParts[] = "CAST(l.Log_Tarih AS DATE) >= ?";
                    $params[]     = $tarihBas;
                }
                if ($tarihBit) {
                    $whereParts[] = "CAST(l.Log_Tarih AS DATE) <= ?";
                    $params[]     = $tarihBit;
                }
                if ($arama) {
                    $whereParts[] = "(l.Log_Alici LIKE ? OR l.Log_Konu LIKE ? OR l.Log_Mesaj LIKE ?)";
                    $params[]     = '%' . $arama . '%';
                    $params[]     = '%' . $arama . '%';
                    $params[]     = '%' . $arama . '%';
                }

                $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

                $rows = $db->fetchAll("
                    SELECT TOP 500
                        l.Log_Id,
                        CONVERT(VARCHAR(16), l.Log_Tarih, 120) AS Log_Tarih,
                        e.Entegrasyon_Kod,
                        e.Entegrasyon_Adi,
                        ek.Kanal_Adi,
                        l.Log_Islem,
                        l.Log_Alici,
                        l.Log_Konu,
                        l.Log_Durum,
                        l.Log_Hata_Mesaj,
                        l.Log_Sure_Ms,
                        l.Log_Kullanici_Id
                    FROM EntegrasyonLoglari l
                    INNER JOIN EntegrasyonKanallari ek ON l.Log_Kanal_Id = ek.Kanal_Id
                    INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                    {$where}
                    ORDER BY l.Log_Tarih DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'detay':
                $logId = (int)($_POST['log_id'] ?? 0);
                $log   = $db->fetchOne("
                    SELECT l.*,
                           CONVERT(VARCHAR(19), l.Log_Tarih, 120) AS Log_Tarih_Str,
                           ek.Kanal_Adi, e.Entegrasyon_Adi, e.Entegrasyon_Kod
                    FROM EntegrasyonLoglari l
                    INNER JOIN EntegrasyonKanallari ek ON l.Log_Kanal_Id = ek.Kanal_Id
                    INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                    WHERE l.Log_Id = ?
                ", [$logId]);
                echo json_encode(['success' => (bool)$log, 'data' => $log]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
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

    <style>
        .badge-SUCCESS { background-color:#198754; }
        .badge-ERROR   { background-color:#dc3545; }
        .badge-WARNING { background-color:#fd7e14; }
        .sure-pill { font-size:.75rem; }
        pre.log-json { background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;
                       padding:10px; font-size:.75rem; max-height:300px; overflow:auto; }
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
                            <?php if ($menuAdi): ?>
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item">
                                <a href="/admin/entegrasyon-yonetimi">Entegrasyon Yönetimi</a>
                            </li>
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
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-journal-text"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Log</span>
                                <span class="info-box-number" id="stat-toplam">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-calendar-day"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün</span>
                                <span class="info-box-number" id="stat-bugun">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Başarılı</span>
                                <span class="info-box-number" id="stat-success">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-x-octagon"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Hatalı</span>
                                <span class="info-box-number" id="stat-error">–</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
                <div class="card mb-3">
                    <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePaneli">
                        <h3 class="card-title"><i class="bi bi-funnel me-2"></i>Filtrele</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filtrePaneli">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="filtrePaneli">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label small">Kanal</label>
                                    <select class="form-select form-select-sm" id="filtre_kanal">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kanallar as $k): ?>
                                        <option value="<?= $k['Kanal_Id'] ?>">
                                            <?= htmlspecialchars($k['Entegrasyon_Adi'] . ' — ' . $k['Kanal_Adi']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Durum</label>
                                    <select class="form-select form-select-sm" id="filtre_durum">
                                        <option value="">Tümü</option>
                                        <option value="SUCCESS">Başarılı</option>
                                        <option value="ERROR">Hatalı</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">İşlem</label>
                                    <select class="form-select form-select-sm" id="filtre_islem">
                                        <option value="">Tümü</option>
                                        <option value="MAIL_GONDER">Mail Gönder</option>
                                        <option value="MAIL_TEST">Mail Test</option>
                                        <option value="WA_GONDER">WA Gönder</option>
                                        <option value="WA_DURUM">WA Durum</option>
                                        <option value="SMS_GONDER">SMS Gönder</option>
                                        <option value="SMS_TEST">SMS Test</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Tarih Başlangıç</label>
                                    <input type="date" class="form-control form-control-sm" id="filtre_tarih_bas">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Tarih Bitiş</label>
                                    <input type="date" class="form-control form-control-sm" id="filtre_tarih_bit">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Arama (alıcı, konu, mesaj)</label>
                                    <input type="text" class="form-control form-control-sm" id="filtre_arama" placeholder="Ara…">
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-primary btn-sm" onclick="logListeYukle()">
                                        <i class="bi bi-search me-1"></i>Filtrele
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="filtreTemizle()">
                                        <i class="bi bi-x-circle me-1"></i>Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Log Tablosu -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-table me-2"></i>Log Kayıtları <small class="text-muted">(son 500)</small></h3>
                    </div>
                    <div class="card-body p-0">
                        <table id="logTable" class="table table-bordered table-striped table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tarih</th>
                                    <th>Entegrasyon</th>
                                    <th>İşlem</th>
                                    <th>Alıcı</th>
                                    <th>Konu/Mesaj</th>
                                    <th>Durum</th>
                                    <th>Süre</th>
                                    <th></th>
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

<!-- MODAL: Log Detay -->
<div class="modal fade" id="detayModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Log Detayı — <span id="detay-id"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detayIcerik">
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-arrow-repeat spin fs-3"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
let table;
const detayModal = new bootstrap.Modal(document.getElementById('detayModal'));

const islemRenkMap = {
    'MAIL_GONDER':'text-bg-danger',
    'MAIL_TEST':'text-bg-secondary',
    'WA_GONDER':'text-bg-success',
    'WA_DURUM':'text-bg-secondary',
    'SMS_GONDER':'text-bg-warning',
    'SMS_TEST':'text-bg-secondary',
};

function statYukle() {
    $.post('', { action:'stats' }, function(r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-bugun').text(r.data.bugun);
        $('#stat-success').text(r.data.success);
        $('#stat-error').text(r.data.error);
    });
}

function logListeYukle() {
    const payload = {
        action:           'list',
        filtre_kanal:     $('#filtre_kanal').val(),
        filtre_durum:     $('#filtre_durum').val(),
        filtre_islem:     $('#filtre_islem').val(),
        filtre_tarih_bas: $('#filtre_tarih_bas').val(),
        filtre_tarih_bit: $('#filtre_tarih_bit').val(),
        filtre_arama:     $('#filtre_arama').val(),
    };

    if (table) { table.destroy(); }

    $.post('', payload, function(r) {
        if (!r.success) { showToast('Yüklenemedi.', 'danger'); return; }

        table = $('#logTable').DataTable({
            data: r.data,
            destroy: true,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            order: [[0, 'desc']],
            pageLength: 25,
            columns: [
                { data:'Log_Id', width:'60px' },
                { data:'Log_Tarih' },
                { data:'Entegrasyon_Adi', render: (d,t,row) =>
                    `<span class="badge text-bg-secondary">${escHtml(row.Entegrasyon_Kod)}</span> ${escHtml(d)}`
                },
                { data:'Log_Islem', render: d => {
                    const cls = islemRenkMap[d] || 'text-bg-secondary';
                    return `<span class="badge ${cls}">${escHtml(d)}</span>`;
                }},
                { data:'Log_Alici', render: d => `<span class="small">${escHtml(d ?? '—')}</span>` },
                { data:'Log_Konu', render: (d, t, row) => {
                    const v = d || (row.Log_Mesaj ? row.Log_Mesaj.substring(0,60) : '—');
                    return `<span class="small text-truncate d-block" style="max-width:200px" title="${escHtml(v)}">${escHtml(v)}</span>`;
                }},
                { data:'Log_Durum', render: d => {
                    const cls = d === 'SUCCESS' ? 'badge-SUCCESS' : d === 'ERROR' ? 'badge-ERROR' : 'badge-WARNING';
                    return `<span class="badge ${cls}">${escHtml(d)}</span>`;
                }},
                { data:'Log_Sure_Ms', render: d => d ? `<span class="badge text-bg-light text-dark sure-pill">${d}ms</span>` : '—' },
                { data:'Log_Id', orderable:false, render: d =>
                    `<button class="btn btn-xs btn-outline-info btn-sm" onclick="logDetay(${d})">
                        <i class="bi bi-eye"></i>
                    </button>`
                },
            ]
        });
    });
}

function filtreTemizle() {
    $('#filtre_kanal, #filtre_durum, #filtre_islem').val('');
    $('#filtre_tarih_bas, #filtre_tarih_bit, #filtre_arama').val('');
    logListeYukle();
}

function logDetay(id) {
    $('#detay-id').text('#' + id);
    $('#detayIcerik').html('<div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat spin fs-3"></i></div>');
    detayModal.show();

    $.post('', { action:'detay', log_id: id }, function(r) {
        if (!r.success || !r.data) {
            $('#detayIcerik').html('<div class="alert alert-danger">Kayıt bulunamadı.</div>');
            return;
        }
        const d = r.data;
        const durumCls = d.Log_Durum === 'SUCCESS' ? 'success' : 'danger';

        let istekJson = '—', yanitJson = '—';
        try { istekJson = d.Log_Istek ? JSON.stringify(JSON.parse(d.Log_Istek), null, 2) : '—'; } catch(e) { istekJson = d.Log_Istek; }
        try { yanitJson = d.Log_Yanit ? JSON.stringify(JSON.parse(d.Log_Yanit), null, 2) : '—'; } catch(e) { yanitJson = d.Log_Yanit; }

        $('#detayIcerik').html(`
            <div class="row g-3">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr><td class="text-muted w-35">Log ID</td><td><strong>#${d.Log_Id}</strong></td></tr>
                        <tr><td class="text-muted">Tarih</td><td>${escHtml(d.Log_Tarih_Str ?? '')}</td></tr>
                        <tr><td class="text-muted">Entegrasyon</td><td>${escHtml(d.Entegrasyon_Adi ?? '')} (${escHtml(d.Entegrasyon_Kod ?? '')})</td></tr>
                        <tr><td class="text-muted">Kanal</td><td>${escHtml(d.Kanal_Adi ?? '')}</td></tr>
                        <tr><td class="text-muted">İşlem</td><td><span class="badge text-bg-secondary">${escHtml(d.Log_Islem ?? '')}</span></td></tr>
                        <tr><td class="text-muted">Durum</td><td><span class="badge badge-${d.Log_Durum}">${escHtml(d.Log_Durum ?? '')}</span></td></tr>
                        <tr><td class="text-muted">Süre</td><td>${d.Log_Sure_Ms ? d.Log_Sure_Ms + ' ms' : '—'}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr><td class="text-muted w-35">Alıcı</td><td>${escHtml(d.Log_Alici ?? '—')}</td></tr>
                        <tr><td class="text-muted">Konu</td><td>${escHtml(d.Log_Konu ?? '—')}</td></tr>
                        <tr><td class="text-muted">Mesaj</td><td class="small">${escHtml((d.Log_Mesaj ?? '—').substring(0, 300))}</td></tr>
                        <tr><td class="text-muted">IP</td><td>${escHtml(d.Log_IP ?? '—')}</td></tr>
                        ${d.Log_Hata_Mesaj ? `<tr><td class="text-muted">Hata</td><td class="text-danger small">${escHtml(d.Log_Hata_Mesaj)}</td></tr>` : ''}
                    </table>
                </div>
                <div class="col-md-6">
                    <strong class="small">İstek (Payload)</strong>
                    <pre class="log-json">${escHtml(istekJson)}</pre>
                </div>
                <div class="col-md-6">
                    <strong class="small">Yanıt (Response)</strong>
                    <pre class="log-json">${escHtml(yanitJson)}</pre>
                </div>
            </div>
        `);
    });
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

$(function() {
    statYukle();
    logListeYukle();
    // Enter tuşuyla filtrele
    $('#filtre_arama').on('keypress', function(e) {
        if (e.which === 13) logListeYukle();
    });
});
</script>
</body>
</html>
