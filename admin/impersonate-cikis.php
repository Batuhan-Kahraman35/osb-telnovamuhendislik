<?php
/**
 * Impersonate Çıkış
 * Orijinal admin hesabına geri döner.
 */
require_once __DIR__ . '/auth.php';
requireAuth();

if (!Auth::isImpersonating()) {
    // Zaten impersonate modunda değilse anasayfaya yönlendir
    header('Location: /admin/anasayfa');
    exit;
}

Auth::stopImpersonate();

header('Location: /admin/personel-yonetimi');
exit;
