<?php
/**
 * Admin Panel - Fatura Görüntüleme (UBL-TR XSLT Render)
 * Önce GİB API'den çeker; e-arşiv / henüz iletilmemiş faturalar için DB'den inşa eder.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TurkbelgeAPI.php';
require_once __DIR__ . '/../includes/TurkbelgeService.php';

$faturaNo  = $_GET['fatura_no'] ?? '';
$direction = $_GET['direction'] ?? '';

if (empty($faturaNo)) {
    die("Fatura numarasi belirtilmedi.");
}

// ── Yardımcı: XML string → HTML (XSLT render) ────────────────────────────────
function renderXmlAsHtml(string $xmlString, bool $isEArsiv = false): void
{
    $doc = new DOMDocument();
    $doc->loadXML($xmlString);

    $xsltString   = '';
    $xsltNodeList = $doc->getElementsByTagName('EmbeddedDocumentBinaryObject');
    foreach ($xsltNodeList as $node) {
        $fn = strtolower($node->getAttribute('filename'));
        if (strpos($fn, 'xslt') !== false || strpos($fn, 'xsl') !== false) {
            $xsltString = base64_decode($node->nodeValue);
            break;
        }
    }
    if (!$xsltString && $xsltNodeList->length > 0) {
        $xsltString = base64_decode($xsltNodeList->item(0)->nodeValue);
    }
    if (!$xsltString) {
        $localXslt = $isEArsiv
            ? __DIR__ . '/../assets/xslt/0_arsivfatura.xslt'
            : __DIR__ . '/../assets/xslt/0_fatura.xslt';
        if (file_exists($localXslt)) {
            $xsltString = file_get_contents($localXslt);
        }
    }

    if ($xsltString) {
        $xslDoc = new DOMDocument();
        $xslDoc->loadXML($xsltString);
        $proc = new XSLTProcessor();
        $proc->importStylesheet($xslDoc);
        echo $proc->transformToXML($doc);
    } else {
        header('Content-Type: text/xml; charset=utf-8');
        echo $xmlString;
    }
}

// ── Yardımcı: GİB API'den fatura XML'i çek ───────────────────────────────────
function fetchXmlFromGib($db, string $faturaNo, string $direction): ?string
{
    $kanal = $db->fetchOne("
        SELECT ek.Kanal_Parametreler
        FROM EntegrasyonKanallari ek
        INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
        WHERE e.Entegrasyon_Kod = 'TURKBELGE' AND ek.Kanal_Durum = 1
        ORDER BY ek.Kanal_Varsayilan DESC
    ");
    $params      = json_decode($kanal['Kanal_Parametreler'] ?? '{}', true);
    $efaturaWsdl = $params['wsdl_efatura'] ?? 'https://efaturaws.turkbelge.com.tr/EFaturaOIB?wsdl';
    if (strpos($efaturaWsdl, 'test') !== false) {
        $efaturaWsdl = str_replace('efaturawstest', 'efaturaws', $efaturaWsdl);
    }

    $api = new TurkbelgeAPI($db);
    $api->login();
    $reflection  = new ReflectionClass($api);
    $sessionProp = $reflection->getProperty('sessionId');
    $sessionProp->setAccessible(true);
    $sessionId = $sessionProp->getValue($api);

    $client = new SoapClient($efaturaWsdl, [
        'trace'          => 1,
        'exceptions'     => true,
        'cache_wsdl'     => WSDL_CACHE_NONE,
        'stream_context' => stream_context_create(['ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]),
    ]);

    $response = $client->GetInvoice([
        'REQUEST_HEADER'     => [
            'SESSION_ID'       => $sessionId,
            'APPLICATION_NAME' => 'ORNEK_PORTAL',
            'COMPRESSED'       => 'N',
        ],
        'INVOICE_SEARCH_KEY' => [
            'LIMIT'         => 1,
            'ID'            => $faturaNo,
            'DIRECTION'     => $direction,
            'READ_INCLUDED' => true,
            'CONTENT_TYPE'  => 'XML',
        ],
        'HEADER_ONLY'        => 'N',
    ]);

    if (!isset($response->INVOICE->CONTENT)) return null;

    $content = is_object($response->INVOICE->CONTENT)
        ? $response->INVOICE->CONTENT->_
        : $response->INVOICE->CONTENT;

    if (strpos($content, '<?xml') === false && strpos($content, '<Invoice') === false) {
        $decoded = base64_decode($content);
        if ($decoded) $content = $decoded;
    }

    return $content ?: null;
}

try {
    $db = Database::getInstance();

    // direction belirtilmemişse DB'den bul
    if (empty($direction)) {
        $isGiden   = $db->fetchOne("SELECT 1 AS v FROM FaturalarGiden WHERE FaturalarGiden_FaturaNo = ?", [$faturaNo]);
        $direction = $isGiden ? 'OUT' : 'IN';
    } else {
        if ($direction !== 'OUT') $direction = 'IN';
    }

    // ── 1. GİB'den çekmeyi dene ──────────────────────────────────────────────
    $gibXml = fetchXmlFromGib($db, $faturaNo, $direction);

    if ($gibXml) {
        $isEArsiv = false;
        if ($direction === 'OUT') {
            $row      = $db->fetchOne(
                "SELECT FaturalarGiden_Senaryo, FaturalarGiden_GibDurum FROM FaturalarGiden WHERE FaturalarGiden_FaturaNo = ?",
                [$faturaNo]
            );
            $gibDurum = mb_strtolower(trim((string)($row['FaturalarGiden_GibDurum'] ?? '')), 'UTF-8');
            $senaryo  = strtoupper(trim((string)($row['FaturalarGiden_Senaryo'] ?? '')));
            $isEArsiv = ($gibDurum === 'e-arşiv' || $senaryo === 'EARSIVFATURA');
        }
        renderXmlAsHtml($gibXml, $isEArsiv);
        exit;
    }

    // ── 2. GİB'den gelmedi → DB'den XML inşa et (yalnız giden faturalar) ─────
    if ($direction !== 'OUT') {
        die("API'den fatura icerigi alinamadi.");
    }

    $gidenFatura = $db->fetchOne("
        SELECT
            f.*,
            c.cari_adres,
            c.cari_telefon,
            c.cari_email,
            c.cari_vergi_dairesi,
            s.SehirAdi AS cari_sehir_adi,
            il.IlceAdi AS cari_ilce_adi
        FROM FaturalarGiden f
        LEFT JOIN Cari c ON c.cari_id = f.FaturalarGiden_Cari_id
        LEFT JOIN Adres_Sehirler s ON s.SehirId = c.cari_sehirler
        LEFT JOIN Adres_Ilceler il ON il.ilceId = c.cari_ilceler
        WHERE f.FaturalarGiden_FaturaNo = ? AND f.Durum = 1
    ", [$faturaNo]);

    if (!$gidenFatura) {
        die("Fatura bulunamad&#305;: " . htmlspecialchars($faturaNo));
    }

    $kalemler = $db->fetchAll("
        SELECT * FROM FaturalarGidenKalemleri
        WHERE FaturalarGidenKalemleri_Fatura_id = ?
        ORDER BY FaturalarGidenKalemleri_Sira
    ", [(int)$gidenFatura['FaturalarGiden_id']]);

    $gibDurum = mb_strtolower(trim((string)($gidenFatura['FaturalarGiden_GibDurum'] ?? '')), 'UTF-8');
    $senaryo  = strtoupper(trim((string)($gidenFatura['FaturalarGiden_Senaryo']  ?? '')));
    $isEArsiv = ($gibDurum === 'e-arşiv' || $senaryo === 'EARSIVFATURA');

    // NetTutar / ToplamKDV NULL ise kalemlerden hesapla
    $netTutar  = (float)($gidenFatura['FaturalarGiden_NetTutar']  ?? 0);
    $toplamKdv = (float)($gidenFatura['FaturalarGiden_ToplamKDV'] ?? 0);
    if ($netTutar == 0 && !empty($kalemler)) {
        foreach ($kalemler as $kl) {
            $netTutar  += (float)($kl['FaturalarGidenKalemleri_NetTutar'] ?? 0);
            $toplamKdv += (float)($kl['FaturalarGidenKalemleri_KDVTutar'] ?? 0);
        }
        $gidenFatura['FaturalarGiden_NetTutar']  = $netTutar;
        $gidenFatura['FaturalarGiden_ToplamKDV'] = $toplamKdv;
    }
    if ((float)($gidenFatura['FaturalarGiden_Tutar'] ?? 0) == 0) {
        $gidenFatura['FaturalarGiden_Tutar'] = $netTutar + $toplamKdv;
    }

    $tb          = TurkbelgeService::fromDb($db);
    $buildResult = $tb->buildUblXml($gidenFatura, $kalemler, $isEArsiv);
    $xmlString   = $buildResult['xml'];

    // Orijinal ETTN varsa koru
    $ettn = trim((string)($gidenFatura['FaturalarGiden_ETTN'] ?? ''));
    if ($ettn) {
        $xmlString = preg_replace(
            '/<cbc:UUID>[^<]*<\/cbc:UUID>/',
            '<cbc:UUID>' . htmlspecialchars($ettn, ENT_XML1) . '</cbc:UUID>',
            $xmlString
        );
    }

    renderXmlAsHtml($xmlString, $isEArsiv);
    exit;

} catch (Exception $e) {
    die("Sistem Hatasi: " . htmlspecialchars($e->getMessage()));
}
