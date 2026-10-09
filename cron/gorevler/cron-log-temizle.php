<?php
/**
 * Cron Görevi: Cron Log Temizliği (retention)
 *
 * Görev kodu: cron_log_temizle
 * Parametre : gun (number) — kaç günden eski kayıtlar silinsin
 *
 * Saklama süresi parametre verilmezse CronAyarlar.log_saklama_gun (varsayılan 90).
 * Tabloyu uzun süre kilitlememek için 5000'lik partiler hâlinde silinir.
 */

function gorev_cron_log_temizle(array $params, $db): array
{
    ob_start();

    $gun = (int)($params['gun'] ?? cronAyar($db, 'log_saklama_gun', '90'));

    // Güvenlik alt sınırı: yanlış parametreyle tüm geçmişin silinmesini engeller
    if ($gun < 7) {
        $gun = 7;
    }

    $toplam  = 0;
    $partiNo = 0;

    do {
        $db->execute("
            DELETE TOP (5000) FROM dbo.CronCalismaLog
            WHERE CronCalismaLog_BaslangicTarihi < DATEADD(DAY, -?, GETDATE())
              AND CronCalismaLog_CalismaDurum <> ?
        ", [$gun, CRON_DURUM_CALISIYOR]);

        // DİKKAT: @@ROWCOUNT bir sonraki sorguda sıfırlanır.
        // execute() sonrası İLK çağrıda okunmalıdır.
        $satir   = $db->fetchOne("SELECT @@ROWCOUNT AS Adet");
        $silinen = (int)($satir['Adet'] ?? 0);

        $toplam += $silinen;
        $partiNo++;

        if ($silinen > 0) {
            echo "[PARTİ {$partiNo}] {$silinen} kayıt silindi.\n";
        }
    } while ($silinen === 5000 && $partiNo < 200);

    echo "[OK] {$gun} günden eski toplam {$toplam} log kaydı silindi.\n";

    // Hâlâ "çalışıyor" görünen eski kayıtlar varsa uyar (zombi birikimi göstergesi)
    $zombi = $db->fetchOne("
        SELECT COUNT(*) AS Adet
        FROM dbo.CronCalismaLog
        WHERE CronCalismaLog_CalismaDurum = ?
          AND CronCalismaLog_BaslangicTarihi < DATEADD(HOUR, -24, GETDATE())
    ", [CRON_DURUM_CALISIYOR]);

    $zombiAdet = (int)($zombi['Adet'] ?? 0);

    if ($zombiAdet > 0) {
        echo "[UYARI] 24 saatten uzun süredir 'çalışıyor' durumunda {$zombiAdet} kayıt var. "
           . "İlgili görevlerin MaxSureSn değerlerini kontrol edin.\n";
    }

    return [
        'durum' => CRON_DURUM_BASARILI,
        'sonuc' => "{$toplam} kayıt silindi ({$gun} gün)" . ($zombiAdet > 0 ? ", {$zombiAdet} zombi kayıt uyarısı" : ''),
        'cikti' => ob_get_clean(),
    ];
}
