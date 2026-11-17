<?php
/**
 * Admin Panel - Tahsilat Ajandası
 *
 * Odeme_Kalemleri uzerinden aylik takvim:
 *   Beklenen     -> Odeme_Kalemleri_vade gunune gore (tum kalemler)
 *   Tahsil       -> Odeme_Kalemleri_odendi = 1, Odeme_Kalemleri_odeme_tar gunune gore
 *   Acik         -> Odeme_Kalemleri_odendi = 0, vade gunune gore
 * Kapsam ve filtreler odeme-yonetimi.php ile aynidir; iki sayfanin rakamlari birbirini tutar.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo     = $db->fetchOne("SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi as menu_adi FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1", ['%' . $currentPageFile]);
$pageTitle    = $pageInfo['sayfalar_sayfa_adi'] ?? 'Tahsilat Ajandası';
$menuAdi      = $pageInfo['menu_adi'] ?? null;
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// "Bugun" DB saatinden alinir; odeme-yonetimi.php geciken hesabini GETDATE() ile yapiyor.
$bugunRow = $db->fetchOne("SELECT CONVERT(VARCHAR(10), GETDATE(), 23) AS bugun");
$bugun    = $bugunRow['bugun'] ?? date('Y-m-d');

/**
 * Ortak FROM + WHERE blogu. Kapsam odeme-yonetimi.php ile aynidir:
 * aktif cari, varsayilan olarak internet (pazaryeri) carileri haric,
 * personel = carinin son teklifini hazirlayan, can_view_own_records -> kendi faturasi olan cariler.
 */
function ajandaKapsamSql(array $post, array $pagePermissions, array $user, array &$params): string
{
    $sql = "
        FROM Odeme_Kalemleri ok
        JOIN Odemeler o ON ok.Odeme_Kalemleri_odeme_id = o.Odemeler_id
        JOIN Cari c ON o.Odemeler_cari_id = c.cari_id
        WHERE ok.Durum = 1 AND o.Durum = 1 AND c.cari_aktif = 1";

    $cariAra = trim($post['cari_ara'] ?? '');
    if ($cariAra !== '') {
        $sql .= " AND (c.cari_adi LIKE ? OR c.cari_unvan LIKE ?)";
        $params[] = "%$cariAra%";
        $params[] = "%$cariAra%";
    }

    $personelId = (int)($post['personel_id'] ?? 0);
    if ($personelId > 0) {
        $sql .= " AND (
            SELECT TOP 1 tf.Teklifler_hazirlayan_id
            FROM Teklifler tf
            WHERE tf.Teklifler_cari_id = c.cari_id AND tf.Durum = 1
            ORDER BY tf.Teklifler_id DESC
        ) = ?";
        $params[] = $personelId;
    }

    $odemeTipi = trim($post['odeme_tipi'] ?? '');
    if ($odemeTipi !== '') {
        $sql .= " AND ok.Odeme_Kalemleri_tip = ?";
        $params[] = $odemeTipi;
    }

    if (empty($post['internet_dahil']) || $post['internet_dahil'] === '0') {
        $sql .= " AND NOT EXISTS (
            SELECT 1 FROM PazaryeriSiparisleri ps
            WHERE ps.PazaryeriSiparisleri_Cari_id = c.cari_id AND ps.Durum = 1
        )";
    }

    if (!empty($pagePermissions['can_view_own_records'])) {
        $sql .= " AND (" . CariDevirHelper::orDevir(
            "EXISTS (
                SELECT 1 FROM FaturalarGiden fgy
                WHERE fgy.FaturalarGiden_Cari_id = c.cari_id
                  AND fgy.Durum = 1
                  AND fgy.OlusturanKullanici = ?
            )",
            (int)$user['kullanici_id'],
            'c.cari_id'
        ) . ")";
        $params[] = $user['kullanici_id'];
    }

    return $sql;
}

/** Yil/ay -> [ayBas, sonrakiAyBas] (indeks dostu aralik karsilastirmasi icin) */
function ajandaAyAraligi(array $post, string $bugun): array
{
    $yil = (int)($post['yil'] ?? substr($bugun, 0, 4));
    $ay  = (int)($post['ay'] ?? substr($bugun, 5, 2));
    if ($ay < 1 || $ay > 12) $ay = (int)substr($bugun, 5, 2);
    if ($yil < 2000 || $yil > 2100) $yil = (int)substr($bugun, 0, 4);

    $ayBas = sprintf('%04d-%02d-01', $yil, $ay);
    return [$ayBas, date('Y-m-d', strtotime($ayBas . ' +1 month'))];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ── Aylık takvim verisi ──
            case 'ay_verisi':
                [$ayBas, $sonrakiAyBas] = ajandaAyAraligi($_POST, $bugun);

                $params = [];
                $kapsam = ajandaKapsamSql($_POST, $pagePermissions, $user, $params);

                // Vade bazli: beklenen (tum kalemler) + acik (odenmemis)
                $vadeRows = $db->fetchAll("
                    SELECT DAY(ok.Odeme_Kalemleri_vade) AS gun,
                           ISNULL(SUM(ok.Odeme_Kalemleri_tutar), 0) AS beklenen,
                           COUNT(*) AS beklenen_adet,
                           ISNULL(SUM(CASE WHEN ISNULL(ok.Odeme_Kalemleri_odendi, 0) = 0
                                           THEN ok.Odeme_Kalemleri_tutar ELSE 0 END), 0) AS acik,
                           SUM(CASE WHEN ISNULL(ok.Odeme_Kalemleri_odendi, 0) = 0 THEN 1 ELSE 0 END) AS acik_adet
                    $kapsam
                      AND ok.Odeme_Kalemleri_vade >= ? AND ok.Odeme_Kalemleri_vade < ?
                    GROUP BY DAY(ok.Odeme_Kalemleri_vade)
                ", array_merge($params, [$ayBas, $sonrakiAyBas]));

                // Tahsil tarihi bazli: gerceklesen
                $tahsilRows = $db->fetchAll("
                    SELECT DAY(ok.Odeme_Kalemleri_odeme_tar) AS gun,
                           ISNULL(SUM(ok.Odeme_Kalemleri_tutar), 0) AS tutar,
                           COUNT(*) AS adet
                    $kapsam
                      AND ok.Odeme_Kalemleri_odendi = 1
                      AND ok.Odeme_Kalemleri_odeme_tar >= ? AND ok.Odeme_Kalemleri_odeme_tar < ?
                    GROUP BY DAY(ok.Odeme_Kalemleri_odeme_tar)
                ", array_merge($params, [$ayBas, $sonrakiAyBas]));

                $gunler = [];
                $bosGun = ['beklenen' => 0, 'beklenen_adet' => 0, 'gerceklesen' => 0, 'gerceklesen_adet' => 0, 'acik' => 0, 'acik_adet' => 0];
                foreach ($vadeRows as $r) {
                    $g = (int)$r['gun'];
                    $gunler[$g] = $gunler[$g] ?? $bosGun;
                    $gunler[$g]['beklenen']      = (float)$r['beklenen'];
                    $gunler[$g]['beklenen_adet'] = (int)$r['beklenen_adet'];
                    $gunler[$g]['acik']          = (float)$r['acik'];
                    $gunler[$g]['acik_adet']     = (int)$r['acik_adet'];
                }
                foreach ($tahsilRows as $r) {
                    $g = (int)$r['gun'];
                    $gunler[$g] = $gunler[$g] ?? $bosGun;
                    $gunler[$g]['gerceklesen']      = (float)$r['tutar'];
                    $gunler[$g]['gerceklesen_adet'] = (int)$r['adet'];
                }

                $toplamBeklenen    = array_sum(array_column($gunler, 'beklenen'));
                $toplamGerceklesen = array_sum(array_column($gunler, 'gerceklesen'));
                $toplamAcik        = array_sum(array_column($gunler, 'acik'));

                echo json_encode([
                    'success' => true,
                    'gunler'  => (object)$gunler,
                    'ozet'    => [
                        'beklenen'    => $toplamBeklenen,
                        'gerceklesen' => $toplamGerceklesen,
                        'acik'        => $toplamAcik,
                        'oran'        => $toplamBeklenen > 0 ? round(($toplamGerceklesen / $toplamBeklenen) * 100, 1) : 0
                    ]
                ]);
                break;

            // ── Üst özet: gerçek bugüne göre bekleyen tahsilat (filtreye bağlı, aya bağlı değil) ──
            case 'ust_ozet':
                $haftaBas = date('Y-m-d', strtotime($bugun . ' monday this week'));
                $haftaSon = date('Y-m-d', strtotime($bugun . ' sunday this week'));
                $ayBas    = substr($bugun, 0, 8) . '01';
                $aySon    = date('Y-m-t', strtotime($bugun));

                $params = [];
                $kapsam = ajandaKapsamSql($_POST, $pagePermissions, $user, $params);

                $row = $db->fetchOne("
                    SELECT
                        ISNULL(SUM(CASE WHEN CONVERT(DATE, ok.Odeme_Kalemleri_vade) = ? THEN ok.Odeme_Kalemleri_tutar END), 0) AS bugun,
                        ISNULL(SUM(CASE WHEN CONVERT(DATE, ok.Odeme_Kalemleri_vade) BETWEEN ? AND ? THEN ok.Odeme_Kalemleri_tutar END), 0) AS hafta,
                        ISNULL(SUM(CASE WHEN CONVERT(DATE, ok.Odeme_Kalemleri_vade) BETWEEN ? AND ? THEN ok.Odeme_Kalemleri_tutar END), 0) AS ay,
                        ISNULL(SUM(CASE WHEN CONVERT(DATE, ok.Odeme_Kalemleri_vade) < ? THEN ok.Odeme_Kalemleri_tutar END), 0) AS geciken
                    $kapsam
                      AND ISNULL(ok.Odeme_Kalemleri_odendi, 0) = 0
                      AND ok.Odeme_Kalemleri_vade IS NOT NULL
                ", array_merge([$bugun, $haftaBas, $haftaSon, $ayBas, $aySon, $bugun], $params));

                echo json_encode(['success' => true, 'data' => [
                    'bugun'   => (float)($row['bugun'] ?? 0),
                    'hafta'   => (float)($row['hafta'] ?? 0),
                    'ay'      => (float)($row['ay'] ?? 0),
                    'geciken' => (float)($row['geciken'] ?? 0)
                ]]);
                break;

            // ── Gün detayı / Aylık detay (Excel) ──
            case 'gun_detay':
            case 'ay_liste':
                if ($action === 'gun_detay') {
                    $tarih = $_POST['tarih'] ?? '';
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz tarih']);
                        break;
                    }
                    $bas  = $tarih;
                    $son  = date('Y-m-d', strtotime($tarih . ' +1 day'));
                } else {
                    [$bas, $son] = ajandaAyAraligi($_POST, $bugun);
                }

                $params = [];
                $kapsam = ajandaKapsamSql($_POST, $pagePermissions, $user, $params);

                // Vadesi aralikta olan VEYA aralikta tahsil edilen kalemler
                $rows = $db->fetchAll("
                    SELECT
                        ok.Odeme_Kalemleri_id AS kalem_id,
                        c.cari_id,
                        ISNULL(NULLIF(LTRIM(RTRIM(c.cari_unvan)), ''), c.cari_adi) AS cari_adi,
                        ISNULL(t.tanim_OdemeTipleri_Adi, ISNULL(ok.Odeme_Kalemleri_tip, '-')) AS odeme_tipi_ad,
                        ISNULL(ok.Odeme_Kalemleri_belge_no, '') AS belge_no,
                        ISNULL(ok.Odeme_Kalemleri_aciklama, '') AS aciklama,
                        ok.Odeme_Kalemleri_tutar AS tutar,
                        ISNULL(ok.Odeme_Kalemleri_odendi, 0) AS odendi,
                        CONVERT(VARCHAR(10), ok.Odeme_Kalemleri_vade, 23) AS vade,
                        CONVERT(VARCHAR(10), ok.Odeme_Kalemleri_odeme_tar, 23) AS odeme_tar,
                        ISNULL(NULLIF(LTRIM(RTRIM(ISNULL(k.kullanici_ad, '') + ' ' + ISNULL(k.kullanici_soyad, ''))), ''), '-') AS personel_adi,
                        COALESCE(ok.Odeme_Kalemleri_vade, ok.Odeme_Kalemleri_odeme_tar) AS sirala_tarih
                    FROM (SELECT ok.Odeme_Kalemleri_id AS id $kapsam
                            AND (
                                (ok.Odeme_Kalemleri_vade >= ? AND ok.Odeme_Kalemleri_vade < ?)
                                OR (ok.Odeme_Kalemleri_odendi = 1
                                    AND ok.Odeme_Kalemleri_odeme_tar >= ? AND ok.Odeme_Kalemleri_odeme_tar < ?)
                            )
                         ) x
                    JOIN Odeme_Kalemleri ok ON ok.Odeme_Kalemleri_id = x.id
                    JOIN Odemeler o ON ok.Odeme_Kalemleri_odeme_id = o.Odemeler_id
                    JOIN Cari c ON o.Odemeler_cari_id = c.cari_id
                    LEFT JOIN dbo.tanim_OdemeTipleri t ON t.tanim_OdemeTipleri_Kod = ok.Odeme_Kalemleri_tip
                    LEFT JOIN kullanicilar k ON ok.Odeme_Kalemleri_personel_id = k.kullanici_id
                    ORDER BY ISNULL(ok.Odeme_Kalemleri_odendi, 0), sirala_tarih, cari_adi
                ", array_merge($params, [$bas, $son, $bas, $son]));

                foreach ($rows as &$r) {
                    $r['tutar']  = (float)$r['tutar'];
                    $r['odendi'] = (int)$r['odendi'];
                    unset($r['sirala_tarih']);
                    // Tur: aralikta kasaya giren -> tahsil, aksi halde vadesi aralikta olan -> beklenen
                    $tahsilAralikta = $r['odendi'] === 1 && $r['odeme_tar'] !== null && $r['odeme_tar'] >= $bas && $r['odeme_tar'] < $son;
                    $r['tur'] = $tahsilAralikta ? 'tahsil' : 'beklenen';
                    if ($r['odendi'] === 1)              $r['durum'] = 'Ödendi';
                    elseif ($r['vade'] !== null && $r['vade'] < $bugun) $r['durum'] = 'Gecikmiş';
                    else                                 $r['durum'] = 'Bekliyor';
                }
                unset($r);

                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

$kullanicilar = $db->fetchAll("SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad AS kullanici_adi FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad");
$odemeTipleri = $db->fetchAll("SELECT tanim_OdemeTipleri_Kod, tanim_OdemeTipleri_Adi FROM dbo.tanim_OdemeTipleri WHERE Durum = 1 ORDER BY tanim_OdemeTipleri_Sira");
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }

        .ajanda-tablo { table-layout: fixed; width: 100%; }
        .ajanda-tablo th { text-align: center; background-color: #f8f9fa; padding: 8px 4px; font-size: 13px; }
        .ajanda-tablo th.hafta-sonu { color: #dc3545; }
        .ajanda-hucre {
            height: 108px; vertical-align: top; padding: 4px 6px; cursor: pointer;
            transition: background-color 0.15s; position: relative; overflow: hidden;
        }
        .ajanda-hucre:hover { background-color: #eef4ff; }
        .ajanda-hucre.bos { background-color: #fafafa; cursor: default; }
        .ajanda-hucre.bugun { outline: 2px solid #0d6efd; outline-offset: -2px; }
        .ajanda-hucre.hafta-sonu { background-color: #fff8f8; }
        .ajanda-gun-no { font-weight: 600; font-size: 14px; color: #495057; }
        .ajanda-gun-no.bugun-no { color: #0d6efd; }
        .ajanda-satir { display: flex; align-items: center; gap: 4px; font-size: 11.5px; margin-top: 3px; line-height: 1.3; }
        .ajanda-badge { display: inline-block; padding: 1px 5px; border-radius: 4px; font-weight: 600; font-size: 10.5px; }
        .badge-tahsil   { background: #d1e7dd; color: #0f5132; }
        .badge-beklenen { background: #fff3cd; color: #664d03; }
        .badge-acik     { background: #f8d7da; color: #842029; }
        .ajanda-tutar { font-weight: 600; margin-left: auto; text-align: right; white-space: nowrap; }
        .t-yesil { color: #0f5132; } .t-sari { color: #997404; } .t-kirmizi { color: #842029; }
        @media (max-width: 768px) {
            .ajanda-hucre { height: auto; min-height: 70px; }
            .ajanda-satir { font-size: 10px; }
        }
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
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
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

                    <!-- Bekleyen Tahsilat Özeti (bugüne göre) -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calendar-day"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Bekleyen</span>
                                    <span class="info-box-number" id="stat-bekleyen-bugun">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-calendar-week"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Hafta Bekleyen</span>
                                    <span class="info-box-number" id="stat-bekleyen-hafta">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-dark shadow-sm"><i class="bi bi-calendar-month"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ay Bekleyen</span>
                                    <span class="info-box-number" id="stat-bekleyen-ay">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Vadesi Geçmiş (Toplam)</span>
                                    <span class="info-box-number" id="stat-geciken">0 ₺</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Seçili Ay Özeti -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-calendar-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ay Beklenen (Vade)</span>
                                    <span class="info-box-number" id="stat-beklenen">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-cash-stack"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ay Tahsil Edilen</span>
                                    <span class="info-box-number" id="stat-gerceklesen">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ay Açık (Vade)</span>
                                    <span class="info-box-number" id="stat-acik">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-percent"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tahsilat Oranı</span>
                                    <span class="info-box-number" id="stat-oran">%0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCollapse">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">Firma</label>
                                    <input type="text" class="form-control" id="filter_cari_ara" placeholder="Firma adı veya ünvanı...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Satış Personeli</label>
                                    <select class="form-select select2" id="filter_personel_id">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kullanicilar as $k): ?>
                                            <option value="<?= (int)$k['kullanici_id'] ?>"><?= htmlspecialchars($k['kullanici_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Ödeme Tipi</label>
                                    <select class="form-select select2" id="filter_odeme_tipi">
                                        <option value="">Tümü</option>
                                        <?php foreach ($odemeTipleri as $t): ?>
                                            <option value="<?= htmlspecialchars($t['tanim_OdemeTipleri_Kod']) ?>"><?= htmlspecialchars($t['tanim_OdemeTipleri_Adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="filter_internet_dahil" name="internet_dahil" value="1">
                                        <label class="form-check-label" for="filter_internet_dahil">İnternet satışları dahil</label>
                                    </div>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-primary w-100" id="btnFiltrele"><i class="bi bi-search"></i></button>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-outline-secondary w-100" id="btnTemizle"><i class="bi bi-x-circle"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ajanda Kartı -->
                    <div class="card card-outline card-primary">
                        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-primary" id="btnOncekiAy"><i class="bi bi-chevron-left"></i></button>
                                <button type="button" class="btn btn-outline-primary" id="btnBuAy">Bugün</button>
                                <button type="button" class="btn btn-outline-primary" id="btnSonrakiAy"><i class="bi bi-chevron-right"></i></button>
                            </div>
                            <h4 class="mb-0 fw-bold" id="ayBaslik">—</h4>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <div class="d-flex gap-2 small">
                                    <span class="ajanda-badge badge-tahsil">Tahsil</span>
                                    <span class="ajanda-badge badge-beklenen">Bekliyor</span>
                                    <span class="ajanda-badge badge-acik">Gecikmiş</span>
                                </div>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-success" id="btnExcelDetay">
                                        <i class="bi bi-file-earmark-excel"></i> Excel (Detay)
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success" id="btnExcelOzet">
                                        <i class="bi bi-file-earmark-spreadsheet"></i> Excel (Günlük Özet)
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-2 position-relative">
                            <div id="ajandaYukleniyor" class="text-center py-5 d-none">
                                <div class="spinner-border text-primary" role="status"></div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered ajanda-tablo mb-0">
                                    <thead>
                                        <tr>
                                            <th>Pzt</th><th>Sal</th><th>Çar</th><th>Per</th><th>Cum</th>
                                            <th class="hafta-sonu">Cmt</th><th class="hafta-sonu">Paz</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ajandaBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Gün Detay Modal -->
    <div class="modal fade" id="gunModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-day"></i> <span id="gunModalBaslik"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="gunModalBody"></div>
            </div>
        </div>
    </div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const AYLAR = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    const GUNLER = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    const BUGUN_STR = '<?= $bugun ?>';
    const bugun = new Date(BUGUN_STR + 'T00:00:00');
    let aktifYil = bugun.getFullYear();
    let aktifAy  = bugun.getMonth() + 1; // 1-12
    let sonGunler = {};

    function fmt(n) {
        return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0) + ' ₺';
    }
    function ikiHane(n) { return (n < 10 ? '0' : '') + n; }
    function trTarih(s) { return s ? s.split('-').reverse().join('.') : '-'; }
    function esc(s) { return $('<div>').text(s ?? '').html(); }
    function filtreler() {
        return {
            cari_ara: $('#filter_cari_ara').val() || '',
            personel_id: $('#filter_personel_id').val() || '',
            odeme_tipi: $('#filter_odeme_tipi').val() || '',
            internet_dahil: $('#filter_internet_dahil').is(':checked') ? 1 : 0
        };
    }

    // ── CSV yardımcıları ──
    function csvHucre(v) { return '"' + String(v ?? '').replace(/"/g, '""') + '"'; }
    function csvSayi(n) { return '"' + (parseFloat(n) || 0).toFixed(2).replace('.', ',') + '"'; }
    function csvIndir(satirlar, dosyaAdi) {
        const icerik = '﻿' + satirlar.map(r => r.join(';')).join('\r\n');
        const url = URL.createObjectURL(new Blob([icerik], { type: 'text/csv;charset=utf-8;' }));
        const a = document.createElement('a');
        a.href = url; a.download = dosyaAdi; a.click();
        URL.revokeObjectURL(url);
    }
    function dosyaAdiUret(onek) { return onek + '-' + aktifYil + '-' + ikiHane(aktifAy) + '.csv'; }

    function excelOzetIndir() {
        const gunSayisi = new Date(aktifYil, aktifAy, 0).getDate();
        const satirlar = [[csvHucre('Tarih'), csvHucre('Gün'), csvHucre('Tahsil Edilen'), csvHucre('Tahsil Adet'),
                           csvHucre('Beklenen (Vade)'), csvHucre('Beklenen Adet'), csvHucre('Açık Tutar'), csvHucre('Durum')]];
        let tT = 0, tTA = 0, tB = 0, tBA = 0, tA = 0;

        for (let g = 1; g <= gunSayisi; g++) {
            const d = sonGunler[g];
            if (!d) continue;
            const tarihStr = aktifYil + '-' + ikiHane(aktifAy) + '-' + ikiHane(g);
            const durum = d.acik > 0 ? (tarihStr < BUGUN_STR ? 'Gecikmiş' : 'Bekliyor') : '';
            tT += d.gerceklesen; tTA += d.gerceklesen_adet; tB += d.beklenen; tBA += d.beklenen_adet; tA += d.acik;
            satirlar.push([csvHucre(trTarih(tarihStr)), csvHucre(GUNLER[new Date(tarihStr + 'T00:00:00').getDay()]),
                           csvSayi(d.gerceklesen), csvHucre(d.gerceklesen_adet), csvSayi(d.beklenen), csvHucre(d.beklenen_adet),
                           csvSayi(d.acik), csvHucre(durum)]);
        }
        if (satirlar.length === 1) { Swal.fire('Bilgi', 'Bu ayda dışa aktarılacak kayıt bulunamadı.', 'info'); return; }
        satirlar.push([csvHucre('TOPLAM'), csvHucre(''), csvSayi(tT), csvHucre(tTA), csvSayi(tB), csvHucre(tBA), csvSayi(tA), csvHucre('')]);
        csvIndir(satirlar, dosyaAdiUret('tahsilat-ajandasi-ozet'));
        showToast('Excel dosyası indirildi', 'success');
    }

    function excelDetayIndir() {
        const $btn = $('#btnExcelDetay');
        const eskiHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Hazırlanıyor...');

        $.post('', { action: 'ay_liste', yil: aktifYil, ay: aktifAy, ...filtreler() }, function (res) {
            $btn.prop('disabled', false).html(eskiHtml);
            if (!res.success) { Swal.fire('Hata', res.message || 'Veri alınamadı', 'error'); return; }
            if (!res.data.length) { Swal.fire('Bilgi', 'Bu ayda dışa aktarılacak kayıt bulunamadı.', 'info'); return; }

            const satirlar = [[csvHucre('Vade Tarihi'), csvHucre('Ödeme Tarihi'), csvHucre('Firma'), csvHucre('Ödeme Tipi'),
                               csvHucre('Belge No'), csvHucre('Personel'), csvHucre('Açıklama'), csvHucre('Tutar'),
                               csvHucre('Durum'), csvHucre('Tür')]];
            let toplam = 0;
            res.data.forEach(function (r) {
                toplam += r.tutar;
                satirlar.push([csvHucre(trTarih(r.vade)), csvHucre(trTarih(r.odeme_tar)), csvHucre(r.cari_adi),
                               csvHucre(r.odeme_tipi_ad), csvHucre(r.belge_no), csvHucre(r.personel_adi), csvHucre(r.aciklama),
                               csvSayi(r.tutar), csvHucre(r.durum), csvHucre(r.tur === 'tahsil' ? 'Tahsil Edildi' : 'Beklenen')]);
            });
            satirlar.push([csvHucre('TOPLAM'), '', '', '', '', '', '', csvSayi(toplam), '', '']);
            csvIndir(satirlar, dosyaAdiUret('tahsilat-ajandasi-detay'));
            showToast('Excel dosyası indirildi', 'success');
        }, 'json').fail(function () {
            $btn.prop('disabled', false).html(eskiHtml);
            Swal.fire('Hata', 'Sunucu ile bağlantı kurulamadı.', 'error');
        });
    }

    function loadStats() {
        $.post('', { action: 'ust_ozet', ...filtreler() }, function (res) {
            if (!res.success) return;
            $('#stat-bekleyen-bugun').text(fmt(res.data.bugun));
            $('#stat-bekleyen-hafta').text(fmt(res.data.hafta));
            $('#stat-bekleyen-ay').text(fmt(res.data.ay));
            $('#stat-geciken').text(fmt(res.data.geciken));
        }, 'json');
    }

    function ayYukle(callback) {
        $('#ajandaYukleniyor').removeClass('d-none');
        $('#ayBaslik').text(AYLAR[aktifAy - 1] + ' ' + aktifYil);

        $.post('', { action: 'ay_verisi', yil: aktifYil, ay: aktifAy, ...filtreler() }, function (res) {
            $('#ajandaYukleniyor').addClass('d-none');
            if (!res.success) { Swal.fire('Hata', res.message || 'Veri alınamadı', 'error'); return; }
            $('#stat-beklenen').text(fmt(res.ozet.beklenen));
            $('#stat-gerceklesen').text(fmt(res.ozet.gerceklesen));
            $('#stat-acik').text(fmt(res.ozet.acik));
            $('#stat-oran').text('%' + res.ozet.oran);
            sonGunler = res.gunler || {};
            takvimCiz(sonGunler);
            if (typeof callback === 'function') callback();
        }, 'json').fail(function () {
            $('#ajandaYukleniyor').addClass('d-none');
            Swal.fire('Hata', 'Sunucu ile bağlantı kurulamadı.', 'error');
        });
    }

    function takvimCiz(gunler) {
        const gunSayisi = new Date(aktifYil, aktifAy, 0).getDate();
        const kaydirma = (new Date(aktifYil, aktifAy - 1, 1).getDay() + 6) % 7; // Pazartesi = 0
        const satirSayisi = Math.ceil((kaydirma + gunSayisi) / 7);
        let html = '', gun = 1;

        for (let s = 0; s < satirSayisi; s++) {
            html += '<tr>';
            for (let g = 0; g < 7; g++) {
                if (s * 7 + g < kaydirma || gun > gunSayisi) { html += '<td class="ajanda-hucre bos"></td>'; continue; }

                const tarihStr = aktifYil + '-' + ikiHane(aktifAy) + '-' + ikiHane(gun);
                const d = gunler[gun] || null;
                const buGunMu = tarihStr === BUGUN_STR;

                let ic = '<div class="ajanda-gun-no ' + (buGunMu ? 'bugun-no' : '') + '">' + gun + '</div>';
                if (d) {
                    if (d.gerceklesen > 0) {
                        ic += '<div class="ajanda-satir"><span class="ajanda-badge badge-tahsil">' + d.gerceklesen_adet + '</span>'
                            + '<span class="ajanda-tutar t-yesil">' + fmt(d.gerceklesen) + '</span></div>';
                    }
                    if (d.acik > 0) {
                        const gecmis = tarihStr < BUGUN_STR;
                        ic += '<div class="ajanda-satir"><span class="ajanda-badge ' + (gecmis ? 'badge-acik' : 'badge-beklenen') + '">'
                            + d.acik_adet + '</span>'
                            + '<span class="ajanda-tutar ' + (gecmis ? 't-kirmizi' : 't-sari') + '">' + fmt(d.acik) + '</span></div>';
                    }
                }

                let cls = 'ajanda-hucre';
                if (g >= 5) cls += ' hafta-sonu';
                if (buGunMu) cls += ' bugun';
                html += '<td class="' + cls + '" data-tarih="' + tarihStr + '">' + ic + '</td>';
                gun++;
            }
            html += '</tr>';
        }
        $('#ajandaBody').html(html);
    }

    function gunDetayAc(tarih) {
        const dt = new Date(tarih + 'T00:00:00');
        $('#gunModalBaslik').text(dt.getDate() + ' ' + AYLAR[dt.getMonth()] + ' ' + dt.getFullYear() + ' ' + GUNLER[dt.getDay()]);
        $('#gunModalBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
        bootstrap.Modal.getOrCreateInstance('#gunModal').show();

        $.post('', { action: 'gun_detay', tarih: tarih, ...filtreler() }, function (res) {
            if (!res.success) { $('#gunModalBody').html('<div class="alert alert-danger">' + esc(res.message || 'Hata') + '</div>'); return; }
            if (!res.data.length) {
                $('#gunModalBody').html('<div class="alert alert-info mb-0"><i class="bi bi-info-circle"></i> Bu güne ait tahsilat kaydı yok.</div>');
                return;
            }
            const durumRenk = { 'Ödendi': 'success', 'Bekliyor': 'warning text-dark', 'Gecikmiş': 'danger' };
            let t = '<div class="table-responsive"><table class="table table-sm table-hover align-middle">'
                  + '<thead class="table-light"><tr><th>Firma</th><th>Tip</th><th>Belge No</th><th>Vade</th><th>Ödeme Tar.</th>'
                  + '<th class="text-end">Tutar</th><th>Durum</th><th class="text-center">Tür</th><th></th></tr></thead><tbody>';
            res.data.forEach(function (r) {
                const turBadge = r.tur === 'tahsil'
                    ? '<span class="badge bg-success">Tahsil Edildi</span>'
                    : '<span class="badge bg-warning text-dark">Beklenen</span>';
                t += '<tr>'
                   + '<td>' + esc(r.cari_adi) + (r.aciklama ? '<div class="small text-muted">' + esc(r.aciklama) + '</div>' : '') + '</td>'
                   + '<td>' + esc(r.odeme_tipi_ad) + '</td>'
                   + '<td>' + esc(r.belge_no || '-') + '</td>'
                   + '<td>' + trTarih(r.vade) + '</td>'
                   + '<td>' + trTarih(r.odeme_tar) + '</td>'
                   + '<td class="text-end fw-bold">' + fmt(r.tutar) + '</td>'
                   + '<td><span class="badge bg-' + (durumRenk[r.durum] || 'secondary') + '">' + esc(r.durum) + '</span></td>'
                   + '<td class="text-center">' + turBadge + '</td>'
                   + '<td class="text-end"><a href="/Admin/odeme-form?cari_id=' + r.cari_id + '&readonly=1" target="_blank" class="btn btn-sm btn-outline-primary" title="Ödeme Geçmişi"><i class="bi bi-box-arrow-up-right"></i></a></td>'
                   + '</tr>';
            });
            t += '</tbody></table></div>';
            $('#gunModalBody').html(t);
        }, 'json').fail(function () {
            $('#gunModalBody').html('<div class="alert alert-danger">Sunucu ile bağlantı kurulamadı.</div>');
        });
    }

    function hepsiniYukle() { ayYukle(loadStats); }

    $(function () {
        $('.select2').select2({
            theme: 'bootstrap-5', width: '100%',
            language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' }
        });

        $('#btnOncekiAy').on('click', function () { aktifAy--; if (aktifAy < 1) { aktifAy = 12; aktifYil--; } ayYukle(); });
        $('#btnSonrakiAy').on('click', function () { aktifAy++; if (aktifAy > 12) { aktifAy = 1; aktifYil++; } ayYukle(); });
        $('#btnBuAy').on('click', function () { aktifYil = bugun.getFullYear(); aktifAy = bugun.getMonth() + 1; ayYukle(); });

        $('#btnFiltrele').on('click', hepsiniYukle);
        $('#filter_cari_ara').on('keypress', function (e) { if (e.which === 13) hepsiniYukle(); });
        $('#btnTemizle').on('click', function () {
            $('#filter_cari_ara').val('');
            $('#filter_personel_id, #filter_odeme_tipi').val('').trigger('change');
            $('#filter_internet_dahil').prop('checked', false);
            hepsiniYukle();
        });

        $('#btnExcelDetay').on('click', excelDetayIndir);
        $('#btnExcelOzet').on('click', excelOzetIndir);
        $('#ajandaBody').on('click', '.ajanda-hucre:not(.bos)', function () { gunDetayAc($(this).data('tarih')); });

        hepsiniYukle();
    });
</script>
</body>
</html>
