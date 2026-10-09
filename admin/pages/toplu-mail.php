<?php
/**
 * Admin Panel - Toplu Mail Gönderimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Bu sayfaya erişim yetkiniz bulunmamaktadır.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Toplu Mail Gönderimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─────────────────────────────────────────────
// AJAX İşlemleri
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // post_max_size aşıldıysa PHP tüm $_POST verisini siler
    if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $maxPost = ini_get('post_max_size');
        echo json_encode(['success' => false, 'message' => 'Toplam yükleme boyutu sunucu limitini aşıyor. (post_max_size: ' . $maxPost . ') Dosya boyutlarını küçültün veya daha az dosya ekleyin.']);
        exit;
    }

    try {
        $action = $_POST['action'] ?? '';

        switch ($action) {

            // ── İstatistikler ──────────────────────────────
            case 'stats':
                $toplam       = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.TopluMailGonderimi WHERE Durum = 1")['s'] ?? 0;
                $devamEden    = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.TopluMailGonderimi WHERE Gonderim_Durum = 'Devam Ediyor' AND Durum = 1")['s'] ?? 0;
                $tamamlandi   = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.TopluMailGonderimi WHERE Gonderim_Durum = 'Tamamlandı' AND Durum = 1")['s'] ?? 0;
                $bekliyor     = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.TopluMailGonderimi WHERE Gonderim_Durum = 'Bekliyor' AND Durum = 1")['s'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplam', 'devamEden', 'tamamlandi', 'bekliyor')]);
                break;

            // ── Kampanya Listesi ───────────────────────────
            case 'liste':
                $durum  = $_POST['durum'] ?? '';
                $search = trim($_POST['search'] ?? '');
                $where  = "WHERE g.Durum = 1";
                $params = [];
                if ($durum !== '') {
                    $where .= " AND g.Gonderim_Durum = ?";
                    $params[] = $durum;
                }
                if ($search !== '') {
                    $where .= " AND g.Gonderim_Konu LIKE ?";
                    $params[] = '%' . $search . '%';
                }
                $rows = $db->fetchAll("
                    SELECT
                        g.Gonderim_Id,
                        g.Gonderim_Konu,
                        g.Gonderim_Durum,
                        g.Gonderim_Toplam,
                        g.Gonderim_Gonderilen,
                        g.Gonderim_Hatali,
                        k.Kanal_Adi,
                        k.Kanal_Kullanici AS Kanal_Email,
                        CONVERT(VARCHAR(16), g.OlusturmaTarihi, 120) AS OlusturmaTarihi,
                        CONVERT(VARCHAR(16), g.Gonderim_Baslama_Tarihi, 120) AS BaslamaTarihi,
                        CONVERT(VARCHAR(16), g.Gonderim_Bitis_Tarihi, 120) AS BitisTarihi,
                        u.kullanici_ad + ' ' + u.kullanici_soyad AS OlusturanAdi
                    FROM dbo.TopluMailGonderimi g
                    LEFT JOIN dbo.EntegrasyonKanallari k ON g.Gonderim_Kanal_Id = k.Kanal_Id
                    LEFT JOIN dbo.kullanicilar u ON g.OlusturanKullanici = u.kullanici_id
                    $where
                    ORDER BY g.Gonderim_Id DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            // ── Mail Kanallarını Getir ─────────────────────
            case 'kanallari_getir':
                $kanallar = $db->fetchAll("
                    SELECT k.Kanal_Id, k.Kanal_Adi, k.Kanal_Kullanici
                    FROM dbo.EntegrasyonKanallari k
                    INNER JOIN dbo.Entegrasyon e ON k.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                    WHERE e.Entegrasyon_Kod = 'MAIL' AND k.Kanal_Durum = 1
                    ORDER BY k.Kanal_Varsayilan DESC, k.Kanal_Adi
                ");
                echo json_encode(['success' => true, 'data' => $kanallar]);
                break;

            // ── Alıcıları Getir (Cariler / Kullanıcılar) ──
            case 'alicilari_getir':
                $kaynak = $_POST['kaynak'] ?? '';
                $alicilar = [];
                if ($kaynak === 'cariler') {
                    $alicilar = $db->fetchAll("
                                                SELECT c.cari_email AS email, c.cari_adi AS ad
                                                FROM dbo.Cari c
                                                WHERE c.cari_email IS NOT NULL AND LEN(TRIM(c.cari_email)) > 0
                                                    AND ISNULL(c.cari_aktif, 1) = 1
                                                ORDER BY c.cari_adi
                    ");
                } elseif ($kaynak === 'kullanicilar') {
                    $alicilar = $db->fetchAll("
                        SELECT kullanici_email AS email,
                               kullanici_ad + ' ' + kullanici_soyad AS ad
                        FROM dbo.kullanicilar
                        WHERE kullanici_email IS NOT NULL AND LEN(TRIM(kullanici_email)) > 0
                          AND kullanici_durum = 1
                        ORDER BY kullanici_ad
                    ");
                }
                echo json_encode(['success' => true, 'data' => $alicilar, 'toplam' => count($alicilar)]);
                break;

            // ── Kampanya Oluştur ───────────────────────────
            case 'kampanya_olustur':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $konu    = trim($_POST['konu'] ?? '');
                $icerik  = trim($_POST['icerik'] ?? '');
                $alicilarJson = $_POST['alicilar'] ?? '[]';

                if (!$kanalId || !$konu || !$icerik) {
                    echo json_encode(['success' => false, 'message' => 'Kanal, konu ve içerik zorunludur.']);
                    break;
                }

                $alicilar = json_decode($alicilarJson, true);
                if (empty($alicilar) || !is_array($alicilar)) {
                    echo json_encode(['success' => false, 'message' => 'En az bir alıcı eklenmelidir.']);
                    break;
                }

                // E-posta formatı doğrulama (kopyala-yapıştır kaynaklı görünmeyen karakterleri temizler)
                $normalizeEmail = static function ($email) {
                    $email = (string)$email;
                    $email = preg_replace('/[\x00-\x1F\x7F\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', '', $email);
                    $email = trim($email);
                    if (preg_match('/<\s*([^\s<>@]+@[^\s<>@]+\.[^\s<>@]+)\s*>/u', $email, $m)) {
                        $email = trim($m[1]);
                    }
                    return $email;
                };

                $temizAlicilar = [];
                $gecersizler = [];
                foreach ($alicilar as $a) {
                    $ad = trim((string)($a['ad'] ?? ''));
                    $email = $normalizeEmail($a['email'] ?? '');

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $gecersizler[] = $email !== '' ? $email : '(boş)';
                        continue;
                    }

                    $temizAlicilar[] = ['ad' => $ad, 'email' => $email];
                }

                if (empty($temizAlicilar)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli alıcı bulunamadı.']);
                    break;
                }

                // Ek dosyaları işle
                $eklerJson   = null;
                $uploadHata  = [];
                if (!empty($_FILES['ekler']['name'][0])) {
                    $eklerArr        = [];
                    $izinliUzantilar = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'zip'];

                    // Temiz, gerçek yol: /../ içermez, Windows ile uyumlu
                    $assetsDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'assets');
                    if (!$assetsDir) {
                        $uploadHata[] = 'assets klasörü bulunamadı.';
                    } else {
                        $uploadDir = $assetsDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'toplu-mail' . DIRECTORY_SEPARATOR;
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        $dosyaSayisi = count($_FILES['ekler']['name']);
                        for ($i = 0; $i < $dosyaSayisi; $i++) {
                            $hataKodu = $_FILES['ekler']['error'][$i];
                            if ($hataKodu === UPLOAD_ERR_NO_FILE) continue;
                            if ($hataKodu !== UPLOAD_ERR_OK) {
                                $uploadHata[] = 'Yükleme hatası (kod ' . $hataKodu . '): ' . basename($_FILES['ekler']['name'][$i]);
                                continue;
                            }
                            $orijinalAd = basename($_FILES['ekler']['name'][$i]);
                            $uzanti     = strtolower(pathinfo($orijinalAd, PATHINFO_EXTENSION));
                            if (!in_array($uzanti, $izinliUzantilar)) {
                                $uploadHata[] = 'İzin verilmeyen tür: ' . $orijinalAd;
                                continue;
                            }
                            if ($_FILES['ekler']['size'][$i] > 10 * 1024 * 1024) {
                                $uploadHata[] = 'Boyut aşıldı (maks 10 MB): ' . $orijinalAd;
                                continue;
                            }
                            $guvenliAd = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $uzanti;
                            $hedefYol  = $uploadDir . $guvenliAd;
                            if (move_uploaded_file($_FILES['ekler']['tmp_name'][$i], $hedefYol)) {
                                $eklerArr[] = ['path' => $hedefYol, 'name' => $orijinalAd];
                            } else {
                                $uploadHata[] = 'Taşıma başarısız: ' . $orijinalAd . ' → ' . $hedefYol;
                            }
                        }
                        if (!empty($eklerArr)) {
                            $eklerJson = json_encode($eklerArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    }
                }

                // Ana kampanya kaydı
                $gonderimId = $db->insert('TopluMailGonderimi', [
                    'Gonderim_Kanal_Id'    => $kanalId,
                    'Gonderim_Konu'        => $konu,
                    'Gonderim_Icerik'      => $icerik,
                    'Gonderim_Ekler'       => $eklerJson,
                    'Gonderim_Toplam'      => count($temizAlicilar),
                    'Gonderim_Gonderilen'  => 0,
                    'Gonderim_Hatali'      => 0,
                    'Gonderim_Durum'       => 'Bekliyor',
                    'OlusturanKullanici'   => $user['kullanici_id'],
                    'OlusturmaTarihi'      => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                    'Durum'                => 1,
                ]);

                // Detay kayıtları (batch insert)
                foreach ($temizAlicilar as $alici) {
                    $db->insert('TopluMailGonderimiDetay', [
                        'Detay_Gonderim_Id'    => $gonderimId,
                        'Detay_Alici_Ad'       => $alici['ad'] ?? '',
                        'Detay_Alici_Email'    => $alici['email'],
                        'Detay_Durum'          => 'Bekliyor',
                        'Detay_Deneme_Sayisi'  => 0,
                        'OlusturanKullanici'   => $user['kullanici_id'],
                        'OlusturmaTarihi'      => date('Y-m-d H:i:s'),
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                        'Durum'                => 1,
                    ]);
                }

                $mesaj = count($temizAlicilar) . ' alıcı için kampanya kuyruğa alındı.';
                if (!empty($gecersizler)) {
                    $mesaj .= ' Geçersiz alıcılar atlandı: ' . implode(', ', array_slice(array_unique($gecersizler), 0, 5)) . '.';
                }
                if (!empty($uploadHata)) {
                    $mesaj .= ' ⚠️ Dosya uyarıları: ' . implode(' | ', $uploadHata);
                }
                echo json_encode(['success' => true, 'message' => $mesaj, 'gonderim_id' => $gonderimId, 'upload_hatalar' => $uploadHata]);
                break;

            // ── Kampanya İlerlemesi ────────────────────────
            case 'ilerleme':
                $gId  = (int)($_POST['gonderim_id'] ?? 0);
                $info = $db->fetchOne("
                    SELECT Gonderim_Durum, Gonderim_Toplam, Gonderim_Gonderilen, Gonderim_Hatali
                    FROM dbo.TopluMailGonderimi WHERE Gonderim_Id = ?
                ", [$gId]);
                echo json_encode(['success' => true, 'data' => $info]);
                break;

            // ── Kampanya İptal ─────────────────────────────
            case 'iptal':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']);
                    break;
                }
                $gId = (int)($_POST['gonderim_id'] ?? 0);
                $db->execute("
                    UPDATE dbo.TopluMailGonderimi
                    SET Gonderim_Durum = 'İptal', GuncellemeTarihi = GETDATE(), GuncelleyenKullanici = ?
                    WHERE Gonderim_Id = ? AND Gonderim_Durum IN ('Bekliyor', 'Devam Ediyor')
                ", [$user['kullanici_id'], $gId]);
                // Bekleyen detayları da iptal et
                $db->execute("
                    UPDATE dbo.TopluMailGonderimiDetay
                    SET Detay_Durum = 'İptal', GuncellemeTarihi = GETDATE()
                    WHERE Detay_Gonderim_Id = ? AND Detay_Durum = 'Bekliyor'
                ", [$gId]);
                echo json_encode(['success' => true, 'message' => 'Kampanya iptal edildi.']);
                break;

            // ── Kampanya Detay ─────────────────────────────
            case 'detay':
                $gId  = (int)($_POST['gonderim_id'] ?? 0);
                $detayDurum = $_POST['detay_durum'] ?? '';
                $where = "WHERE d.Detay_Gonderim_Id = ? AND d.Durum = 1";
                $params = [$gId];
                if ($detayDurum !== '') {
                    $where .= " AND d.Detay_Durum = ?";
                    $params[] = $detayDurum;
                }
                $detaylar = $db->fetchAll("
                    SELECT d.Detay_Id, d.Detay_Alici_Ad, d.Detay_Alici_Email,
                           d.Detay_Durum, d.Detay_Hata_Mesaj, d.Detay_Deneme_Sayisi,
                           CONVERT(VARCHAR(16), d.Detay_Gonderim_Tarihi, 120) AS GonderimTarihi
                    FROM dbo.TopluMailGonderimiDetay d
                    $where
                    ORDER BY d.Detay_Id
                ", $params);
                echo json_encode(['success' => true, 'data' => $detaylar]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform .2s; }
        .info-box:hover { transform: translateY(-4px); box-shadow: 0 4px 8px rgba(0,0,0,.12); }
        .durum-badge { font-size: .78rem; }
        .progress { height: 20px; }
        .progress-bar { font-size: .75rem; line-height: 20px; }
        #aliciListesiContainer { max-height: 280px; overflow-y: auto; }
        .alici-satir { display: flex; align-items: center; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #f0f0f0; }
        .alici-satir:last-child { border-bottom: none; }
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sayfa İçeriği -->
        <div class="app-content">
            <div class="container-fluid">

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-send"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kampanya</span>
                                <span class="info-box-number" id="stat-toplam">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Devam Eden</span>
                                <span class="info-box-number" id="stat-devameden">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Tamamlanan</span>
                                <span class="info-box-number" id="stat-tamamlandi">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-clock"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bekleyen</span>
                                <span class="info-box-number" id="stat-bekliyor">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre + Yeni Kampanya -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="card card-outline card-primary">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel me-1"></i>Filtreler</h3>
                                <div class="card-tools">
                                    <?php if ($pagePermissions['can_add']): ?>
                                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalYeniKampanya">
                                        <i class="bi bi-plus-lg me-1"></i>Yeni Kampanya
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Arama (Konu)</label>
                                        <input type="text" id="filter_search" class="form-control" placeholder="Konu ara...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Durum</label>
                                        <select id="filter_durum" class="form-select select2-basic">
                                            <option value="">Tümü</option>
                                            <option>Bekliyor</option>
                                            <option>Devam Ediyor</option>
                                            <option>Tamamlandı</option>
                                            <option>Hata</option>
                                            <option>İptal</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button class="btn btn-primary w-100" id="btnFiltrele">
                                            <i class="bi bi-search me-1"></i>Listele
                                        </button>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button class="btn btn-secondary w-100" id="btnFiltreTemizle">
                                            <i class="bi bi-x-circle me-1"></i>Temizle
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kampanya Listesi -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-list-ul me-1"></i>Kampanyalar</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0" id="tblKampanyalar">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Konu</th>
                                        <th>Gönderen</th>
                                        <th>Durum</th>
                                        <th>İlerleme</th>
                                        <th>Oluşturma</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody id="kampanyaListesi">
                                    <tr><td colspan="7" class="text-center py-4 text-muted">Yükleniyor...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div><!-- /container-fluid -->
        </div><!-- /app-content -->

    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div><!-- /app-wrapper -->

<!-- ══════════════════════════════════════
     MODAL: Yeni Kampanya
══════════════════════════════════════ -->
<div class="modal fade" id="modalYeniKampanya" tabindex="-1" aria-labelledby="modalYeniKampanyaLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalYeniKampanyaLabel"><i class="bi bi-send me-2"></i>Yeni Toplu Mail Kampanyası</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formYeniKampanya" enctype="multipart/form-data">
                    <div class="row g-3">
                        <!-- Sol Kolon: Mail Detayları -->
                        <div class="col-md-7">
                            <div class="card h-100">
                                <div class="card-header"><h6 class="mb-0"><i class="bi bi-envelope me-1"></i>Mail Detayları</h6></div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Gönderen Hesap <span class="text-danger">*</span></label>
                                        <select class="form-select select2-basic" id="kanal_id" name="kanal_id" required>
                                            <option value="">Yükleniyor...</option>
                                        </select>
                                        <div class="form-text text-muted"><i class="bi bi-info-circle me-1"></i>Entegrasyon sayfasında tanımlı aktif Gmail hesapları listelenir.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Konu <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="mail_konu" name="konu" placeholder="Mail konusunu girin..." required maxlength="500">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">İçerik <span class="text-danger">*</span></label>
                                        <textarea id="mail_icerik" name="icerik" class="form-control" rows="10" placeholder="Mail içeriğini girin... (HTML desteklenir)"></textarea>
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label fw-semibold">Dosya Ekleri</label>
                                        <input type="file" class="form-control" id="mail_ekler" name="ekler[]" multiple
                                               accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.zip">
                                        <div class="form-text text-muted">İzin verilen: PDF, Word, Excel, Görsel, ZIP. Maks 10 MB/dosya.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sağ Kolon: Alıcılar -->
                        <div class="col-md-5">
                            <div class="card h-100">
                                <div class="card-header"><h6 class="mb-0"><i class="bi bi-people me-1"></i>Alıcılar</h6></div>
                                <div class="card-body d-flex flex-column">
                                    <!-- Alıcı Kaynağı -->
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Alıcı Kaynağı</label>
                                        <div class="btn-group w-100" role="group">
                                            <input type="radio" class="btn-check" name="alici_kaynak" id="kaynak_manuel" value="manuel" checked>
                                            <label class="btn btn-outline-secondary btn-sm" for="kaynak_manuel"><i class="bi bi-pencil me-1"></i>Manuel</label>
                                            <input type="radio" class="btn-check" name="alici_kaynak" id="kaynak_cariler" value="cariler">
                                            <label class="btn btn-outline-secondary btn-sm" for="kaynak_cariler"><i class="bi bi-building me-1"></i>Cariler</label>
                                            <input type="radio" class="btn-check" name="alici_kaynak" id="kaynak_kullanicilar" value="kullanicilar">
                                            <label class="btn btn-outline-secondary btn-sm" for="kaynak_kullanicilar"><i class="bi bi-person me-1"></i>Kullanıcılar</label>
                                        </div>
                                    </div>

                                    <!-- Manuel Giriş -->
                                    <div id="panelManuel" class="mb-3">
                                        <label class="form-label">Manuel Alıcı Ekle</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="manuel_ad" placeholder="Ad Soyad (opsiyonel)">
                                            <input type="email" class="form-control" id="manuel_email" placeholder="mail@ornek.com">
                                            <button type="button" class="btn btn-outline-primary" id="btnManuelEkle">
                                                <i class="bi bi-plus-lg me-1"></i>Tek Ekle
                                            </button>
                                        </div>

                                        <div class="mt-2">
                                            <textarea class="form-control" id="manuel_toplu" rows="4" placeholder="Toplu alıcı girişi:&#10;a@x.com, b@x.com&#10;veya her satıra bir e-posta"></textarea>
                                            <div class="d-grid mt-2">
                                                <button type="button" class="btn btn-outline-success" id="btnManuelTopluEkle">
                                                    <i class="bi bi-list-check me-1"></i>Toplu Ekle
                                                </button>
                                            </div>
                                        </div>
                                        <div class="form-text">Virgül, noktalı virgül veya satır sonuna göre toplu ekleme yapabilirsiniz.</div>
                                    </div>

                                    <!-- DB Kaynak Butonu -->
                                    <div id="panelDbKaynak" class="mb-3 d-none">
                                        <button type="button" class="btn btn-outline-info w-100" id="btnDbYukle">
                                            <i class="bi bi-cloud-download me-1"></i>Listeden Yükle
                                        </button>
                                    </div>

                                    <!-- Alıcı Listesi -->
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="fw-semibold">Alıcı Listesi (<span id="aliciSayisi">0</span>)</span>
                                        <button type="button" class="btn btn-outline-danger btn-sm" id="btnAlicilariTemizle">
                                            <i class="bi bi-trash me-1"></i>Temizle
                                        </button>
                                    </div>
                                    <div id="aliciListesiContainer" class="flex-grow-1 border rounded p-2 bg-light">
                                        <div class="text-muted text-center py-3" id="aliciBosMesaj">Henüz alıcı eklenmedi.</div>
                                    </div>
                                    <!-- Gizli alıcı JSON -->
                                    <input type="hidden" id="alicilarJson" name="alicilar">

                                                                        <!-- Spam Uyarısı -->
                                    <div class="alert alert-info mt-3 mb-0 p-2" id="spamUyari" style="display:none;">
                                        <i class="bi bi-info-circle me-1"></i>
                                        <small>Cron her dakika <strong>3 mail</strong> gönderir (Gmail limit uyumlu). <span id="tahminSure"></span></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i>İptal</button>
                <button type="button" class="btn btn-primary" id="btnKampanyaGonder">
                    <i class="bi bi-send me-1"></i>Kuyruğa Al
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     MODAL: Kampanya Detay
══════════════════════════════════════ -->
<div class="modal fade" id="modalDetay" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-list-check me-2"></i>Kampanya Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- İlerleme -->
                <div class="row mb-3">
                    <div class="col-md-4 text-center">
                        <div class="fw-semibold text-muted mb-1">Toplam</div>
                        <div class="fs-4 fw-bold" id="detay_toplam">-</div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="fw-semibold text-success mb-1">Gönderildi</div>
                        <div class="fs-4 fw-bold text-success" id="detay_gonderilen">-</div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="fw-semibold text-danger mb-1">Hatalı</div>
                        <div class="fs-4 fw-bold text-danger" id="detay_hatali">-</div>
                    </div>
                </div>
                <div class="progress mb-4">
                    <div class="progress-bar bg-success" id="detay_progress" role="progressbar" style="width:0%">0%</div>
                </div>
                <!-- Filtre -->
                <div class="mb-3 d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary detay-filtre-btn active" data-durum="">Tümü</button>
                    <button class="btn btn-sm btn-outline-success detay-filtre-btn" data-durum="Gönderildi">Gönderildi</button>
                    <button class="btn btn-sm btn-outline-warning detay-filtre-btn" data-durum="Bekliyor">Bekliyor</button>
                    <button class="btn btn-sm btn-outline-danger detay-filtre-btn" data-durum="Hata">Hata</button>
                </div>
                <!-- Tablo -->
                <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                    <table class="table table-sm table-hover">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Ad Soyad</th>
                                <th>E-posta</th>
                                <th>Durum</th>
                                <th>Gönderim Tarihi</th>
                                <th>Deneme</th>
                                <th>Hata</th>
                            </tr>
                        </thead>
                        <tbody id="detayListesi"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
$(function () {
    // ─────────────────────────────────────────────
    // Select2 başlat
    // ─────────────────────────────────────────────
    $('#filter_durum, #kanal_id').select2({ theme: 'bootstrap-5', width: '100%' });

    // ─────────────────────────────────────────────
    // İstatistikler
    // ─────────────────────────────────────────────
    function statsYukle() {
        $.post('', { action: 'stats' }, function (r) {
            if (r.success) {
                $('#stat-toplam').text(r.data.toplam);
                $('#stat-devameden').text(r.data.devamEden);
                $('#stat-tamamlandi').text(r.data.tamamlandi);
                $('#stat-bekliyor').text(r.data.bekliyor);
            }
        });
    }

    // ─────────────────────────────────────────────
    // Kampanya Listesi
    // ─────────────────────────────────────────────
    function kampanyaListele() {
        var data = {
            action: 'liste',
            search: $('#filter_search').val(),
            durum: $('#filter_durum').val()
        };
        $.post('', data, function (r) {
            var $tbody = $('#kampanyaListesi');
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="7" class="text-center py-4 text-muted">Kayıt bulunamadı.</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function (i, k) {
                html += '<tr>';
                html += '<td>' + k.Gonderim_Id + '</td>';
                html += '<td class="fw-semibold">' + escHtml(k.Gonderim_Konu) + '</td>';
                html += '<td><small>' + escHtml(k.Kanal_Adi || '') + '<br><span class="text-muted">' + escHtml(k.Kanal_Email || '') + '</span></small></td>';
                html += '<td>' + durumBadge(k.Gonderim_Durum) + '</td>';
                html += '<td style="min-width:180px">' + ilerlemeBar(k) + '</td>';
                html += '<td><small>' + (k.OlusturmaTarihi || '-') + '<br><span class="text-muted">' + escHtml(k.OlusturanAdi || '') + '</span></small></td>';
                html += '<td class="text-nowrap">';
                html += '<button class="btn btn-sm btn-outline-info me-1 btn-detay" data-id="' + k.Gonderim_Id + '" data-toplam="' + k.Gonderim_Toplam + '" data-gonderilen="' + k.Gonderim_Gonderilen + '" data-hatali="' + k.Gonderim_Hatali + '" title="Detay"><i class="bi bi-eye"></i></button>';
                if (k.Gonderim_Durum === 'Bekliyor' || k.Gonderim_Durum === 'Devam Ediyor') {
                    html += '<button class="btn btn-sm btn-outline-danger btn-iptal" data-id="' + k.Gonderim_Id + '" title="İptal Et"><i class="bi bi-x-circle"></i></button>';
                }
                html += '</td></tr>';
            });
            $tbody.html(html);
        });
    }

    function durumBadge(durum) {
        var map = {
            'Bekliyor': 'secondary', 'Devam Ediyor': 'warning',
            'Tamamlandı': 'success', 'Hata': 'danger', 'İptal': 'dark'
        };
        var renk = map[durum] || 'secondary';
        return '<span class="badge text-bg-' + renk + ' durum-badge">' + escHtml(durum) + '</span>';
    }

    function ilerlemeBar(k) {
        var toplam = parseInt(k.Gonderim_Toplam) || 0;
        if (!toplam) return '<span class="text-muted">-</span>';
        var yuzde = Math.round((parseInt(k.Gonderim_Gonderilen) / toplam) * 100);
        return '<div class="progress" title="' + k.Gonderim_Gonderilen + '/' + toplam + '">'
            + '<div class="progress-bar bg-success" style="width:' + yuzde + '%">'
            + yuzde + '%</div></div>'
            + '<small class="text-muted">' + k.Gonderim_Gonderilen + '✓ ' + k.Gonderim_Hatali + '✗ / ' + toplam + '</small>';
    }

    function escHtml(s) {
        return $('<div>').text(s).html();
    }

    // Filtrele
    $('#btnFiltrele').on('click', kampanyaListele);
    $('#btnFiltreTemizle').on('click', function () {
        $('#filter_search').val('');
        $('#filter_durum').val('').trigger('change');
        kampanyaListele();
    });

    // ─────────────────────────────────────────────
    // Modal: Yeni Kampanya — Kanal Yükle
    // ─────────────────────────────────────────────
    $('#modalYeniKampanya').on('show.bs.modal', function () {
        $.post('', { action: 'kanallari_getir' }, function (r) {
            var $sel = $('#kanal_id');
            $sel.empty().append('<option value="">-- Hesap Seçin --</option>');
            if (r.success && r.data.length) {
                $.each(r.data, function (i, k) {
                    $sel.append('<option value="' + k.Kanal_Id + '">' + escHtml(k.Kanal_Adi) + ' (' + escHtml(k.Kanal_Kullanici) + ')</option>');
                });
            } else {
                $sel.append('<option value="" disabled>Aktif mail kanalı bulunamadı!</option>');
            }
            $sel.trigger('change');
        });
    });

    // ─────────────────────────────────────────────
    // Alıcı Yönetimi
    // ─────────────────────────────────────────────
    var alicilar = []; // {ad:'', email:''}

    function aliciListeGuncelle() {
        var $cont = $('#aliciListesiContainer');
        if (!alicilar.length) {
            $cont.html('<div class="text-muted text-center py-3" id="aliciBosMesaj">Henüz alıcı eklenmedi.</div>');
            $('#aliciSayisi').text(0);
            $('#spamUyari').hide();
            $('#alicilarJson').val('[]');
            return;
        }
        var html = '';
        $.each(alicilar, function (i, a) {
            html += '<div class="alici-satir">'
                + '<span><small class="text-muted">' + escHtml(a.ad || '') + '</small> ' + escHtml(a.email) + '</span>'
                + '<button type="button" class="btn btn-sm btn-link text-danger p-0 btn-alici-sil" data-idx="' + i + '"><i class="bi bi-x-lg"></i></button>'
                + '</div>';
        });
        $cont.html(html);
        $('#aliciSayisi').text(alicilar.length);
        $('#alicilarJson').val(JSON.stringify(alicilar));

                // Tahmini süre (cron: dakikada 3 mail)
        var dakika = Math.ceil(alicilar.length / 3);
        var sureMetni;
        if (dakika < 60) {
            sureMetni = '~' + dakika + ' dakika';
        } else {
            var saat = Math.floor(dakika / 60);
            var kalanDk = dakika % 60;
            sureMetni = '~' + saat + ' saat' + (kalanDk ? ' ' + kalanDk + ' dk' : '');
        }
        $('#tahminSure').text('Tahmini süre: ' + sureMetni + '.');
        $('#spamUyari').show();
    }

    // Alıcı sil
    $(document).on('click', '.btn-alici-sil', function () {
        var idx = parseInt($(this).data('idx'));
        alicilar.splice(idx, 1);
        aliciListeGuncelle();
    });

    // Temizle
    $('#btnAlicilariTemizle').on('click', function () {
        alicilar = [];
        aliciListeGuncelle();
    });

    // Manuel ekleme
    function emailTemizle(email) {
        var temiz = String(email || '')
            .replace(/[\u0000-\u001F\u007F\u00A0\u200B-\u200D\uFEFF]/g, '')
            .trim();

        var aciliParantez = temiz.match(/<\s*([^\s<>@]+@[^\s<>@]+\.[^\s<>@]+)\s*>/i);
        if (aciliParantez) {
            return aciliParantez[1].trim();
        }

        return temiz;
    }

    function emailGecerliMi(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailTemizle(email));
    }

    function aliciAyrisitir(girdi, varsayilanAd) {
        var metin = $.trim(girdi || '');
        if (!metin) return null;

        // Desteklenen format: Ad Soyad <mail@ornek.com>
        var aciliParantez = metin.match(/^(.*?)<\s*([^\s<>@,;]+@[^\s<>@,;]+\.[^\s<>@,;]+)\s*>$/);
        if (aciliParantez) {
            return {
                ad: $.trim(aciliParantez[1] || varsayilanAd || ''),
                email: $.trim(aciliParantez[2])
            };
        }

        var bulunanEmail = metin.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
        if (bulunanEmail) {
            return {
                ad: $.trim(varsayilanAd || ''),
                email: emailTemizle(bulunanEmail[0])
            };
        }

        return { invalid: metin };
    }

    function aliciEkleToplu(girdiMetni, varsayilanAd) {
        var ham = $.trim(girdiMetni || '');
        if (!ham) {
            return { eklendi: 0, tekrar: 0, gecersiz: [] };
        }

        var parcali = ham.split(/[\n,;]+/).map(function (x) { return $.trim(x); }).filter(Boolean);
        var sonuc = { eklendi: 0, tekrar: 0, gecersiz: [] };

        $.each(parcali, function (i, parca) {
            var alici = aliciAyrisitir(parca, varsayilanAd);
            if (!alici || !alici.email || !emailGecerliMi(alici.email)) {
                sonuc.gecersiz.push(parca);
                return;
            }

            alici.email = emailTemizle(alici.email);

            var mevcut = alicilar.some(function (a) {
                return (a.email || '').toLowerCase() === alici.email.toLowerCase();
            });

            if (mevcut) {
                sonuc.tekrar++;
                return;
            }

            alicilar.push({ ad: $.trim(alici.ad || ''), email: alici.email });
            sonuc.eklendi++;
        });

        return sonuc;
    }

    $('#btnManuelEkle').on('click', function () {
        var emailInput = $.trim($('#manuel_email').val());
        var ad = $.trim($('#manuel_ad').val());
        var sonuc = aliciEkleToplu(emailInput, ad);

        if (sonuc.gecersiz.length) {
            Swal.fire('Hata', 'Geçersiz e-posta: ' + sonuc.gecersiz.slice(0, 3).join(', '), 'error');
            return;
        }

        if (sonuc.eklendi > 0) {
            $('#manuel_email').val('');
            aliciListeGuncelle();
        }
    });

    $('#btnManuelTopluEkle').on('click', function () {
        var topluMetin = $('#manuel_toplu').val();
        var ad = $.trim($('#manuel_ad').val());
        var sonuc = aliciEkleToplu(topluMetin, ad);

        if (!sonuc.eklendi && !sonuc.tekrar && !sonuc.gecersiz.length) {
            Swal.fire('Uyarı', 'Toplu e-posta listesi boş.', 'warning');
            return;
        }

        if (sonuc.eklendi > 0) {
            $('#manuel_toplu, #manuel_email').val('');
            aliciListeGuncelle();
        }

        var mesaj = sonuc.eklendi + ' alıcı eklendi';
        if (sonuc.tekrar) mesaj += ', ' + sonuc.tekrar + ' tekrar atlandı';
        if (sonuc.gecersiz.length) mesaj += ', ' + sonuc.gecersiz.length + ' geçersiz adres atlandı';

        Swal.fire('İşlem Tamamlandı', mesaj + '.', sonuc.eklendi ? 'success' : 'info');
    });

    $('#manuel_email').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); $('#btnManuelEkle').click(); } });
    $('#manuel_toplu').on('keydown', function(e){
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            $('#btnManuelTopluEkle').click();
        }
    });

    // Kaynak seçimi
    $('input[name="alici_kaynak"]').on('change', function () {
        var val = $(this).val();
        $('#panelManuel').toggleClass('d-none', val !== 'manuel');
        $('#panelDbKaynak').toggleClass('d-none', val === 'manuel');
    });

    // DB'den yükle
    $('#btnDbYukle').on('click', function () {
        var kaynak = $('input[name="alici_kaynak"]:checked').val();
        var $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Yükleniyor...');
        $.post('', { action: 'alicilari_getir', kaynak: kaynak }, function (r) {
            $btn.prop('disabled', false).html('<i class="bi bi-cloud-download me-1"></i>Listeden Yükle');
            if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
            if (!r.data.length) { Swal.fire('Bilgi', 'Kayıtlı e-posta adresi bulunamadı.', 'info'); return; }

            var yeni = 0, tekrar = 0;
            $.each(r.data, function(i, a){
                var temizEmail = emailTemizle(a.email || '');
                if (!emailGecerliMi(temizEmail)) return;
                var mevcut = alicilar.some(function(x){ return x.email.toLowerCase() === temizEmail.toLowerCase(); });
                if (!mevcut) { alicilar.push({ ad: a.ad || '', email: temizEmail }); yeni++; }
                else { tekrar++; }
            });
            aliciListeGuncelle();
            Swal.fire('Yüklendi', yeni + ' alıcı eklendi' + (tekrar ? ', ' + tekrar + ' tekrar atlandı.' : '.'), 'success');
        }).fail(function(){
            $btn.prop('disabled', false).html('<i class="bi bi-cloud-download me-1"></i>Listeden Yükle');
        });
    });

    // ─────────────────────────────────────────────
    // Kampanya Gönder (Kuyruğa Al)
    // ─────────────────────────────────────────────
    $('#btnKampanyaGonder').on('click', function () {
        var kanalId  = $('#kanal_id').val();
        var konu     = $.trim($('#mail_konu').val());
        var icerik   = $.trim($('#mail_icerik').val());

        if (!kanalId) { Swal.fire('Uyarı', 'Lütfen gönderen hesabı seçin.', 'warning'); return; }
        if (!konu)    { Swal.fire('Uyarı', 'Konu alanı zorunludur.', 'warning'); return; }
        if (!icerik)  { Swal.fire('Uyarı', 'İçerik alanı zorunludur.', 'warning'); return; }
        if (!alicilar.length) { Swal.fire('Uyarı', 'En az bir alıcı eklemelisiniz.', 'warning'); return; }

        Swal.fire({
            title: 'Kampanyayı Kuyruğa Al',
            html: '<strong>' + alicilar.length + ' alıcıya</strong> mail gönderilecek.<br>Cron her dakika çalışarak gönderime devam eder.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet, Kuyruğa Al',
            cancelButtonText: 'İptal'
        }).then(function(result){
            if (!result.isConfirmed) return;

            var $btn = $('#btnKampanyaGonder').prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Kaydediliyor...');
            var formData = new FormData(document.getElementById('formYeniKampanya'));
            formData.set('action', 'kampanya_olustur');
            formData.set('alicilar', JSON.stringify(alicilar));

            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(r){
                    $btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i>Kuyruğa Al');
                    if (r.success) {
                        $('#modalYeniKampanya').modal('hide');
                        alicilar = [];
                        aliciListeGuncelle();
                        document.getElementById('formYeniKampanya').reset();
                        Swal.fire('Başarılı!', r.message, 'success');
                        statsYukle();
                        kampanyaListele();
                    } else {
                        Swal.fire('Hata', r.message, 'error');
                    }
                },
                error: function(){
                    $btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i>Kuyruğa Al');
                    Swal.fire('Hata', 'Sunucu hatası oluştu.', 'error');
                }
            });
        });
    });

    // ─────────────────────────────────────────────
    // Detay Modal
    // ─────────────────────────────────────────────
    var aktifDetayId = null;

    $(document).on('click', '.btn-detay', function(){
        aktifDetayId = $(this).data('id');
        var toplam    = $(this).data('toplam');
        var gonderilen = $(this).data('gonderilen');
        var hatali    = $(this).data('hatali');

        $('#detay_toplam').text(toplam);
        $('#detay_gonderilen').text(gonderilen);
        $('#detay_hatali').text(hatali);

        var yuzde = toplam > 0 ? Math.round((gonderilen / toplam) * 100) : 0;
        $('#detay_progress').css('width', yuzde + '%').text(yuzde + '%');

        $('.detay-filtre-btn').removeClass('active');
        $('.detay-filtre-btn[data-durum=""]').addClass('active');

        detayYukle('');
        $('#modalDetay').modal('show');
    });

    $('.detay-filtre-btn').on('click', function(){
        $('.detay-filtre-btn').removeClass('active');
        $(this).addClass('active');
        detayYukle($(this).data('durum'));
    });

    function detayYukle(durum) {
        $.post('', { action: 'detay', gonderim_id: aktifDetayId, detay_durum: durum }, function(r){
            var $tbody = $('#detayListesi');
            if (!r.success || !r.data.length) {
                $tbody.html('<tr><td colspan="6" class="text-center text-muted py-3">Kayıt bulunamadı.</td></tr>');
                return;
            }
            var html = '';
            $.each(r.data, function(i, d){
                var durumRenk = d.Detay_Durum === 'Gönderildi' ? 'success' : (d.Detay_Durum === 'Hata' ? 'danger' : (d.Detay_Durum === 'Bekliyor' ? 'secondary' : 'dark'));
                html += '<tr>'
                    + '<td>' + escHtml(d.Detay_Alici_Ad || '-') + '</td>'
                    + '<td>' + escHtml(d.Detay_Alici_Email) + '</td>'
                    + '<td><span class="badge text-bg-' + durumRenk + '">' + escHtml(d.Detay_Durum) + '</span></td>'
                    + '<td>' + (d.GonderimTarihi || '-') + '</td>'
                    + '<td class="text-center">' + d.Detay_Deneme_Sayisi + '</td>'
                    + '<td><small class="text-danger">' + escHtml(d.Detay_Hata_Mesaj || '') + '</small></td>'
                    + '</tr>';
            });
            $tbody.html(html);
        });
    }

    // ─────────────────────────────────────────────
    // İptal
    // ─────────────────────────────────────────────
    $(document).on('click', '.btn-iptal', function(){
        var id = $(this).data('id');
        Swal.fire({
            title: 'Kampanyayı İptal Et',
            text: 'Bekleyen mailler gönderilmeyecek. Onaylıyor musunuz?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Evet, İptal Et',
            cancelButtonText: 'Hayır'
        }).then(function(r){
            if (!r.isConfirmed) return;
            $.post('', { action: 'iptal', gonderim_id: id }, function(res){
                if (res.success) {
                    Swal.fire('İptal Edildi', res.message, 'success');
                    kampanyaListele(); statsYukle();
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            });
        });
    });

    // ─────────────────────────────────────────────
    // Modal kapanınca formu sıfırla
    // ─────────────────────────────────────────────
    $('#modalYeniKampanya').on('hidden.bs.modal', function(){
        alicilar = [];
        aliciListeGuncelle();
        document.getElementById('formYeniKampanya').reset();
        $('#kanal_id').val('').trigger('change');
        $('input[name="alici_kaynak"][value="manuel"]').prop('checked', true).trigger('change');
    });

    // ─────────────────────────────────────────────
    // Otomatik yenileme (30 sn) — devam eden kampanya varsa
    // ─────────────────────────────────────────────
    setInterval(function(){
        statsYukle();
        kampanyaListele();
    }, 30000);

    // ─────────────────────────────────────────────
    // İlk Yükleme
    // ─────────────────────────────────────────────
    statsYukle();
    kampanyaListele();
});
</script>
</body>
</html>
