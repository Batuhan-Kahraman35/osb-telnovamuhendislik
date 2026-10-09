<?php
/**
 * Cron Görevi: Toplu Mail Gönderim Kuyruğu
 *
 * Görev kodu: toplu_mail_gonder
 *
 * Her turda BATCH kadar mail gönderir, mailler arası ARALIK saniye bekler
 * (Gmail ücretsiz ~20/saat yumuşak limiti).
 *
 * Dosya kilidi KALDIRILDI — overlap koruması artık cronCalisiyorMu() ile
 * CronCalismaLog üzerinden merkezi olarak yapılıyor.
 *
 * Ayarlar (CronAyarlar tablosu):
 *   toplu_mail_batch     -> Her turda gönderilecek max mail (varsayılan 3)
 *   toplu_mail_aralik_sn -> Mailler arası bekleme saniyesi (varsayılan 15)
 */

require_once __DIR__ . '/../../admin/includes/EntegrasyonHelper.php';

function gorev_toplu_mail_gonder(array $params, $db): array
{
    ob_start();

    $batch  = (int)($params['batch']  ?? cronAyar($db, 'toplu_mail_batch', '3'));
    $aralik = (int)($params['aralik'] ?? cronAyar($db, 'toplu_mail_aralik_sn', '15'));

    if ($batch < 1)  { $batch = 1; }
    if ($batch > 50) { $batch = 50; }
    if ($aralik < 0) { $aralik = 0; }

    $helper    = new EntegrasyonHelper($db);
    $gonderilen = 0;
    $hatali     = 0;

    // 1. Bekleyen detay kayıtlarını al (birden fazla kampanya varsa Gonderim_Id ASC sırayla)
    $bekleyenler = $db->fetchAll("
        SELECT TOP (?)
            d.Detay_Id,
            d.Detay_Gonderim_Id,
            d.Detay_Alici_Ad,
            d.Detay_Alici_Email,
            d.Detay_Deneme_Sayisi,
            g.Gonderim_Konu,
            g.Gonderim_Icerik,
            g.Gonderim_Ekler,
            g.Gonderim_Kanal_Id
        FROM dbo.TopluMailGonderimiDetay d
        INNER JOIN dbo.TopluMailGonderimi g ON d.Detay_Gonderim_Id = g.Gonderim_Id
        WHERE d.Detay_Durum = 'Bekliyor'
          AND d.Detay_Deneme_Sayisi < 3
          AND g.Gonderim_Durum IN ('Devam Ediyor', 'Bekliyor')
          AND g.Durum = 1
          AND d.Durum = 1
        ORDER BY g.Gonderim_Id ASC, d.Detay_Id ASC
    ", [$batch]);

    if (empty($bekleyenler)) {
        echo "[INFO] Bekleyen mail yok.\n";
        return ['durum' => CRON_DURUM_BASARILI, 'sonuc' => 'Bekleyen mail yok.', 'cikti' => ob_get_clean()];
    }

    $gonderimlerGuncelle = [];

    foreach ($bekleyenler as $satir) {
        $gonderimId = (int)$satir['Detay_Gonderim_Id'];
        $detayId    = (int)$satir['Detay_Id'];

        // Kampanyayı "Devam Ediyor" yap (ilk kez işleniyorsa)
        if (!isset($gonderimlerGuncelle[$gonderimId])) {
            $db->execute("
                UPDATE dbo.TopluMailGonderimi
                SET Gonderim_Durum = 'Devam Ediyor',
                    Gonderim_Baslama_Tarihi = CASE WHEN Gonderim_Baslama_Tarihi IS NULL THEN GETDATE() ELSE Gonderim_Baslama_Tarihi END,
                    GuncellemeTarihi = GETDATE()
                WHERE Gonderim_Id = ? AND Gonderim_Durum IN ('Bekliyor', 'Devam Ediyor')
            ", [$gonderimId]);

            $gonderimlerGuncelle[$gonderimId] = true;
        }

        // Ekleri çöz
        $ekler = [];

        if (!empty($satir['Gonderim_Ekler'])) {
            $eklerRaw = json_decode($satir['Gonderim_Ekler'], true);

            if (is_array($eklerRaw)) {
                $ekler = $eklerRaw;
            }
        }

        $sonuc = $helper->mailGonderKanalIle(
            (int)$satir['Gonderim_Kanal_Id'],
            $satir['Detay_Alici_Email'],
            $satir['Detay_Alici_Ad'] ?? '',
            $satir['Gonderim_Konu'],
            $satir['Gonderim_Icerik'],
            $ekler
        );

        if ($sonuc['success']) {
            $db->execute("
                UPDATE dbo.TopluMailGonderimiDetay
                SET Detay_Durum = 'Gönderildi',
                    Detay_Gonderim_Tarihi = GETDATE(),
                    Detay_Deneme_Sayisi = Detay_Deneme_Sayisi + 1,
                    GuncellemeTarihi = GETDATE()
                WHERE Detay_Id = ?
            ", [$detayId]);

            $db->execute("
                UPDATE dbo.TopluMailGonderimi
                SET Gonderim_Gonderilen = Gonderim_Gonderilen + 1,
                    GuncellemeTarihi = GETDATE()
                WHERE Gonderim_Id = ?
            ", [$gonderimId]);

            echo "[OK]  " . $satir['Detay_Alici_Email'] . "\n";
            $gonderilen++;
        } else {
            $db->execute("
                UPDATE dbo.TopluMailGonderimiDetay
                SET Detay_Durum = CASE WHEN Detay_Deneme_Sayisi + 1 >= 3 THEN 'Hata' ELSE 'Bekliyor' END,
                    Detay_Hata_Mesaj = ?,
                    Detay_Deneme_Sayisi = Detay_Deneme_Sayisi + 1,
                    GuncellemeTarihi = GETDATE()
                WHERE Detay_Id = ?
            ", [mb_substr($sonuc['message'], 0, 1000), $detayId]);

            $db->execute("
                UPDATE dbo.TopluMailGonderimi
                SET Gonderim_Hatali = Gonderim_Hatali + 1,
                    GuncellemeTarihi = GETDATE()
                WHERE Gonderim_Id = ?
            ", [$gonderimId]);

            echo "[HATA] " . $satir['Detay_Alici_Email'] . " — " . $sonuc['message'] . "\n";
            $hatali++;
        }

        // Gmail rate limit: mailler arası bekleme (son mailden sonra bekleme yok)
        if ($aralik > 0 && $satir !== end($bekleyenler)) {
            sleep($aralik);
        }
    }

    // 2. İşlenen kampanyaların tamamlanıp tamamlanmadığını kontrol et
    foreach (array_keys($gonderimlerGuncelle) as $gId) {
        $kalanBekleyen = $db->fetchOne("
            SELECT COUNT(*) AS sayi
            FROM dbo.TopluMailGonderimiDetay
            WHERE Detay_Gonderim_Id = ?
              AND Detay_Durum = 'Bekliyor'
              AND Detay_Deneme_Sayisi < 3
              AND Durum = 1
        ", [$gId]);

        if (($kalanBekleyen['sayi'] ?? 0) == 0) {
            $db->execute("
                UPDATE dbo.TopluMailGonderimi
                SET Gonderim_Durum = 'Tamamlandı',
                    Gonderim_Bitis_Tarihi = GETDATE(),
                    GuncellemeTarihi = GETDATE()
                WHERE Gonderim_Id = ?
                  AND Gonderim_Durum = 'Devam Ediyor'
            ", [$gId]);

            echo "[TAMAMLANDI] Kampanya #" . $gId . "\n";
        }
    }

    $ozet = "Gönderilen: {$gonderilen}, hatalı: {$hatali}";

    return [
        'durum' => $hatali > 0 && $gonderilen === 0 ? CRON_DURUM_HATA : CRON_DURUM_BASARILI,
        'sonuc' => $ozet,
        'cikti' => ob_get_clean(),
    ];
}
