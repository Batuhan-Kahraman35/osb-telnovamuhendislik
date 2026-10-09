<?php
/**
 * Admin Panel - Default Dashboard
 * Departmana özel dashboard tanımlanmamışsa bu sayfa gösterilir
 * NOT: Bu dosya anasayfa.php tarafından include edilir, doğrudan çağrılmaz!
 */

require_once __DIR__ . '/../includes/BolgeYetkiHelper.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';

// $user ve $db değişkenleri anasayfa.php'den geliyor

// Departman 10 için kısıtlar: sadece kendi kayıtları + bölge yetkisi
$departmanKisitAktif = ((int)($user['departman_id'] ?? 0) === 10);
$teklifKendiWhere = '';
$teklifKendiParams = [];
$cariKendiWhere = '';
$cariKendiParams = [];
$teklifBolgeWhere = '';
$cariBolgeWhere = '';

if ($departmanKisitAktif) {
    $kullaniciId = (int)($user['kullanici_id'] ?? 0);
    $bolgePermissions = [
        'is_admin' => false,
        'bolge_gor' => true
    ];

    // Kendi kayıtları + kendisine devredilen cariler
    $teklifKendiWhere = ' AND (' . CariDevirHelper::orDevir(
        't.Teklifler_hazirlayan_id = ?', $kullaniciId, 't.Teklifler_cari_id'
    ) . ')';
    $teklifKendiParams[] = $kullaniciId;

    $cariKendiWhere = ' AND (' . CariDevirHelper::orDevir(
        'c.cari_olusturan_kullanici = ?', $kullaniciId, 'c.cari_id'
    ) . ')';
    $cariKendiParams[] = $kullaniciId;

    $teklifBolgeWhere = BolgeYetkiHelper::getSqlWhere($kullaniciId, $bolgePermissions, 'c.cari_sehirler', 'c.cari_ilceler', 'c.cari_adres');
    $cariBolgeWhere = BolgeYetkiHelper::getSqlWhere($kullaniciId, $bolgePermissions, 'c.cari_sehirler', 'c.cari_ilceler', 'c.cari_adres');
}

// Site ayarlarını çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'dashboard_stats':
                // Cari istatistikleri
                $buAyCari = $db->fetchOne(
                    "SELECT COUNT(*) as total
                     FROM Cari c
                     WHERE c.cari_aktif = 1
                       AND MONTH(c.cari_olusturma_tarihi) = MONTH(GETDATE())
                       AND YEAR(c.cari_olusturma_tarihi) = YEAR(GETDATE())
                       {$cariKendiWhere}
                       {$cariBolgeWhere}",
                    $cariKendiParams
                )['total'] ?? 0;
                $ssaAktif  = $db->fetchOne(
                    "SELECT COUNT(*) as total
                     FROM Cari c
                     WHERE c.cari_SSAAktif = 1
                       AND c.cari_aktif = 1
                       {$cariKendiWhere}
                       {$cariBolgeWhere}",
                    $cariKendiParams
                )['total'] ?? 0;

                // Teklif istatistikleri
                $toplamTeklif    = $db->fetchOne(
                    "SELECT COUNT(*) as total
                     FROM Teklifler t
                     LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
                     WHERE t.Durum = 1
                       {$teklifKendiWhere}
                       {$teklifBolgeWhere}",
                    $teklifKendiParams
                )['total'] ?? 0;
                $onaylananTutar  = $db->fetchOne(
                    "SELECT ISNULL(SUM(t.Teklifler_genel_toplam), 0) as tutar
                     FROM Teklifler t
                     LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
                     WHERE t.Durum = 1
                       AND t.Teklifler_durum_id = 3
                       {$teklifKendiWhere}
                       {$teklifBolgeWhere}",
                    $teklifKendiParams
                )['tutar'] ?? 0;

                echo json_encode(['success' => true, 'data' => [
                    'bu_ay_cari'       => intval($buAyCari),
                    'ssa_aktif'        => intval($ssaAktif),
                    'toplam_teklif'    => intval($toplamTeklif),
                    'onaylanan_tutar'  => floatval($onaylananTutar)
                ]]);
                break;

            case 'son_teklifler':
                $data = $db->fetchAll("
                    SELECT TOP 5
                        t.Teklifler_id,
                        t.Teklifler_no,
                        c.cari_adi,
                        c.cari_unvan,
                        t.Teklifler_genel_toplam,
                        td.Teklif_Durumlari_ad as durum_adi,
                        td.Teklif_Durumlari_renk as durum_renk,
                        kh.kullanici_ad + ' ' + kh.kullanici_soyad as hazirlayan_adi,
                        CONVERT(VARCHAR(16), t.GuncellemeTarihi, 120) as guncelleme_tarihi
                    FROM Teklifler t
                    LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
                    LEFT JOIN Teklif_Durumlari td ON t.Teklifler_durum_id = td.Teklif_Durumlari_id AND td.Durum = 1
                    LEFT JOIN kullanicilar kh ON t.Teklifler_hazirlayan_id = kh.kullanici_id
                    WHERE t.Durum = 1
                      AND t.Teklifler_tip_id = 1
                      {$teklifKendiWhere}
                      {$teklifBolgeWhere}
                    ORDER BY t.GuncellemeTarihi DESC
                ", $teklifKendiParams);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'son_isletmeler':
                $data = $db->fetchAll("
                    SELECT TOP 5
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan,
                        c.cari_SSAAktif,
                        CONVERT(VARCHAR(16), c.cari_guncelleme_tarihi, 120) as guncelleme_tarihi
                    FROM Cari c
                    WHERE c.cari_aktif = 1
                      {$cariKendiWhere}
                      {$cariBolgeWhere}
                    ORDER BY c.cari_guncelleme_tarihi DESC
                ", $cariKendiParams);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'karlilik_ozet':
                // Satış kanalı / personel bazlı karlılık.
                // Personel: teklifi hazırlayan öncelikli, yoksa faturayı oluşturan.
                // Departman kısıtı uygulanmaz; karlılık özeti tüm kullanıcılara aynı gösterilir.
                $karlilikSorgusu = "
                    WITH AlisBirim AS (
                        SELECT
                            fgk.FaturalarGelenKalemleri_UrunHizmet_id   AS urun_id,
                            AVG(fgk.FaturalarGelenKalemleri_BirimFiyat) AS ort_alis_birim
                        FROM dbo.FaturalarGelenKalemleri fgk
                        INNER JOIN dbo.FaturalarGelen fg ON fg.FaturalarGelen_id = fgk.FaturalarGelenKalemleri_Fatura_id
                        WHERE fgk.FaturalarGelenKalemleri_UrunHizmet_id IS NOT NULL
                          AND fg.Durum = 1
                        GROUP BY fgk.FaturalarGelenKalemleri_UrunHizmet_id
                    ),
                    Kalemler AS (
                        SELECT
                            CASE
                                WHEN pz.pazaryeri_adi IS NOT NULL THEN N'İnternet — ' + pz.pazaryeri_adi
                                ELSE ISNULL(per.personel_adi, N'Atanmamış')
                            END AS kanal,
                            CASE WHEN pz.pazaryeri_adi IS NOT NULL THEN 1 ELSE 0 END AS internet_mi,
                            CASE WHEN pz.pazaryeri_adi IS NULL AND per.personel_adi IS NULL
                                 THEN 1 ELSE 0 END AS atanmamis_mi,
                            fdk.FaturalarGidenKalemleri_Miktar * fdk.FaturalarGidenKalemleri_BirimFiyat AS satis_tutar,
                            CASE WHEN ab.ort_alis_birim > 0
                                 THEN fdk.FaturalarGidenKalemleri_Miktar * ab.ort_alis_birim END AS maliyet_tutar,
                            CASE WHEN ab.ort_alis_birim > 0
                                 THEN fdk.FaturalarGidenKalemleri_Miktar * fdk.FaturalarGidenKalemleri_BirimFiyat END AS karli_satis
                        FROM dbo.FaturalarGidenKalemleri fdk
                        INNER JOIN dbo.FaturalarGiden fd ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
                        LEFT  JOIN AlisBirim ab         ON ab.urun_id           = fdk.FaturalarGidenKalemleri_UrunHizmet_id
                        LEFT  JOIN dbo.Teklifler t      ON t.Teklifler_id       = fd.FaturalarGiden_Teklif_id
                        OUTER APPLY (
                            -- Satisi yapan personel: once teklifi hazirlayan, yoksa cariye en cok teklif veren.
                            -- Faturayi sisteme giren kullanici KAYNAK DEGILDIR; fatura girisi ofis
                            -- personeli tarafindan yapildigi icin satis dagilimini bozuyordu.
                            SELECT TOP 1 x.personel_adi
                            FROM (
                                SELECT 1 AS oncelik, 0 AS ayirici,
                                       k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi
                                FROM dbo.kullanicilar k
                                WHERE k.kullanici_id = t.Teklifler_hazirlayan_id
                                UNION ALL
                                -- Cariye en cok teklif veren personel. Carinin olusturan/devredilen
                                -- kullanicisi kaynak DEGILDIR; ikisi de kaydi girenle doluyor.
                                SELECT 2 AS oncelik, COUNT(*),
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
                        WHERE fd.Durum = 1
                          AND fdk.FaturalarGidenKalemleri_UrunHizmet_id IS NOT NULL
                          %s
                    )
                    SELECT TOP 10
                        kanal,
                        MAX(internet_mi)              AS internet_mi,
                        MAX(atanmamis_mi)             AS atanmamis_mi,
                        COUNT(*)                      AS kalem,
                        SUM(satis_tutar)              AS satis,
                        ISNULL(SUM(maliyet_tutar), 0) AS maliyet,
                        ISNULL(SUM(karli_satis), 0)   AS karli_satis
                    FROM Kalemler
                    GROUP BY kanal
                    ORDER BY (ISNULL(SUM(karli_satis), 0) - ISNULL(SUM(maliyet_tutar), 0)) DESC
                ";

                $son30    = $db->fetchAll(sprintf($karlilikSorgusu, "AND fd.FaturalarGiden_Tarih >= DATEADD(DAY, -30, GETDATE())"));
                $tumZaman = $db->fetchAll(sprintf($karlilikSorgusu, ""));

                echo json_encode(['success' => true, 'data' => [
                    'son30'     => $son30,
                    'tum_zaman' => $tumZaman
                ]]);
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
    <title>Ana Sayfa - <?= htmlspecialchars($siteTitle) ?></title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .dashboard-card { transition: transform 0.2s, box-shadow 0.2s; border: none; }
        .dashboard-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .small-box .inner h3 { font-size: 2rem; font-weight: 700; }
        .small-box .inner p { font-size: 0.9rem; }
        .module-header { font-size: 1rem; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; }
        .module-header i { font-size: 1.1rem; }
        .stat-highlight { font-size: 1.5rem; font-weight: 700; }
        .stat-label { font-size: 0.8rem; color: #6c757d; }
        .table-dashboard th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; border-top: none; }
        .table-dashboard td { font-size: 0.85rem; vertical-align: middle; }
        .progress-thin { height: 6px; border-radius: 3px; }
        .welcome-gradient { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .section-divider { border: none; border-top: 2px solid #e9ecef; margin: 1.5rem 0; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <div class="app-content">
                <div class="container-fluid">

                    <!-- InfoBox'lar -->
                    <div class="row mb-4">
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-primary dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_bu_ay_cari">-</h3>
                                    <p>Bu Ay Eklenen İşletme</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-calendar-plus"></i></div>
                                <a href="/admin/cari-yonetimi" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Detaylar <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-success dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_ssa_aktif">-</h3>
                                    <p>SSA Aktif İşletme</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-shield-check"></i></div>
                                <a href="/admin/cari-yonetimi" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Detaylar <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-warning dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_toplam_teklif">-</h3>
                                    <p>Toplam Teklif</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-file-earmark-text"></i></div>
                                <a href="/admin/teklif-yonetimi" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Detaylar <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-info dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_onaylanan_tutar">-</h3>
                                    <p>Onaylanan Teklif Tutarı</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-currency-dollar"></i></div>
                                <a href="/admin/teklif-yonetimi" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Detaylar <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <!-- Son Eklenen Teklifler -->
                        <div class="col-lg-5">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-file-earmark-check me-2"></i>Son Eklenen Teklifler</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Hazırlayan</th>
                                                    <th>İşletme</th>
                                                    <th>Durum</th>
                                                    <th>Son Güncelleme</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_son_teklifler">
                                                <tr><td colspan="4" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Son Güncellenen İşletmeler -->
                        <div class="col-lg-7">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-building me-2"></i>Son Eklenen İşletmeler</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>İşletme Adı</th>
                                                    <th>Ünvan</th>
                                                    <th>SSA</th>
                                                    <th>Son Güncelleme</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_son_isletmeler">
                                                <tr><td colspan="4" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Karlılık Tabloları -->
                    <div class="row mb-4">
                        <div class="col-lg-6">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-calendar-range me-2"></i>Karlılık - Son 30 Gün</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Satış Kanalı</th>
                                                    <th class="text-end">Satış</th>
                                                    <th class="text-end">Toplam Kar</th>
                                                    <th class="text-end">Kar %</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_karlilik_son30">
                                                <tr><td colspan="4" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-infinity me-2"></i>Karlılık - Tüm Zamanlar</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Satış Kanalı</th>
                                                    <th class="text-end">Satış</th>
                                                    <th class="text-end">Toplam Kar</th>
                                                    <th class="text-end">Kar %</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_karlilik_tum">
                                                <tr><td colspan="4" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script>
    // Para formatlama (kısa)
    function fmtMoney(val) {
        if (!val && val !== 0) return '-';
        return parseFloat(val).toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ₺';
    }
    function fmtMoneyShort(val) {
        if (!val && val !== 0) return '-';
        val = parseFloat(val);
        if (val >= 1000000) return (val / 1000000).toFixed(1).replace('.', ',') + 'M ₺';
        if (val >= 1000) return (val / 1000).toFixed(0) + 'K ₺';
        return val.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ₺';
    }

    $(document).ready(function() {
        loadDashboardStats();
        loadSonTeklifler();
        loadSonIsletmeler();
        loadKarlilik();
    });

    // ======================================
    // Ana İstatistikler
    // ======================================
    function loadDashboardStats() {
        $.post('', { action: 'dashboard_stats' }, function(res) {
            if (!res.success) return;
            const d = res.data;
            $('#ib_bu_ay_cari').text(d.bu_ay_cari ?? '-');
            $('#ib_ssa_aktif').text(d.ssa_aktif ?? '-');
            $('#ib_toplam_teklif').text(d.toplam_teklif ?? '-');
            $('#ib_onaylanan_tutar').text(fmtMoneyShort(d.onaylanan_tutar));
        }, 'json');
    }

    // ======================================
    // Grafikler
    // ======================================


    // ======================================
    // Son Kayıtlar Tabloları
    // ======================================
    function loadSonTeklifler() {
        $.post('', { action: 'son_teklifler' }, function(res) {
            if (!res.success || !res.data.length) {
                $('#tbl_son_teklifler').html('<tr><td colspan="4" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }
            let html = '';
            res.data.forEach(function(r) {
                const renk = r.durum_renk || 'secondary';
                const durumBadge = '<span class="badge bg-' + renk + '">' + (r.durum_adi || '-') + '</span>';
                html += '<tr>' +
                    '<td><a href="/admin/teklif-form?id=' + r.Teklifler_id + '" class="text-decoration-none fw-semibold">' + (r.hazirlayan_adi ? r.hazirlayan_adi.toLocaleUpperCase('tr-TR') : '-') + '</a></td>' +
                    '<td><small>' + (r.cari_adi ? r.cari_adi.toLocaleUpperCase('tr-TR') : '-') + '</small></td>' +
                    '<td>' + durumBadge + '</td>' +
                    '<td><small class="text-muted">' + (r.guncelleme_tarihi || '-') + '</small></td>' +
                    '</tr>';
            });
            $('#tbl_son_teklifler').html(html);
        }, 'json');
    }

    // ======================================
    // Karlılık Tabloları
    // ======================================
    function loadKarlilik() {
        $.post('', { action: 'karlilik_ozet' }, function(res) {
            if (!res.success) {
                const hata = '<tr><td colspan="4" class="text-center text-danger py-3">' + (res.message || 'Hata') + '</td></tr>';
                $('#tbl_karlilik_son30, #tbl_karlilik_tum').html(hata);
                return;
            }
            renderKarlilik('#tbl_karlilik_son30', res.data.son30);
            renderKarlilik('#tbl_karlilik_tum',   res.data.tum_zaman);
        }, 'json').fail(function() {
            $('#tbl_karlilik_son30, #tbl_karlilik_tum').html('<tr><td colspan="4" class="text-center text-danger py-3">Sunucu hatası</td></tr>');
        });
    }

    function renderKarlilik(hedef, satirlar) {
        if (!satirlar || !satirlar.length) {
            $(hedef).html('<tr><td colspan="4" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
            return;
        }
        let html = '';
        satirlar.forEach(function(r) {
            // Kar ve marj yalnız alış fiyatı bilinen kalemlerden hesaplanır
            const karliSatis = parseFloat(r.karli_satis || 0);
            const maliyet    = parseFloat(r.maliyet || 0);
            const kar        = karliSatis - maliyet;
            const marj       = karliSatis > 0 ? (kar / karliSatis) * 100 : 0;
            const internet   = parseInt(r.internet_mi || 0) === 1;
            const atanmamis  = parseInt(r.atanmamis_mi || 0) === 1;
            let renk = 'bg-secondary', ikon = 'bi-person-badge';
            if (internet)       { renk = 'bg-success';         ikon = 'bi-globe'; }
            else if (atanmamis) { renk = 'bg-warning text-dark'; ikon = 'bi-question-circle'; }
            const karCls     = kar  >= 0 ? 'text-success'    : 'text-danger';
            const marjCls    = marj >= 0 ? 'text-bg-success' : 'text-bg-danger';

            html += '<tr>' +
                '<td><span class="badge ' + renk + '"><i class="bi ' + ikon + ' me-1"></i>' + esc(r.kanal || '-') + '</span></td>' +
                '<td class="text-end">' + fmtMoneyShort(r.satis) + '</td>' +
                '<td class="text-end"><span class="' + karCls + ' fw-semibold">' + fmtMoneyShort(kar) + '</span></td>' +
                '<td class="text-end"><span class="badge ' + marjCls + '">' + marj.toLocaleString('tr-TR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%</span></td>' +
                '</tr>';
        });
        $(hedef).html(html);
    }

    function esc(v) {
        return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function loadSonIsletmeler() {
        $.post('', { action: 'son_isletmeler' }, function(res) {
            if (!res.success || !res.data.length) {
                $('#tbl_son_isletmeler').html('<tr><td colspan="4" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }
            let html = '';
            res.data.forEach(function(r) {
                const ssaBadge = r.cari_SSAAktif == 1
                    ? '<span class="badge bg-success">Aktif</span>'
                    : '<span class="badge bg-secondary">Pasif</span>';
                html += '<tr>' +
                    '<td><a href="/admin/cari-yonetimi?id=' + r.cari_id + '" class="text-decoration-none fw-semibold">' + (r.cari_adi ? r.cari_adi.toLocaleUpperCase('tr-TR') : '-') + '</a></td>' +
                    '<td><small>' + (r.cari_unvan ? r.cari_unvan.toLocaleUpperCase('tr-TR') : '-') + '</small></td>' +
                    '<td>' + ssaBadge + '</td>' +
                    '<td><small class="text-muted">' + (r.guncelleme_tarihi || '-') + '</small></td>' +
                    '</tr>';
            });
            $('#tbl_son_isletmeler').html(html);
        }, 'json');
    }


    </script>
</body>
</html>
