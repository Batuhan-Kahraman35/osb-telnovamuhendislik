<?php
/**
 * Public Endpoint - Tedarikci Siparis Onay / Red
 *
 * ONEMLI: Bu sayfa auth GEREKTIRMEZ. Tedarikci, mail'den gelen token
 * uzerinden siparisi onaylar veya reddeder. Dolayisiyla requireAuth()
 * cagrilmaz, sadece token dogrulamasi yapilir.
 *
 * Tedarikci yanit verdiginde, BILDIRIM_DEPARTMAN_IDLERI icindeki
 * departmanlarda bulunan aktif kullanicilara otomatik bilgi maili gider.
 *
 * URL ornegi:
 *   /admin/pages/tedarikci-siparis-onay.php?token=XXX&action=onay
 *   /admin/pages/tedarikci-siparis-onay.php?token=XXX&action=red
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/EntegrasyonHelper.php';

// Yanit verildiginde bilgilendirilecek departmanlar
// 1 = BILGI ISLEM, 22 = MUDUR
const BILDIRIM_DEPARTMAN_IDLERI = [1, 22];

$db = Database::getInstance();

$token  = trim((string)($_GET['token']  ?? ''));
$action = trim((string)($_GET['action'] ?? ''));

$message      = '';
$messageType  = 'info'; // success | error | warning | info
$siparis      = null;
$zatenYanitli = false;

/**
 * Ilgili departmanlara bildirim maili gonderir.
 */
function bildirimMailiGonder(Database $db, array $siparis, string $durumYazi, int $yeniDurumId): void
{
    try {
        // Varsayilan mail kanalini al
        $kanal = $db->fetchOne("
            SELECT TOP 1 k.Kanal_Id
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyon e ON k.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
            WHERE e.Entegrasyon_Kod = 'MAIL' AND k.Kanal_Durum = 1
            ORDER BY k.Kanal_Varsayilan DESC, k.Kanal_Id
        ");
        $kanalId = (int)($kanal['Kanal_Id'] ?? 0);
        if ($kanalId <= 0) {
            error_log('tedarikci-siparis-onay: Aktif mail kanali bulunamadi');
            return;
        }

        // Ilgili departmanlardaki aktif kullanicilari al
        $placeholders = implode(',', array_fill(0, count(BILDIRIM_DEPARTMAN_IDLERI), '?'));
        $kullanicilar = $db->fetchAll("
            SELECT kullanici_email, kullanici_ad, kullanici_soyad
            FROM dbo.kullanicilar
            WHERE kullanici_durum = 1
              AND kullanici_email IS NOT NULL
              AND LEN(TRIM(kullanici_email)) > 0
              AND kullanici_departman_id IN ($placeholders)
        ", BILDIRIM_DEPARTMAN_IDLERI);

        if (empty($kullanicilar)) {
            error_log('tedarikci-siparis-onay: Bildirim yapilacak kullanici bulunamadi');
            return;
        }

        // Siparis kalemlerini al
        $kalemler = $db->fetchAll("
            SELECT k.TedarikciSiparisKalemleri_Adet, k.TedarikciSiparisKalemleri_Aciklama,
                   u.urun_hizmet_adi, u.urun_hizmet_kodu
            FROM dbo.TedarikciSiparisKalemleri k
            INNER JOIN dbo.Urun_Hizmet u ON u.urun_hizmet_id = k.TedarikciSiparisKalemleri_Urun_id
            WHERE k.TedarikciSiparisKalemleri_TedarikciSiparisleri_id = ?
            ORDER BY k.TedarikciSiparisKalemleri_id
        ", [(int)$siparis['TedarikciSiparisleri_id']]);

        $kalemRows = '';
        foreach ($kalemler as $index => $k) {
            $kalemRows .= '<tr>'
                . '<td style="padding:6px;border:1px solid #ddd;">' . ($index + 1) . '</td>'
                . '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars((string)$k['urun_hizmet_adi']) . '</td>'
                . '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars((string)($k['urun_hizmet_kodu'] ?? '')) . '</td>'
                . '<td style="padding:6px;border:1px solid #ddd;text-align:right;">' . number_format((float)$k['TedarikciSiparisKalemleri_Adet'], 2, ',', '.') . '</td>'
                . '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars((string)($k['TedarikciSiparisKalemleri_Aciklama'] ?? '')) . '</td>'
                . '</tr>';
        }

        // Durum rengi
        $durumRenk = $yeniDurumId === 3 ? '#198754' : '#dc3545';
        $durumIcon = $yeniDurumId === 3 ? '&#10004;' : '&#10008;';

        $konu = '[' . strtoupper($durumYazi) . '] Tedarikci Siparisi - ' . $siparis['TedarikciSiparisleri_SiparisKodu'];

        $icerik = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;max-width:720px;">'
            . '<div style="background:' . $durumRenk . ';color:#fff;padding:16px;border-radius:6px 6px 0 0;">'
            . '<h2 style="margin:0;font-size:18px;">' . $durumIcon . ' Tedarikci Siparis Yaniti: ' . htmlspecialchars($durumYazi) . '</h2>'
            . '</div>'
            . '<div style="border:1px solid #ddd;border-top:none;padding:16px;border-radius:0 0 6px 6px;">'
            . '<p><b>' . htmlspecialchars($siparis['cari_adi']) . '</b> firmasi, asagidaki siparisi <b style="color:' . $durumRenk . ';">' . htmlspecialchars($durumYazi) . '</b> olarak yanitladi.</p>'
            . '<table style="width:100%;margin:12px 0;">'
            . '<tr><td style="padding:4px 0;color:#666;width:160px;">Siparis Kodu:</td><td style="padding:4px 0;"><b>' . htmlspecialchars($siparis['TedarikciSiparisleri_SiparisKodu']) . '</b></td></tr>'
            . '<tr><td style="padding:4px 0;color:#666;">Tedarikci:</td><td style="padding:4px 0;">' . htmlspecialchars($siparis['cari_adi']) . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#666;">Tedarikci E-Posta:</td><td style="padding:4px 0;">' . htmlspecialchars((string)($siparis['cari_email'] ?? '-')) . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#666;">Siparis Tarihi:</td><td style="padding:4px 0;">' . htmlspecialchars((string)($siparis['SiparisTarihi'] ?? '-')) . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#666;">Yanit Tarihi:</td><td style="padding:4px 0;">' . date('d.m.Y H:i') . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#666;">Kalem Sayisi:</td><td style="padding:4px 0;">' . (int)$siparis['TedarikciSiparisleri_ToplamKalem'] . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#666;">Toplam Adet:</td><td style="padding:4px 0;">' . number_format((float)$siparis['TedarikciSiparisleri_ToplamAdet'], 2, ',', '.') . '</td></tr>'
            . '</table>';

        if (!empty($kalemRows)) {
            $icerik .= '<h3 style="font-size:15px;margin-top:20px;">Siparis Kalemleri</h3>'
                . '<table style="border-collapse:collapse;width:100%;font-size:13px;">'
                . '<thead><tr style="background:#f5f5f5;">'
                . '<th style="padding:6px;border:1px solid #ddd;text-align:left;">#</th>'
                . '<th style="padding:6px;border:1px solid #ddd;text-align:left;">Urun</th>'
                . '<th style="padding:6px;border:1px solid #ddd;text-align:left;">Kod</th>'
                . '<th style="padding:6px;border:1px solid #ddd;text-align:right;">Adet</th>'
                . '<th style="padding:6px;border:1px solid #ddd;text-align:left;">Aciklama</th>'
                . '</tr></thead>'
                . '<tbody>' . $kalemRows . '</tbody>'
                . '</table>';
        }

        $icerik .= '<p style="margin-top:20px;color:#666;font-size:12px;">Bu mail OrnekFirma Muhendislik Portal tarafindan otomatik gonderilmistir.</p>'
                . '</div></div>';

        $helper = new EntegrasyonHelper($db);

        foreach ($kullanicilar as $u) {
            $email = trim((string)$u['kullanici_email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $adSoyad = trim(($u['kullanici_ad'] ?? '') . ' ' . ($u['kullanici_soyad'] ?? ''));
            try {
                $helper->mailGonderKanalIle($kanalId, $email, $adSoyad ?: $email, $konu, $icerik);
            } catch (Throwable $ex) {
                error_log('tedarikci-siparis-onay bildirim hatasi (' . $email . '): ' . $ex->getMessage());
            }
        }
    } catch (Throwable $ex) {
        error_log('tedarikci-siparis-onay bildirim genel hata: ' . $ex->getMessage());
    }
}

try {
    if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
        throw new Exception('Gecersiz veya eksik onay baglantisi.');
    }

    if (!in_array($action, ['onay', 'red'], true)) {
        throw new Exception('Gecersiz islem turu.');
    }

    // Siparisi token ile bul
    $siparis = $db->fetchOne("
        SELECT
            ts.TedarikciSiparisleri_id,
            ts.TedarikciSiparisleri_SiparisKodu,
            ts.TedarikciSiparisleri_Durum_id,
            ts.TedarikciSiparisleri_OnayYanitTarihi,
            ts.TedarikciSiparisleri_ToplamKalem,
            ts.TedarikciSiparisleri_ToplamAdet,
            CONVERT(VARCHAR(19), ts.TedarikciSiparisleri_SiparisTarihi, 120) AS SiparisTarihi,
            c.cari_adi,
            c.cari_email,
            td.Teklif_Durumlari_ad  AS Durum_Ad,
            td.Teklif_Durumlari_renk AS Durum_Renk
        FROM dbo.TedarikciSiparisleri ts
        INNER JOIN dbo.Cari c ON c.cari_id = ts.TedarikciSiparisleri_Tedarikci_id
        LEFT JOIN dbo.Teklif_Durumlari td ON td.Teklif_Durumlari_id = ts.TedarikciSiparisleri_Durum_id
        WHERE ts.TedarikciSiparisleri_OnayToken = ?
    ", [$token]);

    if (!$siparis) {
        throw new Exception('Siparis bulunamadi veya bu onay baglantisi gecersiz.');
    }

    // Zaten yanit verilmis mi? (Durum_id 3 veya 4 ise)
    $mevcutDurum = (int)$siparis['TedarikciSiparisleri_Durum_id'];
    if (in_array($mevcutDurum, [3, 4], true)) {
        $zatenYanitli = true;
        $durumYaziMevcut = $mevcutDurum === 3 ? 'ONAYLANDI' : 'REDDEDILDI';
        $yanitTarihi = $siparis['TedarikciSiparisleri_OnayYanitTarihi'] ?? '';
        $message = 'Bu siparis daha once ' . $durumYaziMevcut . ' olarak yanitlanmistir.'
                 . ($yanitTarihi ? ' (Yanit tarihi: ' . $yanitTarihi . ')' : '');
        $messageType = 'warning';
    } else {
        // Durum guncelle
        $yeniDurumId = $action === 'onay' ? 3 : 4;
        $durumYazi   = $action === 'onay' ? 'Onaylandi' : 'Reddedildi';

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ip = mb_substr($ip, 0, 45);

        $db->update('TedarikciSiparisleri', [
            'TedarikciSiparisleri_Durum_id'        => $yeniDurumId,
            'TedarikciSiparisleri_OnayYanitTarihi' => date('Y-m-d H:i:s'),
            'TedarikciSiparisleri_OnayYanitIP'     => $ip,
            'GuncellemeTarihi'                     => date('Y-m-d H:i:s')
        ], ['TedarikciSiparisleri_id' => (int)$siparis['TedarikciSiparisleri_id']]);

        // Guncellenmis veriyi yansit
        $siparis['TedarikciSiparisleri_Durum_id'] = $yeniDurumId;

        // Ilgili departmanlara bildirim gonder
        bildirimMailiGonder($db, $siparis, $durumYazi, $yeniDurumId);

        if ($action === 'onay') {
            $message = 'Siparisi ONAYLADIGINIZ icin tesekkur ederiz. Bilgileriniz kaydedildi.';
            $messageType = 'success';
        } else {
            $message = 'Siparisi REDDETTIGINIZ bildirildi. Geri bildiriminiz icin tesekkur ederiz.';
            $messageType = 'error';
        }
    }

} catch (Exception $e) {
    $message = $e->getMessage();
    $messageType = 'error';
}

// Ikon ve renk
$iconMap = [
    'success' => 'bi-check-circle-fill',
    'error'   => 'bi-x-circle-fill',
    'warning' => 'bi-exclamation-triangle-fill',
    'info'    => 'bi-info-circle-fill',
];
$colorMap = [
    'success' => '#198754',
    'error'   => '#dc3545',
    'warning' => '#ffc107',
    'info'    => '#0dcaf0',
];
$icon  = $iconMap[$messageType]  ?? 'bi-info-circle-fill';
$color = $colorMap[$messageType] ?? '#0dcaf0';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Siparis Yaniti - OrnekFirma Muhendislik</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            padding: 20px;
        }
        .result-card {
            max-width: 560px;
            width: 100%;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            overflow: hidden;
        }
        .result-header {
            padding: 48px 32px 24px;
            text-align: center;
            background: <?= $color ?>;
            color: #fff;
        }
        .result-header i { font-size: 72px; display: block; margin-bottom: 12px; }
        .result-header h2 { margin: 0; font-weight: 600; }
        .result-body { padding: 32px; }
        .result-body p.lead {
            font-size: 1.05rem;
            color: #333;
            text-align: center;
            margin-bottom: 24px;
        }
        .detail-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .detail-table th, .detail-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
            font-size: 0.95rem;
        }
        .detail-table th { text-align: left; color: #666; font-weight: 500; width: 40%; }
        .detail-table td { color: #222; font-weight: 600; }
        .footer-note {
            text-align: center;
            padding: 16px;
            background: #f8f9fa;
            color: #666;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="result-card">
        <div class="result-header">
            <i class="bi <?= $icon ?>"></i>
            <h2>
                <?php
                if ($zatenYanitli) {
                    echo 'Zaten Yanitlanmis';
                } elseif ($messageType === 'success') {
                    echo 'Siparis Onaylandi';
                } elseif ($messageType === 'error') {
                    if ($siparis && (int)$siparis['TedarikciSiparisleri_Durum_id'] === 4) {
                        echo 'Siparis Reddedildi';
                    } else {
                        echo 'Hata';
                    }
                } else {
                    echo 'Bilgi';
                }
                ?>
            </h2>
        </div>
        <div class="result-body">
            <p class="lead"><?= htmlspecialchars($message) ?></p>

            <?php if ($siparis): ?>
            <table class="detail-table">
                <tr>
                    <th>Siparis Kodu</th>
                    <td><?= htmlspecialchars($siparis['TedarikciSiparisleri_SiparisKodu']) ?></td>
                </tr>
                <tr>
                    <th>Tedarikci</th>
                    <td><?= htmlspecialchars($siparis['cari_adi']) ?></td>
                </tr>
                <tr>
                    <th>Siparis Tarihi</th>
                    <td><?= htmlspecialchars((string)($siparis['SiparisTarihi'] ?? '-')) ?></td>
                </tr>
                <tr>
                    <th>Kalem Sayisi</th>
                    <td><?= (int)$siparis['TedarikciSiparisleri_ToplamKalem'] ?></td>
                </tr>
                <tr>
                    <th>Toplam Adet</th>
                    <td><?= number_format((float)$siparis['TedarikciSiparisleri_ToplamAdet'], 2, ',', '.') ?></td>
                </tr>
            </table>
            <?php endif; ?>
        </div>
        <div class="footer-note">
            OrnekFirma Muhendislik - Bu sayfayi kapatabilirsiniz.
        </div>
    </div>
</body>
</html>
