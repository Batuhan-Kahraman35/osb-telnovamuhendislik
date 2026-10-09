<?php
/**
 * Cron Görevi: Cari Web Tarama
 *
 * Görev kodu: cari_tarama
 *
 * Sıradaki cari web tarama işini çalıştırır (yetkili / iletişim bilgisi toplama).
 *
 * Dosya kilidi KALDIRILDI — overlap koruması artık merkezi.
 * Eski 900 sn'lik zombi eşiği yerine CronGorevler_MaxSureSn x 1.5 kullanılıyor.
 */

require_once __DIR__ . '/../../admin/includes/CariTaramaHelper.php';

function gorev_cari_tarama(array $params, $db): array
{
    ob_start();

    $helper = new CariTaramaHelper($db);
    $sonuc  = $helper->processNextJob();

    $mesaj = $sonuc['message'] ?? 'İşlem tamamlandı.';
    echo '[INFO] ' . $mesaj . "\n";

    $ozet = $mesaj;

    if (!empty($sonuc['job_id'])) {
        $sayfa   = (int)($sonuc['visited_count'] ?? 0);
        $yetkili = (int)($sonuc['contact_count'] ?? 0);

        echo '[JOB] #' . $sonuc['job_id'] . ' | Sayfa: ' . $sayfa . ' | Yetkili: ' . $yetkili . "\n";

        $ozet = 'İş #' . $sonuc['job_id'] . " — sayfa: {$sayfa}, yetkili: {$yetkili}";
    }

    return [
        'durum' => CRON_DURUM_BASARILI,
        'sonuc' => $ozet,
        'cikti' => ob_get_clean(),
    ];
}
