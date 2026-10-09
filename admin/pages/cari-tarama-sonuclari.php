<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user    = Auth::user();
$db      = Database::getInstance();

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
    PageAuth::accessDenied('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
$pageTitle    = 'Cari Tarama Sonuçları';

// ------------------------------------------------------------------
// AJAX
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        // ----------------------------------------------------------------
        // Kayıt listesi
        // ----------------------------------------------------------------
        if ($action === 'kayitlar') {
            $isId   = !empty($_POST['is_id'])   ? (int)$_POST['is_id']   : null;
            $search = trim($_POST['search'] ?? '');

            $where  = ['k.Durum = 1'];
            $params = [];

            if ($isId) {
                $where[]  = 'k.CariTaramaKayitlari_IsId = ?';
                $params[] = $isId;
            }
            if ($search !== '') {
                $where[]  = '(k.CariTaramaKayitlari_FirmaAdi LIKE ? OR k.CariTaramaKayitlari_VergiNo LIKE ? OR k.CariTaramaKayitlari_Email LIKE ?)';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }

            $sql = "
                SELECT
                    k.CariTaramaKayitlari_Id,
                    k.CariTaramaKayitlari_IsId,
                    k.CariTaramaKayitlari_FirmaAdi,
                    k.CariTaramaKayitlari_Unvan,
                    k.CariTaramaKayitlari_VergiNo,
                    k.CariTaramaKayitlari_Telefon,
                    k.CariTaramaKayitlari_Email,
                    k.CariTaramaKayitlari_SektorMetni,
                    k.CariTaramaKayitlari_EfaturaMukellef,
                    k.CariTaramaKayitlari_Aktarildi,
                    k.CariTaramaKayitlari_AktarildiCariId,
                    k.CariTaramaKayitlari_KaynakUrl,
                    CAST(k.CariTaramaKayitlari_Adres AS NVARCHAR(400)) AS Adres,
                    i.CariTaramaIsleri_Domain,
                    (SELECT COUNT(*) FROM dbo.CariTaramaYetkilileri y
                     WHERE y.CariTaramaYetkilileri_KayitId = k.CariTaramaKayitlari_Id AND y.Durum = 1) AS YetkiliSayisi
                FROM dbo.CariTaramaKayitlari k
                LEFT JOIN dbo.CariTaramaIsleri i ON i.CariTaramaIsleri_Id = k.CariTaramaKayitlari_IsId
                WHERE " . implode(' AND ', $where) . "
                ORDER BY k.CariTaramaKayitlari_Id DESC
            ";

            $rows = $db->fetchAll($sql, $params);
            echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ----------------------------------------------------------------
        // Yetkili listesi (bir kayda ait)
        // ----------------------------------------------------------------
        if ($action === 'yetkililer') {
            $kayitId = (int)($_POST['kayit_id'] ?? 0);
            if ($kayitId <= 0) throw new Exception('Geçersiz kayıt ID.');

            $rows = $db->fetchAll("
                SELECT
                    CariTaramaYetkilileri_Id,
                    CariTaramaYetkilileri_Adi,
                    CariTaramaYetkilileri_Soyadi,
                    CariTaramaYetkilileri_Gorev,
                    CariTaramaYetkilileri_Telefon,
                    CariTaramaYetkilileri_Email,
                    CariTaramaYetkilileri_GuvenSkoru,
                    CariTaramaYetkilileri_KaynakUrl,
                    CariTaramaYetkilileri_Aktarildi
                FROM dbo.CariTaramaYetkilileri
                WHERE CariTaramaYetkilileri_KayitId = ? AND Durum = 1
                ORDER BY CariTaramaYetkilileri_GuvenSkoru DESC, CariTaramaYetkilileri_Id ASC
            ", [$kayitId]);

            echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ----------------------------------------------------------------
        // İş listesi (filtre için)
        // ----------------------------------------------------------------
        if ($action === 'isler') {
            $rows = $db->fetchAll("
                SELECT CariTaramaIsleri_Id, CariTaramaIsleri_Domain, CariTaramaIsleri_Durum
                FROM dbo.CariTaramaIsleri
                WHERE Durum = 1
                ORDER BY CariTaramaIsleri_Id DESC
            ");
            echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ----------------------------------------------------------------
        // Kayıt güncelle
        // ----------------------------------------------------------------
        if ($action === 'guncelle_kayit') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            $kayitId = (int)($_POST['kayit_id'] ?? 0);
            if ($kayitId <= 0) throw new Exception('Geçersiz kayıt ID.');

            $db->execute("
                UPDATE dbo.CariTaramaKayitlari SET
                    CariTaramaKayitlari_FirmaAdi    = ?,
                    CariTaramaKayitlari_Unvan        = ?,
                    CariTaramaKayitlari_VergiNo      = ?,
                    CariTaramaKayitlari_VergiDairesi = ?,
                    CariTaramaKayitlari_Telefon      = ?,
                    CariTaramaKayitlari_Email        = ?,
                    GuncelleyenKullanici              = ?,
                    GuncellemeTarihi                  = ?
                WHERE CariTaramaKayitlari_Id = ? AND Durum = 1
            ", [
                trim($_POST['firma_adi']     ?? '') ?: null,
                trim($_POST['unvan']         ?? '') ?: null,
                trim($_POST['vergi_no']      ?? '') ?: null,
                trim($_POST['vergi_dairesi'] ?? '') ?: null,
                trim($_POST['telefon']       ?? '') ?: null,
                trim($_POST['email']         ?? '') ?: null,
                $user['kullanici_id'],
                date('Y-m-d H:i:s'),
                $kayitId,
            ]);

            echo json_encode(['success' => true, 'message' => 'Kayıt güncellendi.']);
            exit;
        }

        // ----------------------------------------------------------------
        // Yetkili güncelle
        // ----------------------------------------------------------------
        if ($action === 'guncelle_yetkili') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            $yetkiliId = (int)($_POST['yetkili_id'] ?? 0);
            if ($yetkiliId <= 0) throw new Exception('Geçersiz yetkili ID.');

            $db->execute("
                UPDATE dbo.CariTaramaYetkilileri SET
                    CariTaramaYetkilileri_Adi     = ?,
                    CariTaramaYetkilileri_Soyadi  = ?,
                    CariTaramaYetkilileri_Gorev   = ?,
                    CariTaramaYetkilileri_Telefon = ?,
                    CariTaramaYetkilileri_Email   = ?,
                    GuncelleyenKullanici           = ?,
                    GuncellemeTarihi               = ?
                WHERE CariTaramaYetkilileri_Id = ? AND Durum = 1
            ", [
                trim($_POST['adi']     ?? '') ?: null,
                trim($_POST['soyadi']  ?? '') ?: null,
                trim($_POST['gorev']   ?? '') ?: null,
                trim($_POST['telefon'] ?? '') ?: null,
                trim($_POST['email']   ?? '') ?: null,
                $user['kullanici_id'],
                date('Y-m-d H:i:s'),
                $yetkiliId,
            ]);

            echo json_encode(['success' => true, 'message' => 'Yetkili güncellendi.']);
            exit;
        }

        // ----------------------------------------------------------------
        // Cari'ye aktar
        // ----------------------------------------------------------------
        if ($action === 'aktar') {
            if (!$pagePermissions['can_add']) throw new Exception('Aktarım yetkiniz yok.');

            require_once __DIR__ . '/../includes/CariTaramaHelper.php';
            $helper      = new CariTaramaHelper($db);
            $kayitId     = (int)($_POST['kayit_id']   ?? 0);
            $yetkiliIds  = json_decode($_POST['yetkili_ids'] ?? '[]', true);
            if (!is_array($yetkiliIds)) $yetkiliIds = [];

            $sonuc = $helper->importRecord($kayitId, $yetkiliIds, (int)$user['kullanici_id']);
            echo json_encode(['success' => true, 'message' => 'Aktarım tamamlandı.', 'data' => $sonuc], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@2/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .badge-efatura { font-size: .7rem; }
        .dt-row-selected { background: rgba(13,110,253,.07) !important; }
        .skor-bar { width: 40px; height: 6px; border-radius: 3px; display: inline-block; vertical-align: middle; }
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
                            <li class="breadcrumb-item"><a href="cari-tarama.php">Cari Tarama</a></li>
                            <li class="breadcrumb-item active">Sonuçlar</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Filtre -->
                <div class="card card-outline card-primary mb-3">
                    <div class="card-body">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label mb-1">Tarama İşi</label>
                                <select class="form-select" id="filterIs">
                                    <option value="">Tümü</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label mb-1">Arama (Firma / Vergi No / E-posta)</label>
                                <input type="text" class="form-control" id="filterSearch" placeholder="Ara...">
                            </div>
                            <div class="col-md-3 d-grid">
                                <button class="btn btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Listele</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kayıtlar tablosu -->
                <div class="card card-outline card-success mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-building"></i> Bulunan Firmalar</h3>
                        <div class="card-tools">
                            <span class="badge bg-secondary" id="kayitSayaci">0 kayıt</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-bordered align-middle mb-0" id="tblKayitlar">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40">#</th>
                                        <th>Firma Adı</th>
                                        <th>Vergi No</th>
                                        <th>Telefon</th>
                                        <th>E-posta</th>
                                        <th>Sektör</th>
                                        <th width="60">e-Fatura</th>
                                        <th width="70">Yetkili</th>
                                        <th width="80">Aktarım</th>
                                        <th width="90">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyKayitlar">
                                    <tr><td colspan="10" class="text-center text-muted py-4">Filtrele butonuna tıklayın.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Yetkililer paneli -->
                <div class="card card-outline card-warning" id="yetkiliCard" style="display:none">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="bi bi-people"></i> Yetkili Adayları — <span id="yetkiliKayitAdi" class="text-primary"></span></h3>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-success" id="btnYetkiliAktar"><i class="bi bi-box-arrow-in-right"></i> Seçilileri Aktar</button>
                            <button class="btn btn-sm btn-secondary" id="btnYetkiliKapat"><i class="bi bi-x"></i> Kapat</button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40"><input type="checkbox" id="checkAllY"></th>
                                        <th>Ad Soyad</th>
                                        <th>Görev</th>
                                        <th>Telefon</th>
                                        <th>E-posta</th>
                                        <th width="60">Skor</th>
                                        <th width="80">Aktarım</th>
                                        <th width="50"></th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyYetkililer">
                                    <tr><td colspan="8" class="text-center text-muted py-3">Yükleniyor...</td></tr>
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

<!-- Kayıt Düzenleme Modal -->
<div class="modal fade" id="modalKayitDuzenle" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-building-gear"></i> Firma Bilgilerini Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editKayitId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Firma Adı</label>
                        <input type="text" class="form-control" id="editFirmaAdi">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ünvan</label>
                        <input type="text" class="form-control" id="editUnvan">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Vergi No</label>
                        <input type="text" class="form-control" id="editVergiNo">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Vergi Dairesi</label>
                        <input type="text" class="form-control" id="editVergiDairesi">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Telefon</label>
                        <input type="text" class="form-control" id="editTelefon">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">E-posta</label>
                        <input type="email" class="form-control" id="editEmail">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" id="btnKayitKaydet"><i class="bi bi-floppy"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Yetkili Düzenleme Modal -->
<div class="modal fade" id="modalYetkiliDuzenle" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-gear"></i> Yetkili Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editYetkiliId">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Ad</label>
                        <input type="text" class="form-control" id="editYetkiliAdi">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Soyad</label>
                        <input type="text" class="form-control" id="editYetkiliSoyadi">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Görev</label>
                        <input type="text" class="form-control" id="editYetkiliGorev">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Telefon</label>
                        <input type="text" class="form-control" id="editYetkiliTelefon">
                    </div>
                    <div class="col-6">
                        <label class="form-label">E-posta</label>
                        <input type="email" class="form-control" id="editYetkiliEmail">
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script>
    let activeKayitId  = 0;
    let activeKayitAdi = '';

    $(function () {
        loadIsler();
        loadKayitlar();

        $('#btnFiltrele').on('click', loadKayitlar);
        $('#filterSearch').on('keypress', function (e) { if (e.which === 13) loadKayitlar(); });

        $('#checkAllY').on('change', function () {
            $('.y-check').prop('checked', $(this).is(':checked'));
        });

        $('#btnYetkiliKapat').on('click', function () {
            $('#yetkiliCard').hide();
            activeKayitId = 0;
        });

        $('#btnYetkiliAktar').on('click', aktarYetkilileri);
        $('#btnKayitKaydet').on('click', saveKayit);
        $('#btnYetkiliKaydet').on('click', saveYetkili);

        $(document).on('click', '.btn-edit-kayit', function () {
            const d = $(this).data();
            $('#editKayitId').val(d.id);
            $('#editFirmaAdi').val(d.firmaadi || '');
            $('#editUnvan').val(d.unvan || '');
            $('#editVergiNo').val(d.vergino || '');
            $('#editVergiDairesi').val(d.vergidairesi || '');
            $('#editTelefon').val(d.telefon || '');
            $('#editEmail').val(d.email || '');
            $('#modalKayitDuzenle').modal('show');
        });

        $(document).on('click', '.btn-yetkililer', function () {
            activeKayitId  = $(this).data('id');
            activeKayitAdi = $(this).data('firmaadi') || '#' + activeKayitId;
            $('#yetkiliKayitAdi').text(activeKayitAdi);
            loadYetkililer();
            $('html, body').animate({ scrollTop: $('#yetkiliCard').offset()?.top - 20 || 0 }, 400);
        });

        $(document).on('click', '.btn-edit-yetkili', function () {
            const d = $(this).data();
            $('#editYetkiliId').val(d.id);
            $('#editYetkiliAdi').val(d.adi || '');
            $('#editYetkiliSoyadi').val(d.soyadi || '');
            $('#editYetkiliGorev').val(d.gorev || '');
            $('#editYetkiliTelefon').val(d.telefon || '');
            $('#editYetkiliEmail').val(d.email || '');
            $('#modalYetkiliDuzenle').modal('show');
        });
    });

    // ------------------------------------------------------------------
    function loadIsler() {
        $.post('', { action: 'isler' }, function (r) {
            if (!r.success) return;
            r.data.forEach(function (row) {
                $('#filterIs').append(
                    `<option value="${row.CariTaramaIsleri_Id}">#${row.CariTaramaIsleri_Id} — ${esc(row.CariTaramaIsleri_Domain)} (${esc(row.CariTaramaIsleri_Durum)})</option>`
                );
            });
        }, 'json');
    }

    function loadKayitlar() {
        const tbody = $('#tbodyKayitlar');
        tbody.html('<tr><td colspan="10" class="text-center text-muted py-3"><i class="bi bi-hourglass-split"></i> Yükleniyor...</td></tr>');

        $.post('', {
            action: 'kayitlar',
            is_id:  $('#filterIs').val(),
            search: $('#filterSearch').val()
        }, function (r) {
            tbody.empty();
            if (!r.success) { tbody.html(`<tr><td colspan="10" class="text-danger py-3 text-center">${esc(r.message)}</td></tr>`); return; }
            if (!r.data.length) { tbody.html('<tr><td colspan="10" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>'); $('#kayitSayaci').text('0 kayıt'); return; }

            $('#kayitSayaci').text(r.data.length + ' kayıt');
            r.data.forEach(function (row) {
                const aktarildi = Number(row.CariTaramaKayitlari_Aktarildi) === 1
                    ? `<span class="badge bg-success">Aktarıldı</span>`
                    : `<button class="btn btn-xs btn-outline-success btn-aktar-kayit" data-id="${row.CariTaramaKayitlari_Id}"><i class="bi bi-box-arrow-in-right"></i> Aktar</button>`;

                tbody.append(`
                    <tr>
                        <td class="text-muted">${row.CariTaramaKayitlari_Id}</td>
                        <td>
                            <div class="fw-semibold">${esc(row.CariTaramaKayitlari_FirmaAdi || '-')}</div>
                            <div class="small text-muted">${esc(row.CariTaramaIsleri_Domain || '')}</div>
                        </td>
                        <td>${esc(row.CariTaramaKayitlari_VergiNo || '-')}</td>
                        <td>${esc(row.CariTaramaKayitlari_Telefon || '-')}</td>
                        <td>${esc(row.CariTaramaKayitlari_Email || '-')}</td>
                        <td>${esc(row.CariTaramaKayitlari_SektorMetni || '-')}</td>
                        <td class="text-center">
                            ${Number(row.CariTaramaKayitlari_EfaturaMukellef) === 1
                                ? '<span class="badge bg-success badge-efatura">Mükellef</span>'
                                : '<span class="badge bg-light text-secondary badge-efatura">-</span>'}
                        </td>
                        <td class="text-center">
                            <button class="btn btn-xs btn-outline-primary btn-yetkililer" data-id="${row.CariTaramaKayitlari_Id}" data-firmaadi="${escAttr(row.CariTaramaKayitlari_FirmaAdi)}">
                                <i class="bi bi-people"></i> ${row.YetkiliSayisi}
                            </button>
                        </td>
                        <td class="text-center">${aktarildi}</td>
                        <td>
                            <button class="btn btn-xs btn-outline-secondary btn-edit-kayit"
                                data-id="${row.CariTaramaKayitlari_Id}"
                                data-firmaadi="${escAttr(row.CariTaramaKayitlari_FirmaAdi)}"
                                data-unvan="${escAttr(row.CariTaramaKayitlari_Unvan)}"
                                data-vergino="${escAttr(row.CariTaramaKayitlari_VergiNo)}"
                                data-vergidairesi="${escAttr(row.CariTaramaKayitlari_VergiDairesi || '')}"
                                data-telefon="${escAttr(row.CariTaramaKayitlari_Telefon)}"
                                data-email="${escAttr(row.CariTaramaKayitlari_Email)}">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });

            // Kayıt aktar butonu
            $(document).off('click', '.btn-aktar-kayit').on('click', '.btn-aktar-kayit', function () {
                const kayitId = $(this).data('id');
                Swal.fire({
                    title: 'Firmayı aktar?',
                    text: 'Yetkilileri daha sonra ayrıca aktarabilirsiniz.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Evet, aktar',
                    cancelButtonText: 'İptal'
                }).then(function (result) {
                    if (!result.isConfirmed) return;
                    $.post('', { action: 'aktar', kayit_id: kayitId, yetkili_ids: '[]' }, function (r) {
                        if (!r.success) { showError('Hata', r.message); return; }
                        showSuccess('Başarılı', r.message);
                        loadKayitlar();
                    }, 'json');
                });
            });

        }, 'json');
    }

    function loadYetkililer() {
        if (!activeKayitId) return;
        const tbody = $('#tbodyYetkililer');
        tbody.html('<tr><td colspan="8" class="text-center text-muted py-3"><i class="bi bi-hourglass-split"></i> Yükleniyor...</td></tr>');
        $('#yetkiliCard').show();

        $.post('', { action: 'yetkililer', kayit_id: activeKayitId }, function (r) {
            tbody.empty();
            if (!r.success) { tbody.html(`<tr><td colspan="8" class="text-danger text-center py-3">${esc(r.message)}</td></tr>`); return; }
            if (!r.data.length) { tbody.html('<tr><td colspan="8" class="text-center text-muted py-4">Yetkili adayı bulunamadı.</td></tr>'); return; }

            r.data.forEach(function (row) {
                const name = [row.CariTaramaYetkilileri_Adi, row.CariTaramaYetkilileri_Soyadi].filter(Boolean).join(' ');
                const skor = Number(row.CariTaramaYetkilileri_GuvenSkoru || 0);
                const skorColor = skor >= 80 ? 'success' : skor >= 60 ? 'warning' : 'danger';
                const aktarildi = Number(row.CariTaramaYetkilileri_Aktarildi) === 1
                    ? '<span class="badge bg-success">Aktarıldı</span>'
                    : '';

                tbody.append(`
                    <tr>
                        <td><input type="checkbox" class="form-check-input y-check" value="${row.CariTaramaYetkilileri_Id}" ${Number(row.CariTaramaYetkilileri_Aktarildi) !== 1 ? 'checked' : ''}></td>
                        <td class="fw-semibold">${esc(name || '-')}</td>
                        <td>${esc(row.CariTaramaYetkilileri_Gorev || '-')}</td>
                        <td>${esc(row.CariTaramaYetkilileri_Telefon || '-')}</td>
                        <td>${esc(row.CariTaramaYetkilileri_Email || '-')}</td>
                        <td>
                            <span class="badge bg-${skorColor}">${skor}</span>
                        </td>
                        <td class="text-center">${aktarildi}</td>
                        <td>
                            <button class="btn btn-xs btn-outline-secondary btn-edit-yetkili"
                                data-id="${row.CariTaramaYetkilileri_Id}"
                                data-adi="${escAttr(row.CariTaramaYetkilileri_Adi)}"
                                data-soyadi="${escAttr(row.CariTaramaYetkilileri_Soyadi)}"
                                data-gorev="${escAttr(row.CariTaramaYetkilileri_Gorev)}"
                                data-telefon="${escAttr(row.CariTaramaYetkilileri_Telefon)}"
                                data-email="${escAttr(row.CariTaramaYetkilileri_Email)}">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });
        }, 'json');
    }

    function aktarYetkilileri() {
        if (!activeKayitId) { showWarning('Uyarı', 'Önce bir firma seçin.'); return; }
        const ids = $('.y-check:checked').map(function () { return Number($(this).val()); }).get();
        if (!ids.length) { showWarning('Uyarı', 'En az bir yetkili seçin.'); return; }

        $.post('', {
            action:      'aktar',
            kayit_id:    activeKayitId,
            yetkili_ids: JSON.stringify(ids)
        }, function (r) {
            if (!r.success) { showError('Hata', r.message); return; }
            showSuccess('Başarılı', r.message);
            loadYetkililer();
            loadKayitlar();
        }, 'json');
    }

    function saveKayit() {
        $.post('', {
            action:        'guncelle_kayit',
            kayit_id:      $('#editKayitId').val(),
            firma_adi:     $('#editFirmaAdi').val(),
            unvan:         $('#editUnvan').val(),
            vergi_no:      $('#editVergiNo').val(),
            vergi_dairesi: $('#editVergiDairesi').val(),
            telefon:       $('#editTelefon').val(),
            email:         $('#editEmail').val()
        }, function (r) {
            if (!r.success) { showError('Hata', r.message); return; }
            showSuccess('Başarılı', r.message);
            $('#modalKayitDuzenle').modal('hide');
            loadKayitlar();
        }, 'json');
    }

    function saveYetkili() {
        $.post('', {
            action:     'guncelle_yetkili',
            yetkili_id: $('#editYetkiliId').val(),
            adi:        $('#editYetkiliAdi').val(),
            soyadi:     $('#editYetkiliSoyadi').val(),
            gorev:      $('#editYetkiliGorev').val(),
            telefon:    $('#editYetkiliTelefon').val(),
            email:      $('#editYetkiliEmail').val()
        }, function (r) {
            if (!r.success) { showError('Hata', r.message); return; }
            showSuccess('Başarılı', r.message);
            $('#modalYetkiliDuzenle').modal('hide');
            loadYetkililer();
        }, 'json');
    }

    function esc(v)     { return String(v || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function escAttr(v) { return String(v || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#039;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</body>
</html>
