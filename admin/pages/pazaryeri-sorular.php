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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri Müşteri Soruları';
$menuAdi   = $pageInfo['menu_adi'] ?? 'Pazaryeri';
$siteTitle = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC")['site_ayarlari_site_title'] ?? 'Portal';

// Kanallar tanim_Pazaryerleri'nden; yalnız soru-cevap yeteneği olanlar. Arayüz eski anahtar adlarını bekler.
$kanallar = array_values(array_map(fn($k) => $k + [
    'Kanal_Id'         => $k['kanal_id'],
    'Kanal_Adi'        => $k['kanal_adi'],
    'Entegrasyon_Adi'  => $k['Pazaryerleri_Ad'],
    'Entegrasyon_Renk' => $k['Pazaryerleri_Renk'],
], array_filter(PazaryeriFabrika::kanallar(), fn($k) => !empty($k['yetenekler']['soruCevap']))));

/*
 * Durum: DB'de pazaryerinden bağımsız normalize kod (acik / cevaplandi / kapali) tutulur.
 * Arayüz (JS) WAITING_FOR_ANSWER / ANSWERED / REJECTED değerlerini beklediği için SQL'de çevrilir.
 */
const SORU_DURUM_SQL = "CASE q.PazaryeriSorulari_DurumKod WHEN 'cevaplandi' THEN 'ANSWERED'
                                                         WHEN 'kapali'     THEN 'REJECTED'
                                                         ELSE 'WAITING_FOR_ANSWER' END";
const SORU_DURUM_KOD = ['WAITING_FOR_ANSWER' => 'acik', 'ANSWERED' => 'cevaplandi', 'REJECTED' => 'kapali'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'stats':
                $kId = (int)($_POST['kanal_id'] ?? 0);
                $r = $db->fetchOne("
                    SELECT COUNT(*) AS toplam,
                           SUM(CASE WHEN PazaryeriSorulari_DurumKod = 'acik'       THEN 1 ELSE 0 END) AS bekleyen,
                           SUM(CASE WHEN PazaryeriSorulari_DurumKod = 'cevaplandi' THEN 1 ELSE 0 END) AS cevaplanan
                    FROM PazaryeriSorulari WHERE Durum = 1" . ($kId ? ' AND kanal_id = ?' : ''), $kId ? [$kId] : []);
                echo json_encode(['success' => true, 'data' => [
                    'toplam'     => (int)($r['toplam'] ?? 0),
                    'bekleyen'   => (int)($r['bekleyen'] ?? 0),
                    'cevaplanan' => (int)($r['cevaplanan'] ?? 0),
                ]]);
                break;

            case 'list':
                $where = ['q.Durum = 1']; $params = [];
                $kId = (int)($_POST['filtre_kanal'] ?? 0);
                if ($kId) { $where[] = 'q.kanal_id = ?'; $params[] = $kId; }
                $durum = SORU_DURUM_KOD[trim($_POST['filtre_durum'] ?? '')] ?? '';
                if ($durum !== '') { $where[] = 'q.PazaryeriSorulari_DurumKod = ?'; $params[] = $durum; }
                $tarihBas = trim($_POST['filtre_tarih_bas'] ?? '');
                $tarihBit = trim($_POST['filtre_tarih_bit'] ?? '');
                if ($tarihBas) { $where[] = 'q.PazaryeriSorulari_SoruTarihi >= ?'; $params[] = $tarihBas . ' 00:00:00'; }
                if ($tarihBit) { $where[] = 'q.PazaryeriSorulari_SoruTarihi <= ?'; $params[] = $tarihBit . ' 23:59:59'; }
                $arama = trim($_POST['filtre_arama'] ?? '');
                if ($arama !== '') { $where[] = '(q.PazaryeriSorulari_UrunAdi LIKE ? OR q.PazaryeriSorulari_MusteriAdi LIKE ?)'; array_push($params, "%$arama%", "%$arama%"); }

                $rows = $db->fetchAll("
                    SELECT q.PazaryeriSorulari_id AS ps_id, q.kanal_id AS ps_kanal_id, ek.Kanal_Adi,
                           p.Pazaryerleri_Ad AS Entegrasyon_Adi, p.Pazaryerleri_Renk AS Entegrasyon_Renk,
                           q.PazaryeriSorulari_DisId      AS ps_platform_soru_id,
                           q.PazaryeriSorulari_UrunAdi    AS ps_urun_adi,
                           q.PazaryeriSorulari_MusteriAdi AS ps_musteri_adi,
                           " . SORU_DURUM_SQL . "          AS ps_durum,
                           q.PazaryeriSorulari_Soru       AS ps_soru_metni,
                           q.PazaryeriSorulari_Cevap      AS ps_cevap_metni,
                           CONVERT(VARCHAR(16), q.PazaryeriSorulari_SoruTarihi, 120)  AS soru_tarihi,
                           CONVERT(VARCHAR(16), q.PazaryeriSorulari_CevapTarihi, 120) AS cevap_tarihi
                    FROM PazaryeriSorulari q
                    INNER JOIN EntegrasyonKanallari ek ON ek.Kanal_Id = q.kanal_id
                    INNER JOIN tanim_Pazaryerleri p    ON p.Pazaryerleri_id = q.Pazaryerleri_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY q.PazaryeriSorulari_SoruTarihi DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            // Sorular 14 günlük dilimlerle son 30 gün çekilir (geniş aralık sessizce eksik döner — tuzak 21)
            case 'cek_sorular':
                $kanalId  = (int)($_POST['kanal_id'] ?? 0);
                $hedefler = $kanalId ? [$kanalId] : array_column($kanallar, 'kanal_id');

                $once = (int)$db->fetchOne("SELECT COUNT(*) AS n FROM PazaryeriSorulari")['n'];
                $toplam = 0; $hatalar = [];
                foreach ($hedefler as $kid) {
                    $api = PazaryeriFabrika::olusturKanaldan((int)$kid, $user['kullanici_id']);
                    if (!$api || !$api->hazirMi()) { $hatalar[] = PazaryeriFabrika::sonHata(); continue; }
                    $r = $api->sorulariSenkronla(30);
                    if (!$r['basarili']) { $hatalar[] = $api->getKanalAdi() . ': ' . $r['hata']; continue; }
                    $toplam += (int)$r['adet'];
                }
                if ($hatalar && $toplam === 0) throw new Exception(implode(' | ', $hatalar));

                $yeni = (int)$db->fetchOne("SELECT COUNT(*) AS n FROM PazaryeriSorulari")['n'] - $once;
                echo json_encode(['success' => true, 'toplam' => $toplam, 'yeni' => $yeni,
                                  'guncellenen' => $toplam - $yeni, 'hata' => count($hatalar)]);
                break;

            case 'cevap_gonder':
                $psId  = (int)($_POST['ps_id'] ?? 0);
                $cevap = trim($_POST['cevap_metni'] ?? '');
                if (!$psId || $cevap === '') throw new Exception('Eksik bilgi gönderildi.');

                $soru = $db->fetchOne("SELECT kanal_id, PazaryeriSorulari_DisId AS dis_id FROM PazaryeriSorulari WHERE PazaryeriSorulari_id = ?", [$psId]);
                if (!$soru) throw new Exception('Soru bulunamadı.');

                $api = PazaryeriFabrika::olusturKanaldan((int)$soru['kanal_id'], $user['kullanici_id']);
                if (!$api || !$api->hazirMi()) throw new Exception('Pazaryeri bağlantısı kurulamadı: ' . ($api ? $api->getHata() : PazaryeriFabrika::sonHata()));

                // Başarılıysa yerel kayıt (cevap, tarih, cevaplayan) ortak katmanda güncellenir
                $s = $api->soruCevapla($soru['dis_id'], $cevap);
                if (!$s['basarili']) throw new Exception($api->getPazaryeriAdi() . ' API Hatası: ' . $s['hata']);

                echo json_encode(['success' => true, 'message' => 'Cevap başarıyla gönderildi.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Durum filtresi — kayıtlı normalize kodlardan (arayüz değerine çevrilerek)
$durumlar = array_values(array_filter(array_map(
    fn($r) => array_search($r['d'], SORU_DURUM_KOD, true) ?: null,
    $db->fetchAll("SELECT DISTINCT PazaryeriSorulari_DurumKod AS d FROM PazaryeriSorulari WHERE Durum = 1")
)));
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
        .d-WAITING_FOR_ANSWER { background:#fff3cd; color:#856404; }
        .d-ANSWERED           { background:#d1e7dd; color:#0a3622; }
        .d-REJECTED           { background:#f8d7da; color:#842029; }
        .soru-metni { max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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
                        ['stat-toplam',    'text-bg-primary', 'bi-question-circle', 'Toplam Soru'],
                        ['stat-bekleyen',  'text-bg-warning', 'bi-hourglass-split', 'Bekleyen'],
                        ['stat-cevaplanan','text-bg-success', 'bi-check-circle',    'Cevaplanan'],
                    ] as [$id, $cls, $icon, $label]): ?>
                    <div class="col-12 col-sm-4">
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
                                    <label class="form-label small">Soru Tarihi (Başlangıç)</label>
                                    <input type="date" class="form-control form-control-sm" id="filtre_tarih_bas">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Soru Tarihi (Bitiş)</label>
                                    <input type="date" class="form-control form-control-sm" id="filtre_tarih_bit">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Ara (ürün, müşteri)</label>
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
                        <h3 class="card-title me-auto"><i class="bi bi-table me-2"></i>Müşteri Soruları</h3>
                        <button class="btn btn-success btn-sm" id="btnCekSorular" onclick="cekSorular()">
                            <i class="bi bi-cloud-download me-1"></i>Soruları Çek
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <table id="sorularTable" class="table table-bordered table-striped table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Platform</th>
                                    <th>Müşteri</th>
                                    <th>Ürün</th>
                                    <th>Soru</th>
                                    <th>Tarih</th>
                                    <th>Durum</th>
                                    <th style="width:80px">İşlem</th>
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

<!-- CEVAP MODALI -->
<div class="modal fade" id="cevapModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-reply me-2"></i>Soru Cevapla</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="c_ps_id">
                
                <div class="mb-3">
                    <label class="fw-bold small text-muted">Ürün</label>
                    <div id="c_urun" class="p-2 bg-light border rounded"></div>
                </div>
                
                <div class="mb-3">
                    <label class="fw-bold small text-muted">Müşteri & Soru</label>
                    <div class="p-3 bg-white border rounded shadow-sm">
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                            <span class="fw-bold" id="c_musteri"></span>
                            <small class="text-muted" id="c_tarih"></small>
                        </div>
                        <div id="c_soru"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="fw-bold small text-muted">Cevabınız</label>
                    <textarea class="form-control" id="c_cevap" rows="4" placeholder="Müşteriye verilecek cevabı yazın..."></textarea>
                    <small class="text-muted">Lütfen profesyonel bir dil kullanın ve iletişim bilgisi paylaşmayın.</small>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-success" id="btnCevapGonder" onclick="cevapGonder()">
                    <i class="bi bi-send me-1"></i>Gönder
                </button>
            </div>
        </div>
    </div>
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
const cevapModal = new bootstrap.Modal(document.getElementById('cevapModal'));

const durumMap = {
    'WAITING_FOR_ANSWER': ['Bekliyor',   'd-WAITING_FOR_ANSWER'],
    'ANSWERED':           ['Cevaplandı', 'd-ANSWERED'],
    'REJECTED':           ['Reddedildi', 'd-REJECTED']
};

function durumBadge(d) {
    const [label, cls] = durumMap[d] || [d ?? '—', 'd-WAITING_FOR_ANSWER'];
    return `<span class="durum-badge ${cls}">${escHtml(label)}</span>`;
}

function statYukle() {
    $.post('', { action:'stats', kanal_id:$('#filtre_kanal').val() }, function(r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-bekleyen').text(r.data.bekleyen);
        $('#stat-cevaplanan').text(r.data.cevaplanan);
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
        table = $('#sorularTable').DataTable({
            data: r.data, destroy:true,
            language: { url:'//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            order:[[4,'desc']], pageLength:25,
            columns: [
                { data:'Entegrasyon_Adi', render:(d,t,row) => `<span class="badge" style="background:${escHtml(row.Entegrasyon_Renk??'#666')}">${escHtml(d)}</span>` },
                { data:'ps_musteri_adi', render:d => escHtml(d??'Müşteri') },
                { data:'ps_urun_adi', render:d => `<div class="soru-metni" title="${escHtml(d)}">${escHtml(d??'—')}</div>` },
                { data:'ps_soru_metni', render:(d,t,row) => {
                    let html = `<div class="soru-metni" title="${escHtml(d)}">${escHtml(d??'—')}</div>`;
                    if (row.ps_durum === 'ANSWERED' && row.ps_cevap_metni) {
                        html += `<div class="small text-muted mt-1 bg-light p-1 rounded" title="${escHtml(row.ps_cevap_metni)}"><i class="bi bi-arrow-return-right me-1"></i>${escHtml(row.ps_cevap_metni)}</div>`;
                    }
                    return html;
                }},
                { data:'soru_tarihi', render:d => `<small>${escHtml(d??'—')}</small>` },
                { data:'ps_durum', render:d => durumBadge(d) },
                { data:'ps_id', orderable:false, render:(d,t,row) => {
                    if (row.ps_durum === 'WAITING_FOR_ANSWER') {
                        return `<button class="btn btn-sm btn-outline-primary" onclick='cevapAc(${JSON.stringify(row)})'>Cevapla</button>`;
                    }
                    return `<button class="btn btn-sm btn-outline-secondary" onclick='cevapAc(${JSON.stringify(row)})'><i class="bi bi-eye"></i></button>`;
                }}
            ]
        });
    });
}

function filtreTemizle() {
    $('#filtre_kanal,#filtre_durum').val('').trigger('change');
    $('#filtre_tarih_bas,#filtre_tarih_bit,#filtre_arama').val('');
    listeYukle(); statYukle();
}

function cekSorular() {
    const btn = $('#btnCekSorular');
    btn.prop('disabled',true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Çekiliyor…');
    $.post('', { action:'cek_sorular', kanal_id:$('#filtre_kanal').val() }, function(r) {
        btn.prop('disabled',false).html('<i class="bi bi-cloud-download me-1"></i>Soruları Çek');
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
        btn.prop('disabled',false).html('<i class="bi bi-cloud-download me-1"></i>Soruları Çek');
        showToast('Sunucu hatası.','danger');
    });
}

function cevapAc(row) {
    $('#c_ps_id').val(row.ps_id);
    $('#c_urun').text(row.ps_urun_adi || 'Bilinmiyor');
    $('#c_musteri').text(row.ps_musteri_adi || 'Müşteri');
    $('#c_tarih').text(row.soru_tarihi || '');
    $('#c_soru').html(escHtml(row.ps_soru_metni || '').replace(/\n/g, '<br>'));
    
    if (row.ps_durum === 'ANSWERED') {
        $('#c_cevap').val(row.ps_cevap_metni || '').prop('disabled', true);
        $('#btnCevapGonder').hide();
    } else {
        $('#c_cevap').val('').prop('disabled', false);
        $('#btnCevapGonder').show();
    }
    
    cevapModal.show();
}

function cevapGonder() {
    const psId  = $('#c_ps_id').val();
    const cevap = $('#c_cevap').val().trim();
    
    if (!cevap) {
        showToast('Lütfen bir cevap yazın.', 'warning');
        return;
    }
    
    const btn = $('#btnCevapGonder');
    btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Gönderiliyor...');
    
    $.post('', { action:'cevap_gonder', ps_id:psId, cevap_metni:cevap }, function(r) {
        btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i>Gönder');
        if (!r.success) { showToast('Hata: '+r.message, 'danger'); return; }
        
        showToast('Cevap başarıyla gönderildi.', 'success');
        cevapModal.hide();
        statYukle(); listeYukle();
    }).fail(() => {
        btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i>Gönder');
        showToast('Sunucu hatası.', 'danger');
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
