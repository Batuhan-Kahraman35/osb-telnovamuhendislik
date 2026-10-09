<?php
/**
 * Merkezi Rapor Detay Sayfası
 * 
 * Tüm raporlardan gelen detay verilerini tek sayfada gösterir.
 * URL parametreleriyle filtreleme yapılır.
 * 
 * Desteklenen kaynaklar (kaynak):
 * - aylik-satis: Aylık satış raporu detayları
 * - personel-satis: Personel satış raporu detayları
 * - personel-tahsilat: Personel tahsilat raporu detayları
 * 
 * Desteklenen tipler (tip):
 * - satis: Satış tutarı detayı
 * - odeme_tipi: Ödeme tipine göre tahsilat detayı
 * - kalan: Kalan tutar detayı
 * - tahsilat: Genel tahsilat detayı
 * - odenecek_senet: Ödenecek senet detayı
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// URL Parametreleri
$kaynak = $_GET['kaynak'] ?? '';
$tip = $_GET['tip'] ?? '';
$yil = intval($_GET['yil'] ?? 0);
$ay = intval($_GET['ay'] ?? 0);
$odemeTipiId = $_GET['odeme_tipi_id'] ?? '';
$odemeTipiAd = $_GET['odeme_tipi'] ?? '';
$sezonId = $_GET['sezon_id'] ?? '';
$personelId = $_GET['personel_id'] ?? '';
$ulkeId = $_GET['ulke_id'] ?? '';
$sehirId = $_GET['sehir_id'] ?? '';

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Ay isimleri
$aylar = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
];

// Dinamik sayfa başlığı oluştur
function buildPageTitle($kaynak, $tip, $yil, $ay, $aylar, $odemeTipiAd, $personelId, $db, $ulkeId = '', $sehirId = '') {
    $baslik = 'Rapor Detayı';
    $altBaslik = '';
    
    // Kaynak bazlı başlık
    $kaynakAdlari = [
        'aylik-satis' => 'Aylık Satış Raporu',
        'personel-satis' => 'Personel Satış Raporu',
        'personel-tahsilat' => 'Personel Tahsilat Raporu',
        'aylik-tahsilat' => 'Aylık Tahsilat Raporu'
    ];
    
    // Tip bazlı başlık
    $tipAdlari = [
        'satis' => 'Satış Tutarı Detayı',
        'odeme_tipi' => ($odemeTipiAd ?: 'Ödeme Tipi') . ' Tahsilat Detayı',
        'kalan' => 'Kalan Tutar Detayı',
        'tahsilat' => 'Tahsilat Detayı',
        'odenecek_senet' => 'Ödenecek Senet Detayı',
        'icra' => 'İcra Detayı',
        'zaafiyet' => 'Zaafiyet Detayı'
    ];
    
    $baslik = $tipAdlari[$tip] ?? 'Detay';
    
    // Alt başlık oluştur
    $parcalar = [];
    if ($yil && $ay) {
        $parcalar[] = ($aylar[$ay] ?? $ay) . ' ' . $yil;
    } elseif ($yil) {
        $parcalar[] = $yil . ' Yılı';
    }
    
    if ($personelId) {
        $personel = $db->fetchOne("SELECT kullanici_ad + ' ' + kullanici_soyad as ad FROM kullanicilar WHERE kullanici_id = ?", [$personelId]);
        if ($personel) {
            $parcalar[] = $personel['ad'];
        }
    }
    
    if ($ulkeId) {
        $ulke = $db->fetchOne("SELECT UlkeAdi FROM Adres_Ulkeler WHERE UlkeId = ?", [$ulkeId]);
        if ($ulke) {
            $parcalar[] = $ulke['UlkeAdi'];
        }
    }
    if ($sehirId) {
        $sehir = $db->fetchOne("SELECT SehirAdi FROM Adres_Sehirler WHERE SehirId = ?", [$sehirId]);
        if ($sehir) {
            $parcalar[] = $sehir['SehirAdi'];
        }
    }
    
    $altBaslik = implode(' - ', $parcalar);
    
    return ['baslik' => $baslik, 'alt_baslik' => $altBaslik, 'kaynak_adi' => $kaynakAdlari[$kaynak] ?? ''];
}

$pageInfo = buildPageTitle($kaynak, $tip, $yil, $ay, $aylar, $odemeTipiAd, $personelId, $db, $ulkeId, $sehirId);
$pageTitle = $pageInfo['baslik'];
$pageAltBaslik = $pageInfo['alt_baslik'];
$kaynakAdi = $pageInfo['kaynak_adi'];

// Sezon listesi
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");

// Ödeme tipleri listesi
$odemeTipleri = $db->fetchAll("SELECT odeme_tipi_id, odeme_tipi_ad FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_durum = 1 ORDER BY odeme_tipi_ad");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $kaynak = $_POST['kaynak'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $yil = intval($_POST['yil'] ?? 0);
                $ay = intval($_POST['ay'] ?? 0);
                $odemeTipiId = $_POST['odeme_tipi_id'] ?? '';
                $sezonId = $_POST['sezon_id'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $ulkeId = $_POST['ulke_id'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                
                $whereClause = "WHERE 1=1";
                $params = [];
                
                if ($yil) {
                    $whereClause .= " AND YEAR(s.sozlesme_tarih) = ?";
                    $params[] = $yil;
                }
                if ($ay) {
                    $whereClause .= " AND MONTH(s.sozlesme_tarih) = ?";
                    $params[] = $ay;
                }
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                if ($personelId) {
                    $whereClause .= " AND s.sozlesme_personel_id = ?";
                    $params[] = $personelId;
                }
                if ($ulkeId) {
                    $whereClause .= " AND EXISTS (SELECT 1 FROM Cari cu WHERE cu.cari_id = s.sozlesme_cari_id AND cu.cari_ulke = ?)";
                    $params[] = $ulkeId;
                }
                if ($sehirId) {
                    $whereClause .= " AND EXISTS (SELECT 1 FROM Cari cu WHERE cu.cari_id = s.sozlesme_cari_id AND cu.cari_sehirler = ?)";
                    $params[] = $sehirId;
                }
                
                // Kayıt sayısı
                $kayitSayisi = $db->fetchOne("
                    SELECT COUNT(DISTINCT s.sozlesme_id) as sayi
                    FROM Sozlesmeler s
                    $whereClause
                ", $params);
                
                // Toplam satış
                $toplamSatis = $db->fetchOne("
                    SELECT ISNULL(SUM(h.hareket_fiyat), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                    $whereClause
                ", $params);
                
                // Toplam tahsilat
                $toplamTahsilat = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_yapildi = 1
                    $whereClause
                ", $params);
                
                // Ödenecek Senet toplamı
                $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
                $odenecekSenetParams = array_merge($params, [$senetTipiId]);
                $toplamOdenecekSenet = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_yapildi = 0 AND o.odeme_tipi_id = ?
                    $whereClause
                ", $odenecekSenetParams);
                
                // İcra toplamı (odeme_durum_id = 7)
                $toplamIcra = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_durum_id = 7
                    $whereClause
                ", $params);
                
                // Zaafiyet toplamı (odeme_durum_id = 8)
                $toplamZaafiyet = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_durum_id = 8
                    $whereClause
                ", $params);
                
                $satis = floatval($toplamSatis['toplam'] ?? 0);
                $tahsilat = floatval($toplamTahsilat['toplam'] ?? 0);
                $odenecekSenet = floatval($toplamOdenecekSenet['toplam'] ?? 0);
                $icra = floatval($toplamIcra['toplam'] ?? 0);
                $zaafiyet = floatval($toplamZaafiyet['toplam'] ?? 0);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'kayit_sayisi' => intval($kayitSayisi['sayi'] ?? 0),
                        'toplam_satis' => $satis,
                        'toplam_tahsilat' => $tahsilat,
                        'toplam_kalan' => $satis - $tahsilat - $odenecekSenet - $icra - $zaafiyet
                    ]
                ]);
                break;
                
            case 'list':
                $kaynak = $_POST['kaynak'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $yil = intval($_POST['yil'] ?? 0);
                $ay = intval($_POST['ay'] ?? 0);
                $odemeTipiId = $_POST['odeme_tipi_id'] ?? '';
                $sezonId = $_POST['sezon_id'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $ulkeId = $_POST['ulke_id'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                
                $whereClause = "WHERE 1=1";
                $params = [];
                
                if ($yil) {
                    $whereClause .= " AND YEAR(s.sozlesme_tarih) = ?";
                    $params[] = $yil;
                }
                if ($ay) {
                    $whereClause .= " AND MONTH(s.sozlesme_tarih) = ?";
                    $params[] = $ay;
                }
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                if ($personelId) {
                    $whereClause .= " AND s.sozlesme_personel_id = ?";
                    $params[] = $personelId;
                }
                if ($ulkeId) {
                    $whereClause .= " AND c.cari_ulke = ?";
                    $params[] = $ulkeId;
                }
                if ($sehirId) {
                    $whereClause .= " AND c.cari_sehirler = ?";
                    $params[] = $sehirId;
                }
                
                $data = [];
                
                if ($tip === 'satis') {
                    // Satış tutarı detayı
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            ISNULL(SUM(h.hareket_fiyat), 0) as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                        $whereClause
                        GROUP BY s.sozlesme_id, s.sozlesme_no, c.cari_adi, s.sozlesme_tarih, k.kullanici_ad, k.kullanici_soyad
                        HAVING ISNULL(SUM(h.hareket_fiyat), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'odeme_tipi' && $odemeTipiId !== '') {
                    // Ödeme tipine göre tahsilat detayı
                    $params[] = $odemeTipiId;
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            ot.odeme_tipi_ad,
                            ISNULL(SUM(o.odeme_tutar), 0) as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        INNER JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_tipi_id = ? AND o.odeme_yapildi = 1
                        GROUP BY s.sozlesme_id, s.sozlesme_no, c.cari_adi, s.sozlesme_tarih, k.kullanici_ad, k.kullanici_soyad, ot.odeme_tipi_ad
                        HAVING ISNULL(SUM(o.odeme_tutar), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'tahsilat') {
                    // Genel tahsilat detayı (tüm ödeme tipleri) - Ödeme bazında detay
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), o.odeme_tarih, 104) as odeme_tarih,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            o.odeme_id,
                            o.odeme_durum_id,
                            od.odeme_durum_ad,
                            od.odeme_durum_renk,
                            od.odeme_durum_icon,
                            ot.odeme_tipi_ad,
                            o.odeme_tutar as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Sozlesme_OdemeDurumlari od ON od.odeme_durum_id = o.odeme_durum_id
                        LEFT JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_yapildi = 1
                        ORDER BY o.odeme_tarih DESC, s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'kalan') {
                    // Kalan tutar detayı (satış - tahsilat - ödenecek senet - icra - zaafiyet)
                    $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                    $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
                    
                    $rawData = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            ISNULL(SUM(h.hareket_fiyat), 0) as satis_tutari,
                            ISNULL((
                                SELECT SUM(o2.odeme_tutar)
                                FROM Sozlesme_Odemeler o2
                                WHERE o2.odeme_sozlesme_id = s.sozlesme_id AND o2.odeme_yapildi = 1
                            ), 0) as tahsilat_tutari,
                            ISNULL((
                                SELECT SUM(o3.odeme_tutar)
                                FROM Sozlesme_Odemeler o3
                                WHERE o3.odeme_sozlesme_id = s.sozlesme_id AND o3.odeme_yapildi = 0 AND o3.odeme_tipi_id = ? AND ISNULL(o3.odeme_durum_id, 0) NOT IN (7, 8)
                            ), 0) as odenecek_senet_tutari,
                            ISNULL((
                                SELECT SUM(o4.odeme_tutar)
                                FROM Sozlesme_Odemeler o4
                                WHERE o4.odeme_sozlesme_id = s.sozlesme_id AND o4.odeme_durum_id = 7 AND o4.odeme_yapildi = 0
                            ), 0) as icra_tutari,
                            ISNULL((
                                SELECT SUM(o5.odeme_tutar)
                                FROM Sozlesme_Odemeler o5
                                WHERE o5.odeme_sozlesme_id = s.sozlesme_id AND o5.odeme_durum_id = 8 AND o5.odeme_yapildi = 0
                            ), 0) as zaafiyet_tutari
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                        $whereClause
                        GROUP BY s.sozlesme_id, s.sozlesme_no, c.cari_adi, s.sozlesme_tarih, k.kullanici_ad, k.kullanici_soyad
                        HAVING ISNULL(SUM(h.hareket_fiyat), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", array_merge([$senetTipiId], $params));
                    
                    // Kalan hesapla (sadece pozitif kalan göster, negatif = fazla tahsilat)
                    foreach ($rawData as $row) {
                        $kalan = floatval($row['satis_tutari']) - floatval($row['tahsilat_tutari']) - floatval($row['odenecek_senet_tutari']) - floatval($row['icra_tutari']) - floatval($row['zaafiyet_tutari']);
                        if ($kalan > 0) {
                            $row['tutar'] = $kalan;
                            $data[] = $row;
                        }
                    }
                    
                } elseif ($tip === 'odenecek_senet') {
                    // Ödenecek senet detayı
                    $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                    $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
                    
                    $params[] = $senetTipiId;
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            ISNULL(SUM(o.odeme_tutar), 0) as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        $whereClause AND o.odeme_tipi_id = ? AND o.odeme_yapildi = 0 AND ISNULL(o.odeme_durum_id, 0) NOT IN (7, 8)
                        GROUP BY s.sozlesme_id, s.sozlesme_no, c.cari_adi, s.sozlesme_tarih, k.kullanici_ad, k.kullanici_soyad
                        HAVING ISNULL(SUM(o.odeme_tutar), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'icra') {
                    // İcra detayı (odeme_durum_id = 7)
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), o.odeme_tarih, 104) as odeme_tarih,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            o.odeme_id,
                            od.odeme_durum_ad,
                            od.odeme_durum_renk,
                            od.odeme_durum_icon,
                            ot.odeme_tipi_ad,
                            o.odeme_tutar as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Sozlesme_OdemeDurumlari od ON od.odeme_durum_id = o.odeme_durum_id
                        LEFT JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_durum_id = 7
                        ORDER BY o.odeme_tarih DESC, s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'zaafiyet') {
                    // Zaafiyet detayı (odeme_durum_id = 8)
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), o.odeme_tarih, 104) as odeme_tarih,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            o.odeme_id,
                            od.odeme_durum_ad,
                            od.odeme_durum_renk,
                            od.odeme_durum_icon,
                            ot.odeme_tipi_ad,
                            o.odeme_tutar as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Sozlesme_OdemeDurumlari od ON od.odeme_durum_id = o.odeme_durum_id
                        LEFT JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_durum_id = 8
                        ORDER BY o.odeme_tarih DESC, s.sozlesme_tarih DESC
                    ", $params);
                }
                
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .clickable-cell { cursor: pointer; transition: background-color 0.2s; }
        .clickable-cell:hover { background-color: #e3f2fd !important; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <div class="app-content-header">
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-6">
                <h3 class="mb-0">
                    <?= htmlspecialchars($pageTitle) ?>
                    <?php if ($pageAltBaslik): ?>
                        <small class="text-muted fs-6">(<?= htmlspecialchars($pageAltBaslik) ?>)</small>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="/admin/pages/anasayfa.php">Ana Sayfa</a></li>
                    <?php if ($kaynakAdi): ?>
                        <li class="breadcrumb-item"><a href="javascript:history.back()"><?= htmlspecialchars($kaynakAdi) ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="app-content">
    <div class="container-fluid">
        
        <!-- Info Box'lar -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="info-box text-bg-primary">
                    <span class="info-box-icon"><i class="bi bi-file-text"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Kayıt Sayısı</span>
                        <span class="info-box-number" id="stat-kayit">0</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box text-bg-info">
                    <span class="info-box-icon"><i class="bi bi-cart-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Toplam Satış</span>
                        <span class="info-box-number" id="stat-satis">0 ₺</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box text-bg-success">
                    <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Toplam Tahsilat</span>
                        <span class="info-box-number" id="stat-tahsilat">0 ₺</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box text-bg-warning">
                    <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Kalan</span>
                        <span class="info-box-number" id="stat-kalan">0 ₺</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtre Kartı -->
        <div class="card card-primary card-outline mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-funnel"></i> Filtreler
                </h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </div>
            </div>
            <div class="card-body collapse" id="filterCard">
                <form id="filterForm">
                    <input type="hidden" id="filter_kaynak" name="kaynak" value="<?= htmlspecialchars($kaynak) ?>">
                    <input type="hidden" id="filter_tip" name="tip" value="<?= htmlspecialchars($tip) ?>">
                    <input type="hidden" id="filter_odeme_tipi_id" name="odeme_tipi_id" value="<?= htmlspecialchars($odemeTipiId) ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Yıl</label>
                            <select class="form-select" id="filter_yil" name="yil">
                                <option value="">Tümü</option>
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                    <option value="<?= $y ?>" <?= $y == $yil ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Ay</label>
                            <select class="form-select" id="filter_ay" name="ay">
                                <option value="">Tümü</option>
                                <?php foreach ($aylar as $ayNo => $ayAdi): ?>
                                    <option value="<?= $ayNo ?>" <?= $ayNo == $ay ? 'selected' : '' ?>><?= $ayAdi ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sezon</label>
                            <select class="form-select" id="filter_sezon" name="sezon_id">
                                <option value="">Tümü</option>
                                <?php foreach ($sezonlar as $sezon): ?>
                                    <option value="<?= $sezon['sezon_id'] ?>" <?= $sezon['sezon_id'] == $sezonId ? 'selected' : '' ?>><?= htmlspecialchars($sezon['sezon_ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Filtrele
                            </button>
                            <button type="button" class="btn btn-secondary me-2" id="clearFilters">
                                <i class="bi bi-x-circle"></i> Temizle
                            </button>
                            <button type="button" class="btn btn-success" id="exportExcel">
                                <i class="bi bi-file-earmark-excel"></i> Excel
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Ana Tablo -->
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-table"></i> <?= htmlspecialchars($pageTitle) ?>
                    <?php if ($pageAltBaslik): ?>
                        <small class="text-muted">(<?= htmlspecialchars($pageAltBaslik) ?>)</small>
                    <?php endif; ?>
                </h3>
                <div class="card-tools">
                    <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Geri
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover" id="dataTable">
                        <thead class="table-dark">
                            <?php if ($tip === 'tahsilat'): ?>
                            <tr>
                                <th>Sözleşme No</th>
                                <th>Cari</th>
                                <th>Personel</th>
                                <th>Ödeme Tarihi</th>
                                <th>Ödeme Tipi</th>
                                <th>Durum</th>
                                <th class="text-end">Tutar</th>
                                <th class="text-center">İşlem</th>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <th>Sözleşme No</th>
                                <th>Cari</th>
                                <th>Personel</th>
                                <th>Tarih</th>
                                <th class="text-end">Tutar</th>
                                <th class="text-center">İşlem</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody id="tableBody">
                            <tr>
                                <td colspan="<?= $tip === 'tahsilat' ? 8 : 6 ?>" class="text-center">Yükleniyor...</td>
                            </tr>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="<?= $tip === 'tahsilat' ? 6 : 4 ?>" class="text-end">Toplam:</th>
                                <th class="text-end" id="tableToplam">0 ₺</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
    // URL'den gelen filtreler
    let currentFilters = {
        kaynak: '<?= addslashes($kaynak) ?>',
        tip: '<?= addslashes($tip) ?>',
        yil: <?= $yil ?: 0 ?>,
        ay: <?= $ay ?: 0 ?>,
        odeme_tipi_id: '<?= addslashes($odemeTipiId) ?>',
        sezon_id: '<?= addslashes($sezonId) ?>',
        personel_id: '<?= addslashes($personelId) ?>',
        ulke_id: '<?= addslashes($ulkeId) ?>',
        sehir_id: '<?= addslashes($sehirId) ?>'
    };
    
    // Para formatla
    function formatMoney(val) {
        return parseFloat(val || 0).toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ₺';
    }
    
    // İstatistikleri yükle
    function loadStats() {
        $.post('', { action: 'stats', ...currentFilters }, function(response) {
            if (response.success) {
                $('#stat-kayit').text(response.data.kayit_sayisi + ' Adet');
                $('#stat-satis').text(formatMoney(response.data.toplam_satis));
                $('#stat-tahsilat').text(formatMoney(response.data.toplam_tahsilat));
                $('#stat-kalan').text(formatMoney(response.data.toplam_kalan));
            }
        });
    }
    
    // Listeyi yükle
    function loadList() {
        const tip = currentFilters.tip;
        
        $.post('', { action: 'list', ...currentFilters }, function(response) {
            if (!response.success) {
                const colspan = tip === 'tahsilat' ? 8 : 6;
                $('#tableBody').html('<tr><td colspan="' + colspan + '" class="text-center text-danger">Hata: ' + response.message + '</td></tr>');
                return;
            }
            
            const data = response.data;
            let html = '';
            let toplam = 0;
            const colspan = tip === 'tahsilat' ? 8 : 6;
            
            if (data.length === 0) {
                html = '<tr><td colspan="' + colspan + '" class="text-center">Kayıt bulunamadı</td></tr>';
            } else {
                data.forEach(row => {
                    const tutar = parseFloat(row.tutar || 0);
                    toplam += tutar;
                    const tutarClass = tutar < 0 ? 'text-danger' : (tutar > 0 ? 'text-success' : '');
                    
                    // Tahsilat detayı için ek kolonlar
                    if (tip === 'tahsilat') {
                        const durumRenk = row.odeme_durum_renk || '#6c757d';
                        const durumIcon = row.odeme_durum_icon || 'bi-question-circle';
                        const durumAd = row.odeme_durum_ad || '-';
                        const odemeTipi = row.odeme_tipi_ad || '-';
                        
                        html += `<tr>
                            <td><a href="/admin/pages/sozlesme-form.php?id=${row.sozlesme_id}" target="_blank">${row.sozlesme_no || '-'}</a></td>
                            <td>${row.cari_adi || '-'}</td>
                            <td>${row.personel_adi || '-'}</td>
                            <td>${row.odeme_tarih || row.sozlesme_tarih || '-'}</td>
                            <td>${odemeTipi}</td>
                            <td><span class="badge" style="background-color: ${durumRenk}"><i class="bi ${durumIcon} me-1"></i>${durumAd}</span></td>
                            <td class="text-end ${tutarClass}">${formatMoney(tutar)}</td>
                            <td class="text-center">
                                <a href="/admin/pages/sozlesme-form.php?id=${row.sozlesme_id}" class="btn btn-sm btn-outline-primary" target="_blank" title="Görüntüle">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>`;
                    } else {
                        // Diğer tipler için standart görünüm
                        html += `<tr>
                            <td><a href="/admin/pages/sozlesme-form.php?id=${row.sozlesme_id}" target="_blank">${row.sozlesme_no || '-'}</a></td>
                            <td>${row.cari_adi || '-'}</td>
                            <td>${row.personel_adi || '-'}</td>
                            <td>${row.sozlesme_tarih || '-'}</td>
                            <td class="text-end ${tutarClass}">${formatMoney(tutar)}</td>
                            <td class="text-center">
                                <a href="/admin/pages/sozlesme-form.php?id=${row.sozlesme_id}" class="btn btn-sm btn-outline-primary" target="_blank" title="Görüntüle">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>`;
                    }
                });
            }
            
            $('#tableBody').html(html);
            $('#tableToplam').text(formatMoney(toplam));
        }).fail(function() {
            const colspan = tip === 'tahsilat' ? 8 : 6;
            $('#tableBody').html('<tr><td colspan="' + colspan + '" class="text-center text-danger">Sunucu hatası</td></tr>');
        });
    }
    
    // Sayfa yüklendiğinde
    $(document).ready(function() {
        // Select2 başlat
        $('.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true
        });
        
        // Verileri yükle
        loadStats();
        loadList();
        
        // Filtre submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            currentFilters.yil = $('#filter_yil').val() || 0;
            currentFilters.ay = $('#filter_ay').val() || 0;
            currentFilters.sezon_id = $('#filter_sezon').val() || '';
            
            // URL'yi güncelle (history)
            const params = new URLSearchParams(window.location.search);
            if (currentFilters.yil) params.set('yil', currentFilters.yil); else params.delete('yil');
            if (currentFilters.ay) params.set('ay', currentFilters.ay); else params.delete('ay');
            if (currentFilters.sezon_id) params.set('sezon_id', currentFilters.sezon_id); else params.delete('sezon_id');
            
            history.pushState(null, '', '?' + params.toString());
            
            loadStats();
            loadList();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filter_yil').val('').trigger('change.select2');
            $('#filter_ay').val('').trigger('change.select2');
            $('#filter_sezon').val('').trigger('change.select2');
            
            currentFilters.yil = 0;
            currentFilters.ay = 0;
            currentFilters.sezon_id = '';
            
            loadStats();
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Excel export
        $('#exportExcel').on('click', function() {
            const tip = currentFilters.tip;
            let csv = '';
            
            // Tahsilat için farklı header
            if (tip === 'tahsilat') {
                csv = 'Sözleşme No,Cari,Personel,Ödeme Tarihi,Ödeme Tipi,Durum,Tutar\n';
            } else {
                csv = 'Sözleşme No,Cari,Personel,Tarih,Tutar\n';
            }
            
            $('#tableBody tr').each(function() {
                const cells = $(this).find('td');
                const expectedCols = tip === 'tahsilat' ? 8 : 6;
                if (cells.length >= expectedCols - 1) {
                    let row = [];
                    if (tip === 'tahsilat') {
                        row = [
                            $(cells[0]).text().trim(),
                            $(cells[1]).text().trim(),
                            $(cells[2]).text().trim(),
                            $(cells[3]).text().trim(),
                            $(cells[4]).text().trim(),
                            $(cells[5]).text().trim(),
                            $(cells[6]).text().trim().replace(/\./g, '').replace(' ₺', '').replace(',', '.')
                        ];
                    } else {
                        row = [
                            $(cells[0]).text().trim(),
                            $(cells[1]).text().trim(),
                            $(cells[2]).text().trim(),
                            $(cells[3]).text().trim(),
                            $(cells[4]).text().trim().replace(/\./g, '').replace(' ₺', '').replace(',', '.')
                        ];
                    }
                    csv += row.map(c => '"' + c.replace(/"/g, '""') + '"').join(',') + '\n';
                }
            });
            
            const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'rapor-detay-<?= date('Y-m-d') ?>.csv';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Excel dosyası indirildi', 'success');
        });
    });
</script>

</body>
</html>
