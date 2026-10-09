<?php
/**
 * Admin Panel - Ticket Listesi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

/**
 * Kullanicinin bilgilendirme (CC) yoluyla erisebildigi taleplerin
 * yerel Tickets_id listesini doner. Destek sisteminden gelen api_id'ler
 * Tickets_api_id uzerinden yerel kayitlarla eslestirilir.
 */
function ccTicketIdleri(Database $db, array $user): array
{
    static $sonuc = null;
    if ($sonuc !== null) {
        return $sonuc;
    }

    $sonuc = [];

    $kullaniciDetay = $db->fetchOne(
        "SELECT TOP 1 kullanici_email FROM dbo.kullanicilar WHERE kullanici_id = ?",
        [(int)$user['kullanici_id']]
    );
    $eposta = trim((string)($kullaniciDetay['kullanici_email'] ?? ($user['email'] ?? '')));
    if ($eposta === '') {
        return $sonuc;
    }

    try {
        $helper = new TicketHelper($db);
        $apiIdler = $helper->ccTicketApiIdleri($eposta);
    } catch (Exception $e) {
        error_log('CC ticket idleri alinamadi: ' . $e->getMessage());
        return $sonuc;
    }

    if (empty($apiIdler)) {
        return $sonuc;
    }

    $yerTutucu = implode(',', array_fill(0, count($apiIdler), '?'));
    $satirlar = $db->fetchAll(
        "SELECT Tickets_id FROM dbo.Tickets
         WHERE Durum = 1 AND Tickets_api_id IN ($yerTutucu)",
        $apiIdler
    ) ?: [];

    foreach ($satirlar as $satir) {
        $sonuc[] = (int)$satir['Tickets_id'];
    }

    return $sonuc;
}

/**
 * "Kendi talebi VEYA CC'li talebi" kosulunu ve parametrelerini uretir.
 * $params dizisine kullanici id'si ve varsa CC id'leri sirayla eklenir.
 */
function ticketErisimKosulu(array $ccIdler, string $onEk, array &$params, int $kullaniciId): string
{
    $params[] = $kullaniciId;

    if (empty($ccIdler)) {
        return " AND {$onEk}Tickets_kullanici_id = ?";
    }

    $yerTutucu = implode(',', array_fill(0, count($ccIdler), '?'));
    foreach ($ccIdler as $id) {
        $params[] = $id;
    }

    return " AND ({$onEk}Tickets_kullanici_id = ? OR {$onEk}Tickets_id IN ($yerTutucu))";
}

$pageTitle   = 'Ticket Listesi';
$pageDesc    = 'Sadece kendi ticket kayitlariniz';
$menuAdi     = 'Destek Sistemi';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Filtre dropdown verileri
$kategoriler  = $db->fetchAll("SELECT Ticket_Kategoriler_id AS id, Ticket_Kategoriler_ad AS ad FROM dbo.Ticket_Kategoriler WHERE Durum = 1 ORDER BY Ticket_Kategoriler_sira");
$oncelikler   = $db->fetchAll("SELECT Ticket_Oncelikler_id AS id, Ticket_Oncelikler_ad AS ad FROM dbo.Ticket_Oncelikler WHERE Durum = 1 ORDER BY Ticket_Oncelikler_sira");
$durumlar     = $db->fetchAll("SELECT Ticket_Durumlar_id AS id, Ticket_Durumlar_ad AS ad FROM dbo.Ticket_Durumlar WHERE Durum = 1 ORDER BY Ticket_Durumlar_sira");

// ─── AJAX ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                $ccIdler = ccTicketIdleri($db, $user);

                $sayim = function (string $ekKosul = '') use ($db, $ccIdler, $user): int {
                    $params = [];
                    $kosul  = ticketErisimKosulu($ccIdler, '', $params, (int)$user['kullanici_id']);
                    $satir  = $db->fetchOne(
                        "SELECT COUNT(*) AS sayi FROM dbo.Tickets WHERE Durum = 1" . $kosul . $ekKosul,
                        $params
                    );
                    return (int)($satir['sayi'] ?? 0);
                };

                $toplam    = $sayim();
                $acik      = $sayim(" AND Tickets_durum_id = 1");
                $islemde   = $sayim(" AND Tickets_durum_id = 2");
                $kapali    = $sayim(" AND Tickets_durum_id IN (4,5)");

                echo json_encode(['success' => true, 'data' => [
                    'toplam'  => $toplam,
                    'acik'    => $acik,
                    'islemde' => $islemde,
                    'kapali'  => $kapali,
                ]]);
                break;

            case 'list':
                $kategoriId   = $_POST['kategori_id']  ?? '';
                $oncelikId    = $_POST['oncelik_id']   ?? '';
                $durumId      = $_POST['durum_id']     ?? '';
                $startDate    = $_POST['start_date']   ?? '';
                $endDate      = $_POST['end_date']     ?? '';
                $search       = $_POST['search']       ?? '';

                $sql = "
                    SELECT
                        v.Tickets_id,
                        v.Tickets_api_id,
                        v.Tickets_no,
                        v.Tickets_konu,
                        v.kategori_adi,
                        v.kategori_renk,
                        v.oncelik_adi,
                        v.oncelik_renk,
                        v.durum_adi,
                        v.durum_renk,
                        v.kullanici_adi,
                        CONVERT(VARCHAR(16), v.OlusturmaTarihi, 120) AS olusturma_tarihi,
                        CONVERT(VARCHAR(16), v.GuncellemeTarihi, 120) AS guncelleme_tarihi,
                        CASE WHEN v.Tickets_kullanici_id = ? THEN 0 ELSE 1 END AS cc_mi
                    FROM dbo.V_Tickets_Liste v
                    WHERE 1=1
                ";
                $ccIdler = ccTicketIdleri($db, $user);
                $params  = [(int)$user['kullanici_id']];
                $sql    .= ticketErisimKosulu($ccIdler, 'v.', $params, (int)$user['kullanici_id']);

                if ($kategoriId) {
                    $sql .= " AND v.Tickets_kategori_id = ?";
                    $params[] = intval($kategoriId);
                }
                if ($oncelikId) {
                    $sql .= " AND v.Tickets_oncelik_id = ?";
                    $params[] = intval($oncelikId);
                }
                if ($durumId) {
                    $sql .= " AND v.Tickets_durum_id = ?";
                    $params[] = intval($durumId);
                }
                if ($startDate) {
                    $sql .= " AND CONVERT(date, v.OlusturmaTarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, v.OlusturmaTarihi) <= ?";
                    $params[] = $endDate;
                }
                if ($search) {
                    $sql .= " AND (v.Tickets_konu LIKE ? OR v.Tickets_no LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                $sql .= " ORDER BY v.Tickets_id DESC";

                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                $ticketKontrol = $db->fetchOne("SELECT Tickets_id FROM dbo.Tickets WHERE Tickets_id = ? AND Tickets_kullanici_id = ? AND Durum = 1", [$id, $user['kullanici_id']]);
                if (!$ticketKontrol) {
                    echo json_encode(['success' => false, 'message' => 'Bu ticket icin islem yetkiniz yok.']);
                    break;
                }
                $db->execute("UPDATE dbo.Tickets SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE Tickets_id = ? AND Tickets_kullanici_id = ?", [$user['kullanici_id'], $id, $user['kullanici_id']]);
                echo json_encode(['success' => true, 'message' => 'Ticket silindi.']);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
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
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-ticket-perforated"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Ticket</span>
                                <span class="info-box-number" id="stat_toplam">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-envelope-open"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Açık</span>
                                <span class="info-box-number" id="stat_acik">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-arrow-repeat"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">İşlemde</span>
                                <span class="info-box-number" id="stat_islemde">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kapalı</span>
                                <span class="info-box-number" id="stat_kapali">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <span><i class="bi bi-funnel me-1"></i> Filtrele</span>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div class="collapse" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label">Kategori</label>
                                    <select id="f_kategori" class="form-select select2-filtre">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kategoriler as $k): ?>
                                        <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Öncelik</label>
                                    <select id="f_oncelik" class="form-select select2-filtre">
                                        <option value="">Tümü</option>
                                        <?php foreach ($oncelikler as $o): ?>
                                        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Durum</label>
                                    <select id="f_durum" class="form-select select2-filtre">
                                        <option value="">Tümü</option>
                                        <?php foreach ($durumlar as $d): ?>
                                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" id="f_start" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" id="f_end" class="form-control">
                                </div>
                                <div class="col-md-4 d-flex align-items-end gap-2">
                                    <button class="btn btn-primary" id="btnFiltrele"><i class="bi bi-search me-1"></i>Filtrele</button>
                                    <button class="btn btn-secondary" id="btnSifirla"><i class="bi bi-x-circle me-1"></i>Sıfırla</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-ticket-perforated me-1"></i> Ticket Listesi</span>
                        <a href="/admin/pages/ticket-ekle.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Yeni Ticket
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table id="tblTicket" class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Ticket No</th>
                                        <th>Konu</th>
                                        <th>Kategori</th>
                                        <th>Öncelik</th>
                                        <th>Durum</th>
                                        <th>Kullanıcı</th>
                                        <th>Tarih</th>
                                        <th>İşlem</th>
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

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script>
$(function () {
    // Destek bildirim: kullanici listeyi gorduyse son kontrol zamanini guncelle, badge'i sifirla
    try { localStorage.setItem('destek_son_kontrol', new Date().toISOString()); } catch (e) {}
    var destekBadge = document.getElementById('destekBadge');
    if (destekBadge) destekBadge.style.display = 'none';

    // Select2
    $('.select2-filtre').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Tümü', allowClear: true });

    // Stats
    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (r.success) {
                $('#stat_toplam').text(r.data.toplam);
                $('#stat_acik').text(r.data.acik);
                $('#stat_islemde').text(r.data.islemde);
                $('#stat_kapali').text(r.data.kapali);
            }
        });
    }
    loadStats();

    // DataTable
    var dt = $('#tblTicket').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        processing: true,
        order: [[0, 'desc']],
        columns: [
            { data: 'Tickets_id' },
            { data: 'Tickets_no', render: d => d ? '<code>' + d + '</code>' : '-' },
            {
                data: 'Tickets_konu',
                render: function (d, t, row) {
                    var link = '<a href="/admin/pages/ticket-detay.php?id=' + row.Tickets_id + '" class="text-decoration-none fw-semibold">' + escHtml(d) + '</a>';
                    // Bilgilendirme (CC) yoluyla erisilen talep: kullanicinin kendi talebi degil
                    if (Number(row.cc_mi) === 1) {
                        link += ' <i class="bi bi-people-fill text-secondary ms-1" title="Bu talebe bilgilendirme (CC) amacıyla eklendiniz"></i>';
                    }
                    return link;
                }
            },
            {
                data: 'kategori_adi',
                render: function (d, t, row) {
                    return d ? '<span class="badge" style="background-color:' + escHtml(row.kategori_renk) + ';color:#fff">' + escHtml(d) + '</span>' : '-';
                }
            },
            {
                data: 'oncelik_adi',
                render: function (d, t, row) {
                    return d ? '<span class="badge" style="background-color:' + escHtml(row.oncelik_renk) + ';color:#fff">' + escHtml(d) + '</span>' : '-';
                }
            },
            {
                data: 'durum_adi',
                render: function (d, t, row) {
                    return d ? '<span class="badge" style="background-color:' + escHtml(row.durum_renk) + ';color:#fff">' + escHtml(d) + '</span>' : '-';
                }
            },
            { data: 'kullanici_adi', render: d => d ? escHtml(d) : '-' },
            { data: 'olusturma_tarihi', render: d => d ?? '-' },
            {
                data: 'Tickets_id',
                orderable: false,
                render: function (id) {
                    return `
                        <a href="/admin/pages/ticket-detay.php?id=${id}" class="btn btn-sm btn-info" title="Detay"><i class="bi bi-eye"></i></a>
                        <button class="btn btn-sm btn-danger btn-sil ms-1" data-id="${id}" title="Sil"><i class="bi bi-trash"></i></button>
                    `;
                }
            }
        ]
    });

    function loadTable() {
        var params = {
            action:       'list',
            kategori_id:  $('#f_kategori').val(),
            oncelik_id:   $('#f_oncelik').val(),
            durum_id:     $('#f_durum').val(),
            start_date:   $('#f_start').val(),
            end_date:     $('#f_end').val(),
        };
        $.post('', params, function (r) {
            dt.clear();
            if (r.success) dt.rows.add(r.data).draw();
        });
    }
    loadTable();

    $('#btnFiltrele').on('click', loadTable);

    $('#btnSifirla').on('click', function () {
        $('.select2-filtre').val(null).trigger('change');
        $('#f_start, #f_end').val('');
        loadTable();
    });

    // Sil
    $('#tblTicket').on('click', '.btn-sil', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Ticket silinsin mi?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Evet, sil',
            cancelButtonText: 'İptal',
            confirmButtonColor: '#dc3545'
        }).then(function (result) {
            if (result.isConfirmed) {
                $.post('', { action: 'delete', id: id }, function (r) {
                    if (r.success) {
                        Swal.fire('Silindi!', r.message, 'success');
                        loadTable();
                        loadStats();
                    } else {
                        Swal.fire('Hata!', r.message, 'error');
                    }
                });
            }
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
