<?php
/**
 * Cron Görevi: Pazaryeri Sipariş Çekme
 *
 * Görev kodu: pazaryeri_siparis_cek
 * Parametreler:
 *   gun_sayisi (number) — kaç günlük sipariş çekilecek (varsayılan: 2, en fazla 14)
 *
 * Aktif tüm Trendyol kanallarının siparişlerini çeker. Kayıt UPSERT olduğu
 * için aralığın örtüşmesi mükerrer kayıt oluşturmaz; aralık çalışma
 * sıklığından geniş tutulur ki kaçırılan tur sipariş kaybettirmesin.
 *
 * @see admin/includes/TrendyolService.php
 */

require_once __DIR__ . '/../../admin/includes/TrendyolService.php';

function gorev_pazaryeri_siparis_cek(array $params, $db): array
{
    ob_start();

    $gun = isset($params['gun_sayisi']) && (int)$params['gun_sayisi'] > 0
        ? (int)$params['gun_sayisi']
        : 2;

    $kanallar = $db->fetchAll("
        SELECT ek.Kanal_Id, ek.Kanal_Adi
        FROM EntegrasyonKanallari ek
        INNER JOIN Entegrasyon e ON e.Entegrasyon_Id = ek.Kanal_Entegrasyon_Id
        WHERE e.Entegrasyon_Kod = 'TRENDYOL'
          AND ek.Kanal_Durum = 1
        ORDER BY ek.Kanal_Id
    ");

    echo "[BASLA] {$gun} gunluk siparis, kanal sayisi: " . count($kanallar) . "\n";

    $yeni = 0; $guncellenen = 0; $hata = 0; $kanalHata = 0;

    foreach ($kanallar as $k) {
        try {
            $s = TrendyolService::fromDb($db, (int)$k['Kanal_Id'])->siparisleriSenkronla($gun);

            $yeni        += $s['yeni'];
            $guncellenen += $s['guncellenen'];
            $hata        += $s['hata'];

            echo "  {$k['Kanal_Adi']}: {$s['toplam']} siparis, {$s['yeni']} yeni, "
               . "{$s['guncellenen']} guncellendi, {$s['hata']} hata\n";
        } catch (Throwable $e) {
            $kanalHata++;
            echo "  {$k['Kanal_Adi']} HATA: " . $e->getMessage() . "\n";
        }
    }

    echo "[BITTI] {$yeni} yeni, {$guncellenen} guncellendi, {$hata} kayit hatasi, {$kanalHata} kanal hatasi\n";

    $ozet = "{$yeni} yeni sipariş, {$guncellenen} güncellendi";

    if ($hata > 0) {
        $ozet .= ", {$hata} kayıt hatası";
    }
    if ($kanalHata > 0) {
        $ozet .= ", {$kanalHata} kanal hatası";
    }

    return [
        'durum' => ($kanalHata > 0 && $kanalHata === count($kanallar)) || !$kanallar
            ? CRON_DURUM_HATA
            : CRON_DURUM_BASARILI,
        'sonuc' => $kanallar ? $ozet : 'Aktif Trendyol kanalı bulunamadı.',
        'cikti' => ob_get_clean(),
    ];
}
