<?php
/**
 * Admin Panel - Ürün Stok Hareketleri
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Ürün Stok Hareketleri';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'OrnekFirma Portal';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                $toplam   = $db->fetchOne("SELECT COUNT(*) as sayi FROM dbo.Urun_Stok_Hareketleri WHERE Durum = 1")['sayi'] ?? 0;
                $giris    = $db->fetchOne("SELECT COUNT(*) as sayi FROM dbo.Urun_Stok_Hareketleri WHERE Durum = 1 AND Islem_Tipi = 'GIRIS'")['sayi'] ?? 0;
                $cikis    = $db->fetchOne("SELECT COUNT(*) as sayi FROM dbo.Urun_Stok_Hareketleri WHERE Durum = 1 AND Islem_Tipi = 'CIKIS'")['sayi'] ?? 0;
                $bugün    = $db->fetchOne("SELECT COUNT(*) as sayi FROM dbo.Urun_Stok_Hareketleri WHERE Durum = 1 AND CONVERT(date, OlusturmaTarihi) = CONVERT(date, GETDATE())")['sayi'] ?? 0;
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => $toplam,
                    'giris'  => $giris,
                    'cikis'  => $cikis,
                    'bugun'  => $bugün
                ]]);
                break;

            case 'list':
                $islemTipi  = $_POST['islem_tipi'] ?? '';
                $urunId     = $_POST['urun_id'] ?? '';
                $kategoriId = $_POST['kategori_id'] ?? '';
                $markaId    = $_POST['marka_id'] ?? '';
                $faturaNo   = $_POST['fatura_no'] ?? '';
                $startDate  = $_POST['start_date'] ?? '';
                $endDate    = $_POST['end_date'] ?? '';
                $search     = $_POST['search'] ?? '';

                $where  = ["h.Durum = 1"];
                $params = [];

                if ($islemTipi) {
                    $where[] = "h.Islem_Tipi = ?";
                    $params[] = $islemTipi;
                }
                if ($urunId) {
                    $where[] = "h.Urun_id = ?";
                    $params[] = (int)$urunId;
                }
                if ($kategoriId) {
                    $where[] = "uh.urun_hizmet_kategori_id = ?";
                    $params[] = (int)$kategoriId;
                }
                if ($markaId) {
                    $where[] = "uh.urun_hizmet_marka_id = ?";
                    $params[] = (int)$markaId;
                }
                if ($faturaNo) {
                    $where[] = "h.Fatura_No LIKE ?";
                    $params[] = "%$faturaNo%";
                }
                if ($startDate) {
                    $where[] = "CONVERT(date, h.OlusturmaTarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $where[] = "CONVERT(date, h.OlusturmaTarihi) <= ?";
                    $params[] = $endDate;
                }
                if ($search) {
                    $where[] = "(uh.urun_hizmet_adi LIKE ? OR uh.urun_hizmet_kodu LIKE ? OR h.Aciklama LIKE ? OR h.Fatura_No LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                $whereClause = implode(" AND ", $where);

                $sql = "SELECT
                            h.Stok_Hareket_id,
                            h.Urun_id,
                            h.Islem_Tipi,
                            h.Miktar,
                            h.Birim_Fiyati,
                            h.DolarKur,
                            h.EuroKur,
                            h.Fatura_id,
                            h.Fatura_No,
                            h.Aciklama,
                            h.OlusturmaTarihi,
                            uh.urun_hizmet_adi,
                            uh.urun_hizmet_kodu,
                            kat.kategori_adi,
                            mrk.marka_adi,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as OlusturanAdi
                        FROM dbo.Urun_Stok_Hareketleri h
                        LEFT JOIN dbo.Urun_Hizmet uh ON uh.urun_hizmet_id = h.Urun_id
                        LEFT JOIN dbo.UrunHizmet_Kategoriler kat ON kat.kategori_id = uh.urun_hizmet_kategori_id
                        LEFT JOIN dbo.UrunHizmet_Markalar mrk ON mrk.marka_id = uh.urun_hizmet_marka_id
                        LEFT JOIN dbo.kullanicilar k ON k.kullanici_id = h.OlusturanKullanici
                        WHERE $whereClause
                        ORDER BY h.OlusturmaTarihi DESC";

                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'get_urunler':
                $q = $_POST['q'] ?? '';
                $sql = "SELECT TOP 50 urun_hizmet_id, urun_hizmet_kodu, urun_hizmet_adi
                        FROM dbo.Urun_Hizmet
                        WHERE urun_hizmet_tip = 1 AND urun_hizmet_durum = 1
                          AND (urun_hizmet_adi LIKE ? OR urun_hizmet_kodu LIKE ?)
                        ORDER BY urun_hizmet_adi";
                $data = $db->fetchAll($sql, ["%$q%", "%$q%"]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'get_kategoriler':
                $sql = "SELECT kategori_id, kategori_adi FROM dbo.UrunHizmet_Kategoriler WHERE kategori_durum = 1 ORDER BY kategori_sira_no, kategori_adi";
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql)]);
                break;

            case 'get_markalar':
                $sql = "SELECT marka_id, marka_adi FROM dbo.UrunHizmet_Markalar WHERE marka_durum = 1 ORDER BY marka_sira_no, marka_adi";
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql)]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
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
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
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
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-arrow-left-right"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hareket</span>
                                    <span class="info-box-number" id="stat-toplam">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-box-arrow-in-down"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Stok Giriş</span>
                                    <span class="info-box-number" id="stat-giris">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-box-arrow-up"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Stok Çıkış</span>
                                    <span class="info-box-number" id="stat-cikis">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-calendar-day"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün</span>
                                    <span class="info-box-number" id="stat-bugun">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-list-ul"></i> Stok Hareketleri</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-success me-1" id="btnExcelIndir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                            </div>
                        </div>

                        <!-- Filtre Paneli -->
                        <div class="collapse" id="filterCard">
                            <div class="card-body border-bottom bg-light">
                                <form id="filterForm">
                                    <div class="row g-3">
                                        <div class="col-md-2">
                                            <label class="form-label">Başlangıç Tarihi</label>
                                            <input type="date" class="form-control form-control-sm" id="filter_start_date" name="start_date">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Bitiş Tarihi</label>
                                            <input type="date" class="form-control form-control-sm" id="filter_end_date" name="end_date">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">İşlem Tipi</label>
                                            <select class="form-select form-select-sm" id="filter_islem_tipi" name="islem_tipi">
                                                <option value="">Tümü</option>
                                                <option value="GIRIS">Giriş</option>
                                                <option value="CIKIS">Çıkış</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Ürün</label>
                                            <select class="form-select form-select-sm" id="filter_urun_id" name="urun_id">
                                                <option value="">Tümü</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Kategori</label>
                                            <select class="form-select form-select-sm" id="filter_kategori_id" name="kategori_id">
                                                <option value="">Tümü</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Marka</label>
                                            <select class="form-select form-select-sm" id="filter_marka_id" name="marka_id">
                                                <option value="">Tümü</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Ara (Ürün/Fatura/Açıklama)</label>
                                            <input type="text" class="form-control form-control-sm" id="filter_search" name="search" placeholder="Arama...">
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="bi bi-search"></i> Filtrele
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-sm" id="clearFilters">
                                                <i class="bi bi-x-circle"></i> Temizle
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="hareketTable" class="table table-bordered table-striped table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th>Tarih</th>
                                            <th>Ürün Kodu</th>
                                            <th>Ürün Adı</th>
                                            <th>Kategori</th>
                                            <th>Marka</th>
                                            <th>İşlem Tipi</th>
                                            <th>Miktar</th>
                                            <th>Birim Fiyat</th>
                                            <th>Dolar Kur</th>
                                            <th>Euro Kur</th>
                                            <th>Fatura No</th>
                                            <th>Açıklama</th>
                                            <th>Kaydeden</th>
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

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
        let table;
        let currentFilters = {};
        let hasSearched = true;

        function loadStats() {
            $.post('', { action: 'stats' }, function(r) {
                if (r.success) {
                    $('#stat-toplam').text(r.data.toplam);
                    $('#stat-giris').text(r.data.giris);
                    $('#stat-cikis').text(r.data.cikis);
                    $('#stat-bugun').text(r.data.bugun);
                }
            });
        }

        function initDataTable() {
            table = $('#hareketTable').DataTable({
                processing: true,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json',
                    emptyTable: 'Kayıt bulunamadı.'
                },
                ajax: function(data, callback) {
                    $.ajax({
                        url: '',
                        type: 'POST',
                        data: { action: 'list', ...currentFilters },
                        success: function(r) {
                            callback({ data: r.success ? r.data : [] });
                        },
                        error: function() { callback({ data: [] }); }
                    });
                },
                columns: [
                    {
                        data: 'OlusturmaTarihi',
                        render: function(d) {
                            if (!d) return '-';
                            try {
                                const dt = new Date(d.replace(' ', 'T'));
                                return isNaN(dt) ? d : dt.toLocaleDateString('tr-TR', {
                                    year: 'numeric', month: '2-digit', day: '2-digit',
                                    hour: '2-digit', minute: '2-digit'
                                });
                            } catch(e) { return d; }
                        }
                    },
                    { data: 'urun_hizmet_kodu', defaultContent: '-' },
                    { data: 'urun_hizmet_adi', defaultContent: '-' },
                    { data: 'kategori_adi', defaultContent: '-' },
                    { data: 'marka_adi', defaultContent: '-' },
                    {
                        data: 'Islem_Tipi',
                        render: function(d) {
                            if (d === 'GIRIS') return '<span class="badge bg-success">Giriş</span>';
                            if (d === 'CIKIS') return '<span class="badge bg-danger">Çıkış</span>';
                            return '<span class="badge bg-secondary">' + (d || '-') + '</span>';
                        }
                    },
                    {
                        data: 'Miktar',
                        render: function(d, type) {
                            if (type === 'display') {
                                return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(d || 0);
                            }
                            return d;
                        }
                    },
                    {
                        data: 'Birim_Fiyati',
                        render: function(d, type) {
                            if (type === 'display') {
                                return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(d || 0) + ' ₺';
                            }
                            return d;
                        }
                    },
                    {
                        data: 'DolarKur',
                        render: function(d, type) {
                            if (!d || d == 0) return '-';
                            if (type === 'display') {
                                return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(d);
                            }
                            return d;
                        }
                    },
                    {
                        data: 'EuroKur',
                        render: function(d, type) {
                            if (!d || d == 0) return '-';
                            if (type === 'display') {
                                return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(d);
                            }
                            return d;
                        }
                    },
                    {
                        data: 'Fatura_No',
                        defaultContent: '-',
                        render: function(d, type, row) {
                            if (!d) return '-';
                            if (type === 'display' && row.Fatura_id) {
                                return `<span class="badge bg-info text-dark">${d}</span>`;
                            }
                            return d || '-';
                        }
                    },
                    { data: 'Aciklama', defaultContent: '-' },
                    { data: 'OlusturanAdi', defaultContent: '-' }
                ],
                order: [[0, 'desc']],
                pageLength: 25,
                buttons: [
                    {
                        extend: 'excel',
                        title: 'Stok Hareketleri',
                        filename: 'stok-hareketleri-' + new Date().toISOString().slice(0, 10),
                        exportOptions: {
                            columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
                            format: {
                                body: function(data) {
                                    // Badge ve HTML tag'lerini temizle
                                    return $('<div>').html(data).text().trim();
                                }
                            }
                        }
                    }
                ]
            });
        }

        function loadUrunler() {
            $.post('', { action: 'get_urunler', q: '' }, function(r) {
                if (r.success) {
                    const sel = $('#filter_urun_id');
                    r.data.forEach(u => {
                        sel.append(`<option value="${u.urun_hizmet_id}">[${u.urun_hizmet_kodu || ''}] ${u.urun_hizmet_adi}</option>`);
                    });
                    sel.select2({
                        theme: 'bootstrap-5',
                        placeholder: 'Tümü',
                        allowClear: true,
                        language: {
                            noResults: () => 'Sonuç bulunamadı',
                            searching: () => 'Aranıyor...'
                        }
                    });
                }
            });
        }

        function loadKategoriler() {
            $.post('', { action: 'get_kategoriler' }, function(r) {
                if (r.success) {
                    const sel = $('#filter_kategori_id');
                    r.data.forEach(k => {
                        sel.append(`<option value="${k.kategori_id}">${k.kategori_adi}</option>`);
                    });
                    sel.select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true });
                }
            });
        }

        function loadMarkalar() {
            $.post('', { action: 'get_markalar' }, function(r) {
                if (r.success) {
                    const sel = $('#filter_marka_id');
                    r.data.forEach(m => {
                        sel.append(`<option value="${m.marka_id}">${m.marka_adi}</option>`);
                    });
                    sel.select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true });
                }
            });
        }

        $(document).ready(function() {
            loadStats();
            initDataTable();
            loadUrunler();
            loadKategoriler();
            loadMarkalar();

            $('#filter_islem_tipi').select2({
                theme: 'bootstrap-5',
                placeholder: 'Tümü',
                allowClear: true
            });

            $('#btnExcelIndir').on('click', function() {
                table.button(0).trigger();
            });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentFilters = {
                    start_date:  $('#filter_start_date').val(),
                    end_date:    $('#filter_end_date').val(),
                    islem_tipi:  $('#filter_islem_tipi').val(),
                    urun_id:     $('#filter_urun_id').val(),
                    kategori_id: $('#filter_kategori_id').val(),
                    marka_id:    $('#filter_marka_id').val(),
                    search:      $('#filter_search').val()
                };
                Object.keys(currentFilters).forEach(k => { if (!currentFilters[k]) delete currentFilters[k]; });
                hasSearched = true;
                table.ajax.reload();
            });

            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_islem_tipi, #filter_urun_id, #filter_kategori_id, #filter_marka_id').val('').trigger('change.select2');
                currentFilters = {};
                hasSearched = true;
                table.ajax.reload();
            });
        });
    </script>
</body>
</html>
