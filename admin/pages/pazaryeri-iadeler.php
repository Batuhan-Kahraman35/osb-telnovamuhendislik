<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/PazaryeriFabrika.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) PageAuth::accessDenied();

$pageInfo  = $db->fetchOne("SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1", ['%' . $currentPageFile]);
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri İadeler';
$menuAdi   = $pageInfo['menu_adi'] ?? 'Pazaryeri';
$siteTitle = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC")['site_ayarlari_site_title'] ?? 'Portal';

// Kanallar tanim_Pazaryerleri'nden; yalnız iade yeteneği olanlar. Arayüz eski anahtar adlarını bekler.
$kanallar = array_values(array_map(fn($k) => $k + [
    'Kanal_Id'         => $k['kanal_id'],
    'Kanal_Adi'        => $k['kanal_adi'],
    'Entegrasyon_Adi'  => $k['Pazaryerleri_Ad'],
    'Entegrasyon_Renk' => $k['Pazaryerleri_Renk'],
], array_filter(PazaryeriFabrika::kanallar(), fn($k) => !empty($k['yetenekler']['iade']))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {

            // Durum: pazaryerinden bağımsız normalize kod (acik / onaylandi / reddedildi / iptal / diger)
            case 'stats':
                $kId = (int)($_POST['kanal_id'] ?? 0);
                $r = $db->fetchOne("
                    SELECT COUNT(*) AS toplam,
                           SUM(CASE WHEN PazaryeriIadeler_DurumKod = 'acik'       THEN 1 ELSE 0 END) AS bekleyen,
                           SUM(CASE WHEN PazaryeriIadeler_DurumKod = 'onaylandi'  THEN 1 ELSE 0 END) AS onaylanan,
                           SUM(CASE WHEN PazaryeriIadeler_DurumKod = 'reddedildi' THEN 1 ELSE 0 END) AS reddedilen
                    FROM PazaryeriIadeler WHERE Durum = 1" . ($kId ? ' AND kanal_id = ?' : ''), $kId ? [$kId] : []);
                echo json_encode(['success' => true, 'data' => [
                    'toplam'     => (int)($r['toplam'] ?? 0),
                    'bekleyen'   => (int)($r['bekleyen'] ?? 0),
                    'onaylanan'  => (int)($r['onaylanan'] ?? 0),
                    'reddedilen' => (int)($r['reddedilen'] ?? 0),
                ]]);
                break;

            case 'list':
                $where = ['i.Durum = 1']; $params = [];
                $kId = (int)($_POST['filtre_kanal'] ?? 0);
                if ($kId) { $where[] = 'i.kanal_id = ?'; $params[] = $kId; }
                $durum = trim($_POST['filtre_durum'] ?? '');
                if ($durum !== '') { $where[] = 'i.PazaryeriIadeler_DurumKod = ?'; $params[] = $durum; }
                $tarihBas = trim($_POST['filtre_tarih_bas'] ?? '');
                $tarihBit = trim($_POST['filtre_tarih_bit'] ?? '');
                if ($tarihBas) { $where[] = 'i.PazaryeriIadeler_IadeTarihi >= ?'; $params[] = $tarihBas . ' 00:00:00'; }
                if ($tarihBit) { $where[] = 'i.PazaryeriIadeler_IadeTarihi <= ?'; $params[] = $tarihBit . ' 23:59:59'; }
                $arama = trim($_POST['filtre_arama'] ?? '');
                if ($arama !== '') { $where[] = '(i.PazaryeriIadeler_SiparisNo LIKE ? OR i.PazaryeriIadeler_MusteriAdi LIKE ?)'; array_push($params, "%$arama%", "%$arama%"); }

                $rows = $db->fetchAll("
                    SELECT i.PazaryeriIadeler_id AS pi_id, i.kanal_id AS pi_kanal_id, ek.Kanal_Adi,
                           p.Pazaryerleri_Ad AS Entegrasyon_Adi, p.Pazaryerleri_Renk AS Entegrasyon_Renk,
                           i.PazaryeriIadeler_DisId      AS pi_platform_iade_id,
                           i.PazaryeriIadeler_SiparisNo  AS pi_siparis_no,
                           i.PazaryeriIadeler_DurumKod   AS pi_durum,
                           i.PazaryeriIadeler_Sebep      AS pi_iade_nedeni,
                           i.PazaryeriIadeler_MusteriAdi AS pi_musteri_adi,
                           i.PazaryeriIadeler_Tutar      AS pi_toplam_tutar,
                           i.PazaryeriIadeler_Urunler    AS pi_urunler,
                           CONVERT(VARCHAR(16), i.PazaryeriIadeler_IadeTarihi, 120) AS iade_tarihi
                    FROM PazaryeriIadeler i
                    INNER JOIN EntegrasyonKanallari ek ON ek.Kanal_Id = i.kanal_id
                    INNER JOIN tanim_Pazaryerleri p    ON p.Pazaryerleri_id = i.Pazaryerleri_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY i.PazaryeriIadeler_IadeTarihi DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'cek_iadeler':
                $kanalId  = (int)($_POST['kanal_id'] ?? 0);
                $gun      = max(1, min(90, (int)($_POST['gun'] ?? 7)));   // API tarafında 14 günlük dilimlere bölünür
                $hedefler = $kanalId ? [$kanalId] : array_column($kanallar, 'kanal_id');

                $once = (int)$db->fetchOne("SELECT COUNT(*) AS n FROM PazaryeriIadeler")['n'];
                $toplam = 0; $hatalar = [];
                foreach ($hedefler as $kid) {
                    $api = PazaryeriFabrika::olusturKanaldan((int)$kid, $user['kullanici_id']);
                    if (!$api || !$api->hazirMi()) { $hatalar[] = PazaryeriFabrika::sonHata(); continue; }
                    $r = $api->iadeleriSenkronla($gun);
                    if (!$r['basarili']) { $hatalar[] = $api->getKanalAdi() . ': ' . $r['hata']; continue; }
                    $toplam += (int)$r['adet'];
                }
                if ($hatalar && $toplam === 0) throw new Exception(implode(' | ', $hatalar));

                $yeni = (int)$db->fetchOne("SELECT COUNT(*) AS n FROM PazaryeriIadeler")['n'] - $once;
                echo json_encode(['success' => true, 'toplam' => $toplam, 'yeni' => $yeni,
                                  'guncellenen' => $toplam - $yeni, 'hata' => count($hatalar)]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Durum filtresi seçenekleri kayıtlı durumlardan (etiketler JS durumMap'te)
$durumlar = array_column($db->fetchAll("
    SELECT DISTINCT PazaryeriIadeler_DurumKod AS d FROM PazaryeriIadeler
    WHERE Durum = 1 AND PazaryeriIadeler_DurumKod IS NOT NULL ORDER BY PazaryeriIadeler_DurumKod
"), 'd');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .durum-badge { font-size:.73rem; padding:3px 8px; border-radius:20px; font-weight:500; }
        .d-WaitingForApproval { background:#fff3cd; color:#856404; }
        .d-Approved           { background:#d1e7dd; color:#0a3622; }
        .d-Rejected           { background:#f8d7da; color:#842029; }
        .d-Created            { background:#cff4fc; color:#055160; }
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
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        <div class="app-content">
            <div class="container-fluid">

                <!-- InfoBox -->
                <div class="row mb-3">
                    <?php foreach ([
                        ['stat-toplam',    'text-bg-primary', 'bi-arrow-return-left', 'Toplam İade'],
                        ['stat-bekleyen',  'text-bg-warning', 'bi-hourglass-split',   'Bekleyen'],
                        ['stat-onaylanan', 'text-bg-success', 'bi-check-circle',      'Onaylanan'],
                        ['stat-reddedilen','text-bg-danger',  'bi-x-circle',          'Reddedilen'],
                    ] as [$id, $cls, $icon, $label]): ?>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon <?= $cls ?> shadow-sm"><i class="bi <?= $icon ?>"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text"><?= $label ?></span>
                                <span class="info-box-number" id="<?= $id ?>">–</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Filtre -->
                <div class="card mb-3">
                    <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePaneli">
                        <h3 class="card-title"><i class="bi bi-funnel me-2"></i>Filtrele</h3>
                        <div class="card-tools"><button type="button" class="btn btn-tool"><i class="bi bi-chevron-down"></i></button></div>
                    </div>
                    <div class="collapse" id="filtrePaneli">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label small">Platform</label>
                                    <select class="form-select form-select-sm select2" id="filtre_kanal">
                                        <option value="">Tüm Platformlar</option>
                                        <?php foreach ($kanallar as $k): ?>
                                        <option value="<?= $k['Kanal_Id'] ?>"><?= htmlspecialchars($k['Entegrasyon_Adi'] . ' — ' . $k['Kanal_Adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Durum</label>
                                    <select class="form-select form-select-sm select2" id="filtre_durum">
                                        <option value="">Tümü</option>
                                        <?php foreach ($durumlar as $d): ?>
                                        <option value="<?= htmlspecialchars($d) ?>" data-durum="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                                        <?php endforeach; ?>
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
                                    <label class="form-label small">Ara (sipariş no, müşteri)</label>
                                    <input type="text" class="form-control form-control-sm" id="filtre_arama" placeholder="Ara…">
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-primary btn-sm" onclick="listeYukle()"><i class="bi bi-search me-1"></i>Filtrele</button>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="filtreTemizle()"><i class="bi bi-x-circle me-1"></i>Temizle</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h3 class="card-title me-auto"><i class="bi bi-table me-2"></i>İadeler</h3>
                        <div class="d-flex gap-2 align-items-center">
                            <select class="form-select form-select-sm select2" id="cek_gun" style="width:130px">
                                <option value="3">Son 3 Gün</option>
                                <option value="7" selected>Son 7 Gün</option>
                                <option value="14">Son 14 Gün</option>
                                <option value="30">Son 30 Gün</option>
                            </select>
                            <button class="btn btn-success btn-sm" id="btnCekIadeler" onclick="cekIadeler()">
                                <i class="bi bi-cloud-download me-1"></i>İadeleri Çek
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table id="iadeTable" class="table table-bordered table-striped table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Platform</th>
                                    <th>İade ID</th>
                                    <th>Sipariş No</th>
                                    <th>Durum</th>
                                    <th>Müşteri</th>
                                    <th>Neden</th>
                                    <th>Tutar</th>
                                    <th>İade Tarihi</th>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let table;

const durumMap = {
    'acik':       ['Bekliyor',   'd-WaitingForApproval'],
    'onaylandi':  ['Onaylandı',  'd-Approved'],
    'reddedildi': ['Reddedildi', 'd-Rejected'],
    'iptal':      ['İptal',      'd-Rejected'],
    'diger':      ['Diğer',      'd-Created'],
};

function durumBadge(d) {
    const [label, cls] = durumMap[d] || [d ?? '—', 'd-Created'];
    return `<span class="durum-badge ${cls}">${escHtml(label)}</span>`;
}

function statYukle() {
    $.post('', { action:'stats', kanal_id:$('#filtre_kanal').val() }, function(r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-bekleyen').text(r.data.bekleyen);
        $('#stat-onaylanan').text(r.data.onaylanan);
        $('#stat-reddedilen').text(r.data.reddedilen);
    });
}

function listeYukle() {
    const payload = {
        action:'list',
        filtre_kanal:     $('#filtre_kanal').val(),
        filtre_durum:     $('#filtre_durum').val(),
        filtre_tarih_bas: $('#filtre_tarih_bas').val(),
        filtre_tarih_bit: $('#filtre_tarih_bit').val(),
        filtre_arama:     $('#filtre_arama').val(),
    };
    if (table) table.destroy();
    $.post('', payload, function(r) {
        if (!r.success) { showToast('Veriler yüklenemedi.','danger'); return; }
        table = $('#iadeTable').DataTable({
            data: r.data, destroy:true,
            language: { url:'//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            order:[[7,'desc']], pageLength:25,
            columns: [
                { data:'Entegrasyon_Adi', render:(d,t,row) => `<span class="badge" style="background:${escHtml(row.Entegrasyon_Renk??'#666')}">${escHtml(d)}</span>` },
                { data:'pi_platform_iade_id', render:d => `<code class="small">${escHtml(d??'—')}</code>` },
                { data:'pi_siparis_no', render:d => `<code class="small">${escHtml(d??'—')}</code>` },
                { data:'pi_durum', render:d => durumBadge(d) },
                { data:'pi_musteri_adi', render:d => escHtml(d??'—') },
                { data:'pi_iade_nedeni', render:d => `<small class="text-muted">${escHtml(d??'—')}</small>` },
                { data:'pi_toplam_tutar', render:d => d ? `<strong>${parseFloat(d).toLocaleString('tr-TR',{minimumFractionDigits:2})} ₺</strong>` : '—' },
                { data:'iade_tarihi', render:d => `<small>${escHtml(d??'—')}</small>` },
            ]
        });
    });
}

function filtreTemizle() {
    $('#filtre_kanal,#filtre_durum').val('').trigger('change');
    $('#filtre_tarih_bas,#filtre_tarih_bit,#filtre_arama').val('');
    listeYukle(); statYukle();
}

function cekIadeler() {
    const btn = $('#btnCekIadeler');
    btn.prop('disabled',true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Çekiliyor…');
    $.post('', { action:'cek_iadeler', kanal_id:$('#filtre_kanal').val(), gun:$('#cek_gun').val() }, function(r) {
        btn.prop('disabled',false).html('<i class="bi bi-cloud-download me-1"></i>İadeleri Çek');
        if (!r.success) { showToast('Hata: '+r.message,'danger'); return; }
        Swal.fire({
            icon:'success', title:'Tamamlandı',
            html:`<table class="table table-sm text-start mt-2">
                <tr><td>Toplam İşlenen</td><td><strong>${r.toplam}</strong></td></tr>
                <tr><td>Yeni</td><td class="text-success"><strong>${r.yeni}</strong></td></tr>
                <tr><td>Güncellenen</td><td class="text-info"><strong>${r.guncellenen}</strong></td></tr>
                <tr><td>Hatalı</td><td class="text-danger"><strong>${r.hata}</strong></td></tr>
            </table>`,
            confirmButtonText:'Tamam',
        }).then(() => { statYukle(); listeYukle(); });
    }).fail(() => {
        btn.prop('disabled',false).html('<i class="bi bi-cloud-download me-1"></i>İadeleri Çek');
        showToast('Sunucu hatası.','danger');
    });
}

function escHtml(s) {
    return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

$(function() {
    // Durum seçeneklerine okunur etiket (değerler DB'den gelir)
    $('#filtre_durum option[data-durum]').each(function() {
        const m = durumMap[$(this).data('durum')];
        if (m) $(this).text(m[0]);
    });
    $('.select2').select2({ theme:'bootstrap-5', width:'100%' });
    statYukle(); listeYukle();
    $('#filtre_arama').on('keypress', e => { if (e.which===13) listeYukle(); });
    $('#filtre_kanal').on('change', () => statYukle());
});
</script>
</body>
</html>
