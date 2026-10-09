<?php
/**
 * Admin Panel - Banka Hareketleri
 *
 * Örnek Portal API'sinden çekilen banka hesap hareketlerinin listelenmesi,
 * dekont görüntüleme ve manuel senkron.
 *
 * Veri, cron görevi (banka_hareket_cek) ile BankaHareketleri tablosuna yazılır;
 * bu sayfa yalnızca yerel tabloyu okur. "Şimdi Senkronize Et" butonu görevi
 * elle tetikler.
 *
 * @see admin/includes/BankaHareketHelper.php
 * @see cron/gorevler/banka-hareket-cek.php
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/BankaHareketHelper.php';
require_once __DIR__ . '/../includes/BankaEslestirmeHelper.php';
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
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadir.');
}

$pageTitle    = 'Banka Hareketleri';
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * Filtre koşullarını POST verisinden üretir.
 * Liste, sayım ve Excel çıktısı aynı koşulu kullanır.
 */
function bankaFiltreKosul(array $girdi, array &$params): string
{
    $where  = " WHERE h.Durum = 1 ";
    $params = [];

    if (!empty($girdi['hesap_id'])) {
        $where .= " AND h.BankaHareketleri_Hesap_id = ? ";
        $params[] = (int)$girdi['hesap_id'];
    }

    if (!empty($girdi['banka_id'])) {
        $where .= " AND h.BankaHareketleri_Banka_id = ? ";
        $params[] = (int)$girdi['banka_id'];
    }

    if (!empty($girdi['tip'])) {
        $where .= " AND h.BankaHareketleri_Tip = ? ";
        $params[] = $girdi['tip'];
    }

    if (isset($girdi['eslesme']) && $girdi['eslesme'] !== '') {
        $where .= " AND h.BankaHareketleri_EslesmeDurum = ? ";
        $params[] = (int)$girdi['eslesme'];
    }

    if (!empty($girdi['baslangic_tarih'])) {
        $where .= " AND h.BankaHareketleri_IslemTarihi >= ? ";
        $params[] = $girdi['baslangic_tarih'] . ' 00:00:00';
    }

    if (!empty($girdi['bitis_tarih'])) {
        $where .= " AND h.BankaHareketleri_IslemTarihi <= ? ";
        $params[] = $girdi['bitis_tarih'] . ' 23:59:59';
    }

    if (isset($girdi['min_tutar']) && $girdi['min_tutar'] !== '') {
        $where .= " AND h.BankaHareketleri_Tutar >= ? ";
        $params[] = (float)$girdi['min_tutar'];
    }

    if (isset($girdi['max_tutar']) && $girdi['max_tutar'] !== '') {
        $where .= " AND h.BankaHareketleri_Tutar <= ? ";
        $params[] = (float)$girdi['max_tutar'];
    }

    if (!empty($girdi['dekont_var'])) {
        $where .= " AND h.BankaHareketleri_DekontVar = 1 ";
    }

    $arama = trim((string)($girdi['arama'] ?? ''));

    if ($arama !== '') {
        $where .= " AND (h.BankaHareketleri_KarsiTaraf LIKE ?
                      OR h.BankaHareketleri_Aciklama LIKE ?
                      OR h.BankaHareketleri_ReferansNo LIKE ?
                      OR h.BankaHareketleri_Iban LIKE ?) ";
        $params[] = "%$arama%";
        $params[] = "%$arama%";
        $params[] = "%$arama%";
        $params[] = "%$arama%";
    }

    return $where;
}

// ═══════════════════════════════════════════════════
// AJAX - Liste (server-side DataTables)
// ═══════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $draw   = (int)($_POST['draw'] ?? 1);
    $start  = max(0, (int)($_POST['start'] ?? 0));
    $length = (int)($_POST['length'] ?? 25);

    if ($length < 1 || $length > 500) {
        $length = 25;
    }

    $params = [];
    $where  = bankaFiltreKosul($_POST, $params);

    // Sıralama kolonu istemciden gelen değerle doğrudan sorguya girmez
    $kolonlar = [
        0 => 'h.BankaHareketleri_IslemTarihi',
        1 => 'h.BankaHareketleri_BankaAd',
        2 => 'h.BankaHareketleri_HesapNo',
        3 => 'h.BankaHareketleri_KarsiTaraf',
        4 => 'h.BankaHareketleri_Aciklama',
        5 => 'h.BankaHareketleri_Tutar',
        6 => 'h.BankaHareketleri_KalanBakiye',
        7 => 'h.BankaHareketleri_EslesmeDurum',
    ];

    $siraIndex = (int)($_POST['order'][0]['column'] ?? 0);
    $siraKolon = $kolonlar[$siraIndex] ?? 'h.BankaHareketleri_IslemTarihi';
    $siraYon   = strtoupper((string)($_POST['order'][0]['dir'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

    $sql = "
        SELECT
            h.BankaHareketleri_id           AS id,
            h.BankaHareketleri_PortalId     AS portal_id,
            CONVERT(VARCHAR(19), h.BankaHareketleri_IslemTarihi, 120) AS islem_tarihi,
            h.BankaHareketleri_BankaAd      AS banka,
            h.BankaHareketleri_HesapNo      AS hesap_no,
            h.BankaHareketleri_Iban         AS iban,
            h.BankaHareketleri_KarsiTaraf   AS karsi_taraf,
            h.BankaHareketleri_Aciklama     AS aciklama,
            h.BankaHareketleri_Tutar        AS tutar,
            h.BankaHareketleri_Tip          AS tip,
            h.BankaHareketleri_ParaBirimi   AS para_birimi,
            h.BankaHareketleri_KalanBakiye  AS kalan_bakiye,
            h.BankaHareketleri_ReferansNo   AS referans_no,
            h.BankaHareketleri_MasrafMi     AS masraf_mi,
            h.BankaHareketleri_DekontVar    AS dekont_var,
            h.BankaHareketleri_EslesmeDurum AS eslesme_durum,
            ISNULL(c.cari_unvan, c.cari_adi) AS cari_unvan
        FROM dbo.BankaHareketleri h
        LEFT JOIN dbo.Cari c ON c.cari_id = h.BankaHareketleri_Cari_id
        $where
        ORDER BY $siraKolon $siraYon, h.BankaHareketleri_id DESC
        OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
    ";

    $kayitlar = $db->fetchAll($sql, $params);

    // Sayımlar JOIN'siz, tek ana tablodan
    $toplam = (int)($db->fetchOne("SELECT COUNT(*) AS adet FROM dbo.BankaHareketleri h WHERE h.Durum = 1")['adet'] ?? 0);
    $filtre = (int)($db->fetchOne("SELECT COUNT(*) AS adet FROM dbo.BankaHareketleri h $where", $params)['adet'] ?? 0);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $toplam,
        'recordsFiltered' => $filtre,
        'data'            => $kayitlar,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════
// AJAX - InfoBox sayımları (tablodan bağımsız)
// ═══════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json; charset=utf-8');

    $ozet = $db->fetchOne("
        SELECT
            COUNT(*) AS toplam,
            SUM(CASE WHEN BankaHareketleri_Tip = 'A' THEN BankaHareketleri_Tutar ELSE 0 END) AS tahsilat,
            SUM(CASE WHEN BankaHareketleri_Tip = 'B' THEN BankaHareketleri_Tutar ELSE 0 END) AS odeme,
            SUM(CASE WHEN BankaHareketleri_EslesmeDurum = 0 THEN 1 ELSE 0 END) AS bekleyen
        FROM dbo.BankaHareketleri
        WHERE Durum = 1
    ");

    $hesaplar = $db->fetchAll("
        SELECT BankaHesaplari_BankaAd AS banka, BankaHesaplari_HesapNo AS hesap_no,
               BankaHesaplari_Bakiye AS bakiye, BankaHesaplari_KullanilabilirBakiye AS kullanilabilir,
               CONVERT(VARCHAR(19), BankaHesaplari_SonSenkron, 120) AS son_senkron
        FROM dbo.BankaHesaplari
        WHERE Durum = 1
        ORDER BY BankaHesaplari_BankaAd
    ");

    echo json_encode([
        'success'  => true,
        'ozet'     => $ozet,
        'hesaplar' => $hesaplar,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════
// AJAX - Manuel senkron
// ═══════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'senkron') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$pagePermissions['can_edit']) {
        echo json_encode(['success' => false, 'message' => 'Senkron yetkiniz yok!']);
        exit;
    }

    try {
        $helper = new BankaHareketHelper($db);
        $helper->hesaplariSenkronla();
        $sonuc = $helper->hareketleriSenkronla(5);

        $mesaj = "{$sonuc['eklenen']} yeni hareket çekildi.";

        if (!empty($sonuc['devam_var'])) {
            $mesaj .= ' Çekilecek kayıt kaldı, tekrar çalıştırabilirsiniz.';
        }

        echo json_encode(['success' => true, 'message' => $mesaj]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Senkron hatası: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// AJAX - Eşleştirme işlemleri (cari mutabakat)
// ═══════════════════════════════════════════════════
if (isset($_POST['action']) && in_array($_POST['action'], ['adaylar', 'eslestir', 'cari_ata', 'yoksay', 'eslesme_kaldir', 'cari_ara'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    $islem     = $_POST['action'];
    $hareketId = (int)($_POST['hareket_id'] ?? 0);

    // Okuma dışındaki işlemler düzenleme yetkisi ister
    if ($islem !== 'adaylar' && $islem !== 'cari_ara' && !$pagePermissions['can_edit']) {
        echo json_encode(['success' => false, 'message' => 'Eşleştirme yetkiniz yok!']);
        exit;
    }

    try {
        $eslestirme = new BankaEslestirmeHelper($db);
        $not        = trim((string)($_POST['not'] ?? '')) ?: null;

        switch ($islem) {
            case 'adaylar':
                $hareket = $eslestirme->hareket($hareketId);

                if (!$hareket) {
                    throw new Exception('Banka hareketi bulunamadı.');
                }

                $tarih = $hareket['BankaHareketleri_IslemTarihi'];

                echo json_encode([
                    'success' => true,
                    'hareket' => [
                        'id'          => (int)$hareket['BankaHareketleri_id'],
                        'tarih'       => $tarih instanceof DateTime ? $tarih->format('d.m.Y H:i') : (string)$tarih,
                        'karsi_taraf' => $hareket['BankaHareketleri_KarsiTaraf'],
                        'vkn_tckn'    => $hareket['BankaHareketleri_VknTckn'] ?? null,
                        'aciklama'    => $hareket['BankaHareketleri_Aciklama'],
                        'tutar'       => (float)$hareket['BankaHareketleri_Tutar'],
                        'para_birimi' => $hareket['BankaHareketleri_ParaBirimi'],
                        'tip'         => $hareket['BankaHareketleri_Tip'],
                        'banka'       => $hareket['BankaHareketleri_BankaAd'],
                        'durum'       => (int)$hareket['BankaHareketleri_EslesmeDurum'],
                        'kalem_id'    => $hareket['BankaHareketleri_OdemeKalem_id'],
                        'cari_id'     => $hareket['BankaHareketleri_Cari_id'],
                        'cari_unvan'  => $hareket['cari_unvan'],
                        'not'         => $hareket['BankaHareketleri_EslesmeNot'],
                    ],
                    'adaylar'    => $eslestirme->adaylar($hareketId),
                    'vkn_cari'   => $eslestirme->vknIleCari($hareketId),
                    'guclu_skor' => BankaEslestirmeHelper::GUCLU_SKOR,
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'eslestir':
                $eslestirme->eslestir($hareketId, (int)($_POST['kalem_id'] ?? 0), (int)$user['kullanici_id'], $not);
                echo json_encode(['success' => true, 'message' => 'Hareket ödeme kalemiyle eşleştirildi.']);
                break;

            case 'cari_ata':
                // Bayrak gelmezse ödeme kaydı üretilir; eski davranışa (yalnız cari)
                // dönmek için modaldeki switch kapatılır.
                $odemeOlustur = !isset($_POST['odeme_olustur']) || (int)$_POST['odeme_olustur'] === 1;

                $sonuc = $eslestirme->cariAta($hareketId, (int)($_POST['cari_id'] ?? 0), (int)$user['kullanici_id'], $not, $odemeOlustur);

                $mesajlar = [
                    'eslesti'     => 'Hareket cariye bağlandı ve aynı gün/tutardaki mevcut ödeme kalemiyle eşleştirildi.',
                    'olusturuldu' => 'Hareket cariye bağlandı ve ödeme kaydı oluşturuldu.',
                    'cari_atandi' => 'Hareket cariye bağlandı (ödeme kaydı oluşturulmadı).',
                ];

                echo json_encode([
                    'success' => true,
                    'message' => $mesajlar[$sonuc['islem']] ?? 'Hareket cariye bağlandı.',
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'yoksay':
                $eslestirme->yoksay($hareketId, (int)$user['kullanici_id'], $not);
                echo json_encode(['success' => true, 'message' => 'Hareket mutabakat dışında bırakıldı.']);
                break;

            case 'eslesme_kaldir':
                $eslestirme->kaldir($hareketId, (int)$user['kullanici_id']);
                echo json_encode(['success' => true, 'message' => 'Eşleşme kaldırıldı.']);
                break;

            case 'cari_ara':
                $terim = trim((string)($_POST['terim'] ?? ''));

                $kayitlar = $db->fetchAll("
                    SELECT TOP 30 cari_id AS id, ISNULL(cari_unvan, cari_adi) AS text
                    FROM dbo.Cari
                    WHERE cari_aktif = 1
                      AND (cari_unvan LIKE ? OR cari_adi LIKE ? OR cari_vergi_no LIKE ?)
                    ORDER BY ISNULL(cari_unvan, cari_adi)
                ", ["%$terim%", "%$terim%", "%$terim%"]);

                echo json_encode(['success' => true, 'results' => $kayitlar], JSON_UNESCAPED_UNICODE);
                break;
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    exit;
}

// ═══════════════════════════════════════════════════
// Dekont görüntüleme (ilk istekte API'den indirilir, sonra yerelden)
// ═══════════════════════════════════════════════════
if (isset($_GET['dekont'])) {
    $portalId = (int)$_GET['dekont'];

    try {
        $helper = new BankaHareketHelper($db);
        $yol    = $helper->dekontIndir($portalId);
    } catch (Throwable $e) {
        $yol = null;
        error_log('Dekont indirme hatası: ' . $e->getMessage());
    }

    $tamYol = $yol ? dirname(__DIR__, 2) . '/' . $yol : null;

    if (!$yol || !is_file($tamYol)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Dekont bulunamadı veya indirilemedi.';
        exit;
    }

    $uzanti = strtolower(pathinfo($tamYol, PATHINFO_EXTENSION));
    $turler = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    header('Content-Type: ' . ($turler[$uzanti] ?? 'application/octet-stream'));
    header('Content-Disposition: inline; filename="dekont_' . $portalId . '.' . $uzanti . '"');
    header('Content-Length: ' . filesize($tamYol));
    readfile($tamYol);
    exit;
}

// ═══════════════════════════════════════════════════
// Excel indirme
// ═══════════════════════════════════════════════════
if (isset($_GET['excel']) && $_GET['excel'] === '1') {
    $params = [];
    $where  = bankaFiltreKosul($_POST, $params);

    $kayitlar = $db->fetchAll("
        SELECT
            CONVERT(VARCHAR(19), h.BankaHareketleri_IslemTarihi, 120) AS islem_tarihi,
            h.BankaHareketleri_BankaAd    AS banka,
            h.BankaHareketleri_HesapNo    AS hesap_no,
            h.BankaHareketleri_KarsiTaraf AS karsi_taraf,
            h.BankaHareketleri_Aciklama   AS aciklama,
            h.BankaHareketleri_Tip        AS tip,
            h.BankaHareketleri_Tutar      AS tutar,
            h.BankaHareketleri_ParaBirimi AS para_birimi,
            h.BankaHareketleri_KalanBakiye AS kalan_bakiye,
            h.BankaHareketleri_ReferansNo AS referans_no
        FROM dbo.BankaHareketleri h
        $where
        ORDER BY h.BankaHareketleri_IslemTarihi DESC, h.BankaHareketleri_id DESC
    ", $params);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="banka_hareketleri_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head><body>';
    echo '<table border="1"><thead><tr style="background-color:#6f42c1;color:#fff;font-weight:bold;">';
    echo '<th>Tarih</th><th>Banka</th><th>Hesap No</th><th>Karşı Taraf</th><th>Açıklama</th>';
    echo '<th>Tür</th><th>Tutar</th><th>Para Birimi</th><th>Kalan Bakiye</th><th>Referans No</th>';
    echo '</tr></thead><tbody>';

    foreach ($kayitlar as $k) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($k['islem_tarihi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['banka'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['hesap_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['karsi_taraf'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['aciklama'] ?? '') . '</td>';
        echo '<td>' . (($k['tip'] ?? '') === 'A' ? 'Tahsilat' : 'Ödeme') . '</td>';
        echo '<td style="text-align:right">' . number_format((float)($k['tutar'] ?? 0), 2, ',', '.') . '</td>';
        echo '<td style="text-align:center">' . htmlspecialchars($k['para_birimi'] ?? 'TRY') . '</td>';
        echo '<td style="text-align:right">' . number_format((float)($k['kalan_bakiye'] ?? 0), 2, ',', '.') . '</td>';
        echo '<td>' . htmlspecialchars($k['referans_no'] ?? '') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// ═══════════════════════════════════════════════════
// Filtre kaynakları — hareket tablosundan dinamik
// (kapanmış hesaplar API'nin hesap ucunda dönmediği için
//  hesap/banka listesi hareketlerden türetilir)
// ═══════════════════════════════════════════════════
$hesapListesi = $db->fetchAll("
    SELECT DISTINCT h.BankaHareketleri_Hesap_id AS id,
           h.BankaHareketleri_BankaAd AS banka,
           h.BankaHareketleri_HesapNo AS hesap_no
    FROM dbo.BankaHareketleri h
    WHERE h.Durum = 1 AND h.BankaHareketleri_Hesap_id IS NOT NULL
    ORDER BY h.BankaHareketleri_BankaAd, h.BankaHareketleri_HesapNo
");

$bankaListesi = $db->fetchAll("
    SELECT DISTINCT h.BankaHareketleri_Banka_id AS id, h.BankaHareketleri_BankaAd AS ad
    FROM dbo.BankaHareketleri h
    WHERE h.Durum = 1 AND h.BankaHareketleri_Banka_id IS NOT NULL
    ORDER BY h.BankaHareketleri_BankaAd
");

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .info-box-icon { width: 50px; height: 50px; font-size: 1.5rem; }
        .tutar-alacak { color: #198754; font-weight: 600; }
        .tutar-borc   { color: #dc3545; font-weight: 600; }
        .aciklama-hucre { max-width: 320px; }
        .hesap-karti .card-body { padding: .75rem 1rem; }
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
                            <li class="breadcrumb-item"><a href="anasayfa.php">Anasayfa</a></li>
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
                            <span class="info-box-icon"><i class="bi bi-list-columns-reverse"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Hareket</span>
                                <span class="info-box-number" id="ibToplam">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-arrow-down-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Tahsilat</span>
                                <span class="info-box-number" id="ibTahsilat">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-arrow-up-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Ödeme</span>
                                <span class="info-box-number" id="ibOdeme">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-link-45deg"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Eşleşme Bekleyen</span>
                                <span class="info-box-number" id="ibBekleyen">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hesap bakiyeleri -->
                <div class="row mb-3" id="hesapKartlari"></div>

                <!-- Filtre Paneli -->
                <div class="card mb-3">
                    <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <i class="bi bi-funnel me-1"></i> Filtrele
                    </div>
                    <div class="collapse show" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label">Hesap</label>
                                    <select id="hesap_id" class="form-select select2-searchable">
                                        <option value="">Tümü</option>
                                        <?php foreach ($hesapListesi as $h): ?>
                                            <option value="<?= (int)$h['id'] ?>">
                                                <?= htmlspecialchars(($h['banka'] ?? '') . ' — ' . ($h['hesap_no'] ?? '')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Banka</label>
                                    <select id="banka_id" class="form-select select2-searchable">
                                        <option value="">Tümü</option>
                                        <?php foreach ($bankaListesi as $b): ?>
                                            <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['ad'] ?? '') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tür</label>
                                    <select id="tip" class="form-select select2-searchable">
                                        <option value="">Tümü</option>
                                        <option value="A">Tahsilat (Giriş)</option>
                                        <option value="B">Ödeme (Çıkış)</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Eşleşme</label>
                                    <select id="eslesme" class="form-select select2-searchable">
                                        <option value="">Tümü</option>
                                        <option value="0">Bekliyor</option>
                                        <option value="1">Otomatik</option>
                                        <option value="2">Manuel</option>
                                        <option value="3">Yoksayıldı</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Ara</label>
                                    <input type="text" id="arama" class="form-control" placeholder="Karşı taraf, açıklama, referans, IBAN...">
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" id="baslangic_tarih" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" id="bitis_tarih" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Min. Tutar</label>
                                    <input type="number" step="0.01" id="min_tutar" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Maks. Tutar</label>
                                    <input type="number" step="0.01" id="max_tutar" class="form-control">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="dekont_var" value="1">
                                        <label class="form-check-label" for="dekont_var">Yalnızca dekontlu</label>
                                    </div>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" id="btnTemizle" class="btn btn-outline-secondary mb-2 w-100">
                                        <i class="bi bi-x-circle me-1"></i> Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hareket Tablosu -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="bi bi-bank me-1"></i> Hareketler</h3>
                        <div class="d-flex gap-2">
                            <?php if ($pagePermissions['can_edit']): ?>
                                <button type="button" id="btnSenkron" class="btn btn-primary btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i> Şimdi Senkronize Et
                                </button>
                            <?php endif; ?>
                            <button type="button" id="btnExcel" class="btn btn-success btn-sm">
                                <i class="bi bi-file-earmark-excel me-1"></i> Excel İndir
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="hareketTable" class="table table-striped table-bordered align-middle" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Banka</th>
                                    <th>Hesap No</th>
                                    <th>Karşı Taraf</th>
                                    <th>Açıklama</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="text-end">Kalan Bakiye</th>
                                    <th class="text-center">Eşleşme</th>
                                    <th class="text-center">Dekont</th>
                                    <th class="text-center">İşlem</th>
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

<!-- Dekont Modalı -->
<div class="modal fade" id="dekontModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt me-1"></i> Dekont</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body p-0" id="dekontGovde" style="min-height:70vh"></div>
            <div class="modal-footer">
                <a href="#" id="dekontYeniSekme" target="_blank" class="btn btn-outline-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Yeni Sekmede Aç
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- Eşleştirme Modalı -->
<div class="modal fade" id="eslestirModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link-45deg me-1"></i> Hareket Eşleştirme</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="es_hareket_id">

                <!-- Hareket özeti -->
                <div class="card mb-3">
                    <div class="card-body py-2" id="es_hareket_ozet"></div>
                </div>

                <!-- VKN ile tespit edilen cari -->
                <div class="alert alert-light border d-none py-2" id="es_vkn_cari"></div>

                <!-- Aday ödeme kalemleri -->
                <h6 class="mb-2"><i class="bi bi-list-check me-1"></i> Önerilen Ödeme Kalemleri</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-hover align-middle mb-0" id="es_aday_tablo">
                        <thead>
                            <tr>
                                <th style="width:90px">Skor</th>
                                <th>Cari</th>
                                <th>Tip / Belge</th>
                                <th>Ödeme Tarihi</th>
                                <th class="text-end">Tutar</th>
                                <th class="text-center" style="width:110px">İşlem</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <hr>

                <!-- Cari atama -->
                <h6 class="mb-2"><i class="bi bi-person-check me-1"></i> Cari Ata</h6>
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">Cari</label>
                        <select id="es_cari_id" class="form-select"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Not (opsiyonel)</label>
                        <input type="text" id="es_not" class="form-control" placeholder="Açıklama...">
                    </div>
                    <div class="col-md-2">
                        <button type="button" id="btnCariAta" class="btn btn-outline-primary w-100">
                            <i class="bi bi-person-plus me-1"></i> Ata
                        </button>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="es_odeme_olustur" checked>
                            <label class="form-check-label" for="es_odeme_olustur">
                                Ödeme kaydı da oluştur
                                <small class="text-muted d-block">
                                    Kapatılırsa hareket yalnız cariye bağlanır; ödeme formunda satır oluşmaz
                                    ve tahsilat bakiyeye girmez (avans, teminat, virman için).
                                </small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div>
                    <button type="button" id="btnYoksay" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-slash-circle me-1"></i> Mutabakat Dışı Bırak
                    </button>
                    <button type="button" id="btnEslesmeKaldir" class="btn btn-outline-danger btn-sm d-none">
                        <i class="bi bi-x-circle me-1"></i> Eşleşmeyi Kaldır
                    </button>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
$(function () {
    $('.select2-searchable').select2({ theme: 'bootstrap-5', width: '100%' });

    const esc = t => $('<div>').text(t == null ? '' : t).html();

    const paraFormat = (deger, birim) => {
        const sayi = parseFloat(deger || 0).toLocaleString('tr-TR', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
        return sayi + ' ' + (birim || 'TRY');
    };

    const eslesmeRozet = d => {
        const harita = {
            0: ['secondary', 'Bekliyor'],
            1: ['info', 'Otomatik'],
            2: ['success', 'Manuel'],
            3: ['dark', 'Yoksayıldı']
        };
        const [renk, metin] = harita[d] || harita[0];
        return '<span class="badge text-bg-' + renk + '">' + metin + '</span>';
    };

    const filtreler = d => {
        d.hesap_id        = $('#hesap_id').val();
        d.banka_id        = $('#banka_id').val();
        d.tip             = $('#tip').val();
        d.eslesme         = $('#eslesme').val();
        d.arama           = $('#arama').val();
        d.baslangic_tarih = $('#baslangic_tarih').val();
        d.bitis_tarih     = $('#bitis_tarih').val();
        d.min_tutar       = $('#min_tutar').val();
        d.max_tutar       = $('#max_tutar').val();
        d.dekont_var      = $('#dekont_var').is(':checked') ? 1 : '';
    };

    const table = $('#hareketTable').DataTable({
        processing: true,
        serverSide: true,
        scrollX: true,
        dom: 'lrtip',                 // global arama kutusu kapalı, filtre panelindeki alan kullanılır
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        order: [[0, 'desc']],
        ajax: {
            url: '?ajax=1',
            type: 'POST',
            data: filtreler
        },
        columns: [
            {
                data: 'islem_tarihi',
                render: d => {
                    if (!d) return '-';
                    const [tarih, saat] = d.split(' ');
                    const p = tarih.split('-');
                    return p[2] + '.' + p[1] + '.' + p[0] +
                           '<br><small class="text-muted">' + (saat || '').substring(0, 5) + '</small>';
                }
            },
            { data: 'banka', render: d => esc(d) },
            {
                data: 'hesap_no',
                render: (d, t, row) => esc(d) +
                    (row.iban ? '<br><small class="text-muted">' + esc(row.iban) + '</small>' : '')
            },
            { data: 'karsi_taraf', render: d => esc(d) || '-' },
            {
                data: 'aciklama',
                className: 'aciklama-hucre',
                render: d => d
                    ? '<span class="d-inline-block text-truncate" style="max-width:320px" title="' + esc(d) + '">' + esc(d) + '</span>'
                    : '-'
            },
            {
                data: 'tutar',
                className: 'text-end',
                render: (d, t, row) => {
                    const sinif = row.tip === 'A' ? 'tutar-alacak' : 'tutar-borc';
                    const isaret = row.tip === 'A' ? '+' : '−';
                    const masraf = row.masraf_mi == 1
                        ? ' <i class="bi bi-tag text-muted" title="Masraf"></i>' : '';
                    return '<span class="' + sinif + '">' + isaret + ' ' +
                           paraFormat(d, row.para_birimi) + '</span>' + masraf;
                }
            },
            {
                data: 'kalan_bakiye',
                className: 'text-end',
                render: (d, t, row) => d == null ? '-' : paraFormat(d, row.para_birimi)
            },
            {
                data: 'eslesme_durum',
                className: 'text-center',
                render: (d, t, row) => eslesmeRozet(parseInt(d, 10)) +
                    (row.cari_unvan ? '<br><small class="text-muted">' + esc(row.cari_unvan) + '</small>' : '')
            },
            {
                data: 'dekont_var',
                className: 'text-center',
                orderable: false,
                render: (d, t, row) => d == 1
                    ? '<button class="btn btn-sm btn-outline-info btn-dekont" data-id="' + row.portal_id + '" title="Dekontu görüntüle"><i class="bi bi-receipt"></i></button>'
                    : '<span class="text-muted">—</span>'
            },
            {
                data: 'id',
                className: 'text-center',
                orderable: false,
                render: (d, t, row) => row.tip === 'A'
                    ? '<button class="btn btn-sm btn-outline-primary btn-eslestir" data-id="' + d + '" title="Eşleştir"><i class="bi bi-link-45deg"></i></button>'
                    : '<button class="btn btn-sm btn-outline-secondary btn-eslestir" data-id="' + d + '" title="Cari ata / yoksay"><i class="bi bi-three-dots"></i></button>'
            }
        ],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        }
    });

    // Filtre değişiminde tabloyu yenile
    $('#hesap_id, #banka_id, #tip, #eslesme, #baslangic_tarih, #bitis_tarih, #dekont_var')
        .on('change', () => table.ajax.reload());

    let zamanlayici = null;
    $('#arama, #min_tutar, #max_tutar').on('keyup change', function () {
        clearTimeout(zamanlayici);
        zamanlayici = setTimeout(() => table.ajax.reload(), 400);
    });

    $('#btnTemizle').on('click', function () {
        $('#hesap_id, #banka_id, #tip, #eslesme').val('').trigger('change.select2');
        $('#arama, #baslangic_tarih, #bitis_tarih, #min_tutar, #max_tutar').val('');
        $('#dekont_var').prop('checked', false);
        table.ajax.reload();
    });

    // InfoBox ve hesap kartları — tablodan bağımsız
    function ozetYukle() {
        $.getJSON('?ajax=stats', function (res) {
            if (!res.success) return;

            const o = res.ozet || {};
            $('#ibToplam').text(parseInt(o.toplam || 0).toLocaleString('tr-TR'));
            $('#ibTahsilat').text(paraFormat(o.tahsilat, 'TRY'));
            $('#ibOdeme').text(paraFormat(o.odeme, 'TRY'));
            $('#ibBekleyen').text(parseInt(o.bekleyen || 0).toLocaleString('tr-TR'));

            const kartlar = (res.hesaplar || []).map(h =>
                '<div class="col-md-4">' +
                  '<div class="card hesap-karti mb-2">' +
                    '<div class="card-body d-flex justify-content-between align-items-center">' +
                      '<div>' +
                        '<div class="fw-bold">' + esc(h.banka) + '</div>' +
                        '<small class="text-muted">' + esc(h.hesap_no) + '</small>' +
                      '</div>' +
                      '<div class="text-end">' +
                        '<div class="fw-bold">' + paraFormat(h.bakiye, 'TRY') + '</div>' +
                        '<small class="text-muted">Senkron: ' + esc(h.son_senkron || '—') + '</small>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                '</div>'
            ).join('');

            $('#hesapKartlari').html(kartlar);
        });
    }

    ozetYukle();

    // Dekont görüntüleme
    $('#hareketTable').on('click', '.btn-dekont', function () {
        const id  = $(this).data('id');
        const url = '?dekont=' + id;

        $('#dekontYeniSekme').attr('href', url);
        $('#dekontGovde').html(
            '<div class="text-center py-5"><div class="spinner-border text-primary"></div>' +
            '<div class="mt-2 text-muted">Dekont yükleniyor…</div></div>'
        );

        const modal = new bootstrap.Modal('#dekontModal');
        modal.show();

        // İlk açılışta dosya API'den indirilir; iframe hem PDF hem görsel için çalışır
        $('#dekontGovde').html(
            '<iframe src="' + url + '" style="width:100%;height:70vh;border:0"></iframe>'
        );
    });

    // ── Eşleştirme ───────────────────────────────
    const bildir = (tur, mesaj) => {
        if (typeof toastr !== 'undefined') { toastr[tur](mesaj); }
        else { alert(mesaj); }
    };

    // Cari seçici — arama sunucu tarafında yapılır
    $('#es_cari_id').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#eslestirModal'),
        placeholder: 'Cari ara…',
        allowClear: true,
        minimumInputLength: 2,
        ajax: {
            url: '',
            type: 'POST',
            dataType: 'json',
            delay: 300,
            data: params => ({ action: 'cari_ara', terim: params.term }),
            processResults: res => ({ results: res.results || [] })
        }
    });

    function adaylariYukle(hareketId) {
        $('#es_hareket_id').val(hareketId);
        $('#es_aday_tablo tbody').html('<tr><td colspan="6" class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></td></tr>');

        $.post('', { action: 'adaylar', hareket_id: hareketId }, function (res) {
            if (!res.success) {
                bildir('error', res.message);
                return;
            }

            const h = res.hareket;
            const tipMetin = h.tip === 'A' ? 'Tahsilat' : 'Ödeme';
            const tipSinif = h.tip === 'A' ? 'tutar-alacak' : 'tutar-borc';

            $('#es_hareket_ozet').html(
                '<div class="row g-2">' +
                  '<div class="col-md-3"><small class="text-muted d-block">Tarih</small>' + esc(h.tarih) + '</div>' +
                  '<div class="col-md-3"><small class="text-muted d-block">Karşı Taraf</small>' + (esc(h.karsi_taraf) || '-') +
                    (h.vkn_tckn ? '<br><small class="text-muted">VKN/TCKN: ' + esc(h.vkn_tckn) + '</small>' : '') + '</div>' +
                  '<div class="col-md-2"><small class="text-muted d-block">Banka</small>' + esc(h.banka) + '</div>' +
                  '<div class="col-md-2"><small class="text-muted d-block">Tür</small>' + tipMetin + '</div>' +
                  '<div class="col-md-2 text-end"><small class="text-muted d-block">Tutar</small>' +
                    '<span class="' + tipSinif + '">' + paraFormat(h.tutar, h.para_birimi) + '</span></div>' +
                  (h.aciklama ? '<div class="col-12"><small class="text-muted d-block">Açıklama</small><small>' + esc(h.aciklama) + '</small></div>' : '') +
                  (h.cari_unvan ? '<div class="col-12"><span class="badge text-bg-success">Bağlı cari: ' + esc(h.cari_unvan) + '</span></div>' : '') +
                '</div>'
            );

            // Zaten eşleşmişse kaldırma butonu görünür
            $('#btnEslesmeKaldir').toggleClass('d-none', h.durum === 0);
            $('#es_not').val(h.not || '');

            // VKN'den bulunan cari — ödeme kaydı olmasa da karşı taraf kesindir
            if (res.vkn_cari) {
                const vc = res.vkn_cari;

                $('#es_vkn_cari').removeClass('d-none').html(
                    '<i class="bi bi-patch-check-fill text-success me-1"></i>' +
                    'VKN ile bulunan cari: <strong>' + esc(vc.cari_unvan) + '</strong> ' +
                    '<small class="text-muted">(' + esc(vc.cari_vergi_no) + ')</small>' +
                    '<button class="btn btn-sm btn-success ms-2" id="btnVknCariAta" data-cari="' + vc.cari_id + '">' +
                    '<i class="bi bi-check-lg"></i> Bu cariye ata</button>'
                );
            } else {
                $('#es_vkn_cari').addClass('d-none').empty();
            }

            const adaylar = res.adaylar || [];

            if (!adaylar.length) {
                $('#es_aday_tablo tbody').html(
                    '<tr><td colspan="6" class="text-center text-muted py-3">' +
                    'Uygun ödeme kalemi bulunamadı. Aşağıdan doğrudan cari atayabilirsiniz.</td></tr>'
                );
                return;
            }

            const satirlar = adaylar.map(a => {
                const renk = a.skor >= res.guclu_skor ? 'success' : (a.skor >= 45 ? 'warning' : 'secondary');
                const gerekce = (a.gerekce || []).join(', ');

                return '<tr>' +
                    '<td><span class="badge text-bg-' + renk + '">' + a.skor + '</span></td>' +
                    '<td>' + (esc(a.cari_unvan) || '-') +
                        (gerekce ? '<br><small class="text-muted">' + esc(gerekce) + '</small>' : '') + '</td>' +
                    '<td>' + esc(a.tip) + (a.belge_no ? '<br><small class="text-muted">' + esc(a.belge_no) + '</small>' : '') + '</td>' +
                    '<td>' + esc(a.odeme_tar || a.vade || '-') +
                        (a.odendi == 1 ? ' <i class="bi bi-check-circle text-success" title="Ödendi"></i>' : '') + '</td>' +
                    '<td class="text-end">' + paraFormat(a.tutar, 'TRY') + '</td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-sm btn-primary btn-aday-sec" data-kalem="' + a.kalem_id + '">' +
                        '<i class="bi bi-check-lg"></i> Eşleştir</button></td>' +
                '</tr>';
            }).join('');

            $('#es_aday_tablo tbody').html(satirlar);
        }, 'json').fail(() => bildir('error', 'Aday listesi alınamadı.'));
    }

    $('#hareketTable').on('click', '.btn-eslestir', function () {
        const id = $(this).data('id');

        $('#es_cari_id').val(null).trigger('change');
        $('#es_odeme_olustur').prop('checked', true);
        adaylariYukle(id);

        new bootstrap.Modal('#eslestirModal').show();
    });

    function eslestirmeIstek(veri, basariMesaji) {
        veri.hareket_id = $('#es_hareket_id').val();
        veri.not        = $('#es_not').val();

        $.post('', veri, function (res) {
            if (res.success) {
                bildir('success', res.message || basariMesaji);
                bootstrap.Modal.getInstance(document.getElementById('eslestirModal')).hide();
                table.ajax.reload(null, false);
                ozetYukle();
            } else {
                bildir('error', res.message);
            }
        }, 'json').fail(() => bildir('error', 'İşlem başarısız oldu.'));
    }

    $('#es_aday_tablo').on('click', '.btn-aday-sec', function () {
        eslestirmeIstek({ action: 'eslestir', kalem_id: $(this).data('kalem') }, 'Eşleştirildi.');
    });

    // Switch açıkken hareketin karşılığı ödeme kalemi olarak da yazılır;
    // aksi halde yalnız cari bağlanır ve ödeme formunda satır oluşmaz.
    function odemeOlusturBayragi() {
        return $('#es_odeme_olustur').is(':checked') ? 1 : 0;
    }

    $('#es_vkn_cari').on('click', '#btnVknCariAta', function () {
        eslestirmeIstek({
            action: 'cari_ata',
            cari_id: $(this).data('cari'),
            odeme_olustur: odemeOlusturBayragi()
        }, 'Cari atandı.');
    });

    $('#btnCariAta').on('click', function () {
        const cariId = $('#es_cari_id').val();

        if (!cariId) {
            bildir('warning', 'Önce bir cari seçin.');
            return;
        }

        eslestirmeIstek({
            action: 'cari_ata',
            cari_id: cariId,
            odeme_olustur: odemeOlusturBayragi()
        }, 'Cari atandı.');
    });

    $('#btnYoksay').on('click', function () {
        eslestirmeIstek({ action: 'yoksay' }, 'Mutabakat dışı bırakıldı.');
    });

    $('#btnEslesmeKaldir').on('click', function () {
        eslestirmeIstek({ action: 'eslesme_kaldir' }, 'Eşleşme kaldırıldı.');
    });

    // Manuel senkron
    $('#btnSenkron').on('click', function () {
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Senkronize ediliyor…');

        $.post('', { action: 'senkron' }, function (res) {
            if (res.success) {
                if (typeof toastr !== 'undefined') { toastr.success(res.message); }
                else { alert(res.message); }

                table.ajax.reload();
                ozetYukle();
            } else {
                if (typeof toastr !== 'undefined') { toastr.error(res.message); }
                else { alert(res.message); }
            }
        }, 'json').fail(function () {
            if (typeof toastr !== 'undefined') { toastr.error('Senkron isteği başarısız oldu.'); }
            else { alert('Senkron isteği başarısız oldu.'); }
        }).always(function () {
            btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i> Şimdi Senkronize Et');
        });
    });

    // Excel — filtreler POST ile gönderilir
    $('#btnExcel').on('click', function () {
        const veri = {};
        filtreler(veri);

        const form = $('<form>', { method: 'POST', action: '?excel=1' });

        $.each(veri, function (ad, deger) {
            if (deger !== '' && deger != null) {
                form.append($('<input>', { type: 'hidden', name: ad, value: deger }));
            }
        });

        form.appendTo('body').submit().remove();
    });
});
</script>
</body>
</html>
