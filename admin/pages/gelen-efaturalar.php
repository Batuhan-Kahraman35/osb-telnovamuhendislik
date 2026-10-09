<?php
/**
 * Admin Panel - Gelen e-Faturalar
 * Turkbelge API uzerinden gelen e-faturalarin listelenmesi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/TurkbelgeAPI.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Bu sayfaya erisim yetkiniz bulunmamaktadir.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erisim yetkiniz bulunmamaktadir.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Gelen e-Faturalar';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX Islemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                // Kanal bilgilerini al
                $kanal = $db->fetchOne("
                    SELECT ek.Kanal_Id, ek.Kanal_Parametreler
                    FROM EntegrasyonKanallari ek
                    INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                    WHERE e.Entegrasyon_Kod = 'TURKBELGE' AND ek.Kanal_Id = 1 AND ek.Kanal_Durum = 1
                ");
                $kanalId = (int)($kanal['Kanal_Id'] ?? 0);
                $params  = json_decode($kanal['Kanal_Parametreler'] ?? '{}', true);
                $efaturaWsdl = $params['wsdl_efatura'] ?? null;

                if ($efaturaWsdl && strpos($efaturaWsdl, 'test') !== false) {
                    $efaturaWsdl = str_replace('efaturawstest', 'efaturaws', $efaturaWsdl);
                }
                if (!$efaturaWsdl) {
                    $efaturaWsdl = 'https://efaturaws.turkbelge.com.tr/EFaturaOIB?wsdl';
                }

                // API'den cek (yenile=1 ise veya DB bos ise)
                $yenile = (int)($_POST['yenile'] ?? 0);
                $dbSayisi = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.FaturalarGelen")['s'] ?? 0;

                if ($yenile || $dbSayisi == 0) {
                    // API Login
                    $api = new TurkbelgeAPI($db);
                    $api->login();

                    $reflection = new ReflectionClass($api);
                    $sessionProp = $reflection->getProperty('sessionId');
                    $sessionProp->setAccessible(true);
                    $sessionId = $sessionProp->getValue($api);

                    $efaturaClient = new SoapClient($efaturaWsdl, [
                        'trace'          => 1,
                        'exceptions'     => true,
                        'cache_wsdl'     => WSDL_CACHE_NONE,
                        'stream_context' => stream_context_create(['ssl' => [
                            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true
                        ]])
                    ]);

                    // DB'deki son fatura tarihini al → API START_DATE olarak kullan
                    $sonTarih = $db->fetchOne("
                        SELECT CONVERT(VARCHAR(10), MAX(FaturalarGelen_Tarih), 120) AS son_tarih
                        FROM dbo.FaturalarGelen
                    ")['son_tarih'] ?? null;

                    // Son tarih varsa oradan 15 gün geriden başlat, yoksa 30 gün geriden
                    $startDate = $sonTarih
                        ? date('Y-m-d', strtotime($sonTarih . ' -15 days'))
                        : date('Y-m-d', strtotime('-30 days'));

                    $apiResponse = $efaturaClient->GetInvoice([
                        'REQUEST_HEADER' => [
                            'SESSION_ID'       => $sessionId,
                            'APPLICATION_NAME' => 'ORNEK_PORTAL',
                            'COMPRESSED'       => 'N'
                        ],
                        'INVOICE_SEARCH_KEY' => [
                            'LIMIT'         => 500,
                            'DIRECTION'     => 'IN',
                            'READ_INCLUDED' => true,
                            'START_DATE'    => $startDate
                        ],
                        'HEADER_ONLY' => 'Y'
                    ]);

                    if (isset($apiResponse->INVOICE)) {
                        $invoices = is_array($apiResponse->INVOICE) ? $apiResponse->INVOICE : [$apiResponse->INVOICE];
                        foreach ($invoices as $inv) {
                            $header = $inv->HEADER ?? null;
                            if (!$header) continue;

                            $faturaNo = $inv->ID ?? null;
                            if (!$faturaNo || $faturaNo === '-') continue;

                            $tutar = 0;
                            $paraBirimi = 'TRY';
                            if (isset($header->PAYABLE_AMOUNT)) {
                                if (is_object($header->PAYABLE_AMOUNT)) {
                                    $tutar = (float)$header->PAYABLE_AMOUNT->_;
                                    $paraBirimi = $header->PAYABLE_AMOUNT->currencyID ?? 'TRY';
                                } else {
                                    $tutar = (float)$header->PAYABLE_AMOUNT;
                                }
                            }

                            $issueDate = null;
                            if (!empty($header->ISSUE_DATE)) {
                                $issueDate = substr($header->ISSUE_DATE, 0, 10);
                            }

                            // Zaten kayitli mi kontrol et (unique index koruyor, try/catch ile atla)
                            try {
                                $db->insert('FaturalarGelen', [
                                    'FaturalarGelen_FaturaNo'   => $faturaNo,
                                    'FaturalarGelen_ETTN'       => $inv->UUID ?? null,
                                    'FaturalarGelen_FaturaTipi' => $header->INVOICE_TYPE_CODE ?? null,
                                    'FaturalarGelen_Senaryo'    => $header->PROFILEID ?? null,
                                    'FaturalarGelen_Tarih'      => $issueDate,
                                    'FaturalarGelen_VKN_TCKN'   => $header->SENDER ?? null,
                                    'FaturalarGelen_Unvan'      => mb_substr($header->SUPPLIER ?? '', 0, 255),
                                    'FaturalarGelen_Tutar'      => $tutar,
                                    'FaturalarGelen_ParaBirimi' => $paraBirimi,
                                    'FaturalarGelen_Durum'      => mb_substr($header->STATUS_DESCRIPTION ?? '', 0, 100),
                                    'FaturalarGelen_GIBDurum'   => mb_substr($header->GIB_STATUS_DESCRIPTION ?? '', 0, 100),
                                    'FaturalarGelen_Kanal_id'   => $kanalId > 0 ? $kanalId : null,
                                    'OlusturanKullanici'        => $user['kullanici_id'],
                                    'OlusturmaTarihi'           => date('Y-m-d H:i:s'),
                                    'Durum'                     => 1
                                ]);
                            } catch (Exception $dupEx) {
                                // Zaten kayitli, gec
                            }
                        }
                    }
                }

                $cariTipiFiltre = $_POST['cari_tipi_id'] ?? '3'; // Default olarak 3 (Tedarikçi) gelsin
                
                $where = "f.Durum = 1";
                $params = [];
                
                if ($cariTipiFiltre !== '') {
                    $where .= " AND c.cari_tipi_id = ?";
                    $params[] = $cariTipiFiltre;
                }

                // DB'den listele
                $faturalar = $db->fetchAll("
                    SELECT
                        f.FaturalarGelen_id       AS id,
                        f.FaturalarGelen_FaturaNo AS fatura_no,
                        f.FaturalarGelen_ETTN     AS ettn,
                        CONVERT(VARCHAR(10), f.FaturalarGelen_Tarih, 120) AS fatura_tarihi,
                        f.FaturalarGelen_VKN_TCKN AS vkn_tckn,
                        f.FaturalarGelen_Unvan    AS unvan,
                        f.FaturalarGelen_FaturaTipi AS fatura_tipi,
                        f.FaturalarGelen_Senaryo  AS senaryo,
                        f.FaturalarGelen_Tutar    AS tutar,
                        f.FaturalarGelen_ParaBirimi AS para_birimi,
                        f.FaturalarGelen_Durum    AS durum,
                        f.FaturalarGelen_GIBDurum AS gib_durum,
                        f.FaturalarGelen_StokIslendi AS stok_islendi,
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan as db_cari_unvan,
                        iade.iade_no_listesi,
                        CASE
                            WHEN f.FaturalarGelen_ParaBirimi = 'USD' THEN sh.DolarKur
                            WHEN f.FaturalarGelen_ParaBirimi = 'EUR' THEN sh.EuroKur
                            ELSE NULL
                        END AS kur
                    FROM dbo.FaturalarGelen f
                    LEFT JOIN dbo.Cari c ON f.FaturalarGelen_VKN_TCKN = c.cari_vergi_no
                    OUTER APPLY (
                        SELECT TOP 1 DolarKur, EuroKur
                        FROM dbo.Urun_Stok_Hareketleri
                        WHERE Fatura_id = f.FaturalarGelen_id AND Islem_Tipi = 'GIRIS' AND Durum = 1
                        ORDER BY Stok_Hareket_id DESC
                    ) sh
                    OUTER APPLY (
                        SELECT STUFF((
                            SELECT ', ' + ig.FaturalarGiden_FaturaNo
                            FROM dbo.FaturalarGiden ig
                            WHERE ig.FaturalarGiden_IadeKaynak_id = f.FaturalarGelen_id
                              AND ig.Durum = 1
                            ORDER BY ig.FaturalarGiden_id
                            FOR XML PATH(''), TYPE
                        ).value('.', 'NVARCHAR(MAX)'), 1, 2, '') AS iade_no_listesi
                    ) iade
                    WHERE $where
                    ORDER BY f.FaturalarGelen_Tarih DESC, f.FaturalarGelen_id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $faturalar]);
                break;

            case 'get_products':
                $urunler = $db->fetchAll("SELECT urun_hizmet_id as Urun_id, urun_hizmet_adi as Urun_Adi, urun_hizmet_kodu as Urun_Kodu, ISNULL(Urun_Stok_Miktari, 0) as Stok_Miktari FROM dbo.Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_adi");
                $kdvler = $db->fetchAll("SELECT kdv_id, kdv_adi, kdv_oran FROM UrunHizmet_KDVTanimlari WHERE kdv_durum = 1 ORDER BY kdv_sira_no, kdv_oran DESC");
                echo json_encode(['success' => true, 'data' => $urunler, 'kdvler' => $kdvler]);
                break;

            case 'get_invoice_lines':
                $faturaNo = $_POST['fatura_no'] ?? '';
                if (!$faturaNo) throw new Exception("Fatura numarasi eksik.");
                
                // API'den detay cek
                $kanal = $db->fetchOne("SELECT ek.Kanal_Parametreler FROM EntegrasyonKanallari ek INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id WHERE e.Entegrasyon_Kod = 'TURKBELGE' AND ek.Kanal_Id = 1 AND ek.Kanal_Durum = 1");
                $params = json_decode($kanal['Kanal_Parametreler'] ?? '{}', true);
                $efaturaWsdl = $params['wsdl_efatura'] ?? 'https://efaturaws.turkbelge.com.tr/EFaturaOIB?wsdl';
                if (strpos($efaturaWsdl, 'test') !== false) $efaturaWsdl = str_replace('efaturawstest', 'efaturaws', $efaturaWsdl);

                $api = new TurkbelgeAPI($db);
                $api->login();
                $ref = new ReflectionClass($api);
                $prop = $ref->getProperty('sessionId');
                $prop->setAccessible(true);
                $sessionId = $prop->getValue($api);

                $client = new SoapClient($efaturaWsdl, [
                    'trace' => 1, 'exceptions' => true, 'cache_wsdl' => WSDL_CACHE_NONE,
                    'stream_context' => stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]])
                ]);

                $resp = $client->GetInvoice([
                    'REQUEST_HEADER' => ['SESSION_ID' => $sessionId, 'APPLICATION_NAME' => 'ORNEK_PORTAL', 'COMPRESSED' => 'N'],
                    'INVOICE_SEARCH_KEY' => ['LIMIT' => 1, 'ID' => $faturaNo, 'DIRECTION' => 'IN', 'READ_INCLUDED' => true, 'CONTENT_TYPE' => 'XML'],
                    'HEADER_ONLY' => 'N'
                ]);

                if (!isset($resp->INVOICE->CONTENT)) throw new Exception("Fatura icerigi alinamadi.");
                
                $xmlString = is_object($resp->INVOICE->CONTENT) ? $resp->INVOICE->CONTENT->_ : $resp->INVOICE->CONTENT;
                if (strpos($xmlString, '<?xml') === false && strpos($xmlString, '<Invoice') === false) {
                    $decoded = base64_decode($xmlString);
                    if ($decoded) $xmlString = $decoded;
                }

                $doc = new DOMDocument();
                $doc->loadXML($xmlString);
                $xpath = new DOMXPath($doc);
                $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
                $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

                $lines = $xpath->query('//cac:InvoiceLine');
                $kalemler = [];
                foreach ($lines as $line) {
                    $isim = $xpath->evaluate('string(cac:Item/cbc:Name)', $line);
                    $miktar = $xpath->evaluate('number(cbc:InvoicedQuantity)', $line);
                    if (is_nan($miktar)) $miktar = 0;
                    
                    $birim = $xpath->evaluate('string(cbc:InvoicedQuantity/@unitCode)', $line);
                    
                    $fiyat = $xpath->evaluate('number(cac:Price/cbc:PriceAmount)', $line);
                    if (is_nan($fiyat)) $fiyat = 0;
                    
                    $kdvStr = $xpath->evaluate('string(cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory[cac:TaxScheme/cbc:TaxTypeCode="0015"]/cbc:Percent)', $line);
                    if (trim($kdvStr) === '') {
                        $kdvStr = $xpath->evaluate('string(cac:Item/cac:ClassifiedTaxCategory[cac:TaxScheme/cbc:TaxTypeCode="0015"]/cbc:Percent)', $line);
                    }
                    if (trim($kdvStr) === '') {
                        $kdvStr = $xpath->evaluate('string(cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)', $line);
                    }
                    if (trim($kdvStr) === '') {
                        $kdvStr = $xpath->evaluate('string(cac:Item/cac:ClassifiedTaxCategory/cbc:Percent)', $line);
                    }
                    // En son çare: Line içindeki ilk Percent etiketi
                    if (trim($kdvStr) === '') {
                        $percents = $xpath->query('.//cbc:Percent', $line);
                        if ($percents->length > 0) {
                            $kdvStr = $percents->item(0)->nodeValue;
                        }
                    }
                    
                    $kdvStr = str_replace(',', '.', trim($kdvStr));
                    $kdv = is_numeric($kdvStr) ? floatval($kdvStr) : null;
                    
                    // Mal Hizmet Kodu (SellersItemIdentification veya BuyersItemIdentification)
                    $kod = $xpath->evaluate('string(cac:Item/cac:SellersItemIdentification/cbc:ID)', $line);
                    if (!$kod) {
                        $kod = $xpath->evaluate('string(cac:Item/cac:BuyersItemIdentification/cbc:ID)', $line);
                    }
                    
                    $kalemler[] = [
                        'isim' => $isim,
                        'kod' => $kod,
                        'miktar' => $miktar,
                        'birim' => $birim,
                        'fiyat' => $fiyat,
                        'kdv' => $kdv
                    ];
                }

                // İrsaliye No (DespatchDocumentReference)
                $irsaliyeNodes = $xpath->query('//cac:DespatchDocumentReference/cbc:ID');
                $irsaliyeNolar = [];
                foreach ($irsaliyeNodes as $n) {
                    $v = trim($n->nodeValue);
                    if ($v) $irsaliyeNolar[] = $v;
                }
                $irsaliyeNo = $irsaliyeNolar ? implode(', ', array_unique($irsaliyeNolar)) : null;

                $notes = $xpath->query('//cbc:Note');
                $kurUsd = null;
                $kurEur = null;
                foreach ($notes as $note) {
                    $noteText = $note->nodeValue;
                    if (preg_match('/USD DÖVİZ KUR\s*:?\s*([0-9,.]+)/ui', $noteText, $m)) {
                        $kurUsd = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
                    }
                    if (preg_match('/EUR DÖVİZ KUR\s*:?\s*([0-9,.]+)/ui', $noteText, $m)) {
                        $kurEur = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
                    }
                }

                // Fatura ID + kullanıcı bilgilerini al
                $faturaRow = $db->fetchOne("
                    SELECT
                        f.FaturalarGelen_id,
                        CONVERT(VARCHAR(16), f.OlusturmaTarihi, 120) AS olusturma_tarihi,
                        CONVERT(VARCHAR(16), f.GuncellemeTarihi, 120) AS guncelleme_tarihi,
                        ISNULL(k1.kullanici_ad + ' ' + k1.kullanici_soyad, '-') AS olusturan_adi,
                        ISNULL(k2.kullanici_ad + ' ' + k2.kullanici_soyad, '-') AS guncelleyen_adi
                    FROM FaturalarGelen f
                    LEFT JOIN kullanicilar k1 ON f.OlusturanKullanici = k1.kullanici_id
                    LEFT JOIN kullanicilar k2 ON f.GuncelleyenKullanici = k2.kullanici_id
                    WHERE f.FaturalarGelen_FaturaNo = ?
                ", [$faturaNo]);
                $dbFaturaId = $faturaRow ? intval($faturaRow['FaturalarGelen_id']) : 0;

                // Mevcut eşleştirmeleri sıraya göre sakla (her sıra için çoklu kayıt desteklenir)
                $mevcutEslesmeler = []; // [sira => [{urun_hizmet_id, miktar}, ...]]
                if ($dbFaturaId > 0) {
                    $mevcutKalem = $db->fetchOne("SELECT COUNT(*) as sayi FROM FaturalarGelenKalemleri WHERE FaturalarGelenKalemleri_Fatura_id = ?", [$dbFaturaId]);
                    if (intval($mevcutKalem['sayi'] ?? 0) > 0) {
                        $mevcutRows = $db->fetchAll(
                            "SELECT FaturalarGelenKalemleri_Sira, FaturalarGelenKalemleri_UrunHizmet_id, FaturalarGelenKalemleri_Miktar
                             FROM FaturalarGelenKalemleri
                             WHERE FaturalarGelenKalemleri_Fatura_id = ?
                             ORDER BY FaturalarGelenKalemleri_id",
                            [$dbFaturaId]
                        );
                        foreach ($mevcutRows as $mr) {
                            $sira = (int)$mr['FaturalarGelenKalemleri_Sira'];
                            if ($mr['FaturalarGelenKalemleri_UrunHizmet_id']) {
                                $mevcutEslesmeler[$sira][] = [
                                    'urun_hizmet_id' => $mr['FaturalarGelenKalemleri_UrunHizmet_id'],
                                    'miktar'         => $mr['FaturalarGelenKalemleri_Miktar']
                                ];
                            }
                        }
                        $db->query("DELETE FROM FaturalarGelenKalemleri WHERE FaturalarGelenKalemleri_Fatura_id = ?", [$dbFaturaId]);
                    }

                    if (!empty($kalemler)) {
                        foreach ($kalemler as $idx => $kl) {
                            $siraNo = $idx + 1;
                            $netTutar = floatval($kl['miktar']) * floatval($kl['fiyat']);
                            $kdvOran = floatval($kl['kdv'] ?? 0);
                            $kdvTutar = $netTutar * ($kdvOran / 100);
                            $gToplam = $netTutar + $kdvTutar;
                            $eslesmelerForSira = $mevcutEslesmeler[$siraNo] ?? [];

                            if (!empty($eslesmelerForSira)) {
                                // Her split için ayrı satır ekle (aynı sıra, farklı ürün)
                                foreach ($eslesmelerForSira as $esRow) {
                                    $db->insert('FaturalarGelenKalemleri', [
                                        'FaturalarGelenKalemleri_Fatura_id'    => $dbFaturaId,
                                        'FaturalarGelenKalemleri_Sira'          => $siraNo,
                                        'FaturalarGelenKalemleri_StokKodu'      => $kl['kod'] ?? null,
                                        'FaturalarGelenKalemleri_UrunAdi'       => $kl['isim'] ?? '',
                                        'FaturalarGelenKalemleri_UrunHizmet_id' => $esRow['urun_hizmet_id'],
                                        'FaturalarGelenKalemleri_Miktar'        => floatval($esRow['miktar']),
                                        'FaturalarGelenKalemleri_Birim'         => $kl['birim'] ?? 'ADET',
                                        'FaturalarGelenKalemleri_BirimFiyat'    => floatval($kl['fiyat']),
                                        'FaturalarGelenKalemleri_KDVOrani'      => $kdvOran,
                                        'FaturalarGelenKalemleri_KDVTutar'      => $kdvTutar,
                                        'FaturalarGelenKalemleri_NetTutar'      => $netTutar,
                                        'FaturalarGelenKalemleri_GToplamTutar'  => $gToplam,
                                        'OlusturanKullanici'                    => $user['kullanici_id'],
                                        'OlusturmaTarihi'                       => date('Y-m-d H:i:s'),
                                        'Durum'                                 => 1
                                    ]);
                                }
                            } else {
                                // Eşleştirme yok — base satır
                                $db->insert('FaturalarGelenKalemleri', [
                                    'FaturalarGelenKalemleri_Fatura_id'    => $dbFaturaId,
                                    'FaturalarGelenKalemleri_Sira'          => $siraNo,
                                    'FaturalarGelenKalemleri_StokKodu'      => $kl['kod'] ?? null,
                                    'FaturalarGelenKalemleri_UrunAdi'       => $kl['isim'] ?? '',
                                    'FaturalarGelenKalemleri_UrunHizmet_id' => null,
                                    'FaturalarGelenKalemleri_Miktar'        => floatval($kl['miktar']),
                                    'FaturalarGelenKalemleri_Birim'         => $kl['birim'] ?? 'ADET',
                                    'FaturalarGelenKalemleri_BirimFiyat'    => floatval($kl['fiyat']),
                                    'FaturalarGelenKalemleri_KDVOrani'      => $kdvOran,
                                    'FaturalarGelenKalemleri_KDVTutar'      => $kdvTutar,
                                    'FaturalarGelenKalemleri_NetTutar'      => $netTutar,
                                    'FaturalarGelenKalemleri_GToplamTutar'  => $gToplam,
                                    'OlusturanKullanici'                    => $user['kullanici_id'],
                                    'OlusturmaTarihi'                       => date('Y-m-d H:i:s'),
                                    'Durum'                                 => 1
                                ]);
                            }

                            $kalemler[$idx]['eslestirmeler'] = $eslesmelerForSira;
                        }
                    }
                }

                echo json_encode([
                    'success'           => true,
                    'data'              => array_values($kalemler),
                    'kur_usd'           => $kurUsd,
                    'kur_eur'           => $kurEur,
                    'irsaliye_no'       => $irsaliyeNo,
                    'olusturan'         => $faturaRow['olusturan_adi'] ?? null,
                    'olusturma_tarihi'  => $faturaRow['olusturma_tarihi'] ?? null,
                    'guncelleyen'       => $faturaRow['guncelleyen_adi'] ?? null,
                    'guncelleme_tarihi' => $faturaRow['guncelleme_tarihi'] ?? null,
                ]);
                break;

            case 'stok_girisi_kaydet':
                $faturaNo = $_POST['fatura_no'] ?? '';
                $faturaId = (int)($_POST['fatura_id'] ?? 0);
                $dolarKur = (float)($_POST['dolar_kur'] ?? 0);
                $euroKur = (float)($_POST['euro_kur'] ?? 0);
                $eslesmeler = $_POST['eslesmeler'] ?? []; // [{urun_id, miktar, fiyat}]

                if (!$faturaNo || !$faturaId || empty($eslesmeler)) throw new Exception("Gecersiz veri.");

                // Fatura islendi mi kontrolu
                $check = $db->fetchOne("SELECT FaturalarGelen_StokIslendi FROM dbo.FaturalarGelen WHERE FaturalarGelen_id = ?", [$faturaId]);
                
                // Eger onceden islenmisse, eski stok hareketlerini bulup geri al
                $eskiHareketler = $db->fetchAll("SELECT Urun_id, Miktar FROM Urun_Stok_Hareketleri WHERE Fatura_id = ? AND Islem_Tipi = 'GIRIS'", [$faturaId]);
                if (!empty($eskiHareketler)) {
                    foreach ($eskiHareketler as $eh) {
                        $eskiUrunId = (int)$eh['Urun_id'];
                        $eskiMiktar = (float)$eh['Miktar'];
                        sqlsrv_query($db->getConnection(), "UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) - $eskiMiktar WHERE urun_hizmet_id = $eskiUrunId");
                    }
                    $db->query("DELETE FROM Urun_Stok_Hareketleri WHERE Fatura_id = ? AND Islem_Tipi = 'GIRIS'", [$faturaId]);
                }
                // Tekrar kaydedilmesine izin veriliyor

                // Stok hareketleri
                foreach ($eslesmeler as $es) {
                    $urunId = (int)$es['urun_id'];
                    $miktar = (float)$es['miktar'];
                    $fiyat  = (float)$es['fiyat'];
                    if ($urunId <= 0 || $miktar <= 0) continue;

                    $db->insert('Urun_Stok_Hareketleri', [
                        'Urun_id'              => $urunId,
                        'Islem_Tipi'           => 'GIRIS',
                        'Miktar'               => $miktar,
                        'Birim_Fiyati'         => $fiyat,
                        'DolarKur'             => $dolarKur > 0 ? $dolarKur : null,
                        'EuroKur'              => $euroKur > 0 ? $euroKur : null,
                        'Fatura_id'            => $faturaId,
                        'Fatura_No'            => $faturaNo,
                        'Aciklama'             => 'E-Fatura Alis: ' . $faturaNo,
                        'OlusturanKullanici'   => $user['kullanici_id'],
                        'OlusturmaTarihi'      => date('Y-m-d H:i:s'),
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                        'Durum'                => 1
                    ]);
                    sqlsrv_query($db->getConnection(), "UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) + $miktar WHERE urun_hizmet_id = $urunId");
                }

                // FaturalarGelenKalemleri: sıra başına DELETE + INSERT (çoklu split destekli)
                $eslesmePerSira = [];
                foreach ($eslesmeler as $es) {
                    $sira = (int)($es['sira'] ?? 0);
                    if ($sira > 0 && (int)$es['urun_id'] > 0 && (float)$es['miktar'] > 0) {
                        $eslesmePerSira[$sira][] = $es;
                    }
                }

                foreach ($eslesmePerSira as $sira => $siraEslesmeler) {
                    // Temel kalem bilgilerini al (UrunAdi, StokKodu, Birim)
                    $mevcutBase = $db->fetchOne(
                        "SELECT TOP 1 FaturalarGelenKalemleri_UrunAdi, FaturalarGelenKalemleri_StokKodu, FaturalarGelenKalemleri_Birim
                         FROM FaturalarGelenKalemleri
                         WHERE FaturalarGelenKalemleri_Fatura_id = ? AND FaturalarGelenKalemleri_Sira = ?",
                        [$faturaId, $sira]
                    );

                    $db->query(
                        "DELETE FROM FaturalarGelenKalemleri WHERE FaturalarGelenKalemleri_Fatura_id = ? AND FaturalarGelenKalemleri_Sira = ?",
                        [$faturaId, $sira]
                    );

                    $firstEs  = $siraEslesmeler[0];
                    $fiyat    = (float)$firstEs['fiyat'];
                    $kdvOran  = isset($firstEs['kdv']) && is_numeric($firstEs['kdv']) ? (float)$firstEs['kdv'] : 0;

                    foreach ($siraEslesmeler as $es) {
                        $urunId  = (int)$es['urun_id'];
                        $miktar  = (float)$es['miktar'];
                        $netTutar = $miktar * $fiyat;
                        $kdvTutar = $netTutar * ($kdvOran / 100);
                        $gToplam  = $netTutar + $kdvTutar;

                        $db->insert('FaturalarGelenKalemleri', [
                            'FaturalarGelenKalemleri_Fatura_id'    => $faturaId,
                            'FaturalarGelenKalemleri_Sira'          => $sira,
                            'FaturalarGelenKalemleri_StokKodu'      => $mevcutBase['FaturalarGelenKalemleri_StokKodu'] ?? null,
                            'FaturalarGelenKalemleri_UrunAdi'       => $mevcutBase['FaturalarGelenKalemleri_UrunAdi'] ?? '',
                            'FaturalarGelenKalemleri_UrunHizmet_id' => $urunId,
                            'FaturalarGelenKalemleri_Miktar'        => $miktar,
                            'FaturalarGelenKalemleri_Birim'         => $mevcutBase['FaturalarGelenKalemleri_Birim'] ?? 'ADET',
                            'FaturalarGelenKalemleri_BirimFiyat'    => $fiyat,
                            'FaturalarGelenKalemleri_KDVOrani'      => $kdvOran,
                            'FaturalarGelenKalemleri_KDVTutar'      => $kdvTutar,
                            'FaturalarGelenKalemleri_NetTutar'      => $netTutar,
                            'FaturalarGelenKalemleri_GToplamTutar'  => $gToplam,
                            'OlusturanKullanici'                    => $user['kullanici_id'],
                            'OlusturmaTarihi'                       => date('Y-m-d H:i:s'),
                            'GuncelleyenKullanici'                  => $user['kullanici_id'],
                            'GuncellemeTarihi'                      => date('Y-m-d H:i:s'),
                            'Durum'                                 => 1
                        ]);

                        if ($kdvOran > 0) {
                            $kdvRecord = $db->fetchOne("SELECT TOP 1 kdv_id FROM UrunHizmet_KDVTanimlari WHERE kdv_oran = ?", [$kdvOran]);
                            if ($kdvRecord) {
                                $kdvId = (int)$kdvRecord['kdv_id'];
                                sqlsrv_query($db->getConnection(), "UPDATE dbo.Urun_Hizmet SET urun_hizmet_kdv_id = $kdvId WHERE urun_hizmet_id = $urunId AND (urun_hizmet_kdv_id IS NULL OR urun_hizmet_kdv_id != $kdvId)");
                            }
                        }
                    }
                }

                // Faturayi islendi isaretle; boylece cari bakiyesine dahil olur.
                // Iade ayrimi Urun_Stok_Hareketleri uzerinden yapilir, ayri kolon tutulmaz.
                $db->update('FaturalarGelen', [
                    'FaturalarGelen_StokIslendi' => 1
                ], ['FaturalarGelen_id' => $faturaId]);

                echo json_encode(['success' => true, 'message' => 'Stok girisleri basariyla tamamlandi.']);
                break;

            case 'stok_iade':
                $faturaId = intval($_POST['fatura_id'] ?? 0);
                if (!$faturaId) throw new Exception('Fatura ID gerekli.');

                $fat = $db->fetchOne(
                    "SELECT FaturalarGelen_FaturaNo, FaturalarGelen_StokIslendi FROM FaturalarGelen WHERE FaturalarGelen_id = ? AND Durum = 1",
                    [$faturaId]
                );
                if (!$fat) throw new Exception('Fatura bulunamadı.');
                if (!$fat['FaturalarGelen_StokIslendi']) throw new Exception('Bu fatura için stok hareketi kaydı bulunamadı.');

                $hareketler = $db->fetchAll(
                    "SELECT Urun_id, Miktar FROM Urun_Stok_Hareketleri WHERE Fatura_id = ? AND Islem_Tipi = 'GIRIS' AND Durum = 1",
                    [$faturaId]
                );
                if (empty($hareketler)) throw new Exception('Bu faturaya ait stok hareketi bulunamadı.');

                $faturaNo = $fat['FaturalarGelen_FaturaNo'];
                foreach ($hareketler as $h) {
                    $urunId = (int)$h['Urun_id'];
                    $miktar = (float)$h['Miktar'];

                    $db->insert('Urun_Stok_Hareketleri', [
                        'Urun_id'              => $urunId,
                        'Islem_Tipi'           => 'CIKIS',
                        'Miktar'               => $miktar,
                        'Fatura_id'            => $faturaId,
                        'Fatura_No'            => $faturaNo,
                        'Aciklama'             => 'E-Fatura Alış İptal: ' . $faturaNo,
                        'OlusturanKullanici'   => $user['kullanici_id'],
                        'OlusturmaTarihi'      => date('Y-m-d H:i:s'),
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                        'Durum'                => 1
                    ]);

                    $db->query(
                        "UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) - ? WHERE urun_hizmet_id = ?",
                        [$miktar, $urunId]
                    );
                }

                // StokIslendi = 0: fatura odeme-yonetimi/odeme-form bakiyesinden dusulur.
                // Iade durumu Urun_Stok_Hareketleri'ndeki ters yonlu (CIKIS) hareketten anlasilir.
                $db->update('FaturalarGelen', [
                    'FaturalarGelen_StokIslendi' => 0
                ], ['FaturalarGelen_id' => $faturaId]);

                echo json_encode(['success' => true, 'message' => $faturaNo . ' faturasının stok girişleri başarıyla iade edildi ve cari bakiyesinden düşüldü.']);
                break;

            case 'get_cari_tipleri':
                $sql = "SELECT cari_tipi_id, cari_tipi_adi FROM Cari_CariTipleri WHERE Durum = 1 ORDER BY cari_tipi_id";
                $tipler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $tipler]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem.']);
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
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        #faturaTable th.col-durum,
        #faturaTable td.col-durum {
            width: 90px !important;
            min-width: 90px !important;
            max-width: 90px !important;
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        #faturaTable td.col-durum .badge {
            white-space: normal;
            text-align: left;
        }
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

                <!-- InfoBox -->
                <div class="row mb-3" id="infoBoxRow">
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-inbox"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Fatura</span>
                                <span class="info-box-number" id="stat_toplam">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Yanit Bekleyen</span>
                                <span class="info-box-number" id="stat_bekleyen">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">GIB Basarili</span>
                                <span class="info-box-number" id="stat_basarili">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-currency-lira"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Tutar</span>
                                <span class="info-box-number" id="stat_tutar">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <span><i class="bi bi-funnel me-1"></i>Filtrele</span>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div class="collapse" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-2">
                                    <label class="form-label">Fatura No</label>
                                    <input type="text" id="f_fatura_no" class="form-control" placeholder="Fatura No ara...">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">VKN / TCKN</label>
                                    <input type="text" id="f_vkn" class="form-control" placeholder="VKN/TCKN...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Ünvan</label>
                                    <input type="text" id="f_unvan" class="form-control" placeholder="Ünvan ara...">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">Fatura Tipi</label>
                                    <input type="text" id="f_tip" class="form-control" placeholder="Tip...">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="checkbox" id="f_sadece_tedarikci" checked>
                                        <label class="form-check-label fw-semibold" for="f_sadece_tedarikci">
                                            <i class="bi bi-building me-1"></i>Sadece Tedarikçi
                                        </label>
                                        <div><small class="text-muted">Cari Tipi = Tedarikçi</small></div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih Başlangıç</label>
                                    <input type="date" id="f_tarih_bas" class="form-control">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">Tarih Bitiş</label>
                                    <input type="date" id="f_tarih_bit" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select id="f_durum" class="form-select" style="width:100%">
                                        <option value="">Tümü</option>
                                        <option value="CANCELLED">İptal</option>
                                        <option value="SUCCEED">Başarılı</option>
                                        <option value="WAIT">Bekliyor</option>
                                        <option value="FAILED">Hatalı</option>
                                    </select>
                                </div>
                                <div class="col-md-1 d-flex align-items-end gap-1">
                                    <button id="btnFiltrele" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
                                    <button id="btnFiltreTemizle" class="btn btn-outline-secondary" title="Temizle"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fatura Tablosu -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="bi bi-inbox me-1"></i>Gelen e-Faturalar</h3>
                        <div class="d-flex gap-2">
                            <button class="btn btn-success btn-sm" id="btnExcelIndir">
                                <i class="bi bi-file-earmark-excel me-1"></i>Excel İndir
                            </button>
                            <button class="btn btn-primary btn-sm" id="btnFaturaCek">
                                <i class="bi bi-arrow-clockwise me-1"></i>Faturalari Cek
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="loadingOverlay" class="text-center py-4" style="display:none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Turkbelge API'den faturalar cekiliyor...</p>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered align-middle" id="faturaTable" style="width:100%">
                                <thead>
                                <tr>
                                    <th>Fatura No</th>
                                    <th>Tarih</th>
                                    <th>VKN/TCKN</th>
                                    <th>Ünvan</th>
                                    <th>Tip</th>
                                    <th>Senaryo</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="col-durum">Durum</th>
                                    <th>GİB</th>
                                    <th class="text-end">İşlemler</th>
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

    <!-- Stok Eşleştirme Modal -->
    <div class="modal fade" id="stokEslestirmeModal" tabindex="-1" aria-labelledby="stokModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="stokModalLabel"><i class="bi bi-box-seam me-2"></i>Fatura Stok Girişi / Eşleştirme</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 mb-3">
                        <div class="d-flex flex-wrap gap-3 align-items-center">
                            <span>Fatura No: <strong id="modalFaturaNo">-</strong></span>
                            <span id="modalIrsaliyeNoWrapper" style="display:none">
                                <i class="bi bi-truck me-1"></i>İrsaliye No: <strong id="modalIrsaliyeNo"></strong>
                            </span>
                            <input type="hidden" id="modalFaturaId" value="">
                        </div>
                        <div class="mt-1" id="modalKullaniciBilgi" style="display:none">
                            <small class="text-muted">
                                <i class="bi bi-person-plus me-1"></i><span id="modalOlusturan"></span>
                                <span id="modalGuncelleyenWrapper" class="ms-3">
                                    <i class="bi bi-pencil me-1"></i><span id="modalGuncelleyen"></span>
                                </span>
                            </small>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-1">
                                <i class="bi bi-currency-dollar text-success me-1"></i>USD Kur
                                <small id="dolarKurKaynak" class="text-muted fw-normal ms-1"></small>
                            </label>
                            <input type="text" inputmode="decimal" id="modalDolarKur"
                                class="form-control kur-decimal-input"
                                placeholder="Faturada kur bilgisi yok, manuel giriniz"
                                autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-1">
                                <i class="bi bi-currency-euro text-primary me-1"></i>EUR Kur
                                <small id="euroKurKaynak" class="text-muted fw-normal ms-1"></small>
                            </label>
                            <input type="text" inputmode="decimal" id="modalEuroKur"
                                class="form-control kur-decimal-input"
                                placeholder="Faturada kur bilgisi yok, manuel giriniz"
                                autocomplete="off">
                        </div>
                    </div>

                    <div id="modalLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Fatura detayları ve ürünler çekiliyor...</p>
                    </div>

                    <div id="modalContent" style="display:none;">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="30%">Faturadaki Kalem Adı</th>
                                    <th width="15%" class="text-center">Miktar / Birim</th>
                                    <th width="15%" class="text-end">Birim Fiyat</th>
                                    <th width="10%" class="text-center">KDV</th>
                                    <th width="30%">Sistemdeki Ürün (Eşleştir)</th>
                                </tr>
                            </thead>
                            <tbody id="stokKalemleriBody">
                                <!-- JS ile doldurulacak -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-success" id="btnStokKaydet" style="display:none;">
                        <i class="bi bi-check-circle me-1"></i>Stoklara İşle ve Kaydet
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
let table;

function formatTutar(tutar, paraBirimi) {
    const formatted = parseFloat(tutar).toLocaleString('tr-TR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    return formatted + ' ' + (paraBirimi || 'TRY');
}

function excelIndir(rows) {
    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    html += '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head>';
    html += '<body><table border="1"><thead>';
    html += '<tr style="background-color:#0d6efd;color:#fff;font-weight:bold;">';
    ['Fatura No','Tarih','VKN/TCKN','Ünvan','Fatura Tipi','Senaryo','Tutar','Para Birimi','Kur','Durum','GİB Durum']
        .forEach(h => html += '<th>' + h + '</th>');
    html += '</tr></thead><tbody>';
    rows.forEach(r => {
        const tarih = (r.fatura_tarihi || '').split('-');
        const tarihStr = tarih.length === 3 ? tarih[2]+'.'+tarih[1]+'.'+tarih[0] : (r.fatura_tarihi || '');
        html += '<tr>';
        html += '<td>' + esc(r.fatura_no) + '</td>';
        html += '<td>' + esc(tarihStr) + '</td>';
        html += '<td>' + esc(r.vkn_tckn) + '</td>';
        html += '<td>' + esc(r.unvan) + '</td>';
        html += '<td>' + esc(r.fatura_tipi) + '</td>';
        html += '<td>' + esc(r.senaryo) + '</td>';
        html += '<td style="text-align:right">' + parseFloat(r.tutar || 0).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
        html += '<td>' + esc(r.para_birimi || 'TRY') + '</td>';
        const kurStr = (r.para_birimi === 'USD' || r.para_birimi === 'EUR') && r.kur
            ? parseFloat(r.kur).toLocaleString('tr-TR', {minimumFractionDigits:4, maximumFractionDigits:4}) : '';
        html += '<td style="text-align:right">' + kurStr + '</td>';
        html += '<td>' + esc(r.durum) + '</td>';
        html += '<td>' + esc(r.gib_durum) + '</td>';
        html += '</tr>';
    });
    html += '</tbody></table></body></html>';

    const blob = new Blob(['﻿' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const ts = new Date().toISOString().slice(0,19).replace(/[T:]/g,'-');
    a.href = url;
    a.download = 'gelen_efaturalar_' + ts + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function badgeFaturaTipi(tip) {
    const renkler = {
        'SATIS': 'primary',
        'IADE': 'warning',
        'TEVKIFAT': 'info',
        'ISTISNA': 'secondary',
        'OZELMATRAH': 'dark',
        'IHRACKAYITLI': 'success'
    };
    const renk = renkler[tip] || 'secondary';
    return '<span class="badge text-bg-' + renk + '">' + (tip || '-') + '</span>';
}

// İade faturası için cari kartı zorunlu — eşleşen cari yoksa kullanıcıyı yönlendir
function iadeCariUyari(vkn, islem) {
    Swal.fire({
        icon: 'warning',
        title: 'Cari Kartı Bulunamadı',
        html: (islem || 'İade faturası') + ' kesebilmek için <strong>' + (vkn || 'bu VKN') + '</strong> numaralı '
            + 'firmanın sistemde cari kartı olmalıdır.',
        showCancelButton: true,
        confirmButtonText: 'Cari Kartı Aç',
        cancelButtonText: 'Kapat'
    }).then(function(r) {
        if (r.isConfirmed) window.open('/Admin/cari-form', '_blank');
    });
}

function htmlEsc(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function badgeDurum(durum) {
    if (!durum) return '-';
    
    let renk = 'secondary';
    let metin = durum;

    switch (durum) {
        case 'SUCCEED':
            renk = 'success'; metin = 'Başarıyla İletildi'; break;
        case 'ACCEPTED':
        case 'KABUL':
            renk = 'success'; metin = 'Kabul Edildi'; break;
        case 'REJECTED':
        case 'RED':
            renk = 'danger'; metin = 'Reddedildi'; break;
        case 'FAILED':
            renk = 'danger'; metin = 'Hatalı / İletilemedi'; break;
        case 'WAIT_APPLICATION_RESPONSE':
            renk = 'warning'; metin = 'Kabul/Red Bekleniyor'; break;
        case 'WAIT_SYSTEM_RESPONSE':
            renk = 'info'; metin = 'Sistem Yanıtı Bekleniyor'; break;
        case 'WAIT_GIB_RESPONSE':
            renk = 'warning text-dark'; metin = 'GİB Yanıtı Bekleniyor'; break;
        case 'PROCESSING':
            renk = 'primary'; metin = 'İşleniyor'; break;
        case 'CANCELLED':
            renk = 'secondary'; metin = 'İptal Edildi'; break;
        default:
            // GİB durum açıklamaları Türkçe metin olarak gelir ("BAŞARIYLA TAMAMLANDI")
            if (durum.indexOf('BASARI') >= 0 || durum.indexOf('BAŞARI') >= 0) { renk = 'success'; metin = 'Başarılı'; }
            else if (durum.indexOf('WAIT') >= 0) { renk = 'warning text-dark'; metin = 'Bekliyor'; }
            else if (durum.indexOf('HATA') >= 0 || durum.indexOf('FAIL') >= 0) { renk = 'danger'; metin = 'Hatalı'; }
    }

    return '<span class="badge bg-' + renk + '" title="Ham Değer: ' + durum + '">' + metin + '</span>';
}

function loadFaturalar(yenile) {
    $('#loadingOverlay').show();
    $('#faturaTable').closest('.table-responsive').hide();

    $.post('', { 
        action: 'list', 
        yenile: yenile ? 1 : 0,
        cari_tipi_id: $('#f_sadece_tedarikci').is(':checked') ? '3' : ''
    }, function(resp) {
        $('#loadingOverlay').hide();
        $('#faturaTable').closest('.table-responsive').show();

        if (!resp.success) {
            if (typeof showToast === 'function') {
                showToast(resp.message || 'Fatura cekme hatasi', 'error');
            } else {
                Swal.fire('Hata', resp.message || 'Fatura cekme hatasi', 'error');
            }
            return;
        }

        table.clear();
        table.rows.add(resp.data).draw();

        // InfoBox guncelle
        const data = resp.data;
        $('#stat_toplam').text(data.length);
        const bekleyen = data.filter(d => (d.durum || '').indexOf('WAIT') >= 0).length;
        $('#stat_bekleyen').text(bekleyen);
        const basarili = data.filter(d => (d.gib_durum || '').indexOf('BASARI') >= 0).length;
        $('#stat_basarili').text(basarili);
        const toplamTutar = data.reduce((sum, d) => sum + parseFloat(d.tutar || 0), 0);
        $('#stat_tutar').text(formatTutar(toplamTutar, 'TRY'));

    }, 'json').fail(function(xhr) {
        $('#loadingOverlay').hide();
        $('#faturaTable').closest('.table-responsive').show();
        Swal.fire('Hata', 'API baglanti hatasi: ' + xhr.statusText, 'error');
    });
}

$(function() {
    table = $('#faturaTable').DataTable({
        data: [],
        order: [[1, 'desc']],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        pageLength: 25,
        columns: [
            {
                data: 'fatura_no',
                render: function(data, type, row) {
                    let no = '<strong>' + data + '</strong>';
                    // Bu faturaya kesilmiş iade faturaları alt satırda listelenir
                    if (type === 'display' && row.iade_no_listesi) {
                        no += '<div class="small text-danger" title="Kesilen iade faturaları">'
                            + '<i class="bi bi-arrow-return-left"></i> ' + row.iade_no_listesi + '</div>';
                    }
                    return no;
                }
            },
            {
                data: 'fatura_tarihi',
                render: function(data, type) {
                    if (!data || data === '-') return '-';
                    // Sort ve filter icin YYYY-MM-DD (dogru siralama)
                    if (type === 'sort' || type === 'type' || type === 'filter') return data;
                    // Gosterim icin DD.MM.YYYY
                    const parts = data.split('-');
                    if (parts.length === 3) return parts[2] + '.' + parts[1] + '.' + parts[0];
                    return data;
                }
            },
            { data: 'vkn_tckn' },
            {
                data: 'unvan',
                className: 'text-nowrap',
                render: function(data, type) {
                    if (!data || data === '-') return '-';
                    if (type !== 'display') return data;   // arama/siralama tam degerde kalsin
                    return '<span title="' + htmlEsc(data) + '">'
                         + htmlEsc(data.length > 20 ? data.substring(0, 20) + '…' : data) + '</span>';
                }
            },
            {
                data: 'fatura_tipi',
                render: function(data) { return badgeFaturaTipi(data); }
            },
            {
                data: 'senaryo',
                render: function(data) {
                    return data || '-';
                }
            },
            {
                data: 'tutar',
                className: 'text-end text-nowrap',
                render: function(data, type, row) {
                    if (type === 'sort' || type === 'type') return parseFloat(data || 0);
                    return '<strong>' + formatTutar(data, row.para_birimi) + '</strong>';
                }
            },
            {
                data: 'durum',
                className: 'col-durum',
                render: function(data) { return badgeDurum(data || '-'); }
            },
            {
                data: 'gib_durum',
                render: function(data) { return badgeDurum(data || '-'); }
            },
            {
                data: null,
                className: 'text-end text-nowrap',
                orderable: false,
                render: function(data, type, row) {
                    let html = `<div class="btn-group btn-group-sm">
                                    <a href="/admin/pages/fatura-goruntule.php?fatura_no=${row.fatura_no}" target="_blank" class="btn btn-primary" title="Faturayı Görüntüle">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>`;
                    
                    if (row.stok_islendi == 1) {
                        html += `<button type="button" class="btn btn-success" onclick="openStokModal('${row.fatura_no}', ${row.id})" title="İşlendi - Tekrar açmak için tıklayın">
                                    <i class="bi bi-check-all"></i> İşlendi
                                 </button>
                                 <button type="button" class="btn btn-danger btn-stok-iade" data-id="${row.id}" data-no="${row.fatura_no}" title="Stok girişini geri al">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                 </button>`;
                    } else {
                        html += `<button type="button" class="btn btn-warning text-dark" onclick="openStokModal('${row.fatura_no}', ${row.id})" title="Stok Eşleştir ve Gir">
                                    <i class="bi bi-box-arrow-in-down"></i> Eşleştir
                                 </button>`;
                    }

                    // İade faturası: bu gelen fatura için IADE tipli giden fatura düzenlenir.
                    // Cari kartı olmayan faturalarda kesilemez (fatura formunda cari zorunlu).
                    if (row.cari_id) {
                        html += `<a href="/Admin/fatura-form?iade_kaynak=${row.id}" class="btn btn-outline-danger" title="Bu fatura için iade faturası kes">
                                    <i class="bi bi-arrow-return-left"></i>
                                 </a>`;
                    } else {
                        html += `<button type="button" class="btn btn-outline-secondary" onclick="iadeCariUyari('${row.vkn_tckn || ''}')" title="İade faturası için önce cari kartı gerekli">
                                    <i class="bi bi-arrow-return-left"></i>
                                 </button>`;
                    }

                    // Fiyat farkı faturası: SATIS tipli giden fatura, tek kalem (Fiyat Farkı) olarak açılır.
                    if (row.cari_id) {
                        html += `<a href="/Admin/fatura-form?fiyat_farki_kaynak=${row.id}" class="btn btn-outline-primary" title="Bu fatura için fiyat farkı faturası kes">
                                    <i class="bi bi-cash-coin"></i>
                                 </a>`;
                    } else {
                        html += `<button type="button" class="btn btn-outline-secondary" onclick="iadeCariUyari('${row.vkn_tckn || ''}', 'Fiyat farkı faturası')" title="Fiyat farkı faturası için önce cari kartı gerekli">
                                    <i class="bi bi-cash-coin"></i>
                                 </button>`;
                    }

                    html += `</div>`;
                    return html;
                }
            }
        ],
    });

    // Sayfa yuklendiginde faturalari cek
    loadFaturalar();

    // Yenile butonu - API'den cek, DB'ye kaydet
    $('#btnFaturaCek').on('click', function() {
        loadFaturalar(1);
    });

    // Excel İndir (DataTables filtrelenmiş veriyi indirir)
    $('#btnExcelIndir').on('click', function() {
        const rows = table.rows({ search: 'applied' }).data().toArray();
        if (!rows.length) {
            Swal.fire('Bilgi', 'İndirilecek kayıt bulunamadı.', 'info');
            return;
        }
        excelIndir(rows);
    });

    // Modal içi kaydet butonu
    $('#btnStokKaydet').on('click', function() {
        kaydetStokGirisi();
    });

    // Kur alanları: sadece DECIMAL(18,4) formatına izin ver (en fazla 14 tam, 4 ondalık hane)
    $(document).on('input', '.kur-decimal-input', function() {
        let val = this.value.replace(',', '.');
        // Sadece rakam ve tek nokta
        val = val.replace(/[^\d.]/g, '');
        const parts = val.split('.');
        if (parts.length > 2) val = parts[0] + '.' + parts.slice(1).join('');
        // Tam kısım max 14 hane
        let intPart = (val.split('.')[0] || '').slice(0, 14);
        let decPart = val.includes('.') ? '.' + (val.split('.')[1] || '').slice(0, 4) : '';
        this.value = intPart + decPart;
    });
    $(document).on('keypress', '.kur-decimal-input', function(e) {
        const allowed = /[\d.,]/;
        if (!allowed.test(e.key) && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab'].includes(e.key)) {
            e.preventDefault();
        }
    });

    // Filtrele butonu
    $('#btnFiltrele').on('click', function() {
        loadFaturalar();
    });

    // Filtre temizle butonu
    $('#btnFiltreTemizle').on('click', function() {
        $('#f_fatura_no, #f_vkn, #f_unvan, #f_tip, #f_tarih_bas, #f_tarih_bit').val('');
        $('#f_durum').val('').trigger('change.select2');
        $('#f_sadece_tedarikci').prop('checked', true); // default: Tedarikçi
        loadFaturalar();
    });

    // Checkbox değiştiğinde otomatik yenile
    $('#f_sadece_tedarikci').on('change', function() {
        loadFaturalar();
    });

    // Select2 Modal Fix
    $.fn.select2.defaults.set("theme", "bootstrap-5");
});

// % karakterini SQL LIKE gibi wildcard olarak kullanan Select2 matcher
function urunWildcardMatcher(params, data) {
    if ($.trim(params.term) === '') return data;
    if (typeof data.text === 'undefined') return null;
    const term = params.term.toLowerCase();
    const text = data.text.toLowerCase();
    if (term.indexOf('%') === -1) {
        return text.indexOf(term) > -1 ? data : null;
    }
    const parts = term.split('%').filter(function(p) { return p !== ''; });
    let pos = 0;
    for (let i = 0; i < parts.length; i++) {
        const idx = text.indexOf(parts[i], pos);
        if (idx === -1) return null;
        pos = idx + parts[i].length;
    }
    return data;
}

// Sistemdeki Urunleri (Dropdown) Global Tutma
let sistemUrunleri = [];
let sistemKdvler = [];

function openStokModal(faturaNo, faturaId) {
    $('#modalFaturaNo').text(faturaNo);
    $('#modalFaturaId').val(faturaId);
    $('#modalDolarKur').val('').prop('readonly', false).removeClass('bg-light');
    $('#modalEuroKur').val('').prop('readonly', false).removeClass('bg-light');
    $('#dolarKurKaynak, #euroKurKaynak').text('');
    $('#modalIrsaliyeNoWrapper').hide();
    $('#modalIrsaliyeNo').text('');
    $('#modalKullaniciBilgi').hide();
    $('#modalOlusturan, #modalGuncelleyen').text('');
    $('#stokKalemleriBody').empty();
    $('#modalContent, #btnStokKaydet').hide();
    $('#modalLoading').show();
    
    var myModal = new bootstrap.Modal(document.getElementById('stokEslestirmeModal'));
    myModal.show();

    // 1. Önce sistemdeki ürünleri çek
    if (sistemUrunleri.length === 0 || sistemKdvler.length === 0) {
        $.post('', { action: 'get_products' }, function(resp) {
            if (resp.success) {
                sistemUrunleri = resp.data;
                sistemKdvler = resp.kdvler || [];
                fetchInvoiceLines(faturaNo);
            } else {
                Swal.fire('Hata', resp.message || 'Ürünler çekilemedi', 'error');
                myModal.hide();
            }
        }, 'json');
    } else {
        fetchInvoiceLines(faturaNo);
    }
}

function fetchInvoiceLines(faturaNo) {
    $.post('', { action: 'get_invoice_lines', fatura_no: faturaNo }, function(resp) {
        $('#modalLoading').hide();
        if (resp.success && resp.data.length > 0) {
            let html = '';
            
            // Kur alanlarini doldur; faturada varsa readonly yap
            $('#modalDolarKur').val(resp.kur_usd || '').prop('readonly', !!resp.kur_usd)
                .toggleClass('bg-light', !!resp.kur_usd);
            $('#modalEuroKur').val(resp.kur_eur || '').prop('readonly', !!resp.kur_eur)
                .toggleClass('bg-light', !!resp.kur_eur);
            $('#dolarKurKaynak').text(resp.kur_usd ? '(faturadan otomatik alındı)' : '');
            $('#euroKurKaynak').text(resp.kur_eur ? '(faturadan otomatik alındı)' : '');

            // İrsaliye No
            if (resp.irsaliye_no) {
                $('#modalIrsaliyeNo').text(resp.irsaliye_no);
                $('#modalIrsaliyeNoWrapper').show();
            }

            // Kullanıcı bilgileri
            if (resp.olusturan || resp.guncelleyen) {
                const tarih = (t) => t ? ' (' + t + ')' : '';
                $('#modalOlusturan').text('Ekleyen: ' + (resp.olusturan || '-') + tarih(resp.olusturma_tarihi));
                $('#modalGuncelleyen').text('Güncelleyen: ' + (resp.guncelleyen || '-') + tarih(resp.guncelleme_tarihi));
                $('#modalKullaniciBilgi').show();
            }

            resp.data.forEach((kalem, index) => {
                const siraNo = index + 1;
                const miktarVal = parseFloat(kalem.miktar) || 0;
                const eslestirmeler = kalem.eslestirmeler || [];

                let eslesenVar = eslestirmeler.length > 0;
                let eslesmeRowsHtml = '';

                if (eslestirmeler.length > 0) {
                    // Kaydedilmiş split satırlarını yükle
                    eslestirmeler.forEach((esl, eslIdx) => {
                        let optHtml = '<option value="">-- Ürün Seçin --</option>';
                        sistemUrunleri.forEach(u => {
                            const sel = (parseInt(esl.urun_hizmet_id) === parseInt(u.Urun_id)) ? 'selected' : '';
                            optHtml += `<option value="${u.Urun_id}" ${sel}>[${u.Urun_Kodu || '-'}] ${u.Urun_Adi} (Stok: ${parseFloat(u.Stok_Miktari)})</option>`;
                        });
                        const showSil = eslestirmeler.length > 1 ? '' : 'display:none';
                        eslesmeRowsHtml += `
                            <div class="eslesme-satir d-flex gap-1 mb-1 align-items-center">
                                <select class="form-select form-select-sm urun-select" style="min-width:0;flex:1">${optHtml}</select>
                                <input type="number" class="form-control form-control-sm eslesme-adet"
                                       value="${parseFloat(esl.miktar) || 0}" min="0" step="1"
                                       style="width:70px;flex-shrink:0" placeholder="Adet" title="Adet">
                                <button type="button" class="btn btn-outline-danger btn-sm btn-eslesme-sil py-0 px-1 flex-shrink-0"
                                        title="Bu satırı sil" style="${showSil}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>`;
                    });
                } else {
                    // Kayıt yok — kod ile otomatik eşleştir veya boş bırak
                    let autoMatchId = null;
                    if (kalem.kod) {
                        const matched = sistemUrunleri.find(u => u.Urun_Kodu && kalem.kod.toString().trim() === u.Urun_Kodu.toString().trim());
                        if (matched) { autoMatchId = matched.Urun_id; eslesenVar = true; }
                    }
                    let optHtml = '<option value="">-- Ürün Seçin --</option>';
                    sistemUrunleri.forEach(u => {
                        const sel = (autoMatchId && parseInt(autoMatchId) === parseInt(u.Urun_id)) ? 'selected' : '';
                        optHtml += `<option value="${u.Urun_id}" ${sel}>[${u.Urun_Kodu || '-'}] ${u.Urun_Adi} (Stok: ${parseFloat(u.Stok_Miktari)})</option>`;
                    });
                    eslesmeRowsHtml = `
                        <div class="eslesme-satir d-flex gap-1 mb-1 align-items-center">
                            <select class="form-select form-select-sm urun-select" style="min-width:0;flex:1">${optHtml}</select>
                            <input type="number" class="form-control form-control-sm eslesme-adet"
                                   value="${miktarVal}" min="0" step="1"
                                   style="width:70px;flex-shrink:0" placeholder="Adet" title="Adet">
                            <button type="button" class="btn btn-outline-danger btn-sm btn-eslesme-sil py-0 px-1 flex-shrink-0"
                                    title="Bu satırı sil" style="display:none">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>`;
                }

                let kalemBaslikHtml = `<strong class="d-block ${eslesenVar ? 'text-success' : 'text-primary'}"><span class="badge bg-secondary me-1">${siraNo}</span>${kalem.isim || 'İsimsiz Kalem'}</strong>`;
                if (kalem.kod) kalemBaslikHtml += `<span class="badge bg-secondary mb-1">Kod: ${kalem.kod}</span> `;
                if (eslesenVar) {
                    let msg = eslestirmeler.length > 1
                        ? `<i class="bi bi-diagram-3"></i> ${eslestirmeler.length} ürüne bölünmüş`
                        : (eslestirmeler.length === 1 ? '<i class="bi bi-bookmark-check"></i> Daha önce eşleştirildi' : '<i class="bi bi-check-circle"></i> Kod ile otomatik eşleşti');
                    kalemBaslikHtml += `<small class="text-success d-block">${msg}</small>`;
                } else {
                    kalemBaslikHtml += `<small class="text-muted d-block">Lütfen uygun ürünle eşleştirin. (Boş bırakılanlar işlenmez)</small>`;
                }

                let kdvOptions = '<option value="">-- KDV --</option>';
                sistemKdvler.forEach(k => {
                    let kSel = (kalem.kdv !== null && parseFloat(kalem.kdv) == parseFloat(k.kdv_oran)) ? 'selected' : '';
                    kdvOptions += `<option value="${k.kdv_oran}" ${kSel}>%${k.kdv_oran}</option>`;
                });

                html += `
                    <tr data-sira="${siraNo}">
                        <td>
                            ${kalemBaslikHtml}
                        </td>
                        <td class="text-center font-monospace">
                            <span class="kalem-miktar" data-miktar="${miktarVal}">${miktarVal.toLocaleString('tr-TR', { maximumFractionDigits: 0 })}</span> <small>${kalem.birim || 'ADET'}</small>
                        </td>
                        <td class="text-end font-monospace">
                            <span class="kalem-fiyat" data-fiyat="${kalem.fiyat}">${parseFloat(kalem.fiyat).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span> <small>TRY</small>
                        </td>
                        <td class="text-center">
                            <select class="form-select form-select-sm kalem-kdv" style="width:100%;" title="XML'den Okunan KDV: ${kalem.kdv !== null ? kalem.kdv : 'Bulunamadı'}">
                                ${kdvOptions}
                            </select>
                        </td>
                        <td class="p-1">
                            <div class="eslesme-listesi">
                                ${eslesmeRowsHtml}
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-eslesme-ekle py-0 px-2"
                                        title="Fatura kalemini birden fazla sisteme kayıtlı ürüne böl">
                                    <i class="bi bi-plus-lg me-1"></i>Ürüne Böl
                                </button>
                                <small class="eslesme-bilgi"></small>
                            </div>
                        </td>
                    </tr>
                `;
            });

            $('#stokKalemleriBody').html(html);
            $('#modalContent, #btnStokKaydet').show();

            // Select2 Init
            $('.urun-select').select2({
                dropdownParent: $('#stokEslestirmeModal'),
                placeholder: 'Ürün Arayın...',
                allowClear: true,
                matcher: urunWildcardMatcher
            });

            // Başlangıç bilgilerini göster
            $('#stokKalemleriBody tr').each(function() { updateEslesmeBilgi($(this)); });

        } else {
            Swal.fire('Hata', resp.message || 'Faturada kalem bulunamadı veya okunamadı.', 'error');
        }
    }, 'json').fail(function() {
        $('#modalLoading').hide();
        Swal.fire('Hata', 'Fatura kalemleri çekilirken ağ hatası oluştu.', 'error');
    });
}

function kaydetStokGirisi() {
    let eslesmeler = [];
    let eksikVar = false;
    let miktarFazlaHata = false;

    $('#stokKalemleriBody tr').each(function() {
        const sira = parseInt($(this).data('sira'));
        const maxMiktar = parseFloat($(this).find('.kalem-miktar').data('miktar') || 0);
        const fiyat = parseFloat($(this).find('.kalem-fiyat').data('fiyat') || 0);
        const kdvData = $(this).find('.kalem-kdv').val();
        const kdv = (kdvData !== '' && kdvData !== undefined && kdvData !== null) ? parseFloat(kdvData) : null;

        let satirEslesmeler = [];
        let satirToplamMiktar = 0;
        let enAzBirUrun = false;

        $(this).find('.eslesme-satir').each(function() {
            const urunId = $(this).find('.urun-select').val();
            const adet = parseFloat($(this).find('.eslesme-adet').val()) || 0;
            if (urunId && adet > 0) {
                satirEslesmeler.push({ sira, urun_id: urunId, miktar: adet, fiyat, kdv });
                satirToplamMiktar += adet;
                enAzBirUrun = true;
            }
        });

        if (!enAzBirUrun) {
            eksikVar = true;
        } else {
            if (satirToplamMiktar > maxMiktar + 0.001) miktarFazlaHata = true;
            eslesmeler = eslesmeler.concat(satirEslesmeler);
        }
    });

    if (miktarFazlaHata) {
        Swal.fire('Hata', 'Bazı kalemlerde toplam eşleştirilen miktar faturadaki miktarı aşıyor! Lütfen kontrol ediniz.', 'error');
        return;
    }

    if (eslesmeler.length === 0) {
        Swal.fire('Uyarı', 'Hiçbir ürünü eşleştirmediniz. Lütfen en az bir ürün eşleştirin.', 'warning');
        return;
    }

    if (eksikVar) {
        Swal.fire({
            title: 'Emin misiniz?',
            text: 'Eşleştirilmeyen (boş bırakılan) kalemler var. Bunlar stoka EKLENMEYECEK. Devam edilsin mi?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet, Sadece Seçilenleri Ekle',
            cancelButtonText: 'İptal'
        }).then((result) => {
            if (result.isConfirmed) {
                gonderStokGirisi(eslesmeler);
            }
        });
    } else {
        gonderStokGirisi(eslesmeler);
    }
}

// Eşleştirme bilgi göstergesi
function updateEslesmeBilgi(tr) {
    const maxMiktar = parseFloat(tr.find('.kalem-miktar').data('miktar') || 0);
    if (maxMiktar <= 0) return;

    let toplam = 0;
    tr.find('.eslesme-adet').each(function() {
        toplam += parseFloat($(this).val()) || 0;
    });

    const bilgi = tr.find('.eslesme-bilgi');
    if (Math.abs(toplam - maxMiktar) < 0.001) {
        bilgi.html(`<span class="text-success fw-semibold"><i class="bi bi-check-circle"></i> ${toplam}/${maxMiktar}</span>`);
    } else if (toplam > maxMiktar + 0.001) {
        bilgi.html(`<span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle"></i> ${toplam}/${maxMiktar} <strong>(Fazla!)</strong></span>`);
    } else if (toplam > 0) {
        bilgi.html(`<span class="text-warning fw-semibold">${toplam}/${maxMiktar} (Kalan: ${(maxMiktar - toplam).toLocaleString('tr-TR')})</span>`);
    } else {
        bilgi.html(`<span class="text-muted">0/${maxMiktar}</span>`);
    }
}

// "Ürüne Böl" — yeni ürün satırı ekle
$(document).on('click', '.btn-eslesme-ekle', function() {
    const td = $(this).closest('td');
    const tr = td.closest('tr');
    const maxMiktar = parseFloat(tr.find('.kalem-miktar').data('miktar') || 0);
    const listesi = td.find('.eslesme-listesi');

    // Mevcut satırlarda sil butonunu göster
    listesi.find('.btn-eslesme-sil').show();

    // Kalan miktarı hesapla
    let kullanilan = 0;
    listesi.find('.eslesme-adet').each(function() {
        kullanilan += parseFloat($(this).val()) || 0;
    });
    const kalan = Math.max(0, maxMiktar - kullanilan);

    let emptyOpts = '<option value="">-- Ürün Seçin --</option>';
    sistemUrunleri.forEach(u => {
        emptyOpts += `<option value="${u.Urun_id}">[${u.Urun_Kodu || '-'}] ${u.Urun_Adi} (Stok: ${parseFloat(u.Stok_Miktari)})</option>`;
    });

    const yeniSatir = `
        <div class="eslesme-satir d-flex gap-1 mb-1 align-items-center">
            <select class="form-select form-select-sm urun-select" style="min-width:0;flex:1">
                ${emptyOpts}
            </select>
            <input type="number" class="form-control form-control-sm eslesme-adet"
                   value="${kalan > 0 ? kalan : 1}" min="0" step="1"
                   style="width:70px;flex-shrink:0" placeholder="Adet" title="Adet">
            <button type="button" class="btn btn-outline-danger btn-sm btn-eslesme-sil py-0 px-1 flex-shrink-0"
                    title="Bu satırı sil">
                <i class="bi bi-trash"></i>
            </button>
        </div>`;

    listesi.append(yeniSatir);

    listesi.find('.urun-select').last().select2({
        dropdownParent: $('#stokEslestirmeModal'),
        placeholder: 'Ürün Arayın...',
        allowClear: true,
        matcher: urunWildcardMatcher
    });

    updateEslesmeBilgi(tr);
});

// Satır sil
$(document).on('click', '.btn-eslesme-sil', function() {
    const tr = $(this).closest('tr');
    const listesi = $(this).closest('.eslesme-listesi');
    $(this).closest('.eslesme-satir').remove();
    if (listesi.find('.eslesme-satir').length === 1) {
        listesi.find('.btn-eslesme-sil').hide();
    }
    updateEslesmeBilgi(tr);
});

// Adet değişince bilgiyi güncelle
$(document).on('input change', '.eslesme-adet', function() {
    updateEslesmeBilgi($(this).closest('tr'));
});

function gonderStokGirisi(eslesmeler) {
    const faturaNo = $('#modalFaturaNo').text();
    const faturaId = $('#modalFaturaId').val();
    const dolarKur = $('#modalDolarKur').val();
    const euroKur = $('#modalEuroKur').val();

    $('#btnStokKaydet').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> İşleniyor...');

    $.post('', {
        action: 'stok_girisi_kaydet',
        fatura_no: faturaNo,
        fatura_id: faturaId,
        dolar_kur: dolarKur,
        euro_kur: euroKur,
        eslesmeler: eslesmeler
    }, function(resp) {
        $('#btnStokKaydet').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>Stoklara İşle ve Kaydet');
        if (resp.success) {
            Swal.fire('Başarılı', resp.message, 'success').then(() => {
                $('#stokEslestirmeModal').modal('hide');
                loadFaturalar(); // Tabloyu yenile
            });
        } else {
            Swal.fire('Hata', resp.message || 'Kayıt sırasında bir sorun oluştu.', 'error');
        }
    }, 'json').fail(function() {
        $('#btnStokKaydet').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>Stoklara İşle ve Kaydet');
        Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
    });
}

// Stok iade butonu (gelen fatura stok girişini geri al)
$(document).on('click', '.btn-stok-iade', function() {
    const id = $(this).data('id');
    const no = $(this).data('no');
    Swal.fire({
        title: 'Stok Girişi İade Edilsin mi?',
        html: '<b>' + no + '</b> numaralı faturanın stok girişleri geri alınacak ve stok miktarları düşülecek.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: '<i class="bi bi-arrow-counterclockwise"></i> Evet, Stok İade Et',
        cancelButtonText: 'Vazgeç'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.post('', { action: 'stok_iade', fatura_id: id }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Stok İade Edildi!', text: res.message, timer: 2000, showConfirmButton: false });
                loadFaturalar();
            } else {
                Swal.fire('Hata', res.message, 'error');
            }
        }, 'json');
    });
});

$(function() {
    // Tarih araligi custom filtre
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (settings.nTable.id !== 'faturaTable') return true;
        var bas = $('#f_tarih_bas').val();   // YYYY-MM-DD
        var bit = $('#f_tarih_bit').val();   // YYYY-MM-DD
        if (!bas && !bit) return true;

        // data[1] = gorsel tarih: DD.MM.YYYY — geri ceviriyoruz
        var raw = data[1]; // "30.04.2026" formati
        if (!raw || raw === '-') return false;
        var parts = raw.split('.');
        if (parts.length !== 3) return false;
        var rowDate = parts[2] + '-' + parts[1] + '-' + parts[0]; // YYYY-MM-DD

        if (bas && rowDate < bas) return false;
        if (bit && rowDate > bit) return false;
        return true;
    });

    // Durum filtresi. Durum metni hem Türkbelge hem GİB açıklamasını
    // içerebildiği için ikisi birlikte aranır.
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex, row) {
        if (settings.nTable.id !== 'faturaTable') return true;
        var secim = $('#f_durum').val() || '';
        if (!secim) return true;

        var d = ((row.durum || '') + ' ' + (row.gib_durum || '')).toUpperCase();
        if (secim === 'CANCELLED') return d.indexOf('CANCEL') >= 0;
        if (secim === 'WAIT')      return d.indexOf('WAIT') >= 0;
        if (secim === 'FAILED')    return d.indexOf('FAIL') >= 0 || d.indexOf('ERROR') >= 0;
        if (secim === 'SUCCEED')   return d.indexOf('SUCCE') >= 0 || d.indexOf('BAŞARI') >= 0 || d.indexOf('BASARI') >= 0;
        return true;
    });

    $('#f_durum').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Tümü', allowClear: true })
        .on('change', function() { table.draw(); });

    // Filtrele butonu
    $('#btnFiltrele').on('click', function() {
        table.column(0).search($('#f_fatura_no').val().trim());
        table.column(2).search($('#f_vkn').val().trim());
        table.column(3).search($('#f_unvan').val().trim());
        table.column(4).search($('#f_tip').val().trim());
        table.draw();
    });

    // Enter ile filtrele
    $('#filtrePanel input').on('keypress', function(e) {
        if (e.which === 13) $('#btnFiltrele').trigger('click');
    });

    // Tarih degisince aninda filtrele
    $('#f_tarih_bas, #f_tarih_bit').on('change', function() {
        table.draw();
    });

    // Cari Tipi değiştiğinde sunucudan yeniden filtrele
    $('#f_cari_tipi').on('change', function() {
        loadFaturalar();
    });

    // Filtre temizle
    $('#btnFiltreTemizle').on('click', function() {
        $('#f_fatura_no, #f_vkn, #f_unvan, #f_tip, #f_tarih_bas, #f_tarih_bit').val('');
        $('#f_durum').val('').trigger('change.select2');
        $('#f_cari_tipi').val('3'); // Temizleyince tekrar 3 (Tedarikçi) olsun
        table.search('').columns().search('').draw();
        loadFaturalar();
    });

    // Cari Tiplerini yükle
    function loadCariTipleri() {
        $.post('', { action: 'get_cari_tipleri' }, function(resp) {
            if (resp.success) {
                let html = '<option value="">Tümü</option>';
                resp.data.forEach(function(item) {
                    let sel = (item.cari_tipi_id == 3) ? ' selected' : '';
                    html += `<option value="${item.cari_tipi_id}"${sel}>${item.cari_tipi_adi}</option>`;
                });
                $('#f_cari_tipi').html(html);
            }
        }, 'json');
    }
    
    // Cari tiplerini yükle
    loadCariTipleri();
});
</script>
</body>
</html>
