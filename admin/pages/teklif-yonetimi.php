<?php
/**
 * Admin Panel - Teklif / Proforma Yönetimi
 * Liste sayfası - DataTables ile
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/BolgeYetkiHelper.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

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

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Teklif Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Dropdown verileri
$teklifTipleri = $db->fetchAll("SELECT * FROM Teklif_Tipleri WHERE Durum = 1 ORDER BY Teklif_Tipleri_sira_no");
$teklifDurumlari = $db->fetchAll("SELECT * FROM Teklif_Durumlari WHERE Durum = 1 ORDER BY Teklif_Durumlari_sira_no");
$kullanicilar = $db->fetchAll("SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad as kullanici_adi FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        // Yetki tabanlı taban WHERE koşulları
        $baseJoin = "FROM Teklifler t LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id";
        $baseWhere = "WHERE t.Durum = 1";
        $baseParams = [];
        if ($pagePermissions['can_view_own_records']) {
            // Kendi hazırladığı teklifler + kendisine devredilen carilerin teklifleri
            $baseWhere .= " AND (" . CariDevirHelper::orDevir(
                "t.Teklifler_hazirlayan_id = ?",
                (int)$user['kullanici_id'],
                't.Teklifler_cari_id'
            ) . ")";
            $baseParams[] = $user['kullanici_id'];
        }
        $bolgeWhere = BolgeYetkiHelper::getSqlWhere($user['kullanici_id'], $pagePermissions, 'c.cari_sehirler', 'c.cari_ilceler');
        if ($bolgeWhere) {
            $baseWhere .= " $bolgeWhere";
        }

        switch ($action) {
            case 'stats':
                $toplam    = $db->fetchOne("SELECT COUNT(*) as sayi $baseJoin $baseWhere", $baseParams)['sayi'] ?? 0;
                $onaylanan = $db->fetchOne("SELECT COUNT(*) as sayi $baseJoin $baseWhere AND t.Teklifler_durum_id = 3", $baseParams)['sayi'] ?? 0;
                $bekleyen  = $db->fetchOne("SELECT COUNT(*) as sayi $baseJoin $baseWhere AND t.Teklifler_durum_id IN (1,2)", $baseParams)['sayi'] ?? 0;
                $buAy      = $db->fetchOne("SELECT COUNT(*) as sayi $baseJoin $baseWhere AND MONTH(t.Teklifler_tarih) = MONTH(GETDATE()) AND YEAR(t.Teklifler_tarih) = YEAR(GETDATE())", $baseParams)['sayi'] ?? 0;
                $toplamTutar = $db->fetchOne("SELECT ISNULL(SUM(t.Teklifler_genel_toplam), 0) as tutar $baseJoin $baseWhere AND t.Teklifler_durum_id = 3", $baseParams)['tutar'] ?? 0;

                echo json_encode(['success' => true, 'data' => [
                    'toplam' => $toplam,
                    'onaylanan' => $onaylanan,
                    'bekleyen' => $bekleyen,
                    'bu_ay' => $buAy,
                    'toplam_tutar' => $toplamTutar
                ]]);
                break;

            case 'list':
                $tipId = $_POST['tip_id'] ?? '';
                $durumId = $_POST['durum_id'] ?? '';
                $cariAra = $_POST['cari_ara'] ?? '';
                $hazirlayanId = $_POST['hazirlayan_id'] ?? '';
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $search = $_POST['search'] ?? '';

                $sql = "
                    SELECT 
                        t.Teklifler_id,
                        t.Teklifler_no,
                        t.Teklifler_tip_id,
                        CONVERT(VARCHAR(10), t.Teklifler_tarih, 120) as Teklifler_tarih,
                        t.Teklifler_toplam_tutar,
                        t.Teklifler_toplam_kdv,
                        t.Teklifler_genel_toplam,
                        t.Teklifler_gecerlilik_suresi,
                        t.Teklifler_durum_id,
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan,
                        tt.Teklif_Tipleri_ad as tip_adi,
                        td.Teklif_Durumlari_ad as durum_adi,
                        td.Teklif_Durumlari_renk as durum_renk,
                        (SELECT COUNT(*) FROM Teklif_Kalemleri tk WHERE tk.Teklif_Kalemleri_teklif_id = t.Teklifler_id AND tk.Durum = 1) as kalem_sayisi,
                        (SELECT COUNT(*) FROM Teklif_Kalemleri tk3 WHERE tk3.Teklif_Kalemleri_teklif_id = t.Teklifler_id AND tk3.Durum = 1 AND tk3.Teklif_Kalemleri_miktar > 0) as aktif_kalem_sayisi,
                        (SELECT COUNT(*) FROM Teklif_Kalemleri tk3 LEFT JOIN Urun_Hizmet uh3 ON tk3.Teklif_Kalemleri_urun_hizmet_id = uh3.urun_hizmet_id WHERE tk3.Teklif_Kalemleri_teklif_id = t.Teklifler_id AND tk3.Durum = 1 AND tk3.Teklif_Kalemleri_miktar > 0 AND ISNULL(uh3.Urun_Stok_Miktari, 0) >= tk3.Teklif_Kalemleri_miktar) as stok_tamamlanan_kalem,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as olusturan_adi,
                        kh.kullanici_ad + ' ' + kh.kullanici_soyad as hazirlayan_adi,
                        t.Teklifler_hazirlayan_id,
                        ISNULL(pb.ParaBirimi_Simge, N'₺') as para_birimi_simge,
                        ftr.FaturalarGiden_id as fatura_id,
                        ftr.FaturalarGiden_Durum as fatura_durum,
                        ftr.FaturalarGiden_FaturaNo as fatura_no
                    FROM Teklifler t
                    LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
                    LEFT JOIN Teklif_Tipleri tt ON t.Teklifler_tip_id = tt.Teklif_Tipleri_id
                    LEFT JOIN Teklif_Durumlari td ON t.Teklifler_durum_id = td.Teklif_Durumlari_id
                    LEFT JOIN kullanicilar k ON t.OlusturanKullanici = k.kullanici_id
                    LEFT JOIN kullanicilar kh ON t.Teklifler_hazirlayan_id = kh.kullanici_id
                    OUTER APPLY (
                        SELECT TOP 1 pb2.ParaBirimi_Simge
                        FROM Teklif_Kalemleri tk2
                        LEFT JOIN tanim_ParaBirimi pb2 ON tk2.Teklif_Kalemleri_para_birimi_id = pb2.ParaBirimi_id
                        WHERE tk2.Teklif_Kalemleri_teklif_id = t.Teklifler_id AND tk2.Durum = 1
                        ORDER BY tk2.Teklif_Kalemleri_id
                    ) pb
                    OUTER APPLY (
                        SELECT TOP 1 FaturalarGiden_id, FaturalarGiden_Durum, FaturalarGiden_FaturaNo
                        FROM FaturalarGiden
                        WHERE FaturalarGiden_Teklif_id = t.Teklifler_id AND Durum = 1
                        ORDER BY FaturalarGiden_id DESC
                    ) ftr
                    WHERE t.Durum = 1
                ";
                $params = $baseParams;

                // Yetki filtreleri
                if ($pagePermissions['can_view_own_records']) {
                    // Kendi teklifleri + kendisine devredilen carilerin teklifleri
                    $sql .= " AND (" . CariDevirHelper::orDevir(
                        "t.Teklifler_hazirlayan_id = ?",
                        (int)$user['kullanici_id'],
                        't.Teklifler_cari_id'
                    ) . ")";
                    // Param zaten $baseParams üzerinden $params'a eklendi
                }
                if ($bolgeWhere) {
                    $sql .= " $bolgeWhere";
                }

                if ($tipId) {
                    $sql .= " AND t.Teklifler_tip_id = ?";
                    $params[] = $tipId;
                }
                if ($durumId) {
                    $sql .= " AND t.Teklifler_durum_id = ?";
                    $params[] = $durumId;
                }
                if ($cariAra) {
                    $sql .= " AND (c.cari_adi LIKE ? OR c.cari_unvan LIKE ?)";
                    $params[] = "%$cariAra%";
                    $params[] = "%$cariAra%";
                }
                if ($hazirlayanId) {
                    $sql .= " AND t.Teklifler_hazirlayan_id = ?";
                    $params[] = intval($hazirlayanId);
                }
                if ($startDate) {
                    $sql .= " AND CONVERT(date, t.Teklifler_tarih) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, t.Teklifler_tarih) <= ?";
                    $params[] = $endDate;
                }
                if ($search) {
                    $sql .= " AND (t.Teklifler_no LIKE ? OR c.cari_adi LIKE ? OR c.cari_unvan LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                $sql .= " ORDER BY t.Teklifler_id DESC";

                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'delete':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = intval($_POST['id'] ?? 0);

                // Soft delete
                $db->execute("UPDATE Teklifler SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE Teklifler_id = ?", [$user['kullanici_id'], $id]);
                $db->execute("UPDATE Teklif_Kalemleri SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE Teklif_Kalemleri_teklif_id = ?", [$user['kullanici_id'], $id]);

                echo json_encode(['success' => true, 'message' => 'Teklif silindi!']);
                break;

            case 'durum_degistir':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $id = intval($_POST['id'] ?? 0);
                $durumId = intval($_POST['durum_id'] ?? 1);

                $db->execute("UPDATE Teklifler SET Teklifler_durum_id = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE Teklifler_id = ?", [$durumId, $user['kullanici_id'], $id]);

                $waMesaji = null;
                if ($durumId === 3) {
                    $teklif = $db->fetchOne("
                        SELECT
                            t.Teklifler_no,
                            CAST(t.Teklifler_aciklama AS NVARCHAR(MAX)) as aciklama,
                            c.cari_adi,
                            kh.kullanici_ad + ' ' + kh.kullanici_soyad as personel
                        FROM Teklifler t
                        LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
                        LEFT JOIN kullanicilar kh ON t.Teklifler_hazirlayan_id = kh.kullanici_id
                        WHERE t.Teklifler_id = ? AND t.Durum = 1
                    ", [$id]);

                    $kalemler = $db->fetchAll("
                        SELECT
                            uh.urun_hizmet_adi,
                            uh.urun_hizmet_kodu,
                            uh.urun_hizmet_model,
                            uh.urun_hizmet_beden,
                            uh.urun_hizmet_renk,
                            tk.Teklif_Kalemleri_miktar,
                            tk.Teklif_Kalemleri_sira_no
                        FROM Teklif_Kalemleri tk
                        LEFT JOIN Urun_Hizmet uh ON tk.Teklif_Kalemleri_urun_hizmet_id = uh.urun_hizmet_id
                        WHERE tk.Teklif_Kalemleri_teklif_id = ? AND tk.Durum = 1
                        ORDER BY tk.Teklif_Kalemleri_sira_no, tk.Teklif_Kalemleri_id
                    ", [$id]);

                    if ($teklif && !empty($kalemler)) {
                        $waMesaji  = "✅ *Teklif No:* " . $teklif['Teklifler_no'] . "\n";
                        $waMesaji .= "🏢 *Cari:* " . ($teklif['cari_adi'] ?? '-') . "\n";
                        $waMesaji .= "👤 *Personel:* " . ($teklif['personel'] ?? '-') . "\n";
                        if (!empty($teklif['aciklama'])) {
                            $waMesaji .= "📝 *Açıklama:* " . $teklif['aciklama'] . "\n";
                        }
                        $waMesaji .= str_repeat("─", 20) . "\n";
                        foreach ($kalemler as $i => $k) {
                            $no = $i + 1;
                            $waMesaji .= "\n*{$no}.* " . ($k['urun_hizmet_adi'] ?? '-') . "\n";
                            $waMesaji .= "   🔖 *Kod:* " . ($k['urun_hizmet_kodu'] ?? '-') . "\n";
                            $waMesaji .= "   🏷️ *Model:* " . ($k['urun_hizmet_model'] ?? '-') . "\n";
                            $waMesaji .= "   📐 *Beden:* " . ($k['urun_hizmet_beden'] ?? '-') . "\n";
                            $waMesaji .= "   🎨 *Renk:* " . ($k['urun_hizmet_renk'] ?? '-') . "\n";
                            $waMesaji .= "   🔢 *Adet:* " . intval($k['Teklif_Kalemleri_miktar']) . "\n";
                        }
                        $waMesaji .= "\n" . str_repeat("─", 20) . "\n";
                        $waMesaji .= "⚠️ Ürünü teslime hazırlayabilirsiniz. Teslim edilen ürün kodu ve adetlerde değişiklik varsa lütfen bildirin ve Fatura kesilmesi için bilgi veriniz.";

                        require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
                        $helper = new EntegrasyonHelper($db);
                        $helper->whatsappGonder('120000000000000000@g.us', $waMesaji);
                    }
                }

                echo json_encode(['success' => true, 'message' => 'Durum güncellendi!' . ($waMesaji ? ' WhatsApp grubuna bildirim gönderildi.' : '')]);
                break;

            case 'wa_grup_gonder':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']);
                    break;
                }
                $id = intval($_POST['id'] ?? 0);
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'Teklif ID gerekli.']);
                    break;
                }

                $teklif = $db->fetchOne("
                    SELECT
                        t.Teklifler_no,
                        t.Teklifler_durum_id,
                        CAST(t.Teklifler_aciklama AS NVARCHAR(MAX)) as aciklama,
                        c.cari_adi,
                        kh.kullanici_ad + ' ' + kh.kullanici_soyad as personel
                    FROM Teklifler t
                    LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
                    LEFT JOIN kullanicilar kh ON t.Teklifler_hazirlayan_id = kh.kullanici_id
                    WHERE t.Teklifler_id = ? AND t.Durum = 1
                ", [$id]);

                if (!$teklif) {
                    echo json_encode(['success' => false, 'message' => 'Teklif bulunamadı.']);
                    break;
                }
                if ($teklif['Teklifler_durum_id'] != 3) {
                    echo json_encode(['success' => false, 'message' => 'Sadece onaylanan teklifler gönderilebilir.']);
                    break;
                }

                $kalemler = $db->fetchAll("
                    SELECT
                        uh.urun_hizmet_adi,
                        uh.urun_hizmet_kodu,
                        uh.urun_hizmet_model,
                        uh.urun_hizmet_beden,
                        uh.urun_hizmet_renk,
                        tk.Teklif_Kalemleri_miktar,
                        tk.Teklif_Kalemleri_sira_no
                    FROM Teklif_Kalemleri tk
                    LEFT JOIN Urun_Hizmet uh ON tk.Teklif_Kalemleri_urun_hizmet_id = uh.urun_hizmet_id
                    WHERE tk.Teklif_Kalemleri_teklif_id = ? AND tk.Durum = 1
                    ORDER BY tk.Teklif_Kalemleri_sira_no, tk.Teklif_Kalemleri_id
                ", [$id]);

                if (empty($kalemler)) {
                    echo json_encode(['success' => false, 'message' => 'Teklife ait kalem bulunamadı.']);
                    break;
                }

                $mesaj  = "✅ *Teklif No:* " . $teklif['Teklifler_no'] . "\n";
                $mesaj .= "🏢 *Cari:* " . ($teklif['cari_adi'] ?? '-') . "\n";
                $mesaj .= "👤 *Personel:* " . ($teklif['personel'] ?? '-') . "\n";
                if (!empty($teklif['aciklama'])) {
                    $mesaj .= "📝 *Açıklama:* " . $teklif['aciklama'] . "\n";
                }
                $mesaj .= str_repeat("─", 20) . "\n";

                foreach ($kalemler as $i => $k) {
                    $no = $i + 1;
                    $mesaj .= "\n*{$no}.* " . ($k['urun_hizmet_adi'] ?? '-') . "\n";
                    $mesaj .= "   🔖 *Kod:* " . ($k['urun_hizmet_kodu'] ?? '-') . "\n";
                    $mesaj .= "   🏷️ *Model:* " . ($k['urun_hizmet_model'] ?? '-') . "\n";
                    $mesaj .= "   📐 *Beden:* " . ($k['urun_hizmet_beden'] ?? '-') . "\n";
                    $mesaj .= "   🎨 *Renk:* " . ($k['urun_hizmet_renk'] ?? '-') . "\n";
                    $mesaj .= "   🔢 *Adet:* " . intval($k['Teklif_Kalemleri_miktar']) . "\n";
                }

                $mesaj .= "\n" . str_repeat("─", 20) . "\n";
                $mesaj .= "⚠️ Ürünü teslime hazırlayabilirsiniz. Teslim edilen ürün kodu ve adetlerde değişiklik varsa lütfen bildirin ve Fatura kesilmesi için bilgi veriniz.";

                require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
                $helper = new EntegrasyonHelper($db);
                $sonuc = $helper->whatsappGonder('120000000000000000@g.us', $mesaj);

                echo json_encode($sonuc);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
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
                                <span class="info-box-icon"><i class="bi bi-file-earmark-text"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Teklif</span>
                                    <span class="info-box-number" id="stat_toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Onaylanan</span>
                                    <span class="info-box-number" id="stat_onaylanan">0</span>
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
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-calendar-month"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ay</span>
                                    <span class="info-box-number" id="stat_bu_ay">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCollapse">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Teklif Tipi</label>
                                        <select class="form-select" id="filter_tip_id" name="tip_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($teklifTipleri as $tip): ?>
                                            <option value="<?= $tip['Teklif_Tipleri_id'] ?>"><?= htmlspecialchars($tip['Teklif_Tipleri_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" id="filter_durum_id" name="durum_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($teklifDurumlari as $durum): ?>
                                            <option value="<?= $durum['Teklif_Durumlari_id'] ?>"><?= htmlspecialchars($durum['Teklif_Durumlari_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Cari Ara</label>
                                        <input type="text" class="form-control" id="filter_cari_ara" name="cari_ara" placeholder="Cari adı veya ünvan...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Hazırlayan</label>
                                        <select class="form-select" id="filter_hazirlayan_id" name="hazirlayan_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kullanicilar as $kul): ?>
                                            <option value="<?= $kul['kullanici_id'] ?>"><?= htmlspecialchars($kul['kullanici_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                    </div>
                                    <div class="col-md-12">
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

                    <!-- Ana Tablo Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-list-ul"></i> Teklif Listesi
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-success" id="btnExcelExport">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="/Admin/teklif-form" class="btn btn-sm btn-primary ms-1">
                                    <i class="bi bi-plus-lg"></i> Yeni Teklif
                                </a>
                                <a href="/Admin/teklif-form?tip=2" class="btn btn-sm btn-warning ms-1">
                                    <i class="bi bi-plus-lg"></i> Yeni Proforma
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="tekliflerTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Teklif No</th>
                                            <th>Tip</th>
                                            <th>Tarih</th>
                                            <th>İşletme</th>
                                            <th>Hazırlayan</th>
                                            <th class="text-end">Ara Toplam</th>
                                            <th class="text-end">KDV</th>
                                            <th class="text-end">Genel Toplam</th>
                                            <th>Durum</th>
                                            <th width="130">İşlemler</th>
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

    <!-- Durum Değiştirme Modal -->
    <div class="modal fade" id="durumModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Durum Değiştir</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="durum_teklif_id">
                    <label class="form-label">Yeni Durum</label>
                    <select class="form-select" id="durum_yeni_id">
                        <?php foreach ($teklifDurumlari as $durum): ?>
                        <option value="<?= $durum['Teklif_Durumlari_id'] ?>"><?= htmlspecialchars($durum['Teklif_Durumlari_ad']) ?></option>
                        <?php endforeach; ?>
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
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
    let table;
    let currentFilters = {};

    $(document).ready(function() {
        // Select2 init
        $('#filter_tip_id, #filter_durum_id, #filter_hazirlayan_id').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true,
            placeholder: 'Seçiniz...'
        });

        // DataTable init
        table = $('#tekliflerTable').DataTable({
            processing: true,
            stateSave: true,
            serverSide: false,
            ajax: {
                url: '',
                type: 'POST',
                data: function() {
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
                {
                    data: 'Teklifler_no',
                    render: function(data, type, row) {
                        return '<a href="/Admin/teklif-form?id=' + row.Teklifler_id + '" class="text-primary fw-semibold text-decoration-none">' + data + '</a>';
                    }
                },
                {
                    data: 'tip_adi',
                    render: function(data, type, row) {
                        if (type === 'sort' || type === 'type') return data;
                        const cls = row.Teklifler_tip_id == 2 ? 'bg-warning' : 'bg-info';
                        const harf = data ? data.charAt(0).toUpperCase() : '-';
                        return '<span class="badge ' + cls + '" title="' + (data || '') + '">' + harf + '</span>';
                    }
                },
                {
                    data: 'Teklifler_tarih',
                    render: function(data, type) {
                        if (!data) return '-';
                        if (type === 'sort' || type === 'type') return data;
                        const p = data.split('-');
                        return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : data;
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        if (!data.cari_adi) return '-';
                        const MAX = 20;
                        const ad = data.cari_adi.length > MAX ? data.cari_adi.substring(0, MAX) + '…' : data.cari_adi;
                        let html = '<a href="/Admin/cari-form?id=' + data.cari_id + '" class="text-primary text-decoration-none" title="' + data.cari_adi + '">' + ad + '</a>';
                        if (data.cari_unvan) {
                            const unvan = data.cari_unvan.length > MAX ? data.cari_unvan.substring(0, MAX) + '…' : data.cari_unvan;
                            html += '<br><small class="text-muted" title="' + data.cari_unvan + '">' + unvan + '</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'hazirlayan_adi',
                    render: function(data) {
                        if (!data) return '-';
                        const parts = data.trim().split(' ');
                        const kisa = parts.length > 1
                            ? parts.slice(0, parts.length - 1).join(' ') + ' ' + parts[parts.length - 1].charAt(0) + '.'
                            : data;
                        return '<span class="text-muted">' + kisa + '</span>';
                    }
                },
                {
                    data: 'Teklifler_toplam_tutar',
                    className: 'text-end',
                    render: function(data, type, row) {
                        if (type === 'sort' || type === 'type') return parseFloat(data || 0);
                        return formatParaBirimi(data, row.para_birimi_simge || '₺');
                    }
                },
                {
                    data: 'Teklifler_toplam_kdv',
                    className: 'text-end',
                    render: function(data, type, row) {
                        if (type === 'sort' || type === 'type') return parseFloat(data || 0);
                        return formatParaBirimi(data, row.para_birimi_simge || '₺');
                    }
                },
                {
                    data: 'Teklifler_genel_toplam',
                    className: 'text-end',
                    render: function(data, type, row) {
                        if (type === 'sort' || type === 'type') return parseFloat(data || 0);
                        return '<strong>' + formatParaBirimi(data, row.para_birimi_simge || '₺') + '</strong>';
                    }
                },
                {
                    data: null,
                    className: 'text-center',
                    render: function(data) {
                        const renk = data.durum_renk || 'secondary';
                        let html = '<span class="badge bg-' + renk + ' cursor-pointer" onclick="durumDegistirModal(' + data.Teklifler_id + ', ' + data.Teklifler_durum_id + ')" title="Durum değiştir">' + (data.durum_adi || '-') + '</span>';
                        if (data.Teklifler_durum_id == 3 && data.aktif_kalem_sayisi > 0) {
                            html += '<br><span class="badge bg-primary mt-1 me-1" title="Miktarı > 0 olan / Toplam kalem">' + data.aktif_kalem_sayisi + '/' + data.kalem_sayisi + '</span>';
                            html += '<span class="badge bg-success mt-1" title="Stok yeterli olan / Toplam kalem">' + data.stok_tamamlanan_kalem + '/' + data.kalem_sayisi + '</span>';
                        }
                        return html;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    className: 'text-center text-nowrap',
                    render: function(data) {
                        let btns = '';

                        // PDF İndir butonu
                        btns += '<a href="/admin/pages/teklif-pdf.php?id=' + data.Teklifler_id + '" class="btn btn-sm btn-outline-danger me-1" title="PDF İndir" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a>';

                        <?php if ($pagePermissions['can_edit']): ?>
                        btns += '<a href="/Admin/teklif-form?id=' + data.Teklifler_id + '" class="btn btn-sm btn-info me-1" title="Düzenle"><i class="bi bi-pencil"></i></a>';
                        
                        // Mevcut fatura kontrolü
                        if (data.Teklifler_durum_id == 3) {
                            if (data.fatura_id) {
                                // Mevcut fatura var → o faturaya git
                                const ftrDurum = data.fatura_durum || 'Taslak';
                                const ftrNo = data.fatura_no || 'Fatura';
                                const badgeRenk = ftrDurum === 'Taslak' ? 'warning' : 'success';
                                btns += '<a href="/Admin/fatura-form?id=' + data.fatura_id + '" class="btn btn-sm btn-' + badgeRenk + ' me-1" title="' + ftrDurum + ': ' + ftrNo + '"><i class="bi bi-receipt"></i></a>';
                            } else {
                                // Fatura yok → yeni oluştur
                                btns += '<a href="/Admin/fatura-form?teklif_id=' + data.Teklifler_id + '" class="btn btn-sm btn-success me-1" title="Fatura Kes"><i class="bi bi-receipt"></i></a>';
                            }
                        }
                        <?php endif; ?>

                        // Gönderim dropdown
                        btns += '<div class="btn-group me-1" role="group">'
                              + '  <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Gönder">'
                              + '    <i class="bi bi-send"></i>'
                              + '  </button>'
                              + '  <ul class="dropdown-menu dropdown-menu-end">'
                              + '    <li><a class="dropdown-item" href="#" onclick="pdfGonderYonetim(' + data.Teklifler_id + ',\'WA_BANA\');return false;"><i class="bi bi-whatsapp me-2 text-success"></i>WA ile Gönder</a></li>'
                              + '    <li><a class="dropdown-item" href="#" onclick="pdfGonderYonetim(' + data.Teklifler_id + ',\'MAIL_BANA\');return false;"><i class="bi bi-envelope me-2 text-warning"></i>Bana Mail At</a></li>'
                              + '    <li><a class="dropdown-item" href="#" onclick="pdfGonderYonetim(' + data.Teklifler_id + ',\'MAIL_MUSTERI\');return false;"><i class="bi bi-envelope-fill me-2 text-primary"></i>Müşteriye Mail At</a></li>'
                              + '  </ul>'
                              + '</div>';

                        <?php if ($pagePermissions['can_delete']): ?>
                        btns += '<button class="btn btn-sm btn-danger" onclick="teklifSil(' + data.Teklifler_id + ')" title="Sil"><i class="bi bi-trash"></i></button>';
                        <?php endif; ?>

                        return btns;
                    }
                }
            ],
            order: [[0, 'desc']],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
            },
            pageLength: 25
        });

        // Stats yükle
        loadStats();

        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                tip_id: $('#filter_tip_id').val(),
                durum_id: $('#filter_durum_id').val(),
                cari_ara: $('#filter_cari_ara').val(),
                hazirlayan_id: $('#filter_hazirlayan_id').val(),
                start_date: $('#filter_start_date').val(),
                end_date: $('#filter_end_date').val()
            };
            table.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        });

        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_tip_id, #filter_durum_id, #filter_hazirlayan_id').val('').trigger('change.select2');
            currentFilters = {};
            table.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });

        // Excel export
        $('#btnExcelExport').on('click', function() {
            const data = table.rows({ search: 'applied' }).data().toArray();
            if (data.length === 0) {
                showToast('Dışa aktarılacak veri yok', 'warning');
                return;
            }

            let csv = [];
            csv.push(['Teklif No', 'Tip', 'Tarih', 'Cari', 'Ünvan', 'Hazırlayan', 'Ara Toplam', 'KDV', 'Genel Toplam', 'Durum'].map(h => '"' + h + '"').join(';'));

            data.forEach(row => {
                const tarih = row.Teklifler_tarih ? row.Teklifler_tarih.split('-').reverse().join('.') : '-';
                csv.push([
                    row.Teklifler_no || '-',
                    row.tip_adi || '-',
                    tarih,
                    row.cari_adi || '-',
                    row.cari_unvan || '-',
                    row.hazirlayan_adi || '-',
                    parseFloat(row.Teklifler_toplam_tutar || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2 }),
                    parseFloat(row.Teklifler_toplam_kdv || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2 }),
                    parseFloat(row.Teklifler_genel_toplam || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2 }),
                    row.durum_adi || '-'
                ].map(c => '"' + String(c).replace(/"/g, '""') + '"').join(';'));
            });

            const csvContent = '\uFEFF' + csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'teklif-listesi-' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Excel dosyası indirildi', 'success');
        });
    });

    // İstatistikleri yükle
    function loadStats() {
        $.post('', { action: 'stats' }, function(res) {
            if (res.success) {
                $('#stat_toplam').text(res.data.toplam);
                $('#stat_onaylanan').text(res.data.onaylanan);
                $('#stat_bekleyen').text(res.data.bekleyen);
                $('#stat_bu_ay').text(res.data.bu_ay);
            }
        });
    }

    // Para birimi formatla
    function formatParaBirimi(value, simge) {
        const num = parseFloat(value || 0);
        return num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (simge || '₺');
    }

    // Durum değiştirme modal
    function durumDegistirModal(id, mevcutDurum) {
        $('#durum_teklif_id').val(id);
        $('#durum_yeni_id').val(mevcutDurum);
        new bootstrap.Modal('#durumModal').show();
    }

    // Durum kaydet
    function durumKaydet() {
        const id = $('#durum_teklif_id').val();
        const durumId = $('#durum_yeni_id').val();

        $.post('', { action: 'durum_degistir', id: id, durum_id: durumId }, function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance('#durumModal').hide();
                table.ajax.reload(null, false);
                loadStats();
                showSuccess('Başarılı!', res.message);
            } else {
                showError('Hata!', res.message);
            }
        });
    }

    // Teklif sil
    function teklifSil(id) {
        confirmAction(
            'Bu teklifi silmek istediğinize emin misiniz?',
            'Teklife ait tüm kalemler de silinecektir!',
            function() {
                $.post('', { action: 'delete', id: id }, function(res) {
                    if (res.success) {
                        showSuccess('Silindi!', res.message);
                        table.ajax.reload(null, false);
                        loadStats();
                    } else {
                        showError('Hata!', res.message);
                    }
                });
            }
        );
    }

    function waGrupGonder(teklifId) {
        Swal.fire({
            title: 'Gruba WA Gönder',
            text: 'Onaylanan teklif kalemleri WhatsApp grubuna gönderilecek. Devam edilsin mi?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Gönder',
            cancelButtonText: 'Vazgeç',
        }).then(result => {
            if (!result.isConfirmed) return;
            showLoading('WhatsApp grubuna gönderiliyor...');
            $.post('', { action: 'wa_grup_gonder', id: teklifId }, function(res) {
                hideLoading();
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Gönderildi!', text: res.message || 'Mesaj gruba iletildi.', timer: 3000, showConfirmButton: false });
                } else {
                    showError('Hata!', res.message || 'Gönderilemedi.');
                }
            }, 'json').fail(function() {
                hideLoading();
                showError('Hata!', 'Sunucuya bağlanılamadı.');
            });
        });
    }

    function pdfGonderYonetim(teklifId, kanal) {
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
                url: '/admin/pages/teklif-form.php',
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
