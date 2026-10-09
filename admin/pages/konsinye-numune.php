<?php
/**
 * Admin Panel - Konsinye & Numune Çıkışları
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki Kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa Bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Konsinye & Numune Çıkışları';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// PHP üzerinden ilk yüklemede gerekli veriler
// Cari ve Ürünler çok fazla kayıt içerdiği için sayfa açılışında çekilmiyor, Select2 AJAX ile veya sayfa yüklendikten sonra JS ile yükleniyor.

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        $baseJoin = "FROM UrunKonsinye_Numune kn LEFT JOIN Cari c ON kn.kn_cari_id = c.cari_id LEFT JOIN Urun_Hizmet u ON kn.kn_urun_id = u.urun_hizmet_id";
        $baseWhere = "WHERE kn.Durum = 1";
        $baseParams = [];

        if (!empty($pagePermissions['can_view_own_records'])) {
            // Kendi kayıtları + kendisine devredilen carilerin kayıtları
            $baseWhere .= " AND (" . CariDevirHelper::orDevir(
                "kn.OlusturanKullanici = ?",
                (int)$user['kullanici_id'],
                'kn.kn_cari_id'
            ) . ")";
            $baseParams[] = $user['kullanici_id'];
        }

        // Ortak filtreler (liste ve istatistik kutuları aynı filtreyi kullanır)
        if (in_array($action, ['list', 'stats'], true)) {
            if (!empty($_POST['cari_id'])) {
                $baseWhere .= " AND kn.kn_cari_id = ?";
                $baseParams[] = (int)$_POST['cari_id'];
            }
            if (!empty($_POST['urun_id'])) {
                $baseWhere .= " AND kn.kn_urun_id = ?";
                $baseParams[] = (int)$_POST['urun_id'];
            }
            if (!empty($_POST['tip'])) {
                $baseWhere .= " AND kn.kn_tip = ?";
                $baseParams[] = $_POST['tip'];
            }
            if (!empty($_POST['tarih_baslangic'])) {
                $baseWhere .= " AND CONVERT(date, ISNULL(kn.kn_tarih, kn.OlusturmaTarihi)) >= ?";
                $baseParams[] = $_POST['tarih_baslangic'];
            }
            if (!empty($_POST['tarih_bitis'])) {
                $baseWhere .= " AND CONVERT(date, ISNULL(kn.kn_tarih, kn.OlusturmaTarihi)) <= ?";
                $baseParams[] = $_POST['tarih_bitis'];
            }
        }

        switch ($action) {
            case 'stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) as sayi $baseJoin $baseWhere", $baseParams)['sayi'] ?? 0;
                $bekleyen = $db->fetchOne("SELECT COUNT(*) as sayi $baseJoin $baseWhere AND kn.kn_durum = 1", $baseParams)['sayi'] ?? 0;
                $fatura = $db->fetchOne("SELECT COUNT(*) as sayi $baseJoin $baseWhere AND kn.kn_durum = 2", $baseParams)['sayi'] ?? 0;
                $iade = $db->fetchOne("SELECT COUNT(*) as sayi $baseJoin $baseWhere AND kn.kn_durum = 3", $baseParams)['sayi'] ?? 0;

                echo json_encode(['success' => true, 'data' => [
                    'toplam' => $toplam,
                    'bekleyen' => $bekleyen,
                    'fatura' => $fatura,
                    'iade' => $iade
                ]]);
                break;

            case 'list':
                $sql = "
                    SELECT 
                        kn.kn_id, kn.kn_tip, kn.kn_miktar, kn.kn_durum, kn.kn_aciklama,
                        CONVERT(VARCHAR(10), ISNULL(kn.kn_tarih, kn.OlusturmaTarihi), 104) as islem_tarihi,
                        CONVERT(VARCHAR(10), ISNULL(kn.kn_tarih, kn.OlusturmaTarihi), 23) as islem_tarihi_iso,
                        c.cari_unvan, c.cari_adi,
                        u.urun_hizmet_adi, u.urun_hizmet_kodu,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as olusturan_adi,
                        fis.fis_kn_id, fis.fis_kalem_sayisi
                    $baseJoin
                    LEFT JOIN kullanicilar k ON kn.OlusturanKullanici = k.kullanici_id
                    OUTER APPLY (
                        SELECT MIN(g.kn_id) as fis_kn_id, COUNT(*) as fis_kalem_sayisi
                        FROM UrunKonsinye_Numune g
                        WHERE g.Durum = 1
                          AND g.kn_cari_id = kn.kn_cari_id
                          AND g.OlusturanKullanici = kn.OlusturanKullanici
                          AND g.OlusturmaTarihi = kn.OlusturmaTarihi
                          AND g.kn_tip = kn.kn_tip
                    ) fis
                    $baseWhere
                ";
                $params = $baseParams;

                if (!empty($_POST['durum_id'])) {
                    $sql .= " AND kn.kn_durum = ?";
                    $params[] = $_POST['durum_id'];
                }

                $sql .= " ORDER BY kn.kn_id DESC";
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'get_cariler':
                $data = $db->fetchAll("SELECT cari_id as id, ISNULL(cari_unvan, cari_adi) as text FROM Cari WHERE cari_aktif = 1 ORDER BY ISNULL(cari_unvan, cari_adi)");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'get_urunler':
                $data = $db->fetchAll("SELECT urun_hizmet_id as id, urun_hizmet_adi + ' (' + ISNULL(urun_hizmet_kodu, '') + ') - Stok: ' + CAST(ISNULL(Urun_Stok_Miktari, 0) AS VARCHAR) as text FROM Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_adi");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'get_urunler_search':
                $q = trim($_POST['q'] ?? '');
                if (strlen($q) < 1) { echo json_encode(['success' => true, 'data' => []]); break; }
                $sadece_stok = !empty($_POST['sadece_stok']) && $_POST['sadece_stok'] == '1';
                $stok_filtre = $sadece_stok ? "AND ISNULL(Urun_Stok_Miktari, 0) > 0" : "";
                $data = $db->fetchAll("
                    SELECT TOP 20 urun_hizmet_id as id,
                        urun_hizmet_adi + ' (' + ISNULL(urun_hizmet_kodu,'') + ') - Stok: ' + CAST(ISNULL(Urun_Stok_Miktari,0) AS VARCHAR) as text
                    FROM Urun_Hizmet
                    WHERE urun_hizmet_durum = 1
                      $stok_filtre
                      AND (urun_hizmet_adi LIKE ? OR urun_hizmet_kodu LIKE ? OR urun_hizmet_barkod LIKE ?)
                    ORDER BY urun_hizmet_adi
                ", ["%$q%", "%$q%", "%$q%"]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'add':
                if (!$pagePermissions['can_add']) throw new Exception('Ekleme yetkiniz yok.');
                
                $tip      = $_POST['kn_tip'] ?? '';
                $cari_id  = (int)($_POST['kn_cari_id'] ?? 0);
                $aciklama = trim($_POST['kn_aciklama'] ?? '');
                $kalemler = json_decode($_POST['kalemler'] ?? '[]', true);

                if (!$tip || !$cari_id) {
                    throw new Exception('Lütfen Tip ve Cari alanlarını doldurun.');
                }
                if (empty($kalemler)) {
                    throw new Exception('En az bir ürün satırı eklemelisiniz.');
                }

                $db->beginTransaction();
                try {
                    foreach ($kalemler as $kalem) {
                        $urun_id = (int)($kalem['urun_id'] ?? 0);
                        $miktar  = (int)($kalem['miktar'] ?? 0);
                        if (!$urun_id || $miktar <= 0) continue;

                        $db->insert('UrunKonsinye_Numune', [
                            'kn_tip'             => $tip,
                            'kn_cari_id'         => $cari_id,
                            'kn_urun_id'         => $urun_id,
                            'kn_miktar'          => $miktar,
                            'kn_tarih'           => date('Y-m-d'),
                            'kn_aciklama'        => $aciklama,
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                            'Durum'              => 1,
                            'kn_durum'           => 1
                        ]);
                    }

                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Kayıt(lar) başarıyla oluşturuldu.']);
                } catch (Throwable $ex) {
                    $db->rollBack();
                    throw $ex;
                }
                break;

            case 'update_status':
                if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
                
                $id = (int)($_POST['id'] ?? 0);
                $yeni_durum = (int)($_POST['durum_id'] ?? 0);

                if (!$id || !$yeni_durum) throw new Exception('Geçersiz veri.');

                $kayit = $db->fetchOne("
                    SELECT kn.*,
                        ISNULL(c.cari_unvan, c.cari_adi) as cari_ad,
                        u.urun_hizmet_adi as urun_ad,
                        u.urun_hizmet_kodu, u.urun_hizmet_model,
                        u.urun_hizmet_beden, u.urun_hizmet_renk,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as olusturan_ad
                    FROM UrunKonsinye_Numune kn
                    LEFT JOIN Cari c ON kn.kn_cari_id = c.cari_id
                    LEFT JOIN Urun_Hizmet u ON kn.kn_urun_id = u.urun_hizmet_id
                    LEFT JOIN kullanicilar k ON kn.OlusturanKullanici = k.kullanici_id
                    WHERE kn.kn_id = ? AND kn.Durum = 1
                ", [$id]);
                if (!$kayit) throw new Exception('Kayıt bulunamadı.');

                $db->beginTransaction();
                try {
                    $mevcut_durum = (int)$kayit['kn_durum'];
                    $miktar  = (int)$kayit['kn_miktar'];
                    $urun_id = (int)$kayit['kn_urun_id'];

                    // Stok düşen statüler: Teslim Edildi (6), Fire/Zayi (4)
                    $stok_duser = [4, 6];
                    $eski_duser = in_array($mevcut_durum, $stok_duser);
                    $yeni_duser = in_array($yeni_durum, $stok_duser);

                    if ($mevcut_durum != $yeni_durum) {
                        if ($yeni_duser && !$eski_duser) {
                            // → Teslim Edildi / Fire Zayi: stok düş
                            $db->insert('Urun_Stok_Hareketleri', [
                                'Urun_id'            => $urun_id,
                                'Islem_Tipi'         => 'CIKIS',
                                'Miktar'             => $miktar,
                                'Aciklama'           => $kayit['kn_tip'] . ' ' . ($yeni_durum == 6 ? 'Teslim' : 'Fire/Zayi') . ' (Kayıt: ' . $id . ')',
                                'OlusturanKullanici' => $user['kullanici_id'],
                                'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                                'Durum'              => 1
                            ]);
                            $db->execute("UPDATE Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) - ? WHERE urun_hizmet_id = ?", [$miktar, $urun_id]);
                        } elseif ($yeni_durum == 3 && $eski_duser) {
                            // → İade Geldi (sadece stok düşülmüş statüden): stok geri ekle
                            $db->insert('Urun_Stok_Hareketleri', [
                                'Urun_id'            => $urun_id,
                                'Islem_Tipi'         => 'GIRIS',
                                'Miktar'             => $miktar,
                                'Aciklama'           => $kayit['kn_tip'] . ' İadesi (Kayıt: ' . $id . ')',
                                'OlusturanKullanici' => $user['kullanici_id'],
                                'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                                'Durum'              => 1
                            ]);
                            $db->execute("UPDATE Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) + ? WHERE urun_hizmet_id = ?", [$miktar, $urun_id]);
                        } elseif ($eski_duser && !$yeni_duser && $yeni_durum != 3) {
                            // Teslim/Fire → diğer statüye geri dönüş: stok iade et
                            $db->insert('Urun_Stok_Hareketleri', [
                                'Urun_id'            => $urun_id,
                                'Islem_Tipi'         => 'GIRIS',
                                'Miktar'             => $miktar,
                                'Aciklama'           => $kayit['kn_tip'] . ' Durum İptali - Stok İadesi (Kayıt: ' . $id . ')',
                                'OlusturanKullanici' => $user['kullanici_id'],
                                'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                                'Durum'              => 1
                            ]);
                            $db->execute("UPDATE Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) + ? WHERE urun_hizmet_id = ?", [$miktar, $urun_id]);
                        }
                    }

                    $db->update('UrunKonsinye_Numune', [
                        'kn_durum'            => $yeni_durum,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'    => date('Y-m-d H:i:s')
                    ], ['kn_id' => $id]);

                    $db->commit();

                    // Onaylandı statüsüne geçince WhatsApp grubuna bildirim gönder
                    if ($yeni_durum == 5 && $mevcut_durum != 5) {
                        try {
                            require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
                            $helper = new EntegrasyonHelper($db);
                            $waMesaj  = "✅ *Konsinye/Numune Onaylandı*\n\n";
                            $waMesaj .= "📋 *Cari:* " . ($kayit['cari_ad'] ?? '-') . "\n";
                            $waMesaj .= "📦 *Ürün:* " . ($kayit['urun_ad'] ?? '-') . "\n";
                            $waMesaj .= "🔖 *Kod:* " . ($kayit['urun_hizmet_kodu'] ?? '-') . "\n";
                            $waMesaj .= "🏷️ *Model:* " . ($kayit['urun_hizmet_model'] ?? '-') . "\n";
                            $waMesaj .= "📐 *Beden:* " . ($kayit['urun_hizmet_beden'] ?? '-') . "\n";
                            $waMesaj .= "🎨 *Renk:* " . ($kayit['urun_hizmet_renk'] ?? '-') . "\n";
                            $waMesaj .= "🔢 *Adet:* " . $kayit['kn_miktar'] . "\n";
                            $waMesaj .= "📝 *Açıklama:* " . (!empty($kayit['kn_aciklama']) ? $kayit['kn_aciklama'] : '-') . "\n";
                            $waMesaj .= "👤 *Personel:* " . ($kayit['olusturan_ad'] ?? '-') . "\n\n";
                            $waMesaj .= "⚠️ Ürünü teslime hazırlayabilirsiniz. Lütfen teslim edilince durumu *Teslim Edildi* olarak değiştiriniz.";
                            $helper->whatsappGonder('120000000000000000@g.us', $waMesaj);
                        } catch (Throwable $waEx) {
                            // WA hatası ana işlemi etkilemesin
                            error_log('WA Konsinye bildirimi hatası: ' . $waEx->getMessage());
                        }
                    }

                    echo json_encode(['success' => true, 'message' => 'Durum güncellendi.']);
                } catch (Throwable $ex) {
                    $db->rollBack();
                    throw $ex;
                }
                break;

            case 'get_one':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('Geçersiz ID.');
                $kayit = $db->fetchOne("
                    SELECT kn.*, ISNULL(c.cari_unvan, c.cari_adi) as cari_text,
                        u.urun_hizmet_adi + ' (' + ISNULL(u.urun_hizmet_kodu,'') + ') - Stok: ' + CAST(ISNULL(u.Urun_Stok_Miktari,0) AS VARCHAR) as urun_text
                    FROM UrunKonsinye_Numune kn
                    LEFT JOIN Cari c ON kn.kn_cari_id = c.cari_id
                    LEFT JOIN Urun_Hizmet u ON kn.kn_urun_id = u.urun_hizmet_id
                    WHERE kn.kn_id = ? AND kn.Durum = 1
                ", [$id]);
                if (!$kayit) throw new Exception('Kayıt bulunamadı.');
                echo json_encode(['success' => true, 'data' => $kayit]);
                break;

            case 'update':
                if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');

                $id      = (int)($_POST['kn_id'] ?? 0);
                $tip     = $_POST['kn_tip'] ?? '';
                $cari_id = (int)($_POST['kn_cari_id'] ?? 0);
                $aciklama = trim($_POST['kn_aciklama'] ?? '');
                $kalemler = json_decode($_POST['kalemler'] ?? '[]', true);

                if (!$id || !$tip || !$cari_id) throw new Exception('Gerekli alanlar eksik.');
                if (empty($kalemler)) throw new Exception('En az bir ürün satırı girilmelidir.');

                $kalem = $kalemler[0];
                $yeni_urun_id = (int)($kalem['urun_id'] ?? 0);
                $yeni_miktar  = (int)($kalem['miktar'] ?? 0);
                if (!$yeni_urun_id || $yeni_miktar <= 0) throw new Exception('Ürün ve miktar zorunludur.');

                $kayit = $db->fetchOne("SELECT * FROM UrunKonsinye_Numune WHERE kn_id = ? AND Durum = 1", [$id]);
                if (!$kayit) throw new Exception('Kayıt bulunamadı.');

                $db->beginTransaction();
                try {
                    $eski_urun_id = (int)$kayit['kn_urun_id'];
                    $eski_miktar  = (int)$kayit['kn_miktar'];

                    // Stok ayarlaması: eski çıkışı geri al, yeni çıkışı uygula
                    if ($eski_urun_id !== $yeni_urun_id || $eski_miktar !== $yeni_miktar) {
                        // Eski ürün stokuna geri ekle
                        $db->execute("UPDATE Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari,0) + ? WHERE urun_hizmet_id = ?", [$eski_miktar, $eski_urun_id]);
                        $db->insert('Urun_Stok_Hareketleri', [
                            'Urun_id' => $eski_urun_id, 'Islem_Tipi' => 'GIRIS',
                            'Miktar' => $eski_miktar,
                            'Aciklama' => $kayit['kn_tip'] . ' Düzenleme - Eski Stok İadesi (Kayıt: ' . $id . ')',
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi' => date('Y-m-d H:i:s'), 'Durum' => 1
                        ]);

                        // Yeni ürün stoktan düş
                        $db->execute("UPDATE Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari,0) - ? WHERE urun_hizmet_id = ?", [$yeni_miktar, $yeni_urun_id]);
                        $db->insert('Urun_Stok_Hareketleri', [
                            'Urun_id' => $yeni_urun_id, 'Islem_Tipi' => 'CIKIS',
                            'Miktar' => $yeni_miktar,
                            'Aciklama' => $tip . ' Düzenleme Çıkışı (Kayıt: ' . $id . ')',
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi' => date('Y-m-d H:i:s'), 'Durum' => 1
                        ]);
                    }

                    $db->update('UrunKonsinye_Numune', [
                        'kn_tip'              => $tip,
                        'kn_cari_id'          => $cari_id,
                        'kn_urun_id'          => $yeni_urun_id,
                        'kn_miktar'           => $yeni_miktar,
                        'kn_aciklama'         => $aciklama,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'    => date('Y-m-d H:i:s')
                    ], ['kn_id' => $id]);

                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Kayıt güncellendi.']);
                } catch (Throwable $ex) {
                    $db->rollBack();
                    throw $ex;
                }
                break;

            case 'delete':
                if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz yok.');
                
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('Geçersiz veri.');

                $kayit = $db->fetchOne("SELECT * FROM UrunKonsinye_Numune WHERE kn_id = ? AND Durum = 1", [$id]);
                if (!$kayit) throw new Exception('Kayıt bulunamadı.');

                $db->beginTransaction();
                try {
                    // Sadece stok düşülmüş statülerde (4, 6) silme → stok geri ekle
                    if (in_array((int)$kayit['kn_durum'], [4, 6])) {
                        $miktar  = (int)$kayit['kn_miktar'];
                        $urun_id = (int)$kayit['kn_urun_id'];

                        $db->insert('Urun_Stok_Hareketleri', [
                            'Urun_id'            => $urun_id,
                            'Islem_Tipi'         => 'GIRIS',
                            'Miktar'             => $miktar,
                            'Aciklama'           => $kayit['kn_tip'] . ' İptali (Silindi)',
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                            'Durum'              => 1
                        ]);
                        $db->execute("UPDATE Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) + ? WHERE urun_hizmet_id = ?", [$miktar, $urun_id]);
                    }

                    $db->execute("UPDATE UrunKonsinye_Numune SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE kn_id = ?", [$user['kullanici_id'], $id]);

                    $db->commit();
                    $msg = in_array((int)$kayit['kn_durum'], [4, 6]) ? 'Kayıt silindi ve stok iade edildi.' : 'Kayıt silindi.';
                    echo json_encode(['success' => true, 'message' => $msg]);
                } catch (Throwable $ex) {
                    $db->rollBack();
                    throw $ex;
                }
                break;

            default:
                throw new Exception('Geçersiz işlem.');
        }
    } catch (Throwable $e) {
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?>
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-box-arrow-up-right"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Çıkış</span>
                                <span class="info-box-number" id="stat_toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bekleyen</span>
                                <span class="info-box-number" id="stat_bekleyen">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-receipt"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Faturaya Dönen</span>
                                <span class="info-box-number" id="stat_fatura">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-arrow-return-left"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">İade Gelen</span>
                                <span class="info-box-number" id="stat_iade">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Kartı -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="filterCollapse">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Cari</label>
                                    <select class="form-select select2" id="filter_cari_id" name="cari_id">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Ürün</label>
                                    <select class="form-select select2" id="filter_urun_id" name="urun_id">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tip</label>
                                    <select class="form-select select2" id="filter_tip" name="tip">
                                        <option value="">Tümü</option>
                                        <option value="NUMUNE">Numune</option>
                                        <option value="KONSINYE">Konsinye</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select select2" id="filter_durum_id" name="durum_id">
                                        <option value="">Tümü</option>
                                        <option value="1">Beklemede</option>
                                        <option value="5">Onaylandı</option>
                                        <option value="6">Teslim Edildi</option>
                                        <option value="2">Faturaya Döndü</option>
                                        <option value="3">İade Geldi</option>
                                        <option value="4">Fire / Zayi</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih (Başlangıç)</label>
                                    <input type="date" class="form-control" id="filter_tarih_baslangic" name="tarih_baslangic">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih (Bitiş)</label>
                                    <input type="date" class="form-control" id="filter_tarih_bitis" name="tarih_bitis">
                                </div>
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tablo Kartı -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-list-ul"></i> Çıkış Listesi</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-success me-1" id="btnExcelIndir">
                                <i class="bi bi-file-earmark-excel"></i> Excel İndir
                            </button>
                            <?php if ($pagePermissions['can_add']): ?>
                            <button type="button" class="btn btn-sm btn-primary" onclick="$('#addModal').modal('show')">
                                <i class="bi bi-plus-lg"></i> Yeni Çıkış
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="dataTable" class="table table-bordered table-striped table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th class="text-center">Kayıt No</th>
                                        <th>Fiş No</th>
                                        <th>Tarih</th>
                                        <th>Cari</th>
                                        <th>Ürün</th>
                                        <th>Tip</th>
                                        <th class="text-center">Miktar</th>
                                        <th>Durum</th>
                                        <th>Oluşturan</th>
                                        <th>Açıklama</th>
                                        <th class="text-center">İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Yeni Çıkış Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addModalTitle"><i class="bi bi-plus-circle me-2"></i>Yeni Çıkış Kaydı</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addForm">
                <input type="hidden" id="kn_edit_id" value="">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">İşlem Tipi <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="kn_tip" id="tipNumune" value="NUMUNE" checked>
                                    <label class="form-check-label" for="tipNumune">Numune</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="kn_tip" id="tipKonsinye" value="KONSINYE">
                                    <label class="form-check-label" for="tipKonsinye">Konsinye</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Cari <span class="text-danger">*</span></label>
                            <select class="form-select" id="add_cari_id" name="kn_cari_id">
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                    </div>

                    <!-- Ürün Satırları -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="d-flex align-items-center gap-3">
                            <label class="form-label mb-0 fw-semibold">Ürün Kalemleri <span class="text-danger">*</span></label>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="chkSadeceStok" checked>
                                <label class="form-check-label small text-muted" for="chkSadeceStok">Stoktaki ürünler</label>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-success" id="btnSatirEkle" onclick="knKalemEkle()">
                            <i class="bi bi-plus-lg"></i> Satır Ekle
                        </button>
                    </div>
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-hover table-sm align-middle" id="knKalemTable">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th style="min-width:300px">Ürün / Hizmet <span class="text-danger">*</span></th>
                                    <th style="width:120px">Miktar (Adet) <span class="text-danger">*</span></th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="knKalemBody">
                                <!-- JS ile doldurulacak -->
                            </tbody>
                        </table>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Açıklama / Not</label>
                        <textarea name="kn_aciklama" id="add_aciklama" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" id="btnKaydet"><i class="bi bi-save"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Durum Değiştirme Modal -->
<div class="modal fade" id="durumModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Durum Değiştir</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="durum_id_field">
                <label class="form-label">Yeni Durum</label>
                <select class="form-select" id="durum_yeni_id">
                    <option value="1">Beklemede</option>
                    <option value="5">Onaylandı</option>
                    <option value="6">Teslim Edildi (Stoktan Düşer)</option>
                    <option value="2">Faturaya Döndü</option>
                    <option value="3">İade Geldi (Stoka Döner)</option>
                    <option value="4">Fire / Zayi</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" onclick="durumKaydet()">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
let table;
let currentFilters = {};
const pAdd = <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>;
const pEdit = <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>;
const pDel = <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>;

function getDurumBadge(d) {
    switch(parseInt(d)) {
        case 1: return '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Beklemede</span>';
        case 5: return '<span class="badge bg-primary"><i class="bi bi-check-circle"></i> Onaylandı</span>';
        case 6: return '<span class="badge bg-dark"><i class="bi bi-truck"></i> Teslim Edildi</span>';
        case 2: return '<span class="badge bg-success"><i class="bi bi-receipt"></i> Faturaya Döndü</span>';
        case 3: return '<span class="badge bg-info"><i class="bi bi-arrow-return-left"></i> İade Geldi</span>';
        case 4: return '<span class="badge bg-danger"><i class="bi bi-trash"></i> Fire/Zayi</span>';
        default: return '-';
    }
}

// Fiş No üretimi: PDF sayfasındaki mantık ile birebir aynı (tip + yıl + fişin ilk kayıt no)
function getFisNo(r) {
    const fisId = parseInt(r.fis_kn_id) || parseInt(r.kn_id);
    const tip   = (r.kn_tip === 'KONSINYE') ? 'KON' : 'NUM';
    const yil   = (r.islem_tarihi || '').slice(-4) || new Date().getFullYear();
    return tip + '-' + yil + '-' + String(fisId).padStart(4, '0');
}

function formYazdir(knId) {
    window.open('/admin/pages/konsinye-numune-pdf.php?id=' + knId, '_blank');
}

let knKalemSayac = 0;
let cariOptions = '';

$(document).ready(function() {
    // Select2 - Filtreler
    $('#filter_tip, #filter_durum_id').select2({
        theme: 'bootstrap-5',
        width: '100%',
        allowClear: true,
        placeholder: 'Seçiniz...'
    });

    // Carileri yükle
    $.post('', {action:'get_cariler'}, function(res) {
        if(res.success) {
            let opts = '<option value=""></option>';
            res.data.forEach(x => opts += `<option value="${x.id}">${x.text}</option>`);
            cariOptions = opts;
            $('#filter_cari_id').html(opts);
            $('#filter_cari_id').select2({ theme:'bootstrap-5', width:'100%', allowClear:true, placeholder:'Seçiniz...' });
            $('#add_cari_id').html(opts);
            $('#add_cari_id').select2({ theme:'bootstrap-5', width:'100%', allowClear:true, placeholder:'Seçiniz...', dropdownParent: $('#addModal') });
        }
    });

    // Filtre ürün listesi
    $.post('', {action:'get_urunler'}, function(res) {
        if(res.success) {
            let opts = '<option value=""></option>';
            res.data.forEach(x => opts += `<option value="${x.id}">${x.text}</option>`);
            $('#filter_urun_id').html(opts);
            $('#filter_urun_id').select2({ theme:'bootstrap-5', width:'100%', allowClear:true, placeholder:'Seçiniz...' });
        }
    });

    // Modal açıldığında (sadece "Yeni" modu için — düzenleme kayitDuzenle() içinde açılır)
    $('#addModal').on('show.bs.modal', function(e) {
        if ($('#kn_edit_id').val()) return; // edit modunda dışarıdan açılmasın
        $('#addModalTitle').html('<i class="bi bi-plus-circle me-2"></i>Yeni Çıkış Kaydı');
        $('#btnSatirEkle').show();
        $('#knKalemBody').empty();
        knKalemSayac = 0;
        knKalemEkle();
    });

    // Modal kapandığında sıfırla
    $('#addModal').on('hidden.bs.modal', function() {
        $('#kn_edit_id').val('');
        $('#addForm')[0].reset();
        $('#add_cari_id').val('').trigger('change');
        $('#knKalemBody').empty();
        knKalemSayac = 0;
        $('#addModalTitle').html('<i class="bi bi-plus-circle me-2"></i>Yeni Çıkış Kaydı');
        $('#btnSatirEkle').show();
    });

    // DataTable init
    table = $('#dataTable').DataTable({
        processing: true,
        stateSave: true,
        ajax: {
            url: '',
            type: 'POST',
            data: function(d) {
                return {
                    action: 'list',
                    ...currentFilters
                };
            },
            dataSrc: function(json) {
                return json.success ? json.data : [];
            }
        },
        columns: [
            { data: 'kn_id', className: 'text-center fw-bold', render: d => '#' + d },
            {
                data: null,
                className: 'text-nowrap',
                render: function(d, t, r) {
                    const fisNo = getFisNo(r);
                    if (t !== 'display') return fisNo;
                    const kalem = parseInt(r.fis_kalem_sayisi) || 1;
                    const rozet = kalem > 1
                        ? ' <span class="badge bg-light text-dark border" title="Fişteki kalem sayısı">' + kalem + ' kalem</span>'
                        : '';
                    return '<span class="fw-semibold">' + fisNo + '</span>' + rozet;
                }
            },
            {
                data: 'islem_tarihi',
                className: 'text-nowrap',
                render: function(d, t, r) {
                    // Görüntüde gg.aa.yyyy, sıralama/arama/filtrede ISO (yyyy-aa-gg)
                    if (t === 'display') return d || '-';
                    return r.islem_tarihi_iso || '';
                }
            },
            {
                data: null,
                render: function(d) {
                    let ad = d.cari_unvan || d.cari_adi;
                    return ad ? (ad.length > 30 ? ad.substring(0,30)+'...' : ad) : '-';
                }
            },
            { 
                data: 'urun_hizmet_adi', 
                render: function(d,t,r) {
                    return d + (r.urun_hizmet_kodu ? '<br><small class="text-muted">'+r.urun_hizmet_kodu+'</small>' : '');
                }
            },
            { 
                data: 'kn_tip', 
                render: function(d) {
                    return `<span class="badge ${d=='NUMUNE' ? 'bg-secondary' : 'bg-primary'}">${d}</span>`;
                }
            },
            { data: 'kn_miktar', className: 'text-center fw-bold' },
            { 
                data: 'kn_durum', 
                className: 'text-center',
                render: function(d,t,r) {
                    let badge = getDurumBadge(d);
                    if (pEdit) {
                        return `<span class="cursor-pointer" onclick="durumDegistirModal(${r.kn_id}, ${d})" title="Durum Değiştir">${badge}</span>`;
                    }
                    return badge;
                }
            },
            { data: 'olusturan_adi', render: d => d || '-' },
            {
                data: 'kn_aciklama',
                render: function(d) {
                    if (!d) return '-';
                    const full = $('<div>').text(d).html();
                    const kisa = $('<div>').text(d.length > 40 ? d.substring(0,40)+'…' : d).html();
                    return '<span title="' + full + '">' + kisa + '</span>';
                }
            },
            {
                data: null, className: 'text-center text-nowrap', orderable: false,
                render: function(data) {
                    let btns = '';
                    btns += `<button class="btn btn-sm btn-secondary me-1" onclick="formYazdir(${data.kn_id})" title="Teslim Formu Yazdır"><i class="bi bi-printer"></i></button>`;
                    if(pEdit) {
                        btns += `<button class="btn btn-sm btn-warning me-1" onclick="kayitDuzenle(${data.kn_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
                        btns += `<button class="btn btn-sm btn-info me-1" onclick="durumDegistirModal(${data.kn_id}, ${data.kn_durum})" title="Durum Güncelle"><i class="bi bi-arrow-repeat"></i></button>`;
                    }
                    if(pDel) {
                        btns += `<button class="btn btn-sm btn-danger" onclick="kayitSil(${data.kn_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                    }
                    return btns;
                }
            }
        ],
        order: [[0, 'desc']],
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        buttons: [
            {
                extend: 'excel',
                title: 'Konsinye & Numune Çıkışları',
                filename: 'konsinye-numune-' + new Date().toISOString().slice(0,10),
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
                    format: {
                        body: function(data, row, column) {
                            // Badge ve HTML tag'lerini temizle
                            return $('<div>').html(data).text().trim();
                        }
                    }
                }
            }
        ]
    });

    $('#btnExcelIndir').on('click', function() {
        table.button(0).trigger();
    });

    loadStats();

    // Filtre Formu
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        const tBas = $('#filter_tarih_baslangic').val();
        const tBit = $('#filter_tarih_bitis').val();

        if (tBas && tBit && tBas > tBit) {
            showError('Hata', 'Başlangıç tarihi bitiş tarihinden büyük olamaz.');
            return;
        }

        currentFilters = {
            cari_id: $('#filter_cari_id').val(),
            urun_id: $('#filter_urun_id').val(),
            tip: $('#filter_tip').val(),
            durum_id: $('#filter_durum_id').val(),
            tarih_baslangic: tBas,
            tarih_bitis: tBit
        };
        table.ajax.reload();
        loadStats();
    });

    $('#clearFilters').on('click', function() {
        $('#filterForm')[0].reset();
        $('#filter_cari_id, #filter_urun_id, #filter_tip, #filter_durum_id').val('').trigger('change');
        $('#filter_tarih_baslangic, #filter_tarih_bitis').val('');
        currentFilters = {};
        table.ajax.reload();
        loadStats();
    });

    // Kaydet Butonu
    $('#btnKaydet').on('click', function() {
        const editId = $('#kn_edit_id').val();
        const cariId = $('#add_cari_id').val();
        const tip    = $('input[name="kn_tip"]:checked').val();
        if (!cariId) { showError('Hata', 'Cari seçimi zorunludur.'); return; }

        const kalemler = [];
        let hata = false;
        $('#knKalemBody .kn-kalem-row').each(function() {
            const urunId = $(this).find('.kn-urun-id').val();
            const miktar = parseInt($(this).find('.kn-miktar').val(), 10);
            if (!urunId || !(miktar > 0)) { hata = true; return false; }
            kalemler.push({ urun_id: urunId, miktar: miktar });
        });

        if (hata || kalemler.length === 0) {
            showError('Hata', 'Tüm satırlarda ürün ve miktar (≥1) girilmelidir.');
            return;
        }

        let btn = $(this);
        let origText = btn.html();
        btn.prop('disabled', true).html('<i class="spinner-border spinner-border-sm"></i> Kaydediliyor...');

        const postData = {
            kn_tip: tip,
            kn_cari_id: cariId,
            kn_aciklama: $('#add_aciklama').val(),
            kalemler: JSON.stringify(kalemler)
        };

        if (editId) {
            postData.action = 'update';
            postData.kn_id  = editId;
        } else {
            postData.action = 'add';
        }

        $.post('', postData, function(res) {
            btn.prop('disabled', false).html(origText);
            if (res.success) {
                $('#addModal').modal('hide');
                showSuccess('Başarılı', res.message);
                table.ajax.reload(null, false);
                loadStats();
            } else {
                showError('Hata', res.message);
            }
        }).fail(function() {
            btn.prop('disabled', false).html(origText);
            showError('Sunucu Hatası', 'Sunucu ile iletişim kurulamadı.');
        });
    });
});

// Konsinye/Numune kalem ekle
function knKalemEkle() {
    knKalemSayac++;
    const idx = knKalemSayac;
    const row = `
    <tr class="kn-kalem-row" data-idx="${idx}">
        <td class="text-center text-muted">${idx}</td>
        <td>
            <select class="form-select kn-urun-select" data-idx="${idx}" style="width:100%">
                <option value="">Ürün arayın...</option>
            </select>
            <input type="hidden" class="kn-urun-id" value="">
        </td>
        <td>
            <input type="number" class="form-control kn-miktar" value="1" min="1" step="1">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="knKalemSil(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>`;
    $('#knKalemBody').append(row);

    // Select2 AJAX ürün arama
    $(`.kn-urun-select[data-idx="${idx}"]`).select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Ürün adı veya kodu ile arayın...',
        allowClear: true,
        minimumInputLength: 1,
        dropdownParent: $('#addModal'),
        language: {
            noResults: () => 'Sonuç bulunamadı',
            searching: () => 'Aranıyor...',
            inputTooShort: () => 'En az 1 karakter girin...'
        },
        ajax: {
            url: '',
            type: 'POST',
            dataType: 'json',
            delay: 300,
            data: params => ({ action: 'get_urunler_search', q: params.term, sadece_stok: $('#chkSadeceStok').is(':checked') ? '1' : '0' }),
            processResults: data => ({ results: data.data || [] })
        }
    }).on('select2:select', function(e) {
        const $row = $(`.kn-kalem-row[data-idx="${idx}"]`);
        $row.find('.kn-urun-id').val(e.params.data.id);
    }).on('select2:clear', function() {
        $(`.kn-kalem-row[data-idx="${idx}"]`).find('.kn-urun-id').val('');
    });
}

function knKalemSil(btn) {
    const $body = $('#knKalemBody');
    if ($body.find('.kn-kalem-row').length <= 1) {
        showError('Uyarı', 'En az bir ürün satırı olmalıdır.');
        return;
    }
    $(btn).closest('tr').remove();
    // Sıra numaralarını yenile
    $body.find('.kn-kalem-row').each(function(i) {
        $(this).find('td:first').text(i + 1);
    });
}

function loadStats() {
    $.post('', { action: 'stats', ...currentFilters }, function(res) {
        if (res.success) {
            $('#stat_toplam').text(res.data.toplam);
            $('#stat_bekleyen').text(res.data.bekleyen);
            $('#stat_fatura').text(res.data.fatura);
            $('#stat_iade').text(res.data.iade);
        }
    });
}

function kayitDuzenle(id) {
    $.post('', { action: 'get_one', id: id }, function(res) {
        if (!res.success) { showError('Hata', res.message); return; }
        const d = res.data;

        // Modal başlık ve mod ayarla
        $('#kn_edit_id').val(d.kn_id);
        $('#addModalTitle').html('<i class="bi bi-pencil me-2"></i>Çıkış Kaydı Düzenle #' + d.kn_id);
        $('#btnSatirEkle').hide();

        // Tip radio
        $('input[name="kn_tip"][value="' + d.kn_tip + '"]').prop('checked', true);

        // Açıklama
        $('#add_aciklama').val(d.kn_aciklama || '');

        // Ürün satırını temizle ve tek satır ekle
        $('#knKalemBody').empty();
        knKalemSayac = 0;
        knKalemEkle();

        // Cari select2'ye mevcut değeri yükle
        if ($('#add_cari_id option[value="' + d.kn_cari_id + '"]').length) {
            $('#add_cari_id').val(d.kn_cari_id).trigger('change');
        } else {
            const opt = new Option(d.cari_text, d.kn_cari_id, true, true);
            $('#add_cari_id').append(opt).trigger('change');
        }

        // Ürün select2'ye mevcut değeri yükle (AJAX select2)
        const $urunSel = $('#knKalemBody .kn-urun-select').first();
        const opt = new Option(d.urun_text, d.kn_urun_id, true, true);
        $urunSel.append(opt).trigger('change');
        $urunSel.closest('tr').find('.kn-urun-id').val(d.kn_urun_id);

        // Miktar
        $('#knKalemBody .kn-miktar').first().val(d.kn_miktar);

        $('#addModal').modal('show');
    }).fail(function() {
        showError('Sunucu Hatası', 'Kayıt bilgileri alınamadı.');
    });
}

function durumDegistirModal(id, mevcutDurum) {
    $('#durum_id_field').val(id);
    $('#durum_yeni_id').val(mevcutDurum);
    $('#durumModal').modal('show');
}

function durumKaydet() {
    const id = $('#durum_id_field').val();
    const durumId = $('#durum_yeni_id').val();

    $.post('', { action: 'update_status', id: id, durum_id: durumId }, function(res) {
        if (res.success) {
            $('#durumModal').modal('hide');
            table.ajax.reload(null, false);
            loadStats();
            showSuccess('Başarılı', res.message);
        } else {
            showError('Hata', res.message);
        }
    });
}

function kayitSil(id) {
    confirmAction(
        'Emin misiniz?',
        'Bu çıkış kaydını silmek istediğinize emin misiniz? Eğer iade edilmediyse stok geri eklenecektir.',
        function() {
            $.post('', { action: 'delete', id: id }, function(res) {
                if (res.success) {
                    showSuccess('Başarılı', res.message);
                    table.ajax.reload(null, false);
                    loadStats();
                } else {
                    showError('Hata', res.message);
                }
            });
        }
    );
}
</script>
</body>
</html>
