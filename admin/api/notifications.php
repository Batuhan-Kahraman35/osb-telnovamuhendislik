<?php
/**
 * Bildirim APi
 * Yeni başvurular ve diğer bildirimler
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

header('Content-Type: application/json');

$db = Database::getInstance();
$user = Auth::user();

// Yetki kontrolç - personel-yonetimi.php erişimi var mi?
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    'personel-yonetimi.php'
);

// Erişim Yetkisi yoksa boş dçndçr
if (!$pagePermissions['has_access']) {
    echo json_encode([
        'success' => true,
        'data' => [],
        'count' => 0,
        'debug' => 'Erişim Yetkisi yok'
    ]);
    exit;
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if ($action === 'get_notifications') {
        // Debug: Toplam durum=NULL (başvuru bekliyor) kayit sayisini kontrol et
        $debugcount = $db->fetchOne("SELECT count(*) as sayi FROM kullanicilar WHERE kullanici_durum IS NULL")['sayi'] ?? 0;
        
        // Yeni başvurulari çek (son 30 gçn)
        $basvurular = $db->fetchAll("
            SELECT 
                kullanici_id,
                kullanici_ad,
                kullanici_soyad,
                kullanici_email,
                CONVERT(VARCHAR(19), kullanici_olusturma_tarihi, 120) as olusturma_tarihi,
                DATEDiFF(MiNUTE, kullanici_olusturma_tarihi, GETDATE()) as dakika_once
            FROM kullanicilar
            WHERE kullanici_durum IS NULL
            AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
            ORDER BY kullanici_olusturma_tarihi DESC
        ");
        
        $zamanFormat = static function (int $dakika): string {
            if ($dakika < 60)   return $dakika . ' dakika önce';
            if ($dakika < 1440) return floor($dakika / 60) . ' saat önce';
            return floor($dakika / 1440) . ' gün önce';
        };

        $bildirimler = [];

        // Yeni kullanıcı başvuruları
        foreach ($basvurular as $basvuru) {
            $adSoyad = trim(($basvuru['kullanici_ad'] ?? '') . ' ' . ($basvuru['kullanici_soyad'] ?? ''));
            if (empty($adSoyad)) $adSoyad = $basvuru['kullanici_email'];
            $bildirimler[] = [
                'id'     => $basvuru['kullanici_id'],
                'tip'    => 'basvuru',
                'baslik' => 'Yeni Başvuru',
                'mesaj'  => $adSoyad . ' başvuruda bulundu',
                'zaman'  => $zamanFormat((int)$basvuru['dakika_once']),
                'link'   => '/Admin/pages/personel-yonetimi.php',
                'icon'   => 'bi-person-plus',
                'renk'   => 'warning',
            ];
        }

        // Destek (ticket) bildirimleri burada DEĞİL: merkezi destek API'sinden
        // /admin/api/destek-bildirim.php ile gelir (footer polling), yerel DB kullanılmaz.
        echo json_encode([
            'success' => true,
            'data'    => $bildirimler,
            'count'   => count($bildirimler),
        ]);
        exit;
    }
    
    if ($action === 'get_count') {
        $basvuruCount = $db->fetchOne("
            SELECT COUNT(*) AS sayi FROM kullanicilar
            WHERE kullanici_durum IS NULL
            AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
        ")['sayi'] ?? 0;

        echo json_encode([
            'success' => true,
            'count'   => $basvuruCount,
        ]);
        exit;
    }
    
    throw new Exception('Geçersiz işlem');
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
