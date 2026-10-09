<?php
/**
 * Hepsiburada Marketplace API
 *
 * Kaynak: TamRenk ortak sistemi (HepsiburadaApi). OrnekFirma uyarlaması 24.09.2026;
 * uç yolları ve alan adları canlı GET testleriyle doğrulandı
 * (bkz. e-Ticaret Entegrasyon Bilgileri\OrnekFirma\HepsiBurada.txt).
 *
 * Kanal (OrnekFirma EntegrasyonKanallari):
 *   Kanal_Kullanici = Merchant ID, Kanal_Sifre = Secret Key
 *   Kanal_Parametreler = {"user_agent": "ornekfirma_dev"}  (+ isteğe bağlı taban adresler)
 *
 * Tuzaklar (24.09.2026 canlı):
 *   - Üç ayrı servis adresi: katalog (mpop), listing, sipariş (oms).
 *   - /packages/merchantid/{id} yalnız kargoya hazır paketleri döner; teslim edilen,
 *     kargodaki, iptal edilen siparişler AYRI uçlardadır. Liste uçları yalnız sipariş/paket
 *     numarası verir; tüm bilgi /orders/merchantid/{id}/ordernumber/{no} ucundadır.
 *   - Durum listeleri (delivered vb.) yaklaşık son 1 ayı döner; eski sipariş yalnız
 *     numarasıyla detay ucundan alınabilir.
 *   - Z eki olmayan tarihler Türkiye saatidir (Z ekli olanlar UTC) → isoTarih() doğru çevirir.
 *   - Tutarlar {currency, amount} nesnesidir.
 *   - Barkod yalnız katalogdadır; listing kaydı katalogla hbSku üzerinden eşleşir.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class HepsiburadaApi extends PazaryeriApi
{
    /** Liste uçlarında sayfa boyutu */
    const SAYFA_BOYUTU = 50;

    /** Katalog sayfa boyutu */
    const KATALOG_SAYFA_BOYUTU = 100;

    /** Sonsuz döngü koruması: liste başına en fazla sayfa */
    const MAKS_SAYFA = 40;

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('hepsiburada', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    /** OrnekFirma kanal kolonları → Hepsiburada anahtarları */
    protected function configEsle()
    {
        $c = &$this->config;
        $c['MerchantId'] = $c['MerchantId'] ?? $c['merchant_id'] ?? $c['Kullanici'] ?? null;
        $c['SecretKey']  = $c['SecretKey']  ?? $c['Sifre'] ?? null;
        $c['UserAgent']  = $c['UserAgent']  ?? $c['user_agent'] ?? null;
    }

    protected function baseUrl($hizmet = 'varsayilan')
    {
        switch ($hizmet) {
            case 'katalog': return $this->getConfig('katalog_url', 'https://mpop.hepsiburada.com');
            case 'listing': return $this->getConfig('listing_url', 'https://listing-external.hepsiburada.com');
            default:        return $this->getConfig('siparis_url', 'https://oms-external.hepsiburada.com');
        }
    }

    protected function authHeaders()
    {
        $token = base64_encode(($this->config['MerchantId'] ?? '') . ':' . ($this->config['SecretKey'] ?? ''));
        return [
            'Authorization: Basic ' . $token,
            'User-Agent: ' . ($this->config['UserAgent'] ?? '')
        ];
    }

    protected function configDogrula()
    {
        if (empty($this->config['MerchantId']) || empty($this->config['SecretKey'])) {
            return 'Hepsiburada kanalında Merchant ID (Kanal_Kullanici) / Secret Key (Kanal_Sifre) tanımlı değil.';
        }
        if (empty($this->config['UserAgent'])) {
            return 'Hepsiburada kanal parametrelerinde user_agent tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'Kanal_Kullanici', 'etiket' => 'Merchant ID', 'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Mağaza ID (GUID)'],
            ['anahtar' => 'Kanal_Sifre',     'etiket' => 'Secret Key',  'gizli' => true,  'zorunlu' => true, 'aciklama' => ''],
            ['anahtar' => 'user_agent',      'etiket' => 'User-Agent',  'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Entegratör (developer) kullanıcı adı (Kanal_Parametreler JSON)'],
        ];
    }

    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if ($httpKod === 401 || $httpKod === 403) {
            return 'HTTP ' . $httpKod . ': Yetkisiz erişim. Merchant ID, Secret Key ve User-Agent bilgilerini kontrol edin.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    private function merchantId()
    {
        return rawurlencode((string)$this->config['MerchantId']);
    }

    /** {currency, amount} → float */
    private function tutar($alan)
    {
        if (is_array($alan)) {
            return isset($alan['amount']) ? (float)$alan['amount'] : null;
        }
        return is_numeric($alan) ? (float)$alan : null;
    }

    /** Yanıt gövdesindeki liste: {items|data|listings: [...]} veya düz dizi */
    private function liste($veri)
    {
        if (!is_array($veri)) {
            return [];
        }
        foreach (['items', 'data', 'listings'] as $anahtar) {
            if (array_key_exists($anahtar, $veri)) {
                return is_array($veri[$anahtar]) ? $veri[$anahtar] : [];
            }
        }
        return array_is_list($veri) ? $veri : [];
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $sonuc = $this->istek('GET', '/listings/merchantid/' . $this->merchantId(),
                              null, ['offset' => 0, 'limit' => 1], 'Bağlantı Testi', 'listing');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        return [
            'basarili' => true,
            'mesaj'    => 'Bağlantı başarılı. Listing sayısı: ' . (int)($sonuc['veri']['totalCount'] ?? 0),
            'veri'     => null
        ];
    }

    // =================================================================
    // Ürünler (listing + katalog)
    // =================================================================

    /** Katalog ürünleri (barkod, ad, marka, kategori, KDV). $sayfa 0'dan başlar. */
    public function urunleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $boyut = min(self::KATALOG_SAYFA_BOYUTU, (int)$boyut);
        $sonuc = $this->istek('GET', '/product/api/products/all-products-of-merchant/' . $this->merchantId(),
            null, array_merge($filtre, ['page' => (int)$sayfa, 'size' => $boyut]), 'Ürün Listele', 'katalog');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'urunler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }

        return [
            'basarili'    => true,
            'hata'        => '',
            'urunler'     => $this->liste($sonuc['veri']),
            'toplam'      => (int)($sonuc['veri']['totalElements'] ?? 0),
            'sayfa'       => (int)($sonuc['veri']['number'] ?? $sayfa),
            'toplamSayfa' => (int)($sonuc['veri']['totalPages'] ?? 0)
        ];
    }

    /** Listing kayıtları (fiyat, stok, satış durumu) */
    public function listingleriListele($sayfa = 0, $boyut = 50)
    {
        $sonuc = $this->istek('GET', '/listings/merchantid/' . $this->merchantId(),
            null, ['offset' => (int)$sayfa * (int)$boyut, 'limit' => (int)$boyut], 'Listing Listele', 'listing');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'listingler' => [], 'toplam' => 0];
        }

        return [
            'basarili'   => true,
            'hata'       => '',
            'listingler' => $this->liste($sonuc['veri']),
            'toplam'     => (int)($sonuc['veri']['totalCount'] ?? 0)
        ];
    }

    /**
     * Mağazada listelenen ürünleri PazaryeriUrunleri'ne yazar.
     * Birim = listing (satılabilir SKU). Barkod, ad, marka ve KDV katalogdan hbSku ile alınır;
     * katalogda karşılığı olmayan listing'de barkod yerine merchantSku yazılır.
     * Listing'i olmayan katalog kayıtları (eski içe aktarmalar) alınmaz.
     */
    public function urunleriSenkronla()
    {
        // 1) Katalog: hbSku → kayıt
        $katalog = [];
        $sayfa   = 0;
        do {
            $k = $this->urunleriListele([], $sayfa, self::KATALOG_SAYFA_BOYUTU);
            if (!$k['basarili']) {
                return ['basarili' => false, 'hata' => $k['hata'], 'adet' => 0];
            }
            foreach ($k['urunler'] as $u) {
                if (!empty($u['hbSku'])) {
                    $katalog[$u['hbSku']] = $u;
                }
            }
            $sayfa++;
        } while ($sayfa < $k['toplamSayfa'] && $sayfa < 200);

        // 2) Katalogda olmayan listing'ler için ad/KDV/görsel: son sipariş kalemi
        $siparisten = $this->siparisKalemBilgileri();

        // 3) Listing'ler
        $adet = 0; $yeni = 0; $guncellenen = 0; $hata = 0;
        $sayfa = 0;
        do {
            $l = $this->listingleriListele($sayfa, self::SAYFA_BOYUTU);
            if (!$l['basarili']) {
                return ['basarili' => false, 'hata' => $l['hata'], 'adet' => $adet];
            }
            foreach ($l['listingler'] as $x) {
                $adet++;
                $sku = $x['hepsiburadaSku'] ?? '';
                $r = $this->urunKaydet($this->urunNotrle($x, $katalog[$sku] ?? null, $siparisten[$sku] ?? null));
                if ($r === 'yeni')            { $yeni++; }
                elseif ($r === 'guncellendi') { $guncellenen++; }
                else                          { $hata++; }
            }
            $sayfa++;
        } while (count($l['listingler']) >= self::SAYFA_BOYUTU && $sayfa < self::MAKS_SAYFA);

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'yeni' => $yeni,
                'guncellenen' => $guncellenen, 'hatali' => $hata];
    }

    /**
     * HB'nin mevcut ürününe eşlenmiş listing'ler katalog ucunda görünmez ve adlarını veren
     * bir uç yoktur (24.09.2026 canlı: hbSku ile katalog sorgusu "Merchant products not found").
     * Ad, KDV ve görsel bu kanalın son sipariş kaleminden alınır.
     * @return array hbSku => ['adi','kdvOran','gorselUrl']
     */
    private function siparisKalemBilgileri()
    {
        $satirlar = $this->db->fetchAll("
            SELECT DisUrunId, UrunAdi, KdvOran, HamVeri
            FROM (
                SELECT k.PazaryeriSiparisKalemleri_DisUrunId AS DisUrunId,
                       k.PazaryeriSiparisKalemleri_UrunAdi   AS UrunAdi,
                       k.PazaryeriSiparisKalemleri_KdvOran   AS KdvOran,
                       k.PazaryeriSiparisKalemleri_HamVeri   AS HamVeri,
                       ROW_NUMBER() OVER (PARTITION BY k.PazaryeriSiparisKalemleri_DisUrunId
                                          ORDER BY s.PazaryeriSiparisleri_SiparisTarihi DESC) AS sira
                FROM dbo.PazaryeriSiparisKalemleri k
                INNER JOIN dbo.PazaryeriSiparisleri s ON s.PazaryeriSiparisleri_id = k.PazaryeriSiparisleri_id
                WHERE s.kanal_id = ? AND k.PazaryeriSiparisKalemleri_DisUrunId IS NOT NULL
            ) t
            WHERE sira = 1
        ", [$this->kanalId]) ?: [];

        $bilgi = [];
        foreach ($satirlar as $r) {
            $ham    = json_decode($r['HamVeri'] ?? '', true) ?: [];
            $gorsel = $ham['productImageUrlFormat'] ?? null;
            $bilgi[$r['DisUrunId']] = [
                'adi'       => $r['UrunAdi'],
                'kdvOran'   => $r['KdvOran'],
                'gorselUrl' => $gorsel ? str_replace('{size}', '500', $gorsel) : null,
            ];
        }
        return $bilgi;
    }

    /** Listing (+ katalog, + sipariş kalemi) → nötr ürün */
    private function urunNotrle(array $x, $kat, $sip = null)
    {
        $barkod = trim((string)($kat['barcode'] ?? ''));
        if ($barkod === '') {
            $barkod = (string)($x['merchantSku'] ?? '');
        }

        $sebepler = array_merge($x['deactivationReasons'] ?? [], $x['lockReasons'] ?? [], $x['freezeReasons'] ?? []);
        if (!empty($kat['rejectReasons'])) {
            $sebepler[] = json_encode($kat['rejectReasons'], JSON_UNESCAPED_UNICODE);
        }

        if (!empty($x['isSuspended']))  { $durum = 'suspended'; }
        elseif (!empty($x['isLocked'])) { $durum = 'locked'; }
        elseif (!empty($x['isFrozen'])) { $durum = 'frozen'; }
        else                            { $durum = !empty($x['isSalable']) ? 'onSale' : 'notOnSale'; }

        $katDurum = strtoupper((string)($kat['status'] ?? ''));

        return [
            'barkod'      => $barkod,
            'stokKodu'    => $x['merchantSku'] ?? null,
            'anaUrunKodu' => ($x['variantGroupId'] ?? null) ?: (($kat['variantGroupId'] ?? null) ?: null),
            'disUrunId'   => $x['hepsiburadaSku'] ?? null,
            'disUrunKodu' => $x['productId'] ?? null,
            'urunUrl'     => !empty($x['hepsiburadaSku']) ? 'https://www.hepsiburada.com/-p-' . $x['hepsiburadaSku'] : null,
            'adi'         => $kat['productName']  ?? ($sip['adi'] ?? null),
            'markaAdi'    => $kat['brand']        ?? null,
            'kategoriAdi' => $kat['categoryName'] ?? null,
            'gorselUrl'   => $sip['gorselUrl']    ?? null,
            // HB'de tek fiyat vardır; liste fiyatı da aynı değerdir
            'listeFiyat'  => isset($x['price']) ? (float)$x['price'] : null,
            'satisFiyat'  => isset($x['price']) ? (float)$x['price'] : null,
            'stok'        => (int)($x['availableStock'] ?? 0),
            'kdvOran'     => isset($kat['tax']) && is_numeric($kat['tax']) ? (float)$kat['tax'] : ($sip['kdvOran'] ?? null),
            // Katalogda yoksa HB'nin mevcut ürününe eşlenmiştir; listing'i olduğu için onaylıdır
            'onayli'      => $kat === null ? true : ($katDurum === 'MATCHED'),
            'redSebebi'   => $sebepler ? mb_substr(implode(', ', $sebepler), 0, 4000) : null,
            'hamDurum'    => $durum,
            'ham'         => ['listing' => $x, 'katalog' => $kat ? array_diff_key($kat, ['description' => 1, 'baseAttributes' => 1]) : null]
        ];
    }

    // =================================================================
    // Siparişler
    // =================================================================

    /**
     * Sipariş numarası kaynakları. Her biri yalnız o durumdaki kayıtları döner.
     * 'tarih' = begindate/enddate kabul eder; 'son' = durum son hâl (DB'de aynı durumdaysa detay çekilmez).
     */
    private function siparisKaynaklari()
    {
        $m = $this->merchantId();
        return [
            ['yol' => "/orders/merchantid/$m",               'islem' => 'Açık Siparişler',  'tarih' => false, 'son' => null],
            ['yol' => "/packages/merchantid/$m",             'islem' => 'Paketler',         'tarih' => false, 'son' => null],
            ['yol' => "/packages/merchantid/$m/shipped",     'islem' => 'Kargodaki Paketler','tarih' => true, 'son' => null],
            ['yol' => "/packages/merchantid/$m/delivered",   'islem' => 'Teslim Edilenler', 'tarih' => true,  'son' => 'teslim'],
            ['yol' => "/packages/merchantid/$m/undelivered", 'islem' => 'Teslim Edilemeyen','tarih' => true,  'son' => null],
            ['yol' => "/orders/merchantid/$m/cancelled",     'islem' => 'İptal Siparişler', 'tarih' => true,  'son' => 'iptal'],
        ];
    }

    /** Liste kaydından sipariş numaraları (uçlara göre alan adı ve büyük/küçük harf değişir) */
    private function siparisNumaralari(array $kayit)
    {
        $no = [];
        foreach (['orderNumber', 'OrderNumber'] as $a) {
            if (!empty($kayit[$a])) { $no[] = (string)$kayit[$a]; }
        }
        foreach (['orderNumbers', 'OrderNumbers'] as $a) {
            foreach ((array)($kayit[$a] ?? []) as $n) { $no[] = (string)$n; }
        }
        foreach ($kayit['items'] ?? ($kayit['Items'] ?? []) as $it) {
            if (!empty($it['orderNumber'])) { $no[] = (string)$it['orderNumber']; }
        }
        return array_unique(array_filter($no));
    }

    /**
     * Durum listelerinden sipariş numaralarını toplar.
     * @return array ['basarili'=>bool,'hata'=>string,'siparisler'=>[no => sonDurum|null]]
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $gun = (int)($filtre['gun'] ?? 0);
        $tarih = $gun > 0 ? [
            'begindate' => date('Y-m-d H:i', time() - $gun * 86400),
            'enddate'   => date('Y-m-d H:i')
        ] : [];

        $numaralar = [];
        foreach ($this->siparisKaynaklari() as $k) {
            $sayfa = 0;
            do {
                $query = ['offset' => $sayfa * self::SAYFA_BOYUTU, 'limit' => self::SAYFA_BOYUTU];
                if ($k['tarih']) { $query += $tarih; }

                $r = $this->istek('GET', $k['yol'], null, $query, $k['islem'], 'siparis');
                if (!$r['basarili']) {
                    return ['basarili' => false, 'hata' => $k['islem'] . ': ' . $r['hata'], 'siparisler' => []];
                }

                $kayitlar = $this->liste($r['veri']);
                foreach ($kayitlar as $kayit) {
                    foreach ($this->siparisNumaralari($kayit) as $no) {
                        // Aynı sipariş birden fazla listede çıkarsa "son durum" işareti yalnız tek kaynaktaysa geçerli
                        $numaralar[$no] = array_key_exists($no, $numaralar) ? null : $k['son'];
                    }
                }

                $toplam = isset($r['veri']['totalCount']) ? (int)$r['veri']['totalCount'] : null;
                $sayfa++;
                $devam = $toplam !== null
                    ? $sayfa * self::SAYFA_BOYUTU < $toplam
                    : count($kayitlar) >= self::SAYFA_BOYUTU;
            } while ($devam && $sayfa < self::MAKS_SAYFA);
        }

        return ['basarili' => true, 'hata' => '', 'siparisler' => $numaralar];
    }

    /** Tek siparişin tam detayı */
    public function siparisDetay($siparisNo)
    {
        $r = $this->istek('GET', '/orders/merchantid/' . $this->merchantId() . '/ordernumber/' . rawurlencode((string)$siparisNo),
                          null, [], 'Sipariş Detay', 'siparis');
        if (!$r['basarili']) {
            return ['basarili' => false, 'hata' => $r['hata'], 'siparis' => null];
        }
        if (empty($r['veri']['orderNumber'])) {
            return ['basarili' => false, 'hata' => 'Sipariş bulunamadı: ' . $siparisNo, 'siparis' => null];
        }
        return ['basarili' => true, 'hata' => '', 'siparis' => $r['veri']];
    }

    /**
     * Siparişleri çek ve yaz. Birim SİPARİŞtir (disId = orderNumber); paket numaraları
     * PaketNo alanında virgülle tutulur. Son durumda olup DB'de aynı durumda kayıtlı
     * siparişlerin detayı yeniden çekilmez.
     */
    public function siparisleriSenkronla($gunSayisi = 2)
    {
        $liste = $this->siparisleriListele(['gun' => (int)$gunSayisi]);
        if (!$liste['basarili']) {
            return ['basarili' => false, 'hata' => $liste['hata'], 'adet' => 0];
        }

        $adet = 0; $yeni = 0; $hatali = 0; $atlanan = 0;
        $hatalar = [];

        foreach ($liste['siparisler'] as $no => $sonDurum) {
            $mevcut = $this->db->fetchOne("
                SELECT PazaryeriSiparisleri_DurumKod AS durum FROM dbo.PazaryeriSiparisleri
                WHERE kanal_id = ? AND PazaryeriSiparisleri_DisId = ?
            ", [$this->kanalId, (string)$no]);

            if ($mevcut && $sonDurum !== null && $mevcut['durum'] === $sonDurum) {
                $atlanan++;
                continue;
            }

            $sonuc = $this->siparisNoIleKaydet($no);
            if ($sonuc['basarili']) {
                $adet++;
                if (!$mevcut) { $yeni++; }
            } else {
                $hatali++;
                $hatalar[] = $no . ': ' . $sonuc['hata'];
            }
        }

        if ($hatali > 0 && $adet === 0 && $atlanan === 0) {
            return ['basarili' => false, 'hata' => implode(' | ', array_slice($hatalar, 0, 3)), 'adet' => 0];
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'yeni' => $yeni,
                'hatali' => $hatali, 'atlanan' => $atlanan];
    }

    /** Sipariş numarasıyla detayı çekip kaydeder (geçmiş siparişleri almak için de kullanılır) */
    public function siparisNoIleKaydet($siparisNo)
    {
        $d = $this->siparisDetay($siparisNo);
        if (!$d['basarili']) {
            return $d;
        }
        $id = $this->siparisKaydet($this->siparisNotrle($d['siparis']));
        return $id
            ? ['basarili' => true, 'hata' => '', 'id' => $id]
            : ['basarili' => false, 'hata' => 'Kayıt yazılamadı.'];
    }

    /** Hepsiburada sipariş detayı → nötr sipariş */
    private function siparisNotrle(array $s)
    {
        $kalemler = [];
        $paketler = [];
        $durumlar = [];
        $toplam   = 0.0;
        $desi     = 0.0;
        $ilk      = null;

        foreach ($s['items'] ?? [] as $it) {
            $ilk = $ilk ?? $it;
            $miktar = (int)($it['quantity'] ?? 1);
            $tutar  = $this->tutar($it['totalPrice'] ?? null);
            $toplam += (float)$tutar;
            $desi   += (float)($it['totalDeci'] ?? 0);
            if (!empty($it['packageNumber'])) { $paketler[] = (string)$it['packageNumber']; }
            $durumlar[] = (string)($it['status'] ?? '');

            $indirim = (float)$this->tutar($it['hbDiscount']['totalPrice'] ?? null)
                     + (float)$this->tutar($it['merchantDiscount']['totalPrice'] ?? null);

            $kalemler[] = [
                'disId'         => (string)($it['id'] ?? ''),
                'urunId'        => $this->yerelUrunId($it['sku'] ?? null),
                'barkod'        => $it['productBarcode'] ?? null,
                'stokKodu'      => $it['merchantSKU'] ?? ($it['merchantSku'] ?? null),
                'disUrunId'     => $it['sku'] ?? null,
                'urunAdi'       => $it['name'] ?? null,
                'miktar'        => $miktar,
                'birimFiyat'    => $this->tutar($it['unitPrice'] ?? null),
                'toplamTutar'   => $tutar,
                'indirimTutar'  => $indirim ?: null,
                'kdvOran'       => isset($it['vatRate']) ? (float)$it['vatRate'] : null,
                'kdvTutar'      => isset($it['vat']) ? (float)$it['vat'] : null,
                'komisyonOran'  => isset($it['commissionRate']) ? (float)$it['commissionRate'] : null,
                'komisyonTutar' => $this->tutar($it['commission'] ?? null),
                'desi'          => isset($it['totalDeci']) ? (float)$it['totalDeci'] : null,
                'hamDurum'      => $it['status'] ?? null,
                'ham'           => $it
            ];
        }

        $teslimat = $s['deliveryAddress'] ?? ($ilk['shippingAddress'] ?? []);
        $fatura   = $s['invoice'] ?? ($ilk['invoice'] ?? []);
        $fAdres   = $fatura['address'] ?? [];
        $hamDurum = $this->baskinDurum($durumlar);

        return [
            'disId'          => (string)($s['orderNumber'] ?? ''),
            'siparisNo'      => (string)($s['orderNumber'] ?? ''),
            'paketNo'        => implode(',', array_unique($paketler)) ?: null,
            'durumKod'       => $this->siparisDurumKodu($hamDurum),
            'hamDurum'       => $hamDurum ?: null,
            'toplamTutar'    => round($toplam, 2),
            'paraBirimi'     => $ilk['totalPrice']['currency'] ?? 'TRY',
            'musteriAdi'     => $s['customer']['name'] ?? ($ilk['customerName'] ?? null),
            'aliciAdi'       => $teslimat['name'] ?? null,
            'eposta'         => $teslimat['email'] ?? ($fAdres['email'] ?? null),
            'telefon'        => $teslimat['phoneNumber'] ?? null,
            'tckn'           => ($fatura['turkishIdentityNumber'] ?? null) ?: null,
            'vergiNo'        => ($fatura['taxNumber'] ?? null) ?: null,
            'vergiDairesi'   => ($fatura['taxOffice'] ?? null) ?: null,
            'il'             => $teslimat['city']     ?? null,
            'ilce'           => $teslimat['town']     ?? null,
            'semt'           => $teslimat['district'] ?? null,
            'teslimatAdresi' => $teslimat,
            'faturaAdresi'   => $fAdres + ['unvan' => $fAdres['name'] ?? null],
            'kargoFirmasi'   => $ilk['cargoCompany'] ?? null,
            'kargoTakipNo'   => ($ilk['barcode'] ?? null) ?: null,
            'kargoBarkod'    => ($ilk['barcode'] ?? null) ?: null,
            'desi'           => $desi ?: null,
            'siparisTarihi'  => $this->isoTarih($s['orderDate'] ?? null),
            'terminTarihi'   => $this->isoTarih($ilk['dueDate'] ?? null),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /**
     * Kalemin yerel ürünü, listing'in hbSku'su (PazaryeriUrunleri_DisUrunId) üzerinden.
     * HB'nin mevcut ürününe eşlenmiş listing'lerde barkod katalogda yoktur; siparişteki
     * productBarcode ile PazaryeriUrunleri barkodu tutmaz. Bulunamazsa null → ortak katman barkodla dener.
     */
    private function yerelUrunId($hbSku)
    {
        if (empty($hbSku)) {
            return null;
        }
        $r = $this->db->fetchOne("
            SELECT urun_hizmet_id FROM dbo.PazaryeriUrunleri
            WHERE kanal_id = ? AND PazaryeriUrunleri_DisUrunId = ? AND urun_hizmet_id IS NOT NULL
        ", [$this->kanalId, (string)$hbSku]);
        return $r['urun_hizmet_id'] ?? null;
    }

    /** Kalem durumlarından sipariş durumu: en az ilerlemiş aktif durum; hepsi iptalse iptal */
    private function baskinDurum(array $durumlar)
    {
        $durumlar = array_values(array_filter($durumlar));
        if (!$durumlar) {
            return '';
        }
        $aktif = array_values(array_filter($durumlar, fn($d) => stripos($d, 'cancel') === false));
        if (!$aktif) {
            return $durumlar[0];
        }
        usort($aktif, fn($a, $b) => $this->durumSirasi($a) <=> $this->durumSirasi($b));
        return $aktif[0];
    }

    private function durumSirasi($durum)
    {
        $d = strtolower($durum);
        if (strpos($d, 'claim') !== false || strpos($d, 'return') !== false) return 5;
        if (strpos($d, 'undeliver') !== false)                               return 3;
        if (strpos($d, 'deliver') !== false)                                 return 4;
        if (strpos($d, 'ship') !== false || strpos($d, 'transit') !== false) return 3;
        if (strpos($d, 'pack') !== false || strpos($d, 'ready') !== false)   return 2;
        return 1;
    }

    /** Hepsiburada kalem durumu → yerel normalize kod */
    private function siparisDurumKodu($durum)
    {
        $d = strtolower((string)$durum);
        if ($d === '')                                                              return 'diger';
        if (strpos($d, 'cancel') !== false)                                         return 'iptal';
        if (strpos($d, 'claim') !== false || strpos($d, 'return') !== false)        return 'iade';
        if (strpos($d, 'undeliver') !== false)                                      return 'diger';
        if (strpos($d, 'deliver') !== false)                                        return 'teslim';
        if (strpos($d, 'ship') !== false || strpos($d, 'transit') !== false)        return 'kargoda';
        if (strpos($d, 'pack') !== false || strpos($d, 'ready') !== false
            || strpos($d, 'picking') !== false)                                     return 'hazirlaniyor';
        if (strpos($d, 'open') !== false || strpos($d, 'new') !== false
            || strpos($d, 'created') !== false || strpos($d, 'approved') !== false) return 'yeni';
        return 'diger';
    }

    // =================================================================
    // İadeler
    // =================================================================

    public function iadeleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $r = $this->istek('GET', '/claims/merchantid/' . $this->merchantId(), null,
            array_merge($filtre, ['offset' => (int)$sayfa * (int)$boyut, 'limit' => (int)$boyut]),
            'İade Listele', 'siparis');

        if (!$r['basarili']) {
            return ['basarili' => false, 'hata' => $r['hata'], 'iadeler' => [], 'toplam' => 0];
        }

        $liste = $this->liste($r['veri']);
        return ['basarili' => true, 'hata' => '', 'iadeler' => $liste, 'toplam' => count($liste)];
    }

    /**
     * İadeleri çek ve yaz. Uç tarih filtresi almaz ve kayıt sayısı düşüktür;
     * tüm liste sayfalanarak alınır ($gunSayisi sözleşme gereği vardır, kullanılmaz).
     */
    public function iadeleriSenkronla($gunSayisi = 30)
    {
        $adet  = 0;
        $sayfa = 0;
        do {
            $sonuc = $this->iadeleriListele([], $sayfa, self::SAYFA_BOYUTU);
            if (!$sonuc['basarili']) {
                return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $adet];
            }
            foreach ($sonuc['iadeler'] as $i) {
                if ($this->iadeKaydet($this->iadeNotrle($i))) {
                    $adet++;
                }
            }
            $sayfa++;
        } while (count($sonuc['iadeler']) >= self::SAYFA_BOYUTU && $sayfa < self::MAKS_SAYFA);

        return ['basarili' => true, 'hata' => '', 'adet' => $adet];
    }

    /** Hepsiburada iadesi → nötr iade (her kayıt tek kalemdir) */
    private function iadeNotrle(array $i)
    {
        $teslimat = $i['Deliveries'][0] ?? [];
        $sebep    = $i['Reason'] ?? ($i['reason'] ?? null);

        $kalemler = [[
            'barkod'  => null,                         // iade kaydında barkod yok
            'stokKodu'=> $i['MerchantSku'] ?? null,
            'disUrunId' => $i['sku'] ?? null,
            'urunAdi' => null,
            'miktar'  => (int)($i['quantity'] ?? 1),
            'fiyat'   => isset($i['totalPriceAmount']) ? (float)$i['totalPriceAmount'] : null,
            'sebep'   => $sebep,
            'durum'   => $i['status'] ?? null,
        ]];

        $aciklama = trim(implode(' | ', array_filter([
            $i['explanation'] ?? '',
            $i['merchantRejectionStatement'] ?? '',
        ])));

        return [
            'disId'      => (string)($i['number'] ?? ($i['id'] ?? '')),
            'siparisNo'  => (string)($i['orderNumber'] ?? ''),
            'paketNo'    => (string)($teslimat['PackageNumber'] ?? ''),
            'durumKod'   => $this->iadeDurumKodu($i['status'] ?? ''),
            'hamDurum'   => $i['status'] ?? null,
            'sebep'      => $sebep,
            'aciklama'   => $aciklama !== '' ? $aciklama : null,
            'musteriAdi' => $i['customerName'] ?? null,
            'tutar'      => isset($i['totalPriceAmount']) ? (float)$i['totalPriceAmount'] : null,
            'iadeTarihi' => $this->isoTarih($i['claimDate'] ?? null),
            'kalemler'   => $kalemler,
            'ham'        => $i
        ];
    }

    /** Hepsiburada iade durumu → yerel kod (canlıda görülen: NewRequest, Accepted, Rejected, Refunded, Cancelled) */
    private function iadeDurumKodu($durum)
    {
        $d = strtolower((string)$durum);
        if (strpos($d, 'new') !== false || strpos($d, 'waiting') !== false
            || strpos($d, 'awaiting') !== false || strpos($d, 'open') !== false)  return 'acik';
        if (strpos($d, 'accept') !== false || strpos($d, 'approve') !== false
            || strpos($d, 'refund') !== false)                                    return 'onaylandi';
        if (strpos($d, 'reject') !== false)                                       return 'reddedildi';
        if (strpos($d, 'cancel') !== false)                                       return 'iptal';
        return 'diger';
    }
}
