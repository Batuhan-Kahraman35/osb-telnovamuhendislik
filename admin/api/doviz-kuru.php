<?php
/**
 * Doviz Kuru API — Sidebar TCMB kur widget'i
 *
 * Cron yoktur; onbellek bayatsa bu istek TCMB'den ceker (DovizKuruHelper).
 *
 * GET /admin/api/doviz-kuru.php
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/DovizKuruHelper.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

try {
    $db   = Database::getInstance();
    $user = Auth::user();

    $helper = new DovizKuruHelper($db);
    $sonuc  = $helper->kurlariGetir($user['kullanici_id'] ?? null);

    echo json_encode([
        'success'       => $sonuc['success'],
        'kurlar'        => $sonuc['kurlar'],
        'tarih'         => $sonuc['tarih'],
        'kaynak'        => $sonuc['kaynak'],
        'hesap_etiketi' => $sonuc['hesap_etiketi'] ?? '',
        'message'       => $sonuc['message'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Doviz kuru API hatasi: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'kurlar'  => [],
        'tarih'   => null,
        'message' => 'Kur bilgisi alinamadi.',
    ], JSON_UNESCAPED_UNICODE);
}
