<?php
/**
 * Cron Görevi: Domain WHOIS Güncelleme
 *
 * Görev kodu: domain_whois_guncelle
 * Parametre : test (checkbox) — işaretliyse DB'ye yazmaz, sadece simülasyon
 *
 * Bu görev, mevcut admin/cron/domain-whois-guncelle.php dosyasını INCLUDE eder.
 * Dosya global state ve kendi logMessage() fonksiyonu üzerine kurulu olduğu için
 * yeniden yazılmadı; içindeki tek satırlık CRON_DISPATCH kontrolü sayesinde
 * HTML çıktı bloğu bastırılır, yalnızca log satırları üretilir.
 */

function gorev_domain_whois_guncelle(array $params, $db): array
{
    // KRİTİK: include edilen dosya üst düzeyde $logfile / $output atar ve
    // logMessage() bunlara "global" ile erişir. Fonksiyon kapsamında require
    // edildiğinde bu atamalar YEREL değişken olur, logMessage() ise boş global
    // görür ve file_put_contents('', ...) "Path cannot be empty" hatası verir.
    // Aşağıdaki global bildirimi, include'ın atadığı değerleri global kapsama bağlar.
    global $logfile, $output, $isTestMode, $isWebRequest;

    if (!defined('CRON_DISPATCH')) {
        define('CRON_DISPATCH', true);
    }

    // Include edilen dosya test modunu $_GET['test'] üzerinden okur
    $eskiGet = $_GET;
    $_GET    = !empty($params['test']) ? ['test' => 1] : [];

    ob_start();

    try {
        // $output dizisini include sonrası okuyabilmek için değişken kapsamı korunur
        require __DIR__ . '/../../admin/cron/domain-whois-guncelle.php';

        $cikti = (string)ob_get_clean();
    } catch (Throwable $e) {
        $cikti = (string)ob_get_clean();
        $_GET  = $eskiGet;

        throw $e;
    }

    $_GET = $eskiGet;

    // include edilen dosya logMessage() ile $output dizisini doldurur
    $hataSayisi   = 0;
    $basariSayisi = 0;

    if (isset($output) && is_array($output)) {
        foreach ($output as $satir) {
            $tip = strtoupper((string)($satir['type'] ?? ''));

            if ($tip === 'ERROR') {
                $hataSayisi++;
            } elseif ($tip === 'SUCCESS') {
                $basariSayisi++;
            }
        }

        // CLI dışında include hiçbir şey echo etmez; log satırlarını çıktıya biz basarız
        if (trim($cikti) === '') {
            foreach ($output as $satir) {
                $cikti .= '[' . ($satir['time'] ?? '') . '] [' . ($satir['type'] ?? '') . '] '
                        . ($satir['message'] ?? '') . "\n";
            }
        }
    }

    $testNotu = !empty($params['test']) ? '[TEST MODU] ' : '';
    $ozet     = $testNotu . "Güncellenen: {$basariSayisi}, hata: {$hataSayisi}";

    return [
        'durum' => $hataSayisi > 0 && $basariSayisi === 0 ? CRON_DURUM_HATA : CRON_DURUM_BASARILI,
        'sonuc' => $ozet,
        'cikti' => $cikti,
    ];
}
