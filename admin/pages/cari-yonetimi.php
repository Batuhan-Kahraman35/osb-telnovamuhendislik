<?php
/**
 * Admin Panel - Cari Yönetimi
 * 
 * Cari (Müşteri/Tedarikçi) kayıtlarının yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
require_once __DIR__ . '/../includes/BolgeYetkiHelper.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolü
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Mevcut sayfanin bilgilerini al
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
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Cari Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Ülke Listesini Getir (Filtre için)
$ulkeler = $db->fetchAll("SELECT UlkeId, UlkeAdi FROM Adres_Ulkeler ORDER BY UlkeAdi");
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad, cari_tipi_renk FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");

// Şehir ve İlçe Listeleri (Filtre için)
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi, UlkeId FROM Adres_Sehirler ORDER BY SehirAdi");
$ilceler = $db->fetchAll("SELECT ilceId, SehirId, IlceAdi FROM Adres_Ilceler ORDER BY IlceAdi");

// Kullanıcı Listesi (Filtre için)
$kullanicilar = $db->fetchAll("SELECT kullanici_id, kullanici_ad, kullanici_soyad FROM Kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad");

// Cari devri yetkisi (Administrator ve Satış Müdürü)
$devirYetkisi = CariDevirHelper::devirYetkisiVarMi($user['departman_id'] ?? null);

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'getSehirler':
                // Şehir listesini getir
                echo json_encode(['success' => true, 'data' => $sehirler]);
                break;
                
            case 'getIlceler':
                // Belirli bir şehre ait ilçeleri getir
                $sehirId = $_POST['sehir_id'] ?? 0;
                $filteredIlceler = array_filter($ilceler, function($ilce) use ($sehirId) {
                    return $ilce['SehirId'] == $sehirId;
                });
                echo json_encode(['success' => true, 'data' => array_values($filteredIlceler)]);
                break;
                
            case 'stats':
                // Filtre parametreleri
                $fSearch = $_POST['search'] ?? '';
                $fTip = $_POST['tip'] ?? '';
                $fDurum = $_POST['durum'] ?? '';
                $fUlke = $_POST['ulke'] ?? '';
                $fSehir = $_POST['sehir'] ?? '';
                $fIlce = $_POST['ilce'] ?? '';
                $fSsaAktif = $_POST['ssa_aktif'] ?? '';
                $fOlusturanKullanici = $_POST['olusturan_kullanici'] ?? '';
                $fEnTeklifVeren = $_POST['en_teklif_veren'] ?? '';
                $fTarihBaslangic = $_POST['tarih_baslangic'] ?? '';
                $fTarihBitis = $_POST['tarih_bitis'] ?? '';

                // Dinamik WHERE oluştur
                $where = "WHERE 1=1";
                $sParams = [];
                if ($fSearch) {
                    $where .= " AND (c.cari_adi LIKE ? OR c.cari_unvan LIKE ? OR c.cari_telefon LIKE ? OR c.cari_email LIKE ?)";
                    $sParams = array_merge($sParams, ["%$fSearch%", "%$fSearch%", "%$fSearch%", "%$fSearch%"]);
                }
                if ($fTip) { $where .= " AND c.cari_tipi_id = ?"; $sParams[] = intval($fTip); }
                if ($fDurum !== '') { $where .= " AND c.cari_aktif = ?"; $sParams[] = $fDurum; }
                if ($fUlke) { $where .= " AND c.cari_ulke = ?"; $sParams[] = $fUlke; }
                if ($fSehir) { $where .= " AND c.cari_sehirler = ?"; $sParams[] = intval($fSehir); }
                if ($fIlce) { $where .= " AND c.cari_ilceler = ?"; $sParams[] = intval($fIlce); }
                if ($fSsaAktif !== '') { $where .= " AND c.cari_SSAAktif = ?"; $sParams[] = $fSsaAktif; }
                if ($fOlusturanKullanici) { $where .= " AND c.cari_olusturan_kullanici = ?"; $sParams[] = intval($fOlusturanKullanici); }
                if ($fEnTeklifVeren) {
                    $where .= " AND ? = (SELECT TOP 1 t.Teklifler_hazirlayan_id FROM Teklifler t WHERE t.Teklifler_cari_id = c.cari_id AND t.Durum = 1 AND t.Teklifler_hazirlayan_id IS NOT NULL GROUP BY t.Teklifler_hazirlayan_id ORDER BY COUNT(*) DESC)";
                    $sParams[] = intval($fEnTeklifVeren);
                }
                if ($fTarihBaslangic) { $where .= " AND CONVERT(DATE, c.cari_olusturma_tarihi) >= ?"; $sParams[] = $fTarihBaslangic; }
                if ($fTarihBitis) { $where .= " AND CONVERT(DATE, c.cari_olusturma_tarihi) <= ?"; $sParams[] = $fTarihBitis; }

                // Bölge yetki filtresi
                $bolgeWhere = BolgeYetkiHelper::getSqlWhere($user['kullanici_id'], $pagePermissions, 'c.cari_sehirler', 'c.cari_ilceler', 'c.cari_adres');
                if ($bolgeWhere) { $where .= " $bolgeWhere"; }

                $baseSql = "FROM Cari c LEFT JOIN Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id $where";
                
                $tipler = [];
                foreach ($cariTipleri as $tip) {
                    $tipSayi = $db->fetchOne("SELECT count(*) as total $baseSql AND c.cari_tipi_id = ?", array_merge($sParams, [$tip['cari_tipi_id']]))['total'] ?? 0;
                    $tipler[] = [
                        'id'   => $tip['cari_tipi_id'],
                        'ad'   => $tip['cari_tipi_ad'],
                        'renk' => $tip['cari_tipi_renk'] ?? 'text-secondary',
                        'sayi' => $tipSayi
                    ];
                }
                $stats = [
                    'toplam_cari' => $db->fetchOne("SELECT count(*) as total $baseSql", $sParams)['total'] ?? 0,
                    'ssa_aktif'   => $db->fetchOne("SELECT count(*) as total $baseSql AND c.cari_SSAAktif = 1", $sParams)['total'] ?? 0,
                    'tipler'      => $tipler
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametreleri (DataTables'dan search_custom olarak geliyor)
                $search = $_POST['search_custom'] ?? $_POST['search'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $ulke = $_POST['ulke'] ?? '';
                $sehir = $_POST['sehir'] ?? '';
                $ilce = $_POST['ilce'] ?? '';
                $ssaAktif = $_POST['ssa_aktif'] ?? '';
                $olusturanKullanici = $_POST['olusturan_kullanici'] ?? '';
                $enTeklifVeren = $_POST['en_teklif_veren'] ?? '';
                $tarihBaslangic = $_POST['tarih_baslangic'] ?? '';
                $tarihBitis = $_POST['tarih_bitis'] ?? '';

                // SQL sorgusu
                $sql = "
                    SELECT
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan,
                        c.cari_vergi_no,
                        c.cari_telefon,
                        c.cari_email,
                        c.cari_tipi_id,
                        ct.cari_tipi_ad,
                        ct.cari_tipi_renk,
                        c.cari_aktif,
                        c.cari_SSAAktif,
                        c.cari_ulke,
                        c.cari_sehirler,
                        c.cari_ilceler,
                        s.SehirAdi,
                        i.IlceAdi,
                        CONVERT(VARCHAR(10), c.cari_olusturma_tarihi, 120) as cari_olusturma_tarihi,
                        ISNULL(ok.kullanici_ad + ' ' + ok.kullanici_soyad, '-') as olusturan_kullanici_adi,
                        c.cari_devredilen_kullanici,
                        dk.kullanici_ad + ' ' + dk.kullanici_soyad as devredilen_kullanici_adi,
                        ISNULL(etv.en_teklif_veren, '-') as en_teklif_veren,
                        etv.teklif_sayisi as en_teklif_veren_sayi,
                        CONVERT(VARCHAR(19), ISNULL(c.cari_guncelleme_tarihi, c.cari_olusturma_tarihi), 120) as sort_tarihi
                    FROM Cari c
                    LEFT JOIN Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler i ON c.cari_ilceler = i.ilceId
                    LEFT JOIN Kullanicilar ok ON c.cari_olusturan_kullanici = ok.kullanici_id
                    LEFT JOIN Kullanicilar dk ON c.cari_devredilen_kullanici = dk.kullanici_id
                    OUTER APPLY (
                        SELECT TOP 1
                            t.Teklifler_hazirlayan_id as en_teklif_veren_id,
                            tk.kullanici_ad + ' ' + tk.kullanici_soyad as en_teklif_veren,
                            COUNT(*) as teklif_sayisi
                        FROM Teklifler t
                        INNER JOIN Kullanicilar tk ON t.Teklifler_hazirlayan_id = tk.kullanici_id
                        WHERE t.Teklifler_cari_id = c.cari_id AND t.Durum = 1
                        GROUP BY t.Teklifler_hazirlayan_id, tk.kullanici_ad, tk.kullanici_soyad
                        ORDER BY COUNT(*) DESC
                    ) etv
                    WHERE 1=1
                ";
                $params = [];

                // Bölge yetki filtresi
                $bolgeWhere = BolgeYetkiHelper::getSqlWhere($user['kullanici_id'], $pagePermissions, 'c.cari_sehirler', 'c.cari_ilceler', 'c.cari_adres');
                if ($bolgeWhere) { $sql .= " $bolgeWhere"; }
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (cari_adi LIKE ? OR cari_unvan LIKE ? OR cari_telefon LIKE ? OR cari_email LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                // Tip filtresi
                if ($tip) {
                    $sql .= " AND c.cari_tipi_id = ?";
                    $params[] = intval($tip);
                }
                
                // Durum filtresi
                if ($durum !== '') {
                    $sql .= " AND c.cari_aktif = ?";
                    $params[] = $durum;
                }
                
                // Ulke filtresi
                if ($ulke) {
                    $sql .= " AND c.cari_ulke = ?";
                    $params[] = $ulke;
                }
                
                // Şehir filtresi
                if ($sehir) {
                    $sql .= " AND c.cari_sehirler = ?";
                    $params[] = intval($sehir);
                }
                
                // İlçe filtresi
                if ($ilce) {
                    $sql .= " AND c.cari_ilceler = ?";
                    $params[] = intval($ilce);
                }
                
                // SSA Aktif filtresi
                if ($ssaAktif !== '') {
                    $sql .= " AND c.cari_SSAAktif = ?";
                    $params[] = $ssaAktif;
                }

                // Oluşturan kullanıcı filtresi
                if ($olusturanKullanici) {
                    $sql .= " AND c.cari_olusturan_kullanici = ?";
                    $params[] = intval($olusturanKullanici);
                }

                // En teklif veren personel filtresi
                if ($enTeklifVeren) {
                    $sql .= " AND etv.en_teklif_veren_id = ?";
                    $params[] = intval($enTeklifVeren);
                }

                // Oluşturma tarihi filtresi
                if ($tarihBaslangic) {
                    $sql .= " AND CONVERT(DATE, c.cari_olusturma_tarihi) >= ?";
                    $params[] = $tarihBaslangic;
                }
                if ($tarihBitis) {
                    $sql .= " AND CONVERT(DATE, c.cari_olusturma_tarihi) <= ?";
                    $params[] = $tarihBitis;
                }

                $sql .= " ORDER BY ISNULL(c.cari_guncelleme_tarihi, c.cari_olusturma_tarihi) DESC";
                
                $cariList = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $cariList]);
                break;
                
            case 'get':
                // Tek bir cari kaydini getir
                $cariid = $_POST['cari_id'] ?? 0;
                $cari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]);
                echo json_encode(['success' => true, 'data' => $cari]);
                break;
                
            case 'save':
                // Yetki kontrolü
                $cariid = $_POST['cari_id'] ?? 0;
                if ($cariid > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($cariid == 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                // Eski kaydı al (log için)
                $eskiCari = $cariid > 0 ? $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]) : null;
                
                // Yeni cari kaydet veya güncelle
                $data = [
                    'cari_adi' => mb_strtoupper($_POST['cari_adi'] ?? '', 'UTF-8'),
                    'cari_unvan' => mb_strtoupper($_POST['cari_unvan'] ?? '', 'UTF-8'),
                    'cari_vergi_dairesi' => $_POST['cari_vergi_dairesi'] ?? '',
                    'cari_vergi_no' => $_POST['cari_vergi_no'] ?? '',
                    'cari_mersis_no' => $_POST['cari_mersis_no'] ?? '',
                    'cari_ticaret_sicil_no' => $_POST['cari_ticaret_sicil_no'] ?? '',
                    'cari_telefon' => $_POST['cari_telefon'] ?? '',
                    'cari_email' => $_POST['cari_email'] ?? '',
                    'cari_adres' => $_POST['cari_adres'] ?? '',
                    'cari_posta_kodu' => $_POST['cari_posta_kodu'] ?? '',
                    'cari_Yetkili_adi' => $_POST['cari_Yetkili_adi'] ?? '',
                    'cari_Yetkili_telefon' => $_POST['cari_Yetkili_telefon'] ?? '',
                    'cari_Yetkili_email' => $_POST['cari_Yetkili_email'] ?? '',
                    'cari_ulke' => $_POST['cari_ulke'] ?? null,
                    'cari_sehirler' => $_POST['cari_sehirler'] ?? '',
                    'cari_ilceler' => $_POST['cari_ilceler'] ?? '',
                    'cari_tipi_id' => !empty($_POST['cari_tipi_id']) ? intval($_POST['cari_tipi_id']) : null,
                    'cari_aktif' => isset($_POST['cari_aktif']) ? 1 : 0,
                    'cari_SSAAktif' => isset($_POST['cari_SSAAktif']) ? 1 : 0
                ];
                
                if ($cariid > 0) {
                    // Güncelleme
                    $data['cari_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['cari_guncelleyen_kullanici'] = $user['kullanici_id'];
                    
                    $db->update('Cari', $data, ['cari_id' => $cariid]);
                    
                    // Log kaydı
                    $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]);
                    logKayitDegisiklikleri($db, 'cari-yonetimi', 'Cari', $cariid, $eskiCari, $yeniCari, $user['kullanici_id'], 'Cari güncellendi');
                    
                    echo json_encode(['success' => true, 'message' => 'Cari başarıyla güncellendi']);
                } else {
                    // Yeni kayıt
                    $data['cari_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['cari_olusturan_kullanici'] = $user['kullanici_id'];
                    
                    $db->insert('Cari', $data);
                    
                    // Yeni eklenen kaydın ID'sini al ve log
                    $yeniCari = $db->fetchOne("SELECT TOP 1 * FROM Cari ORDER BY cari_id DESC");
                    if ($yeniCari) {
                        logKayitDegisiklikleri($db, 'cari-yonetimi', 'Cari', $yeniCari['cari_id'], null, $yeniCari, $user['kullanici_id'], 'Yeni cari eklendi');
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Cari başarıyla eklendi']);
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                // Cari sil
                $cariid = intval($_POST['cari_id'] ?? 0);
                
                // Log için eski kaydı al
                $eskiCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]);
                
                // Log kaydı (silme)
                if ($eskiCari) {
                    logKayitDegisiklikleri($db, 'cari-yonetimi', 'Cari', $cariid, $eskiCari, null, $user['kullanici_id'], 'Cari silindi');
                }

                // Bağımlı kayıtları önce sil
                $db->execute("DELETE FROM CariYetkili WHERE CariYetkili_CariId = ?", [$cariid]);
                $db->execute("DELETE FROM Cari_Dosyalar WHERE cari_id = ?", [$cariid]);
                $db->execute("DELETE FROM Teklifler WHERE Teklifler_cari_id = ?", [$cariid]);

                $db->delete('Cari', ['cari_id' => $cariid]);
                echo json_encode(['success' => true, 'message' => 'Cari başarıyla silindi']);
                break;
            
            case 'devret':
                // Cari devri: yalnızca Administrator ve Satış Müdürü
                if (!$devirYetkisi) {
                    echo json_encode(['success' => false, 'message' => 'Cari devri yetkiniz yok!']);
                    break;
                }

                $cariid = intval($_POST['cari_id'] ?? 0);
                $devredilen = intval($_POST['devredilen_kullanici'] ?? 0);

                if ($cariid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz cari!']);
                    break;
                }

                $eskiCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]);
                if (!$eskiCari) {
                    echo json_encode(['success' => false, 'message' => 'Cari bulunamadı!']);
                    break;
                }

                CariDevirHelper::devret($cariid, $devredilen ?: null, (int)$user['kullanici_id']);

                $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]);
                logKayitDegisiklikleri(
                    $db, 'cari-yonetimi', 'Cari', $cariid, $eskiCari, $yeniCari,
                    $user['kullanici_id'], $devredilen ? 'Cari devredildi' : 'Cari devri kaldırıldı'
                );

                echo json_encode([
                    'success' => true,
                    'message' => $devredilen ? 'Cari devredildi' : 'Cari devri kaldırıldı'
                ]);
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .info-box { cursor: pointer; transition: opacity 0.2s, box-shadow 0.2s; }
        .info-box:hover { opacity: 0.85; }
        .info-box.active-filter { box-shadow: 0 0 0 3px rgba(0,0,0,0.35) inset; }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .badge-aktif {
            background-color: #28a745;
        }
        .badge-pasif {
            background-color: #dc3545;
        }
    </style>
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
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sayfa içeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- info Boxes -->
                    <div class="row mb-3" id="stat-tipler-container">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary" id="infobox-toplam" onclick="infoboxFiltre('toplam')" title="Tüm kayıtları göster">
                                <span class="info-box-icon">
                                    <i class="bi bi-people-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Cari</span>
                                    <span class="info-box-number" id="stat-toplam-cari">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-success" id="infobox-ssa" onclick="infoboxFiltre('ssa')" title="SSA Aktif kayıtları filtrele">
                                <span class="info-box-icon">
                                    <i class="bi bi-check-circle-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">SSA Aktif</span>
                                    <span class="info-box-number" id="stat-ssa-aktif">0</span>
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
                                    <div class="col-md-3">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ad, ünvan, telefon, e-posta...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tip</label>
                                        <select class="form-select" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <?php foreach ($cariTipleri as $tip): ?>
                                                <option value="<?= $tip['cari_tipi_id'] ?>"><?= htmlspecialchars($tip['cari_tipi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">SSA</label>
                                        <select class="form-select" name="ssa_aktif" id="filter_ssa_aktif">
                                            <option value="">Tümü</option>
                                            <option value="1">SSA Aktif</option>
                                            <option value="0">SSA Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ülke</label>
                                        <select class="form-select" name="ulke" id="filter_ulke">
                                            <option value="">Tümü</option>
                                            <?php foreach ($ulkeler as $ulke): ?>
                                                <option value="<?= $ulke['UlkeId'] ?>"><?= htmlspecialchars($ulke['UlkeAdi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row g-3 mt-1">
                                    <div class="col-md-3">
                                        <label class="form-label">İl</label>
                                        <select class="form-select" name="sehir" id="filter_sehir">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">İlçe</label>
                                        <select class="form-select" name="ilce" id="filter_ilce">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Oluşturan Kullanıcı</label>
                                        <select class="form-select" name="olusturan_kullanici" id="filter_olusturan_kullanici">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kullanicilar as $k): ?>
                                                <option value="<?= $k['kullanici_id'] ?>"><?= htmlspecialchars($k['kullanici_ad'] . ' ' . $k['kullanici_soyad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">En Teklif Veren</label>
                                        <select class="form-select" name="en_teklif_veren" id="filter_en_teklif_veren">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kullanicilar as $k): ?>
                                                <option value="<?= $k['kullanici_id'] ?>"><?= htmlspecialchars($k['kullanici_ad'] . ' ' . $k['kullanici_soyad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row g-3 mt-1">
                                    <div class="col-md-2">
                                        <label class="form-label">Oluşturma Tarihi (Başlangıç)</label>
                                        <input type="date" class="form-control" name="tarih_baslangic" id="filter_tarih_baslangic">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Oluşturma Tarihi (Bitiş)</label>
                                        <input type="date" class="form-control" name="tarih_bitis" id="filter_tarih_bitis">
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Cari Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Cari Listesi</h3>
                            <div class="card-tools d-flex gap-2 align-items-center">
                                <button type="button" class="btn btn-success btn-sm" onclick="exportExcel()" title="Mevcut filtrelenmiş veriyi Excel olarak indir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="/Admin/cari-form" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle"></i> Yeni Cari Ekle
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="cariTable">
                                    <thead>
                                        <tr>
                                            <th style="display:none"></th>
                                            <th>ID</th>
                                            <th>Ünvan</th>
                                            <th>Vergi No</th>
                                            <th>Telefon</th>
                                            <th>Tip</th>
                                            <th>İl</th>
                                            <th>İlçe</th>
                                            <th>SSA</th>
                                            <th>Durum</th>
                                            <th>Oluşturan</th>
                                            <th>En Teklif Veren</th>
                                            <th>Oluşturma Tarihi</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Dinamik içerik -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <?php if ($devirYetkisi): ?>
    <!-- Cari Devir Modalı -->
    <div class="modal fade" id="devirModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-left-right"></i> Cari Devri</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="devir_cari_id">
                    <div class="mb-2">
                        <strong id="devir_cari_adi" class="d-block"></strong>
                        <small class="text-muted">Mevcut sorumlu: <span id="devir_mevcut">-</span></small>
                    </div>
                    <label class="form-label" for="devir_kullanici">Devredilecek Personel</label>
                    <select class="form-select" id="devir_kullanici">
                        <option value="">Devri kaldır</option>
                        <?php foreach ($kullanicilar as $k): ?>
                            <option value="<?= $k['kullanici_id'] ?>">
                                <?= htmlspecialchars($k['kullanici_ad'] . ' ' . $k['kullanici_soyad']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block mt-2">
                        Seçilen personel, bu cariye ait geçmiş teklif, fatura, ödeme, konsinye ve görüşme
                        kayıtlarını kendisi oluşturmamış olsa bile görebilir. Geçmiş kayıtların personel
                        bilgisi değişmez, raporlar etkilenmez.
                    </small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="button" class="btn btn-primary" onclick="devirKaydet()">
                        <i class="bi bi-check-lg"></i> Kaydet
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>


    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- AdminLTE JS -->
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <!-- SheetJS -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        // Sayfa yetkileri
        const pagePermissions = {
            can_add: <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>,
            can_edit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>,
            can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>,
            can_devret: <?= $devirYetkisi ? 'true' : 'false' ?>
        };
        
        let table;
        let currentFilters = {};
        
        // Şehir ve İlçe verileri (client-side filtreleme için)
        const allSehirler = <?= json_encode($sehirler) ?>;
        const allIlceler = <?= json_encode($ilceler) ?>;
        
        // Sayfa yüklendiğinde
        $(document).ready(function() {
            // DataTables init
            initDataTable();
            loadStats();
            loadFilterSehirler();
            
            // Select2 init (sadece filtre)
            $('#filter_tip, #filter_durum, #filter_ulke, #filter_sehir, #filter_ilce, #filter_ssa_aktif, #filter_olusturan_kullanici, #filter_en_teklif_veren').select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });

            // Devir modalındaki personel seçimi (modal içinde dropdownParent zorunlu)
            if ($('#devir_kullanici').length) {
                $('#devir_kullanici').select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    dropdownParent: $('#devirModal'),
                    language: {
                        noResults: function() { return "Sonuç bulunamadı"; },
                        searching: function() { return "Aranıyor..."; }
                    }
                });
            }

            // Ülke değiştiğinde şehirleri yükle
            $('#filter_ulke').on('change', function() {
                loadFilterSehirler();
                // İlçeyi de sıfırla
                $('#filter_ilce').html('<option value="">Tümü</option>').trigger('change.select2');
            });
            
            // Şehir değiştiğinde ilçeleri yükle
            $('#filter_sehir').on('change', function() {
                loadFilterIlceler();
            });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentFilters = {
                    search: $('#filter_search').val(),
                    tip: $('#filter_tip').val(),
                    durum: $('#filter_durum').val(),
                    ulke: $('#filter_ulke').val(),
                    sehir: $('#filter_sehir').val(),
                    ilce: $('#filter_ilce').val(),
                    ssa_aktif: $('#filter_ssa_aktif').val(),
                    olusturan_kullanici: $('#filter_olusturan_kullanici').val(),
                    en_teklif_veren: $('#filter_en_teklif_veren').val(),
                    tarih_baslangic: $('#filter_tarih_baslangic').val(),
                    tarih_bitis: $('#filter_tarih_bitis').val()
                };
                table.ajax.reload();
                loadStats();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtre temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                $('#filter_ulke').val('').trigger('change.select2');
                $('#filter_sehir').html('<option value="">Tümü</option>').val('').trigger('change.select2');
                $('#filter_ilce').html('<option value="">Tümü</option>').val('').trigger('change.select2');
                $('#filter_ssa_aktif').val('').trigger('change.select2');
                $('#filter_olusturan_kullanici, #filter_en_teklif_veren').val('').trigger('change.select2');
                $('#filter_tarih_baslangic').val('');
                $('#filter_tarih_bitis').val('');
                currentFilters = {};
                table.ajax.reload();
                loadStats();
                showToast('Filtreler temizlendi', 'info');
            });
        });
        
        // Filtre - Ülkeye göre şehirleri yükle
        function loadFilterSehirler() {
            const ulkeId = $('#filter_ulke').val();
            const sehirSelect = $('#filter_sehir');
            sehirSelect.html('<option value="">Tümü</option>');
            
            const filtered = ulkeId ? allSehirler.filter(s => s.UlkeId == ulkeId) : allSehirler;
            filtered.forEach(function(sehir) {
                sehirSelect.append('<option value="' + sehir.SehirId + '">' + sehir.SehirAdi + '</option>');
            });
            sehirSelect.trigger('change.select2');
        }
        
        // Filtre - Şehire göre ilçeleri yükle
        function loadFilterIlceler() {
            const sehirId = $('#filter_sehir').val();
            const ilceSelect = $('#filter_ilce');
            ilceSelect.html('<option value="">Tümü</option>');
            
            if (sehirId) {
                const filtered = allIlceler.filter(i => i.SehirId == sehirId);
                filtered.forEach(function(ilce) {
                    ilceSelect.append('<option value="' + ilce.ilceId + '">' + ilce.IlceAdi + '</option>');
                });
            }
            ilceSelect.trigger('change.select2');
        }
        
        // DataTables init
        function initDataTable() {
            table = $('#cariTable').DataTable({
                processing: true,
                stateSave: true,
                serverSide: false,
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        d.action = 'list';
                        d.search_custom = currentFilters.search || '';
                        d.tip = currentFilters.tip || '';
                        d.durum = currentFilters.durum !== undefined ? currentFilters.durum : '';
                        d.ulke = currentFilters.ulke || '';
                        d.sehir = currentFilters.sehir || '';
                        d.ilce = currentFilters.ilce || '';
                        d.ssa_aktif = currentFilters.ssa_aktif !== undefined ? currentFilters.ssa_aktif : '';
                        d.olusturan_kullanici = currentFilters.olusturan_kullanici || '';
                        d.en_teklif_veren = currentFilters.en_teklif_veren || '';
                        d.tarih_baslangic = currentFilters.tarih_baslangic || '';
                        d.tarih_bitis = currentFilters.tarih_bitis || '';
                    },
                    dataSrc: function(json) {
                        return json.success ? json.data : [];
                    }
                },
                columns: [
                    { data: 'sort_tarihi', title: 'sort_tarihi', visible: false },
                    { data: 'cari_id', title: 'ID', width: '50px' },
                    { data: 'cari_unvan', title: 'Ünvan', render: function(data) {
                        if (!data) return '';
                        var full = data.toLocaleUpperCase('tr-TR');
                        var kisa = full.length > 20 ? full.substring(0, 20) + '…' : full;
                        var safe = full.replace(/"/g, '&quot;');
                        return '<span class="text-truncate d-inline-block" style="max-width:180px; white-space:nowrap; vertical-align:middle;" title="' + safe + '">' + kisa + '</span>';
                    } },
                    { data: 'cari_vergi_no', title: 'Vergi No' },
                    { data: 'cari_telefon', title: 'Telefon' },
                    {
                        data: null, 
                        title: 'Tip',
                        render: function(data, type, row) {
                            if (row.cari_tipi_ad) {
                                const renk = row.cari_tipi_renk ? row.cari_tipi_renk.replace('text-', 'bg-') : 'bg-primary';
                                return '<span class="badge ' + renk + '">' + row.cari_tipi_ad + '</span>';
                            }
                            return '<span class="badge bg-secondary">Belirtilmemiş</span>';
                        }
                    },
                    { 
                        data: 'SehirAdi', 
                        title: 'İl',
                        render: function(data) {
                            return data || '<span class="text-muted">-</span>';
                        }
                    },
                    { 
                        data: 'IlceAdi', 
                        title: 'İlçe',
                        render: function(data) {
                            return data || '<span class="text-muted">-</span>';
                        }
                    },
                    { 
                        data: 'cari_aktif', 
                        title: 'Durum',
                        render: function(data, type, row) {
                            return data == 1 
                                ? '<span class="badge bg-success">Aktif</span>' 
                                : '<span class="badge bg-danger">Pasif</span>';
                        }
                    },
                    { 
                        data: 'cari_SSAAktif', 
                        title: 'SSA',
                        render: function(data, type, row) {
                            return data == 1 
                                ? '<span class="badge bg-success">Aktif</span>' 
                                : '<span class="badge bg-secondary">Pasif</span>';
                        }
                    },
                    {
                        data: 'olusturan_kullanici_adi',
                        title: 'Oluşturan',
                        render: function(data, type, row) {
                            var metin = data || '<span class="text-muted">-</span>';
                            if (row.devredilen_kullanici_adi) {
                                metin += ' <span class="badge bg-warning text-dark" title="Devredilen personel">'
                                       + '<i class="bi bi-arrow-right"></i> ' + row.devredilen_kullanici_adi + '</span>';
                            }
                            return metin;
                        }
                    },
                    {
                        data: 'en_teklif_veren',
                        title: 'En Teklif Veren',
                        render: function(data, type, row) {
                            if (!data || data === '-') {
                                return '<span class="text-muted">-</span>';
                            }
                            var sayi = row.en_teklif_veren_sayi ? ' <span class="badge bg-info">' + row.en_teklif_veren_sayi + '</span>' : '';
                            return data + sayi;
                        }
                    },
                    {
                        data: 'cari_olusturma_tarihi',
                        title: 'Oluşturma Tarihi',
                        render: function(data) {
                            return data || '<span class="text-muted">-</span>';
                        }
                    },
                    {
                        data: null,
                        title: 'İşlemler',
                        orderable: false,
                        width: '170px',
                        render: function(data, type, row) {
                            let buttons = '<div class="btn-group btn-group-sm">';
                            if (pagePermissions.can_edit) {
                                buttons += `<a href="/Admin/cari-form?id=${row.cari_id}" class="btn btn-info btn-sm" title="Düzenlel"><i class="bi bi-pencil"></i></a>`;
                            }
                            if (pagePermissions.can_devret) {
                                const devirli = !!row.cari_devredilen_kullanici;
                                const devirBaslik = devirli
                                    ? 'Devredildi: ' + (row.devredilen_kullanici_adi || '')
                                    : 'Cari Devret';
                                buttons += `<button class="btn ${devirli ? 'btn-warning' : 'btn-secondary'} btn-sm"
                                    onclick="devirAc(${row.cari_id})" title="${devirBaslik}"><i class="bi bi-arrow-left-right"></i></button>`;
                            }
                            if (pagePermissions.can_delete) {
                                buttons += `<button class="btn btn-danger btn-sm" onclick="deleteCari(${row.cari_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                            }
                            buttons += '</div>';
                            return buttons;
                        }
                    }
                ],
                order: [[0, 'desc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]],
                language: {
                    url: "https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json"
                },
                responsive: true,
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip'
            });
        }
        
        // İstatistikleri yükle
        function loadStats() {
            var statsData = {
                action: 'stats',
                search: currentFilters.search || '',
                tip: currentFilters.tip || '',
                durum: currentFilters.durum !== undefined ? currentFilters.durum : '',
                ulke: currentFilters.ulke || '',
                sehir: currentFilters.sehir || '',
                ilce: currentFilters.ilce || '',
                ssa_aktif: currentFilters.ssa_aktif !== undefined ? currentFilters.ssa_aktif : '',
                olusturan_kullanici: currentFilters.olusturan_kullanici || '',
                en_teklif_veren: currentFilters.en_teklif_veren || '',
                tarih_baslangic: currentFilters.tarih_baslangic || '',
                tarih_bitis: currentFilters.tarih_bitis || ''
            };
            $.post('', statsData, function(response) {
                if (response.success) {
                    $('#stat-toplam-cari').text(response.data.toplam_cari);
                    $('#stat-ssa-aktif').text(response.data.ssa_aktif);

                    // Dinamik cari tipi infoboxları (önceki tipler temizle, sabit 2 kutu kalır)
                    $('#stat-tipler-container .stat-tip-col').remove();
                    $.each(response.data.tipler, function(i, tip) {
                        var bgClass = tip.renk ? tip.renk.replace('text-', 'text-bg-') : 'text-bg-secondary';
                        var html = '<div class="col-md-3 stat-tip-col">' +
                            '<div class="info-box ' + bgClass + '" id="infobox-tip-' + tip.id + '" onclick="infoboxFiltre(\'tip\', ' + tip.id + ')" title="' + tip.ad + ' olarak filtrele">' +
                                '<span class="info-box-icon"><i class="bi bi-tag-fill"></i></span>' +
                                '<div class="info-box-content">' +
                                    '<span class="info-box-text">' + tip.ad + '</span>' +
                                    '<span class="info-box-number">' + tip.sayi + '</span>' +
                                '</div>' +
                            '</div>' +
                        '</div>';
                        $('#stat-tipler-container').append(html);
                    });
                }
            });
        }
        
        // InfoBox filtre
        let activeInfobox = null;
        function infoboxFiltre(tur, tipId) {
            // Aktif infobox vurgusunu temizle
            $('.info-box').removeClass('active-filter');

            if (tur === 'toplam') {
                // Tüm filtreleri sıfırla
                currentFilters = {};
                $('#filterForm')[0].reset();
                $('#filter_tip, #filter_durum, #filter_ssa_aktif, #filter_ulke, #filter_olusturan_kullanici, #filter_en_teklif_veren').val('').trigger('change.select2');
                $('#filter_sehir, #filter_ilce').html('<option value="">Tümü</option>').val('').trigger('change.select2');
                $('#filter_tarih_baslangic, #filter_tarih_bitis').val('');
                $('#infobox-toplam').addClass('active-filter');
            } else if (tur === 'ssa') {
                currentFilters = { ssa_aktif: '1' };
                $('#filterForm')[0].reset();
                $('#filter_tip, #filter_durum, #filter_ulke, #filter_olusturan_kullanici, #filter_en_teklif_veren').val('').trigger('change.select2');
                $('#filter_sehir, #filter_ilce').html('<option value="">Tümü</option>').val('').trigger('change.select2');
                $('#filter_tarih_baslangic, #filter_tarih_bitis').val('');
                $('#filter_ssa_aktif').val('1').trigger('change.select2');
                $('#infobox-ssa').addClass('active-filter');
            } else if (tur === 'tip') {
                currentFilters = { tip: tipId };
                $('#filterForm')[0].reset();
                $('#filter_durum, #filter_ssa_aktif, #filter_ulke, #filter_olusturan_kullanici, #filter_en_teklif_veren').val('').trigger('change.select2');
                $('#filter_sehir, #filter_ilce').html('<option value="">Tümü</option>').val('').trigger('change.select2');
                $('#filter_tarih_baslangic, #filter_tarih_bitis').val('');
                $('#filter_tip').val(tipId).trigger('change.select2');
                $('#infobox-tip-' + tipId).addClass('active-filter');
            }

            table.ajax.reload();
            loadStats();
        }

        // Excel export
        function exportExcel() {
            var rows = table.rows({ search: 'applied' }).data().toArray();
            if (rows.length === 0) {
                showToast('Dışa aktarılacak veri bulunamadı.', 'warning');
                return;
            }

            var headers = ['ID', 'Ünvan', 'Vergi No', 'Telefon', 'Tip', 'İl', 'İlçe', 'Durum', 'SSA', 'Oluşturan', 'Devredilen', 'En Teklif Veren', 'Oluşturma Tarihi'];

            var data = rows.map(function(row) {
                return [
                    row.cari_id,
                    row.cari_unvan || '',
                    row.cari_vergi_no || '',
                    row.cari_telefon || '',
                    row.cari_tipi_ad || '',
                    row.SehirAdi || '',
                    row.IlceAdi || '',
                    row.cari_aktif == 1 ? 'Aktif' : 'Pasif',
                    row.cari_SSAAktif == 1 ? 'Aktif' : 'Pasif',
                    row.olusturan_kullanici_adi || '',
                    row.devredilen_kullanici_adi || '',
                    (row.en_teklif_veren && row.en_teklif_veren !== '-') ? row.en_teklif_veren + (row.en_teklif_veren_sayi ? ' (' + row.en_teklif_veren_sayi + ')' : '') : '',
                    row.cari_olusturma_tarihi || ''
                ];
            });

            var wsData = [headers].concat(data);
            var ws = XLSX.utils.aoa_to_sheet(wsData);

            // Kolon genişlikleri
            ws['!cols'] = [
                {wch:6},{wch:36},{wch:14},{wch:16},
                {wch:16},{wch:14},{wch:14},{wch:8},{wch:8},{wch:22},{wch:22},{wch:22},{wch:16}
            ];

            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Cari Listesi');

            var tarih = new Date().toLocaleDateString('tr-TR').replace(/\./g, '-');
            XLSX.writeFile(wb, 'Cari-Listesi-' + tarih + '.xlsx');
            showToast(rows.length + ' kayıt Excel olarak indirildi.', 'success');
        }

        // Cari devri - modalı aç
        function devirAc(cariid) {
            const row = table.rows().data().toArray().find(r => r.cari_id == cariid);
            if (!row) return;

            $('#devir_cari_id').val(cariid);
            $('#devir_cari_adi').text(row.cari_unvan || row.cari_adi || '');
            $('#devir_mevcut').text(row.devredilen_kullanici_adi || row.olusturan_kullanici_adi || '-');
            $('#devir_kullanici').val(row.cari_devredilen_kullanici || '').trigger('change.select2');

            new bootstrap.Modal(document.getElementById('devirModal')).show();
        }

        // Cari devri - kaydet
        function devirKaydet() {
            const cariid = $('#devir_cari_id').val();
            const devredilen = $('#devir_kullanici').val() || 0;

            $.post('', { action: 'devret', cari_id: cariid, devredilen_kullanici: devredilen }, function(response) {
                if (response.success) {
                    bootstrap.Modal.getInstance(document.getElementById('devirModal')).hide();
                    showSuccess('Tamam!', response.message);
                    table.ajax.reload(null, false);
                } else {
                    showError('Hata!', response.message);
                }
            }, 'json').fail(function() {
                showError('Hata!', 'İşlem sırasında bir hata oluştu.');
            });
        }

        // Cari sil
        function deleteCari(cariid) {
            confirmAction(
                'Bu cariyi silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', cari_id: cariid }, function(response) {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            loadStats();
                            table.ajax.reload(null, false);
                        } else {
                            showError('Hata!', response.message);
                        }
                    }).fail(function() {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
    </script>
    
</body>
</html>