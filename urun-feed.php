<?php
/**
 * Ürün XML Feed
 * Urun_Hizmet tablosundaki ürünleri XML olarak yayınlar.
 * URL: /urun-feed.php
 *      /urun-feed.php?durum=1          → sadece aktif ürünler
 *      /urun-feed.php?kategori_id=5    → kategoriye göre filtre
 *      /urun-feed.php?marka_id=3       → markaya göre filtre
 *      /urun-feed.php?tip=1            → 1=Ürün, 2=Hizmet
 *      /urun-feed.php?stok_miktari=1   → sadece stok miktarı 0'dan büyük olanlar
 *      /urun-feed.php?satis_fiyati=1   → sadece satış fiyatı dolu olanlar (null veya 0 hariç)
 */

// DB sınıfını yükle
require_once __DIR__ . '/admin/db.php';

// Filtreler (opsiyonel)
$durum      = isset($_GET['durum'])      && $_GET['durum'] !== ''      ? (int)$_GET['durum']      : null;
$kategoriId = isset($_GET['kategori_id']) && $_GET['kategori_id'] !== '' ? (int)$_GET['kategori_id'] : null;
$markaId    = isset($_GET['marka_id'])   && $_GET['marka_id'] !== ''   ? (int)$_GET['marka_id']   : null;
$tip          = isset($_GET['tip'])          && $_GET['tip'] !== ''          ? (int)$_GET['tip']          : null;
$stokMiktari  = isset($_GET['stok_miktari']) && $_GET['stok_miktari'] !== '' ? (int)$_GET['stok_miktari'] : null;
$satisFiyati  = isset($_GET['satis_fiyati']) && $_GET['satis_fiyati'] !== '' ? (int)$_GET['satis_fiyati'] : null;

$db = Database::getInstance();

// WHERE koşulları
$whereConditions = ["1=1"];
$params          = [];

if ($durum !== null) {
    $whereConditions[] = "uh.urun_hizmet_durum = ?";
    $params[] = $durum;
}
if ($kategoriId !== null) {
    $whereConditions[] = "uh.urun_hizmet_kategori_id = ?";
    $params[] = $kategoriId;
}
if ($markaId !== null) {
    $whereConditions[] = "uh.urun_hizmet_marka_id = ?";
    $params[] = $markaId;
}
if ($tip !== null) {
    $whereConditions[] = "uh.urun_hizmet_tip = ?";
    $params[] = $tip;
}
if ($stokMiktari !== null && $stokMiktari > 0) {
    $whereConditions[] = "ISNULL(uh.Urun_Stok_Miktari, 0) > 0";
}
if ($satisFiyati !== null && $satisFiyati > 0) {
    $whereConditions[] = "ISNULL(uh.urun_hizmet_satis_fiyati, 0) > 0";
}

$whereClause = implode(" AND ", $whereConditions);

$sql = "
    SELECT
        uh.urun_hizmet_kodu,
        uh.urun_hizmet_adi,
        uh.urun_hizmet_model,
        uh.urun_hizmet_aciklama,
        uh.urun_hizmet_birim,
        uh.urun_hizmet_kritik_stok,
        uh.urun_hizmet_barkod,
        uh.urun_hizmet_gorsel_url,
        uh.urun_hizmet_durum,
        uh.urun_hizmet_satis_fiyati,
        uh.Urun_Hizmet_AlisFiyati,
        ISNULL(uh.Urun_Stok_Miktari, 0) AS Urun_Stok_Miktari,
        k.kategori_adi,
        m.marka_adi,
        kdv.kdv_oran,
        pb.ParaBirimi_Kod
    FROM Urun_Hizmet uh
    LEFT JOIN UrunHizmet_Kategoriler k  ON k.kategori_id  = uh.urun_hizmet_kategori_id
    LEFT JOIN UrunHizmet_Markalar m     ON m.marka_id     = uh.urun_hizmet_marka_id
    LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id  = uh.urun_hizmet_kdv_id
    LEFT JOIN tanim_ParaBirimi pb       ON pb.ParaBirimi_id = uh.urun_hizmet_para_birimi_id
    WHERE $whereClause
    ORDER BY uh.urun_hizmet_tip, uh.urun_hizmet_sira_no, uh.urun_hizmet_adi
";

$urunler = $db->fetchAll($sql, $params);

// Görsel için base URL
$baseUrl = 'https://panel.ornekfirma.com/admin/';

// XML çıktısı
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600'); // 1 saatlik cache

$xml = new XMLWriter();
$xml->openMemory();
$xml->setIndent(true);
$xml->setIndentString('    ');
$xml->startDocument('1.0', 'UTF-8');

$xml->startElement('UrunFeed');
$xml->writeAttribute('olusturma_tarihi', date('Y-m-d H:i:s'));
$xml->writeAttribute('toplam_kayit', count($urunler));

foreach ($urunler as $u) {
    $xml->startElement('Urun');

    // Ürün Kodu*
    $xml->writeElement('UrunKodu',     $u['urun_hizmet_kodu'] ?? '');

    // Ürün Adı*
    $xml->writeElement('UrunAdi',      $u['urun_hizmet_adi'] ?? '');

    // Model
    $xml->writeElement('Model',        $u['urun_hizmet_model'] ?? '');

    // Detaylı Açıklama
    $xml->startElement('Aciklama');
    $xml->writeCData($u['urun_hizmet_aciklama'] ?? '');
    $xml->endElement();

    // Kategori*
    $xml->writeElement('Kategori',     $u['kategori_adi'] ?? '');

    // Marka
    $xml->writeElement('Marka',        $u['marka_adi'] ?? '');

    // Stok Birimi
    $xml->writeElement('StokBirimi',   $u['urun_hizmet_birim'] ?? '');

    // Düşük Stok Eşiği
    $xml->writeElement('DusukStokEsigi', $u['urun_hizmet_kritik_stok'] ?? '0');

    // KDV Oranı
    $xml->writeElement('KDVOrani',     $u['kdv_oran'] ?? '');

    // Barkod
    $xml->writeElement('Barkod',       $u['urun_hizmet_barkod'] ?? '');

    // Görsel URL
    $gorselUrl = '';
    if (!empty($u['urun_hizmet_gorsel_url'])) {
        $gorselUrl = $baseUrl . ltrim($u['urun_hizmet_gorsel_url'], '/');
    }
    $xml->writeElement('Gorsel', $gorselUrl);

    // Durum
    $xml->writeElement('Durum',        $u['urun_hizmet_durum'] ? 'Aktif' : 'Pasif');

    // Para Birimi
    $xml->writeElement('ParaBirimi',   $u['ParaBirimi_Kod'] ?? '');

    // Stok Miktarı
    $xml->writeElement('StokMiktari',  $u['Urun_Stok_Miktari'] ?? '0');

    // Maliyet Fiyatı
    $xml->writeElement('MaliyetFiyati', number_format((float)($u['Urun_Hizmet_AlisFiyati'] ?? 0), 2, '.', ''));

    // Satış Fiyatı
    $xml->writeElement('SatisFiyati',  number_format((float)($u['urun_hizmet_satis_fiyati'] ?? 0), 2, '.', ''));

    $xml->endElement(); // </Urun>
}

$xml->endElement(); // </UrunFeed>
$xml->endDocument();

echo $xml->outputMemory();
