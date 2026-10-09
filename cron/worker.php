<?php
/**
 * Merkezi Cron Sistemi - Worker (tekil tetikleyici)
 *
 * Tek bir görevi veya zamanlamayı elle çalıştırır. Senkron çalışır,
 * çıktıyı düz metin olarak döndürür.
 *
 * Kullanım:
 *   Web : /cron/worker.php?gorev=toplu_mail_gonder&key=...
 *         /cron/worker.php?zamanlama=3&key=...
 *         /cron/worker.php?gorev=giden_efatura_rapor_mail&dry=1&key=...
 *   CLI : php cron/worker.php gorev=toplu_mail_gonder
 *         php cron/worker.php zamanlama=3
 *         php cron/worker.php gorev=cron_log_temizle gun=30
 */

ini_set('memory_limit', '512M');
set_time_limit(900);

require_once __DIR__ . '/tasks.php';

$db = Database::getInstance();

cronKeyDogrula($db);

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Parametreleri topla (CLI: anahtar=deger, Web: $_GET)
$girdi = $_GET;

if (PHP_SAPI === 'cli' && isset($argv)) {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $girdi[trim($k)] = $v;
        }
    }
}

unset($girdi['key']);

$gorevKodu   = isset($girdi['gorev'])      ? trim((string)$girdi['gorev'])  : '';
$zamanlamaId = isset($girdi['zamanlama'])  ? (int)$girdi['zamanlama']       : 0;

unset($girdi['gorev'], $girdi['zamanlama']);

$baslama = microtime(true);

try {
    if ($zamanlamaId > 0) {
        // ── Zamanlama tetiği: parametreler DB'den gelir
        $z = $db->fetchOne("
            SELECT
                z.CronZamanlamalar_Id,
                z.CronZamanlamalar_Ad,
                z.CronZamanlamalar_SabitParametreler,
                g.CronGorevler_Id,
                g.CronGorevler_Ad,
                g.CronGorevler_GorevKodu,
                g.CronGorevler_MaxSureSn
            FROM dbo.CronZamanlamalar z
            INNER JOIN dbo.CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id
            WHERE z.CronZamanlamalar_Id = ? AND z.Durum = 1 AND g.Durum = 1
        ", [$zamanlamaId]);

        if (!$z) {
            throw new RuntimeException('Zamanlama bulunamadı veya pasif: #' . $zamanlamaId);
        }

        $ham    = json_decode((string)($z['CronZamanlamalar_SabitParametreler'] ?? ''), true);
        $params = dinamikParamCoz(is_array($ham) ? $ham : []);

        cronYaz("[WORKER] Zamanlama #{$zamanlamaId} — {$z['CronGorevler_Ad']} / {$z['CronZamanlamalar_Ad']}\n\n");

        $sonuc = cronCalistirVeLogla($db, $z, $zamanlamaId, $params, CRON_TETIK_MANUEL);

    } elseif ($gorevKodu !== '') {
        // ── Görev tetiği: parametreler URL/CLI'dan gelir, şemaya göre doğrulanır
        $gorev = cronGorevGetir($db, $gorevKodu);

        if (!$gorev) {
            throw new RuntimeException('Görev bulunamadı veya pasif: ' . $gorevKodu);
        }

        $params = dinamikParamCoz(
            cronParamDogrula($gorev['CronGorevler_Parametreler'] ?? null, $girdi)
        );

        cronYaz("[WORKER] Görev — {$gorev['CronGorevler_Ad']} ({$gorevKodu})\n");

        if ($params) {
            cronYaz("[PARAM] " . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n");
        }

        cronYaz("\n");

        $sonuc = cronCalistirVeLogla($db, $gorev, null, $params, CRON_TETIK_MANUEL);

    } else {
        throw new RuntimeException(
            "Parametre eksik.\n"
            . "Kullanım: worker.php?gorev=<kod>  veya  worker.php?zamanlama=<id>"
        );
    }

    if (!empty($sonuc['atlandi'])) {
        cronYaz("[ATLANDI] {$sonuc['sonuc']}\n");
        exit(0);
    }

    if ($sonuc['cikti'] !== '') {
        cronYaz($sonuc['cikti']);
        cronYaz("\n");
    }

    $durumMetin = $sonuc['durum'] === CRON_DURUM_BASARILI ? 'BAŞARILI' : 'HATA';
    $sure       = round(microtime(true) - $baslama, 2);

    cronYaz("[{$durumMetin}] {$sonuc['sonuc']}\n");
    cronYaz("[SÜRE] {$sure}s | Log #{$sonuc['log_id']}\n");

    exit($sonuc['durum'] === CRON_DURUM_BASARILI ? 0 : 1);

} catch (Throwable $e) {
    cronYaz("[FATAL] " . $e->getMessage() . "\n");
    error_log('cron/worker.php: ' . $e->getMessage());
    exit(1);
}
