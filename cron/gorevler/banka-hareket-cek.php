<?php
/**
 * Cron Görevi: Banka Hareketleri Çekme
 *
 * Görev kodu: banka_hareket_cek
 * Parametreler:
 *   max_sayfa    (number)   — bu turda çekilecek azami sayfa (varsayılan: kanal ayarı)
 *   hesap_atla   (checkbox) — işaretliyse hesap/bakiye senkronu yapılmaz
 *   bastan_tara  (checkbox) — cursor'ı sıfırlar; mevcut kayıtlar yeniden okunup
 *                             eksik alanları (VKN vb.) tamamlanır, yeni kayıt eklenmez
 *
 * Örnek Portal API'sinden hareketleri artımlı çeker. Cursor, yerel tablodaki
 * en büyük PortalId değeridir; görev yarıda kesilse bile sonraki tur kaldığı
 * yerden devam eder.
 *
 * Saatlik istek limiti 1000'dir. Helper her yanıttaki X-RateLimit-Remaining
 * başlığını izler ve güvenlik payının altına inince turu erken bitirir.
 *
 * @see admin/includes/BankaHareketHelper.php
 */

require_once __DIR__ . '/../../admin/includes/BankaHareketHelper.php';
require_once __DIR__ . '/../../admin/includes/BankaEslestirmeHelper.php';

function gorev_banka_hareket_cek(array $params, $db): array
{
    ob_start();

    $helper   = new BankaHareketHelper($db);
    $maxSayfa = isset($params['max_sayfa']) && (int)$params['max_sayfa'] > 0
        ? (int)$params['max_sayfa']
        : null;

    $uyari = [];

    // ── Hesaplar ve bakiyeler ────────────────────────────────
    if (empty($params['hesap_atla'])) {
        $hesap = $helper->hesaplariSenkronla();

        echo "[HESAP] {$hesap['eklenen']} yeni, {$hesap['guncellenen']} güncellendi.\n";
    } else {
        echo "[HESAP] Parametre gereği atlandı.\n";
    }

    // ── Hareketler ───────────────────────────────────────────
    $bastanTara = !empty($params['bastan_tara']);

    if ($bastanTara) {
        echo "[MOD] Bastan tarama — mevcut kayitlarin eksik alanlari tamamlanacak.\n";
    }

    $sonuc = $helper->hareketleriSenkronla($maxSayfa, $bastanTara);

    echo "[HAREKET] {$sonuc['eklenen']} yeni kayıt, {$sonuc['atlanan']} mükerrer, "
       . "{$sonuc['sayfa']} sayfa okundu. Son id: {$sonuc['son_id']}\n";

    if ($sonuc['kalan_kota'] !== null) {
        echo "[KOTA] Saatlik kalan istek hakkı: {$sonuc['kalan_kota']}\n";

        if ($sonuc['kalan_kota'] <= BankaHareketHelper::KOTA_GUVENLIK_PAYI) {
            $uyari[] = 'saatlik kota güvenlik payına ulaşıldı';
            echo "[UYARI] Güvenlik payına ulaşıldı, tur erken sonlandırıldı. "
               . "Kalan kayıtlar sonraki çalışmada çekilecek.\n";
        }
    }

    // devam_var: sayfa sınırına takılındı, portalda okunmamış kayıt kaldı
    if (!empty($sonuc['devam_var'])) {
        $uyari[] = 'çekilecek kayıt kaldı';
        echo "[BİLGİ] Sayfa sınırına ulaşıldı, kalan kayıtlar sonraki turda çekilecek.\n";
    }

    // ── Otomatik eşleştirme / ödeme kaydı ────────────────────
    // VKN eşleşen tahsilatlar: aynı gün + aynı tutarlı kalem varsa ona bağlanır,
    // yoksa ödeme kaydı oluşturulur. Parametre kapalıysa bu blok atlanır.
    if (!empty($params['otomatik_eslestir'])) {
        $rapor = !empty($params['sadece_rapor']);
        $es    = new BankaEslestirmeHelper($db);
        $oto   = $es->otomatikIsle(1, $rapor);

        echo "\n[OTOMATİK]" . ($rapor ? ' (yalnızca rapor — kayıt yazılmadı)' : '') . "\n";
        echo "  Mevcut kalemle eşleşen : {$oto['eslesen']}\n";
        echo "  Ödeme kaydı oluşturulan: {$oto['olusturulan']}\n";
        echo "  Atlanan (cari yok)     : {$oto['atlanan']}\n";

        if ($oto['hata'] > 0) {
            $uyari[] = "{$oto['hata']} otomatik işlem hatası";
            echo "  HATA                   : {$oto['hata']}\n";
        }

        foreach ($oto['detay'] as $d) {
            if ($d['islem'] === 'hata') {
                echo "    #{$d['hareket_id']} HATA: {$d['mesaj']}\n";
            } else {
                printf("    #%-5d %-12s %-35s %s\n",
                    $d['hareket_id'],
                    $d['islem'],
                    mb_substr((string)($d['cari'] ?? ''), 0, 35),
                    number_format((float)$d['tutar'], 2, ',', '.')
                );
            }
        }

        if ($oto['eslesen'] || $oto['olusturulan']) {
            $ozetOto = ", {$oto['eslesen']} otomatik eşleşme, {$oto['olusturulan']} ödeme kaydı";
        } else {
            $ozetOto = '';
        }
    } else {
        $ozetOto = '';
    }

    $ozet = "{$sonuc['eklenen']} yeni hareket ({$sonuc['sayfa']} sayfa){$ozetOto}";

    if ($uyari) {
        $ozet .= ' — ' . implode(', ', $uyari);
    }

    return [
        'durum' => CRON_DURUM_BASARILI,
        'sonuc' => $ozet,
        'cikti' => ob_get_clean(),
    ];
}
