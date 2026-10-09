<?php
/**
 * Merkezi Cron Sistemi - Runner (tek giriş noktası)
 *
 * Plesk Scheduled Task (TEK TANIM, her dakika):
 *   URL : https://panel.ornekfirma.com/cron/runner.php?key=<CronAyarlar.cron_secret_key>
 *   CLI : php /path/to/cron/runner.php
 *
 * Aktif zamanlamaları tarar, vakti gelenleri sırayla çalıştırır.
 * Her çalışma CronCalismaLog tablosuna işlenir.
 */

ini_set('memory_limit', '512M');
set_time_limit(600);

require_once __DIR__ . '/tasks.php';

$db = Database::getInstance();

cronKeyDogrula($db);

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
}

$simdi    = new DateTime();
$buDakika = $simdi->format('Y-m-d H:i:00');
$baslama  = microtime(true);

cronYaz("[RUNNER] " . $simdi->format('Y-m-d H:i:s') . "\n");

// Aktif zamanlamalar
// NOT: SonCalisma metin olarak da çekilir. sqlsrv DATETIME'ı PHP nesnesi
//      döndürdüğü için string karşılaştırması yapılamaz; 120 formatı
//      (YYYY-MM-DD HH:MM:SS) leksikografik sıralamada kronolojik sırayla
//      birebir örtüştüğü için düz string karşılaştırması güvenlidir.
$zamanlamalar = $db->fetchAll("
    SELECT
        z.CronZamanlamalar_Id,
        z.CronZamanlamalar_GorevId,
        z.CronZamanlamalar_Ad,
        z.CronZamanlamalar_CronIfadesi,
        z.CronZamanlamalar_SabitParametreler,
        z.CronZamanlamalar_TelafiEt,
        z.CronZamanlamalar_TelafiSaatSiniri,
        CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120) AS SonCalismaMetin,
        g.CronGorevler_Id,
        g.CronGorevler_Ad,
        g.CronGorevler_GorevKodu,
        g.CronGorevler_MaxSureSn
    FROM dbo.CronZamanlamalar z
    INNER JOIN dbo.CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id
    WHERE z.Durum = 1
      AND g.Durum = 1
      AND z.CronZamanlamalar_BaslangicTarihi <= GETDATE()
      AND (z.CronZamanlamalar_BitisTarihi IS NULL OR z.CronZamanlamalar_BitisTarihi >= GETDATE())
    ORDER BY z.CronZamanlamalar_Id
");

$calisan = 0;
$atlanan = 0;

foreach ($zamanlamalar as $z) {
    $ifade    = (string)$z['CronZamanlamalar_CronIfadesi'];
    $etiket   = $z['CronGorevler_Ad'] . ' / ' . $z['CronZamanlamalar_Ad'];
    $sonMetin = $z['SonCalismaMetin'] ?? null;

    // ── 1) Normal eşleşme
    $tetik  = cronEslesiyor($ifade, $simdi);
    $telafi = false;

    // ── 2) Eşleşme yoksa telafi (catch-up) kontrolü
    if (!$tetik && (int)$z['CronZamanlamalar_TelafiEt'] === 1) {
        $telafi = cronKacirilanTetik(
            $ifade,
            $sonMetin,
            (int)$z['CronZamanlamalar_TelafiSaatSiniri'],
            $simdi
        );
        $tetik = $telafi;
    }

    if (!$tetik) {
        continue;
    }

    // ── 3) Aynı dakika çift tetik kilidi
    if ($sonMetin !== null && $sonMetin >= $buDakika) {
        cronYaz("  [ATLA] {$etiket} — bu dakika zaten çalıştı.\n");
        $atlanan++;
        continue;
    }

    // ── 4) Kilidi al (overlap kontrolü cronCalistirVeLogla içinde)
    $db->update('CronZamanlamalar', [
        'CronZamanlamalar_SonCalisma' => date('Y-m-d H:i:s'),
        'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
    ], ['CronZamanlamalar_Id' => (int)$z['CronZamanlamalar_Id']]);

    // ── 5) Parametreleri çöz ve çalıştır
    $ham    = json_decode((string)($z['CronZamanlamalar_SabitParametreler'] ?? ''), true);
    $params = dinamikParamCoz(is_array($ham) ? $ham : []);

    $onek = $telafi ? '[TELAFİ] ' : '';
    cronYaz("  {$onek}[BAŞLA] {$etiket} ({$ifade})\n");

    $sonuc = cronCalistirVeLogla(
        $db,
        $z,
        (int)$z['CronZamanlamalar_Id'],
        $params,
        CRON_TETIK_OTOMATIK,
        null,
        $telafi
    );

    if (!empty($sonuc['atlandi'])) {
        cronYaz("  [ATLA] {$sonuc['sonuc']}\n");
        $atlanan++;
        continue;
    }

    $durumMetin = $sonuc['durum'] === CRON_DURUM_BASARILI ? 'BİTTİ' : 'HATA';
    cronYaz("  [{$durumMetin}] {$sonuc['sonuc']}\n");
    $calisan++;
}

$sure = round(microtime(true) - $baslama, 2);
cronYaz("[RUNNER] Tamamlandı — çalışan: {$calisan}, atlanan: {$atlanan}, süre: {$sure}s\n");
