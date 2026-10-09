<?php
/**
 * Admin Panel - Teklif / Proforma Fatura Formu
 * Teklif ve Proforma Fatura ekleme/düzenleme sayfası
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü - teklif-yonetimi sayfası yetkilerini kullan
$currentPageFile = 'teklif-yonetimi.php';
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Düzenleme modu kontrolü
$editId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEdit = $editId > 0;
$teklif = null;
$kalemler = [];

// URL'den gelen cari_id (Cari formundan yönlendirme için)
$selectedCariId = isset($_GET['cari_id']) ? intval($_GET['cari_id']) : 0;
// URL'den gelen tip (1=Teklif, 2=Proforma)
$selectedTipId = isset($_GET['tip']) ? intval($_GET['tip']) : 1;

if ($isEdit) {
    if (!$pagePermissions['can_edit']) {
        PageAuth::accessDenied('Düzenleme yetkiniz bulunmamaktadır.');
    }

    $teklif = $db->fetchOne("
        SELECT 
            t.*,
            CAST(t.Teklifler_aciklama AS NVARCHAR(MAX)) as Teklifler_aciklama,
            CAST(t.Teklifler_notlar AS NVARCHAR(MAX)) as Teklifler_notlar,
            CAST(t.Teklifler_fatura_notu AS NVARCHAR(MAX)) as Teklifler_fatura_notu,
            CONVERT(VARCHAR(10), t.Teklifler_tarih, 120) as Teklifler_tarih_fmt,
            CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) as OlusturmaTarihi_fmt,
            CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) as GuncellemeTarihi_fmt,
            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
        FROM Teklifler t
        LEFT JOIN kullanicilar olusturan ON t.OlusturanKullanici = olusturan.kullanici_id
        LEFT JOIN kullanicilar guncelleyen ON t.GuncelleyenKullanici = guncelleyen.kullanici_id
        WHERE t.Teklifler_id = ? AND t.Durum = 1
    ", [$editId]);

    if (!$teklif) {
        header('Location: /Admin/teklif-yonetimi');
        exit;
    }

    $selectedCariId = $teklif['Teklifler_cari_id'];
    $selectedTipId = $teklif['Teklifler_tip_id'];

    // Teklif kalemlerini çek
    $kalemler = $db->fetchAll("
        SELECT 
            tk.*,
            uh.urun_hizmet_kodu,
            uh.urun_hizmet_adi,
            uh.urun_hizmet_birim,
            uh.urun_hizmet_gorsel_url,
            uh.urun_hizmet_satis_fiyati,
            uh.urun_hizmet_kdv_id,
            ISNULL(uh.Urun_Stok_Miktari, 0) as Stok_Miktari,
            k.kdv_adi,
            k.kdv_oran,
            pb.ParaBirimi_Kod,
            pb.ParaBirimi_Simge
        FROM Teklif_Kalemleri tk
        LEFT JOIN Urun_Hizmet uh ON tk.Teklif_Kalemleri_urun_hizmet_id = uh.urun_hizmet_id
        LEFT JOIN UrunHizmet_KDVTanimlari k ON tk.Teklif_Kalemleri_kdv_id = k.kdv_id
        LEFT JOIN tanim_ParaBirimi pb ON tk.Teklif_Kalemleri_para_birimi_id = pb.ParaBirimi_id
        WHERE tk.Teklif_Kalemleri_teklif_id = ? AND tk.Durum = 1
        ORDER BY tk.Teklif_Kalemleri_sira_no, tk.Teklif_Kalemleri_id
    ", [$editId]);
}

// Sayfa bilgilerini veritabanından çek
$pageInfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$menuAdi = $pageInfo['menu_adi'] ?? null;

if ($isEdit) {
    $pageTitle = 'Teklif Düzenle - ' . ($teklif['Teklifler_no'] ?? '');
} else {
    $pageTitle = 'Yeni Teklif / Proforma';
}

// Site title
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Dropdown verileri
$teklifTipleri = $db->fetchAll("SELECT * FROM Teklif_Tipleri WHERE Durum = 1 ORDER BY Teklif_Tipleri_sira_no");
$teklifDurumlari = $db->fetchAll("SELECT * FROM Teklif_Durumlari WHERE Durum = 1 ORDER BY Teklif_Durumlari_sira_no");
$paraBirimleri = $db->fetchAll("SELECT ParaBirimi_id, ParaBirimi_Kod, ParaBirimi_Ad, ParaBirimi_Simge FROM tanim_ParaBirimi WHERE Durum = 1 ORDER BY ParaBirimi_Kod");
$kdvTanimlari = $db->fetchAll("SELECT kdv_id, kdv_adi, kdv_oran FROM UrunHizmet_KDVTanimlari WHERE kdv_durum = 1 ORDER BY kdv_oran");
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");
$kullanicilar = $db->fetchAll("SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad as kullanici_adi FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad");
$selectedHazirlayanId = $teklif['Teklifler_hazirlayan_id'] ?? $user['kullanici_id'];

// Cari bilgisi (düzenleme veya yönlendirme)
$cariDetay = null;
$ilceler = [];
if ($selectedCariId > 0) {
    $cariDetay = $db->fetchOne("
        SELECT c.cari_id, c.cari_adi, c.cari_unvan,
               c.cari_telefon as cari_yetkili_telefon, c.cari_email as cari_yetkili_email,
               c.cari_sehirler, c.cari_ilceler,
               s.SehirAdi, i.IlceAdi
        FROM Cari c
        LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
        LEFT JOIN Adres_Ilceler i ON c.cari_ilceler = i.ilceId
        WHERE c.cari_id = ?
    ", [$selectedCariId]);
    if ($cariDetay && !empty($cariDetay['cari_sehirler'])) {
        $ilceler = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$cariDetay['cari_sehirler']]);
    }
}

/**
 * Teklif tipine göre sıradaki teklif numarasını üretir.
 * Sıra, kayıt sırasına (Teklifler_id) değil numaranın son bloğuna göre bulunur;
 * böylece elle girilen veya sonradan değiştirilen numaralar sırayı bozmaz.
 */
function teklifNoUret($db, $tipId) {
    $tipBilgi = $db->fetchOne(
        "SELECT Teklif_Tipleri_numara_oneki FROM Teklif_Tipleri WHERE Teklif_Tipleri_id = ?",
        [$tipId]
    );
    $onek = $tipBilgi['Teklif_Tipleri_numara_oneki'] ?? 'TKL';
    $govde = $onek . '-' . date('Y') . '-';

    $sonuc = $db->fetchOne("
        SELECT MAX(CAST(RIGHT(Teklifler_no, CHARINDEX('-', REVERSE(Teklifler_no)) - 1) AS INT)) AS max_sira
        FROM Teklifler WITH (UPDLOCK, HOLDLOCK)
        WHERE Teklifler_no LIKE ?
          AND ISNUMERIC(RIGHT(Teklifler_no, CHARINDEX('-', REVERSE(Teklifler_no)) - 1)) = 1
    ", [$govde . '%']);

    $sira = intval($sonuc['max_sira'] ?? 0) + 1;

    return $govde . str_pad($sira, 5, '0', STR_PAD_LEFT);
}

/**
 * Verilen teklif numarası başka bir kayıtta kullanılıyor mu?
 * $haricTeklifId, güncelleme sırasında kaydın kendisini dışarıda bırakmak içindir.
 */
function teklifNoKullanimda($db, $teklifNo, $haricTeklifId = 0) {
    $satir = $db->fetchOne(
        "SELECT TOP 1 Teklifler_id FROM Teklifler WITH (UPDLOCK, HOLDLOCK)
         WHERE Teklifler_no = ? AND Teklifler_id <> ?",
        [$teklifNo, intval($haricTeklifId)]
    );
    return !empty($satir);
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'cari_ara':
                $q = trim($_POST['q'] ?? '');
                if (strlen($q) < 2) {
                    echo json_encode(['results' => []]);
                    exit;
                }
                $cariler = $db->fetchAll("
                    SELECT TOP 20 
                        c.cari_id as id, 
                        CASE WHEN ISNULL(c.cari_unvan, '') = '' 
                             THEN c.cari_adi 
                             ELSE c.cari_adi + ' | ' + c.cari_unvan 
                        END as text,
                        c.cari_adi, c.cari_unvan,
                        c.cari_telefon as cari_yetkili_telefon, c.cari_email as cari_yetkili_email,
                        c.cari_vergi_dairesi, c.cari_vergi_no, c.cari_adres,
                        c.cari_sehirler, c.cari_ilceler,
                        s.SehirAdi, i.IlceAdi
                    FROM Cari c
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler i ON c.cari_ilceler = i.ilceId
                    WHERE c.cari_aktif = 1
                      AND (c.cari_adi LIKE ? OR c.cari_unvan LIKE ? OR c.cari_vergi_no LIKE ?)
                    ORDER BY c.cari_adi
                ", ["%$q%", "%$q%", "%$q%"]);
                echo json_encode(['results' => $cariler], JSON_UNESCAPED_UNICODE);
                exit;

            case 'cari_detay':
                $cariId = intval($_POST['cari_id'] ?? 0);
                $cari = $db->fetchOne("
                    SELECT c.cari_id, c.cari_adi, c.cari_unvan,
                           c.cari_telefon as cari_yetkili_telefon, c.cari_email as cari_yetkili_email,
                           c.cari_vergi_dairesi, c.cari_vergi_no, c.cari_adres,
                           c.cari_sehirler, c.cari_ilceler,
                           s.SehirAdi, i.IlceAdi
                    FROM Cari c
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler i ON c.cari_ilceler = i.ilceId
                    WHERE c.cari_id = ?
                ", [$cariId]);
                echo json_encode(['success' => true, 'data' => $cari], JSON_UNESCAPED_UNICODE);
                exit;

            case 'urun_ara':
                $q = trim($_POST['q'] ?? '');
                if (strlen($q) < 1) {
                    echo json_encode(['results' => []]);
                    exit;
                }
                $sadeceStoklu = intval($_POST['sadece_stoklu'] ?? 0) === 1;
                $stokFiltre = $sadeceStoklu ? ' AND ISNULL(uh.Urun_Stok_Miktari, 0) > 0' : '';
                $urunler = $db->fetchAll("
                    SELECT TOP 20
                        uh.urun_hizmet_id as id,
                        uh.urun_hizmet_kodu + ' - ' + uh.urun_hizmet_adi + ' (Stok: ' + CAST(CAST(ISNULL(uh.Urun_Stok_Miktari, 0) AS FLOAT) AS VARCHAR(50)) + ')' as text,
                        uh.urun_hizmet_kodu,
                        uh.urun_hizmet_adi,
                        uh.urun_hizmet_birim,
                        uh.urun_hizmet_satis_fiyati,
                        uh.urun_hizmet_gorsel_url,
                        uh.urun_hizmet_kdv_id,
                        uh.urun_hizmet_para_birimi_id,
                        ISNULL(uh.Urun_Stok_Miktari, 0) as Stok_Miktari,
                        k.kdv_adi, k.kdv_oran,
                        pb.ParaBirimi_Kod, pb.ParaBirimi_Simge
                    FROM Urun_Hizmet uh
                    LEFT JOIN UrunHizmet_KDVTanimlari k ON uh.urun_hizmet_kdv_id = k.kdv_id
                    LEFT JOIN tanim_ParaBirimi pb ON uh.urun_hizmet_para_birimi_id = pb.ParaBirimi_id
                    WHERE uh.urun_hizmet_durum = 1
                      AND (uh.urun_hizmet_kodu LIKE ? OR uh.urun_hizmet_adi LIKE ? OR uh.urun_hizmet_barkod LIKE ?)
                      $stokFiltre
                    ORDER BY uh.urun_hizmet_kodu
                ", ["%$q%", "%$q%", "%$q%"]);
                echo json_encode(['results' => $urunler], JSON_UNESCAPED_UNICODE);
                exit;

            case 'get_ilceler':
                $sehirId = intval($_POST['sehir_id'] ?? 0);
                $ilcelerData = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId]);
                echo json_encode(['success' => true, 'data' => $ilcelerData]);
                exit;

            case 'cari_kaydet':
                $yeniCariUnvan = trim($_POST['cari_unvan'] ?? '');
                $yeniCariYetkili = trim($_POST['cari_yetkili_adi'] ?? '');
                $yeniCariTelefon = trim($_POST['cari_yetkili_telefon'] ?? '');
                $yeniCariEmail = trim($_POST['cari_yetkili_email'] ?? '');
                $yeniCariSehir = intval($_POST['cari_sehir'] ?? 0);
                $yeniCariIlce = intval($_POST['cari_ilce'] ?? 0);

                if (empty($yeniCariUnvan)) {
                    echo json_encode(['success' => false, 'message' => 'Firma ünvan zorunludur.']);
                    exit;
                }
                $cariAdi = $yeniCariUnvan;

                $db->execute("
                    INSERT INTO Cari (cari_adi, cari_unvan, cari_telefon, cari_email,
                                      cari_sehirler, cari_ilceler, cari_aktif,
                                      cari_olusturma_tarihi, cari_olusturan_kullanici)
                    VALUES (?, ?, ?, ?, ?, ?, 1, GETDATE(), ?)
                ", [$cariAdi, $yeniCariUnvan, $yeniCariTelefon, $yeniCariEmail,
                    $yeniCariSehir, $yeniCariIlce, $_SESSION['kullanici_id']]);

                $yeniCari = $db->fetchOne("SELECT TOP 1 cari_id FROM Cari ORDER BY cari_id DESC");
                $yeniCariId = $yeniCari['cari_id'];

                // Şehir ve ilçe adlarını al
                $sehirAdi = '';
                $ilceAdi = '';
                if ($yeniCariSehir > 0) {
                    $s = $db->fetchOne("SELECT SehirAdi FROM Adres_Sehirler WHERE SehirId = ?", [$yeniCariSehir]);
                    $sehirAdi = $s['SehirAdi'] ?? '';
                }
                if ($yeniCariIlce > 0) {
                    $i = $db->fetchOne("SELECT IlceAdi FROM Adres_Ilceler WHERE ilceId = ?", [$yeniCariIlce]);
                    $ilceAdi = $i['IlceAdi'] ?? '';
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Cari başarıyla kaydedildi.',
                    'data' => [
                        'cari_id' => $yeniCariId,
                        'cari_adi' => $cariAdi,
                        'cari_unvan' => $yeniCariUnvan,
                        'cari_yetkili_adi' => $yeniCariYetkili,
                        'cari_yetkili_telefon' => $yeniCariTelefon,
                        'cari_yetkili_email' => $yeniCariEmail,
                        'SehirAdi' => $sehirAdi,
                        'IlceAdi' => $ilceAdi
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;

            case 'teklif_no_olustur':
                $tipId = intval($_POST['tip_id'] ?? 1);
                echo json_encode(['success' => true, 'teklif_no' => teklifNoUret($db, $tipId)]);
                exit;

            case 'kaydet':
                $teklifId = intval($_POST['teklif_id'] ?? 0);
                $cariId = intval($_POST['cari_id'] ?? 0);
                $tipId = intval($_POST['tip_id'] ?? 1);
                $teklifNo = trim($_POST['teklif_no'] ?? '');
                $tarih = trim($_POST['tarih'] ?? date('Y-m-d'));
                $gecerlilikSuresi = intval($_POST['gecerlilik_suresi'] ?? 30);
                $odemeKosulu = trim($_POST['odeme_kosulu'] ?? '');
                $teslimatSuresi = trim($_POST['teslimat_suresi'] ?? '');
                $teslimatYeri = trim($_POST['teslimat_yeri'] ?? '');
                $nakliyeDahil = isset($_POST['nakliye_dahil']) ? 1 : 0;
                $aciklama = trim($_POST['aciklama'] ?? '');
                $notlar = trim($_POST['notlar'] ?? '');
                $faturaNotu = trim($_POST['fatura_notu'] ?? '');
                $durumId = intval($_POST['durum_id'] ?? 1);
                $hazirlayanId = intval($_POST['hazirlayan_id'] ?? $user['kullanici_id']);

                // Validasyon
                if (!$cariId) throw new Exception('Cari seçimi zorunludur.');
                if (empty($teklifNo)) throw new Exception('Teklif numarası zorunludur.');
                if (empty($tarih)) throw new Exception('Tarih zorunludur.');

                // Kalemleri parse et
                $kalemlerData = json_decode($_POST['kalemler'] ?? '[]', true);
                if (empty($kalemlerData)) throw new Exception('En az bir kalem eklemelisiniz.');

                // Toplamları hesapla
                $toplamTutar = 0;
                $toplamKdv = 0;
                foreach ($kalemlerData as $kalem) {
                    $miktar = floatval($kalem['miktar'] ?? 0);
                    $birimFiyat = floatval($kalem['birim_fiyat'] ?? 0);
                    $kdvOran = floatval($kalem['kdv_oran'] ?? 0);
                    $satirToplam = $miktar * $birimFiyat;
                    $satirKdv = $satirToplam * ($kdvOran / 100);
                    $toplamTutar += $satirToplam;
                    $toplamKdv += $satirKdv;
                }
                $genelToplam = $toplamTutar + $toplamKdv;

                if ($teklifId > 0) {
                    // GÜNCELLEME
                    $eskiTeklif = $db->fetchOne("SELECT * FROM Teklifler WHERE Teklifler_id = ?", [$teklifId]);

                    if (teklifNoKullanimda($db, $teklifNo, $teklifId)) {
                        throw new Exception('“' . $teklifNo . '” numarası başka bir teklifte kullanılıyor. Lütfen farklı bir numara girin.');
                    }

                    $db->execute("
                        UPDATE Teklifler SET
                            Teklifler_tip_id = ?,
                            Teklifler_tarih = ?,
                            Teklifler_cari_id = ?,
                            Teklifler_no = ?,
                            Teklifler_toplam_tutar = ?,
                            Teklifler_toplam_kdv = ?,
                            Teklifler_genel_toplam = ?,
                            Teklifler_gecerlilik_suresi = ?,
                            Teklifler_odeme_kosulu = ?,
                            Teklifler_teslimat_suresi = ?,
                            Teklifler_teslimat_yeri = ?,
                            Teklifler_nakliye_dahil = ?,
                            Teklifler_aciklama = ?,
                            Teklifler_notlar = ?,
                            Teklifler_fatura_notu = ?,
                            Teklifler_durum_id = ?,
                            Teklifler_hazirlayan_id = ?,
                            GuncelleyenKullanici = ?,
                            GuncellemeTarihi = GETDATE()
                        WHERE Teklifler_id = ?
                    ", [
                        $tipId, $tarih, $cariId, $teklifNo,
                        $toplamTutar, $toplamKdv, $genelToplam,
                        $gecerlilikSuresi,
                        $odemeKosulu, $teslimatSuresi, $teslimatYeri, $nakliyeDahil,
                        $aciklama, $notlar, $faturaNotu, $durumId, $hazirlayanId,
                        $user['kullanici_id'], $teklifId
                    ]);

                    $yeniTeklif = $db->fetchOne("SELECT * FROM Teklifler WHERE Teklifler_id = ?", [$teklifId]);
                    logKayitDegisiklikleri($db, 'teklif-form', 'Teklifler', $teklifId, $eskiTeklif, $yeniTeklif, $user['kullanici_id'], 'Teklif güncellendi');

                } else {
                    // YENİ KAYIT
                    // Numara kayıt anında kesinleştirilir. Form açılışında üretilen numara
                    // bu arada başka bir kayda gitmiş olabilir (çift gönderim, eş zamanlı kayıt);
                    // bu durumda sıradaki boş numara alınır.
                    if (teklifNoKullanimda($db, $teklifNo)) {
                        $teklifNo = teklifNoUret($db, $tipId);
                    }

                    $yeniKayit = $db->fetchOne("
                        INSERT INTO Teklifler (
                            Teklifler_tip_id, Teklifler_tarih, Teklifler_cari_id, Teklifler_no,
                            Teklifler_toplam_tutar, Teklifler_toplam_kdv, Teklifler_genel_toplam,
                            Teklifler_gecerlilik_suresi,
                            Teklifler_odeme_kosulu, Teklifler_teslimat_suresi, Teklifler_teslimat_yeri,
                            Teklifler_nakliye_dahil, Teklifler_aciklama, Teklifler_notlar,
                            Teklifler_fatura_notu, Teklifler_durum_id,
                            Teklifler_hazirlayan_id, OlusturanKullanici, OlusturmaTarihi, Durum
                        )
                        OUTPUT INSERTED.Teklifler_id
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), 1)
                    ", [
                        $tipId, $tarih, $cariId, $teklifNo,
                        $toplamTutar, $toplamKdv, $genelToplam,
                        $gecerlilikSuresi,
                        $odemeKosulu, $teslimatSuresi, $teslimatYeri, $nakliyeDahil,
                        $aciklama, $notlar, $faturaNotu, $durumId, $hazirlayanId,
                        $user['kullanici_id']
                    ]);
                    if (empty($yeniKayit['Teklifler_id'])) {
                        throw new Exception('Teklif kaydedilemedi. Lütfen tekrar deneyin.');
                    }

                    $teklifId = intval($yeniKayit['Teklifler_id']);

                    $yeniTeklif = $db->fetchOne("SELECT * FROM Teklifler WHERE Teklifler_id = ?", [$teklifId]);
                    logKayitDegisiklikleri($db, 'teklif-form', 'Teklifler', $teklifId, null, $yeniTeklif, $user['kullanici_id'], 'Yeni teklif oluşturuldu');
                }

                // KALEMLERİ KAYDET
                // Düzenleme modunda: mevcut fiyatları sil öncesi al (backend güvenlik için)
                $mevcutKalemFiyatlari = [];
                if ($teklifId > 0) {
                    $mkRows = $db->fetchAll(
                        "SELECT Teklif_Kalemleri_urun_hizmet_id, Teklif_Kalemleri_birim_fiyat
                         FROM Teklif_Kalemleri WHERE Teklif_Kalemleri_teklif_id = ?",
                        [$teklifId]
                    );
                    foreach ($mkRows as $mk) {
                        $mevcutKalemFiyatlari[$mk['Teklif_Kalemleri_urun_hizmet_id']] =
                            floatval($mk['Teklif_Kalemleri_birim_fiyat']);
                    }
                }

                // Mevcut kalemleri sil
                $db->execute("DELETE FROM Teklif_Kalemleri WHERE Teklif_Kalemleri_teklif_id = ?", [$teklifId]);

                // Yeni kalemleri ekle
                $siraNo = 1;
                $kullanicidepartmanId = intval($user['departman_id'] ?? 0);
                foreach ($kalemlerData as $kalem) {
                    $miktar     = floatval($kalem['miktar'] ?? 1);
                    $birimFiyat = floatval($kalem['birim_fiyat'] ?? 0);
                    $kdvOran    = floatval($kalem['kdv_oran'] ?? 0);

                    // Departman 22 (Satış Müdürü) ve 1 dışında herkese %20 indirim sınırı
                    if (!in_array($kullanicidepartmanId, [1, 22])) {
                        $urunId = intval($kalem['urun_hizmet_id'] ?? 0);
                        if ($urunId > 0) {
                            $urunBilgi   = $db->fetchOne(
                                "SELECT urun_hizmet_satis_fiyati FROM Urun_Hizmet WHERE urun_hizmet_id = ?",
                                [$urunId]
                            );
                            $satisFiyati = floatval($urunBilgi['urun_hizmet_satis_fiyati'] ?? 0);
                            if ($satisFiyati > 0) {
                                $minFiyat = $satisFiyati * 0.80;
                                if ($birimFiyat < $minFiyat) {
                                    // Düzenleme modunda: D22 zaten düşük fiyat koymuşsa
                                    // kullanıcı o fiyatı ARTIRIYORSA veya AYNI bırakıyorsa → geçir
                                    $mevcutFiyat = $mevcutKalemFiyatlari[$urunId] ?? null;
                                    $izinli = ($mevcutFiyat !== null)
                                        && ($mevcutFiyat < $minFiyat)   // D22 zaten düşük koymuş
                                        && ($birimFiyat >= $mevcutFiyat); // daha da düşürülmüyor
                                    if (!$izinli) {
                                        throw new Exception(
                                            "Birim fiyat satış fiyatının %80'inden düşük olamaz. " .
                                            "Minimum: " . number_format($minFiyat, 2, ',', '.')
                                        );
                                    }
                                }
                            }
                        }
                    }

                    $satirToplam = $miktar * $birimFiyat;
                    $satirKdv = $satirToplam * ($kdvOran / 100);

                    $db->execute("
                        INSERT INTO Teklif_Kalemleri (
                            Teklif_Kalemleri_teklif_id, Teklif_Kalemleri_urun_hizmet_id,
                            Teklif_Kalemleri_miktar, Teklif_Kalemleri_birim,
                            Teklif_Kalemleri_birim_fiyat, Teklif_Kalemleri_para_birimi_id,
                            Teklif_Kalemleri_kdv_id, Teklif_Kalemleri_kdv_tutar,
                            Teklif_Kalemleri_toplam, Teklif_Kalemleri_sira_no,
                            OlusturanKullanici, OlusturmaTarihi, Durum
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), 1)
                    ", [
                        $teklifId,
                        intval($kalem['urun_hizmet_id']),
                        $miktar,
                        trim($kalem['birim'] ?? ''),
                        $birimFiyat,
                        intval($kalem['para_birimi_id'] ?? 0) ?: null,
                        intval($kalem['kdv_id'] ?? 0) ?: null,
                        $satirKdv,
                        $satirToplam,
                        $siraNo++,
                        $user['kullanici_id']
                    ]);
                }

                echo json_encode([
                    'success'   => true,
                    'message'   => 'Teklif başarıyla kaydedildi! Teklif No: ' . $teklifNo,
                    'id'        => $teklifId,
                    'teklif_no' => $teklifNo
                ], JSON_UNESCAPED_UNICODE);
                break;

            // ── PDF Gönder (WhatsApp / Mail) ──────────────────────────
            case 'pdf_gonder':
                $teklifId  = intval($_POST['teklif_id'] ?? 0);
                $kanal     = strtoupper(trim($_POST['kanal'] ?? ''));   // WA_BANA | MAIL_BANA | MAIL_MUSTERI

                if (!$teklifId) {
                    echo json_encode(['success' => false, 'message' => 'Teklif ID gerekli.']);
                    break;
                }

                // Teklif + hazırlayan bilgileri
                $t = $db->fetchOne("
                    SELECT t.Teklifler_id, t.Teklifler_no, t.Teklifler_hazirlayan_id,
                           c.cari_email, c.cari_adi, c.cari_unvan,
                           k.kullanici_ad + ' ' + k.kullanici_soyad AS hazirlayan_adi,
                           k.kullanici_telefon AS hazirlayan_telefon,
                           k.kullanici_email   AS hazirlayan_email,
                           tt.Teklif_Tipleri_ad
                    FROM Teklifler t
                    LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
                    LEFT JOIN kullanicilar k ON t.Teklifler_hazirlayan_id = k.kullanici_id
                    LEFT JOIN Teklif_Tipleri tt ON t.Teklifler_tip_id = tt.Teklif_Tipleri_id
                    WHERE t.Teklifler_id = ? AND t.Durum = 1
                ", [$teklifId]);

                if (!$t) {
                    echo json_encode(['success' => false, 'message' => 'Teklif bulunamadı.']);
                    break;
                }

                // PDF üret — teklif-pdf.php'yi binary mod ile doğrudan include et (cURL kullanmadan)
                $_GET['id'] = $teklifId;
                $GLOBALS['teklif_pdf_binary_mod'] = true;
                include __DIR__ . '/teklif-pdf.php';
                $pdfData   = $GLOBALS['teklif_pdf_output']    ?? null;
                $dosyaAdi  = $GLOBALS['teklif_pdf_dosya_adi'] ?? ($t['Teklifler_no'] . '.pdf');
                unset($GLOBALS['teklif_pdf_binary_mod'], $GLOBALS['teklif_pdf_output'], $GLOBALS['teklif_pdf_dosya_adi']);

                if (empty($pdfData)) {
                    echo json_encode(['success' => false, 'message' => 'PDF oluşturulamadı.']);
                    break;
                }
                $pdfPath = sys_get_temp_dir() . '/teklif_' . $teklifId . '_' . time() . '.pdf';
                file_put_contents($pdfPath, $pdfData);

                require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
                $helper = new EntegrasyonHelper($db);

                switch ($kanal) {
                    case 'WA_BANA':
                        $telefon = preg_replace('/\D/', '', $t['hazirlayan_telefon'] ?? '');
                        if (!$telefon) {
                            @unlink($pdfPath);
                            echo json_encode(['success' => false, 'message' => 'Hazırlayan kullanıcının telefon numarası tanımlı değil.']);
                            break 2;
                        }
                        $mesaj = "📄 *{$t['Teklifler_no']}* numaralı teklif/proforma PDF ektedir.\nHazırlayan: {$t['hazirlayan_adi']}";
                        // WhatsApp dosya gönderimi (sendMedia endpoint)
                        $kanal_row = $db->fetchOne("SELECT * FROM EntegrasyonKanallari WHERE Kanal_Id = 6");
                        if (!$kanal_row) {
                            @unlink($pdfPath);
                            echo json_encode(['success' => false, 'message' => 'WhatsApp kanalı (ID=6) bulunamadı.']);
                            break 2;
                        }
                        $params   = json_decode($kanal_row['Kanal_Parametreler'] ?? '{}', true);
                        $instance = $params['instance'] ?? 'default';
                        $baseUrl  = rtrim($kanal_row['Kanal_Endpoint'], '/');
                        $apiKey   = $kanal_row['Kanal_Sifre'];

                        // Base64'e çevir
                        $base64 = base64_encode($pdfData);
                        $payload = [
                            'number'   => $telefon,
                            'mediatype'=> 'document',
                            'mimetype' => 'application/pdf',
                            'caption'  => $mesaj,
                            'media'    => $base64,
                            'fileName' => $t['Teklifler_no'] . '.pdf',
                        ];
                        $ch2 = curl_init("{$baseUrl}/message/sendMedia/{$instance}");
                        curl_setopt_array($ch2, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => json_encode($payload),
                            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $apiKey],
                            CURLOPT_TIMEOUT        => 30,
                        ]);
                        $waResp   = curl_exec($ch2);
                        $waCode   = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                        curl_close($ch2);
                        @unlink($pdfPath);

                        // Log
                        $waRespArr = json_decode($waResp, true) ?? ['raw' => $waResp];
                        $db->insert('EntegrasyonLoglari', [
                            'Log_Kanal_Id'     => 6,
                            'Log_Islem'        => 'WA_PDF_GONDER',
                            'Log_Alici'        => $telefon,
                            'Log_Konu'         => $t['Teklifler_no'] . ' PDF',
                            'Log_Mesaj'        => $mesaj,
                            'Log_Istek'        => json_encode(['teklif_id' => $teklifId, 'telefon' => $telefon], JSON_UNESCAPED_UNICODE),
                            'Log_Yanit'        => json_encode($waRespArr, JSON_UNESCAPED_UNICODE),
                            'Log_Durum'        => ($waCode >= 200 && $waCode < 300) ? 'SUCCESS' : 'ERROR',
                            'Log_Sure_Ms'      => 0,
                            'Log_IP'           => $_SERVER['REMOTE_ADDR'] ?? null,
                            'Log_Kullanici_Id' => $user['kullanici_id'],
                        ]);

                        echo json_encode(($waCode >= 200 && $waCode < 300)
                            ? ['success' => true,  'message' => 'PDF WhatsApp ile gönderildi. (' . $t['hazirlayan_adi'] . ')']
                            : ['success' => false, 'message' => 'WhatsApp gönderilemedi. HTTP ' . $waCode]);
                        break;

                    case 'MAIL_BANA':
                        $email = $t['hazirlayan_email'] ?? '';
                        if (!$email) {
                            @unlink($pdfPath);
                            echo json_encode(['success' => false, 'message' => 'Hazırlayan kullanıcının e-posta adresi tanımlı değil.']);
                            break 2;
                        }
                        $konu  = $t['Teklifler_no'] . ' - ' . ($t['Teklif_Tipleri_ad'] ?? 'Teklif') . ' PDF';
                        $body  = "<p>Sayın <strong>{$t['hazirlayan_adi']}</strong>,</p>"
                               . "<p><strong>{$t['Teklifler_no']}</strong> numaralı teklife ait PDF ektedir.</p>";
                        $sonuc = $helper->mailGonder(
                            $email,
                            $konu,
                            $body,
                            [['path' => $pdfPath, 'name' => $t['Teklifler_no'] . '.pdf']]
                        );
                        @unlink($pdfPath);
                        echo json_encode($sonuc);
                        break;

                    case 'MAIL_MUSTERI':
                        $musteriEmail = $t['cari_email'] ?? '';
                        if (!$musteriEmail) {
                            @unlink($pdfPath);
                            echo json_encode(['success' => false, 'message' => 'Carinin e-posta adresi tanımlı değil.']);
                            break 2;
                        }

                        // CC: hazırlayan + sabit alıcı listesi
                        $ccHedefler = [
                            'kisi1@ornekyazilim.com',
                            'kisi2@ornekyazilim.com',
                            'satis@ornekfirma.com',
                        ];
                        $ccParam = implode(',', array_fill(0, count($ccHedefler), '?'));
                        $ccUsers = $db->fetchAll("
                            SELECT kullanici_email, kullanici_ad + ' ' + kullanici_soyad AS ad
                            FROM kullanicilar
                            WHERE kullanici_durum = 1
                              AND kullanici_email IS NOT NULL
                              AND kullanici_email != ''
                              AND (
                                  kullanici_id = ?
                                  OR kullanici_email IN ({$ccParam})
                              )
                        ", array_merge([$t['Teklifler_hazirlayan_id']], $ccHedefler));

                        $ccList = [];
                        foreach ($ccUsers as $cu) {
                            if (!empty($cu['kullanici_email'])) {
                                $ccList[$cu['kullanici_email']] = $cu['ad'];
                            }
                        }
                        // Ana alıcıyı CC'den çıkar
                        unset($ccList[$musteriEmail]);

                        $cariAdi = $t['cari_unvan'] ?: $t['cari_adi'];
                        $konu  = $t['Teklifler_no'] . ' - ' . ($t['Teklif_Tipleri_ad'] ?? 'Teklif');
                        $body  = "<p>Sayın <strong>" . htmlspecialchars($cariAdi) . "</strong>,</p>"
                               . "<p>Tarafınıza hazırlamış olduğumuz <strong>{$t['Teklifler_no']}</strong> numaralı teklif ektedir.</p>"
                               . "<p>Herhangi bir sorunuz olursa bizimle iletişime geçmekten çekinmeyiniz.</p>"
                               . "<p>Saygılarımızla,</p>";

                        // PHPMailer ile CC destekli gönderim
                        $mailKanal = $db->fetchOne("
                            SELECT ek.* FROM EntegrasyonKanallari ek
                            INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                            WHERE e.Entegrasyon_Kod = 'MAIL' AND ek.Kanal_Varsayilan = 1 AND ek.Kanal_Durum = 1
                        ");

                        if (!$mailKanal) {
                            @unlink($pdfPath);
                            echo json_encode(['success' => false, 'message' => 'Mail kanalı bulunamadı.']);
                            break 2;
                        }

                        $mp = json_decode($mailKanal['Kanal_Parametreler'] ?? '{}', true);
                        $port    = (int)($mp['port'] ?? 465);
                        $sifrele = strtoupper($mp['encryption'] ?? 'SSL');
                        $fromName = $mp['from_name'] ?? 'Örnek Mühendislik';

                        try {
                            $mail = new PHPMailer(true);
                            $mail->isSMTP();
                            $mail->Host       = $mailKanal['Kanal_Endpoint'];
                            $mail->SMTPAuth   = true;
                            $mail->Username   = $mailKanal['Kanal_Kullanici'];
                            $mail->Password   = $mailKanal['Kanal_Sifre'];
                            $mail->SMTPSecure = ($sifrele === 'TLS') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
                            $mail->Port       = $port;
                            $mail->CharSet    = 'UTF-8';
                            $mail->setFrom($mailKanal['Kanal_Kullanici'], $fromName);
                            $mail->addAddress($musteriEmail, $cariAdi);
                            foreach ($ccList as $ccMail => $ccAd) {
                                $mail->addCC($ccMail, $ccAd);
                            }
                            $mail->addAttachment($pdfPath, $t['Teklifler_no'] . '.pdf');
                            $mail->isHTML(true);
                            $mail->Subject = $konu;
                            $mail->Body    = $body;
                            $mail->AltBody = strip_tags($body);
                            $mail->send();

                            @unlink($pdfPath);

                            // Log
                            $db->insert('EntegrasyonLoglari', [
                                'Log_Kanal_Id'     => $mailKanal['Kanal_Id'],
                                'Log_Islem'        => 'MAIL_MUSTERI_PDF',
                                'Log_Alici'        => $musteriEmail,
                                'Log_Konu'         => $konu,
                                'Log_Mesaj'        => 'PDF müşteriye gönderildi.',
                                'Log_Istek'        => json_encode(['to' => $musteriEmail, 'cc' => array_keys($ccList)], JSON_UNESCAPED_UNICODE),
                                'Log_Yanit'        => json_encode(['status' => 'sent'], JSON_UNESCAPED_UNICODE),
                                'Log_Durum'        => 'SUCCESS',
                                'Log_Sure_Ms'      => 0,
                                'Log_IP'           => $_SERVER['REMOTE_ADDR'] ?? null,
                                'Log_Kullanici_Id' => $user['kullanici_id'],
                            ]);

                            $ccStr = implode(', ', array_keys($ccList));
                            echo json_encode(['success' => true, 'message' => "PDF {$musteriEmail} adresine gönderildi. CC: {$ccStr}"]);
                        } catch (PHPMailerException $pme) {
                            @unlink($pdfPath);
                            echo json_encode(['success' => false, 'message' => 'Mail hatası: ' . $pme->getMessage()]);
                        }
                        break;

                    default:
                        @unlink($pdfPath);
                        echo json_encode(['success' => false, 'message' => 'Bilinmeyen kanal: ' . $kanal]);
                }
                break;

            default:
                throw new Exception('Geçersiz işlem');
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        @keyframes spin { to { transform: rotate(360deg); } }
        .spin { display: inline-block; animation: spin 0.8s linear infinite; }
        .kalem-row .form-select, .kalem-row .form-control {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
        }
        .kalem-row td {
            padding: 0.25rem !important;
            vertical-align: middle;
        }
        .btn-remove-row {
            padding: 0.15rem 0.4rem;
        }
        #kalemlerTable {
            font-size: 0.85rem;
        }
        #kalemlerTable thead th {
            font-size: 0.8rem;
            white-space: nowrap;
        }
        #kalemlerTable th.miktar-col,
        #kalemlerTable td.miktar-col {
            width: 88px;
            min-width: 88px;
            max-width: 88px;
        }
        .kalem-row .kalem-miktar {
            width: 100%;
            min-width: 100%;
            max-width: 100%;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .urun-gorsel {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 4px;
            cursor: pointer;
        }
        .urun-gorsel-placeholder {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f0f0;
            border-radius: 4px;
            color: #aaa;
            font-size: 1.2rem;
        }
        .toplam-satir td {
            font-weight: 600;
            border-top: 2px solid #dee2e6 !important;
        }
        .proforma-fields { display: none; }
        .proforma-fields.show { display: block; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <!-- Sayfa Başlığı -->
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <?php endif; ?>
                                <li class="breadcrumb-item"><a href="/Admin/teklif-yonetimi">Teklif Yönetimi</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    <form id="teklifForm" novalidate>
                        <input type="hidden" name="teklif_id" id="teklif_id" value="<?= $editId ?>">

                        <div class="row">
                            <!-- Sol Kolon -->
                            <div class="col-lg-8">

                                <!-- Cari Bilgileri -->
                                <div class="card card-info card-outline mb-3">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h3 class="card-title"><i class="bi bi-person-vcard"></i> Cari Bilgileri</h3>
                                        <div class="d-flex gap-2">
                                            <?php if ($isEdit && $selectedCariId > 0): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnCariGuncelle" onclick="cariBilgileriniYenile()">
                                                <i class="bi bi-arrow-clockwise"></i> Güncelle
                                            </button>
                                            <a href="/Admin/cari-form?id=<?= $selectedCariId ?>" target="_blank" class="btn btn-sm btn-outline-warning" id="btnCariDuzenle">
                                                <i class="bi bi-pencil"></i> Cari Güncelle
                                            </a>
                                            <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" id="btnYeniCari" onclick="yeniCariModu()">
                                                <i class="bi bi-person-plus"></i> Yeni Cari Ekle
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <!-- Mevcut Cari Seçimi -->
                                            <div class="col-md-12" id="cariSelectWrapper">
                                                <label class="form-label">Cari Seçimi <span class="text-danger">*</span></label>
                                                <select class="form-select" id="cari_select" name="cari_id">
                                                    <?php if ($cariDetay): ?>
                                                    <option value="<?= $cariDetay['cari_id'] ?>" selected>
                                                        <?= htmlspecialchars($cariDetay['cari_adi'] . ' - ' . ($cariDetay['cari_unvan'] ?? '')) ?>
                                                    </option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            <!-- Yeni Cari Bilgi Notu -->
                                            <div class="col-md-12 d-none" id="yeniCariBilgi">
                                                <div class="alert alert-info mb-0 py-2 d-flex align-items-center">
                                                    <i class="bi bi-info-circle me-2"></i>
                                                    <span>Yeni cari kaydı oluşturuluyor. Bilgileri doldurup <strong>"Cariyi Kaydet"</strong> butonuna tıklayın.</span>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Firma Ünvan <span class="text-danger d-none yeni-cari-zorunlu">*</span></label>
                                                <input type="text" class="form-control" id="cari_unvan" readonly
                                                       value="<?= htmlspecialchars($cariDetay['cari_unvan'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Telefon</label>
                                                <input type="text" class="form-control" id="cari_yetkili_telefon" readonly
                                                       value="<?= htmlspecialchars($cariDetay['cari_yetkili_telefon'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">E-posta</label>
                                                <input type="text" class="form-control" id="cari_yetkili_email" readonly
                                                       value="<?= htmlspecialchars($cariDetay['cari_yetkili_email'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Durum</label>
                                                <div id="cari_ssa_durum" style="display:none">
                                                </div>
                                            </div>
                                            <!-- Mevcut cari için readonly il/ilçe -->
                                            <div class="col-md-3 cari-readonly-adres">
                                                <label class="form-label">İl</label>
                                                <input type="text" class="form-control" id="cari_sehir_adi" readonly
                                                       value="<?= htmlspecialchars($cariDetay['SehirAdi'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-3 cari-readonly-adres">
                                                <label class="form-label">İlçe</label>
                                                <input type="text" class="form-control" id="cari_ilce_adi" readonly
                                                       value="<?= htmlspecialchars($cariDetay['IlceAdi'] ?? '') ?>">
                                            </div>
                                            <!-- Yeni cari için select il/ilçe -->
                                            <div class="col-md-3 d-none cari-select-adres">
                                                <label class="form-label">İl</label>
                                                <select class="form-select" id="yeni_cari_sehir">
                                                    <option value="">Seçiniz</option>
                                                    <?php foreach ($sehirler as $s): ?>
                                                    <option value="<?= $s['SehirId'] ?>"><?= htmlspecialchars($s['SehirAdi']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 d-none cari-select-adres">
                                                <label class="form-label">İlçe</label>
                                                <select class="form-select" id="yeni_cari_ilce">
                                                    <option value="">Önce il seçin</option>
                                                </select>
                                            </div>
                                            <!-- Yeni Cari Kaydet / Vazgeç Butonları -->
                                            <div class="col-md-12 d-none" id="yeniCariButonlar">
                                                <div class="d-flex gap-2">
                                                    <button type="button" class="btn btn-success" onclick="yeniCariKaydet()">
                                                        <i class="bi bi-check-lg"></i> Cariyi Kaydet
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary" onclick="mevcutCariModu()">
                                                        <i class="bi bi-x-lg"></i> Vazgeç
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Teklif Kalemleri -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h3 class="card-title"><i class="bi bi-list-ol"></i> Teklif Kalemleri</h3>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch" id="sadeceStoklu">
                                                <label class="form-check-label" for="sadeceStoklu">Stoktakileri Göster</label>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-success" onclick="kalemEkle()">
                                                <i class="bi bi-plus-lg"></i> Kalem Ekle
                                            </button>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-hover mb-0" id="kalemlerTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="width:40px">#</th>
                                                        <th style="width:50px">Foto</th>
                                                        <th style="width:120px">Ürün Kodu</th>
                                                        <th style="min-width:200px">Ürün/Hizmet Adı <span class="text-danger">*</span></th>
                                                        <th class="miktar-col">Miktar</th>
                                                        <th style="width:80px">Birim</th>
                                                        <th style="width:120px">Birim Fiyat</th>
                                                        <th style="width:100px">Para Birimi</th>
                                                        <th style="width:100px">KDV</th>
                                                        <th style="width:100px">KDV Tutar</th>
                                                        <th style="width:120px">Toplam</th>
                                                        <th style="width:40px"></th>
                                                    </tr>
                                                </thead>
                                                <tbody id="kalemlerBody">
                                                    <!-- JS ile doldurulacak -->
                                                </tbody>
                                                <tfoot>
                                                    <tr class="toplam-satir">
                                                        <td colspan="9" class="text-end">Ara Toplam:</td>
                                                        <td id="footKdvToplam" class="text-end">0,00</td>
                                                        <td id="footAraToplam" class="text-end">0,00</td>
                                                        <td></td>
                                                    </tr>
                                                    <tr class="toplam-satir table-warning">
                                                        <td colspan="10" class="text-end fw-bold">Genel Toplam:</td>
                                                        <td id="footGenelToplam" class="text-end fw-bold fs-6">0,00</td>
                                                        <td></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Proforma Alanları -->
                                <div class="card card-warning card-outline mb-3 proforma-fields" id="proformaCard">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-truck"></i> Ödeme & Teslimat Bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Ödeme Koşulu</label>
                                                <input type="text" class="form-control" id="odeme_kosulu" name="odeme_kosulu"
                                                       placeholder="Örn: %50 Peşin, %50 Teslimat"
                                                       value="<?= htmlspecialchars($teklif['Teklifler_odeme_kosulu'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Teslimat Süresi</label>
                                                <input type="text" class="form-control" id="teslimat_suresi" name="teslimat_suresi"
                                                       placeholder="Örn: 15 iş günü"
                                                       value="<?= htmlspecialchars($teklif['Teklifler_teslimat_suresi'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Teslimat Yeri</label>
                                                <input type="text" class="form-control" id="teslimat_yeri" name="teslimat_yeri"
                                                       placeholder="Teslimat adresi"
                                                       value="<?= htmlspecialchars($teklif['Teklifler_teslimat_yeri'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">&nbsp;</label>
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" type="checkbox" id="nakliye_dahil" name="nakliye_dahil"
                                                           <?= ($teklif['Teklifler_nakliye_dahil'] ?? 0) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="nakliye_dahil">Nakliye Fiyata Dahil</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Açıklama & Notlar -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-card-text"></i> Açıklama & Notlar</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Açıklama</label>
                                                <textarea class="form-control" id="aciklama" name="aciklama" rows="3"
                                                          placeholder="Teklif açıklaması..."><?= htmlspecialchars($teklif['Teklifler_aciklama'] ?? '') ?></textarea>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">İç Not (Faturaya yansımaz)</label>
                                                <textarea class="form-control" id="notlar" name="notlar" rows="3"
                                                          placeholder="Özel şartlar, iç notlar..."><?= htmlspecialchars($teklif['Teklifler_notlar'] ?? '') ?></textarea>
                                                <div class="form-text">
                                                    <i class="bi bi-eye-slash text-secondary"></i>
                                                    Yalnızca teklif kaydında görünür; faturaya taşınmaz.
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Fatura Notları</label>
                                                <textarea class="form-control" id="fatura_notu" name="fatura_notu" rows="3"
                                                          placeholder="Faturaya yazılacak not..."><?= htmlspecialchars($teklif['Teklifler_fatura_notu'] ?? '') ?></textarea>
                                                <div class="form-text">
                                                    <i class="bi bi-info-circle text-info"></i>
                                                    Bu tekliften fatura kesildiğinde fatura notlarına, kur bilgisinin alt satırına otomatik eklenir.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <!-- Sağ Kolon -->
                            <div class="col-lg-4">

                                <!-- Teklif Bilgileri -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-file-earmark-text"></i> Teklif Bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Hazırlayan <span class="text-danger">*</span></label>
                                                <select class="form-select" id="hazirlayan_id" name="hazirlayan_id">
                                                    <?php foreach ($kullanicilar as $k): ?>
                                                    <option value="<?= $k['kullanici_id'] ?>"
                                                        <?= $selectedHazirlayanId == $k['kullanici_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($k['kullanici_adi']) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Teklif Tipi <span class="text-danger">*</span></label>
                                                <select class="form-select" id="tip_id" name="tip_id">
                                                    <?php foreach ($teklifTipleri as $tip): ?>
                                                    <option value="<?= $tip['Teklif_Tipleri_id'] ?>"
                                                        <?= ($teklif['Teklifler_tip_id'] ?? $selectedTipId) == $tip['Teklif_Tipleri_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($tip['Teklif_Tipleri_ad']) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Teklif No <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="teklif_no" name="teklif_no" readonly
                                                           value="<?= htmlspecialchars($teklif['Teklifler_no'] ?? '') ?>">
                                                    <?php if (!$isEdit): ?>
                                                    <button type="button" class="btn btn-outline-secondary" onclick="teklifNoOlustur()" title="Numara Oluştur">
                                                        <i class="bi bi-arrow-repeat"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Tarih <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" id="tarih" name="tarih"
                                                       value="<?= htmlspecialchars($teklif['Teklifler_tarih_fmt'] ?? date('Y-m-d')) ?>">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Geçerlilik Süresi (Gün)</label>
                                                <input type="number" class="form-control" id="gecerlilik_suresi" name="gecerlilik_suresi"
                                                       value="<?= intval($teklif['Teklifler_gecerlilik_suresi'] ?? 30) ?>" min="1">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Durum -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-flag"></i> Durum</h3>
                                    </div>
                                    <div class="card-body">
                                        <select class="form-select" id="durum_id" name="durum_id">
                                            <?php foreach ($teklifDurumlari as $durum): ?>
                                            <option value="<?= $durum['Teklif_Durumlari_id'] ?>"
                                                <?= ($teklif['Teklifler_durum_id'] ?? 1) == $durum['Teklif_Durumlari_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($durum['Teklif_Durumlari_ad']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <?php if ($isEdit && $teklif): ?>
                                <!-- Kayıt Bilgileri -->
                                <div class="card card-secondary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-clock-history"></i> Kayıt Bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <small class="text-muted">
                                            <?php if ($teklif['OlusturmaTarihi_fmt']): ?>
                                            <div class="mb-2">
                                                <strong>Oluşturulma:</strong><br>
                                                <?= $teklif['OlusturmaTarihi_fmt'] ?>
                                                <?php if ($teklif['olusturan_adi']): ?>
                                                    <br><span class="text-primary"><?= htmlspecialchars($teklif['olusturan_adi']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>

                                            <?php if ($teklif['GuncellemeTarihi_fmt']): ?>
                                            <div>
                                                <strong>Son Güncelleme:</strong><br>
                                                <?= $teklif['GuncellemeTarihi_fmt'] ?>
                                                <?php if ($teklif['guncelleyen_adi']): ?>
                                                    <br><span class="text-primary"><?= htmlspecialchars($teklif['guncelleyen_adi']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Özet -->
                                <div class="card card-success card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-calculator"></i> Teklif Özeti</h3>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm mb-0">
                                            <tr>
                                                <td>Ara Toplam:</td>
                                                <td class="text-end fw-semibold" id="ozetAraToplam">0,00</td>
                                            </tr>
                                            <tr>
                                                <td>KDV Toplam:</td>
                                                <td class="text-end fw-semibold" id="ozetKdvToplam">0,00</td>
                                            </tr>
                                            <tr class="table-success">
                                                <td class="fw-bold">Genel Toplam:</td>
                                                <td class="text-end fw-bold fs-5" id="ozetGenelToplam">0,00</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>

                                <!-- Butonlar -->
                                <div class="card card-primary card-outline">
                                    <div class="card-body">
                                        <div class="d-grid gap-2">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Güncelle' : 'Kaydet' ?>
                                            </button>
                                            <a href="/Admin/teklif-yonetimi" class="btn btn-secondary">
                                                <i class="bi bi-arrow-left"></i> Geri Dön
                                            </a>
                                            <?php if ($isEdit): ?>
                                            <a href="/admin/pages/teklif-pdf.php?id=<?= $editId ?>" class="btn btn-danger" target="_blank">
                                                <i class="bi bi-file-earmark-pdf"></i> PDF İndir
                                            </a>
                                            <a href="/admin/pages/degisiklik-log.php?tablo=Teklifler&kayit_id=<?= $editId ?>" class="btn btn-info" target="_blank">
                                                <i class="bi bi-clock-history"></i> Log Geçmişi
                                            </a>
                                            <hr class="my-1">
                                            <button type="button" class="btn btn-success" onclick="pdfGonder('WA_BANA')">
                                                <i class="bi bi-whatsapp"></i> WA ile Gönder
                                            </button>
                                            <button type="button" class="btn btn-warning" onclick="pdfGonder('MAIL_BANA')">
                                                <i class="bi bi-envelope"></i> Bana Mail At
                                            </button>
                                            <button type="button" class="btn btn-primary" onclick="pdfGonder('MAIL_MUSTERI')">
                                                <i class="bi bi-envelope-fill"></i> Müşteriye Mail At
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
    // PHP'den gelen veriler
    const kdvTanimlari = <?= json_encode($kdvTanimlari, JSON_UNESCAPED_UNICODE) ?>;
    const paraBirimleri = <?= json_encode($paraBirimleri, JSON_UNESCAPED_UNICODE) ?>;
    const mevcutKalemler = <?= json_encode($kalemler, JSON_UNESCAPED_UNICODE) ?>;
    const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
    const kullaniciDepartmanId = <?= intval($user['departman_id'] ?? 0) ?>;
    const SATIS_MUDURU_DEPARTMAN = 22; // Sadece bu departman %20'nin altına inebilir
    const MAX_INDIRIM_ORANI      = 0.20;
    let kalemSayac = 0;

    $(document).ready(function() {
        // Cari Select2 - AJAX arama
        $('#cari_select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Cari adı, ünvan veya vergi no ile arayın...',
            allowClear: true,
            minimumInputLength: 2,
            language: {
                noResults: function() { return "Sonuç bulunamadı"; },
                searching: function() { return "Aranıyor..."; },
                inputTooShort: function() { return "En az 2 karakter girin..."; }
            },
            ajax: {
                url: '',
                type: 'POST',
                dataType: 'json',
                delay: 300,
                data: function(params) {
                    return { action: 'cari_ara', q: params.term };
                },
                processResults: function(data) {
                    return { results: data.results };
                }
            }
        }).on('select2:select', function(e) {
            cariSecildi(e.params.data);
        }).on('select2:clear', function() {
            cariTemizle();
        });

        // Tip değişikliği
        $('#tip_id').on('change', function() {
            proformaAlanlariGoster();
            if (!isEdit) teklifNoOlustur();
        });

        // Form submit
        $('#teklifForm').on('submit', function(e) {
            e.preventDefault();
            kaydet();
        });

        // Hazırlayan Select2
        $('#hazirlayan_id').select2({
            theme: 'bootstrap-5',
            width: '100%',
            language: {
                noResults: function() { return "Sonuç bulunamadı"; }
            }
        });

        // Başlangıç
        proformaAlanlariGoster();

        // Mevcut kalemleri yükle (düzenleme modu)
        if (mevcutKalemler.length > 0) {
            mevcutKalemler.forEach(function(kalem) {
                kalemEkle(kalem);
            });
        }

        // Yeni teklif ise otomatik numara oluştur
        if (!isEdit && !$('#teklif_no').val()) {
            teklifNoOlustur();
        }

        toplamlariHesapla();
    });

    // Proforma alanlarını göster/gizle
    function proformaAlanlariGoster() {
        const tipId = $('#tip_id').val();
        if (tipId == 2) {
            $('#proformaCard').addClass('show');
        } else {
            $('#proformaCard').removeClass('show');
        }
    }

    // Cari seçildiğinde
    function cariSecildi(data) {
        $('#cari_unvan').val(data.cari_unvan || '');
        $('#cari_yetkili_telefon').val(data.cari_yetkili_telefon || '');
        $('#cari_yetkili_email').val(data.cari_yetkili_email || '');
        $('#cari_sehir_adi').val(data.SehirAdi || '');
        $('#cari_ilce_adi').val(data.IlceAdi || '');
    }

    // Cari temizlendiğinde
    function cariTemizle() {
        $('#cari_unvan, #cari_yetkili_telefon, #cari_yetkili_email, #cari_sehir_adi, #cari_ilce_adi').val('');
        $('#cari_ssa_durum').hide();
    }

    // Cari Güncelle butonuna basınca cari bilgilerini AJAX ile yenile
    function cariBilgileriniYenile() {
        const cariId = $('#cari_select').val();
        if (!cariId) return;

        const btn = $('#btnCariGuncelle');
        btn.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin"></i> Yükleniyor...');

        $.post(window.location.href, { action: 'cari_detay', cari_id: cariId }, function(res) {
            if (res.success && res.data) {
                cariSecildi(res.data);
                showToast('Cari bilgileri güncellendi', 'success');
            } else {
                showToast('Cari bilgileri alınamadı', 'error');
            }
        }, 'json').always(function() {
            btn.prop('disabled', false).html('<i class="bi bi-arrow-clockwise"></i> Güncelle');
        });
    }

    // Yeni cari modu - alanları düzenlenebilir yap
    function yeniCariModu() {
        // Select2'yi gizle
        $('#cariSelectWrapper').addClass('d-none');
        // Bilgi notunu göster
        $('#yeniCariBilgi').removeClass('d-none');
        // Buton metnini değiştir
        $('#btnYeniCari').addClass('d-none');
        // Zorunlu yıldızları göster
        $('.yeni-cari-zorunlu').removeClass('d-none');
        // Alanları düzenlenebilir yap
        $('#cari_unvan, #cari_yetkili_telefon, #cari_yetkili_email').removeAttr('readonly').val('');
        // Readonly adres gizle, select adres göster
        $('.cari-readonly-adres').addClass('d-none');
        $('.cari-select-adres').removeClass('d-none');
        // Select2 for İl
        $('#yeni_cari_sehir').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'İl Seçiniz', allowClear: true });
        $('#yeni_cari_ilce').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Önce il seçin', allowClear: true });
        // İl değiştiğinde ilçeleri yükle
        $('#yeni_cari_sehir').off('change').on('change', function() {
            const sehirId = $(this).val();
            $('#yeni_cari_ilce').html('<option value="">Yükleniyor...</option>').trigger('change');
            if (sehirId) {
                $.post('', { action: 'get_ilceler', sehir_id: sehirId }, function(res) {
                    let opts = '<option value="">Seçiniz</option>';
                    if (res.success && res.data) {
                        res.data.forEach(function(i) {
                            opts += `<option value="${i.ilceId}">${i.IlceAdi}</option>`;
                        });
                    }
                    $('#yeni_cari_ilce').html(opts).trigger('change');
                });
            } else {
                $('#yeni_cari_ilce').html('<option value="">Önce il seçin</option>').trigger('change');
            }
        });
        // Butonları göster
        $('#yeniCariButonlar').removeClass('d-none');
        // cari_select değerini temizle
        $('#cari_select').val(null).trigger('change');
    }

    // Mevcut cari moduna dön
    function mevcutCariModu() {
        // Select2'yi göster
        $('#cariSelectWrapper').removeClass('d-none');
        // Bilgi notunu gizle
        $('#yeniCariBilgi').addClass('d-none');
        // Butonu göster
        $('#btnYeniCari').removeClass('d-none');
        // Zorunlu yıldızları gizle
        $('.yeni-cari-zorunlu').addClass('d-none');
        // Alanları readonly yap ve temizle
        $('#cari_unvan, #cari_yetkili_telefon, #cari_yetkili_email').attr('readonly', true).val('');
        // Readonly adres göster, select adres gizle
        $('.cari-readonly-adres').removeClass('d-none');
        $('.cari-select-adres').addClass('d-none');
        $('#cari_sehir_adi, #cari_ilce_adi').val('');
        // Select2 destroy
        if ($('#yeni_cari_sehir').hasClass('select2-hidden-accessible')) {
            $('#yeni_cari_sehir').select2('destroy');
        }
        if ($('#yeni_cari_ilce').hasClass('select2-hidden-accessible')) {
            $('#yeni_cari_ilce').select2('destroy');
        }
        $('#yeni_cari_sehir').val('');
        $('#yeni_cari_ilce').html('<option value="">Önce il seçin</option>');
        // Butonları gizle
        $('#yeniCariButonlar').addClass('d-none');
    }

    // Yeni cari kaydet
    function yeniCariKaydet() {
        const unvan = $('#cari_unvan').val().trim();
        const telefon = $('#cari_yetkili_telefon').val().trim();
        const email = $('#cari_yetkili_email').val().trim();
        const sehir = $('#yeni_cari_sehir').val() || 0;
        const ilce = $('#yeni_cari_ilce').val() || 0;

        if (!unvan) { showError('Hata!', 'Firma ünvan zorunludur.'); return; }

        showLoading('Cari kaydediliyor...');

        $.post('', {
            action: 'cari_kaydet',
            cari_unvan: unvan,
            cari_yetkili_telefon: telefon,
            cari_yetkili_email: email,
            cari_sehir: sehir,
            cari_ilce: ilce
        }, function(res) {
            hideLoading();
            if (res.success) {
                showSuccess('Başarılı!', res.message);
                // Mevcut cari moduna geri dön
                mevcutCariModu();
                // Yeni cariyi Select2'ye ekle ve seç
                const newOption = new Option(
                    res.data.cari_adi + ' - ' + res.data.cari_unvan,
                    res.data.cari_id,
                    true, true
                );
                $('#cari_select').append(newOption).trigger('change');
                // Alanları doldur
                cariSecildi(res.data);
            } else {
                showError('Hata!', res.message);
            }
        }).fail(function() {
            hideLoading();
            showError('Hata!', 'Sunucuya bağlanılamadı.');
        });
    }

    // Teklif numarası oluştur
    function teklifNoOlustur() {
        const tipId = $('#tip_id').val();
        $.post('', { action: 'teklif_no_olustur', tip_id: tipId }, function(res) {
            if (res.success) {
                $('#teklif_no').val(res.teklif_no);
            }
        });
    }

    // Kalem ekle
    function kalemEkle(kalemData) {
        kalemSayac++;
        const idx = kalemSayac;
        const urunId = kalemData ? (kalemData.Teklif_Kalemleri_urun_hizmet_id || kalemData.urun_hizmet_id || '') : '';
        const urunKodu = kalemData ? (kalemData.urun_hizmet_kodu || '') : '';
        const urunAdi = kalemData ? (kalemData.urun_hizmet_adi || '') : '';
        const stokMiktari = kalemData ? parseFloat(kalemData.Stok_Miktari || 0) : 0;
        const miktarRaw = kalemData ? parseFloat(kalemData.Teklif_Kalemleri_miktar || kalemData.miktar || 1) : 1;
        const miktar = Number.isFinite(miktarRaw) ? Math.max(0, Math.round(miktarRaw)) : 1;
        const birim = kalemData ? (kalemData.Teklif_Kalemleri_birim || kalemData.urun_hizmet_birim || 'ADET') : '';
        const birimFiyat = kalemData ? parseFloat(kalemData.Teklif_Kalemleri_birim_fiyat || kalemData.birim_fiyat || 0) : 0;
        const kdvId = kalemData ? (kalemData.urun_hizmet_kdv_id || kalemData.Teklif_Kalemleri_kdv_id || '') : '';
        const paraBirimiId = kalemData ? (kalemData.Teklif_Kalemleri_para_birimi_id || kalemData.urun_hizmet_para_birimi_id || '') : '';
        const gorselUrl = kalemData ? (kalemData.urun_hizmet_gorsel_url || '') : '';

        // KDV seçenekleri
        let kdvOptions = '<option value="">Seçiniz</option>';
        kdvTanimlari.forEach(function(k) {
            const sel = (k.kdv_id == kdvId) ? ' selected' : '';
            kdvOptions += `<option value="${k.kdv_id}" data-oran="${k.kdv_oran}"${sel}>${k.kdv_adi}</option>`;
        });

        // Para birimi seçenekleri
        let pbOptions = '<option value="">-</option>';
        paraBirimleri.forEach(function(p) {
            const sel = (p.ParaBirimi_id == paraBirimiId) ? ' selected' : '';
            pbOptions += `<option value="${p.ParaBirimi_id}" data-simge="${p.ParaBirimi_Simge}"${sel}>${p.ParaBirimi_Kod}</option>`;
        });

        // Görsel
        let gorselHtml = '';
        if (gorselUrl) {
            gorselHtml = `<img src="${gorselUrl}" class="urun-gorsel" onclick="window.open(this.src,'_blank')" alt="">`;
        } else {
            gorselHtml = '<div class="urun-gorsel-placeholder"><i class="bi bi-image"></i></div>';
        }

        const row = `
        <tr class="kalem-row" data-idx="${idx}">
            <td class="text-center">${idx}</td>
            <td class="text-center gorsel-td">${gorselHtml}</td>
            <td><input type="text" class="form-control kalem-kod" value="${urunKodu}" readonly></td>
            <td>
                <select class="form-select urun-select" data-idx="${idx}">
                    ${urunId ? `<option value="${urunId}" selected>${urunKodu} - ${urunAdi} (Stok: ${stokMiktari})</option>` : '<option value="">Ürün Seçiniz...</option>'}
                </select>
                <input type="hidden" class="kalem-urun-id" value="${urunId}">
            </td>
            <td class="miktar-col">
                <input type="number" class="form-control kalem-miktar text-end" value="${miktar}" min="0" step="1" inputmode="numeric"
                       data-stok="${stokMiktari}"
                       onchange="miktarDuzenle(this); toplamlariHesapla(); stokGuncelle(this)">
                <div class="text-center stok-ind" style="font-size:0.68rem;margin-top:2px;">${stokMiktari > 0 ? '<span class="badge ' + (miktar <= stokMiktari ? 'bg-success' : 'bg-danger') + '" title="Stok: ' + stokMiktari + '">Stok: ' + stokMiktari + '</span>' : ''}</div>
            </td>
            <td><input type="text" class="form-control kalem-birim" value="${birim}"></td>
            <td><input type="number" class="form-control kalem-fiyat text-end"
                value="${birimFiyat.toFixed(2)}"
                min="0" step="0.01"
                data-satis-fiyati="${kalemData ? parseFloat(kalemData.urun_hizmet_satis_fiyati || 0) : 0}"
                data-initial-fiyat="${birimFiyat.toFixed(2)}"
                onchange="fiyatKontrol(this); toplamlariHesapla()"></td>
            <td><select class="form-select kalem-pb" onchange="toplamlariHesapla()">${pbOptions}</select></td>
            <td><select class="form-select kalem-kdv" disabled onchange="toplamlariHesapla()">${kdvOptions}</select></td>
            <td class="text-end kalem-kdv-tutar">0,00</td>
            <td class="text-end kalem-toplam fw-semibold">0,00</td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" onclick="kalemSil(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>`;

        $('#kalemlerBody').append(row);

        // Select2 ürün arama
        const $urunSelect = $(`.urun-select[data-idx="${idx}"]`);
        $urunSelect.select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Ürün arayın...',
            allowClear: true,
            minimumInputLength: 1,
            language: {
                noResults: function() { return "Sonuç bulunamadı"; },
                searching: function() { return "Aranıyor..."; },
                inputTooShort: function() { return "En az 1 karakter girin..."; }
            },
            ajax: {
                url: '',
                type: 'POST',
                dataType: 'json',
                delay: 300,
                data: function(params) {
                    return {
                        action: 'urun_ara',
                        q: params.term,
                        sadece_stoklu: $('#sadeceStoklu').is(':checked') ? 1 : 0
                    };
                },
                processResults: function(data) {
                    return { results: data.results };
                }
            }
        }).on('select2:select', function(e) {
            urunSecildi(idx, e.params.data);
        }).on('select2:clear', function() {
            urunTemizle(idx);
        });

        toplamlariHesapla();
    }

    // Stok göstergesini güncelle
    function stokGuncelle(input) {
        const stok = parseFloat($(input).attr('data-stok')) || 0;
        const miktar = parseInt($(input).val()) || 0;
        const $ind = $(input).siblings('.stok-ind');
        if (stok <= 0) { $ind.html(''); return; }
        const renk = miktar <= stok ? 'bg-success' : 'bg-danger';
        $ind.html('<span class="badge ' + renk + '" title="Stok: ' + stok + '">Stok: ' + stok + '</span>');
    }

    // Ürün seçildiğinde
    function urunSecildi(idx, data) {
        const $row = $(`.kalem-row[data-idx="${idx}"]`);
        $row.find('.kalem-urun-id').val(data.id);
        $row.find('.kalem-kod').val(data.urun_hizmet_kodu || '');
        $row.find('.kalem-birim').val(data.urun_hizmet_birim || 'ADET');
        $row.find('.kalem-fiyat')
            .val(parseFloat(data.urun_hizmet_satis_fiyati || 0).toFixed(2))
            .attr('data-satis-fiyati', parseFloat(data.urun_hizmet_satis_fiyati || 0));

        // KDV seç
        if (data.urun_hizmet_kdv_id) {
            $row.find('.kalem-kdv').val(data.urun_hizmet_kdv_id);
        }

        // Para birimi seç
        if (data.urun_hizmet_para_birimi_id) {
            $row.find('.kalem-pb').val(data.urun_hizmet_para_birimi_id);
        }

        // Görsel
        if (data.urun_hizmet_gorsel_url) {
            $row.find('.gorsel-td').html(`<img src="${data.urun_hizmet_gorsel_url}" class="urun-gorsel" onclick="window.open(this.src,'_blank')" alt="">`);
        } else {
            $row.find('.gorsel-td').html('<div class="urun-gorsel-placeholder"><i class="bi bi-image"></i></div>');
        }

        // Stok göstergesini güncelle
        const $miktarInput = $row.find('.kalem-miktar');
        $miktarInput.attr('data-stok', parseFloat(data.Stok_Miktari || 0));
        stokGuncelle($miktarInput[0]);

        toplamlariHesapla();
    }

    // Ürün temizlendiğinde
    function urunTemizle(idx) {
        const $row = $(`.kalem-row[data-idx="${idx}"]`);
        $row.find('.kalem-urun-id').val('');
        $row.find('.kalem-kod').val('');
        $row.find('.kalem-birim').val('');
        $row.find('.kalem-fiyat').val('0.00');
        $row.find('.kalem-kdv').val('');
        $row.find('.kalem-pb').val('');
        $row.find('.gorsel-td').html('<div class="urun-gorsel-placeholder"><i class="bi bi-image"></i></div>');
        toplamlariHesapla();
    }

    // Kalem sil
    function kalemSil(btn) {
        $(btn).closest('tr').remove();
        satirNumarala();
        toplamlariHesapla();
    }

    // Satır numaralarını yenile
    function satirNumarala() {
        $('#kalemlerBody .kalem-row').each(function(i) {
            $(this).find('td:first').text(i + 1);
        });
    }

    // Miktar alanı yalnızca tam sayı olacak şekilde normalize edilir
    function miktarDuzenle(input) {
        const hamDeger = String($(input).val() || '').replace(',', '.');
        const sayi = parseFloat(hamDeger);
        const tamSayi = Number.isFinite(sayi) ? Math.max(0, Math.round(sayi)) : 0;
        $(input).val(tamSayi);
    }

    // Departman 22 dışındakiler için birim fiyat kontrolü (maks. %20 indirim)
    function fiyatKontrol(input) {
        if (kullaniciDepartmanId === SATIS_MUDURU_DEPARTMAN) return; // D22 sınırsız
        const satisFiyati  = parseFloat($(input).attr('data-satis-fiyati')) || 0;
        if (satisFiyati <= 0) return;
        const minFiyat     = satisFiyati * (1 - MAX_INDIRIM_ORANI);  // %20 indirim tabanı
        const initialFiyat = parseFloat($(input).attr('data-initial-fiyat')) || 0;
        // D22 daha önce düşük fiyat koymuşsa, kullanıcı o değerin altına inemez
        const altSinir = (initialFiyat > 0 && initialFiyat < minFiyat) ? initialFiyat : minFiyat;
        const girilen  = parseFloat($(input).val()) || 0;
        if (girilen < altSinir) {
            const mesaj = initialFiyat < minFiyat
                ? 'Bu fiyatı daha da düşüremezsiniz. Minimum: ' + formatNumber(altSinir)
                : 'En fazla %' + (MAX_INDIRIM_ORANI * 100) + ' indirim yapabilirsiniz. Minimum: ' + formatNumber(minFiyat);
            showToast(mesaj, 'warning');
            $(input).val(altSinir.toFixed(2));
        }
    }

    // Toplamları hesapla
    function toplamlariHesapla() {
        let araToplam = 0;
        let kdvToplam = 0;

        $('#kalemlerBody .kalem-row').each(function() {
            const miktar = parseInt($(this).find('.kalem-miktar').val(), 10) || 0;
            const fiyat = parseFloat($(this).find('.kalem-fiyat').val()) || 0;
            const kdvOran = parseFloat($(this).find('.kalem-kdv option:selected').data('oran')) || 0;

            const satirToplam = miktar * fiyat;
            const satirKdv = satirToplam * (kdvOran / 100);

            $(this).find('.kalem-toplam').text(formatNumber(satirToplam));
            $(this).find('.kalem-kdv-tutar').text(formatNumber(satirKdv));

            araToplam += satirToplam;
            kdvToplam += satirKdv;
        });

        const genelToplam = araToplam + kdvToplam;

        $('#footAraToplam, #ozetAraToplam').text(formatNumber(araToplam));
        $('#footKdvToplam, #ozetKdvToplam').text(formatNumber(kdvToplam));
        $('#footGenelToplam, #ozetGenelToplam').text(formatNumber(genelToplam));
    }

    // Sayı formatla (Türkçe)
    function formatNumber(num) {
        return num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Kaydet
    // Kayıt devam ederken ikinci gönderimi engeller (mükerrer teklif kaydını önler)
    let kayitDevamEdiyor = false;

    function kaydet() {
        if (kayitDevamEdiyor) return;

        // Validasyon
        if ($('#yeniCariButonlar').is(':visible')) {
            showError('Hata!', 'Önce yeni cari kaydını tamamlayın veya vazgeçin.');
            return;
        }
        if (!$('#cari_select').val()) {
            showError('Hata!', 'Cari seçimi zorunludur.');
            return;
        }
        if (!$('#teklif_no').val()) {
            showError('Hata!', 'Teklif numarası zorunludur.');
            return;
        }

        // Kalemleri topla
        const kalemler = [];
        let hata = false;
        $('#kalemlerBody .kalem-row').each(function() {
            const urunId = $(this).find('.kalem-urun-id').val();
            if (!urunId) {
                hata = true;
                return false;
            }
            kalemler.push({
                urun_hizmet_id: urunId,
                miktar: $(this).find('.kalem-miktar').val(),
                birim: $(this).find('.kalem-birim').val(),
                birim_fiyat: $(this).find('.kalem-fiyat').val(),
                para_birimi_id: $(this).find('.kalem-pb').val(),
                kdv_id: $(this).find('.kalem-kdv').val(),
                kdv_oran: $(this).find('.kalem-kdv option:selected').data('oran') || 0
            });
        });

        if (hata || kalemler.length === 0) {
            showError('Hata!', 'En az bir ürün/hizmet kalemi eklemelisiniz.');
            return;
        }

        kayitDevamEdiyor = true;
        const $kaydetBtn = $('#teklifForm button[type="submit"]').prop('disabled', true);

        showLoading('Kaydediliyor...');

        const formData = new FormData($('#teklifForm')[0]);
        formData.append('action', 'kaydet');
        formData.append('kalemler', JSON.stringify(kalemler));

        $.ajax({
            url: '',
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                hideLoading();
                if (res.success) {
                    // Kilit açılmaz: yönlendirme tamamlanana kadar yeni kayıt gönderilmemeli
                    if (res.teklif_no) $('#teklif_no').val(res.teklif_no);
                    $('#teklif_id').val(res.id);
                    window.location.href = '/Admin/teklif-form?id=' + res.id;
                } else {
                    kayitDevamEdiyor = false;
                    $kaydetBtn.prop('disabled', false);
                    showError('Hata!', res.message);
                }
            },
            error: function() {
                hideLoading();
                kayitDevamEdiyor = false;
                $kaydetBtn.prop('disabled', false);
                showError('Hata!', 'Sunucuya bağlanılamadı.');
            }
        });
    }

    function pdfGonder(kanal) {
        const teklifId = <?= $editId ?>;
        const labelMap = {
            'WA_BANA'       : 'PDF hazırlayanın WhatsApp numarasına gönderilecek.',
            'MAIL_BANA'     : 'PDF hazırlayanın e-posta adresine gönderilecek.',
            'MAIL_MUSTERI'  : 'PDF müşterinin e-posta adresine gönderilecek (CC: hazırlayan + Kişi Bir, Kişi İki, Kişi Üç).',
        };
        Swal.fire({
            title: 'Onay',
            text: labelMap[kanal] ?? 'Gönderilsin mi?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Gönder',
            cancelButtonText: 'Vazgeç',
        }).then(result => {
            if (!result.isConfirmed) return;
            showLoading('Gönderiliyor...');
            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: { action: 'pdf_gonder', teklif_id: teklifId, kanal: kanal },
                dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Gönderildi', text: res.message, timer: 3000, showConfirmButton: false });
                    } else {
                        showError('Hata!', res.message);
                    }
                },
                error: function() {
                    hideLoading();
                    showError('Hata!', 'Sunucuya bağlanılamadı.');
                }
            });
        });
    }
    </script>
</body>
</html>
