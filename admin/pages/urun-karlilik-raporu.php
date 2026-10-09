<?php
/**
 * Admin Panel - Ürün Karlılık Raporu
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Erişim yetkiniz yok.');
}

$pageInfo  = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ürün Karlılık Raporu';
$menuAdi   = $pageInfo['menu_adi'] ?? null;
$siteTitle = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC")['site_ayarlari_site_title'] ?? 'OSB Portal';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                $startDate = $_POST['start_date'] ?? '';
                $endDate   = $_POST['end_date']   ?? '';
                $search    = trim($_POST['search'] ?? '');
                $gidenF    = trim($_POST['giden_f'] ?? '');

                $where  = "fd.Durum = 1 AND fdk.FaturalarGidenKalemleri_UrunHizmet_id IS NOT NULL";
                $params = [];

                if ($startDate) {
                    $where   .= " AND CONVERT(date, fd.FaturalarGiden_Tarih) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $where   .= " AND CONVERT(date, fd.FaturalarGiden_Tarih) <= ?";
                    $params[] = $endDate;
                }
                if ($search) {
                    $where   .= " AND (uh.urun_hizmet_adi LIKE ? OR uh.urun_hizmet_kodu LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($gidenF) {
                    $where   .= " AND fd.FaturalarGiden_FaturaNo LIKE ?";
                    $params[] = "%$gidenF%";
                }

                $data = $db->fetchAll("
                    WITH AlisBirim AS (
                        SELECT
                            fgk.FaturalarGelenKalemleri_UrunHizmet_id          AS urun_id,
                            AVG(fgk.FaturalarGelenKalemleri_BirimFiyat)        AS ort_alis_birim,
                            STRING_AGG(fg.FaturalarGelen_FaturaNo, ', ')       AS gelen_faturalar
                        FROM dbo.FaturalarGelenKalemleri fgk
                        INNER JOIN dbo.FaturalarGelen fg ON fg.FaturalarGelen_id = fgk.FaturalarGelenKalemleri_Fatura_id
                        WHERE fgk.FaturalarGelenKalemleri_UrunHizmet_id IS NOT NULL
                          AND fg.Durum = 1
                        GROUP BY fgk.FaturalarGelenKalemleri_UrunHizmet_id
                    )
                    SELECT
                        CONVERT(VARCHAR(10), fd.FaturalarGiden_Tarih, 120) AS tarih,
                        fd.FaturalarGiden_FaturaNo                         AS giden_fatura_no,
                        uh.urun_hizmet_kodu                                AS stok_kodu,
                        uh.urun_hizmet_adi                                 AS urun_adi,
                        fdk.FaturalarGidenKalemleri_Miktar                 AS satis_miktar,
                        fdk.FaturalarGidenKalemleri_BirimFiyat             AS satis_birim_fiyat,
                        fdk.FaturalarGidenKalemleri_NetTutar               AS satis_toplam,
                        ab.ort_alis_birim                                  AS ort_alis_birim,
                        ab.gelen_faturalar                                 AS gelen_faturalar,
                        per.personel_id                                    AS personel_id,
                        per.personel_adi                                   AS personel_adi,
                        pz.pazaryeri_adi                                   AS pazaryeri_adi
                    FROM dbo.FaturalarGidenKalemleri fdk
                    INNER JOIN dbo.FaturalarGiden fd ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
                    INNER JOIN dbo.Urun_Hizmet uh    ON uh.urun_hizmet_id   = fdk.FaturalarGidenKalemleri_UrunHizmet_id
                    LEFT  JOIN AlisBirim ab           ON ab.urun_id          = fdk.FaturalarGidenKalemleri_UrunHizmet_id
                    LEFT  JOIN dbo.Teklifler t        ON t.Teklifler_id      = fd.FaturalarGiden_Teklif_id
                    OUTER APPLY (
                        -- Satisi yapan personel: once teklifi hazirlayan, yoksa cariye en cok teklif veren.
                        -- Faturayi sisteme giren kullanici (OlusturanKullanici) KAYNAK DEGILDIR;
                        -- fatura girisi cogunlukla ofis personeli tarafindan yapildigi icin
                        -- satis dagilimini bozuyordu. Cozulemeyen faturalar Atanmamis kalir.
                        SELECT TOP 1 x.personel_id, x.personel_adi
                        FROM (
                            SELECT 1 AS oncelik, 0 AS ayirici, k.kullanici_id AS personel_id,
                                   k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi
                            FROM dbo.kullanicilar k
                            WHERE k.kullanici_id = t.Teklifler_hazirlayan_id
                            UNION ALL
                            -- Cariye en cok teklif veren personel. Carinin olusturan/devredilen
                            -- kullanicisi kaynak DEGILDIR; ikisi de kaydi girenle doluyor.
                            SELECT 2 AS oncelik, COUNT(*), tk.kullanici_id,
                                   tk.kullanici_ad + ' ' + tk.kullanici_soyad
                            FROM dbo.Cari c
                            INNER JOIN dbo.Teklifler t2   ON t2.Teklifler_cari_id = c.cari_id AND t2.Durum = 1
                            INNER JOIN dbo.kullanicilar tk ON tk.kullanici_id     = t2.Teklifler_hazirlayan_id
                            WHERE c.cari_vergi_no = fd.FaturalarGiden_VKN_TCKN
                              AND ISNULL(c.cari_vergi_no, '') <> ''
                            GROUP BY tk.kullanici_id, tk.kullanici_ad, tk.kullanici_soyad
                        ) x
                        ORDER BY x.oncelik, x.ayirici DESC
                    ) per
                    OUTER APPLY (
                        SELECT TOP 1 p.Pazaryerleri_Ad AS pazaryeri_adi
                        FROM dbo.PazaryeriSiparisleri ps
                        INNER JOIN dbo.tanim_Pazaryerleri p ON p.Pazaryerleri_id = ps.Pazaryerleri_id
                        WHERE ps.PazaryeriSiparisleri_Fatura_id = fd.FaturalarGiden_id AND ps.Durum = 1
                    ) pz
                    WHERE $where
                    ORDER BY fd.FaturalarGiden_Tarih DESC, fd.FaturalarGiden_FaturaNo, uh.urun_hizmet_kodu
                ", $params);

                echo json_encode(['success' => true, 'data' => $data]);
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
    <link rel="stylesheet" href="/admin/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <link rel="stylesheet" href="/admin/assets/vendor/select2/css/select2.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/select2/css/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
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
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-receipt"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kalem</span>
                                <span class="info-box-number" id="stat_kalem">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-cart-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Satış</span>
                                <span class="info-box-number" id="stat_satis">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-graph-up-arrow"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Brüt Kar</span>
                                <span class="info-box-number" id="stat_kar">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-percent"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Ort. Kar Marjı</span>
                                <span class="info-box-number" id="stat_marj">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <span><i class="bi bi-funnel me-1"></i>Filtrele</span>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div class="collapse show" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-2">
                                    <label class="form-label">Ürün Adı / Stok Kodu</label>
                                    <input type="text" id="f_search" class="form-control" placeholder="Ara...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Giden Fatura No</label>
                                    <input type="text" id="f_giden_f" class="form-control" placeholder="Giden F. No...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Satış Kanalı</label>
                                    <select id="f_kanal" class="form-select" style="width:100%">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih Başlangıç</label>
                                    <input type="date" id="f_start" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih Bitiş</label>
                                    <input type="date" id="f_end" class="form-control">
                                </div>
                                <div class="col-md-2 d-flex gap-1 align-items-end">
                                    <button id="btnFiltrele" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button id="btnTemizle" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> Temizle</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personel Bazlı Özet -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#ozetPanel">
                        <h3 class="card-title mb-0"><i class="bi bi-person-badge me-1"></i>Satış Kanalı / Personel Bazlı Özet</h3>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div class="collapse show" id="ozetPanel">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover table-sm align-middle" id="ozetTable" style="width:100%">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Satış Kanalı</th>
                                            <th class="text-end">Kalem</th>
                                            <th class="text-end">Toplam Satış</th>
                                            <th class="text-end">Toplam Maliyet</th>
                                            <th class="text-end">Brüt Kar</th>
                                            <th class="text-end">Kar Marjı</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ozetBody">
                                        <tr><td colspan="6" class="text-center text-muted">Yükleniyor...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title mb-0"><i class="bi bi-table me-1"></i>Satış Kalemleri</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover table-sm align-middle" id="dataTable" style="width:100%">
                                <thead class="table-light">
                                    <tr>
                                        <th>Satış T.</th>
                                        <th>Satış Kanalı</th>
                                        <th>Gelen F.</th>
                                        <th>Satış F.</th>
                                        <th>Stok Kodu</th>
                                        <th>Ürün Adı</th>
                                        <th class="text-end">Satış Adet</th>
                                        <th class="text-end">Ort. Alış Birim</th>
                                        <th class="text-end">Satış Birim</th>
                                        <th class="text-end">Kar Oranı</th>
                                        <th class="text-end">Toplam Tutar</th>
                                        <th class="text-end">Toplam Karlılık</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <tr><td colspan="12" class="text-center text-muted">Yükleniyor...</td></tr>
                                </tbody>
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
<script src="/admin/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script src="/admin/assets/vendor/select2/js/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
let dt      = null;
let ozetDt  = null;
let tumVeri = [];

function fmt(val, decimals) {
    decimals = decimals ?? 2;
    return parseFloat(val || 0).toLocaleString('tr-TR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function htmlEsc(v) {
    return String(v ?? "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

// Satış kanalı metni: pazaryeri siparişi ise kanal adı, değilse satışı yapan personel
function kanalMetni(row) {
    if (row.pazaryeri_adi) return "İnternet — " + row.pazaryeri_adi;
    return row.personel_adi || "Atanmamış";
}

function badgeKanal(row) {
    const deger = kanalMetni(row);
    const atanmamis = !row.pazaryeri_adi && !row.personel_adi;

    let renk = "bg-secondary", ikon = "bi-person-badge";
    let aciklama = "Satış personeli / doğrudan satış";

    if (row.pazaryeri_adi) {
        renk = "bg-success"; ikon = "bi-globe";
        aciklama = "İnternet üzerinden satış";
    } else if (atanmamis) {
        renk = "bg-warning text-dark"; ikon = "bi-question-circle";
        aciklama = "Faturanın teklifi yok ve carisinde sorumlu personel bulunamadı";
    }

    return "<span class=\"badge " + renk + "\" title=\"" + htmlEsc(aciklama) + "\">"
         + "<i class=\"bi " + ikon + " me-1\"></i>" + htmlEsc(deger) + "</span>";
}

function loadList() {
    $('#tableBody').html('<tr><td colspan="12" class="text-center"><i class="bi bi-hourglass-split"></i> Yükleniyor...</td></tr>');
    $('#ozetBody').html('<tr><td colspan="6" class="text-center"><i class="bi bi-hourglass-split"></i> Yükleniyor...</td></tr>');
    if (dt) { dt.destroy(); dt = null; }
    if (ozetDt) { ozetDt.destroy(); ozetDt = null; }

    $.post('', {
        action:     'list',
        search:     $('#f_search').val().trim(),
        giden_f:    $('#f_giden_f').val().trim(),
        start_date: $('#f_start').val(),
        end_date:   $('#f_end').val()
    }, function(r) {
        if (!r.success) {
            $('#tableBody').html('<tr><td colspan="12" class="text-center text-danger">' + r.message + '</td></tr>');
            $('#ozetBody').html('<tr><td colspan="6" class="text-center text-danger">' + r.message + '</td></tr>');
            return;
        }
        tumVeri = r.data || [];
        kanalDoldur(tumVeri);
        render();
    }, 'json').fail(function() {
        $('#tableBody').html('<tr><td colspan="12" class="text-center text-danger">Sunucu hatası.</td></tr>');
        $('#ozetBody').html('<tr><td colspan="6" class="text-center text-danger">Sunucu hatası.</td></tr>');
    });
}

// Satış kanalı dropdown'unu gelen veriden doldurur (statik liste yok)
function kanalDoldur(data) {
    const secili   = $('#f_kanal').val();
    const kanallar = [...new Set(data.map(kanalMetni))].sort((a, b) => a.localeCompare(b, 'tr'));
    const sel      = $('#f_kanal');
    sel.empty().append('<option value="">Tümü</option>');
    kanallar.forEach(k => sel.append($('<option>').attr('value', k).text(k)));
    if (secili && kanallar.includes(secili)) sel.val(secili);
    sel.trigger('change.select2');
}

function render() {
    const kanal = $('#f_kanal').val();
    const data  = kanal ? tumVeri.filter(d => kanalMetni(d) === kanal) : tumVeri;

    const tbody = $('#tableBody');
    if (dt) { dt.destroy(); dt = null; }
    tbody.empty();

    if (data.length === 0) {
        tbody.html('<tr><td colspan="12" class="text-center text-muted">Kayıt bulunamadı</td></tr>');
        updateInfoBox([]);
        updateOzet([]);
        return;
    }

    data.forEach(function(d) {
            const tarih = d.tarih ? d.tarih.split('-').reverse().join('.') : '-';
            tbody.append(`
                <tr>
                    <td class="text-nowrap">${tarih}</td>
                    <td>${badgeKanal(d)}</td>
                    <td><small>${d.gelen_faturalar ? d.gelen_faturalar.split(', ').map(f => `<a href="/admin/pages/fatura-goruntule.php?fatura_no=${encodeURIComponent(f.trim())}" target="_blank">${f.trim()}</a>`).join('<br>') : '<span class="text-muted">-</span>'}</small></td>
                    <td class="text-nowrap"><small><a href="/admin/pages/fatura-goruntule.php?fatura_no=${encodeURIComponent(d.giden_fatura_no)}" target="_blank">${d.giden_fatura_no}</a></small></td>
                    <td><code>${d.stok_kodu || '-'}</code></td>
                    <td>${d.urun_adi || '-'}</td>
                    <td class="text-end">${fmt(d.satis_miktar, 0)}</td>
                    <td class="text-end">${d.ort_alis_birim ? fmt(d.ort_alis_birim) + ' ₺' : '<span class="text-muted">-</span>'}</td>
                    <td class="text-end">${fmt(d.satis_birim_fiyat)} ₺</td>
                    <td class="text-end">${(() => {
                        const alis = parseFloat(d.ort_alis_birim || 0);
                        const satis = parseFloat(d.satis_birim_fiyat || 0);
                        if (!alis || !satis) return '<span class="text-muted">-</span>';
                        const oran = ((satis - alis) / satis) * 100;
                        const cls = oran >= 0 ? 'text-bg-success' : 'text-bg-danger';
                        return `<span class="badge ${cls}">${fmt(oran, 1)}%</span>`;
                    })()}</td>
                    <td class="text-end">${fmt(parseFloat(d.satis_miktar || 0) * parseFloat(d.satis_birim_fiyat || 0))} ₺</td>
                    <td class="text-end">${(() => {
                        const alis   = parseFloat(d.ort_alis_birim   || 0);
                        const miktar = parseFloat(d.satis_miktar      || 0);
                        const satis  = parseFloat(d.satis_birim_fiyat || 0);
                        if (!alis || !miktar || !satis) return '<span class="text-muted">-</span>';
                        const karlilik = miktar * (satis - alis);
                        const cls = karlilik >= 0 ? 'text-success' : 'text-danger';
                        return `<span class="${cls} fw-semibold">${fmt(karlilik)} ₺</span>`;
                    })()}</td>
                </tr>
            `);
        });

    dt = $('#dataTable').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        pageLength: 25,
        order: [[0, 'desc']]
    });

    updateInfoBox(data);
    updateOzet(data);
}

// Satış kanalı / personel bazlı özet - ek sorgu yok, aynı veriden gruplanır
function updateOzet(data) {
    const grup = {};
    data.forEach(function(d) {
        const ad = kanalMetni(d);
        if (!grup[ad]) grup[ad] = { ad: ad, satir: d, kalem: 0, satis: 0, maliyet: 0, karliSatis: 0, maliyetsiz: 0 };
        const miktar = parseFloat(d.satis_miktar || 0);
        const birim  = parseFloat(d.satis_birim_fiyat || 0);
        const alis   = parseFloat(d.ort_alis_birim || 0);
        const g = grup[ad];
        g.kalem += 1;
        g.satis += miktar * birim;
        // Kar ve marj yalnız alış fiyatı bilinen kalemlerden hesaplanır (InfoBox ile aynı mantık)
        if (alis && miktar && birim) {
            g.maliyet    += miktar * alis;
            g.karliSatis += miktar * birim;
        } else {
            g.maliyetsiz += 1;
        }
    });

    const satirlar = Object.values(grup).sort((a, b) => (b.karliSatis - b.maliyet) - (a.karliSatis - a.maliyet));
    const tbody    = $('#ozetBody');
    if (ozetDt) { ozetDt.destroy(); ozetDt = null; }
    tbody.empty();

    if (satirlar.length === 0) {
        tbody.html('<tr><td colspan="6" class="text-center text-muted">Kayıt bulunamadı</td></tr>');
        return;
    }

    satirlar.forEach(function(g) {
        const kar  = g.karliSatis - g.maliyet;
        const marj = g.karliSatis > 0 ? (kar / g.karliSatis) * 100 : 0;
        const cls  = kar  >= 0 ? 'text-success'    : 'text-danger';
        const bcls = marj >= 0 ? 'text-bg-success' : 'text-bg-danger';
        const uyari = g.maliyetsiz
            ? ` <i class="bi bi-exclamation-triangle text-warning" title="${g.maliyetsiz} kalemin alış fiyatı bilinmiyor, kar/marj hesabına katılmadı"></i>`
            : '';
        tbody.append(`
            <tr>
                <td>${badgeKanal(g.satir)}</td>
                <td class="text-end">${g.kalem}${uyari}</td>
                <td class="text-end">${fmt(g.satis)} ₺</td>
                <td class="text-end">${fmt(g.maliyet)} ₺</td>
                <td class="text-end"><span class="${cls} fw-semibold">${fmt(kar)} ₺</span></td>
                <td class="text-end"><span class="badge ${bcls}">${fmt(marj, 1)}%</span></td>
            </tr>
        `);
    });

    ozetDt = $('#ozetTable').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        paging: false,
        searching: false,
        info: false,
        order: [[4, 'desc']]
    });
}

function updateInfoBox(data) {
    const toplamSatis = data.reduce((s, d) => s + parseFloat(d.satis_miktar || 0) * parseFloat(d.satis_birim_fiyat || 0), 0);
    const toplamKar   = data.reduce((s, d) => {
        const alis   = parseFloat(d.ort_alis_birim   || 0);
        const miktar = parseFloat(d.satis_miktar      || 0);
        const satis  = parseFloat(d.satis_birim_fiyat || 0);
        if (!alis || !miktar || !satis) return s;
        return s + miktar * (satis - alis);
    }, 0);
    const karMarji = toplamSatis > 0 ? (toplamKar / toplamSatis) * 100 : 0;

    $('#stat_kalem').text(data.length);
    $('#stat_satis').text(fmt(toplamSatis) + ' ₺');
    $('#stat_kar').text(fmt(toplamKar) + ' ₺');
    $('#stat_marj').text(fmt(karMarji, 1) + '%');
}

$.fn.select2.defaults.set('theme', 'bootstrap-5');
$('#f_kanal').select2({
    theme: 'bootstrap-5',
    width: '100%',
    placeholder: 'Tümü',
    allowClear: true,
    language: { noResults: () => 'Sonuç bulunamadı' }
});

$('#f_kanal').on('change', render);

$('#btnFiltrele').on('click', loadList);
$('#btnTemizle').on('click', function() {
    $('#f_search, #f_giden_f, #f_start, #f_end').val('');
    $('#f_kanal').val('').trigger('change.select2');
    loadList();
});
$('#filtrePanel input').on('keypress', function(e) {
    if (e.which === 13) loadList();
});

$(document).ready(loadList);
</script>
</body>
</html>
