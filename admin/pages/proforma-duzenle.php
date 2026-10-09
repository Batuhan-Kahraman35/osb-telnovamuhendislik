<?php
/**
 * Proforma Düzenleme Sayfası
 * Hem yeni ekleme hem düzenleme için kullanılır
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../../vendor/autoload.php';

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

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Düzenleme modu kontrolü
$editId = intval($_GET['id'] ?? 0);
$isEdit = $editId > 0;

// Veri yükleme (düzenleme için)
$proforma = null;
$kalemler = [];
if ($isEdit) {
    if (!$pagePermissions['can_edit']) {
        PageAuth::accessDenied('Düzenleme yetkiniz bulunmamaktadır.');
    }
    $proforma = $db->fetchOne("SELECT * FROM ProformalarGelen WHERE ProformalarGelen_id = ?", [$editId]);
    if (!$proforma) {
        die('Kayıt bulunamadı.');
    }
    $kalemler = $db->fetchAll("SELECT * FROM ProformalarGelenKalemleri WHERE ProformalarGelenKalemleri_Proforma_id = ? ORDER BY ProformalarGelenKalemleri_Sira", [$editId]);
} else {
    if (!$pagePermissions['can_add']) {
        PageAuth::accessDenied('Ekleme yetkiniz bulunmamaktadır.');
    }
}

// Para birimleri
$paraBirimleri = $db->fetchAll("SELECT ParaBirimi_id, ParaBirimi_Kod, ParaBirimi_Ad FROM tanim_ParaBirimi WHERE Durum = 1 ORDER BY ParaBirimi_id");

// Cariler
$cariler = $db->fetchAll("SELECT cari_id, cari_adi, cari_unvan FROM Cari WHERE cari_aktif = 1 ORDER BY cari_adi");

// Faturalar (bağlama için)
$faturalar = $db->fetchAll("
    SELECT fg.FaturalarGelen_id, fg.FaturalarGelen_FaturaNo, fg.FaturalarGelen_Tarih, c.cari_adi
    FROM FaturalarGelen fg
    LEFT JOIN Cari c ON fg.FaturalarGelen_VKN_TCKN = c.cari_vergi_no
    WHERE fg.Durum = 1
    ORDER BY fg.FaturalarGelen_Tarih DESC
");

// Form submit işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = intval($_POST['id'] ?? 0);
        $cari_id = intval($_POST['ProformalarGelen_Cari_id'] ?? 0);
        if (!$cari_id) throw new Exception('Lütfen bir cari seçin.');

        $data = [
            'ProformalarGelen_No' => $_POST['ProformalarGelen_No'],
            'ProformalarGelen_Tarih' => $_POST['ProformalarGelen_Tarih'],
            'ProformalarGelen_Cari_id' => $cari_id,
            'ProformalarGelen_Tutar' => floatval($_POST['ProformalarGelen_Tutar'] ?? 0),
            'ProformalarGelen_KurUSD' => floatval($_POST['ProformalarGelen_KurUSD'] ?? 1),
            'ProformalarGelen_KurEUR' => floatval($_POST['ProformalarGelen_KurEUR'] ?? 1),
            'ProformalarGelen_Aciklama' => $_POST['ProformalarGelen_Aciklama'] ?? null,
            'ProformalarGelen_Fatura_id' => !empty($_POST['ProformalarGelen_Fatura_id']) ? intval($_POST['ProformalarGelen_Fatura_id']) : null,
        ];

        // Dosya yükleme
        if (isset($_FILES['dosya']) && $_FILES['dosya']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/proformalar/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['dosya']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid('prof_') . '.' . $ext;
            if (move_uploaded_file($_FILES['dosya']['tmp_name'], $uploadDir . $fileName)) {
                $data['ProformalarGelen_DosyaYolu'] = 'admin/uploads/proformalar/' . $fileName;
            }
        }

        if ($id > 0) {
            // Güncelleme
            $db->update('ProformalarGelen', $data, ['ProformalarGelen_id' => $id]);
            $db->delete('ProformalarGelenKalemleri', ['ProformalarGelenKalemleri_Proforma_id' => $id]);
        } else {
            // Yeni kayıt
            $data['OlusturanKullanici'] = $user['kullanici_id'];
            $data['OlusturmaTarihi'] = date('Y-m-d H:i:s');
            $data['Durum'] = 1;
            $id = $db->insert('ProformalarGelen', $data);
        }

        // Kalemleri kaydet
        if (isset($_POST['kalemler'])) {
            $kalemler = json_decode($_POST['kalemler'], true);
            $sira = 1;
            foreach ($kalemler as $k) {
                $kalemData = [
                    'ProformalarGelenKalemleri_Proforma_id' => $id,
                    'ProformalarGelenKalemleri_Sira' => $sira++,
                    'ProformalarGelenKalemleri_StokKodu' => $k['StokKodu'] ?? null,
                    'ProformalarGelenKalemleri_UrunAdi' => $k['UrunAdi'] ?? '',
                    'ProformalarGelenKalemleri_Miktar' => floatval($k['Miktar'] ?? 0),
                    'ProformalarGelenKalemleri_Birim' => $k['Birim'] ?? 'ADET',
                    'ProformalarGelenKalemleri_BirimFiyat' => floatval($k['BirimFiyat'] ?? 0),
                    'ProformalarGelenKalemleri_ParaBirimi_id' => intval($k['ParaBirimi_id'] ?? 1),
                    'ProformalarGelenKalemleri_Kur' => floatval($k['Kur'] ?? 1),
                    'ProformalarGelenKalemleri_KDVOrani' => floatval($k['KDVOrani'] ?? 0),
                    'ProformalarGelenKalemleri_KDVTutar' => floatval($k['KDVTutar'] ?? 0),
                    'ProformalarGelenKalemleri_NetTutar' => floatval($k['NetTutar'] ?? 0),
                    'ProformalarGelenKalemleri_GToplamTutar' => floatval($k['GToplamTutar'] ?? 0),
                    'OlusturanKullanici' => $user['kullanici_id'],
                    'OlusturmaTarihi' => date('Y-m-d H:i:s'),
                    'Durum' => 1
                ];
                $db->insert('ProformalarGelenKalemleri', $kalemData);
            }
        }

        // Güncelleme tarihi
        if ($editId > 0) {
            $db->update('ProformalarGelen', [
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi' => date('Y-m-d H:i:s')
            ], ['ProformalarGelen_id' => $editId]);
        }

        header('Location: proformalar-gelen.php?success=' . ($editId > 0 ? 'updated' : 'created'));
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = $isEdit ? 'Proforma Düzenle' : 'Yeni Proforma Oluştur';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin Panel</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/select2/css/select2.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/select2/css/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        .total-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content">
                <div class="container-fluid">
                    <div class="row pt-3 mb-2">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?php echo $pageTitle; ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="anasayfa.php">Ana Sayfa</a></li>
                                <li class="breadcrumb-item"><a href="proformalar-gelen.php">Proformalar</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><?php echo $isEdit ? 'Düzenle' : 'Yeni'; ?></li>
                            </ol>
                        </div>
                    </div>
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <?php if (isset($_GET['success'])): ?>
                        <div class="alert alert-success">Proforma başarıyla <?php echo $_GET['success'] === 'created' ? 'oluşturuldu' : 'güncellendi'; ?>.</div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-body">
                            <form id="mainForm" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="id" value="<?php echo $editId; ?>">

                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Cari <span class="text-danger">*</span></label>
                                        <select class="form-select select2" name="ProformalarGelen_Cari_id" required>
                                            <option value="">Seçiniz...</option>
                                            <?php foreach($cariler as $c): ?>
                                                <option value="<?php echo $c['cari_id']; ?>" <?php echo ($proforma && $proforma['ProformalarGelen_Cari_id'] == $c['cari_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c['cari_adi']); ?> / <?php echo htmlspecialchars($c['cari_unvan'] ?: '-'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Proforma No <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="ProformalarGelen_No" value="<?php echo htmlspecialchars($proforma['ProformalarGelen_No'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tarih</label>
                                        <input type="date" class="form-control" name="ProformalarGelen_Tarih" value="<?php echo $proforma['ProformalarGelen_Tarih'] ?? date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-primary">USD Kuru</label>
                                        <input type="number" step="0.0001" class="form-control border-primary" name="ProformalarGelen_KurUSD" value="<?php echo $proforma['ProformalarGelen_KurUSD'] ?? 1; ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label text-danger">EUR Kuru</label>
                                        <input type="number" step="0.0001" class="form-control border-danger" name="ProformalarGelen_KurEUR" value="<?php echo $proforma['ProformalarGelen_KurEUR'] ?? 1; ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Bağlı Fatura (Opsiyonel)</label>
                                        <select class="form-select select2" name="ProformalarGelen_Fatura_id">
                                            <option value="">Fatura Bağlı Değil</option>
                                            <?php foreach($faturalar as $f): ?>
                                                <option value="<?php echo $f['FaturalarGelen_id']; ?>" 
                                                    data-fatura-no="<?php echo htmlspecialchars($f['FaturalarGelen_FaturaNo']); ?>"
                                                    <?php echo ($proforma && $proforma['ProformalarGelen_Fatura_id'] == $f['FaturalarGelen_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($f['FaturalarGelen_FaturaNo']); ?> - <?php echo date('d.m.Y', strtotime($f['FaturalarGelen_Tarih'])); ?> (<?php echo htmlspecialchars($f['cari_adi'] ?: '-'); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered" id="itemsTable">
                                        <thead>
                                            <tr>
                                                <th width="40">Durum</th>
                                                <th width="120">Stok Kodu</th>
                                                <th>Ürün/Hizmet Adı</th>
                                                <th width="80">Miktar</th>
                                                <th width="80">Birim</th>
                                                <th width="100">B. Fiyat</th>
                                                <th width="90">Döviz</th>
                                                <th width="80">Kur</th>
                                                <th width="70">KDV%</th>
                                                <th width="100">Tutar</th>
                                                <th width="40"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="itemsBody">
                                            <?php if (!empty($kalemler)): ?>
                                                <?php foreach($kalemler as $k): ?>
                                                    <tr class="kalem-row">
                                                        <td class="text-center align-middle">
                                                            <i class="bi bi-check-circle-fill text-success" title="Kayıtlı"></i>
                                                        </td>
                                                        <td>
                                                            <select class="form-select stok-kodu-select" style="width: 150px;"
                                                                data-init-kod="<?php echo htmlspecialchars($k['ProformalarGelenKalemleri_StokKodu'] ?? ''); ?>"
                                                                data-init-adi="<?php echo htmlspecialchars($k['ProformalarGelenKalemleri_UrunAdi'] ?? ''); ?>">
                                                            </select>
                                                            <input type="hidden" class="stok-kodu-val" value="<?php echo htmlspecialchars($k['ProformalarGelenKalemleri_StokKodu'] ?? ''); ?>">
                                                        </td>
                                                        <td><input type="text" class="form-control urun-adi" value="<?php echo htmlspecialchars($k['ProformalarGelenKalemleri_UrunAdi'] ?? ''); ?>" required></td>
                                                        <td><input type="number" step="0.01" class="form-control miktar" value="<?php echo $k['ProformalarGelenKalemleri_Miktar'] ?? 1; ?>"></td>
                                                        <td><input type="text" class="form-control birim" value="<?php echo htmlspecialchars($k['ProformalarGelenKalemleri_Birim'] ?? 'ADET'); ?>"></td>
                                                        <td><input type="number" step="0.0001" class="form-control fiyat" value="<?php echo $k['ProformalarGelenKalemleri_BirimFiyat'] ?? 0; ?>"></td>
                                                        <td>
                                                            <select class="form-select para-birimi">
                                                                <?php foreach($paraBirimleri as $pb): ?>
                                                                    <option value="<?php echo $pb['ParaBirimi_id']; ?>" <?php echo ($k['ProformalarGelenKalemleri_ParaBirimi_id'] ?? 1) == $pb['ParaBirimi_id'] ? 'selected' : ''; ?>><?php echo $pb['ParaBirimi_Kod']; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </td>
                                                        <td><input type="number" step="0.0001" class="form-control kur" value="<?php echo $k['ProformalarGelenKalemleri_Kur'] ?? 1; ?>"></td>
                                                        <td><input type="number" step="1" class="form-control kdv" value="<?php echo $k['ProformalarGelenKalemleri_KDVOrani'] ?? 20; ?>"></td>
                                                        <td>
                                                            <input type="text" class="form-control text-end toplam-tutar" readonly value="0">
                                                            <input type="hidden" class="kdv-tutar" value="0">
                                                            <input type="hidden" class="net-tutar" value="0">
                                                        </td>
                                                        <td class="text-center align-middle"><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="$(this).closest('tr').remove(); calculateTotals();"><i class="bi bi-trash"></i></button></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="10">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddItem"><i class="bi bi-plus"></i> Satır Ekle</button>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Açıklama / Notlar</label>
                                        <textarea class="form-control" name="ProformalarGelen_Aciklama" rows="3"><?php echo htmlspecialchars($proforma['ProformalarGelen_Aciklama'] ?? ''); ?></textarea>
                                        <div class="mt-2">
                                            <div class="d-flex justify-content-between align-items-end">
                                                <label class="form-label">Dosya Yükle (Excel)</label>
                                                <button type="button" class="btn btn-link btn-sm p-0 mb-2" onclick="downloadTemplate()"><i class="bi bi-download"></i> Örnek Şablon İndir</button>
                                            </div>
                                            <div class="input-group">
                                                <input type="file" class="form-control" name="dosya" id="excelInput" accept=".xlsx, .xls">
                                                <button class="btn btn-outline-success" type="button" id="btnParseExcel"><i class="bi bi-file-earmark-spreadsheet"></i> Excel'den Verileri Çek</button>
                                            </div>
                                            <div id="fileInfo" class="mt-1 small text-info">
                                                <?php if ($isEdit && $proforma['ProformalarGelen_DosyaYolu']): ?>
                                                    <i class="bi bi-file-earmark-spreadsheet"></i> Mevcut dosya: <a href="/<?php echo $proforma['ProformalarGelen_DosyaYolu']; ?>" target="_blank">Görüntüle</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="total-box">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Ara Toplam (TL):</span>
                                                <span id="labelAraToplam">0,00</span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>KDV Toplam (TL):</span>
                                                <span id="labelKDVToplam">0,00</span>
                                            </div>
                                            <hr>
                                            <div class="d-flex justify-content-between fw-bold">
                                                <span>Genel Toplam (TL):</span>
                                                <span id="labelGenelToplam">0,00</span>
                                            </div>
                                            <input type="hidden" name="ProformalarGelen_Tutar" id="hiddenTotal">
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Proformayı Kaydet</button>
                                    <a href="proformalar-gelen.php" class="btn btn-secondary">İptal</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
        </div>
    </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="/admin/assets/vendor/select2/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        const paraBirimleri = <?php echo json_encode($paraBirimleri); ?>;
        const isEdit = <?php echo $isEdit ? 'true' : 'false'; ?>;

        $(document).ready(function() {
            initSelect2();
            
            // PHP'den render edilen mevcut satırlar için Select2 AJAX init
            $('#itemsBody tr.kalem-row').each(function() {
                const $row = $(this);
                const $stokSelect = $row.find('.stok-kodu-select');
                const initKod = $stokSelect.data('init-kod') || '';
                const initAdi = $stokSelect.data('init-adi') || '';

                $stokSelect.select2({
                    theme: 'bootstrap-5',
                    placeholder: 'Stok kodu ara...',
                    allowClear: true,
                    minimumInputLength: 0,
                    ajax: {
                        url: 'proformalar-gelen',
                        type: 'POST',
                        delay: 250,
                        data: params => ({ action: 'search_products', q: params.term || '' }),
                        processResults: res => ({
                            results: res.data.map(p => ({ id: p.kod, text: p.text, extra: p.adi, kdv: p.kdv_oran }))
                        })
                    }
                });

                if (initKod) {
                    const label = initKod + (initAdi ? ' - ' + initAdi : '');
                    const preOption = new Option(label, initKod, true, true);
                    $stokSelect.append(preOption).trigger('change.select2');
                }

                $stokSelect.on('select2:select', function(e) {
                    const sel = e.params.data;
                    const $currentRow = $(this).closest('tr');
                    $currentRow.find('.stok-kodu-val').val(sel.id);
                    if (sel.extra) {
                        $currentRow.find('.urun-adi').val(sel.extra);
                        if (sel.kdv !== undefined) $currentRow.find('.kdv').val(sel.kdv);
                        $currentRow.find('td:first').html('<i class="bi bi-check-circle-fill text-success" title="Sistemle Eşleşti"></i>');
                    }
                    calculateTotals();
                }).on('change', function() {
                    $(this).closest('tr').find('.stok-kodu-val').val($(this).val());
                });

                // Para birimi Select2
                $row.find('.para-birimi').select2({ theme: 'bootstrap-5', minimumResultsForSearch: Infinity });
            });

            if (!$('#itemsBody tr').length) {
                addItem();
            }
            calculateTotals();
        });

        // Bağlı Fatura seçildiğinde stok kodu eşleştirmesi yap
        $('select[name="ProformalarGelen_Fatura_id"]').on('change', function() {
            const faturaId = $(this).val();
            const proformaRows = $('#itemsBody tr.kalem-row');

            // Fatura kaldırıldıysa ikonları sıfırla
            if (!faturaId) {
                proformaRows.each(function() {
                    $(this).find('td:first').html('<i class="bi bi-dash-circle text-muted" title="Fatura Bağlı Değil"></i>');
                });
                return;
            }

            // Önce API'den fatura kalemlerini çekip DB'yi güncelle (StokKodu dahil)
            const faturaNo = $(this).find(':selected').data('fatura-no');
            
            function doEslestirme(faturaId, proformaRows) {
                $.post('proformalar-gelen', { action: 'get_fatura_kalemleri', fatura_id: faturaId }, function(res) {
                if (!res.success || !res.data.length) {
                    Swal.fire('Bilgi', 'Bu faturaya ait kalem bulunamadı. Önce gelen e-Faturalar sayfasından fatura kalemlerini kaydediniz.', 'info');
                    proformaRows.each(function() {
                        $(this).find('td:first').html('<i class="bi bi-question-circle text-muted" title="Fatura Kalemi Yok"></i>');
                    });
                    return;
                }

                // Fatura stok kodlarını topla
                const faturaStokKodlari = res.data.map(fk => (fk.FaturalarGelenKalemleri_StokKodu || '').trim().toLowerCase()).filter(k => k);

                // Proforma stok kodlarını topla
                const proformaStokKodlari = [];
                proformaRows.each(function() {
                    proformaStokKodlari.push(($(this).find('.stok-kodu-val').val() || '').trim().toLowerCase());
                });

                // 1) Proforma satırlarının ikonlarını güncelle
                proformaRows.each(function() {
                    const pStokKodu = ($(this).find('.stok-kodu-val').val() || '').trim().toLowerCase();
                    if (pStokKodu && faturaStokKodlari.includes(pStokKodu)) {
                        $(this).find('td:first').html('<i class="bi bi-check-circle-fill text-success" title="Fatura ile Eşleşti"></i>');
                    } else {
                        $(this).find('td:first').html('<i class="bi bi-x-circle-fill text-danger" title="Fatura ile Eşleşmedi"></i>');
                    }
                });

                // 2) Her fatura kaleminin bir proforma kaleminde olup olmadığını kontrol et
                const faturaEslesen = [];
                const faturaEslesmeyen = [];

                res.data.forEach(fk => {
                    const fStokKodu = (fk.FaturalarGelenKalemleri_StokKodu || '').trim().toLowerCase();
                    const fUrunAdi = (fk.FaturalarGelenKalemleri_UrunAdi || '').trim();
                    const label = fStokKodu ? fStokKodu + ' - ' + fUrunAdi : fUrunAdi;

                    if (fStokKodu && proformaStokKodlari.includes(fStokKodu)) {
                        faturaEslesen.push(label);
                    } else {
                        faturaEslesmeyen.push(label);
                    }
                });

                // Sonuç bildir
                if (faturaEslesmeyen.length > 0) {
                    let msg = `<b>${faturaEslesen.length}</b> fatura kalemi eşleşti, <b class="text-danger">${faturaEslesmeyen.length}</b> fatura kalemi proformada bulunamadı:<br><br>`;
                    msg += '<ul class="text-start">';
                    faturaEslesmeyen.forEach(e => msg += `<li class="text-danger">${e}</li>`);
                    msg += '</ul>';
                    Swal.fire({ title: 'Eşleştirme Sonucu', html: msg, icon: 'warning', width: 600 });
                } else {
                    Swal.fire('Başarılı', `Tüm ${faturaEslesen.length} fatura kalemi proforma ile eşleşti!`, 'success');
                }
                }, 'json');
            }

            // Fatura numarası varsa önce API'den kalemleri çek (StokKodu güncellemesi dahil)
            if (faturaNo) {
                Swal.fire({ title: 'Yükleniyor...', text: 'Fatura kalemleri güncelleniyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                $.post('gelen-efaturalar', { action: 'get_invoice_lines', fatura_no: faturaNo }, function() {
                    Swal.close();
                    doEslestirme(faturaId, proformaRows);
                }, 'json').fail(function() {
                    Swal.close();
                    doEslestirme(faturaId, proformaRows);
                });
            } else {
                doEslestirme(faturaId, proformaRows);
            }
        });

        $('#btnAddItem').click(addItem);

        function addItem(data = null) {
            let dbPbId = data ? (data.ProformalarGelenKalemleri_ParaBirimi_id ?? data.ParaBirimi_id) : null;
            
            // Excel'den geliyorsa ParaBirimi_Kod üzerinden ID bul
            if (data && data.ParaBirimi_Kod && !dbPbId) {
                const found = paraBirimleri.find(pb => pb.ParaBirimi_Kod === data.ParaBirimi_Kod);
                if (found) dbPbId = found.ParaBirimi_id;
            }
            if (!dbPbId) dbPbId = 1; // Default TL

            const dbKur = data ? parseFloat(data.ProformalarGelenKalemleri_Kur ?? data.Kur ?? 1) : null;
            const dbKdv = data ? parseFloat(data.ProformalarGelenKalemleri_KDVOrani ?? data.KDVOrani ?? 20) : 20;
            const dbMiktar = data ? parseFloat(data.ProformalarGelenKalemleri_Miktar ?? data.Miktar ?? 1) : 1;
            const dbFiyat = data ? parseFloat(data.ProformalarGelenKalemleri_BirimFiyat ?? data.BirimFiyat ?? 0) : 0;

            let pbOptions = paraBirimleri.map(pb => `<option value="${pb.ParaBirimi_id}">${pb.ParaBirimi_Kod}</option>`).join('');

            let statusHtml = '<i class="bi bi-question-circle text-muted" title="Manuel Giriş"></i>';
            let initialOption = '';

            if (data) {
                const stokKodu = data.ProformalarGelenKalemleri_StokKodu || data.StokKodu || '';
                const urunAdi = data.ProformalarGelenKalemleri_UrunAdi || data.UrunAdi || '';
                if (stokKodu) {
                    // DB'den veya Excel'den gelen her kayıt için seçeneği oluştur
                    const isMatched = data.Matched === true;
                    const isUnmatched = data.Matched === false;
                    if (isMatched) {
                        statusHtml = `<i class="bi bi-check-circle-fill text-success" title="Sistemde Kayıtlı"></i>`;
                    } else if (isUnmatched) {
                        statusHtml = `<i class="bi bi-exclamation-triangle-fill text-warning" title="Sistemde Kayıtlı Değil"></i>`;
                    } else {
                        // DB'den yüklenen kayıt (Matched bilgisi yok)
                        statusHtml = `<i class="bi bi-check-circle-fill text-success" title="Kayıtlı Kalem"></i>`;
                    }
                    initialOption = `<option value="${stokKodu}" selected>${stokKodu}${urunAdi ? ' - ' + urunAdi : ''}</option>`;
                }
            }

            // Başlangıç stok kodu bilgisi (Select2 init sonrası programatik set edilecek)
            const initStokKodu = data ? (data.ProformalarGelenKalemleri_StokKodu || data.StokKodu || '') : '';
            const initStokLabel = initStokKodu + (data && (data.ProformalarGelenKalemleri_UrunAdi || data.UrunAdi) ? ' - ' + (data.ProformalarGelenKalemleri_UrunAdi || data.UrunAdi) : '');

            const row = `
                <tr class="kalem-row">
                    <td class="text-center align-middle">${statusHtml}</td>
                    <td>
                        <select class="form-select stok-kodu-select" style="width: 150px;"></select>
                        <input type="hidden" class="stok-kodu-val" value="${data ? (data.ProformalarGelenKalemleri_StokKodu || data.StokKodu || '') : ''}">
                    </td>
                    <td><input type="text" class="form-control urun-adi" value="${data ? (data.ProformalarGelenKalemleri_UrunAdi || data.UrunAdi || '') : ''}" required></td>
                    <td><input type="number" step="0.01" class="form-control miktar" value="${dbMiktar}"></td>
                    <td><input type="text" class="form-control birim" value="${data ? (data.ProformalarGelenKalemleri_Birim || data.Birim || 'ADET') : 'ADET'}"></td>
                    <td><input type="number" step="0.0001" class="form-control fiyat" value="${dbFiyat}"></td>
                    <td><select class="form-select para-birimi">${pbOptions}</select></td>
                    <td><input type="number" step="0.0001" class="form-control kur" value="${dbKur ?? 1}"></td>
                    <td><input type="number" step="1" class="form-control kdv" value="${dbKdv}"></td>
                    <td>
                        <input type="text" class="form-control text-end toplam-tutar" readonly value="0">
                        <input type="hidden" class="kdv-tutar" value="0">
                        <input type="hidden" class="net-tutar" value="0">
                    </td>
                    <td class="text-center align-middle"><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="$(this).closest('tr').remove(); calculateTotals();"><i class="bi bi-trash"></i></button></td>
                </tr>
            `;
            $('#itemsBody').append(row);

            const $row = $('#itemsBody tr:last');

            const $stokSelect = $row.find('.stok-kodu-select').select2({
                theme: 'bootstrap-5',
                placeholder: 'Stok kodu ara...',
                allowClear: true,
                minimumInputLength: 0,
                ajax: {
                    url: 'proformalar-gelen',
                    type: 'POST',
                    delay: 250,
                    data: params => ({ action: 'search_products', q: params.term || '' }),
                    processResults: res => ({
                        results: res.data.map(p => ({ id: p.kod, text: p.text, extra: p.adi, kdv: p.kdv_oran }))
                    })
                }
            });

            // Mevcut/Excel'den gelen stok kodunu programatik olarak set et
            // (HTML'e option yazmak AJAX aramasını engellediği için böyle yapılıyor)
            if (initStokKodu) {
                const preOption = new Option(initStokLabel, initStokKodu, true, true);
                $stokSelect.append(preOption).trigger('change.select2');
            }

            $stokSelect.on('select2:select', function(e) {
                const sel = e.params.data;
                const $currentRow = $(this).closest('tr');
                $currentRow.find('.stok-kodu-val').val(sel.id);
                if (sel.extra) {
                    $currentRow.find('.urun-adi').val(sel.extra);
                    if (sel.kdv !== undefined) $currentRow.find('.kdv').val(sel.kdv);
                    $currentRow.find('td:first').html('<i class="bi bi-check-circle-fill text-success" title="Sistemle Eşleşti"></i>');
                }
                calculateTotals();
            }).on('change', function() {
                $(this).closest('tr').find('.stok-kodu-val').val($(this).val());
            });

            const $pbSelect = $row.find('.para-birimi');
            $pbSelect.select2({
                theme: 'bootstrap-5',
                minimumResultsForSearch: Infinity
            });

            if (data && dbPbId) {
                $pbSelect.val(dbPbId).trigger('change.select2');
                // Para birimi değişimi event'inin (updateRowKur) Excel'den gelen kur değerini ezmesini engellemek için tekrar set ediyoruz
                setTimeout(() => {
                    $row.find('.kur').val(dbKur ?? 1);
                    calculateTotals();
                }, 50);
            } else {
                updateRowKur($row);
            }

            $pbSelect.on('select2:select', function() {
                updateRowKur($(this).closest('tr'));
                calculateTotals();
            });

            calculateTotals();
        }

        $(document).on('input', '.miktar, .fiyat, .kdv, .kur', calculateTotals);
        $(document).on('input', '[name="ProformalarGelen_KurUSD"], [name="ProformalarGelen_KurEUR"]', function() {
            $('#itemsBody tr').each(function() {
                updateRowKur($(this));
            });
            calculateTotals();
        });
        $(document).on('change', '.para-birimi', function() {
            updateRowKur($(this).closest('tr'));
            calculateTotals();
        });

        function updateRowKur(row) {
            const pbId = row.find('.para-birimi').val();
            const pbKod = row.find('.para-birimi option:selected').text();
            let kur = 1;
            if (pbKod === 'USD') kur = $('[name="ProformalarGelen_KurUSD"]').val();
            else if (pbKod === 'EUR') kur = $('[name="ProformalarGelen_KurEUR"]').val();
            row.find('.kur').val(kur);
        }

        function calculateTotals() {
            let araToplamTL = 0;
            let kdvToplamTL = 0;
            let genelToplamTL = 0;

            $('#itemsBody tr').each(function() {
                const miktar = parseFloat($(this).find('.miktar').val()) || 0;
                const fiyat = parseFloat($(this).find('.fiyat').val()) || 0;
                const kdvOran = parseFloat($(this).find('.kdv').val()) || 0;
                const kur = parseFloat($(this).find('.kur').val()) || 1;

                const netTutarDoviz = miktar * fiyat;
                const netTutarTL = netTutarDoviz * kur;
                const kdvTutarTL = netTutarTL * (kdvOran / 100);
                const toplamTutarTL = netTutarTL + kdvTutarTL;

                $(this).find('.toplam-tutar').val(netTutarDoviz.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

                $(this).find('.net-tutar').val(netTutarTL.toFixed(2));
                $(this).find('.kdv-tutar').val(kdvTutarTL.toFixed(2));

                araToplamTL += netTutarTL;
                kdvToplamTL += kdvTutarTL;
                genelToplamTL += toplamTutarTL;
            });

            $('#labelAraToplam').text(araToplamTL.toLocaleString('tr-TR', { minimumFractionDigits: 2 }));
            $('#labelKDVToplam').text(kdvToplamTL.toLocaleString('tr-TR', { minimumFractionDigits: 2 }));
            $('#labelGenelToplam').text(genelToplamTL.toLocaleString('tr-TR', { minimumFractionDigits: 2 }));
            $('#hiddenTotal').val(genelToplamTL.toFixed(2));
        }

        function initSelect2() {
            $('.select2').select2({
                theme: 'bootstrap-5'
            });
        }

        $('#mainForm').on('submit', function(e) {
            const kalemler = [];
            $('#itemsBody tr').each(function() {
                const miktar = parseFloat($(this).find('.miktar').val()) || 0;
                const fiyat = parseFloat($(this).find('.fiyat').val()) || 0;
                const kur = parseFloat($(this).find('.kur').val()) || 1;
                const kdvOran = parseFloat($(this).find('.kdv').val()) || 0;

                const netTutarTL = miktar * fiyat * kur;
                const kdvTutarTL = netTutarTL * (kdvOran / 100);
                const gToplamTutarTL = netTutarTL + kdvTutarTL;

                kalemler.push({
                    StokKodu: $(this).find('.stok-kodu-val').val(),
                    UrunAdi: $(this).find('.urun-adi').val(),
                    Miktar: miktar,
                    Birim: $(this).find('.birim').val(),
                    BirimFiyat: fiyat,
                    ParaBirimi_id: $(this).find('.para-birimi').val(),
                    Kur: kur,
                    KDVOrani: kdvOran,
                    KDVTutar: kdvTutarTL,
                    NetTutar: netTutarTL,
                    GToplamTutar: gToplamTutarTL
                });
            });

            // Kalemleri hidden input olarak ekle
            if (!$('#kalemlerInput').length) {
                $('<input>').attr({
                    type: 'hidden',
                    name: 'kalemler',
                    id: 'kalemlerInput'
                }).appendTo('#mainForm');
            }
            $('#kalemlerInput').val(JSON.stringify(kalemler));
        });

        function downloadTemplate() {
            window.location.href = 'proformalar-gelen?action=download_template';
        }

        // Excel İşleme
        $('#btnParseExcel').click(function() {
            const fileInput = document.getElementById('excelInput');
            const file = fileInput.files[0];
            if (!file) {
                Swal.fire('Hata', 'Lütfen önce bir Excel dosyası seçin.', 'error');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                const jsonData = XLSX.utils.sheet_to_json(worksheet, { header: 1 });

                // Sunucuya parse_excel isteği gönder
                $.post('proformalar-gelen', {
                    action: 'parse_excel',
                    data: JSON.stringify(jsonData)
                }, function(res) {
                    if (res.success) {
                        // Mevcut boş satırları temizle (isteğe bağlı)
                        if ($('#itemsBody tr').length === 1 && !$('#itemsBody tr:first .urun-adi').val()) {
                            $('#itemsBody').empty();
                        }
                        
                        res.data.forEach(item => {
                            addItem(item);
                        });
                        
                        calculateTotals();
                        Swal.fire('Başarılı', res.data.length + ' kalem başarıyla içe aktarıldı.', 'success');
                        fileInput.value = ''; // Inputu temizle
                    } else {
                        Swal.fire('Hata', res.message || 'Excel işlenirken bir hata oluştu.', 'error');
                    }
                }, 'json').fail(function() {
                    Swal.fire('Hata', 'Sunucu ile iletişim kurulamadı.', 'error');
                });
            };
            reader.readAsArrayBuffer(file);
        });
        function initSelect2() {
            $('.select2').each(function() {
                $(this).select2({
                    theme: 'bootstrap-5',
                    dropdownParent: $(this).closest('.modal-content').length ? $(this).closest('.modal-content') : $(document.body)
                });
            });
        }
    </script>
</body>
</html>