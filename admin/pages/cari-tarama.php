<?php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/CariTaramaHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();
$helper = new CariTaramaHelper($db);

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Bu sayfaya erişim yetkiniz bulunmamaktadır.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne(
    "SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
     FROM Menu_Sayfalar s
     LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1",
    ['%' . $currentPageFile]
);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cari Tarama';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? 'Domain bazlı cari ve yetkili adaylarını toplayan tarama ekranı';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.CariTaramaIsleri WHERE Durum = 1")['sayi'] ?? 0;
                $bekliyor = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.CariTaramaIsleri WHERE CariTaramaIsleri_Durum = 'Bekliyor' AND Durum = 1")['sayi'] ?? 0;
                $devamEdiyor = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.CariTaramaIsleri WHERE CariTaramaIsleri_Durum = 'Devam Ediyor' AND Durum = 1")['sayi'] ?? 0;
                $tamamlandi = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.CariTaramaIsleri WHERE CariTaramaIsleri_Durum = 'Tamamlandi' AND Durum = 1")['sayi'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplam', 'bekliyor', 'devamEdiyor', 'tamamlandi')]);
                break;

            case 'liste':
                $search = trim($_POST['search'] ?? '');
                $durum = trim($_POST['durum'] ?? '');
                $sql = "SELECT
                            i.CariTaramaIsleri_Id,
                            i.CariTaramaIsleri_Domain,
                            i.CariTaramaIsleri_BaslangicUrl,
                            i.CariTaramaIsleri_Durum,
                            i.CariTaramaIsleri_MaksimumSayfa,
                            i.CariTaramaIsleri_TarananSayfa,
                            i.CariTaramaIsleri_ToplamKayit,
                            i.CariTaramaIsleri_ToplamYetkili,
                            i.CariTaramaIsleri_HataMesaji,
                            i.CariTaramaIsleri_SonIslenenUrl,
                            CONVERT(VARCHAR(16), i.CariTaramaIsleri_BaslamaTarihi, 120) AS BaslamaTarihi,
                            CONVERT(VARCHAR(16), i.CariTaramaIsleri_BitisTarihi, 120) AS BitisTarihi,
                            CONVERT(VARCHAR(16), i.OlusturmaTarihi, 120) AS OlusturmaTarihi,
                            k.kullanici_ad + ' ' + k.kullanici_soyad AS OlusturanAdi
                        FROM dbo.CariTaramaIsleri i
                        LEFT JOIN dbo.kullanicilar k ON i.OlusturanKullanici = k.kullanici_id
                        WHERE i.Durum = 1";
                $params = [];

                if ($search !== '') {
                    $sql .= " AND (i.CariTaramaIsleri_Domain LIKE ? OR i.CariTaramaIsleri_BaslangicUrl LIKE ?)";
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                }
                if ($durum !== '') {
                    $sql .= " AND i.CariTaramaIsleri_Durum = ?";
                    $params[] = $durum;
                }

                $sql .= " ORDER BY i.CariTaramaIsleri_Id DESC";
                $rows = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'baslat':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Tarama başlatma yetkiniz yok.']);
                    break;
                }

                $domain = trim($_POST['domain'] ?? '');
                $startUrl = trim($_POST['baslangic_url'] ?? '');
                $maxPages = max(1, min(250, (int)($_POST['maksimum_sayfa'] ?? 50)));

                if ($domain === '' && $startUrl === '') {
                    throw new Exception('Domain veya başlangıç URL zorunludur.');
                }

                $hamUrl = $startUrl !== '' ? $startUrl : $domain;
                if (!preg_match('~^https?://~i', $hamUrl)) {
                    $hamUrl = 'https://' . $hamUrl;
                }

                $parsed = parse_url($hamUrl);
                if (empty($parsed['host'])) {
                    throw new Exception('Geçerli bir domain giriniz.');
                }

                $kayitId = (int)$db->insert('CariTaramaIsleri', [
                    'CariTaramaIsleri_Domain' => $parsed['host'],
                    'CariTaramaIsleri_BaslangicUrl' => rtrim($hamUrl, '/'),
                    'CariTaramaIsleri_Durum' => 'Bekliyor',
                    'CariTaramaIsleri_MaksimumSayfa' => $maxPages,
                    'CariTaramaIsleri_TarananSayfa' => 0,
                    'CariTaramaIsleri_ToplamKayit' => 0,
                    'CariTaramaIsleri_ToplamYetkili' => 0,
                    'OlusturanKullanici' => $user['kullanici_id'],
                    'OlusturmaTarihi' => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi' => date('Y-m-d H:i:s'),
                    'Durum' => 1
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Tarama işi kuyruğa alındı. Cron çalıştığında işlenecek.',
                    'tarama_id' => $kayitId
                ]);
                break;

            case 'detay':
                $isId = (int)($_POST['is_id'] ?? 0);
                if ($isId <= 0) {
                    throw new Exception('Geçersiz tarama işi.');
                }

                $isBilgi = $db->fetchOne(
                    "SELECT * FROM dbo.CariTaramaIsleri WHERE CariTaramaIsleri_Id = ? AND Durum = 1",
                    [$isId]
                );
                $kayit = $db->fetchOne(
                    "SELECT
                        k.CariTaramaKayitlari_Id,
                        k.CariTaramaKayitlari_IsId,
                        k.CariTaramaKayitlari_KaynakUrl,
                        k.CariTaramaKayitlari_FirmaAdi,
                        k.CariTaramaKayitlari_Unvan,
                        k.CariTaramaKayitlari_VergiNo,
                        k.CariTaramaKayitlari_VergiDairesi,
                        k.CariTaramaKayitlari_MersisNo,
                        k.CariTaramaKayitlari_TicaretSicilNo,
                        k.CariTaramaKayitlari_Telefon,
                        k.CariTaramaKayitlari_Email,
                        CAST(k.CariTaramaKayitlari_Adres AS NVARCHAR(500)) AS CariTaramaKayitlari_Adres,
                        k.CariTaramaKayitlari_SektorId,
                        k.CariTaramaKayitlari_SektorMetni,
                        k.CariTaramaKayitlari_SektorSkoru,
                        k.CariTaramaKayitlari_EfaturaMukellef,
                        k.CariTaramaKayitlari_EfaturaEtiket,
                        k.CariTaramaKayitlari_EfaturaTip,
                        k.CariTaramaKayitlari_Aktarildi,
                        k.CariTaramaKayitlari_AktarildiCariId,
                        s.CariSektor_Adi
                     FROM dbo.CariTaramaKayitlari k
                     LEFT JOIN dbo.Cari_Sektorler s ON k.CariTaramaKayitlari_SektorId = s.CariSektor_Id
                     WHERE k.CariTaramaKayitlari_IsId = ? AND k.Durum = 1",
                    [$isId]
                );
                $yetkililer = $db->fetchAll(
                    "SELECT
                        CariTaramaYetkilileri_Id,
                        CariTaramaYetkilileri_KayitId,
                        CariTaramaYetkilileri_KaynakUrl,
                        CariTaramaYetkilileri_Adi,
                        CariTaramaYetkilileri_Soyadi,
                        CariTaramaYetkilileri_Gorev,
                        CariTaramaYetkilileri_Email,
                        CariTaramaYetkilileri_Telefon,
                        CariTaramaYetkilileri_Telefon2,
                        CariTaramaYetkilileri_GuvenSkoru,
                        CariTaramaYetkilileri_Aktarildi,
                        CariTaramaYetkilileri_AktarildiYetkiliId
                     FROM dbo.CariTaramaYetkilileri
                     WHERE CariTaramaYetkilileri_KayitId = ? AND Durum = 1
                     ORDER BY CariTaramaYetkilileri_GuvenSkoru DESC, CariTaramaYetkilileri_Id ASC",
                    [$kayit['CariTaramaKayitlari_Id'] ?? 0]
                );

                echo json_encode([
                    'success' => true,
                    'is' => $isBilgi,
                    'kayit' => $kayit,
                    'yetkililer' => $yetkililer
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'aktar':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Aktarım yetkiniz yok.']);
                    break;
                }

                $kayitId = (int)($_POST['kayit_id'] ?? 0);
                $yetkiliIds = json_decode($_POST['yetkili_ids'] ?? '[]', true);
                if (!is_array($yetkiliIds)) {
                    $yetkiliIds = [];
                }

                $sonuc = $helper->importRecord($kayitId, $yetkiliIds, (int)$user['kullanici_id']);
                echo json_encode([
                    'success' => true,
                    'message' => 'Seçilen kayıtlar cari kartlarına aktarıldı.',
                    'data' => $sonuc
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'ilerleme':
                $isId = (int)($_POST['is_id'] ?? 0);
                $row = $db->fetchOne(
                    "SELECT CariTaramaIsleri_Durum, CariTaramaIsleri_TarananSayfa, CariTaramaIsleri_ToplamKayit, CariTaramaIsleri_ToplamYetkili, CariTaramaIsleri_HataMesaji, CariTaramaIsleri_SonIslenenUrl
                     FROM dbo.CariTaramaIsleri
                     WHERE CariTaramaIsleri_Id = ? AND Durum = 1",
                    [$isId]
                );
                echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
                break;

            case 'guncelle_kayit':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']);
                    break;
                }
                $kayitId = (int)($_POST['kayit_id'] ?? 0);
                if ($kayitId <= 0) throw new Exception('Geçersiz kayıt ID.');
                $db->execute(
                    "UPDATE dbo.CariTaramaKayitlari SET
                        CariTaramaKayitlari_FirmaAdi     = ?,
                        CariTaramaKayitlari_Unvan         = ?,
                        CariTaramaKayitlari_VergiNo       = ?,
                        CariTaramaKayitlari_VergiDairesi  = ?,
                        CariTaramaKayitlari_Telefon       = ?,
                        CariTaramaKayitlari_Email         = ?,
                        CariTaramaKayitlari_Adres         = ?,
                        CariTaramaKayitlari_SektorId      = ?,
                        GuncelleyenKullanici               = ?,
                        GuncellemeTarihi                   = ?
                     WHERE CariTaramaKayitlari_Id = ? AND Durum = 1",
                    [
                        trim($_POST['firma_adi']     ?? '') ?: null,
                        trim($_POST['unvan']         ?? '') ?: null,
                        trim($_POST['vergi_no']      ?? '') ?: null,
                        trim($_POST['vergi_dairesi'] ?? '') ?: null,
                        trim($_POST['telefon']       ?? '') ?: null,
                        trim($_POST['email']         ?? '') ?: null,
                        trim($_POST['adres']         ?? '') ?: null,
                        !empty($_POST['sektor_id']) ? (int)$_POST['sektor_id'] : null,
                        $user['kullanici_id'],
                        date('Y-m-d H:i:s'),
                        $kayitId
                    ]
                );
                echo json_encode(['success' => true, 'message' => 'Firma bilgileri güncellendi.']);
                break;

            case 'guncelle_yetkili':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']);
                    break;
                }
                $yetkiliId = (int)($_POST['yetkili_id'] ?? 0);
                if ($yetkiliId <= 0) throw new Exception('Geçersiz yetkili ID.');
                $db->execute(
                    "UPDATE dbo.CariTaramaYetkilileri SET
                        CariTaramaYetkilileri_Adi      = ?,
                        CariTaramaYetkilileri_Soyadi   = ?,
                        CariTaramaYetkilileri_Gorev    = ?,
                        CariTaramaYetkilileri_Telefon  = ?,
                        CariTaramaYetkilileri_Email    = ?,
                        GuncelleyenKullanici            = ?,
                        GuncellemeTarihi                = ?
                     WHERE CariTaramaYetkilileri_Id = ? AND Durum = 1",
                    [
                        trim($_POST['adi']     ?? '') ?: null,
                        trim($_POST['soyadi']  ?? '') ?: null,
                        trim($_POST['gorev']   ?? '') ?: null,
                        trim($_POST['telefon'] ?? '') ?: null,
                        trim($_POST['email']   ?? '') ?: null,
                        $user['kullanici_id'],
                        date('Y-m-d H:i:s'),
                        $yetkiliId
                    ]
                );
                echo json_encode(['success' => true, 'message' => 'Yetkili bilgileri güncellendi.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

$sektorler = $db->fetchAll("SELECT CariSektor_Id, CariSektor_Adi FROM dbo.Cari_Sektorler WHERE Durum = 1 ORDER BY CariSektor_Sira, CariSektor_Adi");
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform .2s; }
        .info-box:hover { transform: translateY(-3px); }
        .job-row-active { background: rgba(13, 110, 253, .08); }
        .table-sm td, .table-sm th { vertical-align: middle; }
        .result-card dl { margin-bottom: 0; }
        .result-card dt { font-size: .82rem; color: #6c757d; }
        .result-card dd { font-weight: 600; margin-bottom: .9rem; word-break: break-word; }
        #firmaDetay .form-label { font-size: .78rem; color: #6c757d; margin-bottom: 1px; }
        #firmaDetay .form-control-sm, #firmaDetay .form-select-sm { font-size: .83rem; }
        .btn-xs { padding: .15rem .4rem; font-size: .78rem; line-height: 1.3; }
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
                        <div class="text-muted small"><?= htmlspecialchars($pageDescription) ?></div>
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
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-globe2"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam İş</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bekleyen</span>
                                <span class="info-box-number" id="stat-bekliyor">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-arrow-repeat"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Devam Eden</span>
                                <span class="info-box-number" id="stat-devam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Tamamlanan</span>
                                <span class="info-box-number" id="stat-tamamlandi">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-lg-4">
                        <div class="card card-primary card-outline h-100">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-play-circle"></i> Yeni Tarama</h3>
                            </div>
                            <div class="card-body">
                                <form id="taramaForm">
                                    <div class="mb-3">
                                        <label class="form-label">Domain</label>
                                        <input type="text" class="form-control" id="domain" name="domain" placeholder="ornek.com.tr">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Başlangıç URL</label>
                                        <input type="text" class="form-control" id="baslangic_url" name="baslangic_url" placeholder="https://ornek.com.tr/iletisim">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Maksimum Sayfa</label>
                                        <select class="form-select select2-basic" id="maksimum_sayfa" name="maksimum_sayfa">
                                            <option value="25">25</option>
                                            <option value="50" selected>50</option>
                                            <option value="100">100</option>
                                            <option value="150">150</option>
                                            <option value="250">250</option>
                                        </select>
                                    </div>
                                    <div class="alert alert-light border small mb-3">
                                        Sayfa cron tabanlı çalışır. Bu ekran işi kuyruğa ekler, `cron/cari-tarama.php` bekleyen işi işler.
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100" <?= !$pagePermissions['can_add'] ? 'disabled' : '' ?>>
                                        <i class="bi bi-play-fill"></i> Taramayı Başlat
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="card card-primary card-outline h-100">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel"></i> İş Listesi</h3>
                            </div>
                            <div class="card-body">
                                <div class="row g-2 mb-3">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control" id="filter_search" placeholder="Domain veya URL ara...">
                                    </div>
                                    <div class="col-md-4">
                                        <select class="form-select select2-basic" id="filter_durum">
                                            <option value="">Tüm Durumlar</option>
                                            <option value="Bekliyor">Bekliyor</option>
                                            <option value="Devam Ediyor">Devam Ediyor</option>
                                            <option value="Tamamlandi">Tamamlandı</option>
                                            <option value="Hata">Hata</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 d-grid">
                                        <button type="button" class="btn btn-secondary" id="btnListele"><i class="bi bi-search"></i> Listele</button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Domain</th>
                                                <th>Durum</th>
                                                <th>Sayfa</th>
                                                <th>Kayıt</th>
                                                <th>Oluşturma</th>
                                                <th>İşlem</th>
                                            </tr>
                                        </thead>
                                        <tbody id="jobTableBody">
                                            <tr><td colspan="7" class="text-center text-muted py-4">Henüz tarama işi yok.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-5">
                        <div class="card card-success card-outline result-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-building"></i> Firma Kaydı</h3>
                            </div>
                            <div class="card-body" id="firmaDetay">
                                <div class="text-muted text-center py-5">Bir tarama işi seçin.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="card card-success card-outline">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h3 class="card-title mb-0"><i class="bi bi-people"></i> Yetkili Adayları</h3>
                                <button type="button" class="btn btn-success btn-sm" id="btnAktar" disabled>
                                    <i class="bi bi-box-arrow-in-right"></i> Seçilileri Aktar
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="50"><input type="checkbox" id="checkAllYetkili"></th>
                                                <th>Ad Soyad</th>
                                                <th>Görev</th>
                                                <th>Telefon</th>
                                                <th>E-posta</th>
                                                <th>Skor</th>
                                                <th width="40"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="yetkiliTableBody">
                                            <tr><td colspan="7" class="text-center text-muted py-4">Bir tarama işi seçin.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Yetkili Düzenleme Modalı -->
    <div class="modal fade" id="modalYetkiliDuzenle" tabindex="-1" aria-labelledby="modalYetkiliDuzenleLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalYetkiliDuzenleLabel"><i class="bi bi-person-gear"></i> Yetkili Düzenle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editYetkiliId">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Ad</label>
                            <input type="text" class="form-control" id="editYetkiliAdi" placeholder="Ad">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Soyad</label>
                            <input type="text" class="form-control" id="editYetkiliSoyadi" placeholder="Soyad">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Görev / Ünvan</label>
                            <input type="text" class="form-control" id="editYetkiliGorev" placeholder="Müdür, Uzman...">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" id="editYetkiliTelefon" placeholder="05xx xxx xx xx">
                        </div>
                        <div class="col-6">
                            <label class="form-label">E-posta</label>
                            <input type="email" class="form-control" id="editYetkiliEmail" placeholder="ornek@domain.com">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-primary" id="btnYetkiliKaydet"><i class="bi bi-floppy"></i> Kaydet</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const sektorler = <?= json_encode(array_values($sektorler ?? []), JSON_UNESCAPED_UNICODE) ?>;
    let activeJobId = 0;
    let activeKayitId = 0;

    $(function() {
        $('.select2-basic').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: false,
            language: {
                noResults: function() { return 'Sonuç bulunamadı'; },
                searching: function() { return 'Aranıyor...'; }
            }
        });

        loadStats();
        loadJobs();

        $('#btnListele').on('click', loadJobs);
        $('#taramaForm').on('submit', function(event) {
            event.preventDefault();
            startTarama();
        });
        $('#checkAllYetkili').on('change', function() {
            $('.yetkili-check').prop('checked', $(this).is(':checked'));
        });
        $('#btnAktar').on('click', aktarSecilenler);

        // Yetkili düzenle modal — edit butonuna tıklama
        $(document).on('click', '.btn-edit-yetkili', function() {
            const btn = $(this);
            $('#editYetkiliId').val(btn.data('id'));
            $('#editYetkiliAdi').val(btn.data('adi') || '');
            $('#editYetkiliSoyadi').val(btn.data('soyadi') || '');
            $('#editYetkiliGorev').val(btn.data('gorev') || '');
            $('#editYetkiliTelefon').val(btn.data('telefon') || '');
            $('#editYetkiliEmail').val(btn.data('email') || '');
            $('#modalYetkiliDuzenle').modal('show');
        });

        $('#btnYetkiliKaydet').on('click', saveYetkili);

        setInterval(function() {
            loadStats();
            loadJobs(false);
            if (activeJobId > 0) {
                loadDetail(activeJobId, false);
            }
        }, 15000);
    });

    function loadStats() {
        $.post('', { action: 'stats' }, function(response) {
            if (!response.success) {
                return;
            }
            $('#stat-toplam').text(response.data.toplam || 0);
            $('#stat-bekliyor').text(response.data.bekliyor || 0);
            $('#stat-devam').text(response.data.devamEdiyor || 0);
            $('#stat-tamamlandi').text(response.data.tamamlandi || 0);
        }, 'json');
    }

    function loadJobs(resetSelection = true) {
        $.post('', {
            action: 'liste',
            search: $('#filter_search').val(),
            durum: $('#filter_durum').val()
        }, function(response) {
            if (!response.success) {
                showError('Hata', response.message || 'Liste alınamadı.');
                return;
            }

            const tbody = $('#jobTableBody');
            tbody.empty();

            if (!response.data.length) {
                tbody.html('<tr><td colspan="7" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>');
                if (resetSelection) {
                    resetDetail();
                }
                return;
            }

            response.data.forEach(function(row) {
                const activeClass = Number(row.CariTaramaIsleri_Id) === Number(activeJobId) ? 'job-row-active' : '';
                tbody.append(`
                    <tr class="${activeClass}">
                        <td>${row.CariTaramaIsleri_Id}</td>
                        <td>
                            <div class="fw-semibold">${escapeHtml(row.CariTaramaIsleri_Domain || '')}</div>
                            <div class="small text-muted text-truncate" style="max-width:240px">${escapeHtml(row.CariTaramaIsleri_BaslangicUrl || '')}</div>
                        </td>
                        <td>${renderDurum(row.CariTaramaIsleri_Durum)}</td>
                        <td>${row.CariTaramaIsleri_TarananSayfa || 0} / ${row.CariTaramaIsleri_MaksimumSayfa || 0}</td>
                        <td>${row.CariTaramaIsleri_ToplamKayit || 0} / ${row.CariTaramaIsleri_ToplamYetkili || 0}</td>
                        <td>
                            <div>${row.OlusturmaTarihi || '-'}</div>
                            <div class="small text-muted">${escapeHtml(row.OlusturanAdi || '')}</div>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadDetail(${row.CariTaramaIsleri_Id})">
                                <i class="bi bi-search"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });
        }, 'json');
    }

    function startTarama() {
        $.post('', {
            action: 'baslat',
            domain: $('#domain').val().trim(),
            baslangic_url: $('#baslangic_url').val().trim(),
            maksimum_sayfa: $('#maksimum_sayfa').val()
        }, function(response) {
            if (!response.success) {
                showError('Hata', response.message || 'Tarama başlatılamadı.');
                return;
            }

            showSuccess('Başarılı', response.message);
            $('#taramaForm')[0].reset();
            $('#maksimum_sayfa').val('50').trigger('change');
            loadStats();
            loadJobs();
            if (response.tarama_id) {
                loadDetail(response.tarama_id);
            }
        }, 'json');
    }

    function loadDetail(jobId, notify = false) {
        activeJobId = Number(jobId);

        $.post('', { action: 'detay', is_id: jobId }, function(response) {
            if (!response.success) {
                if (notify) {
                    showError('Hata', response.message || 'Detay yüklenemedi.');
                }
                return;
            }

            renderCompany(response.kayit, response.is || {});
            renderContacts(response.yetkililer || []);
            loadJobs(false);
        }, 'json');
    }

    function renderCompany(kayit, job) {
        const target = $('#firmaDetay');
        if (!kayit) {
            activeKayitId = 0;
            $('#btnAktar').prop('disabled', true);
            target.html(`
                <div class="alert alert-light border mb-0">
                    <div class="fw-semibold mb-1">Tarama henüz sonuç üretmedi.</div>
                    <div class="small text-muted">Durum: ${escapeHtml(job.CariTaramaIsleri_Durum || 'Bekliyor')}</div>
                    ${job.CariTaramaIsleri_HataMesaji ? `<div class="small text-danger mt-2">${escapeHtml(job.CariTaramaIsleri_HataMesaji)}</div>` : ''}
                </div>
            `);
            return;
        }

        activeKayitId = Number(kayit.CariTaramaKayitlari_Id);
        $('#btnAktar').prop('disabled', false);

        // Sektör seçeneklerini oluştur
        let sektorOptions = '<option value="">-- Seçiniz --</option>';
        sektorler.forEach(function(s) {
            const sel = Number(s.CariSektor_Id) === Number(kayit.CariTaramaKayitlari_SektorId) ? ' selected' : '';
            sektorOptions += `<option value="${s.CariSektor_Id}"${sel}>${escapeHtml(s.CariSektor_Adi)}</option>`;
        });

        target.html(`
            <form id="firmaEditForm">
                <input type="hidden" name="kayit_id" value="${kayit.CariTaramaKayitlari_Id}">
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label">Firma Adı</label>
                        <input type="text" class="form-control form-control-sm" name="firma_adi" value="${escapeAttr(kayit.CariTaramaKayitlari_FirmaAdi)}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Ünvan</label>
                        <input type="text" class="form-control form-control-sm" name="unvan" value="${escapeAttr(kayit.CariTaramaKayitlari_Unvan)}">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Vergi No</label>
                        <input type="text" class="form-control form-control-sm" name="vergi_no" value="${escapeAttr(kayit.CariTaramaKayitlari_VergiNo)}">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Vergi Dairesi</label>
                        <input type="text" class="form-control form-control-sm" name="vergi_dairesi" value="${escapeAttr(kayit.CariTaramaKayitlari_VergiDairesi)}">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Telefon</label>
                        <input type="text" class="form-control form-control-sm" name="telefon" value="${escapeAttr(kayit.CariTaramaKayitlari_Telefon)}">
                    </div>
                    <div class="col-6">
                        <label class="form-label">E-posta</label>
                        <input type="email" class="form-control form-control-sm" name="email" value="${escapeAttr(kayit.CariTaramaKayitlari_Email)}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Adres</label>
                        <textarea class="form-control form-control-sm" name="adres" rows="2">${escapeHtml(kayit.CariTaramaKayitlari_Adres || '')}</textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Sektör</label>
                        <select class="form-select form-select-sm" name="sektor_id" id="firmaEditSektor">
                            ${sektorOptions}
                        </select>
                    </div>
                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            E-Fatura: ${Number(kayit.CariTaramaKayitlari_EfaturaMukellef || 0) === 1 ? '<span class="text-success fw-semibold">Mükellef</span>' : 'Bulunamadı'}
                            &nbsp;|&nbsp;
                            <a href="${escapeHtml(kayit.CariTaramaKayitlari_KaynakUrl || '#')}" target="_blank" class="text-truncate d-inline-block" style="max-width:160px;vertical-align:bottom">${escapeHtml(kayit.CariTaramaKayitlari_KaynakUrl || '-')}</a>
                        </small>
                    </div>
                    <div class="col-12 mt-1">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-floppy"></i> Değişiklikleri Kaydet
                        </button>
                    </div>
                </div>
            </form>
        `);

        setTimeout(function() {
            $('#firmaEditSektor').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $('#firmaDetay'),
                allowClear: true,
                placeholder: '-- Seçiniz --',
                language: {
                    noResults: function() { return 'Sonuç bulunamadı'; },
                    searching: function() { return 'Aranıyor...'; }
                }
            });
        }, 50);

        $('#firmaEditForm').on('submit', function(e) {
            e.preventDefault();
            saveKayit($(this));
        });
    }

    function renderContacts(rows) {
        const tbody = $('#yetkiliTableBody');
        tbody.empty();
        $('#checkAllYetkili').prop('checked', false);

        if (!rows.length) {
            tbody.html('<tr><td colspan="7" class="text-center text-muted py-4">Yetkili adayı bulunamadı.</td></tr>');
            return;
        }

        rows.forEach(function(row) {
            const name = [row.CariTaramaYetkilileri_Adi, row.CariTaramaYetkilileri_Soyadi].filter(Boolean).join(' ');
            tbody.append(`
                <tr>
                    <td><input type="checkbox" class="form-check-input yetkili-check" value="${row.CariTaramaYetkilileri_Id}" checked></td>
                    <td>
                        <div class="fw-semibold">${escapeHtml(name || '-')}</div>
                        <div class="small text-muted text-truncate" style="max-width:180px">${escapeHtml(row.CariTaramaYetkilileri_KaynakUrl || '')}</div>
                    </td>
                    <td>${escapeHtml(row.CariTaramaYetkilileri_Gorev || '-')}</td>
                    <td>${escapeHtml(row.CariTaramaYetkilileri_Telefon || '-')}</td>
                    <td>${escapeHtml(row.CariTaramaYetkilileri_Email || '-')}</td>
                    <td>${escapeHtml(row.CariTaramaYetkilileri_GuvenSkoru || '0')}</td>
                    <td>
                        <button type="button" class="btn btn-xs btn-outline-secondary btn-edit-yetkili"
                            data-id="${row.CariTaramaYetkilileri_Id}"
                            data-adi="${escapeAttr(row.CariTaramaYetkilileri_Adi)}"
                            data-soyadi="${escapeAttr(row.CariTaramaYetkilileri_Soyadi)}"
                            data-gorev="${escapeAttr(row.CariTaramaYetkilileri_Gorev)}"
                            data-telefon="${escapeAttr(row.CariTaramaYetkilileri_Telefon)}"
                            data-email="${escapeAttr(row.CariTaramaYetkilileri_Email)}">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
            `);
        });
    }

    function saveKayit($form) {
        const btn = $form.find('button[type="submit"]');
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Kaydediliyor...');
        $.post('', {
            action:         'guncelle_kayit',
            kayit_id:       $form.find('[name="kayit_id"]').val(),
            firma_adi:      $form.find('[name="firma_adi"]').val(),
            unvan:          $form.find('[name="unvan"]').val(),
            vergi_no:       $form.find('[name="vergi_no"]').val(),
            vergi_dairesi:  $form.find('[name="vergi_dairesi"]').val(),
            telefon:        $form.find('[name="telefon"]').val(),
            email:          $form.find('[name="email"]').val(),
            adres:          $form.find('[name="adres"]').val(),
            sektor_id:      $form.find('[name="sektor_id"]').val()
        }, function(response) {
            btn.prop('disabled', false).html('<i class="bi bi-floppy"></i> Değişiklikleri Kaydet');
            if (!response.success) {
                showError('Hata', response.message || 'Kayıt güncellenemedi.');
                return;
            }
            showSuccess('Başarılı', response.message);
        }, 'json');
    }

    function saveYetkili() {
        const yetkiliId = $('#editYetkiliId').val();
        if (!yetkiliId) {
            return;
        }
        $.post('', {
            action:    'guncelle_yetkili',
            yetkili_id: yetkiliId,
            adi:        $('#editYetkiliAdi').val(),
            soyadi:     $('#editYetkiliSoyadi').val(),
            gorev:      $('#editYetkiliGorev').val(),
            telefon:    $('#editYetkiliTelefon').val(),
            email:      $('#editYetkiliEmail').val()
        }, function(response) {
            if (!response.success) {
                showError('Hata', response.message || 'Yetkili güncellenemedi.');
                return;
            }
            showSuccess('Başarılı', response.message);
            $('#modalYetkiliDuzenle').modal('hide');
            loadDetail(activeJobId, false);
        }, 'json');
    }

    function escapeAttr(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function aktarSecilenler() {
        if (!activeKayitId) {
            showWarning('Uyarı', 'Önce bir tarama kaydı seçin.');
            return;
        }

        const ids = $('.yetkili-check:checked').map(function() {
            return Number($(this).val());
        }).get();

        $.post('', {
            action: 'aktar',
            kayit_id: activeKayitId,
            yetkili_ids: JSON.stringify(ids)
        }, function(response) {
            if (!response.success) {
                showError('Hata', response.message || 'Aktarım tamamlanamadı.');
                return;
            }

            showSuccess('Başarılı', response.message);
            loadDetail(activeJobId);
        }, 'json');
    }

    function resetDetail() {
        activeJobId = 0;
        activeKayitId = 0;
        $('#btnAktar').prop('disabled', true);
        $('#firmaDetay').html('<div class="text-muted text-center py-5">Bir tarama işi seçin.</div>');
        $('#yetkiliTableBody').html('<tr><td colspan="7" class="text-center text-muted py-4">Bir tarama işi seçin.</td></tr>');
    }

    function renderDurum(durum) {
        const map = {
            'Bekliyor': 'secondary',
            'Devam Ediyor': 'warning',
            'Tamamlandi': 'success',
            'Hata': 'danger'
        };
        const color = map[durum] || 'light';
        return `<span class="badge bg-${color}">${escapeHtml(durum || '-')}</span>`;
    }

    function renderSuggestionList(raw) {
        if (!raw) {
            return '-';
        }
        try {
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed) || !parsed.length) {
                return '-';
            }
            return parsed.map(escapeHtml).join(', ');
        } catch (error) {
            return escapeHtml(raw);
        }
    }

    function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
</script>
</body>
</html>