<?php
/**
 * Proforma vs Fatura Karşılaştırma Sayfası
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Proforma - Fatura Karşılaştırma';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'get_proformalar':
                $data = $db->fetchAll("
                    SELECT p.ProformalarGelen_id, p.ProformalarGelen_No, 
                           CONVERT(VARCHAR(10), p.ProformalarGelen_Tarih, 120) as tarih,
                           c.cari_adi, c.cari_unvan, p.ProformalarGelen_Tutar,
                           p.ProformalarGelen_Fatura_id
                    FROM ProformalarGelen p
                    LEFT JOIN Cari c ON p.ProformalarGelen_Cari_id = c.cari_id
                    WHERE p.Durum = 1
                    ORDER BY p.ProformalarGelen_Tarih DESC
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'get_faturalar':
                $data = $db->fetchAll("
                    SELECT FaturalarGelen_id, FaturalarGelen_FaturaNo,
                           CONVERT(VARCHAR(10), FaturalarGelen_Tarih, 120) as tarih,
                           FaturalarGelen_Unvan, FaturalarGelen_Tutar, FaturalarGelen_ParaBirimi
                    FROM FaturalarGelen
                    WHERE Durum = 1
                    ORDER BY FaturalarGelen_Tarih DESC
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'karsilastir':
                $proformaId = intval($_POST['proforma_id'] ?? 0);
                $faturaId = intval($_POST['fatura_id'] ?? 0);
                if (!$proformaId || !$faturaId) throw new Exception('Proforma ve Fatura seçimi zorunludur.');

                // Proforma bilgileri
                $proforma = $db->fetchOne("
                    SELECT p.*, c.cari_adi, c.cari_unvan
                    FROM ProformalarGelen p
                    LEFT JOIN Cari c ON p.ProformalarGelen_Cari_id = c.cari_id
                    WHERE p.ProformalarGelen_id = ?
                ", [$proformaId]);

                $proformaKalemler = $db->fetchAll("
                    SELECT pk.*, pb.ParaBirimi_Kod
                    FROM ProformalarGelenKalemleri pk
                    LEFT JOIN tanim_ParaBirimi pb ON pk.ProformalarGelenKalemleri_ParaBirimi_id = pb.ParaBirimi_id
                    WHERE pk.ProformalarGelenKalemleri_Proforma_id = ?
                    ORDER BY pk.ProformalarGelenKalemleri_Sira
                ", [$proformaId]);

                // Fatura bilgileri
                $fatura = $db->fetchOne("SELECT * FROM FaturalarGelen WHERE FaturalarGelen_id = ?", [$faturaId]);

                $faturaKalemler = $db->fetchAll("
                    SELECT * FROM FaturalarGelenKalemleri
                    WHERE FaturalarGelenKalemleri_Fatura_id = ? AND Durum = 1
                    ORDER BY FaturalarGelenKalemleri_Sira
                ", [$faturaId]);

                echo json_encode([
                    'success' => true,
                    'proforma' => $proforma,
                    'proforma_kalemler' => $proformaKalemler,
                    'fatura' => $fatura,
                    'fatura_kalemler' => $faturaKalemler
                ]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
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
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .fark-pozitif { color: #dc3545; font-weight: 600; }
        .fark-negatif { color: #198754; font-weight: 600; }
        .fark-sifir { color: #6c757d; }
        .kalem-eslesmeyen { background-color: #fff3cd !important; }
        .ozet-kutu { border-left: 4px solid; padding: 12px 16px; border-radius: 4px; }
        .ozet-proforma { border-color: #0d6efd; background: #e7f1ff; }
        .ozet-fatura { border-color: #198754; background: #d1e7dd; }
        .ozet-fark { border-color: #dc3545; background: #f8d7da; }
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

        <div class="app-content">
            <div class="container-fluid">

                <!-- Seçim Kartı -->
                <div class="card card-outline card-primary mb-3">
                    <div class="card-header"><h3 class="card-title"><i class="bi bi-arrows-angle-contract"></i> Karşılaştırma Seçimi</h3></div>
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Proforma Seçin</label>
                                <select id="selProforma" class="form-select select2" style="width:100%">
                                    <option value="">-- Proforma Seçin --</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Fatura Seçin</label>
                                <select id="selFatura" class="form-select select2" style="width:100%">
                                    <option value="">-- Fatura Seçin --</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button id="btnKarsilastir" class="btn btn-primary w-100"><i class="bi bi-arrows-angle-contract me-1"></i>Karşılaştır</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sonuç Alanı -->
                <div id="sonucAlani" style="display:none">
                    <!-- Özet Kutuları -->
                    <div class="row mb-3" id="ozetRow">
                        <div class="col-md-4">
                            <div class="ozet-kutu ozet-proforma">
                                <h6><i class="bi bi-file-earmark-text me-1"></i>Proforma Toplam</h6>
                                <h4 id="ozetProformaTutar" class="mb-0">0,00 ₺</h4>
                                <small id="ozetProformaNo" class="text-muted"></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="ozet-kutu ozet-fatura">
                                <h6><i class="bi bi-receipt me-1"></i>Fatura Toplam</h6>
                                <h4 id="ozetFaturaTutar" class="mb-0">0,00 ₺</h4>
                                <small id="ozetFaturaNo" class="text-muted"></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="ozet-kutu ozet-fark">
                                <h6><i class="bi bi-plus-slash-minus me-1"></i>Fark</h6>
                                <h4 id="ozetFark" class="mb-0">0,00 ₺</h4>
                                <small id="ozetFarkYuzde" class="text-muted"></small>
                            </div>
                        </div>
                    </div>

                    <!-- Karşılaştırma Tablosu -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="bi bi-table me-1"></i>Kalem Karşılaştırma</h3>
                            <button id="btnExcelIndir" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel İndir</button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover mb-0" id="karsilastirmaTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th rowspan="2" class="align-middle text-center">#</th>
                                            <th rowspan="2" class="align-middle">Ürün / Stok Kodu</th>
                                            <th colspan="3" class="text-center bg-primary text-white">Proforma</th>
                                            <th colspan="2" class="text-center bg-success text-white">Fatura</th>
                                            <th colspan="1" class="text-center bg-danger text-white">Fark</th>
                                        </tr>
                                        <tr>
                                            <th class="text-center">Miktar</th>
                                            <th class="text-end">B.Fiyat (TL)</th>
                                            <th class="text-end">Kur</th>
                                            <th class="text-center">Miktar</th>
                                            <th class="text-end">B.Fiyat</th>
                                            <th class="text-end">Tutar Fark</th>
                                        </tr>
                                    </thead>
                                    <tbody id="karsilastirmaBody"></tbody>
                                    <tfoot id="karsilastirmaFoot"></tfoot>
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

<script src="/admin/assets/vendor/jquery/jquery.min.js"></script>
<script src="/admin/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/vendor/select2/js/select2.min.js"></script>
<script src="/admin/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
    const fmt = v => parseFloat(v || 0).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});

    $(document).ready(function() {
        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
        loadDropdowns();

        $('#btnKarsilastir').click(karsilastir);
        $('#btnExcelIndir').click(excelIndir);
    });

    function loadDropdowns() {
        // Önce fatura listesini yükle
        $.post('proforma-fatura-karsilastir', {action:'get_faturalar'}, function(res) {
            if (!res.success) return;
            res.data.forEach(f => {
                const label = `${f.FaturalarGelen_FaturaNo} | ${f.tarih} | ${f.FaturalarGelen_Unvan || '-'} | ${fmt(f.FaturalarGelen_Tutar)} ${f.FaturalarGelen_ParaBirimi || 'TRY'}`;
                $('#selFatura').append(`<option value="${f.FaturalarGelen_id}" data-fatura-no="${f.FaturalarGelen_FaturaNo}">${label}</option>`);
            });

            // Sonra proforma listesini yükle
            $.post('proforma-fatura-karsilastir', {action:'get_proformalar'}, function(res2) {
                if (!res2.success) return;
                res2.data.forEach(p => {
                    const label = `${p.ProformalarGelen_No} | ${p.tarih} | ${p.cari_adi || p.cari_unvan || '-'} | ${fmt(p.ProformalarGelen_Tutar)} ₺`;
                    $('#selProforma').append(`<option value="${p.ProformalarGelen_id}" data-fatura-id="${p.ProformalarGelen_Fatura_id || ''}">${label}</option>`);
                });
            }, 'json');
        }, 'json');

        // Proforma seçildiğinde bağlı faturayı otomatik seç
        $('#selProforma').on('change', function() {
            const faturaId = $(this).find(':selected').data('fatura-id');
            if (faturaId) {
                $('#selFatura').val(faturaId).trigger('change');
            }
        });
    }

    function karsilastir() {
        const pId = $('#selProforma').val();
        const fId = $('#selFatura').val();
        if (!pId || !fId) { Swal.fire('Uyarı','Lütfen hem Proforma hem Fatura seçin.','warning'); return; }

        // Önce fatura kalemlerini API'den çekip DB'ye kaydet
        const faturaNo = $('#selFatura').find(':selected').data('fatura-no');
        if (!faturaNo) {
            doKarsilastir(pId, fId);
            return;
        }

        Swal.fire({ title: 'Yükleniyor...', text: 'Fatura kalemleri API\'den çekiliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.post('gelen-efaturalar', { action: 'get_invoice_lines', fatura_no: faturaNo }, function(apiRes) {
            Swal.close();
            doKarsilastir(pId, fId);
        }, 'json').fail(function() {
            Swal.close();
            // API bağlantısı başarısız olsa bile DB'deki mevcut verilerle devam et
            doKarsilastir(pId, fId);
        });
    }

    function doKarsilastir(pId, fId) {
        $.post('proforma-fatura-karsilastir', {action:'karsilastir', proforma_id:pId, fatura_id:fId}, function(res) {
            if (!res.success) { Swal.fire('Hata', res.message, 'error'); return; }
            renderKarsilastirma(res);
        }, 'json');
    }

    function renderKarsilastirma(res) {
        const pk = res.proforma_kalemler || [];
        const fk = res.fatura_kalemler || [];

        // Ürün adı bazlı eşleştirme
        const matched = [];
        const usedF = new Set();

        pk.forEach(p => {
            const pAdi = (p.ProformalarGelenKalemleri_UrunAdi || '').trim().toLowerCase();
            const pKod = (p.ProformalarGelenKalemleri_StokKodu || '').trim().toLowerCase();
            let bestIdx = -1;

            fk.forEach((f, i) => {
                if (usedF.has(i)) return;
                const fAdi = (f.FaturalarGelenKalemleri_UrunAdi || '').trim().toLowerCase();
                if (pAdi && fAdi && (pAdi === fAdi || pKod && fAdi.includes(pKod) || pAdi.includes(fAdi) || fAdi.includes(pAdi))) {
                    bestIdx = i;
                }
            });

            if (bestIdx >= 0) {
                matched.push({p, f: fk[bestIdx]});
                usedF.add(bestIdx);
            } else {
                matched.push({p, f: null});
            }
        });

        fk.forEach((f, i) => { if (!usedF.has(i)) matched.push({p: null, f}); });

        // Tablo render
        let html = '';
        let topPro = 0, topFat = 0;

        matched.forEach((row, idx) => {
            const pMik = row.p ? parseFloat(row.p.ProformalarGelenKalemleri_Miktar || 0) : 0;
            const pFiy = row.p ? parseFloat(row.p.ProformalarGelenKalemleri_BirimFiyat || 0) : 0;
            const pKdv = row.p ? parseFloat(row.p.ProformalarGelenKalemleri_KDVOrani || 0) : 0;
            const pKur = row.p ? parseFloat(row.p.ProformalarGelenKalemleri_Kur || 1) : 1;
            const pNet = pMik * pFiy * pKur;
            const pTop = pNet + (pNet * pKdv / 100);

            const fMik = row.f ? parseFloat(row.f.FaturalarGelenKalemleri_Miktar || 0) : 0;
            const fFiy = row.f ? parseFloat(row.f.FaturalarGelenKalemleri_BirimFiyat || 0) : 0;
            const fKdv = row.f ? parseFloat(row.f.FaturalarGelenKalemleri_KDVOrani || 0) : 0;
            const fTop = row.f ? parseFloat(row.f.FaturalarGelenKalemleri_GToplamTutar || 0) : 0;

            topPro += pTop;
            topFat += fTop;

            const pFiyTL = pFiy * pKur;
            const fark = fFiy - pFiyTL;
            const farkYuzde = pFiyTL !== 0 ? ((fark / pFiyTL) * 100) : (fFiy !== 0 ? 100 : 0);
            const farkCss = fark > 0.01 ? 'fark-pozitif' : (fark < -0.01 ? 'fark-negatif' : 'fark-sifir');

            const urunAdi = row.p ? (row.p.ProformalarGelenKalemleri_UrunAdi || '-') : (row.f ? (row.f.FaturalarGelenKalemleri_UrunAdi || '-') : '-');
            const pStokKodu = row.p ? (row.p.ProformalarGelenKalemleri_StokKodu || '') : '';
            const fStokKodu = row.f ? (row.f.FaturalarGelenKalemleri_StokKodu || '') : '';
            const rowCss = (!row.p || !row.f) ? 'kalem-eslesmeyen' : '';

            let stokHtml = '';
            if (pStokKodu) stokHtml += `<small class="badge bg-primary me-1">P: ${pStokKodu}</small>`;
            if (fStokKodu) stokHtml += `<small class="badge bg-success">F: ${fStokKodu}</small>`;

            html += `<tr class="${rowCss}">
                <td class="text-center">${idx+1}</td>
                <td>${urunAdi}${stokHtml ? '<br>'+stokHtml : ''}</td>
                <td class="text-center">${row.p ? pMik : '<span class="text-muted">-</span>'}</td>
                <td class="text-end">${row.p ? fmt(pFiy * pKur) : '<span class="text-muted">-</span>'}</td>
                <td class="text-end">${row.p ? fmt(pKur) : '<span class="text-muted">-</span>'}</td>
                <td class="text-center">${row.f ? fMik : '<span class="text-muted">-</span>'}</td>
                <td class="text-end">${row.f ? fmt(fFiy) : '<span class="text-muted">-</span>'}</td>
                <td class="text-end ${farkCss}">${fmt(fark)}</td>
            </tr>`;
        });

        $('#karsilastirmaBody').html(html);

        // Footer toplamlar
        const topFark = topFat - topPro;
        const topFarkYuzde = topPro !== 0 ? ((topFark / topPro) * 100) : 0;
        const topFarkCss = topFark > 0.01 ? 'fark-pozitif' : (topFark < -0.01 ? 'fark-negatif' : 'fark-sifir');

        // Proforma kalemlerinden kur bilgilerini çek
        let euroKur = null, dolarKur = null;
        pk.forEach(p => {
            const pbId = parseInt(p.ProformalarGelenKalemleri_ParaBirimi_id || 0);
            const kur = parseFloat(p.ProformalarGelenKalemleri_Kur || 0);
            if (pbId === 3 && kur > 0 && !euroKur) euroKur = kur;
            if (pbId === 2 && kur > 0 && !dolarKur) dolarKur = kur;
        });

        let footHtml = `<tr class="table-secondary fw-bold">
            <td colspan="2" class="text-end">TOPLAM</td>
            <td colspan="2"></td>
            <td></td>
            <td colspan="2"></td>
            <td class="text-end ${topFarkCss}">${fmt(topFark)}</td>
        </tr>`;

        if (dolarKur || euroKur) {
            footHtml += `<tr class="table-light">
                <td colspan="2" class="text-end fw-bold">Proforma Kurları</td>
                <td colspan="6">
                    ${dolarKur ? '<span class="badge bg-info me-3"><i class="bi bi-currency-dollar"></i> Dolar Kur: <b>' + fmt(dolarKur) + '</b></span>' : ''}
                    ${euroKur ? '<span class="badge bg-primary"><i class="bi bi-currency-euro"></i> Euro Kur: <b>' + fmt(euroKur) + '</b></span>' : ''}
                </td>
            </tr>`;
        }

        $('#karsilastirmaFoot').html(footHtml);

        // Özet kutuları
        const pNo = res.proforma?.ProformalarGelen_No || '-';
        const fNo = res.fatura?.FaturalarGelen_FaturaNo || '-';
        $('#ozetProformaTutar').text(fmt(topPro) + ' ₺');
        $('#ozetProformaNo').text('Proforma: ' + pNo);
        $('#ozetFaturaTutar').text(fmt(topFat) + ' ₺');
        $('#ozetFaturaNo').text('Fatura: ' + fNo);
        $('#ozetFark').text(fmt(topFark) + ' ₺').removeClass('fark-pozitif fark-negatif fark-sifir').addClass(topFarkCss);
        $('#ozetFarkYuzde').text('Yüzde: ' + fmt(topFarkYuzde) + '%');

        $('#sonucAlani').fadeIn();
    }

    function excelIndir() {
        const table = document.getElementById('karsilastirmaTable');
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.table_to_sheet(table);
        XLSX.utils.book_append_sheet(wb, ws, 'Karşılaştırma');
        const pNo = $('#ozetProformaNo').text().replace('Proforma: ','');
        const fNo = $('#ozetFaturaNo').text().replace('Fatura: ','');
        XLSX.writeFile(wb, `Karsilastirma_${pNo}_vs_${fNo}.xlsx`);
    }
</script>
</body>
</html>
