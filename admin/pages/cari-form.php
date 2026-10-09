<?php
/**
 * Admin Panel - Cari Ekleme/Düzenleme Formu
 * 
 * Cari (Müşteri/Tedarikçi) ekleme ve düzenleme işlemleri
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
require_once __DIR__ . '/../includes/TurkbelgeAPI.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
requireAuth();

/**
 * Telefonu sadece rakam, başındaki 0 / 90 önekleri olmadan döner.
 * Desteklenen formatlar:
 *   "0232 123 45 67"       → 2321234567
 *   "+90 (232) 123-45-67"  → 2321234567
 *   "+90 (0232) 123 45 67" → 2321234567  (90 + yerel 0)
 *   "2321234567"            → 2321234567
 */
function formatTelefon(string $ham): string {
    $tel = preg_replace('/[^0-9]/', '', $ham);
    // Ülke kodu 90 varsa sil (sonraki adımda yerel 0 da kaldırılabilir)
    if (strlen($tel) > 10 && substr($tel, 0, 2) === '90') {
        $tel = substr($tel, 2);
    }
    // Yerel alan kodu başındaki 0 varsa sil
    if (strlen($tel) > 10 && $tel[0] === '0') {
        $tel = substr($tel, 1);
    }
    return $tel;
}

$user = Auth::user();
$db = Database::getInstance();

// id varsa düzenleme modu
$editMode = isset($_GET['id']) && intval($_GET['id']) > 0;
$cariid = $editMode ? intval($_GET['id']) : 0;
$cari = null;

// Mevcut cari bilgilerini çek
if ($editMode) {
    $cari = $db->fetchOne("
        SELECT 
            c.*,
            CONVERT(VARCHAR(19), c.cari_olusturma_tarihi, 120) as cari_olusturma_tarihi,
            CONVERT(VARCHAR(19), c.cari_guncelleme_tarihi, 120) as cari_guncelleme_tarihi,
            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
        FROM Cari c
        LEFT JOIN kullanicilar olusturan ON c.cari_olusturan_kullanici = olusturan.kullanici_id
        LEFT JOIN kullanicilar guncelleyen ON c.cari_guncelleyen_kullanici = guncelleyen.kullanici_id
        WHERE c.cari_id = ?
    ", [$cariid]);
    
    if (!$cari) {
        header('Location: /Admin/cari-yonetimi?error=notfound');
        exit;
    }
}

// Cari devri yalnızca yetkili departmanlar tarafından yapılabilir
$devirYetkisi = CariDevirHelper::devirYetkisiVarMi($user['departman_id'] ?? null);

// Devir bilgisi (düzenleme modunda gösterilir)
$devredilenAdi = null;
$devredenAdi   = null;
if ($editMode && !empty($cari['cari_devredilen_kullanici'])) {
    $devirBilgi = $db->fetchOne("
        SELECT
            d.kullanici_ad + ' ' + d.kullanici_soyad AS devredilen_adi,
            v.kullanici_ad + ' ' + v.kullanici_soyad AS devreden_adi
        FROM Cari c
        LEFT JOIN kullanicilar d ON c.cari_devredilen_kullanici = d.kullanici_id
        LEFT JOIN kullanicilar v ON c.cari_devreden_kullanici   = v.kullanici_id
        WHERE c.cari_id = ?
    ", [$cariid]);
    $devredilenAdi = $devirBilgi['devredilen_adi'] ?? null;
    $devredenAdi   = $devirBilgi['devreden_adi'] ?? null;
}

// Vergi No kilidi: cariye ait fatura (giden/gelen) varsa VKN değiştirilemez
$vknKilitli = false;
if ($editMode && !empty($cari['cari_vergi_no'])) {
    $vknKilitli = (bool)$db->fetchOne("
        SELECT TOP 1 1 v FROM (
            SELECT FaturalarGiden_VKN_TCKN vkn FROM dbo.FaturalarGiden WHERE Durum = 1
            UNION ALL
            SELECT FaturalarGelen_VKN_TCKN     FROM dbo.FaturalarGelen WHERE Durum = 1
        ) f WHERE f.vkn = ?
    ", [$cari['cari_vergi_no']]);
}

// Sayfa başliği
$pageTitle = $editMode ? 'Cari Düzenle' : 'Yeni Cari Ekle';

// Mevcut sayfanin bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT 
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%cari-yonetimi.php']);

$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Dropdown verileri
$ulkeler = $db->fetchAll("SELECT UlkeId, UlkeAdi FROM Adres_Ulkeler ORDER BY UlkeAdi");
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi, UlkeId FROM Adres_Sehirler ORDER BY SehirAdi");
$ilceler = $db->fetchAll("SELECT ilceId, SehirId, IlceAdi FROM Adres_Ilceler ORDER BY IlceAdi");
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad, cari_tipi_ikon, cari_tipi_renk FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");
$cariSektorler = $db->fetchAll("SELECT CariSektor_Id, CariSektor_Adi FROM Cari_Sektorler WHERE Durum = 1 ORDER BY CariSektor_Sira");
$cariBirimler = $db->fetchAll("SELECT CariBirim_Id, CariBirim_Adi FROM Cari_Birimler WHERE Durum = 1 ORDER BY CariBirim_Sira");
$vadeOdemeTipleri = $db->fetchAll("SELECT tanim_OdemeTipleri_Id, tanim_OdemeTipleri_Adi FROM dbo.tanim_OdemeTipleri WHERE Durum = 1 AND tanim_OdemeTipleri_CariVade = 1 ORDER BY tanim_OdemeTipleri_Sira");
$vadeGunleri = $db->fetchAll("SELECT CariVadeGun_Id, CariVadeGun_Adi FROM dbo.Cari_VadeGunleri WHERE Durum = 1 ORDER BY CariVadeGun_Sira");
$gorusmeTipleri = $db->fetchAll("SELECT GorusmeTipi_Id, GorusmeTipi_Adi, GorusmeTipi_Ikon, GorusmeTipi_Renk FROM Cari_GorusmeTipleri WHERE Durum = 1 ORDER BY GorusmeTipi_Sira");
$gorusmeDurumlari = $db->fetchAll("SELECT GorusmeDurum_Id, GorusmeDurum_Adi, GorusmeDurum_Renk FROM Cari_GorusmeDurumlari WHERE Durum = 1 ORDER BY GorusmeDurum_Sira");
$personeller = $db->fetchAll("
    SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad AS kullanici_adi
    FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad, kullanici_soyad
");

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'kaydet') {
            $id = intval($_POST['cari_id'] ?? 0);
            
            $data = [
                'cari_adi' => mb_strtoupper(trim($_POST['cari_adi'] ?? ''), 'UTF-8'),
                'cari_unvan' => mb_strtoupper(trim($_POST['cari_unvan'] ?? ''), 'UTF-8'),
                'cari_vergi_dairesi' => trim($_POST['cari_vergi_dairesi'] ?? ''),
                'cari_vergi_no' => trim($_POST['cari_vergi_no'] ?? ''),
                'cari_mersis_no' => trim($_POST['cari_mersis_no'] ?? ''),
                'cari_ticaret_sicil_no' => trim($_POST['cari_ticaret_sicil_no'] ?? ''),
                'cari_telefon' => formatTelefon($_POST['cari_telefon'] ?? ''),
                'cari_email' => trim($_POST['cari_email'] ?? ''),
                'cari_adres' => trim($_POST['cari_adres'] ?? ''),
                'cari_posta_kodu' => trim($_POST['cari_posta_kodu'] ?? ''),
                'cari_ulke' => !empty($_POST['cari_ulke']) ? intval($_POST['cari_ulke']) : null,
                'cari_sehirler' => !empty($_POST['cari_sehirler']) ? intval($_POST['cari_sehirler']) : null,
                'cari_ilceler' => !empty($_POST['cari_ilceler']) ? intval($_POST['cari_ilceler']) : null,
                'cari_tipi_id' => !empty($_POST['cari_tipi_id']) ? intval($_POST['cari_tipi_id']) : null,
                'cari_sektor_id' => !empty($_POST['cari_sektor_id']) ? intval($_POST['cari_sektor_id']) : null,
                'cari_vade_odeme_tipi_id' => !empty($_POST['cari_vade_odeme_tipi_id']) ? intval($_POST['cari_vade_odeme_tipi_id']) : null,
                'cari_vade_gun_id' => !empty($_POST['cari_vade_gun_id']) ? intval($_POST['cari_vade_gun_id']) : null,
                'cari_aktif' => isset($_POST['cari_aktif']) ? 1 : 0,
                'cari_SSAAktif' => isset($_POST['cari_SSAAktif']) ? 1 : 0,
                // E-Fatura alanları (VKN sorgulamasından doldurulacak)
                'cari_efatura_mukellef' => 0,
                'cari_efatura_etiket' => null,
                'cari_efatura_tip' => null,
                'cari_efatura_kayit_tarihi' => null,
                'cari_efatura_kontrol_tarihi' => null
            ];

            // Vergi No kilidi (güvenlik): güncelleme modunda cariye ait fatura varsa VKN değiştirilemez
            if ($id > 0) {
                $mevcutVkn = $db->fetchOne("SELECT cari_vergi_no FROM Cari WHERE cari_id = ?", [$id])['cari_vergi_no'] ?? null;
                if ($mevcutVkn) {
                    $faturaVar = $db->fetchOne("
                        SELECT TOP 1 1 v FROM (
                            SELECT FaturalarGiden_VKN_TCKN vkn FROM dbo.FaturalarGiden WHERE Durum = 1
                            UNION ALL
                            SELECT FaturalarGelen_VKN_TCKN     FROM dbo.FaturalarGelen WHERE Durum = 1
                        ) f WHERE f.vkn = ?
                    ", [$mevcutVkn]);
                    if ($faturaVar) {
                        $data['cari_vergi_no'] = $mevcutVkn; // fatura bağlı → VKN sabit
                    }
                }
            }

            // VKN'den e-fatura bilgilerini sorgula
            if (!empty($data['cari_vergi_no']) && preg_match('/^\d{10,11}$/', $data['cari_vergi_no'])) {
                try {
                    $api = new TurkbelgeAPI($db, 'PROD');
                    $efaturaInfo = $api->checkUser($data['cari_vergi_no']);
                    
                    if ($efaturaInfo && $efaturaInfo['efatura_mukellef']) {
                        $data['cari_efatura_mukellef'] = 1;
                        $data['cari_efatura_etiket'] = $efaturaInfo['etiket'];
                        $data['cari_efatura_tip'] = $efaturaInfo['tip'];
                        $data['cari_efatura_kayit_tarihi'] = !empty($efaturaInfo['kayit_tarihi']) 
                            ? date('Y-m-d H:i:s', strtotime($efaturaInfo['kayit_tarihi'])) 
                            : null;
                    }
                    $data['cari_efatura_kontrol_tarihi'] = date('Y-m-d H:i:s');
                } catch (Exception $e) {
                    // API hatası - e-fatura bilgisi olmadan devam et
                    error_log("E-Fatura sorgu hatası: " . $e->getMessage());
                }
            }
            
            // Validasyon
            if (empty($data['cari_adi'])) {
                throw new Exception('Cari adi zorunludur');
            }
            if (empty($data['cari_unvan'])) {
                throw new Exception('Ünvan zorunludur');
            }
            if (empty($data['cari_vergi_no'])) {
                throw new Exception('Vergi No zorunludur');
            }
            if (!preg_match('/^\d{10,11}$/', $data['cari_vergi_no'])) {
                throw new Exception('Vergi No 10 veya 11 haneli rakamdan oluşmalıdır');
            }
            if (empty($data['cari_ulke'])) {
                throw new Exception('Ülke seçimi zorunludur');
            }
            if (empty($data['cari_sehirler'])) {
                throw new Exception('Şehir seçimi zorunludur');
            }
            if (empty($data['cari_vade_odeme_tipi_id'])
                || !in_array($data['cari_vade_odeme_tipi_id'], array_column($vadeOdemeTipleri, 'tanim_OdemeTipleri_Id'))) {
                throw new Exception('Vade ödeme şekli seçimi zorunludur');
            }
            if (empty($data['cari_vade_gun_id'])
                || !in_array($data['cari_vade_gun_id'], array_column($vadeGunleri, 'CariVadeGun_Id'))) {
                throw new Exception('Vade günü seçimi zorunludur');
            }

            // Mükerrer vergi no kontrolü
            $mukerrerParams = [$data['cari_vergi_no']];
            $mukerrerSql = "SELECT cari_id, cari_adi FROM Cari WHERE cari_vergi_no = ?";
            if ($id > 0) {
                $mukerrerSql .= " AND cari_id <> ?";
                $mukerrerParams[] = $id;
            }
            $mukerrer = $db->fetchOne($mukerrerSql, $mukerrerParams);
            if ($mukerrer) {
                $msg = 'Bu Vergi No ile kayıtlı başka bir cari mevcut: ' . $mukerrer['cari_adi'];
                echo json_encode(['success' => false, 'cari_id' => $mukerrer['cari_id'], 'message' => $msg], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            if ($id > 0) {
                // GÜNCELLEME
                // Eski kaydı al (log için)
                $eskiCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$id]);

                // Cari devri (yalnızca yönetici); devreden ve tarih otomatik yazılır
                if ($devirYetkisi && array_key_exists('cari_devredilen_kullanici', $_POST)) {
                    $devredilen = intval($_POST['cari_devredilen_kullanici']);
                    CariDevirHelper::devret($id, $devredilen ?: null, (int)$user['kullanici_id']);
                }

                $data['cari_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                $data['cari_guncelleyen_kullanici'] = $user['kullanici_id'];
                
                $db->update('Cari', $data, ['cari_id' => $id]);
                
                // Yeni kaydı al ve log oluştur
                $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$id]);
                logKayitDegisiklikleri($db, 'cari-form', 'Cari', $id, $eskiCari, $yeniCari, $user['kullanici_id'], 'Cari güncellendi');
                
                echo json_encode(['success' => true, 'message' => 'Cari başarıyla güncellendi', 'id' => $id]);
            } else {
                // YENİ KAYIT
                $data['cari_olusturma_tarihi'] = date('Y-m-d H:i:s');
                $data['cari_olusturan_kullanici'] = $user['kullanici_id'];
                
                $newid = $db->insert('Cari', $data);
                
                // Yeni kaydı al ve log oluştur
                $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$newid]);
                logKayitDegisiklikleri($db, 'cari-form', 'Cari', $newid, null, $yeniCari, $user['kullanici_id'], 'Yeni cari oluşturuldu');
                
                echo json_encode(['success' => true, 'message' => 'Cari başarıyla eklendi', 'id' => $newid]);
            }
            exit;
        }
        
        if ($action === 'vergi_no_kontrol') {
            $vergiNo = trim($_POST['vergi_no'] ?? '');
            $cariId  = intval($_POST['cari_id'] ?? 0);
            
            if (empty($vergiNo)) { echo json_encode(['success' => true]); exit; }
            
            $params = [$vergiNo];
            $sql = "SELECT cari_id, cari_adi FROM Cari WHERE cari_vergi_no = ?";
            if ($cariId > 0) { $sql .= " AND cari_id <> ?"; $params[] = $cariId; }
            
            $mevcut = $db->fetchOne($sql, $params);
            if ($mevcut) {
                $msg = 'Bu Vergi No ile kayıtlı cari mevcut: ' . $mevcut['cari_adi'];
                echo json_encode(['success' => false, 'cari_id' => $mevcut['cari_id'], 'message' => $msg], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => true]);
            }
            exit;
        }
        
        // VKN'den E-Fatura Mükellef Bilgisi Sorgula (TÜRKBELGE API)
        if ($action === 'vkn_sorgula') {
            $vkn = trim($_POST['vkn'] ?? '');
            
            if (empty($vkn) || !preg_match('/^\d{10,11}$/', $vkn)) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz VKN formatı']);
                exit;
            }
            
            try {
                $api = new TurkbelgeAPI($db, 'PROD'); // PROD ortamı
                $sonuc = $api->checkUser($vkn);
                
                if ($sonuc && $sonuc['efatura_mukellef']) {
                    echo json_encode([
                        'success' => true,
                        'mukellef' => true,
                        'unvan' => $sonuc['unvan'],
                        'tip' => $sonuc['tip'],
                        'etiket' => $sonuc['etiket'],
                        'kayit_tarihi' => $sonuc['kayit_tarihi']
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode([
                        'success' => true,
                        'mukellef' => false,
                        'message' => 'Bu VKN için e-fatura mükellefiyeti bulunamadı'
                    ], JSON_UNESCAPED_UNICODE);
                }
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'API hatası: ' . $e->getMessage()
                ], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }
        
        if ($action === 'dosya_listele') {
            $cari_id = intval($_POST['cari_id'] ?? 0);
            if (!$cari_id) throw new Exception('Geçersiz cari ID');
            
            $dosyalar = $db->fetchAll("
                SELECT
                    dosya_id,
                    dosya_orijinal,
                    dosya_adi,
                    dosya_yol,
                    dosya_boyut,
                    dosya_tip,
                    dosya_aciklama,
                    CONVERT(VARCHAR(19), dosya_tarih, 120) as dosya_tarih,
                    k.kullanici_ad + ' ' + k.kullanici_soyad as yukleyen_adi
                FROM Cari_Dosyalar cd
                LEFT JOIN kullanicilar k ON cd.dosya_yukleyen = k.kullanici_id
                WHERE cd.cari_id = ?
                ORDER BY cd.dosya_tarih DESC
            ", [$cari_id]);
            
            echo json_encode(['success' => true, 'data' => $dosyalar]);
            exit;
        }
        
        if ($action === 'dosya_yukle') {
            $cari_id = intval($_POST['cari_id'] ?? 0);
            if (!$cari_id) throw new Exception('Önce cariyi kaydedin');
            
            if (empty($_FILES['dosyalar'])) throw new Exception('Dosya seçilmedi');
            
            $uploadDir = __DIR__ . '/../assets/uploads/cariler/' . $cari_id . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $izinliTipler = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain', 'text/csv',
                'application/zip', 'application/x-rar-compressed'
            ];
            $maxBoyut = 20 * 1024 * 1024; // 20 MB
            
            $yuklenenler = [];
            $files = $_FILES['dosyalar'];
            $count = is_array($files['name']) ? count($files['name']) : 1;
            
            for ($i = 0; $i < $count; $i++) {
                $name     = is_array($files['name'])  ? $files['name'][$i]     : $files['name'];
                $type     = is_array($files['type'])  ? $files['type'][$i]     : $files['type'];
                $tmp      = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $error    = is_array($files['error']) ? $files['error'][$i]    : $files['error'];
                $size     = is_array($files['size'])  ? $files['size'][$i]     : $files['size'];
                
                if ($error !== UPLOAD_ERR_OK) continue;
                if (!in_array($type, $izinliTipler)) throw new Exception(htmlspecialchars($name) . ' - Desteklenmeyen dosya türü');
                if ($size > $maxBoyut) throw new Exception(htmlspecialchars($name) . ' - Dosya boyutu 20 MB sınırını aşıyor');
                
                $ext      = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $yeniAd   = uniqid('cari_', true) . '.' . $ext;
                $hedef    = $uploadDir . $yeniAd;
                
                if (!move_uploaded_file($tmp, $hedef)) throw new Exception('Dosya yüklenemedi: ' . htmlspecialchars($name));
                
                $db->insert('Cari_Dosyalar', [
                    'cari_id'       => $cari_id,
                    'dosya_adi'     => $yeniAd,
                    'dosya_orijinal'=> $name,
                    'dosya_yol'     => '/Admin/assets/uploads/cariler/' . $cari_id . '/' . $yeniAd,
                    'dosya_boyut'   => $size,
                    'dosya_tip'     => $type,
                    'dosya_yukleyen'=> $user['kullanici_id'],
                    'dosya_tarih'   => date('Y-m-d H:i:s')
                ]);
                
                $yuklenenler[] = $name;
            }
            
            echo json_encode(['success' => true, 'message' => count($yuklenenler) . ' dosya yüklendi', 'dosyalar' => $yuklenenler]);
            exit;
        }
        
        if ($action === 'dosya_sil') {
            $dosya_id = intval($_POST['dosya_id'] ?? 0);
            if (!$dosya_id) throw new Exception('Geçersiz dosya ID');
            
            $dosya = $db->fetchOne("SELECT * FROM Cari_Dosyalar WHERE dosya_id = ?", [$dosya_id]);
            if (!$dosya) throw new Exception('Dosya bulunamadı');
            
            $fizikselYol = __DIR__ . '/../assets/uploads/cariler/' . $dosya['cari_id'] . '/' . $dosya['dosya_adi'];
            if (file_exists($fizikselYol)) @unlink($fizikselYol);
            
            $db->execute("DELETE FROM Cari_Dosyalar WHERE dosya_id = ?", [$dosya_id]);
            
            echo json_encode(['success' => true, 'message' => 'Dosya silindi']);
            exit;
        }
        
        // ========== YETKİLİ İŞLEMLERİ ==========
        if ($action === 'yetkili_listele') {
            $cari_id = intval($_POST['cari_id'] ?? 0);
            if (!$cari_id) throw new Exception('Geçersiz cari ID');
            
            $yetkililer = $db->fetchAll("
                SELECT
                    y.CariYetkili_Id,
                    y.CariYetkili_Adi,
                    y.CariYetkili_Soyadi,
                    y.CariYetkili_Email,
                    y.CariYetkili_Telefon,
                    y.CariYetkili_Telefon2,
                    y.CariYetkili_Ulke,
                    y.CariYetkili_Sehir,
                    y.CariYetkili_Ilce,
                    y.CariYetkili_Adres,
                    y.CariYetkili_Not,
                    y.CariYetkili_BirimId,
                    y.Durum,
                    b.CariBirim_Adi,
                    u.UlkeAdi,
                    s.SehirAdi,
                    i.IlceAdi
                FROM CariYetkili y
                LEFT JOIN Cari_Birimler b ON y.CariYetkili_BirimId = b.CariBirim_Id
                LEFT JOIN Adres_Ulkeler u ON y.CariYetkili_Ulke = u.UlkeId
                LEFT JOIN Adres_Sehirler s ON y.CariYetkili_Sehir = s.SehirId
                LEFT JOIN Adres_Ilceler i ON y.CariYetkili_Ilce = i.ilceId
                WHERE y.CariYetkili_CariId = ?
                ORDER BY y.CariYetkili_Adi
            ", [$cari_id]);
            
            echo json_encode(['success' => true, 'data' => $yetkililer]);
            exit;
        }
        
        if ($action === 'yetkili_getir') {
            $yetkili_id = intval($_POST['yetkili_id'] ?? 0);
            if (!$yetkili_id) throw new Exception('Geçersiz yetkili ID');
            
            $yetkili = $db->fetchOne("SELECT * FROM CariYetkili WHERE CariYetkili_Id = ?", [$yetkili_id]);
            if (!$yetkili) throw new Exception('Yetkili bulunamadı');
            
            echo json_encode(['success' => true, 'data' => $yetkili]);
            exit;
        }
        
        if ($action === 'yetkili_kaydet') {
            $cari_id = intval($_POST['cari_id'] ?? 0);
            if (!$cari_id) throw new Exception('Önce cariyi kaydedin');
            
            $yetkili_id = intval($_POST['yetkili_id'] ?? 0);
            $data = [
                'CariYetkili_CariId' => $cari_id,
                'CariYetkili_BirimId' => !empty($_POST['yetkili_birim']) ? intval($_POST['yetkili_birim']) : null,
                'CariYetkili_Adi' => trim($_POST['yetkili_adi'] ?? ''),
                'CariYetkili_Soyadi' => trim($_POST['yetkili_soyadi'] ?? ''),
                'CariYetkili_Email' => trim($_POST['yetkili_email'] ?? ''),
                'CariYetkili_Telefon' => formatTelefon($_POST['yetkili_telefon'] ?? ''),
                'CariYetkili_Telefon2' => formatTelefon($_POST['yetkili_telefon2'] ?? ''),
                'CariYetkili_Ulke' => !empty($_POST['yetkili_ulke']) ? intval($_POST['yetkili_ulke']) : null,
                'CariYetkili_Sehir' => !empty($_POST['yetkili_sehir']) ? intval($_POST['yetkili_sehir']) : null,
                'CariYetkili_Ilce' => !empty($_POST['yetkili_ilce']) ? intval($_POST['yetkili_ilce']) : null,
                'CariYetkili_Adres' => trim($_POST['yetkili_adres'] ?? ''),
                'CariYetkili_Not' => trim($_POST['yetkili_not'] ?? ''),
                'Durum' => intval($_POST['yetkili_durum'] ?? 1)
            ];
            
            if (empty($data['CariYetkili_Adi'])) throw new Exception('Yetkili adı zorunludur');
            
            if ($yetkili_id > 0) {
                $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                $data['GuncellemeTarihi'] = date('Y-m-d H:i:s');
                $db->update('CariYetkili', $data, ['CariYetkili_Id' => $yetkili_id]);
                echo json_encode(['success' => true, 'message' => 'Yetkili güncellendi']);
            } else {
                $data['OlusturanKullanici'] = $user['kullanici_id'];
                $data['OlusturmaTarihi'] = date('Y-m-d H:i:s');
                $db->insert('CariYetkili', $data);
                echo json_encode(['success' => true, 'message' => 'Yetkili eklendi']);
            }
            exit;
        }
        
        if ($action === 'yetkili_sil') {
            $yetkili_id = intval($_POST['yetkili_id'] ?? 0);
            if (!$yetkili_id) throw new Exception('Geçersiz yetkili ID');
            
            $db->execute("DELETE FROM CariYetkili WHERE CariYetkili_Id = ?", [$yetkili_id]);
            echo json_encode(['success' => true, 'message' => 'Yetkili silindi']);
            exit;
        }
        
        // ========== GÖRÜŞME NOTU İŞLEMLERİ ==========
        if ($action === 'gorusme_listele') {
            $cari_id = intval($_POST['cari_id'] ?? 0);
            if (!$cari_id) throw new Exception('Geçersiz cari ID');

            $notlar = $db->fetchAll("
                SELECT
                    g.GorusmeNotlari_Id,
                    g.GorusmeNotlari_TipId,
                    g.GorusmeNotlari_DurumId,
                    g.GorusmeNotlari_IletisimKisi,
                    g.GorusmeNotlari_Aciklama,
                    CONVERT(VARCHAR(16), g.GorusmeNotlari_GorusmeTarihi, 120) AS GorusmeTarihi,
                    CONVERT(VARCHAR(16), g.GorusmeNotlari_RandevuTarihi, 120)  AS RandevuTarihi,
                    t.GorusmeTipi_Adi,
                    t.GorusmeTipi_Ikon,
                    t.GorusmeTipi_Renk,
                    d.GorusmeDurum_Adi,
                    d.GorusmeDurum_Renk,
                    k.kullanici_ad + ' ' + k.kullanici_soyad AS KullaniciAdi
                FROM Cari_GorusmeNotlari g
                LEFT JOIN Cari_GorusmeTipleri   t ON g.GorusmeNotlari_TipId     = t.GorusmeTipi_Id
                LEFT JOIN Cari_GorusmeDurumlari d ON g.GorusmeNotlari_DurumId   = d.GorusmeDurum_Id
                LEFT JOIN kullanicilar          k ON g.GorusmeNotlari_KullaniciId = k.kullanici_id
                WHERE g.GorusmeNotlari_CariId = ? AND g.Durum = 1
                ORDER BY g.GorusmeNotlari_GorusmeTarihi DESC
            ", [$cari_id]);

            echo json_encode(['success' => true, 'data' => $notlar]);
            exit;
        }

        if ($action === 'gorusme_kaydet') {
            $cari_id = intval($_POST['cari_id'] ?? 0);
            if (!$cari_id) throw new Exception('Önce cariyi kaydedin');

            $not_id       = intval($_POST['not_id'] ?? 0);
            $aciklama     = trim($_POST['aciklama'] ?? '');
            $gorusmeTarihi = trim($_POST['gorusme_tarihi'] ?? '');
            $randevuTarihi = trim($_POST['randevu_tarihi'] ?? '');
            $tipId        = !empty($_POST['tip_id'])   ? intval($_POST['tip_id'])   : null;
            $durumId      = !empty($_POST['durum_id']) ? intval($_POST['durum_id']) : null;
            $iletisimKisi = trim($_POST['iletisim_kisi'] ?? '');

            if (empty($aciklama))      throw new Exception('Açıklama zorunludur');
            if (empty($gorusmeTarihi)) throw new Exception('Görüşme tarihi zorunludur');

            $data = [
                'GorusmeNotlari_CariId'        => $cari_id,
                'GorusmeNotlari_KullaniciId'   => $user['kullanici_id'],
                'GorusmeNotlari_TipId'         => $tipId,
                'GorusmeNotlari_DurumId'       => $durumId,
                'GorusmeNotlari_GorusmeTarihi' => $gorusmeTarihi,
                'GorusmeNotlari_RandevuTarihi' => !empty($randevuTarihi) ? $randevuTarihi : null,
                'GorusmeNotlari_IletisimKisi'  => !empty($iletisimKisi) ? $iletisimKisi : null,
                'GorusmeNotlari_Aciklama'      => $aciklama,
                'Durum'                        => 1
            ];

            if ($not_id > 0) {
                $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                $db->update('Cari_GorusmeNotlari', $data, ['GorusmeNotlari_Id' => $not_id]);
                echo json_encode(['success' => true, 'message' => 'Görüşme notu güncellendi']);
            } else {
                $data['OlusturanKullanici'] = $user['kullanici_id'];
                $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                $db->insert('Cari_GorusmeNotlari', $data);
                echo json_encode(['success' => true, 'message' => 'Görüşme notu eklendi']);
            }
            exit;
        }

        if ($action === 'gorusme_getir') {
            $not_id = intval($_POST['not_id'] ?? 0);
            if (!$not_id) throw new Exception('Geçersiz ID');

            $not = $db->fetchOne("
                SELECT
                    GorusmeNotlari_Id,
                    GorusmeNotlari_TipId,
                    GorusmeNotlari_DurumId,
                    GorusmeNotlari_IletisimKisi,
                    GorusmeNotlari_Aciklama,
                    CONVERT(VARCHAR(16), GorusmeNotlari_GorusmeTarihi, 120) AS GorusmeTarihi,
                    CONVERT(VARCHAR(16), GorusmeNotlari_RandevuTarihi, 120) AS RandevuTarihi
                FROM Cari_GorusmeNotlari
                WHERE GorusmeNotlari_Id = ? AND Durum = 1
            ", [$not_id]);

            if (!$not) throw new Exception('Kayıt bulunamadı');
            echo json_encode(['success' => true, 'data' => $not]);
            exit;
        }

        if ($action === 'gorusme_sil') {
            $not_id = intval($_POST['not_id'] ?? 0);
            if (!$not_id) throw new Exception('Geçersiz ID');

            $db->update('Cari_GorusmeNotlari',
                ['Durum' => 0, 'GuncelleyenKullanici' => $user['kullanici_id'], 'GuncellemeTarihi' => date('Y-m-d H:i:s')],
                ['GorusmeNotlari_Id' => $not_id]
            );
            echo json_encode(['success' => true, 'message' => 'Görüşme notu silindi']);
            exit;
        }

        throw new Exception('Geçersiz işlem');
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
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
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başliği -->
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
                                <li class="breadcrumb-item"><a href="/Admin/cari-yonetimi">Cari Yönetimi</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sayfa içeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <form id="cariForm" class="needs-validation" novalidate>
                        <input type="hidden" id="cari_id" name="cari_id" value="<?= $cariid ?>">
                        
                        <div class="row">
                            <!-- Sol Kolon - Ana bilgiler -->
                            <div class="col-lg-8">
                                
                                <!-- Genel bilgiler -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-info-circle"></i> Genel bilgiler</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="cari_vergi_no" class="form-label">Vergi No <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control<?= $vknKilitli ? ' bg-body-secondary' : '' ?>" id="cari_vergi_no" name="cari_vergi_no"
                                                       value="<?= htmlspecialchars($cari['cari_vergi_no'] ?? '') ?>"
                                                       inputmode="numeric" maxlength="11" required
                                                       <?= $vknKilitli ? 'readonly' : '' ?>
                                                       placeholder="10 veya 11 haneli vergi numarası">
                                                <div class="invalid-feedback" id="vergiNoFeedback">Vergi No zorunludur ve sadece rakam girilmelidir.</div>
                                                <div id="vergiNoUyari" class="mt-1" style="display:none"></div>
                                                <?php if ($vknKilitli): ?>
                                                <div class="form-text text-warning"><i class="bi bi-lock-fill"></i> Bu cariye ait fatura bulunduğu için Vergi No değiştirilemez.</div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_vergi_dairesi" class="form-label">Vergi Dairesi</label>
                                                <input type="text" class="form-control" id="cari_vergi_dairesi" name="cari_vergi_dairesi" 
                                                       value="<?= htmlspecialchars($cari['cari_vergi_dairesi'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_mersis_no" class="form-label">Mersis No</label>
                                                <input type="text" class="form-control" id="cari_mersis_no" name="cari_mersis_no" 
                                                       value="<?= htmlspecialchars($cari['cari_mersis_no'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_ticaret_sicil_no" class="form-label">Ticaret Sicil No</label>
                                                <input type="text" class="form-control" id="cari_ticaret_sicil_no" name="cari_ticaret_sicil_no" 
                                                       value="<?= htmlspecialchars($cari['cari_ticaret_sicil_no'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_adi" class="form-label">Cari Adı <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="cari_adi" name="cari_adi" 
                                                       value="<?= htmlspecialchars($cari['cari_adi'] ?? '') ?>" required>
                                                <div class="invalid-feedback">Cari adı zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_unvan" class="form-label">Ünvan <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="cari_unvan" name="cari_unvan" 
                                                       value="<?= htmlspecialchars($cari['cari_unvan'] ?? '') ?>" required>
                                                <div class="invalid-feedback">Ünvan zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_telefon" class="form-label">Telefon</label>
                                                <input type="tel" class="form-control" id="cari_telefon" name="cari_telefon" 
                                                       value="<?= htmlspecialchars($cari['cari_telefon'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_email" class="form-label">E-posta</label>
                                                <input type="email" class="form-control" id="cari_email" name="cari_email" 
                                                       value="<?= htmlspecialchars($cari['cari_email'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_ulke" class="form-label">Ülke <span class="text-danger">*</span></label>
                                                <select class="form-select" id="cari_ulke" name="cari_ulke" required>
                                                    <option value="">Ülke Seçiniz...</option>
                                                    <?php foreach ($ulkeler as $ulke): ?>
                                                        <option value="<?= $ulke['UlkeId'] ?>" <?= ($cari['cari_ulke'] ?? '') == $ulke['UlkeId'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ulke['UlkeAdi']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="invalid-feedback">Ülke seçimi zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_sehirler" class="form-label">Şehir <span class="text-danger">*</span></label>
                                                <select class="form-select" id="cari_sehirler" name="cari_sehirler" required>
                                                    <option value="">Önce Ülke Seçiniz...</option>
                                                </select>
                                                <div class="invalid-feedback">Şehir seçimi zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_ilceler" class="form-label">İlçe</label>
                                                <select class="form-select" id="cari_ilceler" name="cari_ilceler">
                                                    <option value="">İlçe Seçiniz...</option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_adres" class="form-label">Adres</label>
                                                <textarea class="form-control" id="cari_adres" name="cari_adres" rows="2"><?= htmlspecialchars($cari['cari_adres'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <input type="hidden" id="cari_posta_kodu" name="cari_posta_kodu" value="<?= htmlspecialchars($cari['cari_posta_kodu'] ?? '') ?>">

                                <!-- Yetkililer (Inline Satır Ekleme) -->
                                <div class="card card-primary card-outline mb-3" id="yetkiliKarti">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-person-badge"></i> Yetkililer</h3>
                                        <div class="card-tools">
                                            <span class="badge bg-primary" id="yetkiliSayisi">0</span>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered mb-0" id="yetkiliTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th width="130">Adı <span class="text-danger">*</span></th>
                                                        <th width="110">Soyadı</th>
                                                        <th width="120">Birim</th>
                                                        <th width="110">Telefon</th>
                                                        <th width="140">E-posta</th>
                                                        <th width="130">Şehir</th>
                                                        <th width="130">İlçe</th>
                                                        <th width="160">Adres</th>
                                                        <th width="70">Durum</th>
                                                        <th width="60">İşlem</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="yetkiliListesi"></tbody>
                                                <tfoot>
                                                    <tr class="table-warning" id="yetkiliEkleSatir">
                                                        <td><input type="text" class="form-control form-control-sm" id="yeni_yetkili_adi" placeholder="Adı"></td>
                                                        <td><input type="text" class="form-control form-control-sm" id="yeni_yetkili_soyadi" placeholder="Soyadı"></td>
                                                        <td>
                                                            <select class="form-select form-select-sm" id="yeni_yetkili_birim">
                                                                <option value="">Seçiniz</option>
                                                                <?php foreach ($cariBirimler as $birim): ?>
                                                                    <option value="<?= $birim['CariBirim_Id'] ?>"><?= htmlspecialchars($birim['CariBirim_Adi']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </td>
                                                        <td><input type="tel" class="form-control form-control-sm" id="yeni_yetkili_telefon" placeholder="Telefon"></td>
                                                        <td><input type="email" class="form-control form-control-sm" id="yeni_yetkili_email" placeholder="E-posta"></td>
                                                        <td>
                                                            <select class="form-select form-select-sm" id="yeni_yetkili_sehir">
                                                                <option value="">Şehir</option>
                                                                <?php foreach ($sehirler as $s): ?>
                                                                    <option value="<?= $s['SehirId'] ?>"><?= htmlspecialchars($s['SehirAdi']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <select class="form-select form-select-sm" id="yeni_yetkili_ilce">
                                                                <option value="">İlçe</option>
                                                            </select>
                                                        </td>
                                                        <td><input type="text" class="form-control form-control-sm" id="yeni_yetkili_adres" placeholder="Adres"></td>
                                                        <td class="text-center">
                                                            <div class="form-check form-switch d-flex justify-content-center">
                                                                <input class="form-check-input" type="checkbox" id="yeni_yetkili_durum" checked>
                                                            </div>
                                                        </td>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-sm btn-success" onclick="yetkiliSatirEkle()" title="Ekle">
                                                                <i class="bi bi-plus-lg"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dosya Yükleme -->
                                <div class="card card-primary card-outline mb-3" id="dosyaKarti" <?= !$editMode ? 'style="display:none"' : '' ?>>
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-paperclip"></i> Dosyalar</h3>
                                        <div class="card-tools">
                                            <span class="badge bg-primary" id="dosyaSayisi">0</span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <!-- Drop Zone -->
                                        <div id="dropZone" class="border border-2 border-dashed rounded-3 p-4 text-center mb-3"
                                             style="border-color:#0d6efd!important; background:#f8f9ff; cursor:pointer; transition:background .2s;">
                                            <i class="bi bi-cloud-upload fs-2 text-primary"></i>
                                            <p class="mb-1 fw-semibold">Dosyaları buraya sürükleyin</p>
                                            <p class="text-muted small mb-2">veya tıklayarak seçin</p>
                                            <p class="text-muted" style="font-size:11px">JPG, PNG, PDF, Word, Excel, ZIP — Maks. 20 MB/dosya</p>
                                            <input type="file" id="dosyaInput" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip,.rar" style="display:none">
                                        </div>

                                        <!-- Yükleme Progress -->
                                        <div id="uploadProgress" class="d-none mb-3">
                                            <div class="progress">
                                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="progressBar" style="width:0%"></div>
                                            </div>
                                            <small class="text-muted" id="progressText">Yükleniyor...</small>
                                        </div>

                                        <!-- Dosya Listesi -->
                                        <div id="dosyaListesi"></div>
                                    </div>
                                </div>

                                <?php if (!$editMode): ?>
                                <div class="alert alert-info mb-3">
                                    <i class="bi bi-info-circle"></i> Dosya yüklemek için önce <strong>cariyi kaydedin</strong>.
                                </div>
                                <?php endif; ?>

                <!-- Görüşme Notları -->
                <div class="card card-primary card-outline mb-3" id="gorusmeKarti" <?= !$editMode ? 'style="display:none"' : '' ?>>
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-chat-text"></i> Görüşme Notları</h3>
                        <div class="card-tools">
                            <span class="badge bg-primary me-2" id="gorusmeSayisi">0</span>
                            <button type="button" class="btn btn-sm btn-success" onclick="gorusmeModalAc()">
                                <i class="bi bi-plus-lg"></i> Yeni Görüşme
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="gorusmeListesi">
                            <p class="text-muted text-center small p-3 mb-0"><i class="bi bi-inbox"></i> Henüz görüşme notu eklenmedi</p>
                        </div>
                    </div>
                </div>

                <?php if (!$editMode): ?>
                <div class="alert alert-secondary mb-3">
                    <i class="bi bi-chat-text"></i> Görüşme notu eklemek için önce <strong>cariyi kaydedin</strong>.
                </div>
                <?php endif; ?>
                                
                            </div>
                            <!-- Sağ Kolon - Tip ve Durum -->
                            <div class="col-lg-4">
                                
                                <!-- Cari Tipi -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-tags"></i> Cari Tipi</h3>
                                    </div>
                                    <div class="card-body">
                                        <select class="form-select" name="cari_tipi_id" id="cari_tipi_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($cariTipleri as $tip): ?>
                                                <option value="<?= $tip['cari_tipi_id'] ?>"
                                                    <?= ($cari['cari_tipi_id'] ?? 1) == $tip['cari_tipi_id'] ? 'selected' : '' ?>>
                                                    <i class="<?= htmlspecialchars($tip['cari_tipi_ikon']) ?>"></i>
                                                    <?= htmlspecialchars($tip['cari_tipi_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Sektör -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-building"></i> Sektör</h3>
                                    </div>
                                    <div class="card-body">
                                        <select class="form-select" name="cari_sektor_id" id="cari_sektor_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($cariSektorler as $sektor): ?>
                                                <option value="<?= $sektor['CariSektor_Id'] ?>"
                                                    <?= ($cari['cari_sektor_id'] ?? '') == $sektor['CariSektor_Id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sektor['CariSektor_Adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Vade -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-calendar-check"></i> Vade</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label" for="cari_vade_odeme_tipi_id">Ödeme Şekli <span class="text-danger">*</span></label>
                                            <select class="form-select" name="cari_vade_odeme_tipi_id" id="cari_vade_odeme_tipi_id" required>
                                                <option value="">Seçiniz...</option>
                                                <?php foreach ($vadeOdemeTipleri as $odemeTipi): ?>
                                                    <option value="<?= $odemeTipi['tanim_OdemeTipleri_Id'] ?>"
                                                        <?= ($cari['cari_vade_odeme_tipi_id'] ?? '') == $odemeTipi['tanim_OdemeTipleri_Id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($odemeTipi['tanim_OdemeTipleri_Adi']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Ödeme şekli seçimi zorunludur.</div>
                                        </div>
                                        <div>
                                            <label class="form-label" for="cari_vade_gun_id">Vade Günü <span class="text-danger">*</span></label>
                                            <select class="form-select" name="cari_vade_gun_id" id="cari_vade_gun_id" required>
                                                <option value="">Seçiniz...</option>
                                                <?php foreach ($vadeGunleri as $vadeGun): ?>
                                                    <option value="<?= $vadeGun['CariVadeGun_Id'] ?>"
                                                        <?= ($cari['cari_vade_gun_id'] ?? '') == $vadeGun['CariVadeGun_Id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($vadeGun['CariVadeGun_Adi']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Vade günü seçimi zorunludur.</div>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($editMode && $devirYetkisi): ?>
                                <!-- Cari Devri -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-arrow-left-right"></i> Cari Devri</h3>
                                    </div>
                                    <div class="card-body">
                                        <label class="form-label">Devredilen Personel</label>
                                        <select class="form-select" name="cari_devredilen_kullanici" id="cari_devredilen_kullanici">
                                            <option value="">Devredilmedi</option>
                                            <?php foreach ($personeller as $personel): ?>
                                                <option value="<?= $personel['kullanici_id'] ?>"
                                                    <?= ($cari['cari_devredilen_kullanici'] ?? '') == $personel['kullanici_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($personel['kullanici_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted d-block mt-2">
                                            Seçilen personel, bu cariye ait geçmiş teklif, fatura, ödeme, konsinye ve
                                            görüşme kayıtlarını kendisi oluşturmamış olsa bile görebilir.
                                            Geçmiş kayıtların personel bilgisi değişmez.
                                        </small>
                                        <?php if ($devredilenAdi): ?>
                                            <div class="alert alert-info mt-2 mb-0 py-2 px-3 small">
                                                <i class="bi bi-info-circle"></i>
                                                <strong><?= htmlspecialchars($devredilenAdi) ?></strong> personeline devredildi
                                                <?php if ($devredenAdi): ?>
                                                    (devreden: <?= htmlspecialchars($devredenAdi) ?>)
                                                <?php endif; ?>
                                                <?php if (!empty($cari['cari_devir_tarihi'])): ?>
                                                    <br><?= date('d.m.Y H:i', strtotime($cari['cari_devir_tarihi'])) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Durum -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-toggle-on"></i> Durum</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="cari_aktif" name="cari_aktif" 
                                                   <?= ($cari['cari_aktif'] ?? 1) == 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cari_aktif">
                                                Cari Aktif
                                            </label>
                                        </div>
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" role="switch" id="cari_SSAAktif" name="cari_SSAAktif" 
                                                   <?= ($cari['cari_SSAAktif'] ?? 0) == 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cari_SSAAktif">
                                                SSA Aktif
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($editMode && $cari): ?>
                                <!-- Kayit bilgileri -->
                                <div class="card card-secondary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-clock-history"></i> Kayit bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <small class="text-muted">
                                            <?php if ($cari['cari_olusturma_tarihi']): ?>
                                            <div class="mb-2">
                                                <strong>Oluşturulma:</strong><br>
                                                <?= $cari['cari_olusturma_tarihi'] ?>
                                                <?php if ($cari['olusturan_adi']): ?>
                                                    <br><span class="text-primary"><?= htmlspecialchars($cari['olusturan_adi']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($cari['cari_guncelleme_tarihi']): ?>
                                            <div>
                                                <strong>Son Güncelleme:</strong><br>
                                                <?= $cari['cari_guncelleme_tarihi'] ?>
                                                <?php if ($cari['guncelleyen_adi']): ?>
                                                    <br><span class="text-primary"><?= htmlspecialchars($cari['guncelleyen_adi']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Butonlar -->
                                <div class="card card-primary card-outline">
                                    <div class="card-body">
                                        <div class="d-grid gap-2">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-check-lg"></i> <?= $editMode ? 'Güncelle' : 'Kaydet' ?>
                                            </button>
                                            <a href="/Admin/cari-yonetimi" class="btn btn-secondary">
                                                <i class="bi bi-arrow-left"></i> Geri Dön
                                            </a>
                                            <?php if ($editMode): ?>
                                            <a href="/admin/pages/degisiklik-log.php?tablo=Cari&kayit_id=<?= $cariid ?>" class="btn btn-info" target="_blank">
                                                <i class="bi bi-clock-history"></i> Log Geçmişi
                                            </a>
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

    <!-- Görüşme Notu Modal -->
    <div class="modal fade" id="gorusmeModal" tabindex="-1" aria-labelledby="gorusmeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="gorusmeModalLabel"><i class="bi bi-chat-text"></i> Görüşme Notu</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="modal_not_id" value="0">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Görüşme Tarihi <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" id="modal_gorusme_tarihi">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Görüşme Tipi</label>
                            <select class="form-select" id="modal_tip_id">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($gorusmeTipleri as $tip): ?>
                                    <option value="<?= $tip['GorusmeTipi_Id'] ?>"><?= htmlspecialchars($tip['GorusmeTipi_Adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Durum</label>
                            <select class="form-select" id="modal_durum_id">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($gorusmeDurumlari as $d): ?>
                                    <option value="<?= $d['GorusmeDurum_Id'] ?>"><?= htmlspecialchars($d['GorusmeDurum_Adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">İletişim Kurulan Kişi</label>
                            <input type="text" class="form-control" id="modal_iletisim_kisi" placeholder="Karşı taraftaki kişi adı">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Randevu Tarihi <small class="text-muted">(opsiyonel)</small></label>
                            <input type="datetime-local" class="form-control" id="modal_randevu_tarihi">
                            <div class="form-text">Yalnızca ileri tarihli randevu alındıysa doldurun.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Açıklama <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="modal_aciklama" rows="4" placeholder="Görüşme detayları..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x"></i> Kapat</button>
                    <button type="button" class="btn btn-primary" onclick="gorusmeKaydet()"><i class="bi bi-check-lg"></i> Kaydet</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- AdminLTE JS -->
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        // Şehir ve İlçe listeleri
        const allSehirler = <?= json_encode($sehirler) ?>;
        const allIlceler = <?= json_encode($ilceler) ?>;
        const cariBirimler = <?= json_encode($cariBirimler) ?>;
        const selectedSehir = <?= json_encode($cari['cari_sehirler'] ?? null) ?>;
        const selectedIlce = <?= json_encode($cari['cari_ilceler'] ?? null) ?>;
        
        $(document).ready(function() {
            // Select2 init
            $('#cari_ulke, #cari_sehirler, #cari_ilceler, #cari_tipi_id, #cari_sektor_id, #cari_vade_odeme_tipi_id, #cari_vade_gun_id, #cari_devredilen_kullanici').select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Ülke değiştiğinde şehirleri yükle
            $('#cari_ulke').on('change', function() {
                loadSehirler();
                loadIlceler(); // İlçeleri de sıfırla
            });
            
            // Şehir değiştiğinde ilçeleri yükle
            $('#cari_sehirler').on('change', function() {
                loadIlceler();
            });
            
            // Sayfa yüklendiğinde (düzenleme modunda) şehir ve ilçeleri yükle
            if ($('#cari_ulke').val()) {
                loadSehirler(selectedSehir);
                if (selectedSehir) {
                    loadIlceler(selectedIlce);
                }
            }
            
            // Form submit
            $('#cariForm').on('submit', function(e) {
                e.preventDefault();
                
                if (!this.checkValidity()) {
                    e.stopPropagation();
                    $(this).addClass('was-validated');
                    if (!$('#cari_vade_odeme_tipi_id').val() || !$('#cari_vade_gun_id').val()) {
                        showError('Eksik Bilgi', 'Vade ödeme şekli ve vade günü seçimi zorunludur.');
                    }
                    return;
                }
                
                saveCari();
            });
        });
        
        // Şehirleri yükle (Ülke'ye göre)
        function loadSehirler(selectedValue = null) {
            const ulkeId = $('#cari_ulke').val();
            const sehirSelect = $('#cari_sehirler');
            
            sehirSelect.html('<option value="">Şehir Seçiniz...</option>');
            
            if (ulkeId) {
                const filteredSehirler = allSehirler.filter(s => s.UlkeId == ulkeId);
                filteredSehirler.forEach(sehir => {
                    const selected = selectedValue == sehir.SehirId ? ' selected' : '';
                    sehirSelect.append(`<option value="${sehir.SehirId}"${selected}>${sehir.SehirAdi}</option>`);
                });
                sehirSelect.prop('disabled', false);
            } else {
                sehirSelect.html('<option value="">Önce Ülke Seçiniz...</option>');
                sehirSelect.prop('disabled', true);
            }
            
            sehirSelect.trigger('change.select2');
        }
        
        // İlçeleri yükle (Şehir'e göre)
        function loadIlceler(selectedValue = null) {
            const sehirId = $('#cari_sehirler').val();
            const ilceSelect = $('#cari_ilceler');
            
            ilceSelect.html('<option value="">İlçe Seçiniz...</option>');
            
            if (sehirId) {
                const filteredIlceler = allIlceler.filter(ilce => ilce.SehirId == sehirId);
                filteredIlceler.forEach(ilce => {
                    const selected = selectedValue == ilce.ilceId ? ' selected' : '';
                    ilceSelect.append(`<option value="${ilce.ilceId}"${selected}>${ilce.IlceAdi}</option>`);
                });
                ilceSelect.prop('disabled', false);
            } else {
                ilceSelect.html('<option value="">Önce Şehir Seçiniz...</option>');
                ilceSelect.prop('disabled', true);
            }
            
            ilceSelect.trigger('change.select2');
        }
        
        // Kaydet
        function saveCari() {
            const formData = new FormData($('#cariForm')[0]);
            formData.append('action', 'kaydet');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        const cariId = response.id || $('#cari_id').val();
                        
                        // Önce yetkililer kaydedilsin
                        yetkililerKaydet(cariId, function() {
                            showSuccess('Başarılı!', response.message);
                            setTimeout(function() {
                                window.location.href = '/Admin/cari-yonetimi';
                            }, 1500);
                        });
                    } else {
                        if (response.cari_id) {
                            Swal.fire({
                                icon: 'error',
                                title: 'M\u00FCkerrer Vergi No!',
                                text: response.message,
                                showCancelButton: true,
                                confirmButtonText: '<i class="bi bi-box-arrow-up-right"></i> Cari\'ye Git',
                                cancelButtonText: 'Tamam',
                                confirmButtonColor: '#ffc107',
                                cancelButtonColor: '#6c757d'
                            }).then(function(result) {
                                if (result.isConfirmed) {
                                    window.location.href = '/Admin/cari-form?id=' + response.cari_id;
                                }
                            });
                        } else {
                            showError('Hata!', response.message);
                        }
                    }
                },
                error: function() {
                    showError('Hata!', 'Sunucuya ba\u011Flan\u0131lamad\u0131.');
                }
            });
        }
        
        // ===== VERGİ NO =====
        // Sadece rakam girişi
        document.getElementById('cari_vergi_no').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Blur'da VKN sorgulama ve mükerrer kontrol
        document.getElementById('cari_vergi_no').addEventListener('blur', function() {
            const vergiNo = this.value.trim();
            const uyariDiv = document.getElementById('vergiNoUyari');
            const unvanInput = document.getElementById('cari_unvan');
            
            if (vergiNo.length < 10) { 
                uyariDiv.style.display = 'none'; 
                return; 
            }

            const cariId = document.getElementById('cari_id').value;
            
            // 1. Önce mükerrer kontrol
            $.post('', { action: 'vergi_no_kontrol', vergi_no: vergiNo, cari_id: cariId }, function(res) {
                if (!res.success) {
                    let btnHtml = res.cari_id
                        ? ' <a href="/Admin/cari-form?id=' + res.cari_id + '" class="btn btn-warning btn-sm py-0 px-2 ms-2"><i class="bi bi-box-arrow-up-right"></i> Cari\'ye Git</a>'
                        : '';
                    uyariDiv.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> ' + res.message + '</span>' + btnHtml;
                    uyariDiv.style.display = 'block';
                    return; // Mükerrer varsa VKN sorgulamaya geçme
                }
                
                // 2. Mükerrer yoksa TÜRKBELGE API'den ünvan sorgula
                // Sadece ünvan boşsa veya yeni kayıtsa sorgula
                if (!unvanInput.value.trim() || !cariId) {
                    uyariDiv.innerHTML = '<span class="text-info"><i class="bi bi-hourglass-split"></i> VKN sorgulanıyor...</span>';
                    uyariDiv.style.display = 'block';
                    
                    $.post('', { action: 'vkn_sorgula', vkn: vergiNo }, function(vknRes) {
                        if (vknRes.success && vknRes.mukellef) {
                            // Ünvan doldur
                            if (vknRes.unvan && !unvanInput.value.trim()) {
                                unvanInput.value = vknRes.unvan;
                            }
                            
                            // Başarı mesajı ve e-fatura rozeti
                            let badge = vknRes.tip === 'KAMU' 
                                ? '<span class="badge bg-primary ms-2">KAMU</span>' 
                                : '<span class="badge bg-success ms-2">ÖZEL</span>';
                            uyariDiv.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> E-Fatura Mükellefi</span>' + badge;
                            uyariDiv.style.display = 'block';
                            
                            // 3 saniye sonra gizle
                            setTimeout(() => { uyariDiv.style.display = 'none'; }, 3000);
                        } else if (vknRes.success && !vknRes.mukellef) {
                            uyariDiv.innerHTML = '<span class="text-warning"><i class="bi bi-info-circle"></i> E-Fatura mükellefi değil</span>';
                            uyariDiv.style.display = 'block';
                            setTimeout(() => { uyariDiv.style.display = 'none'; }, 3000);
                        } else {
                            // API hatası - sessizce geç
                            uyariDiv.style.display = 'none';
                        }
                    }).fail(function() {
                        uyariDiv.style.display = 'none';
                    });
                } else {
                    uyariDiv.style.display = 'none';
                }
            });
        });

        // ===== DOSYA YÜKLEME =====
        const cariId = <?= $cariid ?>;
        const editMode = <?= $editMode ? 'true' : 'false' ?>;

        // İkon belirle
        function dosyaIkon(tip) {
            if (!tip) return 'bi-file-earmark';
            if (tip.includes('image'))          return 'bi-file-image text-info';
            if (tip.includes('pdf'))            return 'bi-file-pdf text-danger';
            if (tip.includes('word') || tip.includes('document')) return 'bi-file-word text-primary';
            if (tip.includes('excel') || tip.includes('sheet'))   return 'bi-file-excel text-success';
            if (tip.includes('zip') || tip.includes('rar'))       return 'bi-file-zip text-warning';
            return 'bi-file-earmark text-secondary';
        }

        // Boyut formatla
        function dosyaBoyut(bytes) {
            if (!bytes) return '';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // Dosya listesini yükle
        function dosyaListeYukle() {
            if (!editMode || !cariId) return;
            $.post('', { action: 'dosya_listele', cari_id: cariId }, function(res) {
                if (!res.success) return;
                const liste = $('#dosyaListesi');
                $('#dosyaSayisi').text(res.data.length);
                if (res.data.length === 0) {
                    liste.html('<p class="text-muted text-center small mb-0"><i class="bi bi-inbox"></i> Henüz dosya yüklenmedi</p>');
                    return;
                }
                let html = '<div class="list-group list-group-flush">';
                res.data.forEach(function(d) {
                    const ikon = dosyaIkon(d.dosya_tip);
                    const boyut = dosyaBoyut(d.dosya_boyut);
                    const isResim = d.dosya_tip && d.dosya_tip.includes('image');
                    html += `
                        <div class="list-group-item px-0 py-2 d-flex align-items-center gap-2" id="dosya_${d.dosya_id}">
                            <i class="bi ${ikon} fs-4 flex-shrink-0"></i>
                            <div class="flex-grow-1 min-width-0">
                                <div class="fw-semibold text-truncate" title="${d.dosya_orijinal}" style="max-width:280px">${d.dosya_orijinal}</div>
                                <small class="text-muted">${boyut} &bull; ${d.dosya_tarih ?? ''} &bull; ${d.yukleyen_adi ?? ''}</small>
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0">
                                ${isResim ? `<a href="${d.dosya_yol}" target="_blank" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>` : ''}
                                <a href="${d.dosya_yol}" download="${d.dosya_orijinal}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="dosyaSil(${d.dosya_id})"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>`;
                });
                html += '</div>';
                liste.html(html);
            });
        }

        // Dosya yükle
        function dosyaYukle(files) {
            if (!cariId) {
                showToast('Önce cariyi kaydedin!', 'warning');
                return;
            }
            if (!files || files.length === 0) return;

            const formData = new FormData();
            formData.append('action', 'dosya_yukle');
            formData.append('cari_id', cariId);
            for (let i = 0; i < files.length; i++) {
                formData.append('dosyalar[]', files[i]);
            }

            $('#uploadProgress').removeClass('d-none');
            $('#progressBar').css('width', '0%');
            $('#progressText').text('Yükleniyor...');

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const pct = Math.round((e.loaded / e.total) * 100);
                            $('#progressBar').css('width', pct + '%');
                            $('#progressText').text(pct + '% yüklendi...');
                        }
                    });
                    return xhr;
                },
                success: function(res) {
                    $('#uploadProgress').addClass('d-none');
                    if (res.success) {
                        showToast(res.message, 'success');
                        dosyaListeYukle();
                        $('#dosyaInput').val('');
                    } else {
                        showToast(res.message, 'error');
                    }
                },
                error: function() {
                    $('#uploadProgress').addClass('d-none');
                    showToast('Yükleme sırasında hata oluştu!', 'error');
                }
            });
        }

        // Dosya sil
        function dosyaSil(dosyaId) {
            confirmAction('Bu dosyayı silmek istediğinize emin misiniz?', null, function() {
                $.post('', { action: 'dosya_sil', dosya_id: dosyaId }, function(res) {
                    if (res.success) {
                        showToast('Dosya silindi', 'success');
                        dosyaListeYukle();
                    } else {
                        showToast(res.message, 'error');
                    }
                });
            });
        }

        // Drop Zone event'leri
        $(document).ready(function() {
        // editMode kontrolü - görüşme ve dosya listesiyle yetkili listesiyükle
            if (editMode) {
                dosyaListeYukle();
                yetkiliListeYukle();
                gorusmeListeYukle();
            }

            const dropZone = document.getElementById('dropZone');
            const dosyaInput = document.getElementById('dosyaInput');

            dropZone.addEventListener('click', () => dosyaInput.click());
            dosyaInput.addEventListener('change', () => dosyaYukle(dosyaInput.files));

            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                dropZone.style.background = '#e8f0fe';
            });
            dropZone.addEventListener('dragleave', function() {
                dropZone.style.background = '#f8f9ff';
            });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropZone.style.background = '#f8f9ff';
                dosyaYukle(e.dataTransfer.files);
            });
            
            // Yetkili ekleme alanı (tfoot) şehir/ilçe select2 + bağımlılık
            yetkiliSelect2Baglat($('#yetkiliEkleSatir'));
            $('#yeni_yetkili_sehir').on('change', function() {
                $('#yeni_yetkili_ilce').html(yetkiliIlceOptions($(this).val(), null)).trigger('change.select2');
            });

            // Yetkili satırlarında (tbody) şehir değişince o satırın ilçesini güncelle
            $('#yetkiliListesi').on('change', '.yetkili-sehir', function() {
                const $ilce = $(this).closest('tr').find('.yetkili-ilce');
                $ilce.html(yetkiliIlceOptions($(this).val(), null)).trigger('change.select2');
            });

            // Görüşme modal Select2
            $('#modal_tip_id, #modal_durum_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#gorusmeModal'),
                width: '100%',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; }
                }
            });
        });
        
        // ========== YETKİLİ FONKSİYONLARI (Inline Satır Ekleme) ==========
        let yetkiliSayac = 0; // Yeni satırlar için geçici ID
        
        function yetkiliListeYukle() {
            const cariId = $('#cari_id').val();
            if (!cariId || cariId == '0') {
                yetkiliSayisiniGuncelle();
                return;
            }
            
            $.post(window.location.href, { action: 'yetkili_listele', cari_id: cariId }, function(res) {
                if (res.success) {
                    const tbody = $('#yetkiliListesi');
                    tbody.empty();
                    
                    res.data.forEach(function(y) {
                        const $row = $(yetkiliSatirOlustur(y));
                        tbody.append($row);
                        yetkiliSelect2Baglat($row);
                    });

                    yetkiliSayisiniGuncelle();
                }
            }, 'json');
        }
        
        // Yetkili satırı için şehir/ilçe option üretici
        function yetkiliSehirOptions(selectedId) {
            let html = '<option value="">Şehir</option>';
            allSehirler.forEach(s => {
                html += `<option value="${s.SehirId}" ${s.SehirId == selectedId ? 'selected' : ''}>${s.SehirAdi}</option>`;
            });
            return html;
        }
        function yetkiliIlceOptions(sehirId, selectedId) {
            let html = '<option value="">İlçe</option>';
            allIlceler.filter(i => i.SehirId == sehirId).forEach(i => {
                html += `<option value="${i.ilceId}" ${i.ilceId == selectedId ? 'selected' : ''}>${i.IlceAdi}</option>`;
            });
            return html;
        }
        // Bir satırdaki (veya tfoot ekleme alanındaki) şehir/ilçe select'lerini select2 yap
        function yetkiliSelect2Baglat($scope) {
            $scope.find('.yetkili-sehir, .yetkili-ilce').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownAutoWidth: true,
                language: { noResults: function() { return "Sonuç bulunamadı"; } }
            });
        }

        function yetkiliSatirOlustur(y) {
            const birimOptions = cariBirimler.map(b =>
                `<option value="${b.CariBirim_Id}" ${b.CariBirim_Id == y.CariYetkili_BirimId ? 'selected' : ''}>${b.CariBirim_Adi}</option>`
            ).join('');

            return `
                <tr data-id="${y.CariYetkili_Id || 'new_' + yetkiliSayac}">
                    <td><input type="text" class="form-control form-control-sm yetkili-adi" value="${y.CariYetkili_Adi || ''}" placeholder="Adı"></td>
                    <td><input type="text" class="form-control form-control-sm yetkili-soyadi" value="${y.CariYetkili_Soyadi || ''}" placeholder="Soyadı"></td>
                    <td>
                        <select class="form-select form-select-sm yetkili-birim">
                            <option value="">Seçiniz</option>
                            ${birimOptions}
                        </select>
                    </td>
                    <td><input type="tel" class="form-control form-control-sm yetkili-telefon" value="${y.CariYetkili_Telefon || ''}" placeholder="Telefon"></td>
                    <td><input type="email" class="form-control form-control-sm yetkili-email" value="${y.CariYetkili_Email || ''}" placeholder="E-posta"></td>
                    <td>
                        <select class="form-select form-select-sm yetkili-sehir">${yetkiliSehirOptions(y.CariYetkili_Sehir)}</select>
                    </td>
                    <td>
                        <select class="form-select form-select-sm yetkili-ilce">${yetkiliIlceOptions(y.CariYetkili_Sehir, y.CariYetkili_Ilce)}</select>
                    </td>
                    <td><input type="text" class="form-control form-control-sm yetkili-adres" value="${escHtml(y.CariYetkili_Adres || '')}" placeholder="Adres"></td>
                    <td class="text-center">
                        <div class="form-check form-switch d-flex justify-content-center">
                            <input class="form-check-input yetkili-durum" type="checkbox" ${y.Durum == 1 || y.Durum === undefined ? 'checked' : ''}>
                        </div>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-xs btn-danger" onclick="yetkiliSatirSil(this)" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        }
        
        function yetkiliSatirEkle() {
            const adi = $('#yeni_yetkili_adi').val().trim();
            if (!adi) {
                Swal.fire('Uyarı', 'Yetkili adı zorunludur', 'warning');
                return;
            }
            
            yetkiliSayac++;
            const yeniYetkili = {
                CariYetkili_Id: null,
                CariYetkili_Adi: adi,
                CariYetkili_Soyadi: $('#yeni_yetkili_soyadi').val(),
                CariYetkili_BirimId: $('#yeni_yetkili_birim').val(),
                CariYetkili_Telefon: $('#yeni_yetkili_telefon').val(),
                CariYetkili_Email: $('#yeni_yetkili_email').val(),
                CariYetkili_Sehir: $('#yeni_yetkili_sehir').val() || null,
                CariYetkili_Ilce: $('#yeni_yetkili_ilce').val() || null,
                CariYetkili_Adres: $('#yeni_yetkili_adres').val(),
                Durum: $('#yeni_yetkili_durum').is(':checked') ? 1 : 0
            };

            const $row = $(yetkiliSatirOlustur(yeniYetkili));
            $('#yetkiliListesi').append($row);
            yetkiliSelect2Baglat($row);

            // Ekleme alanını temizle
            $('#yeni_yetkili_adi, #yeni_yetkili_soyadi, #yeni_yetkili_telefon, #yeni_yetkili_email, #yeni_yetkili_adres').val('');
            $('#yeni_yetkili_birim').val('');
            $('#yeni_yetkili_sehir').val('').trigger('change.select2');
            $('#yeni_yetkili_ilce').html('<option value="">İlçe</option>').trigger('change.select2');
            $('#yeni_yetkili_durum').prop('checked', true);

            yetkiliSayisiniGuncelle();
        }
        
        function yetkiliSatirSil(btn) {
            const row = $(btn).closest('tr');
            const id = row.data('id');
            
            Swal.fire({
                title: 'Emin misiniz?',
                text: 'Bu yetkili silinecek!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet, Sil!',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Eğer DB'de kayıtlı bir yetkili ise API ile sil
                    if (id && !String(id).startsWith('new_')) {
                        $.post(window.location.href, { action: 'yetkili_sil', yetkili_id: id }, function(res) {
                            if (res.success) {
                                row.remove();
                                yetkiliSayisiniGuncelle();
                                Swal.fire({ icon: 'success', title: 'Silindi!', timer: 1000, showConfirmButton: false });
                            } else {
                                Swal.fire('Hata', res.message, 'error');
                            }
                        }, 'json');
                    } else {
                        // Henüz kaydedilmemiş satır, sadece DOM'dan kaldır
                        row.remove();
                        yetkiliSayisiniGuncelle();
                    }
                }
            });
        }
        
        function yetkiliSayisiniGuncelle() {
            const sayi = $('#yetkiliListesi tr').length;
            $('#yetkiliSayisi').text(sayi);
        }
        
        // Yetkilileri toplu kaydet (form submit öncesi çağrılır)
        function yetkililerKaydet(cariId, callback) {
            // Tfoot'ta bekleyen veri varsa önce tbody'e ekle
            const yeniAdi = $('#yeni_yetkili_adi').val().trim();
            if (yeniAdi) {
                yetkiliSatirEkle();
            }

            const satirlar = $('#yetkiliListesi tr');
            if (satirlar.length === 0) {
                callback();
                return;
            }

            let tamamlanan = 0;
            let hatalar = [];

            satirlar.each(function() {
                const row = $(this);
                const id = row.data('id');
                const adi = row.find('.yetkili-adi').val().trim();

                if (!adi) {
                    tamamlanan++;
                    if (tamamlanan === satirlar.length) {
                        if (hatalar.length > 0) showToast('Bazı yetkililer kaydedilemedi: ' + hatalar.join(', '), 'error');
                        callback();
                    }
                    return;
                }

                const data = {
                    action: 'yetkili_kaydet',
                    cari_id: cariId,
                    yetkili_id: (id && !String(id).startsWith('new_')) ? id : 0,
                    yetkili_adi: adi,
                    yetkili_soyadi: row.find('.yetkili-soyadi').val(),
                    yetkili_birim: row.find('.yetkili-birim').val(),
                    yetkili_telefon: row.find('.yetkili-telefon').val(),
                    yetkili_email: row.find('.yetkili-email').val(),
                    yetkili_sehir: row.find('.yetkili-sehir').val(),
                    yetkili_ilce: row.find('.yetkili-ilce').val(),
                    yetkili_adres: row.find('.yetkili-adres').val(),
                    yetkili_durum: row.find('.yetkili-durum').is(':checked') ? 1 : 0
                };

                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(res) {
                        if (!res.success) hatalar.push(adi + ' (' + (res.message || 'bilinmeyen hata') + ')');
                    },
                    error: function() {
                        hatalar.push(adi + ' (bağlantı hatası)');
                    },
                    complete: function() {
                        tamamlanan++;
                        if (tamamlanan === satirlar.length) {
                            if (hatalar.length > 0) showToast('Bazı yetkililer kaydedilemedi: ' + hatalar.join(', '), 'error');
                            callback();
                        }
                    }
                });
            });
        }
        // ========== GÖRÜŞME NOTU FONKSİYONLARI ==========
        const gorusmeTipleriMap   = <?= json_encode(array_column($gorusmeTipleri, null, 'GorusmeTipi_Id')) ?>;
        const gorusmeDurumlariMap = <?= json_encode(array_column($gorusmeDurumlari, null, 'GorusmeDurum_Id')) ?>;

        function gorusmeListeYukle() {
            const cId = $('#cari_id').val();
            if (!cId || cId == '0') return;

            $.post(window.location.href, { action: 'gorusme_listele', cari_id: cId }, function(res) {
                if (!res.success) return;
                const liste = $('#gorusmeListesi');
                $('#gorusmeSayisi').text(res.data.length);

                if (res.data.length === 0) {
                    liste.html('<p class="text-muted text-center small p-3 mb-0"><i class="bi bi-inbox"></i> Henüz görüşme notu eklenmedi</p>');
                    return;
                }

                let html = '<div class="list-group list-group-flush">';
                res.data.forEach(function(g) {
                    const tipIkon  = g.GorusmeTipi_Ikon  || 'bi bi-chat-dots';
                    const tipRenk  = g.GorusmeTipi_Renk  || 'secondary';
                    const durumAdi = g.GorusmeDurum_Adi   || '';
                    const durumRenk= g.GorusmeDurum_Renk  || 'secondary';
                    const randevu  = g.RandevuTarihi
                        ? `<span class="badge bg-info ms-1"><i class="bi bi-calendar-event"></i> Randevu: ${g.RandevuTarihi}</span>`
                        : '';
                    const kisi = g.GorusmeNotlari_IletisimKisi
                        ? `<span class="text-muted ms-2"><i class="bi bi-person"></i> ${escHtml(g.GorusmeNotlari_IletisimKisi)}</span>`
                        : '';

                    html += `
                        <div class="list-group-item px-3 py-2" id="gorusme_${g.GorusmeNotlari_Id}">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                        <span class="badge bg-${tipRenk}"><i class="${tipIkon}"></i> ${escHtml(g.GorusmeTipi_Adi || 'Belirtilmemiş')}</span>
                                        ${durumAdi ? `<span class="badge bg-${durumRenk}">${escHtml(durumAdi)}</span>` : ''}
                                        <small class="text-muted"><i class="bi bi-clock"></i> ${g.GorusmeTarihi}</small>
                                        ${kisi}
                                        ${randevu}
                                    </div>
                                    <p class="mb-0 small" style="white-space:pre-wrap">${escHtml(g.GorusmeNotlari_Aciklama)}</p>
                                    <small class="text-muted"><i class="bi bi-person-circle"></i> ${escHtml(g.KullaniciAdi || '')}</small>
                                </div>
                                <div class="d-flex gap-1 ms-2 flex-shrink-0">
                                    <button type="button" class="btn btn-xs btn-outline-primary" onclick="gorusmeDuzenle(${g.GorusmeNotlari_Id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-danger" onclick="gorusmeSil(${g.GorusmeNotlari_Id})" title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>`;
                });
                html += '</div>';
                liste.html(html);
            }, 'json');
        }

        function gorusmeModalAc(notId = 0) {
            $('#modal_not_id').val(notId);
            $('#modal_gorusme_tarihi').val('');
            $('#modal_randevu_tarihi').val('');
            $('#modal_aciklama').val('');
            $('#modal_iletisim_kisi').val('');
            $('#modal_tip_id').val('').trigger('change');
            $('#modal_durum_id').val('').trigger('change');
            $('#gorusmeModalLabel').html('<i class="bi bi-chat-text"></i> ' + (notId > 0 ? 'Görüşme Notu Düzenle' : 'Yeni Görüşme Notu'));
            $('#gorusmeModal').modal('show');
        }

        function gorusmeDuzenle(notId) {
            $.post(window.location.href, { action: 'gorusme_getir', not_id: notId }, function(res) {
                if (!res.success) { showToast(res.message, 'error'); return; }
                const d = res.data;
                $('#modal_not_id').val(d.GorusmeNotlari_Id);
                $('#modal_gorusme_tarihi').val(d.GorusmeTarihi ? d.GorusmeTarihi.replace(' ', 'T') : '');
                $('#modal_randevu_tarihi').val(d.RandevuTarihi ? d.RandevuTarihi.replace(' ', 'T') : '');
                $('#modal_aciklama').val(d.GorusmeNotlari_Aciklama);
                $('#modal_iletisim_kisi').val(d.GorusmeNotlari_IletisimKisi || '');
                $('#modal_tip_id').val(d.GorusmeNotlari_TipId || '').trigger('change');
                $('#modal_durum_id').val(d.GorusmeNotlari_DurumId || '').trigger('change');
                $('#gorusmeModalLabel').html('<i class="bi bi-chat-text"></i> Görüşme Notu Düzenle');
                $('#gorusmeModal').modal('show');
            }, 'json');
        }

        function gorusmeKaydet() {
            const aciklama = $('#modal_aciklama').val().trim();
            const tarih    = $('#modal_gorusme_tarihi').val().trim();

            if (!tarih)     { showToast('Görüşme tarihi zorunludur!', 'warning'); return; }
            if (!aciklama)  { showToast('Açıklama zorunludur!', 'warning'); return; }

            const data = {
                action:          'gorusme_kaydet',
                cari_id:         $('#cari_id').val(),
                not_id:          $('#modal_not_id').val(),
                gorusme_tarihi:  tarih.replace('T', ' '),
                randevu_tarihi:  $('#modal_randevu_tarihi').val().replace('T', ' '),
                tip_id:          $('#modal_tip_id').val(),
                durum_id:        $('#modal_durum_id').val(),
                iletisim_kisi:   $('#modal_iletisim_kisi').val(),
                aciklama:        aciklama
            };

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: data,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        showToast(res.message, 'success');
                        $('#gorusmeModal').modal('hide');
                        gorusmeListeYukle();
                    } else {
                        showToast(res.message || 'Kayıt başarısız!', 'error');
                    }
                },
                error: function(xhr) {
                    console.error('AJAX Hatası:', xhr.status, xhr.responseText);
                    showToast('Sunucu hatası (' + xhr.status + '). Konsolu kontrol edin.', 'error');
                }
            });
        }

        function gorusmeSil(notId) {
            confirmAction('Bu görüşme notunu silmek istediğinize emin misiniz?', null, function() {
                $.post(window.location.href, { action: 'gorusme_sil', not_id: notId }, function(res) {
                    if (res.success) {
                        showToast('Görüşme notu silindi', 'success');
                        gorusmeListeYukle();
                    } else {
                        showToast(res.message, 'error');
                    }
                }, 'json');
            });
        }

        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    </script>
</body>
</html>
