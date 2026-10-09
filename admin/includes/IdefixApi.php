<?php
/**
 * idefix Marketplace API
 *
 * Kaynak: TamRenk ortak sistemindeki IdefixApi iskeleti (yalnız kimlik/adres) + resmi doküman
 * (developer.idefix.com/api). OrnekFirma sürümü 24.09.2026; bkz. e-Ticaret Entegrasyon Bilgileri\OrnekFirma\idefix.txt
 *
 * Kanal (OrnekFirma EntegrasyonKanallari):
 *   Kanal_Kullanici = API Key, Kanal_Sifre = API Secret
 *   Kanal_Parametreler = {"satici_id": "23548"}  (+ isteğe bağlı pim_url / oms_url)
 *
 * Doğrulama durumu (24.09.2026 canlı):
 *   - Ürün havuzu (/pim/pool/{vendorId}/list) canlı yanıtla doğrulandı (76 ürün).
 *   - Mağazada hiç sipariş ve iade YOK; sipariş/iade eşlemesi DOKÜMANDAN yazıldı.
 *     Ham yanıt HamVeri'de saklanır; ilk gerçek kayıtta eşleme yeniden doğrulanmalıdır.
 *
 * Tuzaklar (ortak şema 6.5):
 *   - Kimlik başlığı X-API-KEY = base64(ApiKey:ApiSecret); Authorization/Basic DEĞİL.
 *   - OMS uçlarında satıcı id hem yolda hem 'vendor' sorgu parametresinde zorunlu.
 *   - Sayfa numarası 1'den başlar. Ürün ucunda toplam sayı yok; boş sayfaya kadar gezilir.
 *   - Tarih filtresi biçimi 2022/09/30 23:59:59; yanıttaki tarihler +03:00 ekli ISO.
 *   - lastUpdatedAt filtresi geçersiz değerde de 200 döner (davranışı doğrulanamadı);
 *     senkron updateAt'e göre azalan sıralayıp yanıttaki updatedAt ile durur.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class IdefixApi extends PazaryeriApi
{
    /** Liste uçlarında sayfa boyutu */
    const SAYFA_BOYUTU = 50;

    /** Ürün havuzu sayfa boyutu */
    const URUN_SAYFA_BOYUTU = 100;

    /** Sonsuz döngü koruması */
    const MAKS_SAYFA = 100;

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('idefix', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    /** OrnekFirma kanal kolonları → idefix anahtarları */
    protected function configEsle()
    {
        $c = &$this->config;
        $c['ApiKey']    = !empty($c['Kullanici']) ? $c['Kullanici'] : ($c['ApiKey'] ?? null);
        $c['ApiSecret'] = $c['ApiSecret'] ?? $c['Sifre'] ?? null;
        $c['SaticiId']  = $c['SaticiId']  ?? $c['satici_id'] ?? null;
    }

    protected function baseUrl($hizmet = 'varsayilan')
    {
        return $hizmet === 'pim'
            ? $this->getConfig('pim_url', 'https://merchantapi.idefix.com/pim')
            : $this->getConfig('oms_url', 'https://merchantapi.idefix.com/oms');
    }

    protected function authHeaders()
    {
        $anahtar = trim((string)($this->config['ApiKey'] ?? '')) . ':' . trim((string)($this->config['ApiSecret'] ?? ''));
        return ['X-API-KEY: ' . base64_encode($anahtar)];
    }

    protected function configDogrula()
    {
        if (empty($this->config['ApiKey']) || empty($this->config['ApiSecret'])) {
            return 'idefix kanalında API Key (Kanal_Kullanici) / API Secret (Kanal_Sifre) tanımlı değil.';
        }
        if (empty($this->config['SaticiId'])) {
            return 'idefix kanal parametrelerinde satici_id tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'Kanal_Kullanici', 'etiket' => 'API Key',    'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Satıcı paneli > Hesap Bilgileri > Entegrasyon Bilgileri'],
            ['anahtar' => 'Kanal_Sifre',     'etiket' => 'API Secret', 'gizli' => true,  'zorunlu' => true,
             'aciklama' => 'Panelde görünmez, kayıtlı e-postaya gönderilir'],
            ['anahtar' => 'satici_id',       'etiket' => 'Satıcı ID',  'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Kanal_Parametreler JSON'],
        ];
    }

    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if ($httpKod === 401) {
            return 'HTTP 401: Yetkisiz erişim (VENDOR_TOKEN_NOT_EXIST). API Key/Secret yanlış veya başka ortama ait.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    private function saticiId()
    {
        return rawurlencode(trim((string)$this->config['SaticiId']));
    }

    /** OMS isteği: satıcı id yolda VE 'vendor' sorgusunda */
    private function omsIstek($uc, array $query, $islem)
    {
        return $this->istek('GET', '/' . $this->saticiId() . '/' . $uc, null,
                            array_merge(['vendor' => trim((string)$this->config['SaticiId'])], $query), $islem, 'oms');
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $r = $this->omsIstek('list', ['page' => 1, 'limit' => 1], 'Bağlantı Testi');
        if (!$r['basarili']) {
            return ['basarili' => false, 'mesaj' => $r['hata']];
        }
        return [
            'basarili' => true,
            'mesaj'    => 'Bağlantı başarılı. Sevkiyat sayısı: ' . (int)($r['veri']['totalCount'] ?? 0),
            'veri'     => null
        ];
    }

    // =================================================================
    // Ürünler (havuz)
    // =================================================================

    /** Ürün havuzu. $sayfa 0'dan başlar (idefix'te 1'den). Toplam sayı dönmez. */
    public function urunleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $r = $this->istek('GET', '/pool/' . $this->saticiId() . '/list', null,
            array_merge($filtre, ['page' => (int)$sayfa + 1, 'limit' => min(self::URUN_SAYFA_BOYUTU, (int)$boyut)]),
            'Ürün Listele', 'pim');

        if (!$r['basarili']) {
            return ['basarili' => false, 'hata' => $r['hata'], 'urunler' => [], 'toplam' => 0];
        }
        $urunler = $r['veri']['products'] ?? [];
        return ['basarili' => true, 'hata' => '', 'urunler' => $urunler, 'toplam' => count($urunler)];
    }

    /** Havuzdaki tüm ürünleri PazaryeriUrunleri'ne yazar (boş sayfaya kadar) */
    public function urunleriSenkronla()
    {
        $adet = 0; $yeni = 0; $guncellenen = 0; $hata = 0;
        $sayfa = 0;
        do {
            $l = $this->urunleriListele([], $sayfa, self::URUN_SAYFA_BOYUTU);
            if (!$l['basarili']) {
                return ['basarili' => false, 'hata' => $l['hata'], 'adet' => $adet];
            }
            foreach ($l['urunler'] as $u) {
                $adet++;
                $r = $this->urunKaydet($this->urunNotrle($u));
                if ($r === 'yeni')            { $yeni++; }
                elseif ($r === 'guncellendi') { $guncellenen++; }
                else                          { $hata++; }
            }
            $sayfa++;
        } while (count($l['urunler']) >= self::URUN_SAYFA_BOYUTU && $sayfa < self::MAKS_SAYFA);

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'yeni' => $yeni,
                'guncellenen' => $guncellenen, 'hatali' => $hata];
    }

    /**
     * Havuz ürünü → nötr ürün.
     * Durumlar (canlı): ready_for_sale | waiting_vendor_approve | missing_info.
     * Marka/kategori yalnız kimlik olarak gelir; eşleşmiş üründe marka adı matchedProduct'tadır.
     */
    private function urunNotrle(array $u)
    {
        $esli   = is_array($u['matchedProduct'] ?? null) ? $u['matchedProduct'] : [];
        $durum  = (string)($u['status'] ?? '');
        $gorsel = $u['images'][0]['url'] ?? ($esli['images'][0] ?? null);

        $sebep = null;
        if (!empty($u['failureReasons'])) {
            $sebep = is_array($u['failureReasons'])
                ? json_encode($u['failureReasons'], JSON_UNESCAPED_UNICODE)
                : (string)$u['failureReasons'];
        } elseif ($durum === 'missing_info') {
            $sebep = 'Ürün bilgisi eksik (idefix panelinden tamamlanmalı)';
        }

        $fiyat = isset($u['price']) ? (float)$u['price'] : null;
        $liste = isset($u['comparePrice']) && (float)$u['comparePrice'] > 0 ? (float)$u['comparePrice'] : $fiyat;

        return [
            'barkod'      => $u['barcode'] ?? null,
            'stokKodu'    => ($u['vendorStockCode'] ?? null) ?: null,
            'anaUrunKodu' => ($u['productMainId'] ?? null) ?: null,
            'disUrunId'   => isset($u['reference']) ? (string)$u['reference'] : null,
            'disUrunKodu' => $esli['slug'] ?? null,
            'urunUrl'     => !empty($esli['slug']) ? 'https://www.idefix.com/' . $esli['slug'] : null,
            'adi'         => ($u['title'] ?? null) ?: ($esli['name'] ?? null),
            'markaAdi'    => $esli['brand'] ?? null,
            'listeFiyat'  => $liste,
            'satisFiyat'  => $fiyat,
            'stok'        => (int)($u['inventoryQuantity'] ?? 0),
            'kdvOran'     => isset($u['vatRate']) && is_numeric($u['vatRate']) ? (float)$u['vatRate'] : null,
            'gorselUrl'   => $gorsel,
            'onayli'      => $durum === 'ready_for_sale',
            'redSebebi'   => $sebep,
            'hamDurum'    => $durum !== '' ? $durum : null,
            'ham'         => array_diff_key($u, ['description' => 1, 'aiDescription' => 1])
        ];
    }

    // =================================================================
    // Siparişler (sevkiyat) — DOKÜMANDAN, canlı doğrulanmadı
    // =================================================================

    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $r = $this->omsIstek('list', array_merge($filtre, [
            'page'  => (int)$sayfa + 1,
            'limit' => (int)$boyut
        ]), 'Sipariş Listele');

        if (!$r['basarili']) {
            return ['basarili' => false, 'hata' => $r['hata'], 'siparisler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }
        return [
            'basarili'    => true,
            'hata'        => '',
            'siparisler'  => $r['veri']['items'] ?? [],
            'toplam'      => (int)($r['veri']['totalCount'] ?? 0),
            'toplamSayfa' => (int)($r['veri']['pageCount'] ?? 0)
        ];
    }

    /**
     * Son $gunSayisi gün içinde güncellenen sevkiyatları yazar.
     * Liste updateAt'e göre azalan sıralanır; yanıttaki updatedAt sınırın altına düşünce durulur.
     */
    public function siparisleriSenkronla($gunSayisi = 2)
    {
        $sinir = time() - max(1, (int)$gunSayisi) * 86400;
        $adet = 0; $yeni = 0; $hatali = 0;
        $sayfa = 0;

        do {
            $l = $this->siparisleriListele(['sortByField' => 'updateAt', 'sortDirection' => 'desc'],
                                           $sayfa, self::SAYFA_BOYUTU);
            if (!$l['basarili']) {
                return ['basarili' => false, 'hata' => $l['hata'], 'adet' => $adet];
            }

            $eskiyeUlasti = false;
            foreach ($l['siparisler'] as $s) {
                $guncelleme = strtotime((string)($s['updatedAt'] ?? $s['createdAt'] ?? ''));
                if ($guncelleme && $guncelleme < $sinir) {
                    $eskiyeUlasti = true;
                    break;
                }
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
        } while (!$eskiyeUlasti && $sayfa < $l['toplamSayfa'] && $sayfa < self::MAKS_SAYFA);

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'yeni' => $yeni, 'hatali' => $hatali];
    }

    /** idefix sevkiyatı → nötr sipariş. Birim SEVKİYAT (disId = sevkiyat id, bölünmüş sipariş ayrı satır). */
    private function siparisNotrle(array $s)
    {
        $teslimat = $s['shippingAddress'] ?? [];
        $fatura   = $s['invoiceAddress'] ?? $teslimat;

        $kalemler = [];
        $desi     = 0.0;
        foreach ($s['items'] ?? [] as $it) {
            $fiyat = isset($it['discountedTotalPrice']) ? (float)$it['discountedTotalPrice']
                   : (isset($it['price']) ? (float)$it['price'] : null);
            $kalemler[] = [
                'disId'         => isset($it['id']) ? (string)$it['id'] : null,
                'barkod'        => $it['barcode'] ?? null,
                'stokKodu'      => ($it['merchantSku'] ?? null) ?: null,
                'disUrunId'     => ($it['erpId'] ?? null) ?: null,
                'urunAdi'       => $it['productName'] ?? null,
                'miktar'        => (int)($it['quantity'] ?? 1),
                'birimFiyat'    => $fiyat,
                'toplamTutar'   => $fiyat,
                'indirimTutar'  => (float)($it['platformDiscount'] ?? 0) + (float)($it['vendorDiscount'] ?? 0) ?: null,
                'kdvOran'       => isset($it['vatRate']) ? (float)$it['vatRate'] : null,
                'komisyonTutar' => isset($it['commissionAmount']) ? (float)$it['commissionAmount'] : null,
                'hamDurum'      => $it['itemStatus'] ?? null,
                'ham'           => $it
            ];
        }

        // Kargoya veriliş: geçmişteki ilk shipment_in_cargo kaydı
        $kargoyaVerilme = null;
        foreach ($s['histories'] ?? [] as $h) {
            if (($h['state'] ?? '') === 'shipment_in_cargo' && !empty($h['createdAt'])) {
                $kargoyaVerilme = $this->isoTarih($h['createdAt']);
                break;
            }
        }

        $alici = trim((string)($teslimat['fullName'] ?? (($teslimat['firstName'] ?? '') . ' ' . ($teslimat['lastName'] ?? ''))));

        return [
            'disId'          => (string)($s['id'] ?? ''),
            'siparisNo'      => (string)($s['orderNumber'] ?? ''),
            'paketNo'        => isset($s['id']) ? (string)$s['id'] : null,
            'durumKod'       => $this->siparisDurumKodu($s['status'] ?? ''),
            'hamDurum'       => $s['status'] ?? null,
            'toplamTutar'    => isset($s['discountedTotalPrice']) ? (float)$s['discountedTotalPrice']
                                : (isset($s['totalPrice']) ? (float)$s['totalPrice'] : null),
            'paraBirimi'     => 'TRY',
            'musteriAdi'     => ($s['customerContactName'] ?? null) ?: ($alici ?: null),
            'aliciAdi'       => $alici ?: null,
            'eposta'         => $s['customerContactMail'] ?? null,
            'telefon'        => ($teslimat['phone'] ?? null) ?: null,
            'tckn'           => ($s['customerTcNumber'] ?? null) ?: (($fatura['identificationNumber'] ?? null) ?: null),
            'vergiNo'        => ($fatura['taxNumber'] ?? null) ?: null,
            'vergiDairesi'   => ($fatura['taxOffice'] ?? null) ?: null,
            'il'             => $teslimat['city']   ?? null,
            'ilce'           => $teslimat['county'] ?? null,
            'semt'           => $teslimat['neighboorhood'] ?? ($teslimat['neighbourhood'] ?? null),
            'teslimatAdresi' => $teslimat,
            'faturaAdresi'   => $fatura + ['unvan' => ($fatura['company'] ?? null) ?: ($fatura['fullName'] ?? null)],
            'kargoFirmasi'   => $s['cargoCompany'] ?? null,
            'kargoTakipNo'   => ($s['cargoTrackingNumber'] ?? null) ?: null,
            'kargoBarkod'    => ($s['cargoTrackingUrl'] ?? null) ?: null,
            'kargoyaVerilme' => $kargoyaVerilme,
            'siparisTarihi'  => $this->isoTarih($s['orderDate'] ?? ($s['createdAt'] ?? null)),
            'terminTarihi'   => $this->isoTarih($s['items'][0]['lastShipmentDate'] ?? null),
            'tahminiTeslim'  => $this->isoTarih($s['estimatedDeliveryDate'] ?? null),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /** idefix sevkiyat durumu (doküman) → yerel kod */
    private function siparisDurumKodu($durum)
    {
        switch ((string)$durum) {
            case 'created':
            case 'shipment_ready':      return 'yeni';
            case 'shipment_picking':
            case 'shipment_invoiced':
            case 'shipment_split':      return 'hazirlaniyor';
            case 'shipment_in_cargo':   return 'kargoda';
            case 'shipment_delivered':
            case 'shipment_approved':   return 'teslim';
            case 'shipment_cancelled':
            case 'shipment_unsupplied': return 'iptal';
            default:                    return 'diger';   // shipment_undeliver vb.
        }
    }

    // =================================================================
    // İadeler — DOKÜMANDAN, canlı doğrulanmadı
    // =================================================================

    public function iadeleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $r = $this->omsIstek('claim-list', array_merge($filtre, [
            'page'  => (int)$sayfa + 1,
            'limit' => (int)$boyut
        ]), 'İade Listele');

        if (!$r['basarili']) {
            return ['basarili' => false, 'hata' => $r['hata'], 'iadeler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }
        return [
            'basarili'    => true,
            'hata'        => '',
            'iadeler'     => $r['veri']['items'] ?? [],
            'toplam'      => (int)($r['veri']['totalCount'] ?? 0),
            'toplamSayfa' => (int)($r['veri']['pageCount'] ?? 0)
        ];
    }

    /**
     * İadeleri yazar. Uçta sıralama parametresi belgelenmemiş; kayıt sayısı düşük olduğundan
     * tüm liste sayfalanır ($gunSayisi sözleşme gereği vardır, kullanılmaz).
     */
    public function iadeleriSenkronla($gunSayisi = 30)
    {
        $adet  = 0;
        $sayfa = 0;
        do {
            $l = $this->iadeleriListele([], $sayfa, self::SAYFA_BOYUTU);
            if (!$l['basarili']) {
                return ['basarili' => false, 'hata' => $l['hata'], 'adet' => $adet];
            }
            foreach ($l['iadeler'] as $i) {
                if ($this->iadeKaydet($this->iadeNotrle($i))) {
                    $adet++;
                }
            }
            $sayfa++;
        } while ($sayfa < $l['toplamSayfa'] && $sayfa < self::MAKS_SAYFA);

        return ['basarili' => true, 'hata' => '', 'adet' => $adet];
    }

    /** idefix iadesi → nötr iade (kalemler items[]) */
    private function iadeNotrle(array $i)
    {
        $kalemler = [];
        $ilk      = null;
        foreach ($i['items'] ?? [] as $it) {
            $ilk   = $ilk ?? $it;
            $sebep = $it['customerReason'] ?? ($it['vendorReason'] ?? ($it['platformReason'] ?? null));
            $kalemler[] = [
                'barkod'  => $it['barcode'] ?? null,
                'urunAdi' => $it['productName'] ?? null,
                'miktar'  => 1,
                'fiyat'   => isset($it['totalPrice']) ? (float)$it['totalPrice'] : null,
                'sebep'   => $sebep,
                'durum'   => $it['state'] ?? null,
            ];
        }

        $durum    = (string)($ilk['state'] ?? '');
        $aciklama = trim(implode(' | ', array_filter([
            $ilk['customerNote'] ?? '', $ilk['vendorNote'] ?? '', $ilk['declineRequestNote'] ?? ''
        ])));

        return [
            'disId'      => (string)($i['id'] ?? ''),
            'siparisNo'  => (string)($i['orderNumber'] ?? ''),
            'paketNo'    => ($i['cargoKey'] ?? null) ?: null,
            'durumKod'   => $this->iadeDurumKodu($durum),
            'hamDurum'   => $durum !== '' ? $durum : null,
            'sebep'      => $kalemler[0]['sebep'] ?? null,
            'aciklama'   => $aciklama !== '' ? $aciklama : null,
            'musteriAdi' => $i['customerName'] ?? null,
            'tutar'      => array_sum(array_map(fn($k) => (float)($k['fiyat'] ?? 0), $kalemler)) ?: null,
            'iadeTarihi' => $this->isoTarih($i['createdAt'] ?? null),
            'kalemler'   => $kalemler,
            'ham'        => $i
        ];
    }

    /** idefix iade durumu (doküman) → yerel kod */
    private function iadeDurumKodu($durum)
    {
        switch ($durum) {
            case 'ready':
            case 'in_cargo':
            case 'waiting_vendor_approve': return 'acik';
            case 'approved':               return 'onaylandi';
            case 'decline':
            case 'vendor_decline_request': return 'reddedildi';
            default:                       return 'diger';
        }
    }
}
