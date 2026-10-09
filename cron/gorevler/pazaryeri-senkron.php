<?php
/**
 * Cron Görevi: Pazaryeri Senkron (jenerik)
 *
 * Görev kodu: pazaryeri_senkron
 * Parametreler:
 *   gun_geri  (number) — sipariş için kaç gün geriye (varsayılan 2, en fazla 14)
 *   iade_gun  (number) — iade için kaç gün geriye (varsayılan 30)
 *   soru_gun  (number) — soru için kaç gün geriye (varsayılan 30)
 *   islemler  (text)   — "siparis,iade,soru,batch" (boşsa hepsi)
 *
 * Aktif tüm pazaryeri kanallarını (tanim_Pazaryerleri + EntegrasyonKanallari) dolaşır,
 * her kanalda yalnız yetenek bayrağı açık olan işlemleri yapar. Yeni pazaryeri eklendiğinde
 * bu dosya DEĞİŞMEZ. Bir kanalın hatası diğerlerini durdurmaz; hata sebebi özete yazılır.
 * Kayıtlar UPSERT olduğu için aralık çalışma sıklığından geniş tutulur (kaçan tur veri kaybettirmez).
 *
 * Eski görev: pazaryeri_siparis_cek (yalnız Trendyol sipariş) — bunun yerine geçer.
 *
 * @see admin/includes/PazaryeriFabrika.php, admin/includes/PazaryeriApi.php
 */

require_once __DIR__ . '/../../admin/includes/PazaryeriFabrika.php';

function gorev_pazaryeri_senkron(array $params, $db): array
{
    ob_start();

    $sayi = fn($ad, $varsayilan) => isset($params[$ad]) && (int)$params[$ad] > 0 ? (int)$params[$ad] : $varsayilan;
    $gunGeri = min(14, $sayi('gun_geri', 2));
    $iadeGun = $sayi('iade_gun', 30);
    $soruGun = $sayi('soru_gun', 30);

    $islemler = array_filter(array_map('trim', explode(',', (string)($params['islemler'] ?? ''))));
    if (!$islemler) {
        $islemler = ['siparis', 'iade', 'soru', 'batch'];
    }

    $kanallar = PazaryeriFabrika::kanallar();
    echo "[BASLA] kanal: " . count($kanallar) . " | islemler: " . implode(',', $islemler)
       . " | siparis {$gunGeri} gun, iade {$iadeGun} gun, soru {$soruGun} gun\n";

    $ozet = []; $hataSayisi = 0; $basariliKanal = 0;

    foreach ($kanallar as $k) {
        $etiket = $k['Pazaryerleri_Ad'] . ' / ' . $k['kanal_adi'];
        $api    = PazaryeriFabrika::olusturKanaldan((int)$k['kanal_id'], null);

        if (!$api || !$api->hazirMi()) {
            $sebep = $api ? $api->getHata() : PazaryeriFabrika::sonHata();
            echo "  {$etiket}: KURULAMADI — {$sebep}\n";
            $ozet[] = "{$etiket} (HATA: {$sebep})";
            $hataSayisi++;
            continue;
        }

        $parca = []; $kanalHata = false;
        $calistir = function (string $islem, string $yetenek, callable $is) use ($api, $islemler, &$parca, &$kanalHata, &$hataSayisi) {
            if (!in_array($islem, $islemler, true) || !$api->yetenek($yetenek)) {
                return;
            }
            try {
                $r = $is();
                if (!empty($r['basarili'])) {
                    $parca[] = "{$islem} " . (int)($r['adet'] ?? 0) . (isset($r['yeni']) ? " ({$r['yeni']} yeni)" : '');
                } else {
                    $parca[] = "{$islem} HATA: " . ($r['hata'] ?? 'bilinmiyor');
                    $kanalHata = true; $hataSayisi++;
                }
            } catch (Throwable $e) {
                $parca[] = "{$islem} İSTİSNA: " . $e->getMessage();
                $kanalHata = true; $hataSayisi++;
            }
        };

        $calistir('siparis', 'siparis',   fn() => $api->siparisleriSenkronla($gunGeri));
        $calistir('iade',    'iade',      fn() => $api->iadeleriSenkronla($iadeGun));
        $calistir('soru',    'soruCevap', fn() => $api->sorulariSenkronla($soruGun));

        // Bekleyen toplu işlemler (stok/fiyat, ürün gönderimi) — sonucu ortak katman işler
        if (in_array('batch', $islemler, true) && $api->yetenek('batch')) {
            $bekleyen = $db->fetchAll("
                SELECT PazaryeriBatchIstekleri_BatchId AS batchId
                FROM dbo.PazaryeriBatchIstekleri
                WHERE kanal_id = ? AND PazaryeriBatchIstekleri_Durum = 'beklemede' AND Durum = 1
                  AND OlusturmaTarihi >= DATEADD(DAY, -7, GETDATE())
            ", [$api->getKanalId()]);
            $biten = 0;
            foreach ($bekleyen as $b) {
                try {
                    $r = $api->batchDurumGuncelle($b['batchId']);
                    if (!empty($r['basarili']) && ($r['durum'] ?? '') !== 'beklemede') $biten++;
                } catch (Throwable $e) {
                    echo "    batch {$b['batchId']} İSTİSNA: " . $e->getMessage() . "\n";
                }
            }
            if ($bekleyen) $parca[] = "batch {$biten}/" . count($bekleyen);
        }

        $satir = $parca ? implode(', ', $parca) : 'işlem yok (yetenek kapalı)';
        echo "  {$etiket}: {$satir}\n";
        $ozet[] = "{$etiket} ({$satir})";
        if (!$kanalHata) $basariliKanal++;
    }

    echo "[BITTI] {$basariliKanal}/" . count($kanallar) . " kanal hatasız, {$hataSayisi} hata\n";

    return [
        // Hiç kanal yoksa veya hiçbiri hatasız bitmediyse HATA; kısmi hata özette görünür
        'durum' => (!$kanallar || ($hataSayisi > 0 && $basariliKanal === 0)) ? CRON_DURUM_HATA : CRON_DURUM_BASARILI,
        'sonuc' => $kanallar ? (count($kanallar) . ' kanal — ' . implode(' | ', $ozet)) : 'Aktif pazaryeri kanalı bulunamadı.',
        'cikti' => ob_get_clean(),
    ];
}
