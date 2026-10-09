<?php
/**
 * Cron Görevi: HalkÖde Ödeme Linki Durum Sorgulama
 *
 * Görev kodu: halkode_durum_sorgula
 * Parametreler:
 *   gun_sayisi (number) — kaç günlük linkler sorgulanacak (varsayılan: 7)
 *
 * Callback ulaşmadığında (ağ kesintisi, hata) ödemenin sisteme düşmemesini
 * önler. Sonuç işleme HalkodeService::sonucIsle üzerinden yapılır; kalem
 * açma koşullu olduğu için callback ile aynı anda çalışsa da mükerrer
 * tahsilat oluşmaz.
 *
 * @see admin/includes/HalkodeService.php
 */

require_once __DIR__ . '/../../admin/includes/HalkodeService.php';

function gorev_halkode_durum_sorgula(array $params, $db): array
{
    ob_start();

    $gun = isset($params['gun_sayisi']) && (int)$params['gun_sayisi'] > 0
        ? (int)$params['gun_sayisi']
        : 7;

    $servis = new HalkodeService($db);

    // Ödendiği veya iptal edildiği bilinen linkler tekrar sorgulanmaz.
    $linkler = $db->fetchAll("
        SELECT Halkode_Odeme_Linkleri_id AS id,
               Halkode_Odeme_Linkleri_SiparisNo AS siparis_no,
               Halkode_Odeme_Linkleri_Tutar AS tutar
        FROM dbo.Halkode_Odeme_Linkleri
        WHERE Durum = 1
          AND Halkode_Odeme_Linkleri_OdemeKalem_id IS NULL
          AND Halkode_Odeme_Linkleri_DurumKod IN ('OLUSTURULDU', 'GONDERILDI')
          AND Halkode_Odeme_Linkleri_Link IS NOT NULL
          AND OlusturmaTarihi >= DATEADD(DAY, ?, GETDATE())
        ORDER BY Halkode_Odeme_Linkleri_id
    ", [-$gun]);

    echo "[BASLA] {$gun} gunluk bekleyen link sayisi: " . count($linkler) . "\n";

    $odenen = 0;
    $bekleyen = 0;
    $hata = 0;

    foreach ($linkler as $link) {
        $id = (int)$link['id'];

        try {
            $sonuc = $servis->durumSorgulaVeIsle($id, 0);

            if ($sonuc['durum'] === HalkodeService::DURUM_ODENDI && $sonuc['islendi']) {
                $odenen++;
                printf("  #%-5d ODENDI    %12s TL -> kalem #%d\n",
                    $id, number_format((float)$link['tutar'], 2, ',', '.'), (int)$sonuc['kalem_id']);
            } elseif ($sonuc['durum'] === HalkodeService::DURUM_ODENDI) {
                printf("  #%-5d ZATEN ISLENMIS\n", $id);
            } else {
                $bekleyen++;
            }
        } catch (Throwable $e) {
            $hata++;
            echo "  #{$id} HATA: " . $e->getMessage() . "\n";
        }
    }

    echo "[BITTI] {$odenen} yeni tahsilat, {$bekleyen} bekliyor, {$hata} hata\n";

    $ozet = "{$odenen} yeni tahsilat, {$bekleyen} bekleyen link";

    if ($hata > 0) {
        $ozet .= ", {$hata} hata";
    }

    return [
        'durum' => $hata > 0 && $odenen === 0 ? CRON_DURUM_HATA : CRON_DURUM_BASARILI,
        'sonuc' => $ozet,
        'cikti' => ob_get_clean(),
    ];
}
