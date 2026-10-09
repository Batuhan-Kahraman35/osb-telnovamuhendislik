<?php
/**
 * Admin Panel - Tedarikci Siparis Detay
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    'tedarikci-siparisleri.php'
);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Bu sayfaya erisim yetkiniz bulunmamaktadir.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erisim yetkiniz bulunmamaktadir.');
}

$siparisId = (int)($_GET['id'] ?? 0);
if ($siparisId <= 0) {
    header('Location: /admin/pages/tedarikci-siparisleri.php');
    exit;
}

function siparisGetir($db, int $siparisId): ?array {
    return $db->fetchOne("
        SELECT
            ts.*,
            c.cari_adi,
            c.cari_email,
            c.cari_telefon,
            k.Kanal_Adi,
            k.Kanal_Kullanici,
            td.Teklif_Durumlari_ad  AS Durum_Ad,
            td.Teklif_Durumlari_renk AS Durum_Renk,
            CONVERT(VARCHAR(19), ts.TedarikciSiparisleri_SiparisTarihi, 120) AS SiparisTarihi,
            CONVERT(VARCHAR(19), ts.TedarikciSiparisleri_MailGonderimTarihi, 120) AS MailGonderimTarihi
        FROM dbo.TedarikciSiparisleri ts
        INNER JOIN dbo.Cari c ON c.cari_id = ts.TedarikciSiparisleri_Tedarikci_id
        LEFT JOIN dbo.Teklif_Durumlari td ON td.Teklif_Durumlari_id = ts.TedarikciSiparisleri_Durum_id
        LEFT JOIN dbo.EntegrasyonKanallari k ON k.Kanal_Id = ts.TedarikciSiparisleri_GonderenHesap_id
        WHERE ts.TedarikciSiparisleri_id = ?
    ", [$siparisId]);
}

function siparisKalemleriGetir($db, int $siparisId): array {
    return $db->fetchAll("
        SELECT
            sk.TedarikciSiparisKalemleri_id,
            sk.TedarikciSiparisKalemleri_Urun_id,
            sk.TedarikciSiparisKalemleri_Adet,
            sk.TedarikciSiparisKalemleri_Aciklama,
            u.urun_hizmet_adi,
            u.urun_hizmet_kodu
        FROM dbo.TedarikciSiparisKalemleri sk
        INNER JOIN dbo.Urun_Hizmet u ON u.urun_hizmet_id = sk.TedarikciSiparisKalemleri_Urun_id
        WHERE sk.TedarikciSiparisKalemleri_TedarikciSiparisleri_id = ?
        ORDER BY sk.TedarikciSiparisKalemleri_id
    ", [$siparisId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'update':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Duzenleme yetkiniz yok!']);
                    break;
                }

                $tedarikciId     = (int)($_POST['tedarikci_id'] ?? 0);
                $gonderenHesapId = (int)($_POST['gonderen_hesap_id'] ?? 0);
                $kalemler        = json_decode($_POST['kalemler'] ?? '[]', true);

                if ($tedarikciId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Tedarikci secimi zorunludur.']);
                    break;
                }

                if (empty($kalemler) || !is_array($kalemler)) {
                    echo json_encode(['success' => false, 'message' => 'En az bir kalem eklemelisiniz.']);
                    break;
                }

                $db->execute(
                    "DELETE FROM dbo.TedarikciSiparisKalemleri WHERE TedarikciSiparisKalemleri_TedarikciSiparisleri_id = ?",
                    [$siparisId]
                );

                $toplamAdet  = 0;
                $toplamKalem = 0;

                foreach ($kalemler as $kalem) {
                    $urunId   = (int)($kalem['urun_id'] ?? 0);
                    $adet     = (float)($kalem['adet'] ?? 0);
                    $aciklama = trim($kalem['aciklama'] ?? '');

                    if ($urunId <= 0 || $adet <= 0) {
                        continue;
                    }

                    $db->insert('TedarikciSiparisKalemleri', [
                        'TedarikciSiparisKalemleri_TedarikciSiparisleri_id' => $siparisId,
                        'TedarikciSiparisKalemleri_Urun_id'                 => $urunId,
                        'TedarikciSiparisKalemleri_Adet'                    => $adet,
                        'TedarikciSiparisKalemleri_Aciklama'                => $aciklama !== '' ? $aciklama : null,
                        'OlusturanKullanici'                                 => $user['kullanici_id'],
                        'OlusturmaTarihi'                                    => date('Y-m-d H:i:s')
                    ]);

                    $toplamKalem++;
                    $toplamAdet += $adet;
                }

                if ($toplamKalem <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Gecerli kalem bulunamadi.']);
                    break;
                }

                $db->update('TedarikciSiparisleri', [
                    'TedarikciSiparisleri_Tedarikci_id'      => $tedarikciId,
                    'TedarikciSiparisleri_GonderenHesap_id'  => $gonderenHesapId > 0 ? $gonderenHesapId : null,
                    'TedarikciSiparisleri_ToplamKalem'        => $toplamKalem,
                    'TedarikciSiparisleri_ToplamAdet'         => $toplamAdet,
                    'GuncelleyenKullanici'                    => $user['kullanici_id'],
                    'GuncellemeTarihi'                        => date('Y-m-d H:i:s')
                ], ['TedarikciSiparisleri_id' => $siparisId]);

                echo json_encode(['success' => true, 'message' => 'Siparis guncellendi.']);
                break;

            case 'send_mail':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Mail gonderim yetkiniz yok!']);
                    break;
                }

                $siparis = siparisGetir($db, $siparisId);
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

                $kalemler = siparisKalemleriGetir($db, $siparisId);
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

                try {
                    $onayToken = bin2hex(random_bytes(16));
                } catch (Throwable $ex) {
                    $onayToken = bin2hex(openssl_random_pseudo_bytes(16));
                }

                $db->update('TedarikciSiparisleri', [
                    'TedarikciSiparisleri_OnayToken'       => $onayToken,
                    'TedarikciSiparisleri_OnayYanitTarihi' => null,
                    'TedarikciSiparisleri_OnayYanitIP'     => null
                ], ['TedarikciSiparisleri_id' => $siparisId]);

                $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host    = $_SERVER['HTTP_HOST'] ?? 'panel.ornekfirma.com';
                $baseUrl = $scheme . '://' . $host;
                $onayUrl = $baseUrl . '/admin/pages/tedarikci-siparis-onay.php?token=' . $onayToken . '&action=onay';
                $redUrl  = $baseUrl . '/admin/pages/tedarikci-siparis-onay.php?token=' . $onayToken . '&action=red';

                $mailKonu = 'Örnek Mühendislik Sipariş - ' . $siparis['TedarikciSiparisleri_SiparisKodu'];
                $mailIcerik = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;max-width:720px;">'
                    . '<p>Merhaba,</p>'
                    . '<p>Aşağıdaki sipariş kalemleri için siparişin onaylanması ve tahmini teslim süresi bilgisi rica ederiz.</p>'
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
                    . '<p style="color:#666;font-size:12px;margin-top:20px;">Bu mail Örnek Mühendislik Portal tarafından otomatik oluşturulmuştur. Bu bağlantılar bu siparişe özeldir.</p>'
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

            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }

    exit;
}

$siparis = siparisGetir($db, $siparisId);
if (!$siparis) {
    header('Location: /admin/pages/tedarikci-siparisleri.php');
    exit;
}

$kalemler = siparisKalemleriGetir($db, $siparisId);

$tedarikciTipId = 3;

$tedarikciler = $db->fetchAll("
    SELECT c.cari_id, c.cari_adi, c.cari_email
    FROM dbo.Cari c
    INNER JOIN dbo.Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id
    WHERE c.cari_aktif = 1 AND c.cari_tipi_id = ? AND ct.cari_tipi_durum = 1
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

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . basename($_SERVER['PHP_SELF'])]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Tedarikci Siparis Detay';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
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
                            <li class="breadcrumb-item"><a href="/admin/pages/tedarikci-siparisleri.php">Tedarikci Siparisleri</a></li>
                            <li class="breadcrumb-item active">Detay</li>
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
                            <span class="info-box-icon"><i class="bi bi-upc-scan"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Siparis Kodu</span>
                                <span class="info-box-number" style="font-size:1rem"><?= htmlspecialchars($siparis['TedarikciSiparisleri_SiparisKodu']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-box-seam"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kalem</span>
                                <span class="info-box-number" id="infoKalem"><?= (int)$siparis['TedarikciSiparisleri_ToplamKalem'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-123"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Adet</span>
                                <span class="info-box-number" id="infoAdet"><?= number_format((float)$siparis['TedarikciSiparisleri_ToplamAdet'], 2, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <?php
                        $durumAd   = $siparis['Durum_Ad'] ?? '-';
                        $durumRenk = !empty($siparis['Durum_Renk']) ? $siparis['Durum_Renk'] : 'secondary';
                        ?>
                        <div class="info-box text-bg-<?= htmlspecialchars($durumRenk) ?>">
                            <span class="info-box-icon"><i class="bi bi-flag"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Siparis Durumu</span>
                                <span class="info-box-number" style="font-size:1rem"><?= htmlspecialchars($durumAd) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Siparis Bilgisi</h3>
                        <div class="d-flex gap-2">
                            <a href="/admin/pages/tedarikci-siparisleri.php" class="btn btn-outline-secondary btn-sm" id="btnListeyeDon">
                                <i class="bi bi-arrow-left me-1"></i>Listeye Don
                            </a>
                            <?php if (!empty($pagePermissions['can_edit'])): ?>
                            <button class="btn btn-warning btn-sm" id="btnDuzenle">
                                <i class="bi bi-pencil me-1"></i>Duzenle
                            </button>
                            <button class="btn btn-secondary btn-sm d-none" id="btnIptal">
                                <i class="bi bi-x me-1"></i>Iptal
                            </button>
                            <button class="btn btn-primary btn-sm d-none" id="btnKaydet">
                                <i class="bi bi-check-lg me-1"></i>Kaydet
                            </button>
                            <button class="btn btn-success btn-sm" id="btnMailGonder">
                                <i class="bi bi-send me-1"></i>Mail Gonder / Tekrar Gonder
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Goruntuleme modu -->
                        <div id="viewMode">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label text-muted">Tedarikci</label>
                                    <div class="fw-semibold" id="view_tedarikci"><?= htmlspecialchars($siparis['cari_adi']) ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted">E-Posta</label>
                                    <div><?= htmlspecialchars((string)($siparis['cari_email'] ?? '-')) ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted">Telefon</label>
                                    <div><?= htmlspecialchars((string)($siparis['cari_telefon'] ?? '-')) ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted">Siparis Tarihi</label>
                                    <div><?= htmlspecialchars((string)$siparis['SiparisTarihi']) ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted">Mail Hesabi</label>
                                    <div id="view_kanal"><?= htmlspecialchars((string)($siparis['Kanal_Adi'] ?? '-')) ?> <?= !empty($siparis['Kanal_Kullanici']) ? '(' . htmlspecialchars((string)$siparis['Kanal_Kullanici']) . ')' : '' ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted">Son Gonderim</label>
                                    <div><?= htmlspecialchars((string)($siparis['MailGonderimTarihi'] ?? '-')) ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted">Deneme Sayisi</label>
                                    <div><?= (int)$siparis['TedarikciSiparisleri_MailDenemeSayisi'] ?></div>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label text-muted">Sonuc Mesaji</label>
                                    <div class="text-danger"><?= htmlspecialchars((string)($siparis['TedarikciSiparisleri_MailHataMesaji'] ?? '-')) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Duzenleme modu -->
                        <div id="editMode" class="d-none">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Tedarikci</label>
                                    <select id="edit_tedarikci" class="form-select"></select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Gonderen Mail Hesabi</label>
                                    <select id="edit_kanal" class="form-select"></select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Siparis Kalemleri</h3>
                        <button type="button" class="btn btn-outline-primary btn-sm d-none" id="btnKalemEkle">
                            <i class="bi bi-plus-lg"></i> Kalem Ekle
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <!-- Goruntuleme tablosu -->
                            <table class="table table-striped table-bordered mb-0" id="viewKalemTable">
                                <thead>
                                <tr>
                                    <th style="width:60px">#</th>
                                    <th>Urun</th>
                                    <th style="width:180px">Kod</th>
                                    <th style="width:140px">Adet</th>
                                    <th>Aciklama</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($kalemler as $index => $kalem): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars((string)$kalem['urun_hizmet_adi']) ?></td>
                                        <td><?= htmlspecialchars((string)($kalem['urun_hizmet_kodu'] ?? '')) ?></td>
                                        <td class="text-end"><?= number_format((float)$kalem['TedarikciSiparisKalemleri_Adet'], 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars((string)($kalem['TedarikciSiparisKalemleri_Aciklama'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($kalemler)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">Kalem bulunamadi.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>

                            <!-- Duzenleme tablosu -->
                            <table class="table table-sm table-bordered mb-0 d-none" id="editKalemTable">
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
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script>
const TEDARIKCILER  = <?= json_encode($tedarikciler, JSON_UNESCAPED_UNICODE) ?>;
const URUNLER       = <?= json_encode($urunler, JSON_UNESCAPED_UNICODE) ?>;
const KANALLAR      = <?= json_encode($mailKanallari, JSON_UNESCAPED_UNICODE) ?>;
const MEVCUT_KALEMLER = <?= json_encode(array_map(function($k) {
    return [
        'urun_id'  => (int)$k['TedarikciSiparisKalemleri_Urun_id'],
        'adet'     => (float)$k['TedarikciSiparisKalemleri_Adet'],
        'aciklama' => (string)($k['TedarikciSiparisKalemleri_Aciklama'] ?? '')
    ];
}, $kalemler), JSON_UNESCAPED_UNICODE) ?>;
const MEVCUT_TEDARIKCI_ID = <?= (int)$siparis['TedarikciSiparisleri_Tedarikci_id'] ?>;
const MEVCUT_KANAL_ID     = <?= (int)($siparis['TedarikciSiparisleri_GonderenHesap_id'] ?? 0) ?>;

function buildProductOptions(selected) {
    let opts = '<option value="">Urun secin</option>';
    URUNLER.forEach(u => {
        const sel = String(u.urun_hizmet_id) === String(selected) ? ' selected' : '';
        const lbl = (u.urun_hizmet_kodu ? '[' + u.urun_hizmet_kodu + '] ' : '') + (u.urun_hizmet_adi || '');
        opts += '<option value="' + u.urun_hizmet_id + '"' + sel + '>' + lbl + '</option>';
    });
    return opts;
}

function addEditRow(data) {
    data = data || { urun_id: '', adet: '', aciklama: '' };
    const tr = $('<tr>');
    tr.append('<td><select class="form-select kalem-urun">' + buildProductOptions(data.urun_id) + '</select></td>');
    tr.append('<td><input type="number" class="form-control kalem-adet" min="0.01" step="0.01" value="' + (data.adet || '') + '"></td>');
    tr.append('<td><input type="text" class="form-control kalem-aciklama" value="' + (data.aciklama || '') + '"></td>');
    tr.append('<td><button type="button" class="btn btn-sm btn-outline-danger btn-kalem-sil"><i class="bi bi-x"></i></button></td>');
    $('#editKalemTable tbody').append(tr);
    tr.find('.kalem-urun').select2({ theme: 'bootstrap-5', width: '100%' });
}

function collectKalemler() {
    const kalemler = [];
    $('#editKalemTable tbody tr').each(function() {
        const urunId  = parseInt($(this).find('.kalem-urun').val() || '0', 10);
        const adet    = parseFloat($(this).find('.kalem-adet').val() || '0');
        const aciklama = ($(this).find('.kalem-aciklama').val() || '').trim();
        if (urunId > 0 && adet > 0) {
            kalemler.push({ urun_id: urunId, adet: adet, aciklama: aciklama });
        }
    });
    return kalemler;
}

function enterEditMode() {
    // Tedarikci select
    $('#edit_tedarikci').empty();
    TEDARIKCILER.forEach(t => {
        const sel = t.cari_id === MEVCUT_TEDARIKCI_ID ? ' selected' : '';
        $('#edit_tedarikci').append('<option value="' + t.cari_id + '"' + sel + '>' + t.cari_adi + (t.cari_email ? ' (' + t.cari_email + ')' : '') + '</option>');
    });
    $('#edit_tedarikci').select2({ theme: 'bootstrap-5', width: '100%' });

    // Kanal select
    $('#edit_kanal').empty().append('<option value="">Varsayilan kanal</option>');
    KANALLAR.forEach(k => {
        const sel = k.Kanal_Id === MEVCUT_KANAL_ID ? ' selected' : '';
        $('#edit_kanal').append('<option value="' + k.Kanal_Id + '"' + sel + '>' + k.Kanal_Adi + ' - ' + k.Kanal_Kullanici + '</option>');
    });
    $('#edit_kanal').select2({ theme: 'bootstrap-5', width: '100%' });

    // Kalemleri yukle
    $('#editKalemTable tbody').empty();
    MEVCUT_KALEMLER.forEach(k => addEditRow(k));
    if (MEVCUT_KALEMLER.length === 0) addEditRow();

    // Toggle
    $('#viewMode').addClass('d-none');
    $('#editMode').removeClass('d-none');
    $('#viewKalemTable').addClass('d-none');
    $('#editKalemTable').removeClass('d-none');
    $('#btnKalemEkle').removeClass('d-none');
    $('#btnDuzenle').addClass('d-none');
    $('#btnMailGonder').addClass('d-none');
    $('#btnIptal').removeClass('d-none');
    $('#btnKaydet').removeClass('d-none');
    $('#btnListeyeDon').addClass('d-none');
}

function exitEditMode() {
    $('#viewMode').removeClass('d-none');
    $('#editMode').addClass('d-none');
    $('#viewKalemTable').removeClass('d-none');
    $('#editKalemTable').addClass('d-none');
    $('#btnKalemEkle').addClass('d-none');
    $('#btnDuzenle').removeClass('d-none');
    $('#btnMailGonder').removeClass('d-none');
    $('#btnIptal').addClass('d-none');
    $('#btnKaydet').addClass('d-none');
    $('#btnListeyeDon').removeClass('d-none');
}

$(function() {
    $('#btnDuzenle').on('click', function() {
        enterEditMode();
    });

    $('#btnIptal').on('click', function() {
        exitEditMode();
    });

    $('#btnKalemEkle').on('click', function() {
        addEditRow();
    });

    $('#editKalemTable').on('click', '.btn-kalem-sil', function() {
        if ($('#editKalemTable tbody tr').length <= 1) {
            showToast('En az bir kalem olmalidir.', 'error');
            return;
        }
        $(this).closest('tr').remove();
    });

    $('#btnKaydet').on('click', function() {
        const tedarikciId     = parseInt($('#edit_tedarikci').val() || '0', 10);
        const gonderenHesapId = parseInt($('#edit_kanal').val() || '0', 10);
        const kalemler        = collectKalemler();

        if (tedarikciId <= 0) {
            showToast('Tedarikci secimi zorunludur.', 'error');
            return;
        }
        if (kalemler.length === 0) {
            showToast('En az bir gecerli kalem eklemelisiniz.', 'error');
            return;
        }

        $.post('', {
            action: 'update',
            tedarikci_id: tedarikciId,
            gonderen_hesap_id: gonderenHesapId,
            kalemler: JSON.stringify(kalemler)
        }, function(resp) {
            if (resp.success) {
                showToast(resp.message || 'Siparis guncellendi.', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(resp.message || 'Kayit hatasi.', 'error');
            }
        }, 'json');
    });

    $('#btnMailGonder').on('click', function() {
        Swal.fire({
            title: 'Mail gonderilsin mi?',
            text: 'Siparis tedarikciye mail olarak gonderilecek.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Gonder',
            cancelButtonText: 'Iptal'
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post('', { action: 'send_mail' }, function(resp) {
                if (resp.success) {
                    showToast(resp.message || 'Mail gonderildi.', 'success');
                    setTimeout(function() { location.reload(); }, 900);
                } else {
                    showToast(resp.message || 'Mail gonderim hatasi.', 'error');
                }
            }, 'json');
        });
    });
});
</script>
</body>
</html>
