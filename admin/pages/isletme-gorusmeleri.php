<?php
/**
 * Admin Panel - İşletme Görüşmeleri
 *
 * Tüm cari görüşme notlarının listesi ve yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

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
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'İşletme Görüşmeleri';
$pageDescription = $pageInfo['sayfalar_aciklama']  ?? '';
$menuAdi         = $pageInfo['menu_adi']            ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Dropdown filtreleri için veri
$gorusmeTipleri   = $db->fetchAll("SELECT GorusmeTipi_Id, GorusmeTipi_Adi, GorusmeTipi_Ikon, GorusmeTipi_Renk FROM Cari_GorusmeTipleri WHERE Durum = 1 ORDER BY GorusmeTipi_Sira");
$gorusmeDurumlari = $db->fetchAll("SELECT GorusmeDurum_Id, GorusmeDurum_Adi, GorusmeDurum_Renk FROM Cari_GorusmeDurumlari WHERE Durum = 1 ORDER BY GorusmeDurum_Sira");
$kullanicilar     = $db->fetchAll("SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad AS kullanici_adi FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad");

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'stats':
                $bugun = date('Y-m-d');
                $ayBasi = date('Y-m-01');

                // Yetki filtresi
                $statWhere = "WHERE g.Durum = 1";
                $statParams = [];
                if ($pagePermissions['can_view_own_records']) {
                    // Kendi görüşmeleri + kendisine devredilen carilerin görüşmeleri
                    $statWhere .= " AND (" . CariDevirHelper::orDevir(
                        "g.GorusmeNotlari_KullaniciId = ?",
                        (int)$user['kullanici_id'],
                        'g.GorusmeNotlari_CariId'
                    ) . ")";
                    $statParams[] = $user['kullanici_id'];
                }

                $toplam    = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Cari_GorusmeNotlari g $statWhere", $statParams)['sayi'] ?? 0;
                $buAy      = $db->fetchOne("SELECT COUNT(*) AS sayi FROM Cari_GorusmeNotlari g $statWhere AND CONVERT(date, g.GorusmeNotlari_GorusmeTarihi) >= ?", array_merge($statParams, [$ayBasi]))['sayi'] ?? 0;
                $randevular= $db->fetchOne("SELECT COUNT(*) AS sayi FROM Cari_GorusmeNotlari g $statWhere AND g.GorusmeNotlari_RandevuTarihi IS NOT NULL AND CONVERT(date, g.GorusmeNotlari_RandevuTarihi) >= ?", array_merge($statParams, [$bugun]))['sayi'] ?? 0;
                $takip     = $db->fetchOne("
                    SELECT COUNT(*) AS sayi FROM Cari_GorusmeNotlari g
                    INNER JOIN Cari_GorusmeDurumlari d ON g.GorusmeNotlari_DurumId = d.GorusmeDurum_Id
                    $statWhere AND d.GorusmeDurum_Adi = N'Takip Gerekli'
                ", $statParams)['sayi'] ?? 0;

                echo json_encode(['success' => true, 'data' => compact('toplam', 'buAy', 'randevular', 'takip')]);
                break;

            case 'list':
                $fSearch    = $_POST['search']    ?? '';
                $fTipId     = $_POST['tip_id']    ?? '';
                $fDurumId   = $_POST['durum_id']  ?? '';
                $fKullanici = $_POST['kullanici']  ?? '';
                $fStart     = $_POST['start_date'] ?? '';
                $fEnd       = $_POST['end_date']   ?? '';
                $fRandevu   = $_POST['randevu']    ?? '';

                $sql = "
                    SELECT
                        g.GorusmeNotlari_Id,
                        g.GorusmeNotlari_IletisimKisi,
                        g.GorusmeNotlari_Aciklama,
                        CONVERT(VARCHAR(16), g.GorusmeNotlari_GorusmeTarihi, 120) AS GorusmeTarihi,
                        CONVERT(VARCHAR(16), g.GorusmeNotlari_RandevuTarihi, 120) AS RandevuTarihi,
                        COALESCE(NULLIF(c.cari_adi, ''), c.cari_unvan) AS cari_unvan,
                        c.cari_id,
                        t.GorusmeTipi_Adi,
                        t.GorusmeTipi_Ikon,
                        t.GorusmeTipi_Renk,
                        d.GorusmeDurum_Adi,
                        d.GorusmeDurum_Renk,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS KullaniciAdi
                    FROM Cari_GorusmeNotlari g
                    LEFT JOIN Cari c                  ON g.GorusmeNotlari_CariId      = c.cari_id
                    LEFT JOIN Cari_GorusmeTipleri   t ON g.GorusmeNotlari_TipId       = t.GorusmeTipi_Id
                    LEFT JOIN Cari_GorusmeDurumlari d ON g.GorusmeNotlari_DurumId     = d.GorusmeDurum_Id
                    LEFT JOIN kullanicilar          k ON g.GorusmeNotlari_KullaniciId = k.kullanici_id
                    WHERE g.Durum = 1
                ";
                $params = [];

                if ($fSearch) {
                    $sql .= " AND (c.cari_unvan LIKE ? OR g.GorusmeNotlari_Aciklama LIKE ? OR g.GorusmeNotlari_IletisimKisi LIKE ?)";
                    $params = array_merge($params, ["%$fSearch%", "%$fSearch%", "%$fSearch%"]);
                }
                if ($fTipId)     { $sql .= " AND g.GorusmeNotlari_TipId = ?";     $params[] = intval($fTipId); }
                if ($fDurumId)   { $sql .= " AND g.GorusmeNotlari_DurumId = ?";   $params[] = intval($fDurumId); }
                if ($fKullanici) { $sql .= " AND g.GorusmeNotlari_KullaniciId = ?"; $params[] = intval($fKullanici); }
                if ($fStart)     { $sql .= " AND CONVERT(date, g.GorusmeNotlari_GorusmeTarihi) >= ?"; $params[] = $fStart; }
                if ($fEnd)       { $sql .= " AND CONVERT(date, g.GorusmeNotlari_GorusmeTarihi) <= ?"; $params[] = $fEnd; }
                if ($fRandevu === '1') {
                    $sql .= " AND g.GorusmeNotlari_RandevuTarihi IS NOT NULL AND CONVERT(date, g.GorusmeNotlari_RandevuTarihi) >= ?";
                    $params[] = date('Y-m-d');
                }

                // Yetki kontrolü: sadece kendi kayıtlarını görebiliyorsa filtrele
                if ($pagePermissions['can_view_own_records']) {
                    // Kendi görüşmeleri + kendisine devredilen carilerin görüşmeleri
                    $sql .= " AND (" . CariDevirHelper::orDevir(
                        "g.GorusmeNotlari_KullaniciId = ?",
                        (int)$user['kullanici_id'],
                        'g.GorusmeNotlari_CariId'
                    ) . ")";
                    $params[] = $user['kullanici_id'];
                }

                $sql .= " ORDER BY g.GorusmeNotlari_GorusmeTarihi DESC";

                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;

            case 'get_cariler':
                $q = $_POST['q'] ?? '';
                $cariler = $db->fetchAll("
                    SELECT TOP 20 cari_id, cari_unvan 
                    FROM Cari 
                    WHERE cari_aktif = 1 AND cari_unvan LIKE ? 
                    ORDER BY cari_unvan
                ", ["%$q%"]);
                echo json_encode(['success' => true, 'data' => $cariler]);
                break;

            case 'kaydet':
                $cari_id      = intval($_POST['cari_id'] ?? 0);
                $not_id       = intval($_POST['not_id']  ?? 0);
                $aciklama     = trim($_POST['aciklama']  ?? '');
                $gorusmeTarihi= trim($_POST['gorusme_tarihi'] ?? '');
                $randevuTarihi= trim($_POST['randevu_tarihi'] ?? '');
                $tipId        = !empty($_POST['tip_id'])   ? intval($_POST['tip_id'])   : null;
                $durumId      = !empty($_POST['durum_id']) ? intval($_POST['durum_id']) : null;
                $iletisimKisi = trim($_POST['iletisim_kisi'] ?? '');

                if (!$cari_id)         throw new Exception('Cari seçimi zorunludur');
                if (empty($aciklama))  throw new Exception('Açıklama zorunludur');
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
                break;

            case 'getir':
                $not_id = intval($_POST['not_id'] ?? 0);
                if (!$not_id) throw new Exception('Geçersiz ID');

                $not = $db->fetchOne("
                    SELECT
                        g.GorusmeNotlari_Id,
                        g.GorusmeNotlari_CariId,
                        g.GorusmeNotlari_TipId,
                        g.GorusmeNotlari_DurumId,
                        g.GorusmeNotlari_IletisimKisi,
                        g.GorusmeNotlari_Aciklama,
                        CONVERT(VARCHAR(16), g.GorusmeNotlari_GorusmeTarihi, 120) AS GorusmeTarihi,
                        CONVERT(VARCHAR(16), g.GorusmeNotlari_RandevuTarihi, 120) AS RandevuTarihi,
                        COALESCE(NULLIF(c.cari_adi, ''), c.cari_unvan) AS cari_unvan
                    FROM Cari_GorusmeNotlari g
                    LEFT JOIN Cari c ON g.GorusmeNotlari_CariId = c.cari_id
                    WHERE g.GorusmeNotlari_Id = ? AND g.Durum = 1
                ", [$not_id]);

                if (!$not) throw new Exception('Kayıt bulunamadı');
                echo json_encode(['success' => true, 'data' => $not]);
                break;

            case 'sil':
                if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz yok');
                $not_id = intval($_POST['not_id'] ?? 0);
                if (!$not_id) throw new Exception('Geçersiz ID');

                // can_view_own_records yetkisindeyse sadece kendi kaydını silebilir
                if ($pagePermissions['can_view_own_records']) {
                    $kayit = $db->fetchOne(
                        "SELECT GorusmeNotlari_KullaniciId FROM Cari_GorusmeNotlari WHERE GorusmeNotlari_Id = ? AND Durum = 1",
                        [$not_id]
                    );
                    if (!$kayit || $kayit['GorusmeNotlari_KullaniciId'] != $user['kullanici_id']) {
                        throw new Exception('Bu kaydı silme yetkiniz yok');
                    }
                }

                $db->update('Cari_GorusmeNotlari',
                    ['Durum' => 0, 'GuncelleyenKullanici' => $user['kullanici_id'], 'GuncellemeTarihi' => date('Y-m-d H:i:s')],
                    ['GorusmeNotlari_Id' => $not_id]
                );
                echo json_encode(['success' => true, 'message' => 'Görüşme notu silindi']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform .2s; }
        .info-box:hover { transform: translateY(-4px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .text-limit { 
            display: -webkit-box; 
            -webkit-line-clamp: 3; 
            -webkit-box-orient: vertical; 
            overflow: hidden; 
            word-break: break-all; 
            width: 10ch; 
            line-height: 1.2;
            max-height: 3.6em;
        }
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
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-chat-text"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Görüşme</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-calendar-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bu Ay</span>
                                <span class="info-box-number" id="stat-bu-ay">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-calendar-event"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Yaklaşan Randevu</span>
                                <span class="info-box-number" id="stat-randevu">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-exclamation-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Takip Gereken</span>
                                <span class="info-box-number" id="stat-takip">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Kartı -->
                <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                    </div>
                    <div class="card-body">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="filter_search" name="search" placeholder="Cari, açıklama, kişi...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Görüşme Tipi</label>
                                    <select class="form-select" id="filter_tip" name="tip_id">
                                        <option value="">Tümü</option>
                                        <?php foreach ($gorusmeTipleri as $t): ?>
                                            <option value="<?= $t['GorusmeTipi_Id'] ?>"><?= htmlspecialchars($t['GorusmeTipi_Adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select" id="filter_durum" name="durum_id">
                                        <option value="">Tümü</option>
                                        <?php foreach ($gorusmeDurumlari as $d): ?>
                                            <option value="<?= $d['GorusmeDurum_Id'] ?>"><?= htmlspecialchars($d['GorusmeDurum_Adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Kullanıcı</label>
                                    <select class="form-select" id="filter_kullanici" name="kullanici">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kullanicilar as $k): ?>
                                            <option value="<?= $k['kullanici_id'] ?>"><?= htmlspecialchars($k['kullanici_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="filter_randevu" name="randevu" value="1">
                                        <label class="form-check-label" for="filter_randevu">Sadece yaklaşan randevular</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Liste Kartı -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-list-ul"></i> Görüşme Listesi</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-success me-1" id="btnExcelIndir">
                                <i class="bi bi-file-earmark-excel"></i> Excel İndir
                            </button>
                            <?php if ($pagePermissions['can_add']): ?>
                            <button type="button" class="btn btn-sm btn-success me-1" onclick="modalAc()">
                                <i class="bi bi-plus-lg"></i> Yeni Görüşme
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtrele
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table id="gorusmeTable" class="table table-sm table-hover table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Cari</th>
                                        <th>Tip</th>
                                        <th>Durum</th>
                                        <th>Görüşme Tarihi</th>
                                        <th>Randevu</th>
                                        <th>İletişim Kişi</th>
                                        <th>Açıklama</th>
                                        <th>Kullanıcı</th>
                                        <th width="80">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody id="gorusmeTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

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
                    <div class="col-12">
                        <label class="form-label">Cari / İşletme <span class="text-danger">*</span></label>
                        <select class="form-select" id="modal_cari_id">
                            <option value="">Cari ara ve seçin...</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Görüşme Tarihi <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" id="modal_gorusme_tarihi">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Görüşme Tipi</label>
                        <select class="form-select" id="modal_tip_id">
                            <option value="">Seçiniz...</option>
                            <?php foreach ($gorusmeTipleri as $t): ?>
                                <option value="<?= $t['GorusmeTipi_Id'] ?>"><?= htmlspecialchars($t['GorusmeTipi_Adi']) ?></option>
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
                <button type="button" class="btn btn-primary" onclick="kaydet()"><i class="bi bi-check-lg"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
let dt;

$(document).ready(function () {
    // Select2
    $('#filter_tip, #filter_durum, #filter_kullanici').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, language: { noResults: () => 'Sonuç bulunamadı' } });
    $('#modal_tip_id, #modal_durum_id').select2({ theme: 'bootstrap-5', dropdownParent: $('#gorusmeModal'), width: '100%', allowClear: true, language: { noResults: () => 'Sonuç bulunamadı' } });

    // Cari AJAX arama
    $('#modal_cari_id').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#gorusmeModal'),
        width: '100%',
        allowClear: true,
        minimumInputLength: 1,
        language: {
            noResults: () => 'Sonuç bulunamadı',
            inputTooShort: () => 'En az 1 karakter girin',
            searching: () => 'Aranıyor...'
        },
        ajax: {
            url: window.location.href,
            type: 'POST',
            data: params => ({ action: 'get_cariler', q: params.term }),
            processResults: res => ({
                results: (res.data || []).map(c => ({ 
                    id: c.cari_id, 
                    text: c.cari_unvan
                }))
            })
        }
    });

    // DataTable
    dt = $('#gorusmeTable').DataTable({
        dom: '<"d-flex justify-content-between mb-2"lf>rtip',
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
        pageLength: 25,
        order: [[4, 'desc']],
        columnDefs: [{ orderable: false, targets: [9] }],
        buttons: [{
            extend: 'excel',
            title: 'İşletme Görüşmeleri',
            filename: 'isletme-gorusmeleri-' + new Date().toISOString().slice(0, 10),
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8],
                format: {
                    body: function (data) {
                        return $('<div>').html(data).text().trim();
                    }
                }
            }
        }]
    });

    $('#btnExcelIndir').on('click', function () {
        dt.button(0).trigger();
    });

    // İstatistikler
    istatistikYukle();

    // Filtre form
    $('#filterForm').on('submit', function (e) {
        e.preventDefault();
        listeYukle();
    });
    $('#clearFilters').on('click', function () {
        $('#filterForm')[0].reset();
        $('#filter_tip, #filter_durum, #filter_kullanici').val('').trigger('change');
        listeYukle();
    });

    // İlk yükleme
    listeYukle();
});

function istatistikYukle() {
    $.post('', { action: 'stats' }, function (res) {
        if (!res.success) return;
        $('#stat-toplam').text(res.data.toplam);
        $('#stat-bu-ay').text(res.data.buAy);
        $('#stat-randevu').text(res.data.randevular);
        $('#stat-takip').text(res.data.takip);
    }, 'json');
}

function listeYukle() {
    const fd = new FormData($('#filterForm')[0]);
    const data = { action: 'list' };
    fd.forEach((v, k) => { data[k] = v; });
    if ($('#filter_randevu').is(':checked')) data.randevu = '1';

    $.post('', data, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }

        dt.clear();
        res.data.forEach(function (g, i) {
            const tipBadge = g.GorusmeTipi_Adi
                ? `<span class="badge bg-${g.GorusmeTipi_Renk || 'secondary'}"><i class="${g.GorusmeTipi_Ikon || 'bi bi-chat-dots'}"></i> ${esc(g.GorusmeTipi_Adi)}</span>`
                : '<span class="text-muted">-</span>';

            const durumBadge = g.GorusmeDurum_Adi
                ? `<span class="badge bg-${g.GorusmeDurum_Renk || 'secondary'}">${esc(g.GorusmeDurum_Adi)}</span>`
                : '<span class="text-muted">-</span>';

            const randevuCol = g.RandevuTarihi
                ? `<span class="badge bg-info"><i class="bi bi-calendar-event"></i> ${g.RandevuTarihi}</span>`
                : '<span class="text-muted">-</span>';

            const islemler = `
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" title="Düzenle" onclick="modalDuzenle(${g.GorusmeNotlari_Id})"><i class="bi bi-pencil"></i></button>
                    <a class="btn btn-outline-secondary" href="/Admin/cari-form?id=${g.cari_id}" target="_blank" title="Cariye Git"><i class="bi bi-box-arrow-up-right"></i></a>
                    <button class="btn btn-outline-danger" title="Sil" onclick="sil(${g.GorusmeNotlari_Id})"><i class="bi bi-trash"></i></button>
                </div>`;

            dt.row.add([
                i + 1,
                `<div class="text-limit" title="${esc(g.cari_unvan)}">${esc(g.cari_unvan)}</div>`,
                tipBadge,
                durumBadge,
                g.GorusmeTarihi || '-',
                randevuCol,
                esc(g.GorusmeNotlari_IletisimKisi || '-'),
                `<div class="text-limit" title="${esc(g.GorusmeNotlari_Aciklama)}">${esc(g.GorusmeNotlari_Aciklama)}</div>`,
                esc(g.KullaniciAdi || '-'),
                islemler
            ]);
        });
        dt.draw();
        istatistikYukle();
    }, 'json');
}

function modalAc(notId = 0) {
    $('#modal_not_id').val(notId);
    $('#modal_cari_id').val('').trigger('change');
    $('#modal_gorusme_tarihi').val('');
    $('#modal_randevu_tarihi').val('');
    $('#modal_aciklama').val('');
    $('#modal_iletisim_kisi').val('');
    $('#modal_tip_id').val('').trigger('change');
    $('#modal_durum_id').val('').trigger('change');
    $('#gorusmeModalLabel').html('<i class="bi bi-chat-text"></i> ' + (notId > 0 ? 'Görüşme Notu Düzenle' : 'Yeni Görüşme Notu'));
    $('#gorusmeModal').modal('show');
}

function modalDuzenle(notId) {
    $.post('', { action: 'getir', not_id: notId }, function (res) {
        if (!res.success) { showToast(res.message, 'error'); return; }
        const d = res.data;

        // Cari seçeneğini Select2'ye ekle
        const cariOption = new Option(d.cari_unvan, d.GorusmeNotlari_CariId, true, true);
        $('#modal_cari_id').append(cariOption).trigger('change');

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

function kaydet() {
    const cariId   = $('#modal_cari_id').val();
    const tarih    = $('#modal_gorusme_tarihi').val().trim();
    const aciklama = $('#modal_aciklama').val().trim();

    if (!cariId)   { showToast('Cari seçimi zorunludur!', 'warning'); return; }
    if (!tarih)    { showToast('Görüşme tarihi zorunludur!', 'warning'); return; }
    if (!aciklama) { showToast('Açıklama zorunludur!', 'warning'); return; }

    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            action:         'kaydet',
            not_id:         $('#modal_not_id').val(),
            cari_id:        cariId,
            gorusme_tarihi: tarih.replace('T', ' '),
            randevu_tarihi: $('#modal_randevu_tarihi').val().replace('T', ' '),
            tip_id:         $('#modal_tip_id').val(),
            durum_id:       $('#modal_durum_id').val(),
            iletisim_kisi:  $('#modal_iletisim_kisi').val(),
            aciklama:       aciklama
        },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                showToast(res.message, 'success');
                $('#gorusmeModal').modal('hide');
                listeYukle();
            } else {
                showToast(res.message || 'Kayıt başarısız!', 'error');
            }
        },
        error: function (xhr) {
            console.error('AJAX Hatası:', xhr.status, xhr.responseText);
            showToast('Sunucu hatası (' + xhr.status + '). Konsolu kontrol edin.', 'error');
        }
    });
}

function sil(notId) {
    confirmAction('Bu görüşme notunu silmek istediğinize emin misiniz?', null, function () {
        $.post('', { action: 'sil', not_id: notId }, function (res) {
            if (res.success) {
                showToast('Görüşme notu silindi', 'success');
                listeYukle();
            } else {
                showToast(res.message, 'error');
            }
        }, 'json');
    });
}

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
