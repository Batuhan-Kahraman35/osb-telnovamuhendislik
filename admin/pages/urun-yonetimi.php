<?php
/**
 * Admin Panel - Ürün Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UrunBirlestirmeHelper.php';

/** Liste ve Excel için ortak WHERE (yalnız Urun_Hizmet uh kolonları, join gerektirmez) */
function urunListeFiltresi(array $post): array
{
    $where  = ["1=1"];
    $params = [];

    if (($post['tip'] ?? '') !== '')         { $where[] = "uh.urun_hizmet_tip = ?";         $params[] = (int)$post['tip']; }
    if (!empty($post['kategori_id']))        { $where[] = "uh.urun_hizmet_kategori_id = ?"; $params[] = (int)$post['kategori_id']; }
    if (!empty($post['marka_id']))           { $where[] = "uh.urun_hizmet_marka_id = ?";    $params[] = (int)$post['marka_id']; }
    if (($post['durum'] ?? '') !== '')       { $where[] = "uh.urun_hizmet_durum = ?";       $params[] = (int)$post['durum']; }

    $search = trim($post['search_text'] ?? '');
    if ($search !== '') {
        $where[] = "(uh.urun_hizmet_kodu LIKE ? OR uh.urun_hizmet_adi LIKE ? OR uh.urun_hizmet_model LIKE ?)";
        array_push($params, "%$search%", "%$search%", "%$search%");
    }

    return ['where' => implode(' AND ', $where), 'params' => $params];
}

// AJAX istekleri için özel auth kontrolü
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::check()) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Oturum süresi doldu. Lütfen tekrar giriş yapın.', 'redirect' => '/Admin/login.php']));
    }
} else {
    requireAuth();
}

$user = Auth::user();
$db = Database::getInstance();

// Mevcut sayfanin bilgilerini al
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

// Sayfa bilgileri
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Ürün Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

// Erişim Yetkisi yoksa hata sayfası göster
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim Yetkiniz yok!');
}

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'list':
                // Server-side DataTables: 1. aşama sayfa id'leri (hafif), 2. aşama join + son alış APPLY yalnız bu satırlara
                $filtre = urunListeFiltresi($_POST);

                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 500) $length = 25;

                // Kolon index => [sıralama ifadesi, gereken join]
                $orderMap = [
                    0  => ['uh.urun_hizmet_id', null],
                    1  => ['uh.urun_hizmet_kodu', null],
                    2  => ['uh.urun_hizmet_adi', null],
                    3  => ['k.kategori_adi', "LEFT JOIN UrunHizmet_Kategoriler k ON k.kategori_id = uh.urun_hizmet_kategori_id"],
                    4  => ['m.marka_adi', "LEFT JOIN UrunHizmet_Markalar m ON m.marka_id = uh.urun_hizmet_marka_id"],
                    8  => ['uh.Urun_Hizmet_AlisFiyati', null],
                    9  => ['uh.urun_hizmet_taban_fiyati', null],
                    10 => ['uh.urun_hizmet_satis_fiyati', null],
                    11 => ['uh.BayiSatisFiyati', null],
                    12 => ['uh.HirdavatSatisFiyati', null],
                    13 => ['uh.FabrikaSatisFiyati', null],
                    14 => ['pb.ParaBirimi_Kod', "LEFT JOIN tanim_ParaBirimi pb ON pb.ParaBirimi_id = uh.urun_hizmet_para_birimi_id"],
                    15 => ['kdv.kdv_oran', "LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id"],
                ];
                $orderIdx  = (int)($_POST['order'][0]['column'] ?? 2);
                [$orderCol, $orderJoin] = $orderMap[$orderIdx] ?? $orderMap[2];
                $orderDir  = strtoupper($_POST['order'][0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM Urun_Hizmet")['sayi'] ?? 0);
                $recordsFiltered = $filtre['params']
                    ? (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM Urun_Hizmet uh WHERE {$filtre['where']}", $filtre['params'])['sayi'] ?? 0)
                    : $recordsTotal;

                $sql = "WITH Sayfa AS (
                            SELECT uh.urun_hizmet_id,
                                   ROW_NUMBER() OVER (ORDER BY $orderCol $orderDir, uh.urun_hizmet_id $orderDir) AS Sira
                            FROM Urun_Hizmet uh
                            " . ($orderJoin ?? '') . "
                            WHERE {$filtre['where']}
                            ORDER BY $orderCol $orderDir, uh.urun_hizmet_id $orderDir
                            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                        )
                        SELECT
                            uh.urun_hizmet_id,
                            uh.urun_hizmet_kodu,
                            uh.urun_hizmet_adi,
                            uh.urun_hizmet_satis_fiyati,
                            uh.urun_hizmet_taban_fiyati,
                            uh.Urun_Hizmet_AlisFiyati,
                            uh.BayiSatisFiyati,
                            uh.HirdavatSatisFiyati,
                            uh.FabrikaSatisFiyati,
                            k.kategori_adi,
                            m.marka_adi,
                            kdv.kdv_oran,
                            pb.ParaBirimi_Kod,
                            pb.ParaBirimi_Simge,
                            stok_alis.FAlisTL,
                            stok_alis.FAlisDolar,
                            stok_alis.FAlisEuro
                        FROM Sayfa s
                        INNER JOIN Urun_Hizmet uh ON uh.urun_hizmet_id = s.urun_hizmet_id
                        OUTER APPLY (
                            SELECT TOP 1
                                Birim_Fiyati as FAlisTL,
                                CASE WHEN ISNULL(DolarKur, 0) > 0 THEN (Birim_Fiyati / DolarKur) ELSE 0 END as FAlisDolar,
                                CASE WHEN ISNULL(EuroKur, 0) > 0 THEN (Birim_Fiyati / EuroKur) ELSE 0 END as FAlisEuro
                            FROM Urun_Stok_Hareketleri
                            WHERE Urun_id = uh.urun_hizmet_id AND Islem_Tipi = 'GIRIS'
                            ORDER BY OlusturmaTarihi DESC
                        ) stok_alis
                        LEFT JOIN UrunHizmet_Kategoriler k ON k.kategori_id = uh.urun_hizmet_kategori_id
                        LEFT JOIN UrunHizmet_Markalar m ON m.marka_id = uh.urun_hizmet_marka_id
                        LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                        LEFT JOIN tanim_ParaBirimi pb ON pb.ParaBirimi_id = uh.urun_hizmet_para_birimi_id
                        ORDER BY s.Sira";

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $db->fetchAll($sql, array_merge($filtre['params'], [$start, $length])),
                ]);
                break;

            case 'urun_ara':
                // Select2 aramaları için hafif sorgu (Üst Ürün, Taşı ve Birleştir)
                $q       = trim($_POST['q'] ?? '');
                $haricId = (int)($_POST['haric_id'] ?? 0);
                $sql     = "SELECT TOP 30 urun_hizmet_id AS id,
                                   CASE WHEN ISNULL(urun_hizmet_kodu, '') <> '' THEN urun_hizmet_kodu + ' - ' ELSE '' END + urun_hizmet_adi AS text
                            FROM Urun_Hizmet
                            WHERE urun_hizmet_durum = 1 AND urun_hizmet_id <> ?";
                $params  = [$haricId];
                if ($q !== '') {
                    $sql     .= " AND (urun_hizmet_kodu LIKE ? OR urun_hizmet_adi LIKE ? OR urun_hizmet_model LIKE ?)";
                    array_push($params, "%$q%", "%$q%", "%$q%");
                    // Öncelik: kod birebir > kod ile başlayan > içinde geçen
                    $sql     .= " ORDER BY CASE WHEN urun_hizmet_kodu = ? THEN 0 WHEN urun_hizmet_kodu LIKE ? THEN 1 ELSE 2 END, urun_hizmet_kodu";
                    array_push($params, $q, "$q%");
                } else {
                    $sql .= " ORDER BY urun_hizmet_adi";
                }
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            case 'excel':
                // Excel için filtreye uyan tüm kayıtlar (yalnız Excel'e yazılan kolonlar)
                $filtre = urunListeFiltresi($_POST);
                $sql = "SELECT
                            uh.urun_hizmet_id, uh.urun_hizmet_tip, uh.urun_hizmet_kodu, uh.urun_hizmet_adi, uh.urun_hizmet_model,
                            uh.urun_hizmet_birim, uh.urun_hizmet_kritik_stok, uh.urun_hizmet_satis_fiyati, uh.urun_hizmet_taban_fiyati,
                            uh.Urun_Hizmet_AlisFiyati, uh.BayiSatisFiyati, uh.HirdavatSatisFiyati, uh.FabrikaSatisFiyati,
                            uh.urun_hizmet_barkod, uh.urun_hizmet_durum,
                            CONVERT(VARCHAR(19), uh.urun_hizmet_olusturma_tarihi, 120) as urun_hizmet_olusturma_tarihi,
                            k.kategori_adi, m.marka_adi, kdv.kdv_adi, kdv.kdv_oran, pb.ParaBirimi_Kod,
                            stok_alis.FAlisTL, stok_alis.FAlisDolar, stok_alis.FAlisEuro
                        FROM Urun_Hizmet uh
                        OUTER APPLY (
                            SELECT TOP 1
                                Birim_Fiyati as FAlisTL,
                                CASE WHEN ISNULL(DolarKur, 0) > 0 THEN (Birim_Fiyati / DolarKur) ELSE 0 END as FAlisDolar,
                                CASE WHEN ISNULL(EuroKur, 0) > 0 THEN (Birim_Fiyati / EuroKur) ELSE 0 END as FAlisEuro
                            FROM Urun_Stok_Hareketleri
                            WHERE Urun_id = uh.urun_hizmet_id AND Islem_Tipi = 'GIRIS'
                            ORDER BY OlusturmaTarihi DESC
                        ) stok_alis
                        LEFT JOIN UrunHizmet_Kategoriler k ON k.kategori_id = uh.urun_hizmet_kategori_id
                        LEFT JOIN UrunHizmet_Markalar m ON m.marka_id = uh.urun_hizmet_marka_id
                        LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                        LEFT JOIN tanim_ParaBirimi pb ON pb.ParaBirimi_id = uh.urun_hizmet_para_birimi_id
                        WHERE {$filtre['where']}
                        ORDER BY uh.urun_hizmet_tip, uh.urun_hizmet_sira_no, uh.urun_hizmet_adi";
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $filtre['params'])]);
                break;

                

            case 'stats':
                // istatistikleri getir
                $stats = $db->fetchOne("
                    SELECT
                        SUM(CASE WHEN urun_hizmet_tip = 1 THEN 1 ELSE 0 END)          AS toplam_urun,
                        SUM(CASE WHEN urun_hizmet_tip = 2 THEN 1 ELSE 0 END)          AS toplam_hizmet,
                        SUM(CASE WHEN urun_hizmet_durum = 1 THEN 1 ELSE 0 END)        AS aktif,
                        SUM(CASE WHEN urun_hizmet_kritik_stok > 0 THEN 1 ELSE 0 END)  AS kritik_stok
                    FROM Urun_Hizmet
                ") ?: [];
                $stats = array_map(fn($v) => (int)$v, $stats);
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'get':
                // Tek ürün getir
                $id = $_POST['id'] ?? 0;
                $sql = "SELECT uh.*, ust.urun_hizmet_kodu as ust_urun_kodu, ust.urun_hizmet_adi as ust_urun_adi
                        FROM Urun_Hizmet uh
                        LEFT JOIN Urun_Hizmet ust ON ust.urun_hizmet_id = uh.urun_hizmet_ust_id
                        WHERE uh.urun_hizmet_id = ?";
                $urun = $db->fetchOne($sql, [$id]);
                
                if ($urun) {
                    echo json_encode(['success' => true, 'data' => $urun]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Ürün/Hizmet bulunamadı!']);
                }
                break;
                
            case 'save':
                $id = $_POST['id'] ?? 0;

                // Yetki kontrolü
                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme Yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme Yetkiniz yok!']);
                    break;
                }

                // Yeni ürün ekle veya güncelle
                $urun_hizmet_tip = intval($_POST['urun_hizmet_tip'] ?? 1);
                $urun_hizmet_kodu = trim($_POST['urun_hizmet_kodu'] ?? '');
                $urun_hizmet_adi = trim($_POST['urun_hizmet_adi'] ?? '');
                $urun_hizmet_model = trim($_POST['urun_hizmet_model'] ?? '');
                $urun_hizmet_aciklama = trim($_POST['urun_hizmet_aciklama'] ?? '');
                $urun_hizmet_kategori_id = $_POST['urun_hizmet_kategori_id'] ?? null;
                $urun_hizmet_marka_id = $_POST['urun_hizmet_marka_id'] ?? null;
                $urun_hizmet_birim = trim($_POST['urun_hizmet_birim'] ?? '');
                $urun_hizmet_kritik_stok = intval($_POST['urun_hizmet_kritik_stok'] ?? 0);
                $urun_hizmet_satis_fiyati = floatval($_POST['urun_hizmet_satis_fiyati'] ?? 0);
                $urun_hizmet_taban_fiyati = floatval($_POST['urun_hizmet_taban_fiyati'] ?? 0);
                $Urun_Hizmet_AlisFiyati = floatval($_POST['Urun_Hizmet_AlisFiyati'] ?? 0);
                $BayiSatisFiyati = floatval($_POST['BayiSatisFiyati'] ?? 0);
                $HirdavatSatisFiyati = floatval($_POST['HirdavatSatisFiyati'] ?? 0);
                $FabrikaSatisFiyati = floatval($_POST['FabrikaSatisFiyati'] ?? 0);
                $urun_hizmet_kdv_id = intval($_POST['urun_hizmet_kdv_id'] ?? 0);
                $hizmetSuresi = !empty($_POST['hizmetSuresi']) ? intval($_POST['hizmetSuresi']) : null;
                $urun_hizmet_barkod = trim($_POST['urun_hizmet_barkod'] ?? '');
                $urun_hizmet_serino = isset($_POST['urun_hizmet_serino']) ? 1 : 0;
                $urun_hizmet_sira_no = intval($_POST['urun_hizmet_sira_no'] ?? 0);
                $urun_hizmet_durum = isset($_POST['urun_hizmet_durum']) ? 1 : 0;
                $urun_hizmet_para_birimi_id = !empty($_POST['urun_hizmet_para_birimi_id']) ? intval($_POST['urun_hizmet_para_birimi_id']) : null;
                $urun_hizmet_renk = trim($_POST['urun_hizmet_renk'] ?? '') ?: null;
                $urun_hizmet_beden = trim($_POST['urun_hizmet_beden'] ?? '') ?: null;
                $urun_hizmet_ust_id = !empty($_POST['urun_hizmet_ust_id']) ? intval($_POST['urun_hizmet_ust_id']) : null;

                $allowedSures = [3, 6, 9, 12, 24];
                if ($hizmetSuresi !== null && !in_array($hizmetSuresi, $allowedSures, true)) {
                    echo json_encode(['success' => false, 'message' => 'Hizmet Süresi yalnızca 3, 6, 9, 12 veya 24 olabilir!']);
                    break;
                }
                
                if (empty($urun_hizmet_adi)) {
                    echo json_encode(['success' => false, 'message' => 'Ürün/Hizmet adı zorunludur!']);
                    break;
                }
                
                if ($urun_hizmet_kdv_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'KDV seçimi zorunludur!']);
                    break;
                }
                
                // Boş değerleri NULL yap
                if (empty($urun_hizmet_kodu)) {
                    // Otomatik kod çret: UH-timeSTAMP
                    $urun_hizmet_kodu = 'UH-' . time();
                }
                if (empty($urun_hizmet_kategori_id)) $urun_hizmet_kategori_id = null;
                if (empty($urun_hizmet_marka_id)) $urun_hizmet_marka_id = null;
                if (empty($urun_hizmet_barkod)) $urun_hizmet_barkod = null;

                // Mükerrer kod kontrolü (UQ_Urun_Hizmet_Kod kısıtına takılmadan anlaşılır uyarı ver)
                if ($id > 0) {
                    $mevcutKod = $db->fetchOne(
                        "SELECT urun_hizmet_id, urun_hizmet_adi, urun_hizmet_durum FROM Urun_Hizmet WHERE urun_hizmet_kodu = ? AND urun_hizmet_id <> ?",
                        [$urun_hizmet_kodu, $id]
                    );
                } else {
                    $mevcutKod = $db->fetchOne(
                        "SELECT urun_hizmet_id, urun_hizmet_adi, urun_hizmet_durum FROM Urun_Hizmet WHERE urun_hizmet_kodu = ?",
                        [$urun_hizmet_kodu]
                    );
                }
                if ($mevcutKod) {
                    $durumEtiket = intval($mevcutKod['urun_hizmet_durum']) === 1 ? '' : ' (pasif kayıt)';
                    echo json_encode([
                        'success' => false,
                        'message' => 'Bu kod (' . $urun_hizmet_kodu . ') zaten kullanımda: "' . $mevcutKod['urun_hizmet_adi'] . '"' . $durumEtiket . '. Lütfen farklı bir kod girin.'
                    ]);
                    break;
                }

                // Görsel yükleme işlemi
                $urun_hizmet_gorsel_url = null;
                $gorsel_url_mevcut = trim($_POST['urun_hizmet_gorsel_url_mevcut'] ?? '');
                if (isset($_FILES['urun_hizmet_gorsel']) && $_FILES['urun_hizmet_gorsel']['size'] > 0) {
                    // Dosya yükleme hata kontrolü
                    if ($_FILES['urun_hizmet_gorsel']['error'] !== UPLOAD_ERR_OK) {
                        $uploadErrors = [
                            UPLOAD_ERR_INI_SIZE   => 'Dosya boyutu PHP limitini aşıyor (upload_max_filesize).',
                            UPLOAD_ERR_FORM_SIZE  => 'Dosya boyutu form limitini aşıyor.',
                            UPLOAD_ERR_PARTIAL    => 'Dosya kısmen yüklendi.',
                            UPLOAD_ERR_NO_FILE    => 'Dosya seçilmedi.',
                            UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici klasör bulunamadı.',
                            UPLOAD_ERR_CANT_WRITE => 'Sunucuda dosya yazılamadı.',
                            UPLOAD_ERR_EXTENSION  => 'Dosya yükleme bir eklenti tarafından engellendi.',
                        ];
                        $errCode = $_FILES['urun_hizmet_gorsel']['error'];
                        $errMsg = $uploadErrors[$errCode] ?? "Bilinmeyen hata (kod: $errCode)";
                        echo json_encode(['success' => false, 'message' => 'Görsel yükleme hatası: ' . $errMsg]);
                        break;
                    }
                    
                    $uploaddir = __DIR__ . '/../assets/uploads/urunler/';
                    
                    if (!is_dir($uploaddir)) {
                        if (!mkdir($uploaddir, 0755, true)) {
                            echo json_encode(['success' => false, 'message' => 'Yükleme klasörü oluşturulamadı!']);
                            break;
                        }
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['urun_hizmet_gorsel']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (!in_array($fileExtension, $allowedExtensions)) {
                        echo json_encode(['success' => false, 'message' => 'Sadece resim dosyaları yüklenebilir! (jpg, jpeg, png, gif, webp)']);
                        break;
                    }
                    
                    if ($_FILES['urun_hizmet_gorsel']['size'] > 5 * 1024 * 1024) {
                        echo json_encode(['success' => false, 'message' => 'Dosya boyutu en fazla 5MB olabilir!']);
                        break;
                    }
                    
                    $newfileName = time() . '_' . uniqid() . '.' . $fileExtension;
                    $uploadPath = $uploaddir . $newfileName;
                    
                    if (move_uploaded_file($_FILES['urun_hizmet_gorsel']['tmp_name'], $uploadPath)) {
                        $urun_hizmet_gorsel_url = 'assets/uploads/urunler/' . $newfileName;
                        
                        if ($id > 0) {
                            $oldData = $db->fetchOne("SELECT urun_hizmet_gorsel_url FROM Urun_Hizmet WHERE urun_hizmet_id = ?", [$id]);
                            if ($oldData && !empty($oldData['urun_hizmet_gorsel_url'])) {
                                $oldfile = __DIR__ . '/../' . $oldData['urun_hizmet_gorsel_url'];
                                if (file_exists($oldfile)) {
                                    unlink($oldfile);
                                }
                            }
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Dosya sunucuya taşınamadı! Klasör yazma izinlerini kontrol edin.']);
                        break;
                    }
                } elseif ($id == 0 && !empty($gorsel_url_mevcut)) {
                    // Çoğaltma: yeni dosya yüklenmedi, mevcut görseli kullan
                    $urun_hizmet_gorsel_url = $gorsel_url_mevcut;
                }

                if ($id > 0) {
                    // güncelleme
                    if ($urun_hizmet_gorsel_url) {
                        $sql = "UPDATE Urun_Hizmet SET
                                    urun_hizmet_tip = ?,
                                    urun_hizmet_kodu = ?,
                                    urun_hizmet_adi = ?,
                                    urun_hizmet_model = ?,
                                    urun_hizmet_aciklama = ?,
                                    urun_hizmet_kategori_id = ?,
                                    urun_hizmet_marka_id = ?,
                                    urun_hizmet_birim = ?,
                                    urun_hizmet_kritik_stok = ?,
                                    urun_hizmet_satis_fiyati = ?,
                                    urun_hizmet_taban_fiyati = ?,
                                    Urun_Hizmet_AlisFiyati = ?,
                                    BayiSatisFiyati = ?,
                                    HirdavatSatisFiyati = ?,
                                    FabrikaSatisFiyati = ?,
                                    urun_hizmet_kdv_id = ?,
                                    hizmetSuresi = ?,
                                    urun_hizmet_barkod = ?,
                                    urun_hizmet_serino = ?,
                                    urun_hizmet_gorsel_url = ?,
                                    urun_hizmet_sira_no = ?,
                                    urun_hizmet_durum = ?,
                                    urun_hizmet_para_birimi_id = ?,
                                    urun_hizmet_renk = ?,
                                    urun_hizmet_beden = ?,
                                    urun_hizmet_ust_id = ?,
                                    urun_hizmet_guncelleme_tarihi = GETDATE(),
                                    urun_hizmet_guncelleyen_kullanici_id = ?
                                WHERE urun_hizmet_id = ?";

                        $db->execute($sql, [
                            $urun_hizmet_tip, $urun_hizmet_kodu, $urun_hizmet_adi, $urun_hizmet_model,
                            $urun_hizmet_aciklama, $urun_hizmet_kategori_id, $urun_hizmet_marka_id,
                            $urun_hizmet_birim, $urun_hizmet_kritik_stok, $urun_hizmet_satis_fiyati,
                            $urun_hizmet_taban_fiyati, $Urun_Hizmet_AlisFiyati, $BayiSatisFiyati, $HirdavatSatisFiyati, $FabrikaSatisFiyati,
                            $urun_hizmet_kdv_id, $hizmetSuresi, $urun_hizmet_barkod, $urun_hizmet_serino, $urun_hizmet_gorsel_url,
                            $urun_hizmet_sira_no, $urun_hizmet_durum, $urun_hizmet_para_birimi_id,
                            $urun_hizmet_renk, $urun_hizmet_beden, $urun_hizmet_ust_id, $user['kullanici_id'], $id
                        ]);
                    } else {
                        $sql = "UPDATE Urun_Hizmet SET
                                    urun_hizmet_tip = ?,
                                    urun_hizmet_kodu = ?,
                                    urun_hizmet_adi = ?,
                                    urun_hizmet_model = ?,
                                    urun_hizmet_aciklama = ?,
                                    urun_hizmet_kategori_id = ?,
                                    urun_hizmet_marka_id = ?,
                                    urun_hizmet_birim = ?,
                                    urun_hizmet_kritik_stok = ?,
                                    urun_hizmet_satis_fiyati = ?,
                                    urun_hizmet_taban_fiyati = ?,
                                    Urun_Hizmet_AlisFiyati = ?,
                                    BayiSatisFiyati = ?,
                                    HirdavatSatisFiyati = ?,
                                    FabrikaSatisFiyati = ?,
                                    urun_hizmet_kdv_id = ?,
                                    hizmetSuresi = ?,
                                    urun_hizmet_barkod = ?,
                                    urun_hizmet_serino = ?,
                                    urun_hizmet_sira_no = ?,
                                    urun_hizmet_durum = ?,
                                    urun_hizmet_para_birimi_id = ?,
                                    urun_hizmet_renk = ?,
                                    urun_hizmet_beden = ?,
                                    urun_hizmet_ust_id = ?,
                                    urun_hizmet_guncelleme_tarihi = GETDATE(),
                                    urun_hizmet_guncelleyen_kullanici_id = ?
                                WHERE urun_hizmet_id = ?";

                        $db->execute($sql, [
                            $urun_hizmet_tip, $urun_hizmet_kodu, $urun_hizmet_adi, $urun_hizmet_model,
                            $urun_hizmet_aciklama, $urun_hizmet_kategori_id, $urun_hizmet_marka_id,
                            $urun_hizmet_birim, $urun_hizmet_kritik_stok, $urun_hizmet_satis_fiyati,
                            $urun_hizmet_taban_fiyati, $Urun_Hizmet_AlisFiyati, $BayiSatisFiyati, $HirdavatSatisFiyati, $FabrikaSatisFiyati,
                            $urun_hizmet_kdv_id, $hizmetSuresi, $urun_hizmet_barkod, $urun_hizmet_serino, $urun_hizmet_sira_no,
                            $urun_hizmet_durum, $urun_hizmet_para_birimi_id,
                            $urun_hizmet_renk, $urun_hizmet_beden, $urun_hizmet_ust_id, $user['kullanici_id'], $id
                        ]);
                    }

                    echo json_encode(['success' => true, 'message' => 'Ürün/Hizmet başarıyla güncellendi!']);
                } else {
                    // Ekleme
                    $sql = "INSERT INTO Urun_Hizmet (
                                urun_hizmet_tip, urun_hizmet_kodu, urun_hizmet_adi, urun_hizmet_model,
                                urun_hizmet_aciklama, urun_hizmet_kategori_id, urun_hizmet_marka_id,
                                urun_hizmet_birim, urun_hizmet_kritik_stok, urun_hizmet_satis_fiyati,
                                urun_hizmet_taban_fiyati, Urun_Hizmet_AlisFiyati, BayiSatisFiyati, HirdavatSatisFiyati, FabrikaSatisFiyati,
                                urun_hizmet_kdv_id, hizmetSuresi, urun_hizmet_barkod, urun_hizmet_serino, urun_hizmet_gorsel_url,
                                urun_hizmet_sira_no, urun_hizmet_durum, urun_hizmet_para_birimi_id,
                                urun_hizmet_renk, urun_hizmet_beden, urun_hizmet_ust_id,
                                urun_hizmet_olusturma_tarihi, urun_hizmet_olusturan_kullanici_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)";

                    $params = [
                        $urun_hizmet_tip, $urun_hizmet_kodu, $urun_hizmet_adi, $urun_hizmet_model,
                        $urun_hizmet_aciklama, $urun_hizmet_kategori_id, $urun_hizmet_marka_id,
                        $urun_hizmet_birim, $urun_hizmet_kritik_stok, $urun_hizmet_satis_fiyati,
                        $urun_hizmet_taban_fiyati, $Urun_Hizmet_AlisFiyati, $BayiSatisFiyati, $HirdavatSatisFiyati, $FabrikaSatisFiyati,
                        $urun_hizmet_kdv_id, $hizmetSuresi, $urun_hizmet_barkod, $urun_hizmet_serino, $urun_hizmet_gorsel_url,
                        $urun_hizmet_sira_no, $urun_hizmet_durum, $urun_hizmet_para_birimi_id,
                        $urun_hizmet_renk, $urun_hizmet_beden, $urun_hizmet_ust_id, $user['kullanici_id']
                    ];
                    
                    $result = $db->execute($sql, $params);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'message' => 'Ürün/Hizmet başarıyla eklendi!']);
                    } else {
                        // Hata detayini logla ve gçster
                        $errors = sqlsrv_errors();
                        $errorMsg = $errors ? $errors[0]['message'] : 'Bilinmeyen hata';
                        error_log('Urun iNSERT Hatasi: ' . print_r($errors, true));
                        error_log('Params: ' . print_r($params, true));
                        echo json_encode(['success' => false, 'message' => 'Kayit eklenirken hata: ' . $errorMsg]);
                    }
                }
                break;
                
            case 'toplu_fiyat_guncelle':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']);
                    break;
                }
                
                $kaynakKolon = $_POST['kaynak_kolon'] ?? '';
                $oranlar = $_POST['oranlar'] ?? [];
                
                $allowedColumns = [
                    'urun_hizmet_satis_fiyati', 'Urun_Hizmet_AlisFiyati', 'urun_hizmet_taban_fiyati',
                    'BayiSatisFiyati', 'HirdavatSatisFiyati', 'FabrikaSatisFiyati'
                ];
                
                if (!in_array($kaynakKolon, $allowedColumns)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz kaynak kolon.']);
                    break;
                }
                
                $kategori_id = $_POST['filter_kategori_id'] ?? '';
                $marka_id = $_POST['filter_marka_id'] ?? '';
                $durum = $_POST['filter_durum'] ?? '';
                $search = $_POST['filter_search'] ?? '';

                $whereConditions = ["1=1"];
                $params = [];
                
                if ($kategori_id) {
                    $whereConditions[] = "urun_hizmet_kategori_id = ?";
                    $params[] = $kategori_id;
                }
                if ($marka_id) {
                    $whereConditions[] = "urun_hizmet_marka_id = ?";
                    $params[] = $marka_id;
                }
                if ($durum !== '') {
                    $whereConditions[] = "urun_hizmet_durum = ?";
                    $params[] = $durum;
                }
                if ($search) {
                    $whereConditions[] = "(urun_hizmet_kodu LIKE ? OR urun_hizmet_adi LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                $islemTipleri = $_POST['islem'] ?? [];
                $sadeceBoslar = $_POST['sadece_boslar'] ?? [];
                
                $setClauses = [];
                foreach ($oranlar as $col => $oran) {
                    $oran = (float)$oran;
                    $islem = $islemTipleri[$col] ?? '';
                    $sadeceBos = isset($sadeceBoslar[$col]) && $sadeceBoslar[$col] == '1';
                    
                    if ($oran > 0 && $islem && in_array($col, $allowedColumns) && $col !== $kaynakKolon) {
                        $carpan = null;
                        if ($islem === 'indirim') {
                            $carpan = 1 - ($oran / 100);
                        } elseif ($islem === 'zam') {
                            $carpan = 1 + ($oran / 100);
                        }
                        
                        if ($carpan !== null) {
                            if ($sadeceBos) {
                                $setClauses[] = "$col = CASE WHEN ISNULL($col, 0) <= 0 THEN ($kaynakKolon * $carpan) ELSE $col END";
                            } else {
                                $setClauses[] = "$col = ($kaynakKolon * $carpan)";
                            }
                        }
                    }
                }
                
                if (empty($setClauses)) {
                    echo json_encode(['success' => false, 'message' => 'Lütfen en az bir hedef kolon için geçerli bir oran girin.']);
                    break;
                }
                
                $setString = implode(", ", $setClauses);
                $sql = "UPDATE Urun_Hizmet SET $setString, urun_hizmet_guncelleme_tarihi = GETDATE(), urun_hizmet_guncelleyen_kullanici_id = ? WHERE $whereClause";
                
                array_unshift($params, $user['kullanici_id']);
                
                $result = $db->execute($sql, $params);
                if ($result !== false) {
                    echo json_encode(['success' => true, 'message' => 'Fiyatlar başarıyla güncellendi!']);
                } else {
                    $err = sqlsrv_errors();
                    echo json_encode(['success' => false, 'message' => 'Hata: ' . ($err[0]['message'] ?? 'Bilinmeyen hata')]);
                }
                break;
                
            case 'check_usage':
                $id = intval($_POST['id'] ?? 0);
                if (!$id) { echo json_encode(['success' => false, 'message' => 'Geçersiz ID']); break; }
                $urun = $db->fetchOne("SELECT urun_hizmet_adi, urun_hizmet_kodu FROM Urun_Hizmet WHERE urun_hizmet_id = ?", [$id]);
                if (!$urun) { echo json_encode(['success' => false, 'message' => 'Ürün bulunamadı']); break; }
                $kullanimlar = [];
                $sayilar = (new UrunBirlestirmeHelper($db))->kullanimSayilari([$id])[$id] ?? [];
                foreach ($sayilar as $anahtar => $sayi) {
                    if ($sayi > 0) {
                        $kullanimlar[] = ['tablo' => UrunBirlestirmeHelper::TABLOLAR[$anahtar]['etiket'], 'sayi' => $sayi];
                    }
                }
                echo json_encode(['success' => true, 'kullanimlar' => $kullanimlar, 'urun' => $urun, 'kullaniliyor' => !empty($kullanimlar)]);
                break;

            case 'transfer_and_delete':
                // Kayıtlar ve stok hedef ürüne taşınır, ürün silinmez pasife alınır (Ürün Birleştirme sayfasından geri alınabilir)
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme Yetkiniz yok!']);
                    break;
                }
                $eskiId = intval($_POST['eski_id'] ?? 0);
                $yeniId = intval($_POST['yeni_id'] ?? 0);
                if (!$eskiId || !$yeniId || $eskiId === $yeniId) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz ürün seçimi.']);
                    break;
                }
                try {
                    (new UrunBirlestirmeHelper($db))->birlestir($yeniId, $eskiId, (int)$user['kullanici_id'], 'Ürün yönetimi silme ekranından birleştirildi');
                    echo json_encode(['success' => true, 'message' => 'Kayıtlar ve stok hedef ürüne taşındı, ürün pasife alındı. İşlem Ürün Birleştirme sayfasından geri alınabilir.']);
                } catch (Throwable $ex) {
                    echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
                }
                break;

            case 'delete':
                // Yetki kontrolç
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme Yetkiniz yok!']);
                    break;
                }
                
                $id = intval($_POST['id'] ?? 0);

                // Bağlı kaydı olan ürün silinmez (FK hatası sessizce yutuluyordu)
                $sayilar = (new UrunBirlestirmeHelper($db))->kullanimSayilari([$id])[$id] ?? [];
                if (array_sum($sayilar) > 0) {
                    echo json_encode(['success' => false, 'message' => 'Ürün başka kayıtlarda kullanıldığı için silinemez. Kayıtları başka bir ürüne taşıyın.']);
                    break;
                }

                $oldData = $db->fetchOne("SELECT urun_hizmet_gorsel_url FROM Urun_Hizmet WHERE urun_hizmet_id = ?", [$id]);

                if (!$db->execute("DELETE FROM Urun_Hizmet WHERE urun_hizmet_id = ?", [$id])) {
                    echo json_encode(['success' => false, 'message' => 'Ürün silinemedi. Bağlı kayıtları kontrol edin.']);
                    break;
                }

                // Görseli yalnız kayıt silindikten sonra kaldır
                if ($oldData && !empty($oldData['urun_hizmet_gorsel_url'])) {
                    $oldfile = __DIR__ . '/../' . $oldData['urun_hizmet_gorsel_url'];
                    if (file_exists($oldfile)) {
                        unlink($oldfile);
                    }
                }

                echo json_encode(['success' => true, 'message' => 'Ürün/Hizmet başarıyla silindi!']);
                break;
                
            case 'get_kategoriler':
                $sql = "SELECT kategori_id, kategori_adi FROM UrunHizmet_Kategoriler WHERE kategori_durum = 1 ORDER BY kategori_sira_no, kategori_adi";
                $UrunHizmet_Kategoriler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $UrunHizmet_Kategoriler]);
                break;
                
            case 'get_markalar':
                $sql = "SELECT marka_id, marka_adi FROM UrunHizmet_Markalar WHERE marka_durum = 1 ORDER BY marka_sira_no, marka_adi";
                $UrunHizmet_Markalar = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $UrunHizmet_Markalar]);
                break;
                
            case 'get_kdv':
                $sql = "SELECT kdv_id, kdv_adi, kdv_oran FROM UrunHizmet_KDVTanimlari WHERE kdv_durum = 1 ORDER BY kdv_sira_no, kdv_oran DESC";
                $kdvler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $kdvler]);
                break;

            case 'get_para_birimleri':
                $sql = "SELECT ParaBirimi_id, ParaBirimi_Kod, ParaBirimi_Ad, ParaBirimi_Simge FROM tanim_ParaBirimi WHERE Durum = 1 ORDER BY ParaBirimi_id";
                $paraBirimleri = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $paraBirimleri]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .urun-img-preview {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 4px;
            border: 1px solid #ddd;
            padding: 2px;
            background: white;
        }
        #gorselPreview {
            max-width: 100%;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
            padding: 5px;
            background: white;
        }
        .tip-badge {
            font-weight: 600;
        }
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
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
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
                    <!-- info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-box-seam"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Ürün</span>
                                    <span class="info-box-number" id="stat-urun">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-tools"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hizmet</span>
                                    <span class="info-box-number" id="stat-hizmet">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kritik Stok</span>
                                    <span class="info-box-number" id="stat-kritik">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Karti -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <!-- Tip -->
                                    <div class="col-md-2">
                                        <label class="form-label">Tip</label>
                                        <select class="form-select" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <option value="1">Ürün</option>
                                            <option value="2">Hizmet</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Kategori -->
                                    <div class="col-md-3">
                                        <label class="form-label">Kategori</label>
                                        <select class="form-select" name="kategori_id" id="filter_kategori_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Marka -->
                                    <div class="col-md-3">
                                        <label class="form-label">Marka</label>
                                        <select class="form-select" name="marka_id" id="filter_marka_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Durum -->
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Arama -->
                                    <div class="col-md-2">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kod, ad, model...">
                                    </div>
                                    
                                    <!-- Butonlar -->
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearfilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Ürün ve Hizmetler</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm me-1" onclick="exportToExcel()" id="btnExcelIndir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                </button>
                                <?php if ($permissions['can_edit']): ?>
                                <a href="urun-toplu-kdv-guncelle.php" class="btn btn-warning btn-sm me-1">
                                    <i class="bi bi-percent"></i> Toplu KDV Güncelle
                                </a>
                                <?php endif; ?>
                                <?php if ($permissions['can_add']): ?>
                                <a href="urun-toplu-import.php" class="btn btn-secondary btn-sm me-1">
                                    <i class="bi bi-file-earmark-spreadsheet"></i> Toplu Import
                                </a>
                                <?php endif; ?>
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm me-1" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Ekle
                                </button>
                                <?php endif; ?>
                                <?php if ($permissions['can_edit']): ?>
                                <button type="button" class="btn btn-warning btn-sm" onclick="$('#fiyatGuncelleModal').modal('show')">
                                    <i class="bi bi-currency-exchange"></i> Toplu Fiyat Güncelle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="urunTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>id</th>
                                        <th>Kod</th>
                                        <th>Ad</th>
                                        <th>Kategori</th>
                                        <th>Marka</th>
                                        <th>FAlışTL</th>
                                        <th>FAlışDolar</th>
                                        <th>FAlışEuro</th>
                                        <th>Alış Fiyatı</th>
                                        <th>Taban Fiyat</th>
                                        <th>Satış Fiyatı</th>
                                        <th>Bayi S.Fiyatı</th>
                                        <th>Hırdavat S.Fiyatı</th>
                                        <th>Fabrika S.Fiyatı</th>
                                        <th>Para Birimi</th>
                                        <th>KDV</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Modal -->
    <div class="modal fade" id="urunModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Ürün/Hizmet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="urunForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="urun_hizmet_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Tip <span class="text-danger">*</span></label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="urun_hizmet_tip" id="tip_urun" value="1" checked>
                                    <label class="form-check-label" for="tip_urun">
                                        <i class="bi bi-box-seam"></i> Ürün
                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="urun_hizmet_tip" id="tip_hizmet" value="2">
                                    <label class="form-check-label" for="tip_hizmet">
                                        <i class="bi bi-tools"></i> Hizmet
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_kodu" class="form-label">Kod</label>
                                    <input type="text" class="form-control" id="urun_hizmet_kodu" name="urun_hizmet_kodu">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_adi" class="form-label">Ad <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="urun_hizmet_adi" name="urun_hizmet_adi" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="urun_hizmet_ust_id" class="form-label">Üst Ürün</label>
                            <select class="form-select" id="urun_hizmet_ust_id" name="urun_hizmet_ust_id">
                                <option value="">Yok (Ana Ürün)</option>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_model" class="form-label">Model</label>
                                    <input type="text" class="form-control" id="urun_hizmet_model" name="urun_hizmet_model">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_barkod" class="form-label">Barkod</label>
                                    <input type="text" class="form-control" id="urun_hizmet_barkod" name="urun_hizmet_barkod">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_renk" class="form-label">Renk</label>
                                    <select class="form-select" id="urun_hizmet_renk" name="urun_hizmet_renk">
                                        <option value="">Seçiniz veya yazınız</option>
                                        <option value="Beyaz">Beyaz</option>
                                        <option value="Siyah">Siyah</option>
                                        <option value="Kırmızı">Kırmızı</option>
                                        <option value="Mavi">Mavi</option>
                                        <option value="Yeşil">Yeşil</option>
                                        <option value="Sarı">Sarı</option>
                                        <option value="Turuncu">Turuncu</option>
                                        <option value="Mor">Mor</option>
                                        <option value="Pembe">Pembe</option>
                                        <option value="Gri">Gri</option>
                                        <option value="Lacivert">Lacivert</option>
                                        <option value="Kahverengi">Kahverengi</option>
                                        <option value="Bej">Bej</option>
                                        <option value="Krem">Krem</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_beden" class="form-label">Beden</label>
                                    <select class="form-select" id="urun_hizmet_beden" name="urun_hizmet_beden">
                                        <option value="">Seçiniz veya yazınız</option>
                                        <option value="XS">XS</option>
                                        <option value="S">S</option>
                                        <option value="M">M</option>
                                        <option value="L">L</option>
                                        <option value="XL">XL</option>
                                        <option value="XXL">XXL</option>
                                        <option value="3XL">3XL</option>
                                        <?php for ($i = 6; $i <= 46; $i++): ?>
                                        <option value="<?= $i ?>"><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="urun_hizmet_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="urun_hizmet_aciklama" name="urun_hizmet_aciklama" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_kategori_id" class="form-label">Kategori</label>
                                    <select class="form-select" id="urun_hizmet_kategori_id" name="urun_hizmet_kategori_id">
                                        <option value="">Seçiniz</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_marka_id" class="form-label">Marka</label>
                                    <select class="form-select" id="urun_hizmet_marka_id" name="urun_hizmet_marka_id">
                                        <option value="">Seçiniz</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="urun_hizmet_birim" class="form-label">Birim</label>
                                    <select class="form-select" id="urun_hizmet_birim" name="urun_hizmet_birim">
                                        <option value="">Seçiniz</option>
                                        <option value="Adet">Adet</option>
                                        <option value="Kg">Kg</option>
                                        <option value="Gram">Gram</option>
                                        <option value="Litre">Litre</option>
                                        <option value="Metre">Metre</option>
                                        <option value="M2">M²</option>
                                        <option value="M3">M³</option>
                                        <option value="Paket">Paket</option>
                                        <option value="Koli">Koli</option>
                                        <option value="Saat">Saat</option>
                                        <option value="Gun">Gün</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="urun_hizmet_kritik_stok" class="form-label">Kritik Stok</label>
                                    <input type="number" class="form-control" id="urun_hizmet_kritik_stok" name="urun_hizmet_kritik_stok" value="0" min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="urun_hizmet_para_birimi_id" class="form-label">Para Birimi <span class="text-danger">*</span></label>
                                    <select class="form-select" id="urun_hizmet_para_birimi_id" name="urun_hizmet_para_birimi_id" required>
                                        <option value="">Seçiniz</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="Urun_Hizmet_AlisFiyati" class="form-label">Alış Fiyatı</label>
                                    <input type="number" step="0.01" class="form-control" id="Urun_Hizmet_AlisFiyati" name="Urun_Hizmet_AlisFiyati" value="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="urun_hizmet_taban_fiyati" class="form-label">Taban Fiyat</label>
                                    <input type="number" step="0.01" class="form-control" id="urun_hizmet_taban_fiyati" name="urun_hizmet_taban_fiyati" value="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="urun_hizmet_satis_fiyati" class="form-label">Satış Fiyatı</label>
                                    <input type="number" step="0.01" class="form-control" id="urun_hizmet_satis_fiyati" name="urun_hizmet_satis_fiyati" value="0">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="BayiSatisFiyati" class="form-label">Bayi Satış Fiyatı</label>
                                    <input type="number" step="0.01" class="form-control" id="BayiSatisFiyati" name="BayiSatisFiyati" value="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="HirdavatSatisFiyati" class="form-label">Hırdavat Satış Fiyatı</label>
                                    <input type="number" step="0.01" class="form-control" id="HirdavatSatisFiyati" name="HirdavatSatisFiyati" value="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="FabrikaSatisFiyati" class="form-label">Fabrika Satış Fiyatı</label>
                                    <input type="number" step="0.01" class="form-control" id="FabrikaSatisFiyati" name="FabrikaSatisFiyati" value="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_kdv_id" class="form-label">KDV <span class="text-danger">*</span></label>
                                    <select class="form-select" id="urun_hizmet_kdv_id" name="urun_hizmet_kdv_id" required>
                                        <option value="">Seçiniz</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label d-block">Seri No Takipli</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="urun_hizmet_serino" name="urun_hizmet_serino">
                                        <label class="form-check-label" for="urun_hizmet_serino">
                                            Bu ürün seri no ile takip edilsin
                                            <small class="text-muted d-block">Stok giriş/çıkışta seri no zorunlu olur</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="urun_hizmet_gorsel" class="form-label">Görsel</label>
                            <input type="file" class="form-control" id="urun_hizmet_gorsel" name="urun_hizmet_gorsel" accept="image/*">
                            <small class="text-muted">Maksimum 5MB</small>
                            <img id="gorselPreview" style="display: none;">
                            <input type="hidden" id="urun_hizmet_gorsel_url_mevcut" name="urun_hizmet_gorsel_url_mevcut">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_sira_no" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="urun_hizmet_sira_no" name="urun_hizmet_sira_no" value="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label d-block">Durum</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="urun_hizmet_durum" name="urun_hizmet_durum" checked>
                                        <label class="form-check-label" for="urun_hizmet_durum">Aktif</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="button" class="btn btn-info text-white" id="btnCogalt" style="display:none" onclick="cogaltUrun()">
                            <i class="bi bi-copy"></i> Ürün Çoğalt
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Toplu Fiyat Güncelle Modal -->
    <div class="modal fade" id="fiyatGuncelleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="fiyatGuncelleForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Toplu Fiyat Güncelle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Bu işlem <strong>aktif filtreye uyan tüm ürünleri</strong> etkileyecektir! (Filtre yoksa tüm ürünler)
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kaynak Fiyat Kolonu</label>
                            <select class="form-select" name="kaynak_kolon" required>
                                <option value="urun_hizmet_satis_fiyati">Satış Fiyatı</option>
                                <option value="Urun_Hizmet_AlisFiyati">Alış Fiyatı</option>
                                <option value="urun_hizmet_taban_fiyati">Taban Fiyat</option>
                            </select>
                            <div class="form-text">Diğer tüm kolonlar bu değere göre hesaplanacaktır. Boş bırakılan kolonlar güncellenmez.</div>
                        </div>
                        
                        <div class="card card-body bg-light">
                            <h6>Hedef Kolonlar ve İşlemler</h6>
                            <p class="small text-muted mb-3">Örn: Satış fiyatı üzerinden %10 indirim yapmak için "İndirim (-%)" seçip "10" yazın.</p>
                            
                            <div class="row mb-2 align-items-center">
                                <div class="col-md-3"><label class="form-label mb-0">Alış Fiyatı</label></div>
                                <div class="col-md-3">
                                    <select class="form-select" name="islem[Urun_Hizmet_AlisFiyati]">
                                        <option value="">İşlem Seçin</option>
                                        <option value="indirim">İndirim (-%)</option>
                                        <option value="zam">Zam (+%)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <div class="input-group">
                                        <input type="number" step="0.01" class="form-control" name="oranlar[Urun_Hizmet_AlisFiyati]" placeholder="Örn: 10">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch mt-2 mt-md-0">
                                        <input class="form-check-input" type="checkbox" name="sadece_boslar[Urun_Hizmet_AlisFiyati]" id="sb_alis" value="1">
                                        <label class="form-check-label" for="sb_alis" style="font-size: 0.85rem;" title="Sadece fiyatı 0 veya boş olanları günceller">Sadece 0 olanlar</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-2 align-items-center">
                                <div class="col-md-3"><label class="form-label mb-0">Taban Fiyat</label></div>
                                <div class="col-md-3">
                                    <select class="form-select" name="islem[urun_hizmet_taban_fiyati]">
                                        <option value="">İşlem Seçin</option>
                                        <option value="indirim">İndirim (-%)</option>
                                        <option value="zam">Zam (+%)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <div class="input-group">
                                        <input type="number" step="0.01" class="form-control" name="oranlar[urun_hizmet_taban_fiyati]" placeholder="Örn: 10">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch mt-2 mt-md-0">
                                        <input class="form-check-input" type="checkbox" name="sadece_boslar[urun_hizmet_taban_fiyati]" id="sb_taban" value="1">
                                        <label class="form-check-label" for="sb_taban" style="font-size: 0.85rem;" title="Sadece fiyatı 0 veya boş olanları günceller">Sadece 0 olanlar</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-2 align-items-center">
                                <div class="col-md-3"><label class="form-label mb-0">Satış Fiyatı</label></div>
                                <div class="col-md-3">
                                    <select class="form-select" name="islem[urun_hizmet_satis_fiyati]">
                                        <option value="">İşlem Seçin</option>
                                        <option value="indirim">İndirim (-%)</option>
                                        <option value="zam">Zam (+%)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <div class="input-group">
                                        <input type="number" step="0.01" class="form-control" name="oranlar[urun_hizmet_satis_fiyati]" placeholder="Örn: 10">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch mt-2 mt-md-0">
                                        <input class="form-check-input" type="checkbox" name="sadece_boslar[urun_hizmet_satis_fiyati]" id="sb_satis" value="1">
                                        <label class="form-check-label" for="sb_satis" style="font-size: 0.85rem;" title="Sadece fiyatı 0 veya boş olanları günceller">Sadece 0 olanlar</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-2 align-items-center">
                                <div class="col-md-3"><label class="form-label mb-0">Bayi Satış Fiyatı</label></div>
                                <div class="col-md-3">
                                    <select class="form-select" name="islem[BayiSatisFiyati]">
                                        <option value="">İşlem Seçin</option>
                                        <option value="indirim">İndirim (-%)</option>
                                        <option value="zam">Zam (+%)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <div class="input-group">
                                        <input type="number" step="0.01" class="form-control" name="oranlar[BayiSatisFiyati]" placeholder="Örn: 10">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch mt-2 mt-md-0">
                                        <input class="form-check-input" type="checkbox" name="sadece_boslar[BayiSatisFiyati]" id="sb_bayi" value="1">
                                        <label class="form-check-label" for="sb_bayi" style="font-size: 0.85rem;" title="Sadece fiyatı 0 veya boş olanları günceller">Sadece 0 olanlar</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-2 align-items-center">
                                <div class="col-md-3"><label class="form-label mb-0">Hırdavat S. Fiyatı</label></div>
                                <div class="col-md-3">
                                    <select class="form-select" name="islem[HirdavatSatisFiyati]">
                                        <option value="">İşlem Seçin</option>
                                        <option value="indirim">İndirim (-%)</option>
                                        <option value="zam">Zam (+%)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <div class="input-group">
                                        <input type="number" step="0.01" class="form-control" name="oranlar[HirdavatSatisFiyati]" placeholder="Örn: 10">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch mt-2 mt-md-0">
                                        <input class="form-check-input" type="checkbox" name="sadece_boslar[HirdavatSatisFiyati]" id="sb_hirdavat" value="1">
                                        <label class="form-check-label" for="sb_hirdavat" style="font-size: 0.85rem;" title="Sadece fiyatı 0 veya boş olanları günceller">Sadece 0 olanlar</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-2 align-items-center">
                                <div class="col-md-3"><label class="form-label mb-0">Fabrika S. Fiyatı</label></div>
                                <div class="col-md-3">
                                    <select class="form-select" name="islem[FabrikaSatisFiyati]">
                                        <option value="">İşlem Seçin</option>
                                        <option value="indirim">İndirim (-%)</option>
                                        <option value="zam">Zam (+%)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <div class="input-group">
                                        <input type="number" step="0.01" class="form-control" name="oranlar[FabrikaSatisFiyati]" placeholder="Örn: 10">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check form-switch mt-2 mt-md-0">
                                        <input class="form-check-input" type="checkbox" name="sadece_boslar[FabrikaSatisFiyati]" id="sb_fabrika" value="1">
                                        <label class="form-check-label" for="sb_fabrika" style="font-size: 0.85rem;" title="Sadece fiyatı 0 veya boş olanları günceller">Sadece 0 olanlar</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-currency-exchange"></i> Fiyatları Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Transfer Modal -->
    <div class="modal fade" id="transferModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-arrow-left-right"></i> Kayıtları Taşı ve Birleştir</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-3">
                        <strong><i class="bi bi-exclamation-triangle"></i> <span id="tr_urun_adi"></span></strong> ürünü aşağıdaki tablolarda kullanılıyor:
                        <ul id="tr_kullanimlar" class="mt-2 mb-0"></ul>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Bu kayıtları taşıyacağınız hedef ürünü seçin: <span class="text-danger">*</span></label>
                        <select class="form-select" id="tr_yeni_id" style="width:100%">
                            <option value="">Ürün arayın...</option>
                        </select>
                        <div class="form-text text-danger">Seçilen ürüne tüm bağlı kayıtlar ve stok aktarılacak, ardından mevcut ürün pasife alınacaktır.</div>
                    </div>
                    <input type="hidden" id="tr_eski_id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-danger" id="btnTransferSil">
                        <i class="bi bi-arrow-left-right"></i> Taşı ve Birleştir
                    </button>
                </div>
            </div>
        </div>
    </div>

    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentfilters = {};
        const modal = new bootstrap.Modal(document.getElementById('urunModal'));
        
        // Sayfa yetkileri (PHP'den)
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-urun').text(response.data.toplam_urun);
                    $('#stat-hizmet').text(response.data.toplam_hizmet);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-kritik').text(response.data.kritik_stok);
                }
            });
        }

        function exportToExcel() {
            const btn = $('#btnExcelIndir');
            btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Hazırlanıyor...');

            $.post('', { action: 'excel', ...currentfilters }, function(response) {
                if (!response.success || !response.data.length) {
                    showToast('Dışa aktarılacak veri bulunamadı.', 'warning');
                    btn.prop('disabled', false).html('<i class="bi bi-file-earmark-excel"></i> Excel İndir');
                    return;
                }

                const tipMap = { '1': 'Ürün', '2': 'Hizmet' };

                const rows = response.data.map(r => ({
                    'ID'                : r.urun_hizmet_id,
                    'Tip'               : tipMap[r.urun_hizmet_tip] || r.urun_hizmet_tip,
                    'Kod'               : r.urun_hizmet_kodu || '',
                    'Ad'                : r.urun_hizmet_adi || '',
                    'Model'             : r.urun_hizmet_model || '',
                    'Kategori'          : r.kategori_adi || '',
                    'Marka'             : r.marka_adi || '',
                    'Alış Fiyatı (TL)'  : parseFloat(r.FAlisTL || 0),
                    'Alış Fiyatı ($)'   : parseFloat(r.FAlisDolar || 0),
                    'Alış Fiyatı (€)'   : parseFloat(r.FAlisEuro || 0),
                    'Alış Fiyatı'       : parseFloat(r.Urun_Hizmet_AlisFiyati || 0),
                    'Taban Fiyat'       : parseFloat(r.urun_hizmet_taban_fiyati || 0),
                    'Satış Fiyatı'      : parseFloat(r.urun_hizmet_satis_fiyati || 0),
                    'Bayi Satış Fiyatı' : parseFloat(r.BayiSatisFiyati || 0),
                    'Hırdavat S.Fiyatı' : parseFloat(r.HirdavatSatisFiyati || 0),
                    'Fabrika S.Fiyatı'  : parseFloat(r.FabrikaSatisFiyati || 0),
                    'Para Birimi'       : r.ParaBirimi_Kod || 'TRY',
                    'KDV Adı'           : r.kdv_adi || '',
                    'KDV Oranı (%)'     : parseFloat(r.kdv_oran || 0),
                    'Birim'             : r.urun_hizmet_birim || '',
                    'Kritik Stok'       : parseInt(r.urun_hizmet_kritik_stok || 0),
                    'Barkod'            : r.urun_hizmet_barkod || '',
                    'Durum'             : r.urun_hizmet_durum == 1 ? 'Aktif' : 'Pasif',
                    'Oluşturma Tarihi'  : r.urun_hizmet_olusturma_tarihi || ''
                }));

                const ws   = XLSX.utils.json_to_sheet(rows);
                const wb   = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Ürün-Hizmet');

                // Sütun genişliklerini ayarla
                const colWidths = Object.keys(rows[0]).map(k => ({ wch: Math.max(k.length, 14) }));
                ws['!cols'] = colWidths;

                const tarih = new Date().toISOString().slice(0, 10);
                XLSX.writeFile(wb, `urun-hizmet-listesi_${tarih}.xlsx`);

                showToast(`${rows.length} kayıt Excel'e aktarıldı.`, 'success');
            }, 'json')
            .fail(() => showToast('Veri alınamadı.', 'error'))
            .always(() => {
                btn.prop('disabled', false).html('<i class="bi bi-file-earmark-excel"></i> Excel İndir');
            });
        }
        
        function initDataTable() {
            table = $('#urunTable').DataTable({
                serverSide: true,
                processing: true,
                dom: 'lrtip',
                pageLength: 25,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: d => Object.assign(d, { action: 'list' }, currentfilters),
                    error: () => showToast('Liste yüklenemedi.', 'error')
                },
                columns: [
                    { data: 'urun_hizmet_id' },
                    {
                        data: 'urun_hizmet_kodu', 
                        defaultContent: '-',
                        render: function(data, type) {
                            if (type === 'display' && data && data.length > 10) {
                                return '<span title="' + data + '">' + data.substr(0, 10) + '...</span>';
                            }
                            return data;
                        }
                    },
                    { data: 'urun_hizmet_adi' },
                    { data: 'kategori_adi', defaultContent: '-' },
                    { data: 'marka_adi', defaultContent: '-' },
                    {
                        data: 'FAlisTL',
                        orderable: false,
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = '₺';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    {
                        data: 'FAlisDolar',
                        orderable: false,
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = '$';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    {
                        data: 'FAlisEuro',
                        orderable: false,
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = '€';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    { 
                        data: 'Urun_Hizmet_AlisFiyati',
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = row.ParaBirimi_Simge || '₺';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    { 
                        data: 'urun_hizmet_taban_fiyati',
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = row.ParaBirimi_Simge || '₺';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    { 
                        data: 'urun_hizmet_satis_fiyati',
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = row.ParaBirimi_Simge || '₺';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    { 
                        data: 'BayiSatisFiyati',
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = row.ParaBirimi_Simge || '₺';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    { 
                        data: 'HirdavatSatisFiyati',
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = row.ParaBirimi_Simge || '₺';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    { 
                        data: 'FabrikaSatisFiyati',
                        render: (data, type, row) => {
                            if (type === 'display' || type === 'filter') {
                                const simge = row.ParaBirimi_Simge || '₺';
                                return parseFloat(data || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
                            }
                            return parseFloat(data || 0);
                        }
                    },
                    {
                        data: 'ParaBirimi_Kod',
                        defaultContent: 'TRY',
                        render: data => data || 'TRY'
                    },
                    { 
                        data: null,
                        render: data => `%${data.kdv_oran}`
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: function(data) {
                            let buttons = '';
                            
                            if (permissions.canEdit) {
                                buttons += `
                                    <button class="btn btn-sm btn-warning" onclick="editUrun(${data.urun_hizmet_id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                `;
                            }
                            
                            if (permissions.canDelete) {
                                buttons += `
                                    <button class="btn btn-sm btn-danger" onclick="deleteUrun(${data.urun_hizmet_id})" title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                `;
                            }
                            
                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[2, 'asc']]
            });
        }
        
        function loadKategoriler(targetSelect = '#urun_hizmet_kategori_id', selectedVal = '') {
            return $.post('', { action: 'get_kategoriler' }).then(response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(k => select.append(`<option value="${k.kategori_id}">${k.kategori_adi}</option>`));
                    select.val(selectedVal || '').trigger('change');
                }
            });
        }

        function loadMarkalar(targetSelect = '#urun_hizmet_marka_id', selectedVal = '') {
            return $.post('', { action: 'get_markalar' }).then(response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(m => select.append(`<option value="${m.marka_id}">${m.marka_adi}</option>`));
                    select.val(selectedVal || '').trigger('change');
                }
            });
        }

        function loadKDV(selectedVal = '') {
            return $.post('', { action: 'get_kdv' }).then(response => {
                if (response.success) {
                    const select = $('#urun_hizmet_kdv_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(kdv => select.append(`<option value="${kdv.kdv_id}">${kdv.kdv_adi} (%${kdv.kdv_oran})</option>`));
                    select.val(selectedVal || '').trigger('change');
                }
            });
        }

        function loadParaBirimleri(selectedVal = '') {
            return $.post('', { action: 'get_para_birimleri' }).then(response => {
                if (response.success) {
                    const select = $('#urun_hizmet_para_birimi_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(pb => select.append(`<option value="${pb.ParaBirimi_id}">${pb.ParaBirimi_Kod} - ${pb.ParaBirimi_Ad} (${pb.ParaBirimi_Simge})</option>`));
                    select.val(selectedVal || '').trigger('change');
                }
            });
        }
        
        function openModal() {
            document.getElementById('modalTitle').textContent = 'Yeni Ürün/Hizmet';
            document.getElementById('urunForm').reset();
            document.getElementById('urun_hizmet_id').value = '';
            document.getElementById('urun_hizmet_durum').checked = true;
            document.getElementById('gorselPreview').style.display = 'none';
            document.getElementById('urun_hizmet_gorsel_url_mevcut').value = '';
            $('#urun_hizmet_renk').val(null).trigger('change');
            $('#urun_hizmet_beden').val(null).trigger('change');
            $('#urun_hizmet_ust_id').val(null).trigger('change');
            $('#btnCogalt').hide();
            loadKategoriler();
            loadMarkalar();
            loadKDV();
            // Para birimi yüklendikten sonra TRY'yi seç
            loadParaBirimleri().then(() => {
                $('#urun_hizmet_para_birimi_id option').each(function() {
                    if ($(this).text().indexOf('TRY') === 0) {
                        $(this).prop('selected', true);
                        return false;
                    }
                });
            });
            modal.show();
        }

        function cogaltUrun() {
            document.getElementById('modalTitle').textContent = 'Yeni Ürün/Hizmet (Kopya)';
            document.getElementById('urun_hizmet_id').value = '';
            document.getElementById('urun_hizmet_kodu').value = '';
            document.getElementById('urun_hizmet_barkod').value = '';
            $('#btnCogalt').hide();
        }
        
        document.getElementById('urun_hizmet_gorsel').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = e => {
                    const preview = document.getElementById('gorselPreview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
        
        function editUrun(id) {
            $.post('', { action: 'get', id: id }, response => {
                if (response.success) {
                    const data = response.data;
                    document.getElementById('modalTitle').textContent = 'Ürün/Hizmet Düzenle';
                    document.getElementById('urun_hizmet_id').value = data.urun_hizmet_id;
                    $('#btnCogalt').show();
                    
                    if (data.urun_hizmet_tip == 1) {
                        document.getElementById('tip_urun').checked = true;
                    } else {
                        document.getElementById('tip_hizmet').checked = true;
                    }
                    
                    document.getElementById('urun_hizmet_kodu').value = data.urun_hizmet_kodu || '';
                    document.getElementById('urun_hizmet_adi').value = data.urun_hizmet_adi;
                    document.getElementById('urun_hizmet_model').value = data.urun_hizmet_model || '';
                    document.getElementById('urun_hizmet_aciklama').value = data.urun_hizmet_aciklama || '';
                    $('#urun_hizmet_birim').val((data.urun_hizmet_birim || '').trim()).trigger('change');
                    document.getElementById('urun_hizmet_kritik_stok').value = data.urun_hizmet_kritik_stok || 0;
                    document.getElementById('Urun_Hizmet_AlisFiyati').value = data.Urun_Hizmet_AlisFiyati || 0;
                    document.getElementById('urun_hizmet_taban_fiyati').value = data.urun_hizmet_taban_fiyati || 0;
                    document.getElementById('urun_hizmet_satis_fiyati').value = data.urun_hizmet_satis_fiyati || 0;
                    document.getElementById('BayiSatisFiyati').value = data.BayiSatisFiyati || 0;
                    document.getElementById('HirdavatSatisFiyati').value = data.HirdavatSatisFiyati || 0;
                    document.getElementById('FabrikaSatisFiyati').value = data.FabrikaSatisFiyati || 0;
                    document.getElementById('urun_hizmet_barkod').value = data.urun_hizmet_barkod || '';
                    document.getElementById('urun_hizmet_serino').checked = data.urun_hizmet_serino == 1;
                    document.getElementById('urun_hizmet_sira_no').value = data.urun_hizmet_sira_no || 0;
                    document.getElementById('urun_hizmet_durum').checked = data.urun_hizmet_durum == 1;
                    
                    loadKategoriler('#urun_hizmet_kategori_id', data.urun_hizmet_kategori_id || '');
                    loadMarkalar('#urun_hizmet_marka_id', data.urun_hizmet_marka_id || '');
                    loadKDV(data.urun_hizmet_kdv_id || '');
                    loadParaBirimleri(data.urun_hizmet_para_birimi_id || '');

                    // Renk: listede varsa seç, yoksa tags ile ekle
                    const renkSelect = $('#urun_hizmet_renk');
                    const renkVal = data.urun_hizmet_renk || '';
                    if (renkVal && !renkSelect.find(`option[value="${renkVal}"]`).length) {
                        renkSelect.append(new Option(renkVal, renkVal, true, true));
                    }
                    renkSelect.val(renkVal).trigger('change');

                    // Beden: listede varsa seç, yoksa tags ile ekle
                    const bedenSelect = $('#urun_hizmet_beden');
                    const bedenVal = data.urun_hizmet_beden || '';
                    if (bedenVal && !bedenSelect.find(`option[value="${bedenVal}"]`).length) {
                        bedenSelect.append(new Option(bedenVal, bedenVal, true, true));
                    }
                    bedenSelect.val(bedenVal).trigger('change');

                    // Üst Ürün
                    const ustSelect = $('#urun_hizmet_ust_id');
                    ustSelect.find('option:not(:first)').remove();
                    if (data.urun_hizmet_ust_id) {
                        const ustLabel = (data.ust_urun_kodu ? data.ust_urun_kodu + ' - ' : '') + (data.ust_urun_adi || '');
                        ustSelect.append(new Option(ustLabel, data.urun_hizmet_ust_id, true, true));
                    }
                    ustSelect.val(data.urun_hizmet_ust_id || null).trigger('change');

                    const preview = document.getElementById('gorselPreview');
                    if (data.urun_hizmet_gorsel_url) {
                        preview.src = '/Admin/' + data.urun_hizmet_gorsel_url;
                        preview.style.display = 'block';
                    } else {
                        preview.style.display = 'none';
                    }
                    document.getElementById('urun_hizmet_gorsel_url_mevcut').value = data.urun_hizmet_gorsel_url || '';
                    
                    modal.show();
                } else {
                    showToast(response.message, 'error');
                }
            });
        }
        
        function deleteUrun(id) {
            confirmAction(
                'Bu ürün/hizmeti silmek istediğinizden emin misiniz?',
                'Başka tablolarda kullanılıyorsa taşıma ekranı açılacaktır.',
                function() {
                    $.post('', { action: 'check_usage', id: id }, function(response) {
                        if (!response.success) { showError('Hata!', response.message); return; }

                        if (!response.kullaniliyor) {
                            $.post('', { action: 'delete', id: id }, function(res) {
                                if (res.success) {
                                    showSuccess('Silindi!', res.message);
                                    table.ajax.reload();
                                    loadStats();
                                } else {
                                    showError('Hata!', res.message);
                                }
                            }, 'json');
                        } else {
                            $('#tr_eski_id').val(id);
                            $('#tr_urun_adi').text(response.urun.urun_hizmet_adi + ' (' + response.urun.urun_hizmet_kodu + ')');
                            const ul = $('#tr_kullanimlar').empty();
                            response.kullanimlar.forEach(k => {
                                ul.append(`<li>${k.tablo}: <strong>${k.sayi} kayıt</strong></li>`);
                            });
                            if ($('#tr_yeni_id').hasClass('select2-hidden-accessible')) {
                                $('#tr_yeni_id').select2('destroy');
                            }
                            $('#tr_yeni_id').html('<option value="">Ürün arayın...</option>').select2({
                                theme: 'bootstrap-5',
                                width: '100%',
                                dropdownParent: $('#transferModal'),
                                placeholder: 'Ürün adı veya kodu ile arayın...',
                                allowClear: true,
                                minimumInputLength: 1,
                                language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' },
                                ajax: {
                                    url: '',
                                    type: 'POST',
                                    dataType: 'json',
                                    delay: 300,
                                    data: params => ({ action: 'urun_ara', q: params.term, haric_id: id }),
                                    processResults: data => ({ results: data.success ? data.data : [] })
                                }
                            });
                            new bootstrap.Modal(document.getElementById('transferModal')).show();
                        }
                    }, 'json').fail(() => showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'));
                }
            );
        }

        document.getElementById('btnTransferSil').addEventListener('click', function() {
            const eskiId = $('#tr_eski_id').val();
            const yeniId = $('#tr_yeni_id').val();
            if (!yeniId) { showToast('Lütfen hedef ürünü seçin.', 'warning'); return; }

            confirmAction(
                'Tüm kayıtlar ve stok seçilen ürüne taşınacak, bu ürün pasife alınacak!',
                'İşlem Ürün Birleştirme sayfasındaki geçmişten geri alınabilir.',
                function() {
                    bootstrap.Modal.getInstance(document.getElementById('transferModal')).hide();
                    $.post('', { action: 'transfer_and_delete', eski_id: eskiId, yeni_id: yeniId }, function(res) {
                        if (res.success) {
                            showSuccess('Başarılı!', res.message);
                            table.ajax.reload();
                            loadStats();
                        } else {
                            showError('Hata!', res.message);
                        }
                    }, 'json').fail(() => showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'));
                }
            );
        });
        
        $('#urunForm').on('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: response => {
                    if (response.success) {
                        showToast(response.message, 'success');
                        modal.hide();
                        table.ajax.reload();
                        loadStats();
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr.responseText);
                    showToast('Sunucu hatasi: ' + error, 'error');
                }
            });
        });
        
        $('#fiyatGuncelleForm').on('submit', function(e) {
            e.preventDefault();
            
            // Onay al
            Swal.fire({
                title: 'Emin misiniz?',
                text: 'Fiyatlar mevcut filtre koşullarına göre yeniden hesaplanıp GÜNCELLENECEKTİR. Bu işlem geri alınamaz!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Evet, Güncelle',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData(this);
                    formData.append('action', 'toplu_fiyat_guncelle');
                    
                    // Aktif filtreleri forma ekle
                    Object.keys(currentfilters).forEach(key => {
                        formData.append('filter_' + key, currentfilters[key]);
                    });
                    
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: response => {
                            if (response.success) {
                                showToast(response.message, 'success');
                                $('#fiyatGuncelleModal').modal('hide');
                                table.ajax.reload();
                            } else {
                                showError('Hata!', response.message);
                            }
                        },
                        error: function() {
                            showError('Bağlantı Hatası', 'Sunucuya ulaşılamıyor.');
                        }
                    });
                }
            });
        });
        
        $('select[name="kaynak_kolon"]').on('change', function() {
            let seciliKolon = $(this).val();
            
            // Tüm alanları aktif et
            $('#fiyatGuncelleForm').find('select[name^="islem["], input[name^="oranlar["]').prop('disabled', false);
            
            if (seciliKolon) {
                // Sadece kaynak kolonla aynı olan hedef kolonu pasif yapıp değerlerini sıfırla
                let targetSelect = $('select[name="islem[' + seciliKolon + ']"]');
                let targetInput = $('input[name="oranlar[' + seciliKolon + ']"]');
                
                targetSelect.prop('disabled', true).val('');
                targetInput.prop('disabled', true).val('');
            }
        });

        $('#fiyatGuncelleModal').on('show.bs.modal', function () {
            $('select[name="kaynak_kolon"]').trigger('change');
        });

        $(document).ready(() => {
            loadStats();
            initDataTable();

            // Üst Ürün - Select2 AJAX arama
            $('#urun_hizmet_ust_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#urunModal'),
                placeholder: 'Üst ürün arayın...',
                allowClear: true,
                width: '100%',
                minimumInputLength: 1,
                language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' },
                ajax: {
                    url: '',
                    type: 'POST',
                    dataType: 'json',
                    delay: 300,
                    data: params => ({ action: 'urun_ara', q: params.term, haric_id: $('#urun_hizmet_id').val() || 0 }),
                    processResults: data => ({ results: data.success ? data.data : [] })
                }
            });

            // Renk ve Beden - Select2 tags (serbest giriş)
            $('#urun_hizmet_renk').select2({
                theme: 'bootstrap-5',
                tags: true,
                dropdownParent: $('#urunModal'),
                placeholder: 'Seçiniz veya yazınız',
                allowClear: true,
                width: '100%'
            });
            $('#urun_hizmet_beden').select2({
                theme: 'bootstrap-5',
                tags: true,
                dropdownParent: $('#urunModal'),
                placeholder: 'Seçiniz veya yazınız',
                allowClear: true,
                width: '100%'
            });

            // Filtre dropdown'larini doldur
            loadKategoriler('#filter_kategori_id');
            loadMarkalar('#filter_marka_id');
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                // Filtreleri topla
                currentfilters = {
                    tip: $('#filter_tip').val(),
                    kategori_id: $('#filter_kategori_id').val(),
                    marka_id: $('#filter_marka_id').val(),
                    durum: $('#filter_durum').val(),
                    search_text: $('#filter_search').val()
                };
                
                // Boş değerleri kaldir
                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });
                
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip').val('').trigger('change.select2');
                $('#filter_kategori_id').val('').trigger('change.select2');
                $('#filter_marka_id').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                currentfilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });
    </script>
</body>
</html>
