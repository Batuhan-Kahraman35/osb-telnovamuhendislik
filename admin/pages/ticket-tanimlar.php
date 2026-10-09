<?php
/**
 * Admin Panel - Ticket Tanımları (Kategori / Öncelik / Durum)
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/TicketHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageTitle = 'Ticket Tanımları';
$menuAdi   = 'Destek Sistemi';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─── AJAX ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $tip    = $_POST['tip'] ?? ''; // kategori | oncelik | durum

    $tablo = match ($tip) {
        'kategori' => 'Ticket_Kategoriler',
        'oncelik'  => 'Ticket_Oncelikler',
        'durum'    => 'Ticket_Durumlar',
        default    => null
    };

    $prefix = match ($tip) {
        'kategori' => 'Ticket_Kategoriler',
        'oncelik'  => 'Ticket_Oncelikler',
        'durum'    => 'Ticket_Durumlar',
        default    => null
    };

    if (!$tablo) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz tip.']);
        exit;
    }

    try {
        switch ($action) {
            case 'list':
                $data = $db->fetchAll("SELECT {$prefix}_id AS id, {$prefix}_ad AS ad, {$prefix}_renk AS renk, {$prefix}_sira AS sira FROM dbo.{$tablo} WHERE Durum = 1 ORDER BY {$prefix}_sira, {$prefix}_id");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'api_guncelle':
                $helper = new TicketHelper($db);
                $sync = $helper->senkronizeMetaTanimlari((int)$user['kullanici_id']);
                echo json_encode([
                    'success' => true,
                    'message' => 'API tanimlari guncellendi.',
                    'counts'  => $sync['counts'] ?? [],
                ]);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
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
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Sekmeler -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <ul class="nav nav-tabs" id="tanimTab">
                    <li class="nav-item"><button class="nav-link active" data-tip="kategori"><i class="bi bi-tags me-1"></i>Kategoriler</button></li>
                    <li class="nav-item"><button class="nav-link" data-tip="oncelik"><i class="bi bi-flag me-1"></i>Öncelikler</button></li>
                    <li class="nav-item"><button class="nav-link" data-tip="durum"><i class="bi bi-circle-half me-1"></i>Durumlar</button></li>
                    </ul>
                    <button class="btn btn-primary" id="btnApiGuncelle"><i class="bi bi-arrow-repeat me-1"></i>API ile Guncelle</button>
                </div>

                <div class="row">
                    <!-- Tablo -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <span id="tabloBaslik"><i class="bi bi-tags me-1"></i>Kategoriler</span>
                            </div>
                            <div class="card-body p-0">
                                <table id="tblTanim" class="table table-hover align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr><th>#</th><th>Ad</th><th>Renk</th><th>Sıra</th></tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script>
$(function () {
    var aktivTip = 'kategori';

    var basliklar = { kategori: 'Kategoriler', oncelik: 'Öncelikler', durum: 'Durumlar' };

    // Sekme değiştir
    $('#tanimTab button').on('click', function () {
        $('#tanimTab button').removeClass('active');
        $(this).addClass('active');
        aktivTip = $(this).data('tip');
        $('#tabloBaslik').html('<i class="bi bi-tags me-1"></i>' + basliklar[aktivTip]);
        loadList();
    });

    // Listeyi yükle
    function loadList() {
        $.post('', { action: 'list', tip: aktivTip }, function (r) {
            var tbody = $('#tblTanim tbody').empty();
            if (r.success && r.data.length > 0) {
                r.data.forEach(function (row) {
                    tbody.append(`
                        <tr>
                            <td>${row.id}</td>
                            <td><strong>${escHtml(row.ad)}</strong></td>
                            <td><span class="badge text-bg-${escHtml(row.renk)}">${escHtml(row.renk)}</span></td>
                            <td>${row.sira}</td>
                        </tr>
                    `);
                });
            } else {
                tbody.append('<tr><td colspan="4" class="text-center text-muted">Kayıt yok.</td></tr>');
            }
        });
    }
    loadList();

    $('#btnApiGuncelle').on('click', function () {
        var btn = $(this);
        btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Guncelleniyor...');

        $.post('', { action: 'api_guncelle', tip: aktivTip }, function (r) {
            if (r.success) {
                loadList();
                var c = r.counts || {};
                var bilgi = 'Kategori: ' + (c.kategoriler || 0) + ', Oncelik: ' + (c.oncelikler || 0) + ', Durum: ' + (c.durumlar || 0);
                Swal.fire({ icon: 'success', title: r.message, text: bilgi });
            } else {
                Swal.fire('Hata!', r.message || 'Guncelleme basarisiz.', 'error');
            }
        }).fail(function () {
            Swal.fire('Hata!', 'Sunucu hatasi.', 'error');
        }).always(function () {
            btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i>API ile Guncelle');
        });
    });

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
});
</script>
</body>
</html>
