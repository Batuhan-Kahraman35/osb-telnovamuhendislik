<?php
/**
 * Cron Görevi: Son 3 Günlük Görüşme Notları Mail Gönderimi
 *
 * Görev kodu: gorusme_notlari_mail
 *
 * Alıcılar : kullanici_departman_id = 10
 * CC       : kullanici_departman_id = 22
 * BCC      : kullanici_departman_id = 1
 */

require_once __DIR__ . '/../../admin/includes/EntegrasyonHelper.php';

function gorev_gorusme_notlari_mail(array $params, $db): array
{
    ob_start();

    $helper = new EntegrasyonHelper($db);
    $dryRun = !empty($params['dry']);

    // 1. Son 3 günün görüşme notlarını al
    $notlar = $db->fetchAll("
        SELECT
            k.kullanici_ad + ' ' + k.kullanici_soyad AS [Personel],
            COALESCE(NULLIF(c.cari_adi, ''), c.cari_unvan) AS [Cari / İşletme],
            t.GorusmeTipi_Adi AS [Tip],
            d.GorusmeDurum_Adi AS [Durum],
            CONVERT(VARCHAR(16), g.GorusmeNotlari_GorusmeTarihi, 120) AS [Görüşme Tarihi],
            CONVERT(VARCHAR(16), g.GorusmeNotlari_RandevuTarihi, 120) AS [Randevu Tarihi],
            g.GorusmeNotlari_IletisimKisi AS [İletişim Kişi],
            g.GorusmeNotlari_Aciklama AS [Açıklama]
        FROM Cari_GorusmeNotlari g
        LEFT JOIN Cari c ON g.GorusmeNotlari_CariId = c.cari_id
        LEFT JOIN kullanicilar k ON g.GorusmeNotlari_KullaniciId = k.kullanici_id
        LEFT JOIN Cari_GorusmeTipleri t ON g.GorusmeNotlari_TipId = t.GorusmeTipi_Id
        LEFT JOIN Cari_GorusmeDurumlari d ON g.GorusmeNotlari_DurumId = d.GorusmeDurum_Id
        WHERE g.Durum = 1
          AND COALESCE(g.GuncellemeTarihi, g.OlusturmaTarihi) >= DATEADD(day, -3, GETDATE())
        ORDER BY g.GorusmeNotlari_GorusmeTarihi DESC
    ");

    if (empty($notlar)) {
        echo "[INFO] Son 3 güne ait yeni/güncellenmiş görüşme notu bulunamadı.\n";

        return [
            'durum' => CRON_DURUM_BASARILI,
            'sonuc' => 'Gönderilecek görüşme notu yok.',
            'cikti' => ob_get_clean(),
        ];
    }

    // 2. Alıcılar (departman 10)
    $kullanicilar = $db->fetchAll("
        SELECT kullanici_email, kullanici_ad + ' ' + kullanici_soyad AS AdSoyad
        FROM kullanicilar
        WHERE kullanici_departman_id = 10 AND kullanici_durum = 1
    ");

    if (empty($kullanicilar)) {
        echo "[ERROR] Departman ID: 10 olan aktif kullanıcı bulunamadı.\n";

        return [
            'durum' => CRON_DURUM_HATA,
            'sonuc' => 'Alıcı bulunamadı (departman 10).',
            'cikti' => ob_get_clean(),
        ];
    }

    $aliciListesi = [];

    foreach ($kullanicilar as $k) {
        $aliciListesi[$k['kullanici_email']] = $k['AdSoyad'];
    }

    // 2.1 CC (departman 22)
    $ccListesi = [];

    foreach ($db->fetchAll("
        SELECT kullanici_email, kullanici_ad + ' ' + kullanici_soyad AS AdSoyad
        FROM kullanicilar
        WHERE kullanici_departman_id = 22 AND kullanici_durum = 1
    ") as $k) {
        $ccListesi[$k['kullanici_email']] = $k['AdSoyad'];
    }

    // 2.2 BCC (departman 1)
    $bccListesi = [];

    foreach ($db->fetchAll("
        SELECT kullanici_email, kullanici_ad + ' ' + kullanici_soyad AS AdSoyad
        FROM kullanicilar
        WHERE kullanici_departman_id = 1 AND kullanici_durum = 1
    ") as $k) {
        $bccListesi[$k['kullanici_email']] = $k['AdSoyad'];
    }

    // 3. HTML içeriği
    $icerik = "
    <div style='font-family: Arial, sans-serif; color: #333;'>
        <h2 style='color: #0056b3;'>Son 3 Günlük Görüşme Notları Özeti</h2>
        <p>Aşağıda son 3 gün içerisinde eklenen veya güncellenen görüşme notlarının listesi yer almaktadır.</p>
        <table border='1' cellpadding='10' cellspacing='0' style='width: 100%; border-collapse: collapse; font-size: 13px;'>
            <thead>
                <tr style='background-color: #f8f9fa;'>
                    <th>Personel</th>
                    <th>Cari / İşletme</th>
                    <th>Tip</th>
                    <th>Durum</th>
                    <th>Görüşme Tarihi</th>
                    <th>Randevu Tarihi</th>
                    <th>İletişim Kişi</th>
                    <th>Açıklama</th>
                </tr>
            </thead>
            <tbody>";

    foreach ($notlar as $n) {
        $icerik .= "
                <tr>
                    <td>" . htmlspecialchars($n['Personel'] ?? '-') . "</td>
                    <td>" . htmlspecialchars($n['Cari / İşletme'] ?? '-') . "</td>
                    <td align='center'>" . htmlspecialchars($n['Tip'] ?? '-') . "</td>
                    <td align='center'>" . htmlspecialchars($n['Durum'] ?? '-') . "</td>
                    <td align='center'>" . htmlspecialchars($n['Görüşme Tarihi'] ?? '-') . "</td>
                    <td align='center'>" . htmlspecialchars($n['Randevu Tarihi'] ?? '-') . "</td>
                    <td>" . htmlspecialchars($n['İletişim Kişi'] ?? '-') . "</td>
                    <td>" . nl2br(htmlspecialchars($n['Açıklama'] ?? '-')) . "</td>
                </tr>";
    }

    $icerik .= "
            </tbody>
        </table>
        <br>
        <p>Detaylı incelemek için: <a href='https://panel.ornekfirma.com/admin/isletme-gorusmeleri' target='_blank'>İşletme Görüşmeleri Sayfasına Git</a></p>
        <br>
        <p style='font-size: 12px; color: #777;'>Bu e-posta sistem tarafından otomatik olarak oluşturulmuştur. Lütfen yanıtlamayınız.</p>
    </div>";

    $adet = count($notlar);
    $konu = "Son 3 Günlük Görüşme Notları Özeti - " . date('d.m.Y');

    // 4. Gönderim
    if ($dryRun) {
        echo "[DRY-RUN] Mail GÖNDERİLMEDİ. Alıcılar olacaktı: " . implode(', ', array_keys($aliciListesi)) . "\n";
        echo "[DRY-RUN] Not sayısı: {$adet}\n";

        return [
            'durum' => CRON_DURUM_BASARILI,
            'sonuc' => "Deneme modu — {$adet} not, mail gönderilmedi.",
            'cikti' => ob_get_clean(),
        ];
    }

    $sonuc = $helper->mailGonder($aliciListesi, $konu, $icerik, [], $ccListesi, $bccListesi);

    if ($sonuc['success']) {
        echo "[OK] Mail başarıyla gönderildi: " . implode(', ', array_keys($aliciListesi)) . "\n";

        return [
            'durum' => CRON_DURUM_BASARILI,
            'sonuc' => "{$adet} not, " . count($aliciListesi) . ' alıcıya gönderildi.',
            'cikti' => ob_get_clean(),
        ];
    }

    echo "[HATA] Mail gönderilemedi: " . $sonuc['message'] . "\n";

    return [
        'durum' => CRON_DURUM_HATA,
        'sonuc' => 'Mail gönderilemedi: ' . $sonuc['message'],
        'cikti' => ob_get_clean(),
    ];
}
