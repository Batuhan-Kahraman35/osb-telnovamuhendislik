<?php
/**
 * Admin Panel - Tedarikci Siparisleri
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Bu sayfaya erisim yetkiniz bulunmamaktadir.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erisim yetkiniz bulunmamaktadir.');
}

$pageInfo = $db->fetchOne(" 
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Tedarikci Siparisleri';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$tedarikciTipId = 3;

$tedarikciler = $db->fetchAll("
    SELECT c.cari_id, c.cari_adi, c.cari_email
    FROM dbo.Cari c
    INNER JOIN dbo.Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id
    WHERE c.cari_aktif = 1
            AND c.cari_tipi_id = ?
      AND ct.cari_tipi_durum = 1
    ORDER BY c.cari_adi
", [$tedarikciTipId]);

$urunler = $db->fetchAll("
    SELECT urun_hizmet_id, urun_hizmet_adi, urun_hizmet_kodu
    FROM dbo.Urun_Hizmet
    WHERE urun_hizmet_durum = 1
    ORDER BY urun_hizmet_adi
");

$mailKanallari = $db->fetchAll("
    SELECT k.Kanal_Id, k.Kanal_Adi, k.Kanal_Kullanici
    FROM dbo.EntegrasyonKanallari k
    INNER JOIN dbo.Entegrasyon e ON k.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
    WHERE e.Entegrasyon_Kod = 'MAIL' AND k.Kanal_Durum = 1
    ORDER BY k.Kanal_Varsayilan DESC, k.Kanal_Adi
");

$siparisDurumlari = $db->fetchAll("
    SELECT Teklif_Durumlari_id, Teklif_Durumlari_ad, Teklif_Durumlari_renk
    FROM dbo.Teklif_Durumlari
    WHERE Durum = 1
    ORDER BY Teklif_Durumlari_sira_no
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
                        case 'stats':
                $toplam     = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.TedarikciSiparisleri")['s'] ?? 0;
                $hazirlandi = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.TedarikciSiparisleri WHERE TedarikciSiparisleri_Durum_id = 1")['s'] ?? 0;
                $gonderildi = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.TedarikciSiparisleri WHERE TedarikciSiparisleri_Durum_id = 2")['s'] ?? 0;
                $hata       = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.TedarikciSiparisleri WHERE TedarikciSiparisleri_Durum_id = 4")['s'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplam', 'hazirlandi', 'gonderildi', 'hata')]);
                break;

                        case 'list':
                $durumuId = (int)($_POST['durum_id'] ?? 0);
                $tedarikciId = (int)($_POST['tedarikci_id'] ?? 0);
                $search = trim($_POST['search'] ?? '');

                $where = "WHERE 1=1";
                $params = [];

                if ($durumuId > 0) {
                    $where .= " AND ts.TedarikciSiparisleri_Durum_id = ?";
                    $params[] = $durumuId;
                }
                if ($tedarikciId > 0) {
                    $where .= " AND ts.TedarikciSiparisleri_Tedarikci_id = ?";
                    $params[] = $tedarikciId;
                }
                if ($search !== '') {
                    $where .= " AND (ts.TedarikciSiparisleri_SiparisKodu LIKE ? OR c.cari_adi LIKE ? OR c.cari_email LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                                $rows = $db->fetchAll(" 
                    SELECT
                        ts.TedarikciSiparisleri_id,
                        ts.TedarikciSiparisleri_SiparisKodu,
                        ts.TedarikciSiparisleri_ToplamKalem,
                        ts.TedarikciSiparisleri_ToplamAdet,
                        ts.TedarikciSiparisleri_Durum_id,
                        td.Teklif_Durumlari_ad  AS Durum_Ad,
                        td.Teklif_Durumlari_renk AS Durum_Renk,
                        ts.TedarikciSiparisleri_MailGonderimTarihi,
                        ts.TedarikciSiparisleri_MailHataMesaji,
                        ts.TedarikciSiparisleri_MailDenemeSayisi,
                        CONVERT(VARCHAR(16), ts.TedarikciSiparisleri_SiparisTarihi, 120) AS SiparisTarihi,
                        c.cari_adi,
                        c.cari_email,
                        k.Kanal_Adi
                    FROM dbo.TedarikciSiparisleri ts
                    INNER JOIN dbo.Cari c ON c.cari_id = ts.TedarikciSiparisleri_Tedarikci_id
                    LEFT JOIN dbo.Teklif_Durumlari td ON td.Teklif_Durumlari_id = ts.TedarikciSiparisleri_Durum_id
                    LEFT JOIN dbo.EntegrasyonKanallari k ON k.Kanal_Id = ts.TedarikciSiparisleri_GonderenHesap_id
                    $where
                    ORDER BY ts.TedarikciSiparisleri_id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'create':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $tedarikciId = (int)($_POST['tedarikci_id'] ?? 0);
                $gonderenHesapId = (int)($_POST['gonderen_hesap_id'] ?? 0);
                $kalemler = json_decode($_POST['kalemler'] ?? '[]', true);

                if ($tedarikciId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Tedarikci secimi zorunludur.']);
                    break;
                }

                $tedarikciKontrol = $db->fetchOne(
                    "SELECT TOP 1 cari_id FROM dbo.Cari WHERE cari_id = ? AND cari_aktif = 1 AND cari_tipi_id = ?",
                    [$tedarikciId, $tedarikciTipId]
                );
                if (!$tedarikciKontrol) {
                    echo json_encode(['success' => false, 'message' => 'Sadece tedarikci tipi (ID: 3) secilebilir.']);
                    break;
                }

                if (empty($kalemler) || !is_array($kalemler)) {
                    echo json_encode(['success' => false, 'message' => 'En az bir kalem eklemelisiniz.']);
                    break;
                }

                $siparisKodu = 'TSP-' . date('Ymd-His') . '-' . random_int(100, 999);

                $siparisId = $db->insert('TedarikciSiparisleri', [
                    'TedarikciSiparisleri_SiparisKodu' => $siparisKodu,
                    'TedarikciSiparisleri_Tedarikci_id' => $tedarikciId,
                    'TedarikciSiparisleri_GonderenHesap_id' => $gonderenHesapId > 0 ? $gonderenHesapId : null,
                    'TedarikciSiparisleri_SiparisTarihi' => date('Y-m-d H:i:s'),
                    'TedarikciSiparisleri_ToplamKalem' => 0,
                    'TedarikciSiparisleri_ToplamAdet' => 0,
                                        'TedarikciSiparisleri_Durum_id' => 1,
                    'TedarikciSiparisleri_MailDenemeSayisi' => 0,
                    'OlusturanKullanici' => $user['kullanici_id'],
                    'OlusturmaTarihi' => date('Y-m-d H:i:s')
                ]);

                $toplamAdet = 0;
                $toplamKalem = 0;

                foreach ($kalemler as $kalem) {
                    $urunId = (int)($kalem['urun_id'] ?? 0);
                    $adet = (float)($kalem['adet'] ?? 0);
                    $aciklama = trim($kalem['aciklama'] ?? '');

                    if ($urunId <= 0 || $adet <= 0) {
                        continue;
                    }

                                        $db->insert('TedarikciSiparisKalemleri', [
                        'TedarikciSiparisKalemleri_TedarikciSiparisleri_id' => $siparisId,
                        'TedarikciSiparisKalemleri_Urun_id' => $urunId,
                        'TedarikciSiparisKalemleri_Adet' => $adet,
                        'TedarikciSiparisKalemleri_Aciklama' => $aciklama !== '' ? $aciklama : null,
                        'OlusturanKullanici' => $user['kullanici_id'],
                        'OlusturmaTarihi' => date('Y-m-d H:i:s')
                    ]);

                    $toplamKalem++;
                    $toplamAdet += $adet;
                }

                if ($toplamKalem <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Gecerli kalem bulunamadi.']);
                    break;
                }

                $db->update('TedarikciSiparisleri', [
                    'TedarikciSiparisleri_ToplamKalem' => $toplamKalem,
                    'TedarikciSiparisleri_ToplamAdet' => $toplamAdet,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi' => date('Y-m-d H:i:s')
                ], ['TedarikciSiparisleri_id' => $siparisId]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Siparis olusturuldu.',
                    'siparis_id' => $siparisId
                ]);
                break;

            case 'send_mail':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Mail gonderim yetkiniz yok!']);
                    break;
                }

                $siparisId = (int)($_POST['siparis_id'] ?? 0);
                if ($siparisId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Gecersiz siparis.']);
                    break;
                }

                                $siparis = $db->fetchOne(" 
                    SELECT ts.*, c.cari_adi, c.cari_email
                    FROM dbo.TedarikciSiparisleri ts
                    INNER JOIN dbo.Cari c ON c.cari_id = ts.TedarikciSiparisleri_Tedarikci_id
                    WHERE ts.TedarikciSiparisleri_id = ?
                ", [$siparisId]);

                if (!$siparis) {
                    echo json_encode(['success' => false, 'message' => 'Siparis bulunamadi.']);
                    break;
                }

                                $aliciEmail = trim((string)($siparis['cari_email'] ?? ''));
                if ($aliciEmail === '' || !filter_var($aliciEmail, FILTER_VALIDATE_EMAIL)) {
                    $db->update('TedarikciSiparisleri', [
                        'TedarikciSiparisleri_Durum_id' => 4,
                        'TedarikciSiparisleri_MailHataMesaji' => 'Tedarikci e-posta adresi bos/gecersiz.',
                        'TedarikciSiparisleri_MailDenemeSayisi' => ((int)$siparis['TedarikciSiparisleri_MailDenemeSayisi']) + 1,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi' => date('Y-m-d H:i:s')
                    ], ['TedarikciSiparisleri_id' => $siparisId]);

                    echo json_encode(['success' => false, 'message' => 'Tedarikci e-posta adresi gecersiz.']);
                    break;
                }

                $kanalId = (int)($siparis['TedarikciSiparisleri_GonderenHesap_id'] ?? 0);
                if ($kanalId <= 0) {
                    $varsayilanKanal = $db->fetchOne(" 
                        SELECT TOP 1 k.Kanal_Id
                        FROM dbo.EntegrasyonKanallari k
                        INNER JOIN dbo.Entegrasyon e ON k.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                        WHERE e.Entegrasyon_Kod = 'MAIL' AND k.Kanal_Durum = 1
                        ORDER BY k.Kanal_Varsayilan DESC, k.Kanal_Id
                    ");
                    $kanalId = (int)($varsayilanKanal['Kanal_Id'] ?? 0);
                }

                if ($kanalId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Aktif mail kanali bulunamadi.']);
                    break;
                }

                                $kalemler = $db->fetchAll(" 
                    SELECT k.TedarikciSiparisKalemleri_Adet, k.TedarikciSiparisKalemleri_Aciklama,
                           u.urun_hizmet_adi, u.urun_hizmet_kodu
                    FROM dbo.TedarikciSiparisKalemleri k
                    INNER JOIN dbo.Urun_Hizmet u ON u.urun_hizmet_id = k.TedarikciSiparisKalemleri_Urun_id
                    WHERE k.TedarikciSiparisKalemleri_TedarikciSiparisleri_id = ?
                    ORDER BY k.TedarikciSiparisKalemleri_id
                ", [$siparisId]);

                if (empty($kalemler)) {
                    echo json_encode(['success' => false, 'message' => 'Siparis kalemi bulunamadi.']);
                    break;
                }

                $rows = '';
                foreach ($kalemler as $index => $kalem) {
                    $rows .= '<tr>'
                        . '<td style="padding:6px;border:1px solid #ddd;">' . ($index + 1) . '</td>'
                        . '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars((string)$kalem['urun_hizmet_adi']) . '</td>'
                        . '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars((string)($kalem['urun_hizmet_kodu'] ?? '')) . '</td>'
                        . '<td style="padding:6px;border:1px solid #ddd;text-align:right;">' . number_format((float)$kalem['TedarikciSiparisKalemleri_Adet'], 2, ',', '.') . '</td>'
                        . '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars((string)($kalem['TedarikciSiparisKalemleri_Aciklama'] ?? '')) . '</td>'
                        . '</tr>';
                }

                                // Onay/Red token uret (her mail gonderiminde yenilenir, eski linkler gecersiz olur)
                try {
                    $onayToken = bin2hex(random_bytes(16)); // 32 karakter hex
                } catch (Throwable $ex) {
                    $onayToken = bin2hex(openssl_random_pseudo_bytes(16));
                }

                $db->update('TedarikciSiparisleri', [
                    'TedarikciSiparisleri_OnayToken'       => $onayToken,
                    'TedarikciSiparisleri_OnayYanitTarihi' => null,
                    'TedarikciSiparisleri_OnayYanitIP'     => null
                ], ['TedarikciSiparisleri_id' => $siparisId]);

                // Base URL (onay/red sayfasi) - https zorunlu
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'panel.ornekfirma.com';
                $baseUrl = $scheme . '://' . $host;
                $onayUrl = $baseUrl . '/admin/pages/tedarikci-siparis-onay.php?token=' . $onayToken . '&action=onay';
                $redUrl  = $baseUrl . '/admin/pages/tedarikci-siparis-onay.php?token=' . $onayToken . '&action=red';

                $mailKonu = 'Örnek Mühendislik Sipariş - ' . $siparis['TedarikciSiparisleri_SiparisKodu'];
                $mailIcerik = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;max-width:720px;">'
                    . '<p>Merhaba,</p>'
                    . '<p>Aşağıdaki ürünlerin siparişlerinin onaylanması ve tahmini teslim süresi bilgisi rica ederim.</p>'
                    . '<p><b>Sipariş Kodu:</b> ' . htmlspecialchars((string)$siparis['TedarikciSiparisleri_SiparisKodu']) . '<br>'
                    . '<b>Tarih:</b> ' . date('d.m.Y H:i') . '</p>'
                    . '<table style="border-collapse:collapse;width:100%;font-size:13px;">'
                    . '<thead><tr style="background:#f5f5f5;">'
                    . '<th style="padding:6px;border:1px solid #ddd;text-align:left;">#</th>'
                    . '<th style="padding:6px;border:1px solid #ddd;text-align:left;">Ürün</th>'
                    . '<th style="padding:6px;border:1px solid #ddd;text-align:left;">Kod</th>'
                    . '<th style="padding:6px;border:1px solid #ddd;text-align:right;">Adet</th>'
                    . '<th style="padding:6px;border:1px solid #ddd;text-align:left;">Açıklama</th>'
                    . '</tr></thead>'
                    . '<tbody>' . $rows . '</tbody></table>'

                    // Onay / Red butonlari
                    . '<div style="margin:28px 0 12px;padding:20px;background:#f8f9fa;border-radius:8px;text-align:center;">'
                    . '<p style="margin:0 0 14px;font-size:15px;color:#333;"><b>Lütfen sipariş hakkındaki kararınızı aşağıdaki butonlardan belirtiniz:</b></p>'
                    . '<table role="presentation" style="margin:0 auto;"><tr>'
                    . '<td style="padding:0 8px;">'
                    . '<a href="' . htmlspecialchars($onayUrl) . '" target="_blank" '
                    . 'style="display:inline-block;padding:14px 32px;background:#198754;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:15px;">'
                    . '&#10004; Siparişi Onayla</a>'
                    . '</td>'
                    . '<td style="padding:0 8px;">'
                    . '<a href="' . htmlspecialchars($redUrl) . '" target="_blank" '
                    . 'style="display:inline-block;padding:14px 32px;background:#dc3545;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:15px;">'
                    . '&#10008; Siparişi Reddet</a>'
                    . '</td>'
                    . '</tr></table>'
                    . '<p style="margin:14px 0 0;font-size:11px;color:#888;">Buton çalışmazsa aşağıdaki bağlantıları tarayıcınıza kopyalayın:<br>'
                    . '<span style="color:#198754;">Onay:</span> <a href="' . htmlspecialchars($onayUrl) . '" style="color:#198754;word-break:break-all;">' . htmlspecialchars($onayUrl) . '</a><br>'
                    . '<span style="color:#dc3545;">Red:</span> <a href="' . htmlspecialchars($redUrl) . '" style="color:#dc3545;word-break:break-all;">' . htmlspecialchars($redUrl) . '</a></p>'
                    . '</div>'

                    . '<p style="color:#666;font-size:12px;margin-top:20px;">Bu mail Örnek Mühendislik Portal tarafından otomatik oluşturulmuştur. Bu bağlantılar bu siparişe özeldir ve sadece sizin kullanımınız içindir.</p>'
                    . '</div>';

                $helper = new EntegrasyonHelper($db);
                $result = $helper->mailGonderKanalIle(
                    $kanalId,
                    $aliciEmail,
                    (string)$siparis['cari_adi'],
                    $mailKonu,
                    $mailIcerik
                );

                $updateData = [
                    'TedarikciSiparisleri_MailDenemeSayisi' => ((int)$siparis['TedarikciSiparisleri_MailDenemeSayisi']) + 1,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi' => date('Y-m-d H:i:s')
                ];

                if (!empty($result['success'])) {
                    $updateData['TedarikciSiparisleri_Durum_id'] = 2;
                    $updateData['TedarikciSiparisleri_MailGonderimTarihi'] = date('Y-m-d H:i:s');
                    $updateData['TedarikciSiparisleri_MailHataMesaji'] = null;
                } else {
                    $updateData['TedarikciSiparisleri_Durum_id'] = 4;
                    $updateData['TedarikciSiparisleri_MailHataMesaji'] = mb_substr((string)($result['message'] ?? 'Bilinmeyen hata'), 0, 4000);
                }

                $db->update('TedarikciSiparisleri', $updateData, ['TedarikciSiparisleri_id' => $siparisId]);

                echo json_encode([
                    'success' => !empty($result['success']),
                    'message' => $result['message'] ?? 'Islem tamamlandi.'
                ]);
                break;

            case 'delete':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                                $siparisId = (int)($_POST['siparis_id'] ?? 0);
                if ($siparisId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Gecersiz siparis.']);
                    break;
                }

                // Artik [Durum] kolonu yok. Siparis iptali Durum_id = 5 (Iptal) olarak isaretleniyor.
                $db->update('TedarikciSiparisleri', [
                    'TedarikciSiparisleri_Durum_id' => 5,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi' => date('Y-m-d H:i:s')
                ], ['TedarikciSiparisleri_id' => $siparisId]);

                echo json_encode(['success' => true, 'message' => 'Siparis iptal edildi.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem.']);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
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
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-card-list"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Siparis</span>
                                <span class="info-box-number" id="stat_toplam">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Hazirlandi</span>
                                <span class="info-box-number" id="stat_hazirlandi">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-envelope-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Gonderildi</span>
                                <span class="info-box-number" id="stat_gonderildi">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Hata</span>
                                <span class="info-box-number" id="stat_hata">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <span><i class="bi bi-funnel me-1"></i> Filtrele</span>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div class="collapse" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2">
                                                                <div class="col-md-3">
                                    <label class="form-label">Siparis Durumu</label>
                                    <select id="f_durum" class="form-select select2-filtre">
                                        <option value="">Tumu</option>
                                        <?php foreach ($siparisDurumlari as $d): ?>
                                        <option value="<?= (int)$d['Teklif_Durumlari_id'] ?>"><?= htmlspecialchars($d['Teklif_Durumlari_ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Tedarikci</label>
                                    <select id="f_tedarikci" class="form-select select2-filtre">
                                        <option value="">Tumu</option>
                                        <?php foreach ($tedarikciler as $t): ?>
                                        <option value="<?= (int)$t['cari_id'] ?>"><?= htmlspecialchars($t['cari_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Arama</label>
                                    <input type="text" id="f_search" class="form-control" placeholder="Kod, tedarikci, email">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button id="btnFiltrele" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Filtrele</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Siparis Listesi</h3>
                        <?php if (!empty($pagePermissions['can_add'])): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siparisModal">
                            <i class="bi bi-plus-circle me-1"></i>Yeni Siparis
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered align-middle" id="siparisTable" style="width:100%">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Siparis Kodu</th>
                                    <th>Tedarikci</th>
                                    <th>E-Posta</th>
                                    <th>Kalem</th>
                                    <th>Toplam Adet</th>
                                    <th>Siparis Durumu</th>
                                    <th>Deneme</th>
                                    <th>Tarih</th>
                                    <th>Islem</th>
                                </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<div class="modal fade" id="siparisModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Yeni Tedarikci Siparisi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Tedarikci</label>
                        <select id="m_tedarikci" class="form-select"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Gonderen Mail Hesabi</label>
                        <select id="m_kanal" class="form-select"></select>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Kalemler</h6>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnKalemEkle">
                        <i class="bi bi-plus-lg"></i> Kalem Ekle
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="kalemTable">
                        <thead>
                        <tr>
                            <th style="width:40%">Urun</th>
                            <th style="width:15%">Adet</th>
                            <th style="width:35%">Aciklama</th>
                            <th style="width:10%">Islem</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-primary" id="btnSiparisKaydet">Siparis Olustur</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
const TEDARIKCILER = <?= json_encode($tedarikciler, JSON_UNESCAPED_UNICODE) ?>;
const URUNLER = <?= json_encode($urunler, JSON_UNESCAPED_UNICODE) ?>;
const KANALLAR = <?= json_encode($mailKanallari, JSON_UNESCAPED_UNICODE) ?>;

let table;

function initSelect2() {
    $('.select2-filtre').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });

    $('#m_tedarikci').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#siparisModal')
    });

    $('#m_kanal').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#siparisModal')
    });
}

function loadStats() {
    $.post('', { action: 'stats' }, function(resp) {
        if (resp.success) {
            $('#stat_toplam').text(resp.data.toplam);
            $('#stat_hazirlandi').text(resp.data.hazirlandi);
            $('#stat_gonderildi').text(resp.data.gonderildi);
            $('#stat_hata').text(resp.data.hata);
        }
    }, 'json');
}

function loadTable() {
    const filters = {
        action: 'list',
        durum_id: $('#f_durum').val(),
        tedarikci_id: $('#f_tedarikci').val(),
        search: $('#f_search').val().trim()
    };

    $.post('', filters, function(resp) {
        if (!resp.success) {
            showToast(resp.message || 'Listeleme hatasi', 'error');
            return;
        }

        table.clear();
        table.rows.add(resp.data).draw();
    }, 'json');
}

function badgeDurum(row) {
    const ad = row.Durum_Ad || '-';
    const renk = row.Durum_Renk && String(row.Durum_Renk).trim() !== '' ? row.Durum_Renk : 'secondary';
    return '<span class="badge text-bg-' + renk + '">' + ad + '</span>';
}

function buildRowActions(row) {
    let html = '<a class="btn btn-sm btn-outline-secondary me-1" href="/admin/pages/tedarikci-siparis-detay.php?id=' + row.TedarikciSiparisleri_id + '"><i class="bi bi-eye"></i></a>';

    <?php if (!empty($pagePermissions['can_edit'])): ?>
    html += '<button class="btn btn-sm btn-success me-1 btn-mail" data-id="' + row.TedarikciSiparisleri_id + '"><i class="bi bi-envelope"></i></button>';
    <?php endif; ?>

    <?php if (!empty($pagePermissions['can_delete'])): ?>
    html += '<button class="btn btn-sm btn-danger btn-sil" data-id="' + row.TedarikciSiparisleri_id + '"><i class="bi bi-trash"></i></button>';
    <?php endif; ?>

    return html;
}

function rowTemplate() {
    return {
        urun_id: '',
        adet: '',
        aciklama: ''
    };
}

function buildProductOptions(selected = '') {
    let options = '<option value="">Urun secin</option>';
    URUNLER.forEach(u => {
        const value = String(u.urun_hizmet_id);
        const isSelected = value === String(selected) ? ' selected' : '';
        const label = (u.urun_hizmet_kodu ? '[' + u.urun_hizmet_kodu + '] ' : '') + (u.urun_hizmet_adi || '');
        options += '<option value="' + value + '"' + isSelected + '>' + label + '</option>';
    });
    return options;
}

function addKalemRow(data = rowTemplate()) {
    const tr = $('<tr>');
    tr.append('<td><select class="form-select kalem-urun">' + buildProductOptions(data.urun_id) + '</select></td>');
    tr.append('<td><input type="number" class="form-control kalem-adet" min="0.01" step="0.01" value="' + (data.adet || '') + '"></td>');
    tr.append('<td><input type="text" class="form-control kalem-aciklama" value="' + (data.aciklama || '') + '"></td>');
    tr.append('<td><button type="button" class="btn btn-sm btn-outline-danger btn-kalem-sil"><i class="bi bi-x"></i></button></td>');
    $('#kalemTable tbody').append(tr);

    tr.find('.kalem-urun').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#siparisModal')
    });
}

function clearModal() {
    $('#m_tedarikci').val('').trigger('change');
    if (KANALLAR.length > 0) {
        $('#m_kanal').val(String(KANALLAR[0].Kanal_Id)).trigger('change');
    } else {
        $('#m_kanal').val('').trigger('change');
    }
    $('#kalemTable tbody').html('');
    addKalemRow();
}

function collectKalemler() {
    const kalemler = [];
    $('#kalemTable tbody tr').each(function() {
        const urunId = parseInt($(this).find('.kalem-urun').val() || '0', 10);
        const adet = parseFloat($(this).find('.kalem-adet').val() || '0');
        const aciklama = ($(this).find('.kalem-aciklama').val() || '').trim();

        if (urunId > 0 && adet > 0) {
            kalemler.push({ urun_id: urunId, adet: adet, aciklama: aciklama });
        }
    });
    return kalemler;
}

function sendMail(siparisId) {
    Swal.fire({
        title: 'Mail gonderilsin mi?',
        text: 'Siparis tedarikciye mail olarak gonderilecek.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Gonder',
        cancelButtonText: 'Iptal'
    }).then(result => {
        if (!result.isConfirmed) return;

        $.post('', { action: 'send_mail', siparis_id: siparisId }, function(resp) {
            if (resp.success) {
                showToast(resp.message || 'Mail gonderildi.', 'success');
            } else {
                showToast(resp.message || 'Mail gonderim hatasi', 'error');
            }
            loadStats();
            loadTable();
        }, 'json');
    });
}

function silSiparis(siparisId) {
    Swal.fire({
        title: 'Siparis silinsin mi?',
        text: 'Bu islem geri alinamaz.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sil',
        cancelButtonText: 'Iptal'
    }).then(result => {
        if (!result.isConfirmed) return;

        $.post('', { action: 'delete', siparis_id: siparisId }, function(resp) {
            if (resp.success) {
                showToast(resp.message || 'Siparis silindi.', 'success');
                loadStats();
                loadTable();
            } else {
                showToast(resp.message || 'Silme hatasi', 'error');
            }
        }, 'json');
    });
}

$(function() {
    TEDARIKCILER.forEach(t => {
        $('#m_tedarikci').append('<option value="' + t.cari_id + '">' + (t.cari_adi || '') + (t.cari_email ? ' (' + t.cari_email + ')' : '') + '</option>');
    });

    KANALLAR.forEach(k => {
        $('#m_kanal').append('<option value="' + k.Kanal_Id + '">' + (k.Kanal_Adi || '') + ' - ' + (k.Kanal_Kullanici || '') + '</option>');
    });

    initSelect2();
    clearModal();

    table = $('#siparisTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        columns: [
            { data: 'TedarikciSiparisleri_id' },
            { data: 'TedarikciSiparisleri_SiparisKodu' },
            { data: 'cari_adi' },
            { data: 'cari_email', defaultContent: '' },
            { data: 'TedarikciSiparisleri_ToplamKalem' },
            {
                data: 'TedarikciSiparisleri_ToplamAdet',
                render: function(v) {
                    return Number(v || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            },
                        {
                data: null,
                render: function(row) {
                    return badgeDurum(row);
                }
            },
            { data: 'TedarikciSiparisleri_MailDenemeSayisi' },
            { data: 'SiparisTarihi' },
            {
                data: null,
                orderable: false,
                render: function(row) {
                    return buildRowActions(row);
                }
            }
        ]
    });

    $('#btnFiltrele').on('click', function() {
        loadTable();
        loadStats();
    });

    $('#btnKalemEkle').on('click', function() {
        addKalemRow();
    });

    $('#kalemTable').on('click', '.btn-kalem-sil', function() {
        if ($('#kalemTable tbody tr').length <= 1) {
            showToast('En az bir kalem olmalidir.', 'error');
            return;
        }
        $(this).closest('tr').remove();
    });

    $('#btnSiparisKaydet').on('click', function() {
        const tedarikciId = parseInt($('#m_tedarikci').val() || '0', 10);
        const gonderenHesapId = parseInt($('#m_kanal').val() || '0', 10);
        const kalemler = collectKalemler();

        if (tedarikciId <= 0) {
            showToast('Tedarikci secimi zorunludur.', 'error');
            return;
        }
        if (kalemler.length === 0) {
            showToast('En az bir gecerli kalem eklemelisiniz.', 'error');
            return;
        }

        $.post('', {
            action: 'create',
            tedarikci_id: tedarikciId,
            gonderen_hesap_id: gonderenHesapId,
            kalemler: JSON.stringify(kalemler)
        }, function(resp) {
            if (resp.success) {
                showToast(resp.message || 'Siparis olusturuldu.', 'success');
                $('#siparisModal').modal('hide');
                clearModal();
                loadStats();
                loadTable();
            } else {
                showToast(resp.message || 'Kayit hatasi', 'error');
            }
        }, 'json');
    });

    $('#siparisModal').on('hidden.bs.modal', function() {
        clearModal();
    });

    $('#siparisTable').on('click', '.btn-mail', function() {
        const siparisId = parseInt($(this).data('id'), 10);
        if (siparisId > 0) {
            sendMail(siparisId);
        }
    });

    $('#siparisTable').on('click', '.btn-sil', function() {
        const siparisId = parseInt($(this).data('id'), 10);
        if (siparisId > 0) {
            silSiparis(siparisId);
        }
    });

    loadStats();
    loadTable();
});
</script>
</body>
</html>
