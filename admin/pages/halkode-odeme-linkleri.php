<?php
/**
 * Admin Panel - HalkÖde Ödeme Linkleri
 *
 * Ödeme formunda üretilen HalkÖde ödeme linklerinin listelenmesi, durum
 * sorgulama, linki yeniden gönderme ve iptal etme.
 *
 * Kayıtlar Halkode_Odeme_Linkleri tablosundadır; ödeme sonucu callback ile
 * (admin/api/halkode-callback.php) veya cron görevi ile düşer. Bu sayfadaki
 * "Durum Sorgula" işlemi aynı idempotent akışı elle tetikler.
 *
 * @see admin/includes/HalkodeService.php
 * @see cron/gorevler/halkode-durum-sorgula.php
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/HalkodeService.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
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

$pageTitle    = 'HalkÖde Ödeme Linkleri';
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * kendini_gor yetkisinde cari kısıtı.
 * odeme-form.php ile aynı küme: kullanıcının fatura kestiği + cari kartını açtığı
 * + teklif hazırladığı cariler + kendisine devredilenler. Yetki yoksa boş döner.
 */
function halkodeYetkiKosul(string $cariKolon, array &$params): string
{
    global $pagePermissions, $user;

    if (empty($pagePermissions['can_view_own_records'])) {
        return '';
    }

    $uid = (int)$user['kullanici_id'];

    // Soru isareti sirasi asagidaki EXISTS bloklariyla ayni olmali
    $params[] = $uid; // fatura kesen
    $params[] = $uid; // cari kartini acan
    $params[] = $uid; // teklif hazirlayan

    $sahiplik = "EXISTS (
            SELECT 1 FROM dbo.FaturalarGiden fgy
            WHERE fgy.FaturalarGiden_Cari_id = {$cariKolon}
              AND fgy.Durum = 1
              AND fgy.OlusturanKullanici = ?
        )
        OR EXISTS (
            SELECT 1 FROM dbo.Cari cy
            WHERE cy.cari_id = {$cariKolon}
              AND cy.cari_olusturan_kullanici = ?
        )
        OR EXISTS (
            SELECT 1 FROM dbo.Teklifler ty
            WHERE ty.Teklifler_cari_id = {$cariKolon}
              AND ty.Durum = 1
              AND ty.Teklifler_hazirlayan_id = ?
        )";

    return " AND (" . CariDevirHelper::orDevir($sahiplik, $uid, $cariKolon) . ") ";
}

/**
 * Filtre koşullarını POST verisinden üretir.
 * Liste, sayım ve Excel çıktısı aynı koşulu kullanır.
 */
function halkodeFiltreKosul(array $girdi, array &$params): string
{
    $params = [];
    $where  = " WHERE h.Durum = 1 " . halkodeYetkiKosul('h.Halkode_Odeme_Linkleri_Cari_id', $params);

    if (!empty($girdi['cari_id'])) {
        $where .= " AND h.Halkode_Odeme_Linkleri_Cari_id = ? ";
        $params[] = (int)$girdi['cari_id'];
    }

    if (!empty($girdi['durum'])) {
        $where .= " AND h.Halkode_Odeme_Linkleri_DurumKod = ? ";
        $params[] = (string)$girdi['durum'];
    }

    if (!empty($girdi['taksitli'])) {
        $where .= " AND h.Halkode_Odeme_Linkleri_Taksit > 1 ";
    }

    if (!empty($girdi['baslangic_tarih'])) {
        $where .= " AND CONVERT(DATE, h.OlusturmaTarihi) >= ? ";
        $params[] = $girdi['baslangic_tarih'];
    }

    if (!empty($girdi['bitis_tarih'])) {
        $where .= " AND CONVERT(DATE, h.OlusturmaTarihi) <= ? ";
        $params[] = $girdi['bitis_tarih'];
    }

    if (isset($girdi['min_tutar']) && $girdi['min_tutar'] !== '') {
        $where .= " AND h.Halkode_Odeme_Linkleri_Tutar >= ? ";
        $params[] = (float)$girdi['min_tutar'];
    }

    if (isset($girdi['max_tutar']) && $girdi['max_tutar'] !== '') {
        $where .= " AND h.Halkode_Odeme_Linkleri_Tutar <= ? ";
        $params[] = (float)$girdi['max_tutar'];
    }

    if (!empty($girdi['arama'])) {
        $arama  = '%' . trim((string)$girdi['arama']) . '%';
        $where .= " AND (
                        h.Halkode_Odeme_Linkleri_SiparisNo  LIKE ?
                     OR h.Halkode_Odeme_Linkleri_Aciklama   LIKE ?
                     OR h.Halkode_Odeme_Linkleri_AuthCode   LIKE ?
                     OR h.Halkode_Odeme_Linkleri_Rrn        LIKE ?
                     OR h.Halkode_Odeme_Linkleri_KartMaske  LIKE ?
                     OR h.Halkode_Odeme_Linkleri_Telefon    LIKE ?
                     OR h.Halkode_Odeme_Linkleri_Email      LIKE ?
                    ) ";
        array_push($params, $arama, $arama, $arama, $arama, $arama, $arama, $arama);
    }

    return $where;
}

// ═══════════════════════════════════════════════════
// AJAX - DataTables (server-side)
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
    $where  = halkodeFiltreKosul($_POST, $params);

    // Sıralama kolonu istemciden gelen değerle doğrudan sorguya girmez
    $kolonlar = [
        0 => 'h.OlusturmaTarihi',
        1 => 'ISNULL(c.cari_unvan, c.cari_adi)',
        2 => 'h.Halkode_Odeme_Linkleri_Tutar',
        3 => 'h.Halkode_Odeme_Linkleri_Taksit',
        4 => 'h.Halkode_Odeme_Linkleri_DurumKod',
        5 => 'h.Halkode_Odeme_Linkleri_GonderimTarihi',
        6 => 'h.Halkode_Odeme_Linkleri_OdemeTarihi',
    ];

    $siraIndex = (int)($_POST['order'][0]['column'] ?? 0);
    $siraKolon = $kolonlar[$siraIndex] ?? 'h.OlusturmaTarihi';
    $siraYon   = strtoupper((string)($_POST['order'][0]['dir'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

    // Ucuz CTE: yalnız filtre + sıralama + sayfalama ile o sayfanın id'leri
    $sql = "
        WITH sayfa AS (
            SELECT h.Halkode_Odeme_Linkleri_id AS id
            FROM dbo.Halkode_Odeme_Linkleri h
            LEFT JOIN dbo.Cari c ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
            $where
            ORDER BY $siraKolon $siraYon, h.Halkode_Odeme_Linkleri_id DESC
            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
        )
        SELECT
            h.Halkode_Odeme_Linkleri_id            AS id,
            h.Halkode_Odeme_Linkleri_SiparisNo     AS siparis_no,
            h.Halkode_Odeme_Linkleri_Cari_id       AS cari_id,
            ISNULL(c.cari_unvan, c.cari_adi)       AS cari_unvan,
            h.Halkode_Odeme_Linkleri_Tutar         AS tutar,
            h.Halkode_Odeme_Linkleri_ParaBirimi    AS para_birimi,
            h.Halkode_Odeme_Linkleri_Taksit        AS taksit,
            h.Halkode_Odeme_Linkleri_DurumKod      AS durum,
            h.Halkode_Odeme_Linkleri_Link          AS link,
            h.Halkode_Odeme_Linkleri_GonderimKanali AS gonderim_kanali,
            CONVERT(VARCHAR(19), h.Halkode_Odeme_Linkleri_GonderimTarihi, 120) AS gonderim_tarihi,
            CONVERT(VARCHAR(19), h.Halkode_Odeme_Linkleri_OdemeTarihi, 120)    AS odeme_tarihi,
            CONVERT(VARCHAR(19), h.OlusturmaTarihi, 120)                       AS olusturma,
            h.Halkode_Odeme_Linkleri_AuthCode      AS auth_code,
            h.Halkode_Odeme_Linkleri_Rrn           AS rrn,
            h.Halkode_Odeme_Linkleri_KartMaske     AS kart_maske,
            h.Halkode_Odeme_Linkleri_SonucMesaj    AS sonuc_mesaj,
            h.Halkode_Odeme_Linkleri_Aciklama      AS aciklama,
            h.Halkode_Odeme_Linkleri_Telefon       AS telefon,
            h.Halkode_Odeme_Linkleri_Email         AS email,
            h.Halkode_Odeme_Linkleri_OdemeKalem_id AS kalem_id
        FROM sayfa s
        INNER JOIN dbo.Halkode_Odeme_Linkleri h ON h.Halkode_Odeme_Linkleri_id = s.id
        LEFT JOIN dbo.Cari c ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
        ORDER BY $siraKolon $siraYon, h.Halkode_Odeme_Linkleri_id DESC
    ";

    $kayitlar = $db->fetchAll($sql, $params);

    // Sayımlar JOIN'siz, tek ana tablodan
    $toplamParams = [];
    $toplamWhere  = " WHERE h.Durum = 1 " . halkodeYetkiKosul('h.Halkode_Odeme_Linkleri_Cari_id', $toplamParams);
    $toplam = (int)($db->fetchOne("SELECT COUNT(*) AS adet FROM dbo.Halkode_Odeme_Linkleri h $toplamWhere", $toplamParams)['adet'] ?? 0);
    $filtre = (int)($db->fetchOne("SELECT COUNT(*) AS adet FROM dbo.Halkode_Odeme_Linkleri h $where", $params)['adet'] ?? 0);

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

    $ozetParams = [];
    $ozetYetki  = halkodeYetkiKosul('h.Halkode_Odeme_Linkleri_Cari_id', $ozetParams);

    $ozet = $db->fetchOne("
        SELECT
            COUNT(*) AS toplam,
            SUM(CASE WHEN h.Halkode_Odeme_Linkleri_DurumKod = 'ODENDI'
                     THEN h.Halkode_Odeme_Linkleri_Tutar ELSE 0 END) AS odenen_tutar,
            SUM(CASE WHEN h.Halkode_Odeme_Linkleri_DurumKod IN ('OLUSTURULDU', 'GONDERILDI')
                     THEN 1 ELSE 0 END) AS bekleyen,
            SUM(CASE WHEN h.Halkode_Odeme_Linkleri_DurumKod = 'BASARISIZ'
                     THEN 1 ELSE 0 END) AS basarisiz
        FROM dbo.Halkode_Odeme_Linkleri h
        WHERE h.Durum = 1 $ozetYetki
    ", $ozetParams);

    echo json_encode(['success' => true, 'ozet' => $ozet], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════
// AJAX - İşlemler
// ═══════════════════════════════════════════════════
if (isset($_POST['action']) && in_array($_POST['action'], ['sorgula', 'gonder', 'iptal', 'detay'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    $islem  = (string)$_POST['action'];
    $linkId = (int)($_POST['link_id'] ?? 0);

    // Okuma dışındaki işlemler düzenleme yetkisi ister
    if ($islem !== 'detay' && !$pagePermissions['can_edit']) {
        echo json_encode(['success' => false, 'message' => 'Bu işlem için düzenleme yetkiniz yok!']);
        exit;
    }

    try {
        $servis = new HalkodeService($db);
        $link   = $servis->linkGetir($linkId);

        if (!$link) {
            throw new Exception('Ödeme linki kaydı bulunamadı.');
        }

        // kendini_gor yetkisinde link kullanıcının cari kümesinde olmalı
        $erisimParams = [$linkId];
        $erisimYetki  = halkodeYetkiKosul('h.Halkode_Odeme_Linkleri_Cari_id', $erisimParams);
        if ($erisimYetki !== '') {
            $erisim = $db->fetchOne(
                "SELECT 1 AS var FROM dbo.Halkode_Odeme_Linkleri h
                 WHERE h.Halkode_Odeme_Linkleri_id = ? $erisimYetki",
                $erisimParams
            );
            if (!$erisim) {
                throw new Exception('Bu ödeme linkine erişim yetkiniz yok.');
            }
        }

        switch ($islem) {
            case 'detay':
                echo json_encode([
                    'success' => true,
                    'data'    => $link,
                    // Yeniden gönderimde hedef cari kartı veya yetkililerden seçilir
                    'kisiler' => $servis->iletisimKisileri((int)$link['Halkode_Odeme_Linkleri_Cari_id']),
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'sorgula':
                $sonuc = $servis->durumSorgulaVeIsle($linkId, $user['kullanici_id']);

                echo json_encode([
                    'success' => true,
                    'durum'   => $sonuc['durum'],
                    'message' => $sonuc['durum'] === HalkodeService::DURUM_ODENDI
                        ? ($sonuc['islendi']
                            ? 'Ödeme alındı, ödeme kalemi oluşturuldu.'
                            : 'Ödeme daha önce işlenmiş, yeni kayıt açılmadı.')
                        : 'Ödeme henüz tamamlanmamış.',
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'gonder':
                if ($link['Halkode_Odeme_Linkleri_DurumKod'] === HalkodeService::DURUM_ODENDI) {
                    throw new Exception('Bu link için ödeme alınmış, yeniden gönderilemez.');
                }

                $kanallar = array_values(array_intersect(
                    (array)json_decode((string)($_POST['kanallar'] ?? '[]'), true),
                    ['sms', 'mail']
                ));

                if (!$kanallar) {
                    throw new Exception('Gönderim kanalı seçilmedi.');
                }

                $sonuc = $servis->linkGonder($linkId, $kanallar, $user['kullanici_id'], [
                    'telefon' => trim((string)($_POST['telefon'] ?? '')),
                    'email'   => trim((string)($_POST['email'] ?? '')),
                ]);
                $basari = [];

                if (!empty($sonuc['sms']))  $basari[] = 'SMS';
                if (!empty($sonuc['mail'])) $basari[] = 'E-posta';

                echo json_encode([
                    'success' => (bool)$basari,
                    'message' => $basari
                        ? implode(' ve ', $basari) . ' ile gönderildi.'
                        : implode(' ', $sonuc['hatalar']),
                    'hatalar' => $sonuc['hatalar'],
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'iptal':
                if ($link['Halkode_Odeme_Linkleri_DurumKod'] === HalkodeService::DURUM_ODENDI) {
                    throw new Exception('Ödemesi alınmış link iptal edilemez.');
                }

                // Yalnızca portaldaki takip kaydı iptal edilir; VPOS tarafındaki
                // link süresi dolana kadar teknik olarak açık kalabilir.
                $db->update('Halkode_Odeme_Linkleri', [
                    'Halkode_Odeme_Linkleri_DurumKod'   => HalkodeService::DURUM_IPTAL,
                    'Halkode_Odeme_Linkleri_SonucMesaj' => 'Kullanıcı tarafından iptal edildi.',
                    'GuncelleyenKullanici'              => $user['kullanici_id'],
                    'GuncellemeTarihi'                  => date('Y-m-d H:i:s'),
                ], [
                    'Halkode_Odeme_Linkleri_id' => $linkId,
                ]);

                echo json_encode(['success' => true, 'message' => 'Link iptal edildi.'], JSON_UNESCAPED_UNICODE);
                break;
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

// ═══════════════════════════════════════════════════
// Excel indirme
// ═══════════════════════════════════════════════════
if (isset($_GET['excel']) && $_GET['excel'] === '1') {
    $params = [];
    $where  = halkodeFiltreKosul($_POST, $params);

    $kayitlar = $db->fetchAll("
        SELECT
            CONVERT(VARCHAR(19), h.OlusturmaTarihi, 120)                    AS olusturma,
            ISNULL(c.cari_unvan, c.cari_adi)                                AS cari_unvan,
            h.Halkode_Odeme_Linkleri_Tutar                                  AS tutar,
            h.Halkode_Odeme_Linkleri_Taksit                                 AS taksit,
            h.Halkode_Odeme_Linkleri_DurumKod                               AS durum,
            h.Halkode_Odeme_Linkleri_GonderimKanali                         AS gonderim_kanali,
            CONVERT(VARCHAR(19), h.Halkode_Odeme_Linkleri_OdemeTarihi, 120) AS odeme_tarihi,
            h.Halkode_Odeme_Linkleri_AuthCode                               AS auth_code,
            h.Halkode_Odeme_Linkleri_KartMaske                              AS kart_maske,
            h.Halkode_Odeme_Linkleri_SiparisNo                              AS siparis_no,
            h.Halkode_Odeme_Linkleri_Aciklama                               AS aciklama
        FROM dbo.Halkode_Odeme_Linkleri h
        LEFT JOIN dbo.Cari c ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
        $where
        ORDER BY h.Halkode_Odeme_Linkleri_id DESC
    ", $params);

    $durumAd = [
        'OLUSTURULDU' => 'Oluşturuldu',
        'GONDERILDI'  => 'Gönderildi',
        'ODENDI'      => 'Ödendi',
        'BASARISIZ'   => 'Başarısız',
        'IPTAL'       => 'İptal',
    ];

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="halkode_odeme_linkleri_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head><body>';
    echo '<table border="1"><thead><tr style="background-color:#00539F;color:#fff;font-weight:bold;">';
    echo '<th>Oluşturma</th><th>Cari</th><th>Tutar</th><th>Taksit</th><th>Durum</th><th>Gönderim</th>';
    echo '<th>Ödeme Tarihi</th><th>Onay Kodu</th><th>Kart</th><th>Sipariş No</th><th>Açıklama</th>';
    echo '</tr></thead><tbody>';

    foreach ($kayitlar as $k) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($k['olusturma'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['cari_unvan'] ?? '') . '</td>';
        echo '<td style="text-align:right">' . number_format((float)($k['tutar'] ?? 0), 2, ',', '.') . '</td>';
        echo '<td style="text-align:center">' . (int)($k['taksit'] ?? 1) . '</td>';
        echo '<td>' . htmlspecialchars($durumAd[$k['durum']] ?? ($k['durum'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($k['gonderim_kanali'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['odeme_tarihi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['auth_code'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['kart_maske'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['siparis_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['aciklama'] ?? '') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// ═══════════════════════════════════════════════════
// Filtre kaynağı — yalnızca linki olan cariler
// ═══════════════════════════════════════════════════
$cariParams  = [];
$cariYetki   = halkodeYetkiKosul('h.Halkode_Odeme_Linkleri_Cari_id', $cariParams);
$cariListesi = $db->fetchAll("
    SELECT DISTINCT h.Halkode_Odeme_Linkleri_Cari_id AS id,
           ISNULL(c.cari_unvan, c.cari_adi) AS unvan
    FROM dbo.Halkode_Odeme_Linkleri h
    LEFT JOIN dbo.Cari c ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
    WHERE h.Durum = 1 $cariYetki
    ORDER BY ISNULL(c.cari_unvan, c.cari_adi)
", $cariParams);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .info-box-icon  { width: 50px; height: 50px; font-size: 1.5rem; }
        .tutar-hucre    { font-weight: 600; }
        .aciklama-hucre { max-width: 280px; }
        .link-kutu      { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; }
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
                            <span class="info-box-icon"><i class="bi bi-link-45deg"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Link</span>
                                <span class="info-box-number" id="ibToplam">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Tahsil Edilen</span>
                                <span class="info-box-number" id="ibOdenen">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Ödeme Bekleyen</span>
                                <span class="info-box-number" id="ibBekleyen">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Başarısız</span>
                                <span class="info-box-number" id="ibBasarisiz">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
                <div class="card mb-3">
                    <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <i class="bi bi-funnel me-1"></i> Filtrele
                    </div>
                    <div class="collapse show" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">Cari</label>
                                    <select id="cari_id" class="form-select select2-searchable">
                                        <option value="">Tümü</option>
                                        <?php foreach ($cariListesi as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['unvan'] ?? '') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select id="durum" class="form-select select2-searchable">
                                        <option value="">Tümü</option>
                                        <option value="OLUSTURULDU">Oluşturuldu</option>
                                        <option value="GONDERILDI">Gönderildi</option>
                                        <option value="ODENDI">Ödendi</option>
                                        <option value="BASARISIZ">Başarısız</option>
                                        <option value="IPTAL">İptal</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Ara</label>
                                    <input type="text" id="arama" class="form-control" placeholder="Sipariş no, onay kodu, kart, telefon, e-posta...">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="taksitli" value="1">
                                        <label class="form-check-label" for="taksitli">Yalnızca taksitli</label>
                                    </div>
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
                                    <button type="button" id="btnTemizle" class="btn btn-outline-secondary mb-2 w-100">
                                        <i class="bi bi-x-circle me-1"></i> Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Link Tablosu -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="bi bi-credit-card-2-front me-1"></i> Ödeme Linkleri</h3>
                        <div class="d-flex gap-2">
                            <?php if ($pagePermissions['can_add']): ?>
                                <a href="odeme-form" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-lg me-1"></i> Yeni Link (Ödeme Formu)
                                </a>
                            <?php endif; ?>
                            <button type="button" id="btnExcel" class="btn btn-success btn-sm">
                                <i class="bi bi-file-earmark-excel me-1"></i> Excel İndir
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="linkTable" class="table table-striped table-bordered align-middle" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Oluşturma</th>
                                    <th>Cari</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="text-center">Taksit</th>
                                    <th class="text-center">Durum</th>
                                    <th>Gönderim</th>
                                    <th>Ödeme</th>
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

<!-- Detay / Gönderim Modalı -->
<div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-credit-card-2-front me-1"></i> Ödeme Linki Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="d_link_id">
                <div id="d_govde"></div>

                <div id="d_gonderim_alan" class="border rounded p-3 mt-3">
                    <div class="fw-semibold mb-2"><i class="bi bi-send me-1"></i> Linki Yeniden Gönder</div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Gönderilecek Kişi</label>
                            <select id="d_kisi" class="form-select form-select-sm"></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefon</label>
                            <input type="tel" id="d_telefon" class="form-control form-control-sm" placeholder="5xxxxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-posta</label>
                            <input type="email" id="d_email" class="form-control form-control-sm" placeholder="musteri@ornek.com">
                        </div>
                    </div>
                    <div class="form-check form-switch d-inline-block me-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="d_sms" value="1" checked>
                        <label class="form-check-label" for="d_sms">SMS</label>
                    </div>
                    <div class="form-check form-switch d-inline-block me-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="d_mail" value="1" checked>
                        <label class="form-check-label" for="d_mail">E-posta</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" id="btnYenidenGonder">
                        <i class="bi bi-send me-1"></i> Gönder
                    </button>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnDetaySorgula">
                        <i class="bi bi-arrow-clockwise me-1"></i> Durumu Sorgula
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm d-none" id="btnDetayIptal">
                        <i class="bi bi-x-circle me-1"></i> Linki İptal Et
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
$(function () {
    $('.select2-searchable').select2({ theme: 'bootstrap-5', width: '100%' });

    const duzenleYetki = <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>;
    let   dKisiler     = [];
    const esc = t => $('<div>').text(t == null ? '' : t).html();

    const paraFormat = (deger, birim) => parseFloat(deger || 0).toLocaleString('tr-TR', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    }) + ' ' + (birim === 'TRY' || !birim ? '₺' : birim);

    const DURUM = {
        OLUSTURULDU: ['secondary', 'Oluşturuldu'],
        GONDERILDI:  ['info',      'Gönderildi'],
        ODENDI:      ['success',   'Ödendi'],
        BASARISIZ:   ['danger',    'Başarısız'],
        IPTAL:       ['dark',      'İptal'],
    };

    const durumRozet = d => {
        const [renk, metin] = DURUM[d] || ['secondary', d || '-'];
        return '<span class="badge text-bg-' + renk + '">' + metin + '</span>';
    };

    // Taksit 0 ise seçim ödeme sayfasında müşteriye bırakılmıştır
    const taksitMetni = d => {
        const s = parseInt(d, 10) || 0;
        return s > 1 ? s + ' taksit' : (s === 1 ? 'Tek çekim' : 'Müşteri seçimi');
    };

    const tarihKisa = d => {
        if (!d) return '-';
        const [tarih, saat] = d.split(' ');
        const p = tarih.split('-');
        return p[2] + '.' + p[1] + '.' + p[0] +
               '<br><small class="text-muted">' + (saat || '').substring(0, 5) + '</small>';
    };

    const filtreler = d => {
        d.cari_id         = $('#cari_id').val();
        d.durum           = $('#durum').val();
        d.arama           = $('#arama').val();
        d.taksitli        = $('#taksitli').is(':checked') ? 1 : '';
        d.baslangic_tarih = $('#baslangic_tarih').val();
        d.bitis_tarih     = $('#bitis_tarih').val();
        d.min_tutar       = $('#min_tutar').val();
        d.max_tutar       = $('#max_tutar').val();
    };

    const table = $('#linkTable').DataTable({
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
            { data: 'olusturma', render: tarihKisa },
            {
                data: 'cari_unvan',
                render: (d, t, row) => esc(d) +
                    (row.aciklama ? '<br><small class="text-muted">' + esc(row.aciklama) + '</small>' : '')
            },
            {
                data: 'tutar',
                className: 'text-end tutar-hucre',
                render: (d, t, row) => paraFormat(d, row.para_birimi)
            },
            {
                data: 'taksit',
                className: 'text-center',
                render: d => taksitMetni(d)
            },
            {
                data: 'durum',
                className: 'text-center',
                render: (d, t, row) => durumRozet(d) +
                    (d === 'BASARISIZ' && row.sonuc_mesaj
                        ? '<br><small class="text-danger">' + esc(row.sonuc_mesaj) + '</small>' : '')
            },
            {
                data: 'gonderim_kanali',
                render: (d, t, row) => d
                    ? esc(d) + '<br><small class="text-muted">' + (row.gonderim_tarihi || '').substring(0, 16) + '</small>'
                    : '<span class="text-muted">Gönderilmedi</span>'
            },
            {
                data: 'odeme_tarihi',
                render: (d, t, row) => {
                    if (!d) return '<span class="text-muted">—</span>';

                    let ek = [];
                    if (row.auth_code)  ek.push('Onay: ' + esc(row.auth_code));
                    if (row.kart_maske) ek.push(esc(row.kart_maske));

                    return d.substring(0, 16) +
                        (ek.length ? '<br><small class="text-muted">' + ek.join(' · ') + '</small>' : '');
                }
            },
            {
                data: 'id',
                className: 'text-center',
                orderable: false,
                render: (d, t, row) => {
                    let dugmeler = '';

                    if (row.link) {
                        dugmeler += '<button class="btn btn-sm btn-outline-secondary btn-kopyala me-1" ' +
                                    'data-link="' + esc(row.link) + '" title="Linki kopyala">' +
                                    '<i class="bi bi-clipboard"></i></button>';
                    }

                    dugmeler += '<button class="btn btn-sm btn-outline-primary btn-detay" data-id="' + d +
                                '" title="Detay ve işlemler"><i class="bi bi-three-dots"></i></button>';

                    return dugmeler;
                }
            }
        ],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        }
    });

    // Filtre değişiminde tabloyu yenile
    $('#cari_id, #durum, #baslangic_tarih, #bitis_tarih, #taksitli').on('change', () => table.ajax.reload());

    let zamanlayici = null;
    $('#arama, #min_tutar, #max_tutar').on('keyup change', function () {
        clearTimeout(zamanlayici);
        zamanlayici = setTimeout(() => table.ajax.reload(), 400);
    });

    $('#btnTemizle').on('click', function () {
        $('#cari_id, #durum').val('').trigger('change.select2');
        $('#arama, #baslangic_tarih, #bitis_tarih, #min_tutar, #max_tutar').val('');
        $('#taksitli').prop('checked', false);
        table.ajax.reload();
    });

    // InfoBox — tablodan bağımsız
    function ozetYukle() {
        $.getJSON('?ajax=stats', function (res) {
            if (!res.success) return;

            const o = res.ozet || {};
            $('#ibToplam').text(o.toplam ?? 0);
            $('#ibOdenen').text(paraFormat(o.odenen_tutar));
            $('#ibBekleyen').text(o.bekleyen ?? 0);
            $('#ibBasarisiz').text(o.basarisiz ?? 0);
        });
    }

    ozetYukle();

    // ── Linki kopyala
    $('#linkTable').on('click', '.btn-kopyala', function () {
        const adres = $(this).data('link');

        navigator.clipboard.writeText(adres).then(
            () => showSuccess('Kopyalandı', 'Ödeme linki panoya kopyalandı.'),
            () => showError('Hata', 'Link kopyalanamadı, detay ekranından elle kopyalayın.')
        );
    });

    // ── Detay modalı
    $('#linkTable').on('click', '.btn-detay', function () {
        const id = $(this).data('id');
        $('#d_link_id').val(id);

        $.post('', { action: 'detay', link_id: id }, function (res) {
            if (!res.success) { showError('Hata', res.message); return; }

            const k = res.data;
            const durum = k.Halkode_Odeme_Linkleri_DurumKod;
            const odendi = durum === 'ODENDI';

            const satir = (etiket, deger) => deger
                ? '<div class="row mb-1"><div class="col-4 text-muted">' + etiket +
                  '</div><div class="col-8">' + deger + '</div></div>'
                : '';

            let govde = ''
                + satir('Cari', esc(k.cari_unvan))
                + satir('Tutar', paraFormat(k.Halkode_Odeme_Linkleri_Tutar, k.Halkode_Odeme_Linkleri_ParaBirimi))
                + satir('Taksit', taksitMetni(k.Halkode_Odeme_Linkleri_Taksit))
                + satir('Durum', durumRozet(durum))
                + satir('Sipariş No', '<span class="link-kutu">' + esc(k.Halkode_Odeme_Linkleri_SiparisNo) + '</span>')
                + satir('İşlem No', k.Halkode_Odeme_Linkleri_ProcessId
                        ? '<span class="link-kutu">' + esc(k.Halkode_Odeme_Linkleri_ProcessId) + '</span>' : '')
                + satir('Telefon', esc(k.Halkode_Odeme_Linkleri_Telefon || k.cari_telefon))
                + satir('E-posta', esc(k.Halkode_Odeme_Linkleri_Email || k.cari_email))
                + satir('Gönderim', k.Halkode_Odeme_Linkleri_GonderimKanali
                        ? esc(k.Halkode_Odeme_Linkleri_GonderimKanali) + ' — ' +
                          (k.Halkode_Odeme_Linkleri_GonderimTarihi || '').substring(0, 16) : '')
                + satir('Ödeme Tarihi', (k.Halkode_Odeme_Linkleri_OdemeTarihi || '').substring(0, 16))
                + satir('Onay Kodu', esc(k.Halkode_Odeme_Linkleri_AuthCode))
                + satir('Referans (RRN)', esc(k.Halkode_Odeme_Linkleri_Rrn))
                + satir('Kart', esc(k.Halkode_Odeme_Linkleri_KartMaske))
                + satir('Sonuç', esc(k.Halkode_Odeme_Linkleri_SonucMesaj))
                + satir('Açıklama', esc(k.Halkode_Odeme_Linkleri_Aciklama));

            if (k.Halkode_Odeme_Linkleri_OdemeKalem_id) {
                govde += satir('Ödeme Kalemi',
                    '<span class="badge text-bg-success">#' + k.Halkode_Odeme_Linkleri_OdemeKalem_id +
                    '</span> <small class="text-muted">otomatik oluşturuldu</small>');
            }

            if (k.Halkode_Odeme_Linkleri_Link) {
                govde += '<div class="input-group input-group-sm mt-3">' +
                         '<input type="text" class="form-control link-kutu" id="d_link" readonly value="' +
                         esc(k.Halkode_Odeme_Linkleri_Link) + '">' +
                         '<button class="btn btn-outline-secondary btn-kopyala" type="button" data-link="' +
                         esc(k.Halkode_Odeme_Linkleri_Link) + '"><i class="bi bi-clipboard"></i> Kopyala</button>' +
                         '</div>';
            }

            $('#d_govde').html(govde);

            // Kişi listesi: cari kartı + yetkililer. Kayıttaki telefon/e-posta
            // ile eşleşen kişi varsa o seçili gelir.
            dKisiler = res.kisiler || [];

            const kayitliTelefon = (k.Halkode_Odeme_Linkleri_Telefon || '').replace(/\D/g, '');
            const kayitliEmail   = (k.Halkode_Odeme_Linkleri_Email || '').toLowerCase();
            const kisiSecim      = $('#d_kisi').empty();
            let   secili         = 0;

            dKisiler.forEach(function (kisi, i) {
                const eksik = [];
                if (!kisi.telefon) eksik.push('telefon yok');
                if (!kisi.email)   eksik.push('e-posta yok');

                kisiSecim.append($('<option>', {
                    value: i,
                    text : kisi.ad + ' — ' + kisi.gorev + (eksik.length ? ' (' + eksik.join(', ') + ')' : '')
                }));

                if ((kayitliTelefon && kisi.telefon === kayitliTelefon)
                    || (kayitliEmail && (kisi.email || '').toLowerCase() === kayitliEmail)) {
                    secili = i;
                }
            });

            if (dKisiler.length) {
                kisiSecim.val(secili);
            }

            // Kayıtta özel bir hedef varsa alanlar onunla doldurulur
            $('#d_telefon').val(kayitliTelefon || (dKisiler[secili] ? dKisiler[secili].telefon : ''));
            $('#d_email').val(k.Halkode_Odeme_Linkleri_Email || (dKisiler[secili] ? dKisiler[secili].email : ''));

            // Ödenmiş linkte gönderim ve iptal kapalı
            $('#d_gonderim_alan').toggleClass('d-none', odendi || !duzenleYetki || !k.Halkode_Odeme_Linkleri_Link);
            $('#btnDetayIptal').toggleClass('d-none', odendi || !duzenleYetki || durum === 'IPTAL');
            $('#btnDetaySorgula').toggleClass('d-none', !duzenleYetki);

            new bootstrap.Modal(document.getElementById('detayModal')).show();
        }, 'json').fail(() => showError('Hata', 'Detay alınamadı.'));
    });

    // ── Durum sorgula
    $('#btnDetaySorgula').on('click', function () {
        const btn = $(this).prop('disabled', true);

        $.post('', { action: 'sorgula', link_id: $('#d_link_id').val() }, function (res) {
            if (!res.success) { showError('Hata', res.message); return; }

            if (res.durum === 'ODENDI') {
                showSuccess('Ödeme Alındı', res.message);
            } else {
                showWarning('Bekliyor', res.message);
            }

            table.ajax.reload(null, false);
            ozetYukle();
            $('#linkTable .btn-detay[data-id="' + $('#d_link_id').val() + '"]').trigger('click');
        }, 'json').fail(() => showError('Hata', 'Sorgulama başarısız.'))
          .always(() => btn.prop('disabled', false));
    });

    // Kişi seçimi telefon ve e-posta alanlarını doldurur; alanlar elle de düzenlenebilir
    $('#d_kisi').on('change', function () {
        const kisi = dKisiler[parseInt($(this).val(), 10)];

        if (!kisi) return;

        $('#d_telefon').val(kisi.telefon || '');
        $('#d_email').val(kisi.email || '');
    });

    // ── Yeniden gönder
    $('#btnYenidenGonder').on('click', function () {
        const kanallar = [];
        if ($('#d_sms').is(':checked'))  kanallar.push('sms');
        if ($('#d_mail').is(':checked')) kanallar.push('mail');

        if (!kanallar.length) { showError('Hata', 'En az bir gönderim kanalı seçin.'); return; }

        const btn = $(this).prop('disabled', true);

        $.post('', {
            action: 'gonder',
            link_id: $('#d_link_id').val(),
            kanallar: JSON.stringify(kanallar),
            telefon: $('#d_telefon').val(),
            email:   $('#d_email').val()
        }, function (res) {
            if (res.success) {
                showSuccess('Gönderildi', res.message);
                table.ajax.reload(null, false);
            } else {
                showError('Gönderilemedi', res.message);
            }
        }, 'json').fail(() => showError('Hata', 'Gönderim başarısız.'))
          .always(() => btn.prop('disabled', false));
    });

    // ── İptal
    $('#btnDetayIptal').on('click', function () {
        Swal.fire({
            title: 'Link iptal edilsin mi?',
            text: 'Portaldaki takip kaydı iptal olarak işaretlenecek. Müşteri linki henüz kullanmadıysa HalkÖde tarafında süresi dolana kadar açık kalabilir.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'İptal Et',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#dc3545'
        }).then(function (secim) {
            if (!secim.isConfirmed) return;

            $.post('', { action: 'iptal', link_id: $('#d_link_id').val() }, function (res) {
                if (res.success) {
                    showSuccess('İptal Edildi', res.message);
                    bootstrap.Modal.getInstance(document.getElementById('detayModal')).hide();
                    table.ajax.reload(null, false);
                    ozetYukle();
                } else {
                    showError('Hata', res.message);
                }
            }, 'json').fail(() => showError('Hata', 'İptal işlemi başarısız.'));
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
