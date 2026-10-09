<?php
/**
 * Admin Panel - Ödeme Yönetimi Listesi
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/FaturaKur.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile  = basename($_SERVER['PHP_SELF']);
$pagePermissions  = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo    = $db->fetchOne("SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi as menu_adi FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1", ['%' . $currentPageFile]);
$pageTitle   = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ödeme Yönetimi';
$menuAdi     = $pageInfo['menu_adi'] ?? null;
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle   = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// FG_TL_TUTAR / FG_KUR / FG_KURSUZ / FG_KUR_APPLY sabitleri artik
// includes/FaturaKur.php icinde; odeme-form.php ile paylasilir.

/**
 * Liste ve InfoBox sorgularinin ortak WHERE blogunu uretir.
 * Iki hesap ayni filtreleri kullanmazsa tablo toplami ile kutular birbirini tutmuyordu.
 *
 * @param string $cariAlias Sorgudaki Cari tablosu alias'i
 * @param array  $params    Sorgu parametreleri (referansla doldurulur)
 */
function odemeFiltreSql(array $post, string $cariAlias, array &$params): string
{
    $sql = '';

    $cariAra = trim($post['cari_ara'] ?? '');
    if ($cariAra !== '') {
        $sql .= " AND ($cariAlias.cari_adi LIKE ? OR $cariAlias.cari_unvan LIKE ?)";
        $params[] = "%$cariAra%";
        $params[] = "%$cariAra%";
    }

    // Listede gosterilen personel = carinin SON teklifini hazirlayan kisi.
    // Filtre de ayni mantigi kullanmali, aksi halde ekranda gorunen adla
    // filtre sonucu ortusmez.
    $personelId = (int)($post['personel_id'] ?? 0);
    if ($personelId > 0) {
        $sql .= " AND (
            SELECT TOP 1 tf.Teklifler_hazirlayan_id
            FROM Teklifler tf
            WHERE tf.Teklifler_cari_id = $cariAlias.cari_id AND tf.Durum = 1
            ORDER BY tf.Teklifler_id DESC
        ) = ?";
        $params[] = $personelId;
    }

    // Internet (pazaryeri) satislari VARSAYILAN OLARAK haric tutulur.
    // Tespit cari_tipi_id uzerinden degil, fiili siparis iliskisi uzerinden
    // yapilir: elle acilmis bir cari pazaryerinden siparis verdiginde
    // cari_tipi_id guncellenmiyor, bu yuzden tip alani guvenilir degil.
    if (empty($post['internet_dahil']) || $post['internet_dahil'] === '0') {
        $sql .= " AND NOT EXISTS (
            SELECT 1 FROM PazaryeriSiparisleri ps
            WHERE ps.PazaryeriSiparisleri_Cari_id = $cariAlias.cari_id AND ps.Durum = 1
        )";
    }

    return $sql;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                // Bes kutunun tamami AYNI cari kumesi uzerinden hesaplanir.
                // Onceki surumde her kutu kendi WHERE'ini kuruyordu: yetki
                // kisiti yalnizca borc sorgusuna, cari_aktif yalnizca gelen
                // fatura sorgusuna uygulaniyordu; kutular ne birbirini ne de
                // tablo toplamini tutuyordu.
                $kumeParams = [];
                $kumeWhere  = "c.cari_aktif = 1" . odemeFiltreSql($_POST, 'c', $kumeParams);

                if (!empty($pagePermissions['can_view_own_records'])) {
                    // Yetki kisiti fatura bazliydi; artik cari kumesine uygulanir,
                    // boylece odeme ve gelen fatura kutulari da ayni kapsamda kalir.
                    $kumeWhere .= " AND (" . CariDevirHelper::orDevir(
                        "EXISTS (
                            SELECT 1 FROM FaturalarGiden fgy
                            WHERE fgy.FaturalarGiden_Cari_id = c.cari_id
                              AND fgy.Durum = 1
                              AND fgy.OlusturanKullanici = ?
                        )",
                        (int)$user['kullanici_id'],
                        'c.cari_id'
                    ) . ")";
                    $kumeParams[] = $user['kullanici_id'];
                }

                $kumeSql = "SELECT c.cari_id, c.cari_vergi_no FROM Cari c WHERE $kumeWhere";

                // Giden fatura = cariye kestigimiz fatura (borcun kaynagi).
                // Stoga islenmemis / stok cikisi geri alinmis fatura adette gorunur,
                // tutara girmez.
                $giden = $db->fetchOne("
                    SELECT
                        ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                                        THEN " . FG_TL_TUTAR . " ELSE 0 END), 0) AS tutar,
                        COUNT(*) AS adet,
                        ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                                        THEN " . FG_KURSUZ . " ELSE 0 END), 0) AS kursuz
                    FROM FaturalarGiden fg
                    JOIN ($kumeSql) k ON fg.FaturalarGiden_Cari_id = k.cari_id
                    " . FG_KUR_APPLY . "
                    WHERE fg.Durum = 1
                      AND UPPER(ISNULL(fg.FaturalarGiden_Durum, '')) != 'CANCELLED'
                      -- GİB İptal/İade Portalı iptalinde zarf durumu SUCCEED kalır (bkz. odeme-form.php)
                      AND ISNULL(fg.FaturalarGiden_HariciIptal, 0) = 0
                ", $kumeParams);

                // Gelen fatura = cariden bize gelen fatura; kalan bakiyeden dusulur.
                // Cari eslesmesi id ile degil VKN uzerinden kurulur (bkz. FaturaKur.php).
                $gelen = $db->fetchOne("
                    SELECT
                        ISNULL(SUM(CASE WHEN ISNULL(fge.FaturalarGelen_StokIslendi, 0) = 1
                                        THEN " . FGE_TL_TUTAR . " ELSE 0 END), 0) AS tutar,
                        COUNT(*) AS adet,
                        ISNULL(SUM(CASE WHEN ISNULL(fge.FaturalarGelen_StokIslendi, 0) = 1
                                        THEN " . FGE_KURSUZ . " ELSE 0 END), 0) AS kursuz
                    FROM FaturalarGelen fge
                    JOIN ($kumeSql) k ON fge.FaturalarGelen_VKN_TCKN = NULLIF(k.cari_vergi_no, '')
                    " . FGE_KUR_APPLY . "
                    WHERE fge.Durum = 1
                ", $kumeParams);

                // Odeme kalemleri tek sorguda ayrilir:
                //   Odeme_Kalemleri_odendi = 1 -> tahsil edilmis (Gelen Odeme)
                //   Odeme_Kalemleri_odendi = 0 -> henuz tahsil edilmemis taahhut (Beklenen)
                $odeme = $db->fetchOne("
                    SELECT
                        ISNULL(SUM(CASE WHEN ok.Odeme_Kalemleri_odendi = 1
                                        THEN ok.Odeme_Kalemleri_tutar ELSE 0 END), 0) AS gelen_odeme,
                        SUM(CASE WHEN ok.Odeme_Kalemleri_odendi = 1 THEN 1 ELSE 0 END) AS gelen_adet,
                        ISNULL(SUM(CASE WHEN ISNULL(ok.Odeme_Kalemleri_odendi, 0) = 0
                                        THEN ok.Odeme_Kalemleri_tutar ELSE 0 END), 0) AS beklenen,
                        SUM(CASE WHEN ISNULL(ok.Odeme_Kalemleri_odendi, 0) = 0 THEN 1 ELSE 0 END) AS beklenen_adet,
                        ISNULL(SUM(CASE WHEN ISNULL(ok.Odeme_Kalemleri_odendi, 0) = 0
                                         AND ok.Odeme_Kalemleri_vade IS NOT NULL
                                         AND ok.Odeme_Kalemleri_vade < CAST(GETDATE() AS DATE)
                                        THEN ok.Odeme_Kalemleri_tutar ELSE 0 END), 0) AS geciken,
                        SUM(CASE WHEN ISNULL(ok.Odeme_Kalemleri_odendi, 0) = 0
                                  AND ok.Odeme_Kalemleri_vade IS NOT NULL
                                  AND ok.Odeme_Kalemleri_vade < CAST(GETDATE() AS DATE)
                                 THEN 1 ELSE 0 END) AS geciken_adet
                    FROM Odeme_Kalemleri ok
                    JOIN Odemeler o ON ok.Odeme_Kalemleri_odeme_id = o.Odemeler_id
                    JOIN ($kumeSql) k ON o.Odemeler_cari_id = k.cari_id
                    WHERE ok.Durum = 1 AND o.Durum = 1
                ", $kumeParams);

                $gidenTutar = (float)($giden['tutar'] ?? 0);
                $gelenTutar = (float)($gelen['tutar'] ?? 0);
                $gelenOdeme = (float)($odeme['gelen_odeme'] ?? 0);
                // KALAN, diger kutularin aksine toplamlardan TUREMEZ; cari bazinda
                // hesaplanip toplanir. Sebebi SINIRLI MAHSUP kuralidir: gelen fatura
                // kalan borctan dusulur ama o carinin borcunu ASAMAZ. Toplam uzerinden
                // mahsup yapilsaydi tek bir buyuk tedarikcinin alis faturalari (or.
                // 56 milyon TL) tum musteri alacagini silerdi.
                //   brut = giden - tahsil edilen
                //   brut <= 0          -> fazla tahsilat, oldugu gibi (eksi kalir)
                //   gelen >= brut      -> kalan 0
                //   aksi halde         -> brut - gelen
                $kalanSatir = $db->fetchOne("
                    SELECT
                        ISNULL(SUM(CASE WHEN x.brut <= 0 THEN x.brut
                                        WHEN x.gelen >= x.brut THEN 0
                                        ELSE x.brut - x.gelen END), 0) AS kalan,
                        ISNULL(SUM(CASE WHEN x.brut <= 0 THEN 0
                                        WHEN x.gelen >= x.brut THEN x.brut
                                        ELSE x.gelen END), 0) AS mahsup
                    FROM (
                        SELECT
                            ISNULL(gd.tutar, 0) - ISNULL(od2.odenen, 0) AS brut,
                            ISNULL(gl.tutar, 0) AS gelen
                        FROM ($kumeSql) k
                        OUTER APPLY (
                            SELECT ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                                                   THEN " . FG_TL_TUTAR . " ELSE 0 END), 0) AS tutar
                            FROM FaturalarGiden fg
                            " . FG_KUR_APPLY . "
                            WHERE fg.FaturalarGiden_Cari_id = k.cari_id
                              AND fg.Durum = 1
                              AND UPPER(ISNULL(fg.FaturalarGiden_Durum, '')) != 'CANCELLED'
                              AND ISNULL(fg.FaturalarGiden_HariciIptal, 0) = 0
                        ) gd
                        OUTER APPLY (
                            SELECT ISNULL(SUM(ok.Odeme_Kalemleri_tutar), 0) AS odenen
                            FROM Odemeler o
                            JOIN Odeme_Kalemleri ok ON ok.Odeme_Kalemleri_odeme_id = o.Odemeler_id
                            WHERE o.Odemeler_cari_id = k.cari_id
                              AND o.Durum = 1 AND ok.Durum = 1
                              AND ok.Odeme_Kalemleri_odendi = 1
                        ) od2
                        OUTER APPLY (
                            SELECT ISNULL(SUM(CASE WHEN ISNULL(fge.FaturalarGelen_StokIslendi, 0) = 1
                                                   THEN " . FGE_TL_TUTAR . " ELSE 0 END), 0) AS tutar
                            FROM FaturalarGelen fge
                            " . FGE_KUR_APPLY . "
                            WHERE fge.FaturalarGelen_VKN_TCKN = NULLIF(k.cari_vergi_no, '')
                              AND fge.Durum = 1
                        ) gl
                    ) x
                ", $kumeParams);

                $kalan  = (float)($kalanSatir['kalan'] ?? 0);
                $mahsup = (float)($kalanSatir['mahsup'] ?? 0);

                echo json_encode(['success' => true, 'data' => [
                    'gelen_fatura'        => number_format($gelenTutar, 2, ',', '.'),
                    'gelen_fatura_adet'   => (int)($gelen['adet'] ?? 0),
                    'gelen_fatura_kursuz' => (int)($gelen['kursuz'] ?? 0),
                    'giden_fatura'        => number_format($gidenTutar, 2, ',', '.'),
                    'giden_fatura_adet'   => (int)($giden['adet'] ?? 0),
                    'giden_fatura_kursuz' => (int)($giden['kursuz'] ?? 0),
                    'beklenen'            => number_format((float)($odeme['beklenen'] ?? 0), 2, ',', '.'),
                    'beklenen_adet'       => (int)($odeme['beklenen_adet'] ?? 0),
                    'geciken'             => number_format((float)($odeme['geciken'] ?? 0), 2, ',', '.'),
                    'geciken_adet'        => (int)($odeme['geciken_adet'] ?? 0),
                    'gelen_odeme'         => number_format($gelenOdeme, 2, ',', '.'),
                    'gelen_odeme_adet'    => (int)($odeme['gelen_adet'] ?? 0),
                    'kalan'               => number_format($kalan, 2, ',', '.'),
                    'kalan_ham'           => $kalan,
                    'mahsup'              => number_format($mahsup, 2, ',', '.'),
                    'mahsup_ham'          => $mahsup,
                ]]);
                break;

            case 'list':
                $params = [];

                $sql = "
                    SELECT
                        c.cari_id,
                        ISNULL(c.cari_unvan, c.cari_adi) AS cari_adi,
                        c.cari_telefon,
                        COUNT(DISTINCT fg.FaturalarGiden_id) AS fatura_sayisi,
                        -- DISTINCT KULLANILMAZ: aynı tutarlı iki farklı fatura tek sayılıyordu.
                        -- Ödeme toplamı OUTER APPLY ile ayrı hesaplandığı için satır çoğullaması
                        -- yoktur, düz SUM doğru sonucu verir.
                        -- Tutarlar TL karşılığına çevrilerek toplanır (bkz. FG_TL_TUTAR).
                        -- Stoga islenmemis fatura sayida gorunur ama borca yazilmaz.
                        ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                                        THEN " . FG_TL_TUTAR . " ELSE 0 END), 0) AS toplam_borc,
                        ISNULL(od_agg.toplam_odenen, 0) AS toplam_odenen,
                        -- Gelen fatura KALAN icinde SINIRLI mahsup edilir (bkz. dis SELECT).
                        ISNULL(gel_agg.toplam_gelen, 0) AS toplam_gelen,
                        ISNULL(gel_agg.gelen_sayisi, 0) AS gelen_fatura_sayisi,
                        ISNULL(gel_agg.kursuz_gelen, 0) AS kursuz_gelen,
                        ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                                        THEN " . FG_TL_TUTAR . " ELSE 0 END), 0)
                            - ISNULL(od_agg.toplam_odenen, 0) AS brut_kalan,
                        ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                                        THEN " . FG_KURSUZ . " ELSE 0 END), 0) AS kursuz_fatura,
                        MAX(pers.personel_adi) AS personel_adi,
                        ISNULL(pz.var_mi, 0) AS internet_satis,
                        pz.kanal_adi AS kanal_adi
                    FROM Cari c
                    LEFT JOIN FaturalarGiden fg ON fg.FaturalarGiden_Cari_id = c.cari_id AND fg.Durum = 1 AND UPPER(ISNULL(fg.FaturalarGiden_Durum, '')) != 'CANCELLED' AND ISNULL(fg.FaturalarGiden_HariciIptal, 0) = 0
                    " . FG_KUR_APPLY . "
                    OUTER APPLY (
                        -- Odeme_Kalemleri_odendi = 1 filtresi ZORUNLU: bu kolon isaretli degilse
                        -- kalem henuz tahsil edilmemis bir odeme taahhududur. Filtre olmadan
                        -- taahhutler de odenmis sayiliyor ve Kalan oldugundan dusuk gorunuyordu.
                        -- InfoBox (stats) zaten bu filtreyi kullaniyordu; iki hesap artik tutarli.
                        SELECT ISNULL(SUM(ok.Odeme_Kalemleri_tutar), 0) AS toplam_odenen
                        FROM Odemeler o
                        JOIN Odeme_Kalemleri ok ON ok.Odeme_Kalemleri_odeme_id = o.Odemeler_id
                        WHERE o.Odemeler_cari_id = c.cari_id
                          AND o.Durum = 1
                          AND ok.Durum = 1
                          AND ok.Odeme_Kalemleri_odendi = 1
                    ) od_agg
                    OUTER APPLY (
                        -- Gelen fatura cariye id ile degil VKN uzerinden baglidir.
                        -- NULLIF zorunlu: vergi no'su bos olan cariler aksi halde
                        -- birbirinin faturalarini ustlenirdi.
                        -- Stoga islenmemis / stok girisi geri alinmis fatura sayida gorunur
                        -- ama toplam_gelen'e girmez; boylece kalan bakiyeyi dusurmez.
                        SELECT COUNT(*) AS gelen_sayisi,
                               ISNULL(SUM(CASE WHEN ISNULL(fge.FaturalarGelen_StokIslendi, 0) = 1
                                               THEN " . FGE_TL_TUTAR . " ELSE 0 END), 0) AS toplam_gelen,
                               ISNULL(SUM(CASE WHEN ISNULL(fge.FaturalarGelen_StokIslendi, 0) = 1
                                               THEN " . FGE_KURSUZ . " ELSE 0 END), 0) AS kursuz_gelen
                        FROM FaturalarGelen fge
                        " . FGE_KUR_APPLY . "
                        WHERE fge.FaturalarGelen_VKN_TCKN = NULLIF(c.cari_vergi_no, '')
                          AND fge.Durum = 1
                    ) gel_agg
                    OUTER APPLY (
                        SELECT TOP 1 k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi
                        FROM Teklifler t2
                        JOIN kullanicilar k ON t2.Teklifler_hazirlayan_id = k.kullanici_id
                        WHERE t2.Teklifler_cari_id = c.cari_id AND t2.Durum = 1
                        ORDER BY t2.Teklifler_id DESC
                    ) pers
                    OUTER APPLY (
                        -- Internet satisi rozeti icin: carinin bagli oldugu pazaryeri kanali
                        SELECT TOP 1 1 AS var_mi, p.Pazaryerleri_Ad AS kanal_adi
                        FROM PazaryeriSiparisleri ps
                        INNER JOIN tanim_Pazaryerleri p ON p.Pazaryerleri_id = ps.Pazaryerleri_id
                        WHERE ps.PazaryeriSiparisleri_Cari_id = c.cari_id AND ps.Durum = 1
                        ORDER BY ps.PazaryeriSiparisleri_id DESC
                    ) pz
                    WHERE c.cari_aktif = 1
                    AND (
                        fg.FaturalarGiden_id IS NOT NULL
                        OR EXISTS (SELECT 1 FROM Odemeler o WHERE o.Odemeler_cari_id = c.cari_id AND o.Durum = 1)
                        OR ISNULL(gel_agg.gelen_sayisi, 0) > 0
                    )
                ";

                $sql .= odemeFiltreSql($_POST, 'c', $params);

                $sql .= " GROUP BY c.cari_id, c.cari_unvan, c.cari_adi, c.cari_telefon, od_agg.toplam_odenen,
                          gel_agg.toplam_gelen, gel_agg.gelen_sayisi, gel_agg.kursuz_gelen,
                          pz.var_mi, pz.kanal_adi";

                // SINIRLI MAHSUP: gelen fatura kalan borctan dusulur ama borcu ASAMAZ.
                // Sinirsiz mahsupta tek bir buyuk tedarikci (or. 56 milyon TL alis) tum
                // musteri alacagini silip toplami eksiye cekiyordu. Mahsup cari bazinda
                // yapilir; toplam uzerinden yapilamaz (bkz. stats).
                //   brut_kalan <= 0            -> fazla tahsilat, oldugu gibi kalir
                //   gelen >= brut_kalan        -> borc tamamen mahsuplu, kalan 0
                //   aksi halde                 -> brut_kalan - gelen
                $sql = "SELECT t.*,
                            CASE WHEN t.brut_kalan <= 0 THEN 0
                                 WHEN t.toplam_gelen >= t.brut_kalan THEN t.brut_kalan
                                 ELSE t.toplam_gelen END AS mahsup_edilen,
                            CASE WHEN t.brut_kalan <= 0 THEN t.brut_kalan
                                 WHEN t.toplam_gelen >= t.brut_kalan THEN 0
                                 ELSE t.brut_kalan - t.toplam_gelen END AS kalan
                        FROM ($sql) t
                        ORDER BY kalan DESC";
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;


            default:
                throw new Exception('Geçersiz işlem.');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$kullanicilar = $db->fetchAll("SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad AS kullanici_adi FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        /* Tutar kutulari: inline font-size yerine tek nokta */
        .info-box .infobox-tutar { font-size: 1.05rem; font-weight: 600; }
        .info-box .progress-description { font-size: .75rem; opacity: .85; }
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
                    <div class="col-sm-6 col-xl mb-3">
                        <div class="info-box text-bg-secondary h-100">
                            <span class="info-box-icon"><i class="bi bi-box-arrow-in-down"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text" title="Cariden bize gelen faturalar; Kalan hesabında mahsup edilir (borcu aşan kısmı düşülmez)">Gelen Fatura</span>
                                <span class="info-box-number infobox-tutar" id="stat_gelen_fatura">0,00 &#8378;</span>
                                <span class="progress-description" id="stat_gelen_fatura_alt">&nbsp;</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl mb-3">
                        <div class="info-box text-bg-danger h-100">
                            <span class="info-box-icon"><i class="bi bi-receipt-cutoff"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text" title="Cariye kestiğimiz faturalar; borcun kaynağı">Giden Fatura</span>
                                <span class="info-box-number infobox-tutar" id="stat_giden_fatura">0,00 &#8378;</span>
                                <span class="progress-description" id="stat_giden_fatura_alt">&nbsp;</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl mb-3">
                        <div class="info-box text-bg-warning h-100">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text" title="Ödendi işaretlenmemiş ödeme kalemleri (tahsil edilmemiş taahhüt)">Beklenen Ödemeler</span>
                                <span class="info-box-number infobox-tutar" id="stat_beklenen">0,00 &#8378;</span>
                                <span class="progress-description" id="stat_beklenen_alt">&nbsp;</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl mb-3">
                        <div class="info-box text-bg-success h-100">
                            <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text" title="Ödendi işaretli, fiilen tahsil edilmiş ödeme kalemleri">Gelen Ödeme</span>
                                <span class="info-box-number infobox-tutar" id="stat_gelen_odeme">0,00 &#8378;</span>
                                <span class="progress-description" id="stat_gelen_odeme_alt">&nbsp;</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl mb-3">
                        <div class="info-box text-bg-primary h-100">
                            <span class="info-box-icon"><i class="bi bi-wallet2"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text" title="Giden Fatura - Tahsil Edilen - Gelen Fatura (mahsup borcu aşamaz, cari bazında hesaplanır)">Kalan Borç</span>
                                <span class="info-box-number infobox-tutar" id="stat_kalan">0,00 &#8378;</span>
                                <span class="progress-description" id="stat_kalan_alt">&nbsp;</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse"><i class="bi bi-chevron-down"></i></button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="filterCollapse">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Cari Ara</label>
                                    <input type="text" class="form-control" id="filter_cari_ara" name="cari_ara" placeholder="Firma adı veya ünvanı...">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Personel</label>
                                    <select class="form-select select2" id="filter_personel_id" name="personel_id">
                                        <option value="">Tümü</option>
                                        <?php foreach($kullanicilar as $k): ?>
                                        <option value="<?= $k['kullanici_id'] ?>"><?= htmlspecialchars($k['kullanici_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Satış Kanalı</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="filter_internet_dahil" name="internet_dahil" value="1">
                                        <label class="form-check-label" for="filter_internet_dahil" title="İşaretlenmezse pazaryeri (internet) satışı olan cariler listelenmez">
                                            <i class="bi bi-globe"></i> İnternet satışları dahil
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-2 d-flex align-items-end gap-2">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Ara</button>
                                    <button type="button" class="btn btn-secondary w-100" id="clearFilters"><i class="bi bi-x"></i></button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-list-ul"></i> Cari Bakiye Listesi</h3>
                        <div class="card-tools d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-sm btn-success" onclick="exportExcel()" title="Mevcut filtrelenmiş veriyi Excel olarak indir">
                                <i class="bi bi-file-earmark-excel"></i> Excel İndir
                            </button>
                            <?php if ($pagePermissions['can_add']): ?>
                            <a href="/Admin/odeme-form" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Yeni Ödeme</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="dataTable" class="table table-bordered table-striped table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Firma</th>
                                        <th>Telefon</th>
                                        <th class="text-center">Fatura Sayısı</th>
                                        <th class="text-end">Giden Fatura</th>
                                        <th class="text-end">Ödenen</th>
                                        <th class="text-end">Gelen Fatura</th>
                                        <th class="text-end">Kalan</th>
                                        <th>Personel</th>
                                        <th class="text-center">İşlemler</th>
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
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
let table;
let currentFilters = {};

function fmt(v) {
    return parseFloat(v || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ₺';
}

$(document).ready(function() {
    $('#filter_personel_id').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'Seçiniz...' });

    table = $('#dataTable').DataTable({
        processing: true,
        ajax: {
            url: window.location.href, type: 'POST',
            data: () => ({ action: 'list', ...currentFilters }),
            dataSrc: function(json) {
                if (!json.success) { return []; }
                return json.data || [];
            },
            error: function(xhr, status, err) {
                console.error('DataTable AJAX hatası:', status, err);
            }
        },
        columns: [
            {
                data: 'cari_adi',
                render: function(d, t, row) {
                    if (t !== 'display') return d;
                    let html = d;
                    if (parseInt(row.internet_satis || 0) === 1) {
                        const kanal = row.kanal_adi ? ' — ' + row.kanal_adi : '';
                        html += ` <span class="badge bg-success ms-1" title="İnternet üzerinden satış${kanal}"><i class="bi bi-globe"></i> İnternet</span>`;
                    }
                    // Kuru bulunamayan döviz faturaları TL kabul edilir; bakiye eksik görünür.
                    const kursuz = parseInt(row.kursuz_fatura || 0);
                    if (kursuz > 0) {
                        html += ` <span class="badge bg-warning text-dark ms-1" title="${kursuz} adet döviz faturasının kuru bulunamadı, tutar TL kabul edildi"><i class="bi bi-exclamation-triangle"></i> Kur yok</span>`;
                    }
                    return html;
                }
            },
            { data: 'cari_telefon', render: d => d || '-' },
            { data: 'fatura_sayisi', className: 'text-center' },
            { data: 'toplam_borc', className: 'text-end', render: d => fmt(d) },
            { data: 'toplam_odenen', className: 'text-end', render: d => fmt(d) },
            {
                data: 'toplam_gelen', className: 'text-end',
                render: function(d, t, row) {
                    if (t !== 'display') return parseFloat(d || 0);
                    const adet = parseInt(row.gelen_fatura_sayisi || 0);
                    if (!adet) return '-';
                    let html = `<span title="${adet} adet gelen fatura; mahsup edilen: ${fmt(row.mahsup_edilen)} &#8378;">${fmt(d)}</span>`;
                    const kursuz = parseInt(row.kursuz_gelen || 0);
                    if (kursuz > 0) {
                        html += ` <span class="badge bg-warning text-dark" title="${kursuz} adet gelen döviz faturasının kuru bulunamadı, tutar TL kabul edildi"><i class="bi bi-exclamation-triangle"></i></span>`;
                    }
                    return html;
                }
            },
            {
                data: 'kalan', className: 'text-end fw-bold',
                render: function(d) {
                    const r = parseFloat(d || 0);
                    const cls = r > 0 ? 'text-danger' : 'text-success';
                    return `<span class="${cls}">${fmt(r)}</span>`;
                }
            },
            { data: 'personel_adi', render: d => d ? `<span class="text-muted">${d}</span>` : '-' },
            {
                data: null, orderable: false, className: 'text-center text-nowrap',
                render: function(d) {
                    let btns = `<a href="/Admin/odeme-form?cari_id=${d.cari_id}" class="btn btn-sm btn-primary me-1" title="Ödeme Ekle"><i class="bi bi-plus-circle"></i></a>`;
                    btns += `<a href="/Admin/odeme-form?cari_id=${d.cari_id}&readonly=1" class="btn btn-sm btn-info" title="Ödeme Geçmişi"><i class="bi bi-clock-history"></i></a>`;
                    return btns;
                }
            }
        ],
        order: [[6, 'desc']],
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
    });

    loadStats();

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentFilters = {
            cari_ara: $('#filter_cari_ara').val(),
            personel_id: $('#filter_personel_id').val(),
            internet_dahil: $('#filter_internet_dahil').is(':checked') ? '1' : '0'
        };
        table.ajax.reload();
        loadStats();
    });

    $('#clearFilters').on('click', function() {
        $('#filterForm')[0].reset();
        $('#filter_personel_id').val('').trigger('change');
        $('#filter_internet_dahil').prop('checked', false);
        currentFilters = {};
        table.ajax.reload();
        loadStats();
    });
});

function loadStats() {
    // Kutular tablo ile AYNI filtreyi kullanir; aksi halde kutu toplamlari
    // liste toplamini tutmuyordu.
    $.post('', Object.assign({ action: 'stats' }, currentFilters), function(res) {
        if (!res.success) {
            showToast(res.message || 'Özet bilgiler yüklenemedi.', 'error');
            return;
        }
        const d = res.data;

        $('#stat_gelen_fatura').text(d.gelen_fatura + ' \u20ba');
        $('#stat_gelen_fatura_alt').html(statAlt(d.gelen_fatura_adet + ' fatura', d.gelen_fatura_kursuz));

        $('#stat_giden_fatura').text(d.giden_fatura + ' \u20ba');
        $('#stat_giden_fatura_alt').html(statAlt(d.giden_fatura_adet + ' fatura', d.giden_fatura_kursuz));

        $('#stat_beklenen').text(d.beklenen + ' \u20ba');
        let bekAlt = d.beklenen_adet + ' kalem';
        if (parseInt(d.geciken_adet || 0) > 0) {
            bekAlt += ' &middot; <span title="Vadesi geçmiş, tahsil edilmemiş">'
                    + d.geciken_adet + ' vadesi geçti (' + d.geciken + ' \u20ba)</span>';
        }
        $('#stat_beklenen_alt').html(bekAlt);

        $('#stat_gelen_odeme').text(d.gelen_odeme + ' \u20ba');
        $('#stat_gelen_odeme_alt').text(d.gelen_odeme_adet + ' kalem');

        $('#stat_kalan').text(d.kalan + ' \u20ba');
        var kalanAlt = parseFloat(d.kalan_ham || 0) < 0
            ? "Fazla tahsilat"
            : "Giden Fatura - Tahsil Edilen - Gelen Fatura";
        if (parseFloat(d.mahsup_ham || 0) > 0) {
            kalanAlt += " (" + d.mahsup + " ₺ mahsup)";
        }
        $("#stat_kalan_alt").text(kalanAlt);
    }).fail(function() {
        showToast('Özet bilgiler yüklenirken bağlantı hatası oluştu.', 'error');
    });
}

// Kutu altindaki aciklama satiri; kuru cozulemeyen doviz faturasi varsa uyari ekler.
function statAlt(metin, kursuz) {
    kursuz = parseInt(kursuz || 0);
    if (kursuz > 0) {
        metin += ' &middot; <span title="' + kursuz + ' adet döviz faturasının kuru bulunamadı, tutar TL kabul edildi">'
               + '<i class="bi bi-exclamation-triangle"></i> ' + kursuz + ' kur yok</span>';
    }
    return metin;
}

function exportExcel() {
    var rows = table.rows({ search: 'applied' }).data().toArray();
    if (rows.length === 0) {
        showToast('Dışa aktarılacak veri bulunamadı.', 'warning');
        return;
    }

    var headers = ['Firma', 'Telefon', 'Fatura Sayısı', 'Giden Fatura', 'Ödenen', 'Gelen Fatura Sayısı', 'Gelen Fatura Tutarı', 'Kalan Borç', 'Personel', 'Satış Kanalı'];

    var data = rows.map(function(row) {
        return [
            row.cari_adi || '',
            row.cari_telefon || '',
            parseInt(row.fatura_sayisi || 0),
            parseFloat(row.toplam_borc || 0),
            parseFloat(row.toplam_odenen || 0),
            parseInt(row.gelen_fatura_sayisi || 0),
            parseFloat(row.toplam_gelen || 0),
            parseFloat(row.kalan || 0),
            row.personel_adi || '',
            parseInt(row.internet_satis || 0) === 1 ? ('İnternet' + (row.kanal_adi ? ' — ' + row.kanal_adi : '')) : 'Doğrudan Satış'
        ];
    });

    var wsData = [headers].concat(data);
    var ws = XLSX.utils.aoa_to_sheet(wsData);

    // Para kolonları için Türkçe sayı biçimi
    var fmt = '#,##0.00';
    for (var i = 2; i <= data.length + 1; i++) {
        ['D', 'E', 'G', 'H'].forEach(function(col) {
            var cell = ws[col + i];
            if (cell) cell.z = fmt;
        });
    }

    ws['!cols'] = [
        {wch:35},{wch:16},{wch:12},{wch:16},{wch:16},{wch:18},{wch:18},{wch:16},{wch:22},{wch:24}
    ];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Ödeme Yönetimi');

    var tarih = new Date().toLocaleDateString('tr-TR').replace(/\./g, '-');
    XLSX.writeFile(wb, 'Odeme-Yonetimi-' + tarih + '.xlsx');
    showToast(rows.length + ' kayıt Excel olarak indirildi.', 'success');
}
</script>
</body>
</html>
