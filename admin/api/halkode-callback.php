<?php
/**
 * HalkÖde Ödeme Bildirimi (Sale WebHook / return)
 *
 * HalkÖde (Platformode) ödeme tamamlandığında iki yerden bildirim gelir:
 *   1. Sale WebHook  → sunucu-sunucu POST (form-urlencoded), JSON yanıt bekler
 *   2. Return URL    → müşterinin tarayıcısı (?tur=return), HTML sonuç sayfası
 *
 * Oturum kontrolü yoktur; güvenlik hash_key doğrulamasıyla sağlanır
 * (bkz. HalkodeService::bildirimCoz). Aynı işlem birden fazla kez bildirilse de
 * ödeme kalemi bir kez açılır.
 *
 * @see admin/includes/HalkodeService.php
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/HalkodeService.php';

date_default_timezone_set('Europe/Istanbul');

$return = (($_GET['tur'] ?? '') === 'return');
$db     = Database::getInstance();

$durum   = 'hata';
$mesaj   = 'İşlem sonucu alınamadı.';
$tutar   = null;
$kodHttp = 200;

try {
    $servis   = new HalkodeService($db);
    $bildirim = $servis->bildirimCoz(array_merge($_GET, $_POST));

    $link = $servis->linkGetirSiparisNo($bildirim['invoice_id']);

    if (!$link) {
        throw new Exception('Sipariş numarası sistemde bulunamadı: ' . $bildirim['invoice_id']);
    }

    $islem = $servis->sonucIsle($link, $bildirim, (int)$link['OlusturanKullanici']);
    $tutar = (float)$link['Halkode_Odeme_Linkleri_Tutar'];

    if ($islem['durum'] === HalkodeService::DURUM_ODENDI) {
        $durum = 'basarili';
        $mesaj = 'Ödemeniz alındı. Teşekkür ederiz.';
    } else {
        $durum = 'basarisiz';
        $mesaj = $bildirim['mesaj'] ?: 'Ödeme tamamlanamadı.';
    }
} catch (Throwable $e) {
    error_log('HalkÖde bildirim hatası: ' . $e->getMessage());
    $mesaj   = $return ? 'İşlem sonucu doğrulanamadı.' : $e->getMessage();
    $kodHttp = 400;
}

// ── Sale WebHook (sunucu-sunucu) ───────────────────────────
if (!$return) {
    http_response_code($durum === 'basarili' ? 200 : $kodHttp);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $durum === 'basarili', 'message' => $mesaj], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Müşteriye gösterilen sonuç sayfası ─────────────────────
$renk   = $durum === 'basarili' ? '#198754' : '#dc3545';
$ikon   = $durum === 'basarili' ? '&#10004;' : '&#10006;';
$baslik = $durum === 'basarili' ? 'Ödeme Başarılı' : 'Ödeme Tamamlanamadı';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($baslik) ?></title>
    <style>
        body   { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                 background: #f5f6f8; margin: 0; display: flex; align-items: center;
                 justify-content: center; min-height: 100vh; }
        .kutu  { background: #fff; border-radius: 12px; padding: 40px 32px; max-width: 420px;
                 width: 90%; text-align: center; box-shadow: 0 2px 16px rgba(0,0,0,.08); }
        .ikon  { font-size: 48px; color: <?= $renk ?>; }
        h1     { font-size: 20px; margin: 16px 0 8px; color: #212529; }
        p      { color: #6c757d; margin: 0; line-height: 1.5; }
        .tutar { font-size: 22px; font-weight: 600; color: #212529; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="kutu">
        <div class="ikon"><?= $ikon ?></div>
        <h1><?= htmlspecialchars($baslik) ?></h1>
        <p><?= htmlspecialchars($mesaj) ?></p>
        <?php if ($tutar !== null && $durum === 'basarili'): ?>
            <div class="tutar"><?= number_format($tutar, 2, ',', '.') ?> TL</div>
        <?php endif; ?>
    </div>
</body>
</html>
