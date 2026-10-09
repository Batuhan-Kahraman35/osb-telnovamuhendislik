<?php
/**
 * Teklif PDF Çıktısı
 * DOMPDF ile teklif/proforma PDF oluşturma
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireAuth();
$db = Database::getInstance();

// Teklif ID
$teklifId = intval($_GET['id'] ?? 0);
if ($teklifId <= 0) {
    die('Geçersiz teklif ID.');
}

// Teklif bilgileri
$teklif = $db->fetchOne("
    SELECT 
        t.*,
        CAST(t.Teklifler_aciklama AS NVARCHAR(MAX)) as Teklifler_aciklama,
        CAST(t.Teklifler_notlar AS NVARCHAR(MAX)) as Teklifler_notlar,
        CONVERT(VARCHAR(10), t.Teklifler_tarih, 104) as tarih_fmt,
        tt.Teklif_Tipleri_ad as tip_adi,
        td.Teklif_Durumlari_ad as durum_adi,
        c.cari_adi, c.cari_unvan, c.cari_telefon as cari_Yetkili_telefon, c.cari_email as cari_Yetkili_email,
        cy.CariYetkili_Adi, cy.CariYetkili_Soyadi, cy.CariYetkili_Telefon as cy_telefon, cy.CariYetkili_Email as cy_email,
        s.SehirAdi, il.IlceAdi,
        hazirlayan.kullanici_ad + ' ' + hazirlayan.kullanici_soyad as teklif_veren_adi,
        hazirlayan.kullanici_email as teklif_veren_email,
        hazirlayan.kullanici_telefon as teklif_veren_telefon,
        d.departman_adi as teklif_veren_gorevi
    FROM Teklifler t
    LEFT JOIN Teklif_Tipleri tt ON t.Teklifler_tip_id = tt.Teklif_Tipleri_id
    LEFT JOIN Teklif_Durumlari td ON t.Teklifler_durum_id = td.Teklif_Durumlari_id
    LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
    OUTER APPLY (
        SELECT TOP 1 CariYetkili_Adi, CariYetkili_Soyadi, CariYetkili_Telefon, CariYetkili_Email
        FROM CariYetkili
        WHERE CariYetkili_CariId = t.Teklifler_cari_id AND Durum = 1
        ORDER BY CariYetkili_Id
    ) cy
    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
    LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
    LEFT JOIN kullanicilar hazirlayan ON ISNULL(t.Teklifler_hazirlayan_id, t.OlusturanKullanici) = hazirlayan.kullanici_id
    LEFT JOIN kullanici_Departmanlar d ON hazirlayan.kullanici_departman_id = d.departman_id
    WHERE t.Teklifler_id = ? AND t.Durum = 1
", [$teklifId]);

if (!$teklif) {
    die('Teklif bulunamadı.');
}

$teklifAciklamaRaw = trim((string)($teklif['Teklifler_aciklama'] ?? ''));
$teklifAciklamaHtml = nl2br(htmlspecialchars($teklifAciklamaRaw, ENT_QUOTES, 'UTF-8'));

// Teklif kalemleri
$kalemler = $db->fetchAll("
    SELECT 
        tk.*,
        uh.urun_hizmet_kodu,
        uh.urun_hizmet_adi,
        uh.urun_hizmet_birim,
        uh.urun_hizmet_gorsel_url,
        k.kdv_adi, k.kdv_oran,
        pb.ParaBirimi_Kod
    FROM Teklif_Kalemleri tk
    LEFT JOIN Urun_Hizmet uh ON tk.Teklif_Kalemleri_urun_hizmet_id = uh.urun_hizmet_id
    LEFT JOIN UrunHizmet_KDVTanimlari k ON tk.Teklif_Kalemleri_kdv_id = k.kdv_id
    LEFT JOIN tanim_ParaBirimi pb ON tk.Teklif_Kalemleri_para_birimi_id = pb.ParaBirimi_id
    WHERE tk.Teklif_Kalemleri_teklif_id = ? AND tk.Durum = 1
    ORDER BY tk.Teklif_Kalemleri_sira_no, tk.Teklif_Kalemleri_id
", [$teklifId]);

// Header görseli base64
$headerPath = __DIR__ . '/../assets/images/TeklifHeader.jpg';
$headerBase64 = '';
if (file_exists($headerPath)) {
    $headerData = file_get_contents($headerPath);
    $headerBase64 = 'data:image/jpeg;base64,' . base64_encode($headerData);
}



// Geçerlilik tarihi hesapla
$gecerlilikGun = intval($teklif['Teklifler_gecerlilik_suresi'] ?? 30);
$teklifTarihRaw = $teklif['Teklifler_tarih'] ?? null;
if ($teklifTarihRaw instanceof DateTime) {
    $teklifTarihStr = $teklifTarihRaw->format('Y-m-d');
} else {
    $teklifTarihStr = $teklifTarihRaw ? (string)$teklifTarihRaw : date('Y-m-d');
}
$gecerlilikTarihi = date('d.m.Y', strtotime($teklifTarihStr . " +{$gecerlilikGun} days"));

// Para birimi (sabit TRY)
$paraBirimi = 'TRY';
$paraSimge = '₺';

// Sayı formatlama
if (!function_exists('formatTutar')) {
    function formatTutar($sayi) {
        return number_format(floatval($sayi), 2, ',', '.');
    }
}

if (!function_exists('formatMiktar')) {
    function formatMiktar($sayi) {
        return number_format(round(floatval($sayi)), 0, ',', '.');
    }
}

// Ürün görselleri için base64 dönüştürme
if (!function_exists('gorselBase64')) {
    function gorselBase64($url) {
        if (empty($url)) return '';
        // Sunucudaki fiziksel yol (dosyalar admin/ altında)
        $basePath = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR;
        $filePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $url);
        if (file_exists($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mime = ($ext === 'png') ? 'image/png' : (($ext === 'gif') ? 'image/gif' : 'image/jpeg');
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($filePath));
        }
        return '';
    }
}

// HTML Şablon
$html = '<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<style>
    @page {
        margin: 8mm 12mm 15mm 12mm;
        size: A4;
    }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 10px;
        color: #333;
        line-height: 1.4;
        margin: 0;
        padding: 0;
    }

    /* Header */
    .header-img {
        width: 100%;
        margin-bottom: 10px;
    }
    .header-img img {
        width: 100%;
        height: auto;
        display: block;
    }

    /* Adres */
    .adres-bar {
        font-size: 8.5px;
        color: #555;
        padding: 3px 0;
        border-bottom: 2px solid #0d47a1;
        margin-bottom: 8px;
    }
    .adres-bar a {
        color: #0d6efd;
        text-decoration: none;
    }

    /* Section Headers */
    .section-header {
        background-color: #0d47a1;
        color: #fff;
        font-weight: bold;
        font-size: 10px;
        padding: 5px 8px;
        margin-bottom: 0;
        text-transform: uppercase;
    }

    /* İletişim tablosu */
    .iletisim-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
        border: 1px solid #ddd;
    }
    .iletisim-table td {
        padding: 4px 8px;
        font-size: 9.5px;
        border: 1px solid #ddd;
        vertical-align: top;
    }
    .iletisim-table .label {
        font-weight: bold;
        color: #0d47a1;
        white-space: nowrap;
        width: 15%;
    }
    .iletisim-table .value {
        width: 35%;
    }
    .iletisim-table .label-right {
        font-weight: bold;
        color: #0d47a1;
        white-space: nowrap;
        width: 15%;
    }
    .iletisim-table .value-right {
        width: 35%;
    }

    /* Ürün tablosu */
    .urun-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
        border: 1px solid #ddd;
    }

    /* Dip Toplam Kutusu */
    .dip-toplam-wrap {
        width: 100%;
        margin-bottom: 12px;
    }
    .dip-toplam-tablo {
        width: 35%;
        margin-left: auto;
        border-collapse: collapse;
        border: 1px solid #aac4e0;
    }
    .dip-toplam-tablo td {
        font-size: 9px;
        padding: 4px 8px;
        border: 1px solid #aac4e0;
    }
    .dip-toplam-tablo td.dt-label {
        background-color: #d6e4f0;
        color: #0d47a1;
        font-weight: bold;
        white-space: nowrap;
    }
    .dip-toplam-tablo td.dt-value {
        text-align: right;
        white-space: nowrap;
    }
    .dip-toplam-tablo tr.dt-genel td {
        background-color: #0d47a1;
        color: #fff;
        font-weight: bold;
        font-size: 10px;
    }
    .urun-table th {
        background-color: #d6e4f0;
        color: #0d47a1;
        font-size: 9px;
        padding: 5px 4px;
        text-align: center;
        border: 1px solid #ddd;
        font-weight: bold;
    }
    .urun-table td {
        padding: 6px 4px;
        font-size: 9px;
        border: 1px solid #ddd;
        vertical-align: middle;
    }
    .urun-table td.text-center {
        text-align: center;
    }
    .urun-table td.text-right {
        text-align: right;
    }
    .urun-table .urun-aciklama {
        font-size: 8.5px;
        text-align: left;
    }
    .urun-gorsel {
        width: 65px;
        height: 50px;
        object-fit: contain;
    }

    /* Ticari Koşullar */
    .kosullar {
        border: 1px solid #ddd;
        padding: 8px 10px;
        font-size: 8.5px;
        line-height: 1.5;
    }
    .kosullar p {
        margin: 2px 0;
    }

    .aciklama-kutu {
        border: 1px solid #ddd;
        border-top: none;
        padding: 8px 10px;
        font-size: 9px;
        line-height: 1.45;
        white-space: normal;
        word-break: break-word;
        margin-bottom: 12px;
    }


</style>
</head>
<body>';

// Header görseli
$html .= '<div class="header-img">';
if ($headerBase64) {
    $html .= '<img src="' . $headerBase64 . '" alt="Teklif Header">';
}
$html .= '</div>';

// İletişim Bilgileri
$html .= '<div class="section-header">İLETİŞİM BİLGİLERİ</div>';
$html .= '
<table class="iletisim-table">
    <tr>
        <td class="label">Firma Ünvanı</td>
        <td class="value">' . htmlspecialchars($teklif['cari_unvan'] ?? $teklif['cari_adi'] ?? '') . '</td>
        <td class="label-right">Teklif Veren:</td>
        <td class="value-right">' . htmlspecialchars(mb_convert_case($teklif['teklif_veren_adi'] ?? '', MB_CASE_TITLE, 'UTF-8')) . '</td>
    </tr>
    <tr>
        <td class="label">Yetkili</td>
        <td class="value">' . htmlspecialchars(mb_convert_case(trim(($teklif['CariYetkili_Adi'] ?? '') . ' ' . ($teklif['CariYetkili_Soyadi'] ?? '')), MB_CASE_TITLE, 'UTF-8')) . '</td>
        <td class="label-right">Görevi:</td>
        <td class="value-right">' . htmlspecialchars($teklif['teklif_veren_gorevi'] ?? '') . '</td>
    </tr>
    <tr>
        <td class="label">Tarih</td>
        <td class="value">' . htmlspecialchars($teklif['tarih_fmt'] ?? '') . '</td>
        <td class="label-right">Gsm:</td>
        <td class="value-right">' . htmlspecialchars($teklif['teklif_veren_telefon'] ?? '') . '</td>
    </tr>
    <tr>
        <td class="label">Teklif No</td>
        <td class="value">' . htmlspecialchars($teklif['Teklifler_no'] ?? '') . '</td>
        <td class="label-right">Email:</td>
        <td class="value-right">' . htmlspecialchars($teklif['teklif_veren_email'] ?? '') . '</td>
    </tr>
</table>';

// Fiyat Teklifi - Ürün Tablosu
$html .= '<div class="section-header">FİYAT TEKLİFİMİZ</div>';
$html .= '
<table class="urun-table">
    <thead>
        <tr>
            <th style="width:10%">Ürün Kodu</th>
            <th style="width:24%">Ürün Açıklaması</th>
            <th style="width:10%">Birim Fiyat</th>
            <th style="width:8%">Döviz Cinsi</th>
            <th style="width:7%">Birim</th>
            <th style="width:6%">Miktar</th>
            <th style="width:7%">KDV</th>
            <th style="width:9%">KDV Tutar</th>
            <th style="width:9%">Toplam</th>
            <th style="width:10%">Fotoğraf</th>
        </tr>
    </thead>
    <tbody>';

$genelAraToplam = 0;
$genelKdvToplam = 0;
$genelToplam = 0;
$genelMiktar = 0;

foreach ($kalemler as $kalem) {
    $gorselB64 = gorselBase64($kalem['urun_hizmet_gorsel_url'] ?? '');
    $gorselHtml = $gorselB64 ? '<img src="' . $gorselB64 . '" class="urun-gorsel">' : '';
    $miktar = floatval($kalem['Teklif_Kalemleri_miktar'] ?? 1);
    $birimFiyat = floatval($kalem['Teklif_Kalemleri_birim_fiyat'] ?? 0);
    $kdvOran = floatval($kalem['kdv_oran'] ?? 0);
    $satirToplam = $miktar * $birimFiyat;
    $satirKdvTutar = $satirToplam * ($kdvOran / 100);
    $satirGenelToplam = $satirToplam + $satirKdvTutar;
    
    $genelAraToplam += $satirToplam;
    $genelKdvToplam += $satirKdvTutar;
    $genelToplam += $satirGenelToplam;
    $genelMiktar += $miktar;
    
    $html .= '
        <tr>
            <td class="text-center">' . htmlspecialchars($kalem['urun_hizmet_kodu'] ?? '') . '</td>
            <td class="urun-aciklama">' . htmlspecialchars($kalem['urun_hizmet_adi'] ?? '') . '</td>
            <td class="text-right">' . formatTutar($birimFiyat) . '</td>
            <td class="text-center">' . htmlspecialchars($kalem['ParaBirimi_Kod'] ?? $paraBirimi) . '</td>
            <td class="text-center">' . htmlspecialchars($kalem['Teklif_Kalemleri_birim'] ?? $kalem['urun_hizmet_birim'] ?? '') . '</td>
            <td class="text-center">' . formatMiktar($miktar) . '</td>
            <td class="text-center">%' . intval($kdvOran) . '</td>
            <td class="text-right">' . formatTutar($satirKdvTutar) . '</td>
            <td class="text-right">' . formatTutar($satirToplam) . '</td>
            <td class="text-center">' . $gorselHtml . '</td>
        </tr>';
}

$html .= '
    </tbody>
</table>';

// KDV gruplarını hesapla (farklı oranlar için)
$kdvGruplari = [];
foreach ($kalemler as $kalem) {
    $oran = intval($kalem['kdv_oran'] ?? 0);
    $miktar2 = floatval($kalem['Teklif_Kalemleri_miktar'] ?? 1);
    $birimFiyat2 = floatval($kalem['Teklif_Kalemleri_birim_fiyat'] ?? 0);
    $satirToplam2 = $miktar2 * $birimFiyat2;
    $satirKdv2 = $satirToplam2 * ($oran / 100);
    if (!isset($kdvGruplari[$oran])) $kdvGruplari[$oran] = 0;
    $kdvGruplari[$oran] += $satirKdv2;
}
ksort($kdvGruplari);

$html .= '<div class="dip-toplam-wrap"><table class="dip-toplam-tablo">';
$html .= '<tr><td class="dt-label">Ara Toplam</td><td class="dt-value">' . formatTutar($genelAraToplam) . '</td></tr>';
foreach ($kdvGruplari as $oran => $kdvTutar) {
    $html .= '<tr><td class="dt-label">KDV (%' . $oran . ')</td><td class="dt-value">' . formatTutar($kdvTutar) . '</td></tr>';
}
$html .= '<tr class="dt-genel"><td class="dt-label">Genel Toplam</td><td class="dt-value">' . formatTutar($genelToplam) . '</td></tr>';
$html .= '</table></div>';

if ($teklifAciklamaRaw !== '') {
    $html .= '<div class="section-header">AÇIKLAMA</div>';
    $html .= '<div class="aciklama-kutu">' . $teklifAciklamaHtml . '</div>';
}

// Ticari Koşullar
$html .= '<div class="section-header">TİCARİ KOŞULLAR</div>';
$html .= '
<div class="kosullar">
    <p>* Fiyat teklifimiz ' . htmlspecialchars($gecerlilikTarihi) . ' tarihine kadar geçerlidir.</p>
    <p>* Döviz olarak verilen tekliflerimizde Fatura kesim tarihi TCMB döviz satış kuru baz alınmaktadır.</p>
    <p>* Teklif fiyatımıza KDV dahil değildir.</p>
    <p>* Yıllık alım potansiyeline göre Örnek Mühendislik iskonto oranlarında değişiklik yapma hakkına sahiptir.</p>
    <p>* Vadesi geçen bakiyeler için Örnek Mühendislik vade farkı yansıtacaktır.</p>';

// Ödeme koşulu varsa ekle
if (!empty($teklif['Teklifler_odeme_kosulu'])) {
    $html .= '<p>* Ödeme Koşulu: ' . htmlspecialchars($teklif['Teklifler_odeme_kosulu']) . '</p>';
}
// Teslimat süresi varsa ekle
if (!empty($teklif['Teklifler_teslimat_suresi'])) {
    $html .= '<p>* Teslimat Süresi: ' . htmlspecialchars($teklif['Teklifler_teslimat_suresi']) . '</p>';
}
// Teslimat yeri varsa ekle
if (!empty($teklif['Teklifler_teslimat_yeri'])) {
    $html .= '<p>* Teslimat Yeri: ' . htmlspecialchars($teklif['Teklifler_teslimat_yeri']) . '</p>';
}

$html .= '</div>';



$html .= '
</body>
</html>';

// DOMPDF ile PDF oluştur
$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->setChroot(__DIR__ . '/../../');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Dosya adı
$dosyaAdi = ($teklif['Teklifler_no'] ?? 'teklif') . '.pdf';
$dosyaAdi = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $dosyaAdi);

// İç çağrı (gönderim amaçlı) — binary döndür, tarayıcıya stream etme
if (!empty($GLOBALS['teklif_pdf_binary_mod'])) {
    $GLOBALS['teklif_pdf_dosya_adi'] = $dosyaAdi;
    $GLOBALS['teklif_pdf_output']    = $dompdf->output();
    return;
}

// PDF çıktısı
$dompdf->stream($dosyaAdi, ['Attachment' => true]);
exit;
