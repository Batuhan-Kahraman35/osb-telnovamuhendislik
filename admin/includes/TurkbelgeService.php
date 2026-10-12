<?php
/**
 * TurkbelgeService - Gerçek WSDL yapısına göre yazıldı
 */
class TurkbelgeService
{
    private string $authWsdl;
    private string $efaturaWsdl;
    private string $earsivWsdl;
    private string $vkn;
    private string $firmaUnvan;
    private string $kullanici;
    private string $sifre;
    private string $gbAlias;  // Gönderici Birim alias (DB'den)
    private string $pkAlias;  // Posta Kutusu alias (DB'den)
    private string $firmaAdres;
    private string $firmaIl;
    private string $firmaIlce;
    private string $firmaTelefon;
    private string $firmaEposta;
    private string $firmaVergiDairesi;

    private ?SoapClient $authClient    = null;
    private ?SoapClient $efaturaClient = null;
    private ?SoapClient $earsivClient  = null;
    private ?string     $sessionId     = null; // Auth SESSION_ID (eArsiv için)

    public function __construct(array $kanal, array $parametreler)
    {
        $this->authWsdl    = $kanal['Kanal_Endpoint'];
        $this->efaturaWsdl = $parametreler['wsdl_efatura'];
        $this->earsivWsdl  = $parametreler['wsdl_earsiv'];
        $this->vkn         = $parametreler['vkn'];
        $this->firmaUnvan  = $parametreler['firma_unvan'];
        $this->kullanici   = $kanal['Kanal_Kullanici'];
        $this->sifre       = $kanal['Kanal_Sifre'];
        $this->gbAlias     = $parametreler['gb_alias'] ?? '';
        $this->pkAlias     = $parametreler['pk_alias'] ?? '';
        $this->firmaAdres         = $parametreler['firma_adres'] ?? '';
        $this->firmaIl            = $parametreler['firma_il'] ?? '';
        $this->firmaIlce          = $parametreler['firma_ilce'] ?? '';
        $this->firmaTelefon       = $parametreler['firma_telefon'] ?? '';
        $this->firmaEposta        = $parametreler['firma_eposta'] ?? '';
        $this->firmaVergiDairesi  = $parametreler['firma_vergi_dairesi'] ?? '';
    }

    public static function fromDb($db, $kanalId = 1): self
    {
        $kanal = $db->fetchOne("
            SELECT ek.* FROM EntegrasyonKanallari ek
            INNER JOIN Entegrasyon e ON e.Entegrasyon_Id = ek.Kanal_Entegrasyon_Id
            WHERE e.Entegrasyon_Kod = 'TURKBELGE' AND ek.Kanal_Id = ?
        ", [$kanalId]);
        if (!$kanal) throw new RuntimeException('Turkbelge kanal bulunamadı.');
        $p = json_decode($kanal['Kanal_Parametreler'], true);
        if (!$p) throw new RuntimeException('Kanal parametreleri hatalı.');
        return new self($kanal, $p);
    }

    private function soapOpts(): array
    {
        return [
            'exceptions'   => true,
            'cache_wsdl'   => WSDL_CACHE_DISK,  // WSDL'i diske önbelleğle
            'trace'        => true,
            'soap_version' => SOAP_1_1,
        ];
    }

    // ── Kimlik Doğrulama ──────────────────────────────────────────────────

    public function authenticate(): void
    {
        $opts = $this->soapOpts();

        // Auth Login → SESSION_ID (eArsiv + Auth sorgusu için)
        $this->authClient = new SoapClient($this->authWsdl, $opts);
        $r = $this->authClient->Login([
            'REQUEST_HEADER' => ['SESSION_ID' => '', 'APPLICATION_NAME' => 'e-Fatura', 'COMPRESSED' => 'N'],
            'USER_NAME'      => $this->kullanici,
            'PASSWORD'       => $this->sifre,
        ]);
        $this->checkError($r, 'Auth Login');
        $this->sessionId = $r->SESSION_ID ?? null;
        if (!$this->sessionId) throw new RuntimeException('Auth SESSION_ID alınamadı.');

        // eArsiv client
        $this->earsivClient = new SoapClient($this->earsivWsdl, $opts);

        // eFatura client (LoadInvoice/SendInvoice için - CheckUser hariç)
        $this->efaturaClient = new SoapClient($this->efaturaWsdl, $opts);
    }

    /** Authenticate sonrası SESSION_ID'yi döner (debug / test amaçlı) */
    public function getSessionId(): ?string { return $this->sessionId; }

    /** Authenticate sonrası authClient'ı döner (debug / test amaçlı) */
    public function getAuthClient(): ?SoapClient { return $this->authClient; }

    /** Authenticate sonrası earsivClient'ı döner (debug / test amaçlı) */
    public function getEarsivClient(): ?SoapClient { return $this->earsivClient; }

    // ── Mükellef Kontrolü (Auth CheckUser, DOCUMENT_TYPE=EFATURA) ─────────

    /**
     * Alıcı e-fatura mükellefi mi?
     * GetGibUserList binary format üzerinden kontrol.
     * @return array|null ['alias'=>'...'] ya da null
     */
    public function mukellefKontrol(string $aliciVkn): ?array
    {
        try {
            $r = $this->authClient->CheckUser([
                'REQUEST_HEADER' => [
                    'SESSION_ID'       => $this->sessionId,
                    'APPLICATION_NAME' => 'e-Fatura',
                    'COMPRESSED'       => 'N',
                ],
                'USER' => [
                    'IDENTIFIER' => $aliciVkn,
                ],
            ]);

            // Kullanıcı kayıtlıysa USER objesi döner
            if (!empty($r->USER)) {
                $user = is_array($r->USER) ? $r->USER[0] : $r->USER;
                $alias = '';
                if (!empty($user->ALIAS)) {
                    $alias = is_object($user->ALIAS) ? ($user->ALIAS->_ ?? '') : (string)$user->ALIAS;
                }
                $title = '';
                if (!empty($user->TITLE)) {
                    $title = is_object($user->TITLE) ? ($user->TITLE->_ ?? '') : (string)$user->TITLE;
                }
                return ['alias' => $alias, 'title' => $title];
            }
        } catch (Exception $e) {
            // Kayıtlı değil veya hata
        }
        return null;
    }

    // ── e-Fatura: LoadInvoice → SendInvoice ──────────────────────────────

    public function efaturaGonder(string $xml, string $uuid, string $aliciVkn, string $aliciAlias, string $senaryo): array
    {
        // Gönderici alias: DB'den okunan GB (Gönderici Birim) alias'ı
        $gondericiAlias = $this->gbAlias;
        if (empty($gondericiAlias)) {
            throw new RuntimeException('Gönderici GB alias tanımlı değil. EntegrasyonKanallari.Kanal_Parametreler içinde gb_alias alanını kontrol edin.');
        }


        // 1. LoadInvoice
        $loadR = $this->efaturaClient->LoadInvoice([
            'REQUEST_HEADER' => [
                'SESSION_ID'       => $this->sessionId,
                'APPLICATION_NAME' => 'e-Fatura',
                'COMPRESSED'       => 'N',
            ],
            'INVOICE' => [
                'CONTENT' => new \SoapVar(base64_encode($xml), XSD_STRING),
                'UUID'    => $uuid,
                'HEADER'  => [
                    'SENDER'    => $this->vkn,
                    'RECEIVER'  => $aliciVkn,
                    'PROFILEID' => $senaryo,
                ],
            ],
            'VALIDATION_FLAG' => 'N',
        ]);
        $this->checkError($loadR, 'LoadInvoice');

        // 2. SendInvoice
        $sendR = $this->efaturaClient->SendInvoice([
            'REQUEST_HEADER' => [
                'SESSION_ID'       => $this->sessionId,
                'APPLICATION_NAME' => 'e-Fatura',
                'COMPRESSED'       => 'N',
            ],
            'SENDER'   => ['vkn' => $this->vkn,  'alias' => $gondericiAlias],
            'RECEIVER' => ['vkn' => $aliciVkn,    'alias' => $aliciAlias],
            'INVOICE'  => [
                'UUID'   => $uuid,
                'HEADER' => ['SENDER' => $this->vkn, 'RECEIVER' => $aliciVkn],
            ],
        ]);
        $this->checkError($sendR, 'SendInvoice');

        return ['invoice_id' => $sendR->INVOICE_ID ?? $uuid];
    }

    // ── e-Arşiv: WriteToArchiveExtended ──────────────────────────────────

    public function earsivGonder(string $xml, string $uuid, bool $testOrtami = true): array
    {
        // DOM ile ProfileID güvenli değiştir
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        foreach ($xpath->query('//cbc:ProfileID') as $node) {
            $node->nodeValue = 'EARSIVFATURA';
        }
        $xml = $dom->saveXML();

        // XSD_STRING: base64_encode + SoapVar(string) → SOAP paketi bozulmaz
        // XSD_BASE64BINARY kullanılırsa SoapClient ek encode yapar → çift encode → PAKET AYRIŞTIRILAMADI
        $icerik = new \SoapVar(base64_encode($xml), XSD_STRING);

        $r = $this->earsivClient->WriteToArchiveExtended([
            'REQUEST_HEADER' => [
                'SESSION_ID'       => $this->sessionId,
                'APPLICATION_NAME' => 'e-Arsiv',
                'COMPRESSED'       => 'N',
            ],
            'ArchiveInvoiceExtendedContent' => [
                'INVOICE_PROPERTIES' => [
                    'EARSIV_FLAG'     => 'Y',
                    'SENDING_TYPE'    => 'ELEKTRONIK',
                    'INVOICE_CONTENT' => $icerik,
                    'EARSIV_PROPERTIES' => [
                        'EARSIV_TYPE'        => 'NORMAL',
                        'SUB_STATUS'         => 'NEW',
                        'EARSIV_EMAIL_FLAG'  => 'N',
                        'EARCHIVE_TEST_FLAG' => $testOrtami ? 'Y' : 'N',
                    ],
                ],
            ],
        ]);

        $this->checkError($r, 'WriteToArchiveExtended');

        return [
            'invoice_id' => $r->INVOICE_ID ?? $uuid,
            'web_key'    => $r->WEB_KEY    ?? '',
        ];
    }

    // ── UBL-TR XML ───────────────────────────────────────────────────────

    public function buildUblXml(array $fatura, array $kalemler, bool $isEArsiv = false): array
    {
        $uuid    = $this->uuid();
        $tarih   = date('Y-m-d', strtotime($fatura['FaturalarGiden_Tarih']));
        $senaryo = $fatura['FaturalarGiden_Senaryo']   ?? 'TEMELFATURA';
        $tip     = $fatura['FaturalarGiden_FaturaTipi'] ?? 'SATIS';

        // İade faturası: Türkbelge kılavuzu gereği TEMELFATURA zorunludur ve
        // iade edilen faturanın no/tarih bilgisi BillingReference ile taşınır.
        $isIade        = ($tip === 'IADE');
        $iadeKaynakNo  = '';
        $iadeKaynakTar = '';
        if ($isIade) {
            $senaryo       = 'TEMELFATURA';
            $iadeKaynakNo  = trim($fatura['iade_kaynak_no'] ?? '');
            $iadeKaynakTar = trim($fatura['iade_kaynak_tarih'] ?? '');
            if ($iadeKaynakNo === '' || $iadeKaynakTar === '') {
                throw new Exception('İade faturası için iade edilen faturanın numarası ve tarihi zorunludur.');
            }
            $iadeKaynakTar = date('Y-m-d', strtotime($iadeKaynakTar));
        }
        $net     = (float)($fatura['FaturalarGiden_NetTutar']  ?? 0);
        $kdv     = (float)($fatura['FaturalarGiden_ToplamKDV'] ?? 0);
        $genel   = (float)($fatura['FaturalarGiden_Tutar']     ?? 0);
        $para    = strtoupper(trim($fatura['FaturalarGiden_ParaBirimi'] ?? 'TRY')) ?: 'TRY';
        $kur     = 0.0;
        if ($para === 'USD') {
            $kur = (float)($fatura['FaturalarGiden_DolarKur'] ?? 0);
        } elseif ($para === 'EUR') {
            $kur = (float)($fatura['FaturalarGiden_EuroKur'] ?? 0);
        }

        $aliciVkn   = $this->e($fatura['FaturalarGiden_VKN_TCKN'] ?? '');
        $aliciUnvan = $this->e($fatura['FaturalarGiden_Unvan']     ?? '');

        // GİB Schematron: schemeID="VKN" → 10 hane, schemeID="TCKN" → 11 hane
        $rawVkn      = preg_replace('/\D/', '', $fatura['FaturalarGiden_VKN_TCKN'] ?? '');
        $aliciScheme = strlen($rawVkn) === 11 ? 'TCKN' : 'VKN';

        // Kalemlerden brüt toplamları hesapla (iskonto uygulanmadan)
        $grossNet = 0.0;
        foreach ($kalemler as $k) {
            $grossNet += (float)$k['FaturalarGidenKalemleri_NetTutar'];
        }
        // Negatif iskonto UBL'de geçersizdir (GİB Schematron: AllowanceTotalAmount).
        // Eski kayıtlardaki kuruş farkları negatif değer üretebildiği için sıfıra çekilir.
        $iskontoTutar = round($grossNet - $net, 2);
        if ($iskontoTutar < 0) {
            $iskontoTutar = 0.0;
            $grossNet     = $net; // LineExtensionAmount ile TaxExclusiveAmount tutarlı kalsın
        }
        $iskontoPct   = $grossNet > 0 ? ($iskontoTutar / $grossNet) : 0.0;

        $kdvGrup = [];
        foreach ($kalemler as $k) {
            $o = (int)round((float)$k['FaturalarGidenKalemleri_KDVOrani']);
            $kdvGrup[$o]['matrah'] = ($kdvGrup[$o]['matrah'] ?? 0) + (float)$k['FaturalarGidenKalemleri_NetTutar'];
            $kdvGrup[$o]['tutar']  = ($kdvGrup[$o]['tutar']  ?? 0) + (float)$k['FaturalarGidenKalemleri_KDVTutar'];
        }
        // İskonto varsa TaxSubtotal matrah ve tutarlarını orantılı azalt
        if ($iskontoPct > 0) {
            foreach ($kdvGrup as &$g) {
                $g['matrah'] *= (1 - $iskontoPct);
                $g['tutar']  *= (1 - $iskontoPct);
            }
            unset($g);
        }

        $x  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $x .= "<Invoice\n";
        $x .= "  xmlns=\"urn:oasis:names:specification:ubl:schema:xsd:Invoice-2\"\n";
        $x .= "  xmlns:cac=\"urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2\"\n";
        $x .= "  xmlns:cbc=\"urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2\"\n";
        $x .= "  xmlns:ext=\"urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2\"\n";
        $x .= "  xmlns:ds=\"http://www.w3.org/2000/09/xmldsig#\"\n";
        $x .= "  xmlns:xades=\"http://uri.etsi.org/01903/v1.3.2#\">\n";

        // 1. UBLExtensions (zorunlu, imza bloğu buraya girer — test ortamı boş)
        $x .= "<ext:UBLExtensions>\n";
        $x .= "  <ext:UBLExtension>\n";
        $x .= "    <ext:ExtensionContent/>\n";
        $x .= "  </ext:UBLExtension>\n";
        $x .= "</ext:UBLExtensions>\n";

        // 2. Başlık
        $x .= "<cbc:UBLVersionID>2.1</cbc:UBLVersionID>\n";
        $x .= "<cbc:CustomizationID>TR1.2</cbc:CustomizationID>\n";
        $x .= "<cbc:ProfileID>{$this->e($senaryo)}</cbc:ProfileID>\n";
        $x .= "<cbc:ID>{$this->e($fatura['FaturalarGiden_FaturaNo'])}</cbc:ID>\n";
        $x .= "<cbc:CopyIndicator>false</cbc:CopyIndicator>\n";
        $x .= "<cbc:UUID>$uuid</cbc:UUID>\n";
        $x .= "<cbc:IssueDate>$tarih</cbc:IssueDate>\n";
        $x .= "<cbc:IssueTime>00:00:00</cbc:IssueTime>\n";
        $x .= "<cbc:InvoiceTypeCode>" . $this->e($tip) . "</cbc:InvoiceTypeCode>\n";
        if (!empty($fatura['FaturalarGiden_Notlar'])) {
            $x .= "<cbc:Note>{$this->e($fatura['FaturalarGiden_Notlar'])}</cbc:Note>\n";
        }
        $x .= "<cbc:DocumentCurrencyCode>$para</cbc:DocumentCurrencyCode>\n";
        if ($para !== 'TRY') {
            $x .= "<cbc:TaxCurrencyCode>TRY</cbc:TaxCurrencyCode>\n";
        }
        $x .= "<cbc:LineCountNumeric>" . count($kalemler) . "</cbc:LineCountNumeric>\n";

        // 2a. İade faturası referansı — UBL-TR sırası: LineCountNumeric sonrası
        if ($isIade) {
            $x .= "<cac:BillingReference>\n";
            $x .= "  <cac:InvoiceDocumentReference>\n";
            $x .= "    <cbc:ID>{$this->e($iadeKaynakNo)}</cbc:ID>\n";
            $x .= "    <cbc:IssueDate>$iadeKaynakTar</cbc:IssueDate>\n";
            $x .= "    <cbc:DocumentTypeCode>IADE</cbc:DocumentTypeCode>\n";
            $x .= "  </cac:InvoiceDocumentReference>\n";
            $x .= "</cac:BillingReference>\n";
        }

        // 3. XSLT Şablon Eklemesi
        $xsltYolu = $isEArsiv 
            ? __DIR__ . '/../assets/xslt/0_arsivfatura.xslt'
            : __DIR__ . '/../assets/xslt/0_fatura.xslt';
        
        $xsltContent = '';
        if (file_exists($xsltYolu)) {
            $xsltContent = base64_encode(file_get_contents($xsltYolu));
        } else {
            $xsltContent = base64_encode($this->getEarsivXslt());
        }

        $x .= "<cac:AdditionalDocumentReference>\n";
        $x .= "  <cbc:ID>" . uniqid() . "</cbc:ID>\n";
        $x .= "  <cbc:IssueDate>$tarih</cbc:IssueDate>\n";
        $x .= "  <cbc:DocumentType>XSLT</cbc:DocumentType>\n";
        $x .= "  <cac:Attachment>\n";
        $x .= "    <cbc:EmbeddedDocumentBinaryObject mimeCode=\"application/xml\" encodingCode=\"Base64\" characterSetCode=\"UTF-8\" filename=\"template.xslt\">$xsltContent</cbc:EmbeddedDocumentBinaryObject>\n";
        $x .= "  </cac:Attachment>\n";
        $x .= "</cac:AdditionalDocumentReference>\n";

        // 3a-2. e-Arşiv gönderim şekli (Turkbelge XSLT: cbc:DocumentTypeCode='SendingType')
        if ($isEArsiv) {
            $x .= "<cac:AdditionalDocumentReference>\n";
            $x .= "  <cbc:ID>" . uniqid() . "</cbc:ID>\n";
            $x .= "  <cbc:IssueDate>$tarih</cbc:IssueDate>\n";
            $x .= "  <cbc:DocumentTypeCode>SendingType</cbc:DocumentTypeCode>\n";
            $x .= "  <cbc:DocumentType>ELEKTRONIK</cbc:DocumentType>\n";
            $x .= "</cac:AdditionalDocumentReference>\n";
        }

        // 3b. Signature (zorunlu referans — gerçek imza ext bloğunda)
        $x .= "<cac:Signature>\n";
        $x .= "  <cbc:ID schemeID=\"VKN_TCKN\">{$this->vkn}</cbc:ID>\n";
        $x .= "  <cac:SignatoryParty>\n";
        $x .= "    <cac:PartyIdentification><cbc:ID schemeID=\"VKN\">{$this->vkn}</cbc:ID></cac:PartyIdentification>\n";
        $x .= "    <cac:PartyName><cbc:Name>{$this->e($this->firmaUnvan)}</cbc:Name></cac:PartyName>\n";
        $x .= "    <cac:PostalAddress>\n";
        $x .= "      <cbc:CitySubdivisionName/>\n";
        $x .= "      <cbc:CityName/>\n";
        $x .= "      <cac:Country><cbc:Name>Türkiye</cbc:Name></cac:Country>\n";
        $x .= "    </cac:PostalAddress>\n";
        $x .= "  </cac:SignatoryParty>\n";
        $x .= "  <cac:DigitalSignatureAttachment>\n";
        $x .= "    <cac:ExternalReference><cbc:URI>#Signature</cbc:URI></cac:ExternalReference>\n";
        $x .= "  </cac:DigitalSignatureAttachment>\n";
        $x .= "</cac:Signature>\n";


        // 4. Satıcı
        $saticiAdres = $this->e($this->firmaAdres);
        $saticiIl    = $this->e($this->firmaIl);
        $saticiIlce  = $this->e($this->firmaIlce);
        $saticiTel   = $this->e($this->firmaTelefon);
        $saticiMail  = $this->e($this->firmaEposta);
        $saticiVD    = $this->e($this->firmaVergiDairesi);

        $x .= "<cac:AccountingSupplierParty><cac:Party>\n";
        $x .= "  <cac:PartyIdentification><cbc:ID schemeID=\"VKN\">{$this->vkn}</cbc:ID></cac:PartyIdentification>\n";
        $x .= "  <cac:PartyName><cbc:Name>{$this->e($this->firmaUnvan)}</cbc:Name></cac:PartyName>\n";
        $x .= "  <cac:PostalAddress>\n";
        if ($saticiAdres) {
            $x .= "    <cbc:StreetName>$saticiAdres</cbc:StreetName>\n";
        }
        $x .= "    <cbc:CitySubdivisionName>$saticiIlce</cbc:CitySubdivisionName>\n";
        $x .= "    <cbc:CityName>$saticiIl</cbc:CityName>\n";
        $x .= "    <cac:Country><cbc:Name>Türkiye</cbc:Name></cac:Country>\n";
        $x .= "  </cac:PostalAddress>\n";
        $x .= "  <cac:PartyTaxScheme>\n";
        $x .= "    <cbc:RegistrationName>$saticiVD</cbc:RegistrationName>\n";
        $x .= "    <cac:TaxScheme><cbc:Name>$saticiVD</cbc:Name></cac:TaxScheme>\n";
        $x .= "  </cac:PartyTaxScheme>\n";
        if ($saticiTel || $saticiMail) {
            $x .= "  <cac:Contact>\n";
            if ($saticiTel) $x .= "    <cbc:Telephone>$saticiTel</cbc:Telephone>\n";
            if ($saticiMail) $x .= "    <cbc:ElectronicMail>$saticiMail</cbc:ElectronicMail>\n";
            $x .= "  </cac:Contact>\n";
        }
        $x .= "</cac:Party></cac:AccountingSupplierParty>\n";

        // 5. Alıcı
        $aliciAdres   = $this->e($fatura['cari_adres']     ?? '');
        $aliciTelefon = $this->e($fatura['cari_telefon']   ?? '');
        $aliciEposta  = $this->e($fatura['cari_email']     ?? '');
        $aliciSehir   = $this->e($fatura['cari_sehir_adi'] ?? '');
        $aliciIlce    = $this->e($fatura['cari_ilce_adi']  ?? '');
        $aliciVD      = $this->e($fatura['cari_vergi_dairesi'] ?? '');

        $x .= "<cac:AccountingCustomerParty><cac:Party>\n";
        $x .= "  <cac:PartyIdentification><cbc:ID schemeID=\"{$aliciScheme}\">$aliciVkn</cbc:ID></cac:PartyIdentification>\n";
        $x .= "  <cac:PartyName><cbc:Name>$aliciUnvan</cbc:Name></cac:PartyName>\n";
        $x .= "  <cac:PostalAddress>\n";
        if ($aliciAdres) {
            $x .= "    <cbc:StreetName>$aliciAdres</cbc:StreetName>\n";
        }
        $x .= "    <cbc:CitySubdivisionName>$aliciIlce</cbc:CitySubdivisionName>\n";
        $x .= "    <cbc:CityName>$aliciSehir</cbc:CityName>\n";
        $x .= "    <cac:Country><cbc:Name>Türkiye</cbc:Name></cac:Country>\n";
        $x .= "  </cac:PostalAddress>\n";
        // GİB: TCKN'de PartyTaxScheme gerekmez; VKN'de yazılır
        if ($aliciScheme === 'VKN') {
            $x .= "  <cac:PartyTaxScheme>\n";
            $x .= "    <cbc:RegistrationName>$aliciVD</cbc:RegistrationName>\n";
            $x .= "    <cac:TaxScheme><cbc:Name>$aliciVD</cbc:Name></cac:TaxScheme>\n";
            $x .= "  </cac:PartyTaxScheme>\n";
        }
        // UBL-TR şema sırası: Contact → Person (Contact önce gelir)
        if ($aliciTelefon || $aliciEposta) {
            $x .= "  <cac:Contact>\n";
            if ($aliciTelefon) {
                $x .= "    <cbc:Telephone>$aliciTelefon</cbc:Telephone>\n";
            }
            if ($aliciEposta) {
                $x .= "    <cbc:ElectronicMail>$aliciEposta</cbc:ElectronicMail>\n";
            }
            $x .= "  </cac:Contact>\n";
        }
        // GİB Schematron: schemeID="TCKN" ise cac:Person zorunlu (Contact'tan SONRA gelir)
        if ($aliciScheme === 'TCKN') {
            $unvanHam = trim($fatura['FaturalarGiden_Unvan'] ?? '');
            $parcalar = explode(' ', $unvanHam);
            $soyad    = count($parcalar) > 1 ? $this->e(array_pop($parcalar)) : '';
            $ad       = $this->e(implode(' ', $parcalar));
            $x .= "  <cac:Person>\n";
            $x .= "    <cbc:FirstName>$ad</cbc:FirstName>\n";
            if ($soyad) {
                $x .= "    <cbc:FamilyName>$soyad</cbc:FamilyName>\n";
            }
            $x .= "  </cac:Person>\n";
        }
        $x .= "</cac:Party></cac:AccountingCustomerParty>\n";

        // UBL-TR şema sırası (UBL-Invoice-2.1.xsd): Alıcı → PaymentMeans → AllowanceCharge → PricingExchangeRate → TaxTotal
        // Sıra bozulursa servis 10003 (şema hatası) döner.

        // 5b. Ödeme Bilgisi — e-Arşiv'de zorunlu (gönderim şekli)
        if ($isEArsiv) {
            $x .= "<cac:PaymentMeans>\n";
            $x .= "  <cbc:PaymentMeansCode>1</cbc:PaymentMeansCode>\n";
            $x .= "  <cbc:PaymentChannelCode>ELEKTRONIK</cbc:PaymentChannelCode>\n";
            $x .= "</cac:PaymentMeans>\n";
        }

        // 5c. Belge düzeyinde iskonto (AllowanceCharge)
        if ($iskontoTutar > 0) {
            $x .= "<cac:AllowanceCharge>\n";
            $x .= "  <cbc:ChargeIndicator>false</cbc:ChargeIndicator>\n";
            $x .= "  <cbc:AllowanceChargeReason>İskonto</cbc:AllowanceChargeReason>\n";
            $x .= "  <cbc:Amount currencyID=\"$para\">{$this->f($iskontoTutar)}</cbc:Amount>\n";
            $x .= "  <cbc:BaseAmount currencyID=\"$para\">{$this->f($grossNet)}</cbc:BaseAmount>\n";
            $x .= "</cac:AllowanceCharge>\n";
        }

        // 5d. Döviz kuru
        if ($para !== 'TRY' && $kur > 0) {
            $x .= "<cac:PricingExchangeRate>\n";
            $x .= "  <cbc:SourceCurrencyCode>$para</cbc:SourceCurrencyCode>\n";
            $x .= "  <cbc:TargetCurrencyCode>TRY</cbc:TargetCurrencyCode>\n";
            $x .= "  <cbc:CalculationRate>" . number_format($kur, 4, '.', '') . "</cbc:CalculationRate>\n";
            $x .= "</cac:PricingExchangeRate>\n";
        }

        // 6. KDV Toplamı
        $x .= "<cac:TaxTotal>\n";
        $x .= "  <cbc:TaxAmount currencyID=\"$para\">{$this->f($kdv)}</cbc:TaxAmount>\n";
        foreach ($kdvGrup as $oran => $g) {
            $x .= "  <cac:TaxSubtotal>\n";
            $x .= "    <cbc:TaxableAmount currencyID=\"$para\">{$this->f($g['matrah'])}</cbc:TaxableAmount>\n";
            $x .= "    <cbc:TaxAmount currencyID=\"$para\">{$this->f($g['tutar'])}</cbc:TaxAmount>\n";
            $x .= "    <cbc:Percent>$oran</cbc:Percent>\n";
            $x .= "    <cac:TaxCategory>\n";
            $x .= "      <cbc:TaxExemptionReasonCode/>\n";
            $x .= "      <cac:TaxScheme><cbc:Name>KDV</cbc:Name><cbc:TaxTypeCode>0015</cbc:TaxTypeCode></cac:TaxScheme>\n";
            $x .= "    </cac:TaxCategory>\n";
            $x .= "  </cac:TaxSubtotal>\n";
        }
        $x .= "</cac:TaxTotal>\n";

        // 7. Parasal Toplamlar
        $x .= "<cac:LegalMonetaryTotal>\n";
        $x .= "  <cbc:LineExtensionAmount currencyID=\"$para\">{$this->f($grossNet)}</cbc:LineExtensionAmount>\n";
        $x .= "  <cbc:TaxExclusiveAmount currencyID=\"$para\">{$this->f($net)}</cbc:TaxExclusiveAmount>\n";
        $x .= "  <cbc:TaxInclusiveAmount currencyID=\"$para\">{$this->f($genel)}</cbc:TaxInclusiveAmount>\n";
        $x .= "  <cbc:AllowanceTotalAmount currencyID=\"$para\">{$this->f($iskontoTutar)}</cbc:AllowanceTotalAmount>\n";
        $x .= "  <cbc:PayableAmount currencyID=\"$para\">{$this->f($genel)}</cbc:PayableAmount>\n";
        $x .= "</cac:LegalMonetaryTotal>\n";

        // 8. Kalemler
        foreach ($kalemler as $i => $k) {
            $birim  = $this->birimKodu($k['FaturalarGidenKalemleri_Birim'] ?? 'ADET');
            $miktar = (float)$k['FaturalarGidenKalemleri_Miktar'];
            $fiyat  = (float)$k['FaturalarGidenKalemleri_BirimFiyat'];
            $knet   = (float)$k['FaturalarGidenKalemleri_NetTutar'];
            $kkdv   = (float)$k['FaturalarGidenKalemleri_KDVTutar'];
            $koran  = (float)$k['FaturalarGidenKalemleri_KDVOrani'];
            $urun   = $this->e($k['FaturalarGidenKalemleri_UrunAdi']);

            $x .= "<cac:InvoiceLine>\n";
            $x .= "  <cbc:ID>" . ($i + 1) . "</cbc:ID>\n";
            $x .= "  <cbc:InvoicedQuantity unitCode=\"$birim\">$miktar</cbc:InvoicedQuantity>\n";
            $x .= "  <cbc:LineExtensionAmount currencyID=\"$para\">{$this->f($knet)}</cbc:LineExtensionAmount>\n";
            $x .= "  <cac:TaxTotal>\n";
            $x .= "    <cbc:TaxAmount currencyID=\"$para\">{$this->f($kkdv)}</cbc:TaxAmount>\n";
            $x .= "    <cac:TaxSubtotal>\n";
            $x .= "      <cbc:TaxableAmount currencyID=\"$para\">{$this->f($knet)}</cbc:TaxableAmount>\n";
            $x .= "      <cbc:TaxAmount currencyID=\"$para\">{$this->f($kkdv)}</cbc:TaxAmount>\n";
            $x .= "      <cbc:Percent>$koran</cbc:Percent>\n";
            $x .= "      <cac:TaxCategory>\n";
            $x .= "        <cbc:TaxExemptionReasonCode/>\n";
            $x .= "        <cac:TaxScheme><cbc:Name>KDV</cbc:Name><cbc:TaxTypeCode>0015</cbc:TaxTypeCode></cac:TaxScheme>\n";
            $x .= "      </cac:TaxCategory>\n";
            $x .= "    </cac:TaxSubtotal>\n";
            $x .= "  </cac:TaxTotal>\n";
            $x .= "  <cac:Item><cbc:Name>$urun</cbc:Name></cac:Item>\n";
            $x .= "  <cac:Price><cbc:PriceAmount currencyID=\"$para\">" . number_format($fiyat, 4, '.', '') . "</cbc:PriceAmount></cac:Price>\n";
            $x .= "</cac:InvoiceLine>\n";
        }
        $x .= "</Invoice>";

        return ['xml' => $x, 'uuid' => $uuid, 'senaryo' => $senaryo];
    }

    // ── Yardımcı ─────────────────────────────────────────────────────────

    private function checkError($resp, string $islem): void
    {
        if (!empty($resp->ERROR_TYPE->ERROR_CODE)) {
            $kod  = $resp->ERROR_TYPE->ERROR_CODE;
            $acik = $resp->ERROR_TYPE->ERROR_LONG_DES ?? $resp->ERROR_TYPE->ERROR_SHORT_DES ?? 'Bilinmeyen hata';
            throw new RuntimeException("$islem hatası [$kod]: $acik");
        }
    }

    private function e(string $s): string { return htmlspecialchars($s, ENT_XML1, 'UTF-8'); }
    private function f(float $v): string
    {
        // -0.00 çıktısını engelle (Schematron geçersiz değer sayar)
        if (abs($v) < 0.005) $v = 0.0;
        return number_format($v, 2, '.', '');
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    }

    private function birimKodu(string $b): string
    {
        return ['ADET'=>'C62','AD'=>'C62','KG'=>'KGM','KILO'=>'KGM',
                'LITRE'=>'LTR','LT'=>'LTR','METRE'=>'MTR','MT'=>'MTR',
                'SAAT'=>'HUR','GUN'=>'DAY','AY'=>'MON','KUTU'=>'BX','PAKET'=>'PA'
               ][strtoupper(trim($b))] ?? 'C62';
    }

    /**
     * Minimal e-Arşiv XSLT şablonu — XML içine gömülü gönderilir
     */
    private function getEarsivXslt(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
  xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:n1="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
  xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
  xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html>
<head><meta charset="UTF-8"/><title>e-Fatura</title>
<style>
body{font-family:Arial,sans-serif;font-size:12px;margin:20px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #999;padding:5px;text-align:left}
th{background:#f0f0f0}
.header{font-size:16px;font-weight:bold;margin-bottom:10px}
.right{text-align:right}
</style>
</head>
<body>
<div class="header">e-Fatura</div>
<table>
<tr><td><b>Fatura No:</b></td><td><xsl:value-of select="//n1:Invoice/cbc:ID"/></td>
    <td><b>Tarih:</b></td><td><xsl:value-of select="//n1:Invoice/cbc:IssueDate"/></td></tr>
<tr><td><b>ETTN:</b></td><td colspan="3"><xsl:value-of select="//n1:Invoice/cbc:UUID"/></td></tr>
<tr><td><b>Senaryo:</b></td><td><xsl:value-of select="//n1:Invoice/cbc:ProfileID"/></td>
    <td><b>Tip:</b></td><td><xsl:value-of select="//n1:Invoice/cbc:InvoiceTypeCode"/></td></tr>
</table>
<br/>
<table>
<tr><td style="width:50%;vertical-align:top"><b>SATICI</b><br/>
  <xsl:value-of select="//n1:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name"/><br/>
  VKN: <xsl:value-of select="//n1:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID"/></td>
<td style="width:50%;vertical-align:top"><b>ALICI</b><br/>
  <xsl:value-of select="//n1:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name"/><br/>
  VKN: <xsl:value-of select="//n1:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID"/></td></tr>
</table>
<br/>
<table>
<tr><th>#</th><th>Açıklama</th><th>Miktar</th><th>Birim Fiyat</th><th>KDV %</th><th>KDV Tutar</th><th>Toplam</th></tr>
<xsl:for-each select="//n1:Invoice/cac:InvoiceLine">
<tr>
  <td><xsl:value-of select="cbc:ID"/></td>
  <td><xsl:value-of select="cac:Item/cbc:Name"/></td>
  <td class="right"><xsl:value-of select="cbc:InvoicedQuantity"/></td>
  <td class="right"><xsl:value-of select="cac:Price/cbc:PriceAmount"/></td>
  <td class="right"><xsl:value-of select="cac:TaxTotal/cac:TaxSubtotal/cbc:Percent"/></td>
  <td class="right"><xsl:value-of select="cac:TaxTotal/cbc:TaxAmount"/></td>
  <td class="right"><xsl:value-of select="cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount + cac:TaxTotal/cbc:TaxAmount"/></td>
</tr>
</xsl:for-each>
</table>
<br/>
<table>
<tr><td class="right"><b>Mal/Hizmet Toplam:</b></td>
    <td class="right"><xsl:value-of select="//n1:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount"/></td></tr>
<tr><td class="right"><b>KDV Toplam:</b></td>
    <td class="right"><xsl:value-of select="//n1:Invoice/cac:TaxTotal/cbc:TaxAmount"/></td></tr>
<tr><td class="right"><b>Genel Toplam:</b></td>
    <td class="right"><xsl:value-of select="//n1:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount"/></td></tr>
</table>
<xsl:if test="//n1:Invoice/cbc:Note">
<br/><p><b>Not:</b> <xsl:value-of select="//n1:Invoice/cbc:Note"/></p>
</xsl:if>
</body>
</html>
</xsl:template>
</xsl:stylesheet>';
    }
}
