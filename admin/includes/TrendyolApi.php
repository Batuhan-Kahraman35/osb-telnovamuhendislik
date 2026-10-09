<?php
/**
 * Trendyol Marketplace API
 *
 * Kaynak: TamRenk ortak sistemi (TrendyolApi). OrnekFirma uyarlaması 23.09.2026;
 * uç yolları ve alan eşlemeleri canlı test + resmi dokümanla doğrulandı
 * (bkz. e-Ticaret Entegrasyon Bilgileri\#Entegrason Bilgileri\Trendyol.txt).
 *
 * Ortak altyapı PazaryeriApi sınıfındadır. Bu sınıfta yalnızca Trendyol'a özgü
 * olanlar bulunur: uç yolları, yanıt ayrıştırma ve alan eşlemesi.
 *
 * Kanal (OrnekFirma EntegrasyonKanallari):
 *   Kanal_Kullanici = API Key, Kanal_Sifre = API Secret
 *   Kanal_Parametreler = {"satici_id": "...", "user_agent": "{saticiId} - {Firma}"}
 *
 * Tuzaklar (Trendyol.txt / ortak şema):
 *   - Sipariş/soru tarih aralığı en fazla 14 gün; geniş aralık sessizce EKSİK döner.
 *   - orderDate yerel saati UTC gibi taşır → msYerelTarih(); diğer tarihler gerçek UTC.
 *   - Yanıtta shipmentPackages yok; takip no kökte, kargoya veriliş packageHistories'te.
 *   - Kalem alanları: lineUnitPrice / commission / orderLineItemStatusName / vatRate.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class TrendyolApi extends PazaryeriApi
{
    /** Trendyol tek istekte en fazla 1000 öğe kabul eder (ürün ve stok/fiyat) */
    const BATCH_LIMIT = 1000;

    /** Sipariş sorgusunda tarih aralığı en fazla 14 gün */
    const SIPARIS_GUN_SINIRI = 14;

    /** Soru filtresinde güvenli tarih aralığı (geniş aralık sessizce eksik döner) */
    const SORU_GUN_SINIRI = 14;

    /** Ürün V2 sayfa boyutu üst sınırı (page x size <= 10.000) */
    const URUN_SAYFA_BOYUTU = 100;

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('trendyol', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    /** OrnekFirma kanal kolonları → Trendyol anahtarları */
    protected function configEsle()
    {
        $c = &$this->config;
        $c['SaticiId']  = $c['SaticiId']  ?? $c['satici_id'] ?? null;
        // OrnekFirma'da API Key Kanal_Kullanici'dadır (Kanal_ApiKey başka amaçla dolu olabilir)
        $c['ApiKey']    = !empty($c['Kullanici']) ? $c['Kullanici'] : ($c['ApiKey'] ?? null);
        $c['ApiSecret'] = $c['ApiSecret'] ?? $c['Sifre'] ?? null;
    }

    protected function baseUrl($hizmet = 'varsayilan')
    {
        // Yollar "/integration/..." ile yazılır; OrnekFirma Entegrasyon_BaseUrl'i zaten
        // "/integration" ile bitiyorsa önek iki kez eklenmesin (tuzak 14).
        $url = rtrim($this->config['BaseURL'] ?? 'https://apigw.trendyol.com', '/');
        return preg_replace('#/integration$#i', '', $url);
    }

    protected function authHeaders()
    {
        $token    = base64_encode(($this->config['ApiKey'] ?? '') . ':' . ($this->config['ApiSecret'] ?? ''));
        $saticiId = trim((string)($this->config['SaticiId'] ?? ''));

        // User-Agent zorunlu: "{SaticiId} - {Firma}" (eksik/hatalıysa 403)
        $userAgent = trim((string)($this->config['user_agent'] ?? ''));
        if ($userAgent === '') {
            $referans  = trim((string)($this->config['EntegrasyonReferansKodu'] ?? ''));
            $userAgent = $saticiId . ' - ' . ($referans !== '' ? $referans : 'SelfIntegration');
        }

        return [
            'Authorization: Basic ' . $token,
            'User-Agent: ' . $userAgent
        ];
    }

    protected function configDogrula()
    {
        if (empty($this->config['SaticiId'])) {
            return 'Trendyol kanal parametrelerinde satici_id tanımlı değil.';
        }
        if (empty($this->config['ApiKey']) || empty($this->config['ApiSecret'])) {
            return 'Trendyol kanalında API Key (Kanal_Kullanici) / API Secret (Kanal_Sifre) tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'Kanal_Kullanici', 'etiket' => 'API Key',    'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Satıcı Paneli > Hesap Bilgilerim > Entegrasyon Bilgileri'],
            ['anahtar' => 'Kanal_Sifre',     'etiket' => 'API Secret', 'gizli' => true,  'zorunlu' => true, 'aciklama' => ''],
            ['anahtar' => 'satici_id',       'etiket' => 'Satıcı ID',  'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Kanal_Parametreler JSON'],
            ['anahtar' => 'user_agent',      'etiket' => 'User-Agent', 'gizli' => false, 'zorunlu' => false,
             'aciklama' => '"{SaticiId} - {Firma}" (Kanal_Parametreler JSON)'],
        ];
    }

    /** Trendyol hata gövdesi: errors[] | error.errors[].title */
    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if ($httpKod === 401 || $httpKod === 403) {
            return 'HTTP ' . $httpKod . ': Yetkisiz erişim. API Key/Secret, Satıcı ID '
                 . 've User-Agent bilgilerini kontrol edin.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    private function saticiId()
    {
        return rawurlencode((string)$this->config['SaticiId']);
    }

    /** Ürün uçlarının ortak öneki */
    private function urunYolu($ek = '')
    {
        return '/integration/product/sellers/' . $this->saticiId() . $ek;
    }

    /** Sipariş, iade ve paket uçlarının ortak öneki */
    private function siparisYolu($ek = '')
    {
        return '/integration/order/sellers/' . $this->saticiId() . $ek;
    }

    /** Satıcı düzeyindeki uçlar (fatura linki, ortak etiket, adresler) */
    private function saticiYolu($ek = '')
    {
        return '/integration/sellers/' . $this->saticiId() . $ek;
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $sonuc = $this->istek('GET', $this->saticiYolu('/addresses'), null, [], 'Bağlantı Testi');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        $adresler = $sonuc['veri']['supplierAddresses'] ?? [];
        return [
            'basarili' => true,
            'mesaj'    => 'Bağlantı başarılı. Tanımlı adres sayısı: ' . count($adresler),
            'veri'     => $sonuc['veri']
        ];
    }

    // =================================================================
    // Ürünler (Product V2)
    // =================================================================

    /**
     * Ürünleri listele. $filtre['onay'] = 'approved' | 'unapproved' (varsayılan approved).
     * approved: bir kayıt = bir MODEL, barkodlar variants[] içinde.
     * unapproved: bir kayıt = bir barkod (düz yapı), status pendingApproval | rejected.
     */
    public function urunleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $onay = $filtre['onay'] ?? 'approved';
        unset($filtre['onay']);
        if (!in_array($onay, ['approved', 'unapproved'], true)) {
            $onay = 'approved';
        }

        $query = array_merge($filtre, [
            'page' => (int)$sayfa,
            'size' => min(self::URUN_SAYFA_BOYUTU, (int)$boyut)
        ]);

        $sonuc = $this->istek('GET', $this->urunYolu('/products/' . $onay), null, $query, 'Ürün Listele');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'urunler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }

        return [
            'basarili'    => true,
            'hata'        => '',
            'urunler'     => $sonuc['veri']['content'] ?? [],
            'toplam'      => (int)($sonuc['veri']['totalElements'] ?? 0),
            'sayfa'       => (int)($sonuc['veri']['page'] ?? 0),
            'toplamSayfa' => (int)($sonuc['veri']['totalPages'] ?? 0)
        ];
    }

    /** Onaylı + onaysız tüm ürünleri çekip PazaryeriUrunleri'ne yazar */
    public function urunleriSenkronla()
    {
        $adet = 0; $yeni = 0; $guncellenen = 0; $hata = 0; $model = 0;

        foreach (['approved', 'unapproved'] as $onay) {
            $sayfa = 0;
            do {
                $sonuc = $this->urunleriListele(['onay' => $onay], $sayfa, self::URUN_SAYFA_BOYUTU);
                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $adet];
                }

                foreach ($sonuc['urunler'] as $kayit) {
                    $model++;
                    foreach ($this->urunBirimleri($kayit, $onay) as $birim) {
                        $adet++;
                        $r = $this->urunKaydet($birim);
                        if ($r === 'yeni')            { $yeni++; }
                        elseif ($r === 'guncellendi') { $guncellenen++; }
                        else                          { $hata++; }
                    }
                }

                $sayfa++;
            } while ($sayfa < (int)($sonuc['toplamSayfa'] ?? 0)
                     && $sayfa * self::URUN_SAYFA_BOYUTU < 10000);
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'yeni' => $yeni,
                'guncellenen' => $guncellenen, 'hatali' => $hata, 'model' => $model];
    }

    /**
     * V2 kaydını satılabilir birimlere (barkod) açar → nötr ürün listesi.
     * approved model varyant sayısı kadar, unapproved kayıt tek öğe üretir (tuzak 19).
     */
    private function urunBirimleri(array $kayit, $onay)
    {
        $modelAttr = $kayit['attributes'] ?? [];
        $marka     = $kayit['brand']    ?? null;
        $kategori  = $kayit['category'] ?? null;

        $ortak = [
            'anaUrunKodu' => $kayit['productMainId'] ?? null,
            'disUrunKodu' => $kayit['contentId'] ?? null,
            'adi'         => $kayit['title'] ?? null,
            'markaAdi'    => is_array($marka)    ? ($marka['name'] ?? null)    : $marka,
            'kategoriAdi' => is_array($kategori) ? ($kategori['name'] ?? null) : $kategori,
            'gorselUrl'   => $kayit['images'][0]['url'] ?? null,
        ];

        if ($onay === 'unapproved') {
            $durum = strtolower((string)($kayit['status'] ?? ''));
            return [$ortak + [
                'barkod'     => $kayit['barcode']   ?? null,
                'stokKodu'   => $kayit['stockCode'] ?? null,
                'disUrunId'  => $kayit['barcode']   ?? null,
                'renk'       => $this->ozellikDegeri($modelAttr, 'renk'),
                'listeFiyat' => $kayit['listPrice'] ?? null,
                'satisFiyat' => $kayit['salePrice'] ?? $kayit['listPrice'] ?? null,
                'stok'       => $kayit['quantity']  ?? 0,
                'kdvOran'    => $kayit['vatRate']   ?? null,
                'onayli'     => false,
                'redSebebi'  => !empty($kayit['rejectReasonDetails'])
                                ? json_encode($kayit['rejectReasonDetails'], JSON_UNESCAPED_UNICODE) : null,
                'hamDurum'   => $durum === 'rejected' ? 'rejected' : 'pendingApproval',
                'ham'        => $kayit,
            ]];
        }

        $model = $kayit;
        unset($model['variants']);

        $birimler = [];
        foreach ($kayit['variants'] ?? [] as $v) {
            $attrs = array_merge($modelAttr, $v['attributes'] ?? []);
            $birimler[] = $ortak + [
                'barkod'        => $v['barcode']   ?? null,
                'stokKodu'      => $v['stockCode'] ?? null,
                'disUrunId'     => $v['variantId'] ?? null,
                'urunUrl'       => $v['productUrl'] ?? null,
                'varyantDegeri' => $this->ozellikDegeri($v['attributes'] ?? [], 'beden'),
                'renk'          => $this->ozellikDegeri($attrs, 'renk'),
                'listeFiyat'    => $v['price']['listPrice'] ?? null,
                'satisFiyat'    => $v['price']['salePrice'] ?? $v['price']['listPrice'] ?? null,
                'stok'          => $v['stock']['quantity']  ?? 0,
                'kdvOran'       => $v['vatRate'] ?? null,
                'onayli'        => true,
                'hamDurum'      => !empty($v['archived']) ? 'archived' : (!empty($v['onSale']) ? 'onSale' : 'notOnSale'),
                'ham'           => array_merge($model, ['variant' => $v]),
            ];
        }
        return $birimler;
    }

    /** Öznitelik listesinden ada göre değer (Renk, Beden...) */
    private function ozellikDegeri(array $attrs, $ad)
    {
        foreach ($attrs as $a) {
            if (mb_strtolower((string)($a['attributeName'] ?? '')) === $ad) {
                return $a['attributeValue'] ?? null;
            }
        }
        return null;
    }

    /**
     * Fiyat ve stok güncelle (inventory ucu — kapanmıyor).
     * Kural: tek istekte max 1000 SKU, ürün başına max 20.000 stok,
     * aynı gövde 15 dk içinde tekrar gönderilemez. Sonuç batch ile takip edilir.
     *
     * @param array $items [{barcode, quantity, salePrice, listPrice}]
     */
    public function fiyatStokGuncelle(array $items)
    {
        return $this->urunIstegiGonder('POST',
            '/integration/inventory/sellers/' . $this->saticiId() . '/products/price-and-inventory',
            $items, 'fiyat_stok', 'Fiyat/Stok Güncelle');
    }

    private function urunIstegiGonder($method, $yol, array $items, $tip, $islem)
    {
        if (empty($items)) {
            return ['basarili' => false, 'batchId' => '', 'hata' => 'Gönderilecek ürün yok.'];
        }
        if (count($items) > self::BATCH_LIMIT) {
            return ['basarili' => false, 'batchId' => '',
                    'hata' => 'Tek istekte en fazla ' . self::BATCH_LIMIT . ' ürün gönderilebilir.'];
        }

        $sonuc = $this->istek($method, $yol, ['items' => array_values($items)], [], $islem);

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'batchId' => '', 'hata' => $sonuc['hata']];
        }

        $batchId = (string)($sonuc['veri']['batchRequestId'] ?? '');
        if ($batchId === '') {
            return ['basarili' => false, 'batchId' => '', 'hata' => 'Trendyol batchRequestId döndürmedi.'];
        }

        $this->batchKaydet($batchId, $tip, count($items));

        return ['basarili' => true, 'batchId' => $batchId, 'hata' => ''];
    }

    /** Batch sonucunu sorgula; kayıt güncellemesini ortak katman yapar */
    public function batchDurumGuncelle($batchId)
    {
        $batchId = trim((string)$batchId);
        if ($batchId === '') {
            return ['basarili' => false, 'hata' => 'Batch ID boş.'];
        }

        $sonuc = $this->istek('GET', $this->urunYolu('/products/batch-requests/' . rawurlencode($batchId)),
                              null, [], 'Batch Durum Sorgu');

        if (!$sonuc['basarili']) {
            $simdi = date('Y-m-d H:i:s');
            $this->db->update('PazaryeriBatchIstekleri', [
                'PazaryeriBatchIstekleri_SonKontrol' => $simdi,
                'GuncellemeTarihi'                   => $simdi
            ], [
                'kanal_id'                        => $this->kanalId,
                'PazaryeriBatchIstekleri_BatchId' => $batchId
            ]);
            return ['basarili' => false, 'hata' => $sonuc['hata']];
        }

        $veri  = $sonuc['veri'] ?? [];
        $items = $veri['items'] ?? [];

        $kalemler = [];
        foreach ($items as $item) {
            $barkod = (string)($item['requestItem']['barcode'] ?? '');
            if ($barkod === '') {
                continue;
            }
            $mesajlar = [];
            foreach ($item['failureReasons'] ?? [] as $sebep) {
                $mesajlar[] = is_array($sebep)
                    ? ($sebep['message'] ?? json_encode($sebep, JSON_UNESCAPED_UNICODE))
                    : (string)$sebep;
            }
            $kalemler[] = [
                'barkod'   => $barkod,
                'basarili' => strtoupper((string)($item['status'] ?? '')) === 'SUCCESS',
                'hata'     => implode(' | ', $mesajlar)
            ];
        }

        // items boşken Trendyol henüz işlemeye başlamamıştır → beklemede kalır
        $tamamlandi = !empty($items) && strtoupper((string)($veri['status'] ?? '')) !== 'PROCESSING';

        return $this->batchSonucIsle($batchId, ['tamamlandi' => $tamamlandi, 'kalemler' => $kalemler]);
    }

    // =================================================================
    // Siparişler (Order V2 — V1 /orders 15.10.2026'da kapanıyor)
    // =================================================================

    /**
     * @param array $filtre status, orderNumber, startDate/endDate (ms epoch, max 14 gün)
     * @param int   $boyut  en fazla 200
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $query = array_merge($filtre, [
            'page' => (int)$sayfa,
            'size' => min(200, (int)$boyut)
        ]);

        $sonuc = $this->istek('GET', $this->siparisYolu('/v2/orders'), null, $query, 'Sipariş Listele');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'],
                    'siparisler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }

        return [
            'basarili'    => true,
            'hata'        => '',
            'siparisler'  => $sonuc['veri']['content'] ?? [],
            'toplam'      => (int)($sonuc['veri']['totalElements'] ?? 0),
            'sayfa'       => (int)($sonuc['veri']['page'] ?? 0),
            'toplamSayfa' => (int)($sonuc['veri']['totalPages'] ?? 0)
        ];
    }

    /**
     * Siparişleri çek ve veritabanına yaz. Aralık 14 günlük dilimlere bölünür.
     * Filtre paketin SON GÜNCELLENME tarihine göre çalışır; eski siparişler statü
     * değişince yeniden gelir ve güncellenir.
     */
    public function siparisleriSenkronla($gunSayisi = 2)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $adet = 0; $yeni = 0; $hatali = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SIPARIS_GUN_SINIRI) as $dilim) {
            $sayfa = 0;
            do {
                $sonuc = $this->siparisleriListele([
                    'startDate' => $dilim['bas'] * 1000,
                    'endDate'   => $dilim['bit'] * 1000
                ], $sayfa, 200);

                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $adet];
                }

                foreach ($sonuc['siparisler'] as $s) {
                    $notr  = $this->siparisNotrle($s);
                    $vardi = $this->db->fetchOne(
                        "SELECT 1 AS v FROM dbo.PazaryeriSiparisleri WHERE kanal_id = ? AND PazaryeriSiparisleri_DisId = ?",
                        [$this->kanalId, $notr['disId']]
                    );
                    if ($this->siparisKaydet($notr)) {
                        $adet++;
                        if (!$vardi) { $yeni++; }
                    } else {
                        $hatali++;
                    }
                }

                $sayfa++;
            } while ($sayfa < (int)($sonuc['toplamSayfa'] ?? 0));
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'yeni' => $yeni, 'hatali' => $hatali];
    }

    /**
     * Trendyol paketi → nötr sipariş.
     * Birim PAKETtir (disId = shipmentPackageId): bölünmüş siparişin paketleri ayrı satırdır.
     */
    private function siparisNotrle(array $s)
    {
        $adres  = $s['shipmentAddress'] ?? ($s['invoiceAddress'] ?? []);
        $fatura = $s['invoiceAddress'] ?? [];

        $kalemler = [];
        foreach ($s['lines'] ?? [] as $line) {
            $miktar = (int)($line['quantity'] ?? 1);
            $birim  = $line['lineUnitPrice'] ?? $line['price'] ?? null;   // indirim sonrası birim fiyat
            $kalemler[] = [
                'disId'        => (string)($line['lineId'] ?? $line['id'] ?? ''),
                'barkod'       => $line['barcode'] ?? null,
                'stokKodu'     => $this->stokKoduAl($line),
                'disUrunId'    => (string)($line['contentId'] ?? $line['productCode'] ?? ''),
                'urunAdi'      => $line['productName'] ?? null,
                'miktar'       => $miktar,
                'birimFiyat'   => $birim,
                'toplamTutar'  => $birim !== null ? round((float)$birim * $miktar, 2) : null,
                'indirimTutar' => $line['lineTotalDiscount'] ?? $line['discount'] ?? null,
                'kdvOran'      => $line['vatRate'] ?? null,
                'komisyonOran' => $line['commission'] ?? $line['commissionRate'] ?? null,
                'hamDurum'     => $line['orderLineItemStatusName'] ?? null,
                'ham'          => $line
            ];
        }

        // Kargoya veriliş: paket geçmişindeki ilk Shipped kaydı (gerçek UTC)
        $kargoyaVerilme = null;
        foreach ($s['packageHistories'] ?? [] as $h) {
            if (($h['status'] ?? '') === 'Shipped' && !empty($h['createdDate'])) {
                $kargoyaVerilme = $this->msTarih($h['createdDate']);
                break;
            }
        }

        $musteri = trim(($s['customerFirstName'] ?? '') . ' ' . ($s['customerLastName'] ?? ''));
        $alici   = trim(($adres['firstName'] ?? '') . ' ' . ($adres['lastName'] ?? ''));
        $paketNo = (string)($s['shipmentPackageId'] ?? $s['id'] ?? '');

        return [
            'disId'          => $paketNo !== '' ? $paketNo : (string)($s['orderNumber'] ?? ''),
            'siparisNo'      => (string)($s['orderNumber'] ?? ''),
            'paketNo'        => $paketNo,
            'durumKod'       => $this->siparisDurumKodu($s['status'] ?? ''),
            'hamDurum'       => $s['status'] ?? null,
            'toplamTutar'    => $s['totalPrice'] ?? null,
            'paraBirimi'     => $s['currencyCode'] ?? 'TRY',
            'musteriAdi'     => $musteri ?: ($alici ?: null),
            'aliciAdi'       => $alici ?: null,
            'eposta'         => $s['customerEmail'] ?? null,
            'telefon'        => $adres['phone'] ?? null,      // KVKK: genelde boş
            'tckn'           => $s['tcIdentityNumber'] ?? null,
            'vergiNo'        => $fatura['taxNumber'] ?? null,
            'vergiDairesi'   => $fatura['taxOffice'] ?? null,
            'il'             => $adres['city']     ?? null,
            'ilce'           => $adres['district'] ?? null,
            'semt'           => $adres['neighborhood'] ?? null,
            'teslimatAdresi' => $adres,
            'faturaAdresi'   => $fatura,
            'kargoFirmasi'   => $s['cargoProviderName'] ?? null,
            'kargoTakipNo'   => isset($s['cargoTrackingNumber']) ? (string)$s['cargoTrackingNumber'] : null,
            'kargoBarkod'    => $s['cargoTrackingLink'] ?? null,
            'kargoyaVerilme' => $kargoyaVerilme,
            'siparisTarihi'  => $this->msYerelTarih($s['orderDate'] ?? null),   // tuzak 22
            'terminTarihi'   => $this->msTarih($s['agreedDeliveryDate'] ?? null),
            'tahminiTeslim'  => $this->msTarih($s['estimatedDeliveryEndDate'] ?? null),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /**
     * Kalem stok kodu. Üründe stok kodu boşsa Trendyol stockCode ve merchantSku alanlarına
     * "merchantSku" yer tutucu METNİNİ yazar (23.09.2026 canlı) → boş kabul edilir.
     */
    private function stokKoduAl(array $line)
    {
        foreach (['stockCode', 'merchantSku'] as $alan) {
            $deger = trim((string)($line[$alan] ?? ''));
            if ($deger !== '' && strcasecmp($deger, 'merchantSku') !== 0) {
                return $deger;
            }
        }
        return null;
    }

    /** Trendyol sipariş durumu → yerel normalize kod */
    private function siparisDurumKodu($durum)
    {
        switch (strtolower((string)$durum)) {
            case 'created':    case 'awaiting':   return 'yeni';
            case 'picking':    case 'invoiced':   return 'hazirlaniyor';
            case 'shipped':                       return 'kargoda';
            case 'delivered':                     return 'teslim';
            case 'cancelled':  case 'unsupplied': return 'iptal';
            case 'returned':                      return 'iade';
            default:                              return 'diger';
        }
    }

    // =================================================================
    // Paket işlemleri (durum, fatura, kargo etiketi)
    // =================================================================

    /**
     * Paket statüsünü güncelle. Sıra: önce Picking, sonra Invoiced (opsiyonel).
     * Paket Shipped/Delivered ise Trendyol reddeder (beklenen durum).
     *
     * @param string $durum    Picking | Invoiced
     * @param array  $kalemler [['lineId'=>int,'quantity'=>int], ...]
     */
    public function paketDurumGuncelle($paketId, $durum, array $kalemler = [], array $ek = [])
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Paket ID boş.'];
        }

        // NOT: storeFrontCode başlığı Trendyol tarafında geçersiz sayılıyor, gönderilmez.
        $sonuc = $this->istek('PUT',
            $this->siparisYolu('/shipment-packages/' . rawurlencode((string)$paketId)),
            ['status' => $durum, 'lines' => $kalemler, 'params' => (object)$ek],
            [], 'Paket Durum Güncelle');

        return [
            'basarili' => $sonuc['basarili'],
            'hata'     => $sonuc['hata'],
            'mesaj'    => $sonuc['basarili'] ? "Paket statüsü '{$durum}' olarak güncellendi." : ''
        ];
    }

    /**
     * e-Fatura / e-Arşiv bağlantısını Trendyol'a bildir.
     * POST /integration/sellers/{id}/seller-invoice-links (201; aynı pakete tekrar → 409)
     *
     * @param array $fatura ['link'=>, 'numara'=>, 'tarih'=> ms epoch]
     */
    public function faturaLinkGonder($paketId, array $fatura)
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Paket ID boş.'];
        }

        $sonuc = $this->istek('POST', $this->saticiYolu('/seller-invoice-links'), [
            'invoiceLink'       => $fatura['link']   ?? '',
            'shipmentPackageId' => (int)$paketId,
            'invoiceNumber'     => $fatura['numara'] ?? null,
            'invoiceDateTime'   => isset($fatura['tarih']) ? (int)$fatura['tarih'] : null
        ], [], 'Fatura Linki Gönder');

        return ['basarili' => $sonuc['basarili'], 'hata' => $sonuc['hata']];
    }

    /**
     * Ortak kargo etiketi talebi (yalnız TEX / Aras, Trendyol ödemeli; hesap yetkisi gerekir).
     * POST /integration/sellers/{id}/common-label/{cargoTrackingNumber}
     */
    public function kargoEtiketiOlustur($kargoTakipNo)
    {
        if (!$kargoTakipNo) {
            return ['basarili' => false, 'hata' => 'Kargo takip numarası boş.'];
        }

        $sonuc = $this->istek('POST',
            $this->saticiYolu('/common-label/' . rawurlencode((string)$kargoTakipNo)),
            ['format' => 'ZPL', 'boxQuantity' => 1], [], 'Kargo Etiketi Oluştur');

        return ['basarili' => $sonuc['basarili'], 'hata' => $sonuc['hata']];
    }

    /** Etiketin ZPL içeriği: {"data":[{"label":"^XA...^XZ","format":"ZPL"}]} */
    public function kargoEtiketiGetir($kargoTakipNo)
    {
        if (!$kargoTakipNo) {
            return ['basarili' => false, 'hata' => 'Kargo takip numarası boş.', 'zpl' => null];
        }

        $sonuc = $this->istek('GET',
            $this->saticiYolu('/common-label/' . rawurlencode((string)$kargoTakipNo)),
            null, [], 'Kargo Etiketi Getir');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'zpl' => null];
        }

        $etiketler = array_filter(array_column($sonuc['veri']['data'] ?? [], 'label'));
        $zpl = $etiketler ? implode("\n", $etiketler) : null;

        return ['basarili' => (bool)$zpl, 'zpl' => $zpl, 'hata' => $zpl ? '' : 'ZPL içeriği alınamadı.'];
    }

    // =================================================================
    // İadeler
    // =================================================================

    public function iadeleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $query = array_merge($filtre, ['page' => (int)$sayfa, 'size' => (int)$boyut]);

        $sonuc = $this->istek('GET', $this->siparisYolu('/claims'), null, $query, 'İade Listele');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'iadeler' => [], 'toplam' => 0];
        }

        $veri = $sonuc['veri'] ?? [];
        return [
            'basarili'    => true,
            'hata'        => '',
            'iadeler'     => $veri['content'] ?? [],
            'toplam'      => (int)($veri['totalElements'] ?? count($veri['content'] ?? [])),
            'toplamSayfa' => (int)($veri['totalPages'] ?? 1)
        ];
    }

    /** İadeleri çek ve veritabanına yaz */
    public function iadeleriSenkronla($gunSayisi = 30)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $adet      = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SIPARIS_GUN_SINIRI) as $dilim) {
            $sayfa = 0;
            do {
                $sonuc = $this->iadeleriListele([
                    'startDate' => $dilim['bas'] * 1000,
                    'endDate'   => $dilim['bit'] * 1000
                ], $sayfa, 50);

                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $adet];
                }

                foreach ($sonuc['iadeler'] as $i) {
                    if ($this->iadeKaydet($this->iadeNotrle($i))) {
                        $adet++;
                    }
                }

                $sayfa++;
            } while ($sayfa < (int)($sonuc['toplamSayfa'] ?? 0));
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $adet];
    }

    /** Trendyol iadesi → nötr iade (kalemler items[].claimItems[] içinde) */
    private function iadeNotrle(array $i)
    {
        $kalemler = [];
        $ilk      = null;
        foreach ($i['items'] ?? [] as $item) {
            $satir = $item['orderLine'] ?? [];
            foreach ($item['claimItems'] ?? [] as $ci) {
                $ilk = $ilk ?? $ci;
                $kalemler[] = [
                    'barkod'  => $satir['barcode']     ?? null,
                    'urunAdi' => $satir['productName'] ?? null,
                    'miktar'  => 1,
                    'fiyat'   => $satir['price'] ?? null,
                    'sebep'   => $ci['customerClaimItemReason']['name'] ?? ($ci['trendyolClaimItemReason']['name'] ?? null),
                    'durum'   => $ci['claimItemStatus']['name'] ?? null,
                ];
            }
        }

        $durum   = $ilk['claimItemStatus']['name'] ?? ($i['status'] ?? '');
        $musteri = trim(($i['customerFirstName'] ?? '') . ' ' . ($i['customerLastName'] ?? ''));

        return [
            'disId'      => (string)($i['id'] ?? ($i['claimId'] ?? '')),
            'siparisNo'  => (string)($i['orderNumber'] ?? ''),
            'paketNo'    => (string)($i['orderShipmentPackageId'] ?? ''),
            'durumKod'   => $this->iadeDurumKodu($durum),
            'hamDurum'   => $durum ?: null,
            'sebep'      => $kalemler[0]['sebep'] ?? null,
            'aciklama'   => $ilk['customerNote'] ?? null,
            'musteriAdi' => $musteri ?: null,
            'tutar'      => array_sum(array_map(fn($k) => (float)($k['fiyat'] ?? 0), $kalemler)) ?: null,
            'iadeTarihi' => $this->msTarih($i['claimDate'] ?? null),
            'kalemler'   => $kalemler,
            'ham'        => $i
        ];
    }

    private function iadeDurumKodu($durum)
    {
        $d = strtolower((string)$durum);
        if (strpos($d, 'waiting') !== false || strpos($d, 'created') !== false) return 'acik';
        if (strpos($d, 'accept')  !== false || strpos($d, 'approved') !== false) return 'onaylandi';
        if (strpos($d, 'reject')  !== false || strpos($d, 'unresolved') !== false) return 'reddedildi';
        if (strpos($d, 'cancel')  !== false)                                     return 'iptal';
        return 'diger';
    }

    // =================================================================
    // Müşteri soruları
    // =================================================================

    public function sorulariListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $query = array_merge($filtre, ['page' => (int)$sayfa, 'size' => (int)$boyut]);

        $sonuc = $this->istek('GET', '/integration/qna/sellers/' . $this->saticiId() . '/questions/filter',
                              null, $query, 'Müşteri Soruları');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'sorular' => [], 'toplam' => 0];
        }

        return [
            'basarili'    => true,
            'hata'        => '',
            'sorular'     => $sonuc['veri']['content'] ?? [],
            'toplam'      => (int)($sonuc['veri']['totalElements'] ?? 0),
            'toplamSayfa' => (int)($sonuc['veri']['totalPages'] ?? 0)
        ];
    }

    /** Soruları 14 günlük dilimlerle çek ve veritabanına yaz */
    public function sorulariSenkronla($gunSayisi = 30)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $adet      = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SORU_GUN_SINIRI) as $dilim) {
            $sayfa = 0;
            do {
                $sonuc = $this->sorulariListele([
                    'startDate' => $dilim['bas'] * 1000,
                    'endDate'   => $dilim['bit'] * 1000
                ], $sayfa, 50);

                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $adet];
                }

                foreach ($sonuc['sorular'] as $q) {
                    if ($this->soruKaydet($this->soruNotrle($q))) {
                        $adet++;
                    }
                }

                $sayfa++;
            } while ($sayfa < (int)($sonuc['toplamSayfa'] ?? 0));
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $adet];
    }

    /** Trendyol sorusu → nötr soru ("answer" tekil nesne; ortak şema kaydı) */
    private function soruNotrle(array $q)
    {
        $cevap = $q['answer'] ?? null;

        return [
            'disId'       => (string)($q['id'] ?? ''),
            'disUrunId'   => (string)($q['productMainId'] ?? $q['productId'] ?? ''),
            'urunAdi'     => $q['productName']  ?? null,
            'musteriAdi'  => $q['userName'] ?? $q['customerName'] ?? null,
            'konu'        => $q['subject']      ?? null,
            'soru'        => $q['text']         ?? null,
            'cevap'       => is_array($cevap) ? ($cevap['text'] ?? null) : null,
            'durumKod'    => $this->soruDurumKodu($q['status'] ?? ''),
            'hamDurum'    => $q['status'] ?? null,
            'soruTarihi'  => $this->msTarih($q['creationDate'] ?? null),
            'cevapTarihi' => is_array($cevap) ? $this->msTarih($cevap['creationDate'] ?? null) : null,
            'gorseller'   => $q['imageUrls'] ?? [],
            'ham'         => $q
        ];
    }

    private function soruDurumKodu($durum)
    {
        $d = strtoupper((string)$durum);
        if ($d === 'WAITING_FOR_ANSWER' || $d === 'WAITING_FOR_APPROVE') return 'acik';
        if ($d === 'ANSWERED' || $d === 'REPORTED')                      return 'cevaplandi';
        if ($d === 'REJECTED' || $d === 'UNANSWERED')                    return 'kapali';
        return 'acik';
    }

    /**
     * Müşteri sorusunu cevapla. Kural: 10-2000 karakter, yalnız WAITING_FOR_ANSWER, tek seferlik.
     */
    public function soruCevapla($soruDisId, $cevap)
    {
        $cevap = trim((string)$cevap);
        if ((string)$soruDisId === '' || $cevap === '') {
            return ['basarili' => false, 'hata' => 'Soru ID veya cevap boş.'];
        }
        $uzunluk = mb_strlen($cevap);
        if ($uzunluk < 10 || $uzunluk > 2000) {
            return ['basarili' => false, 'hata' => 'Cevap 10-2000 karakter arasında olmalıdır.'];
        }

        $sonuc = $this->istek('POST',
            '/integration/qna/sellers/' . $this->saticiId() . '/questions/' . rawurlencode((string)$soruDisId) . '/answers',
            ['text' => $cevap], [], 'Soru Cevapla');

        if ($sonuc['basarili']) {
            $simdi = date('Y-m-d H:i:s');
            $this->db->update('PazaryeriSorulari', [
                'PazaryeriSorulari_Cevap'               => $cevap,
                'PazaryeriSorulari_CevapTarihi'         => $simdi,
                'PazaryeriSorulari_DurumKod'            => 'cevaplandi',
                'PazaryeriSorulari_CevaplayanKullanici' => $this->userId,
                'GuncelleyenKullanici'                  => $this->userId,
                'GuncellemeTarihi'                      => $simdi
            ], [
                'kanal_id'                => $this->kanalId,
                'PazaryeriSorulari_DisId' => (string)$soruDisId
            ]);
        }

        return ['basarili' => $sonuc['basarili'], 'hata' => $sonuc['hata']];
    }
}
