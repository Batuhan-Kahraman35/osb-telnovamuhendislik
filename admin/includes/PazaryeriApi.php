<?php
/**
 * Pazaryeri Entegrasyonu — Soyut Temel Sınıf (ortak gövde)
 *
 * Kaynak: TamRenk ortak sistemi (Ortak Entegrasyon Şeması.txt, bölüm 2).
 * OrnekFirma OSB uyarlaması (23.09.2026):
 *   - Kanal yapısı OrnekFirma kolonlarıdır: Entegrasyon / EntegrasyonKanallari
 *     (Kanal_Kullanici, Kanal_Sifre, Kanal_ApiKey, Kanal_Parametreler JSON).
 *   - Firma katmanı (tanim_Firmalar) yoktur; sahiplik kanal bazlıdır.
 *   - Yerel ürün tablosu Urun_Hizmet'tir (urun_hizmet_id).
 *   - Log: EntegrasyonLoglari (Log_*); yalnız yazma istekleri ve hatalar loglanır.
 *   - Ürün gönderme akışı (kategori / öznitelik / marka / kargo) bu sürümde YOK;
 *     ürünler pazaryerinden çekilir ve listelenir (urunKaydet).
 *
 * Tasarım ilkesi:
 *   "Ne çekileceği" alt sınıfın işi, "çekilenle ne yapılacağı" ortak sınıfın işi.
 *   Alt sınıf API'den nötr bir dizi döndürür; veritabanına yazma, doğrulama,
 *   hata yönetimi ve durum takibi burada tek yerde yaşar.
 *
 * Alt sınıf sözleşmesi (zorunlu):
 *   baseUrl($hizmet)   → API kök adresi (hizmet bazlı; Hepsiburada 3 sunucu kullanır)
 *   authHeaders()      → ['Authorization: ...', 'User-Agent: ...']
 *   configDogrula()    → eksik ayar varsa hata metni, yoksa ''
 * İsteğe bağlı:
 *   configEsle()       → OrnekFirma kanal kolonlarını pazaryerinin beklediği anahtarlara eşler
 *
 * Diğer iş metotları soyut DEĞİLDİR; varsayılan olarak "desteklenmiyor" döner.
 */

require_once __DIR__ . '/../db.php';

abstract class PazaryeriApi
{
    /** Log'a yazılırken maskelenecek ayar anahtarları */
    const GIZLI_ANAHTARLAR = [
        'ApiSecret', 'Sifre', 'Password', 'Secret', 'Token', 'ApiKey', 'Kullanici',
        'SecretKey', 'AppSecret', 'AppKey', 'AccessToken', 'RefreshToken', 'ClientSecret'
    ];

    /** Yanıt gövdesinin log'a yazılacak azami karakter sayısı */
    const LOG_YANIT_LIMIT = 4000;

    protected $db;
    protected $config = [];
    protected $pazaryeriId = null;
    protected $pazaryeriKod = '';
    protected $pazaryeriAdi = '';
    protected $entegrasyonId = null;
    protected $kanalId = null;
    protected $kanalAdi = '';
    protected $yetenekler = [];
    protected $userId = null;
    protected $hata = '';

    /** Uç bazlı son istek zamanı (hız sınırlayıcı için) — mikro saniye */
    private $sonIstekZamani = [];

    /**
     * @param string   $pazaryeriKod tanim_Pazaryerleri.Pazaryerleri_Kod
     * @param int|null $userId       işlemi yapan kullanıcı (log ve kayıt alanları için)
     * @param int|null $kanalId      belirli bir kanal; null ise varsayılan kanal
     */
    public function __construct($pazaryeriKod, $userId = null, $kanalId = null)
    {
        $this->db = Database::getInstance();
        $this->pazaryeriKod = $pazaryeriKod;
        $this->userId = $userId;
        $this->kanalId = $kanalId !== null ? (int)$kanalId : null;
        $this->configYukle();
    }

    // =================================================================
    // Yapılandırma
    // =================================================================

    /**
     * Pazaryeri + entegrasyon + kanal ayarlarını yükle ve birleştir.
     * Kanal belirtilmişse o kanal, belirtilmemişse varsayılan aktif kanal alınır.
     *
     * OrnekFirma kanal kolonları standart anahtarlara açılır:
     *   BaseURL ← Entegrasyon_BaseUrl, Kullanici ← Kanal_Kullanici, Sifre ← Kanal_Sifre,
     *   ApiKey ← Kanal_ApiKey, Endpoint ← Kanal_Endpoint, Ortam ← Kanal_Ortam
     * Kanal_Parametreler JSON'u bunların üzerine yazılır; pazaryerine özgü eşleme
     * alt sınıfın configEsle() metodunda yapılır.
     */
    protected function configYukle()
    {
        $sql = "
            SELECT TOP 1
                p.Pazaryerleri_id,
                p.Pazaryerleri_Ad,
                p.Pazaryerleri_Yetenekler,
                e.Entegrasyon_Id,
                e.Entegrasyon_BaseUrl,
                k.Kanal_Id,
                k.Kanal_Adi,
                k.Kanal_Ortam,
                k.Kanal_Endpoint,
                k.Kanal_Kullanici,
                k.Kanal_Sifre,
                k.Kanal_ApiKey,
                k.Kanal_Parametreler
            FROM dbo.tanim_Pazaryerleri p
            INNER JOIN dbo.Entegrasyon e
                ON e.Entegrasyon_Kod = p.Pazaryerleri_EntegrasyonKod
               AND e.Entegrasyon_Durum = 1
            INNER JOIN dbo.EntegrasyonKanallari k
                ON k.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
               AND k.Kanal_Durum = 1
            WHERE p.Pazaryerleri_Kod = ?
              AND p.Durum = 1
        ";
        $params = [$this->pazaryeriKod];

        if ($this->kanalId !== null) {
            $sql .= " AND k.Kanal_Id = ? ";
            $params[] = $this->kanalId;
        }

        $sql .= " ORDER BY k.Kanal_Varsayilan DESC, k.Kanal_Id ASC";

        $row = $this->db->fetchOne($sql, $params);

        if (!$row) {
            $this->hata = $this->pazaryeriKod . ' entegrasyonu tanımlı veya aktif değil. '
                        . 'Entegrasyon Yönetimi sayfasını kontrol edin.';
            return;
        }

        $this->pazaryeriId   = (int)$row['Pazaryerleri_id'];
        $this->pazaryeriAdi  = $row['Pazaryerleri_Ad'] ?? '';
        $this->entegrasyonId = (int)$row['Entegrasyon_Id'];
        $this->kanalId       = (int)$row['Kanal_Id'];
        $this->kanalAdi      = $row['Kanal_Adi'] ?? '';

        $temel = array_filter([
            'BaseURL'   => $row['Entegrasyon_BaseUrl'] ?? null,
            'Kullanici' => $row['Kanal_Kullanici']     ?? null,
            'Sifre'     => $row['Kanal_Sifre']         ?? null,
            'ApiKey'    => $row['Kanal_ApiKey']        ?? null,
            'Endpoint'  => $row['Kanal_Endpoint']      ?? null,
            'Ortam'     => $row['Kanal_Ortam']         ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $kanalAyar    = json_decode($row['Kanal_Parametreler'] ?? '{}', true) ?: [];
        $this->config = array_merge($temel, $kanalAyar);
        $this->configEsle();

        $this->yetenekler = json_decode($row['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: [];

        $this->hata = $this->configDogrula();
    }

    /** Alt sınıf OrnekFirma kolonlarını kendi beklediği anahtarlara eşler (varsayılan: yok) */
    protected function configEsle()
    {
    }

    // --- Alt sınıfın doldurması zorunlu olanlar ----------------------

    /**
     * @param string $hizmet 'varsayilan' | 'katalog' | 'listing' | 'siparis' ...
     *                       Tek adres kullanan pazaryerleri parametreyi yok sayar.
     */
    abstract protected function baseUrl($hizmet = 'varsayilan');
    abstract protected function authHeaders();
    abstract protected function configDogrula();

    /**
     * Bu pazaryerinin beklediği kimlik alanları (kanal düzenleme formu için).
     * @return array [['anahtar'=>, 'etiket'=>, 'gizli'=>bool, 'zorunlu'=>bool, 'aciklama'=>], ...]
     */
    public static function kimlikAlanlari()
    {
        return [];
    }

    // --- Durum sorguları ---------------------------------------------

    public function hazirMi()         { return $this->hata === ''; }
    public function getHata()         { return $this->hata; }
    public function getPazaryeriId()  { return $this->pazaryeriId; }
    public function getPazaryeriKod() { return $this->pazaryeriKod; }
    public function getPazaryeriAdi() { return $this->pazaryeriAdi; }
    public function getKanalId()      { return $this->kanalId; }
    public function getKanalAdi()     { return $this->kanalAdi; }

    public function getConfig($anahtar, $varsayilan = null)
    {
        return $this->config[$anahtar] ?? $varsayilan;
    }

    /** Bu pazaryeri ilgili yeteneği destekliyor mu? */
    public function yetenek($ad)
    {
        return !empty($this->yetenekler[$ad]);
    }

    public function getYetenekler() { return $this->yetenekler; }

    /** Desteklenmeyen yetenek için standart yanıt */
    protected function desteklenmiyor($ad)
    {
        return [
            'basarili' => false,
            'hata'     => $this->pazaryeriAdi . ' bu işlemi desteklemiyor (' . $ad . ').',
            'veri'     => null
        ];
    }

    // =================================================================
    // Ayar çözümleme
    // =================================================================

    /**
     * Ayarı en özelden en genele doğru çöz; ilk dolu bulunan kazanır.
     *   1. Kanal bazlı       → PazaryeriAyarlari.kanal_id dolu
     *   2. Pazaryeri bazlı   → PazaryeriAyarlari.Pazaryerleri_id dolu
     *   3. Genel varsayılan  → ikisi de NULL
     */
    public function ayar($anahtar, $varsayilan = null)
    {
        static $onbellek = [];
        $anahtarTam = $this->kanalId . '|' . $this->pazaryeriId . '|' . $anahtar;

        if (array_key_exists($anahtarTam, $onbellek)) {
            return $onbellek[$anahtarTam];
        }

        $row = $this->db->fetchOne("
            SELECT TOP 1 PazaryeriAyarlari_Deger, PazaryeriAyarlari_Tip
            FROM dbo.PazaryeriAyarlari
            WHERE PazaryeriAyarlari_Anahtar = ?
              AND Durum = 1
              AND (
                    kanal_id = ?
                 OR (kanal_id IS NULL AND Pazaryerleri_id = ?)
                 OR (kanal_id IS NULL AND Pazaryerleri_id IS NULL)
              )
            ORDER BY
                CASE WHEN kanal_id IS NOT NULL THEN 1
                     WHEN Pazaryerleri_id IS NOT NULL THEN 2
                     ELSE 3 END
        ", [$anahtar, $this->kanalId, $this->pazaryeriId]);

        if (!$row || $row['PazaryeriAyarlari_Deger'] === null) {
            return $onbellek[$anahtarTam] = $varsayilan;
        }

        $deger = $row['PazaryeriAyarlari_Deger'];

        switch ($row['PazaryeriAyarlari_Tip']) {
            case 'sayi': $deger = is_numeric($deger) ? $deger + 0 : $varsayilan; break;
            case 'bit':  $deger = (int)$deger === 1;                             break;
            case 'json': $deger = json_decode($deger, true) ?: $varsayilan;      break;
        }

        return $onbellek[$anahtarTam] = $deger;
    }

    // =================================================================
    // Taşıyıcı katman — hız sınırı, JSON, SOAP
    // =================================================================

    /** Uç bazlı hız sınırı; sınır kanal parametrelerindeki HizSiniriSaniye değeridir */
    protected function hizSiniriBekle($uc)
    {
        $sinir = (float)$this->getConfig('HizSiniriSaniye', 0);
        if ($sinir <= 0) {
            return;
        }

        $simdi = microtime(true);
        if (isset($this->sonIstekZamani[$uc])) {
            $gecen = $simdi - $this->sonIstekZamani[$uc];
            if ($gecen < $sinir) {
                usleep((int)round(($sinir - $gecen) * 1000000));
            }
        }
        $this->sonIstekZamani[$uc] = microtime(true);
    }

    /**
     * Tarih aralığını API'nin kabul ettiği dilimlere böl.
     * Trendyol sipariş/soru sorgusunda aralık en fazla 14 gün olabilir; daha geniş
     * aralık hata vermeden EKSİK döner (tuzak 21).
     *
     * @return array [['bas'=>timestamp,'bit'=>timestamp], ...]
     */
    protected function tarihDilimle($baslangic, $bitis, $gunSiniri = null)
    {
        $gunSiniri = $gunSiniri ?: (int)$this->ayar('SiparisCekmeGun', 14);
        if ($gunSiniri <= 0) { $gunSiniri = 14; }

        $adim = $gunSiniri * 86400;
        $dilimler = [];

        for ($bas = $baslangic; $bas < $bitis; $bas += $adim) {
            $dilimler[] = ['bas' => $bas, 'bit' => min($bas + $adim, $bitis)];
        }

        return $dilimler ?: [['bas' => $baslangic, 'bit' => $bitis]];
    }

    // --- Token yaşam döngüsü (OAuth2 tabanlı pazaryerleri) -----------

    /** Sabit başlıkla kimlik doğrulayanlarda her zaman true; Amazon SP-API ezer */
    protected function tokenGecerliMi()
    {
        return true;
    }

    /** Jetonu yenile. Varsayılan: yapacak iş yok. */
    protected function tokenYenile()
    {
        return true;
    }

    /** Yenilenen jetonu kanal parametrelerine (Kanal_Parametreler JSON) kalıcı yaz */
    protected function tokenKaydet(array $yeniAyarlar)
    {
        $this->config = array_merge($this->config, $yeniAyarlar);

        $kanal  = $this->db->fetchOne(
            "SELECT Kanal_Parametreler FROM dbo.EntegrasyonKanallari WHERE Kanal_Id = ?",
            [$this->kanalId]
        );
        $mevcut = json_decode($kanal['Kanal_Parametreler'] ?? '{}', true) ?: [];

        $this->db->update('EntegrasyonKanallari', [
            'Kanal_Parametreler'   => json_encode(array_merge($mevcut, $yeniAyarlar),
                                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'GuncelleyenKullanici' => $this->userId ?? 0,
            'GuncellemeTarihi'     => date('Y-m-d H:i:s')
        ], ['Kanal_Id' => $this->kanalId]);
    }

    /**
     * JSON API isteği gönder
     *
     * @param string     $method GET|POST|PUT|DELETE
     * @param string     $path   baseUrl sonrası yol (/ ile başlar)
     * @param array|null $body   POST/PUT gövdesi
     * @param array      $query  sorgu parametreleri
     * @param string     $islem  log'da görünecek işlem adı
     * @param string     $hizmet baseUrl() için hizmet adı
     *
     * @return array ['basarili'=>bool,'httpKod'=>int,'veri'=>array|null,'hata'=>string]
     */
    protected function istek($method, $path, $body = null, array $query = [], $islem = '', $hizmet = 'varsayilan')
    {
        if (!$this->hazirMi()) {
            return ['basarili' => false, 'httpKod' => 0, 'veri' => null, 'hata' => $this->hata];
        }

        // OAuth2 kullanan pazaryerlerinde jeton süresi dolmuşsa yenile
        if (!$this->tokenGecerliMi() && !$this->tokenYenile()) {
            return ['basarili' => false, 'httpKod' => 0, 'veri' => null,
                    'hata' => 'Erişim jetonu yenilenemedi.'];
        }

        $method = strtoupper($method);
        $url = rtrim($this->baseUrl($hizmet), '/') . $path;
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $this->hizSiniriBekle($hizmet . '|' . strtok($path, '?'));

        $govde = $body === null
            ? null
            : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = array_merge($this->authHeaders(), [
            'Accept: application/json',
            'Content-Type: application/json'
        ]);

        $islem = $islem !== '' ? $islem : ($method . ' ' . $path);

        $httpKod = 0;
        $yanit = '';
        $curlHata = '';
        $sureMs = 0;
        $denemeSayisi = 0;

        // 429 (hız sınırı) ve 5xx için sınırlı yeniden deneme (1 ve 2 sn bekleme)
        while ($denemeSayisi < 3) {
            $denemeSayisi++;
            $start = microtime(true);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            if ($govde !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $govde);
            }

            $yanit    = curl_exec($ch);
            $httpKod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlHata = curl_error($ch);
            curl_close($ch);

            $sureMs = (int)round((microtime(true) - $start) * 1000);

            if ($yanit === false) {
                $yanit = '';
                if ($denemeSayisi < 3) { usleep(500000 * $denemeSayisi); continue; }
                break;
            }

            if ($httpKod === 429 || $httpKod >= 500) {
                if ($denemeSayisi < 3) { sleep($denemeSayisi); continue; }
            }
            break;
        }

        $veri = null;
        $hata = '';

        if ($curlHata !== '' && $yanit === '') {
            $hata = 'Bağlantı hatası: ' . $curlHata;
        } else {
            $cozulen = json_decode($yanit, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $veri = $cozulen;
            }

            if ($httpKod < 200 || $httpKod >= 300) {
                $hata = $this->hataMesajiCoz($httpKod, $veri, $yanit);
            }
        }

        // Yazma istekleri ve tüm hatalar loglanır; başarılı okumalar (saatlik senkron) loglanmaz
        if ($method !== 'GET' || $hata !== '') {
            $this->logla($islem, $method, $url, $body, $yanit, $httpKod, $sureMs,
                         $hata . ($denemeSayisi > 1 ? ' (' . ($denemeSayisi - 1) . ' tekrar)' : ''));
        }

        return [
            'basarili' => $hata === '',
            'httpKod'  => $httpKod,
            'veri'     => $veri,
            'hata'     => $hata
        ];
    }

    /**
     * SOAP isteği gönder (SOAP kullanan pazaryerleri için).
     * @return array ['basarili'=>bool,'httpKod'=>int,'xml'=>SimpleXMLElement|null,'hata'=>string]
     */
    protected function soapIstek($url, $zarf, $islem = 'SOAP', $soapAction = '')
    {
        if (!$this->hazirMi()) {
            return ['basarili' => false, 'httpKod' => 0, 'xml' => null, 'hata' => $this->hata];
        }

        $this->hizSiniriBekle('soap|' . $islem);

        $basliklar = ['Content-Type: text/xml; charset=utf-8'];
        if ($soapAction !== '') {
            $basliklar[] = 'SOAPAction: "' . $soapAction . '"';
        }

        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $zarf,
            CURLOPT_HTTPHEADER     => $basliklar,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $yanit    = curl_exec($ch);
        $httpKod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        curl_close($ch);
        $sureMs = (int)round((microtime(true) - $start) * 1000);

        $hata = '';
        $xml  = null;

        if ($yanit === false || $curlHata !== '') {
            $hata = 'Bağlantı hatası: ' . $curlHata;
            $yanit = '';
        } else {
            $xml = @simplexml_load_string($yanit);
            if ($xml === false) {
                $xml  = null;
                $hata = 'SOAP yanıtı çözümlenemedi (HTTP ' . $httpKod . ').';
            } else {
                foreach ($xml->xpath('//result') ?: [] as $r) {
                    $durum = strtolower((string)($r->status ?? ''));
                    $mesaj = (string)($r->errorMessage ?? '');
                    if ($durum === 'failure' || $mesaj !== '') {
                        $hata = $mesaj !== '' ? $mesaj : 'SOAP isteği başarısız.';
                        break;
                    }
                }
                foreach ($xml->xpath('//*[local-name()="Fault"]') ?: [] as $f) {
                    $hata = trim((string)($f->faultstring ?? 'SOAP Fault'));
                    break;
                }
            }
        }

        // Zarf içinde kimlik bilgisi olduğu için log'a gövde yazılmaz
        $this->logla($islem, 'POST', $url, null, $yanit, $httpKod, $sureMs, $hata);

        return ['basarili' => $hata === '', 'httpKod' => $httpKod, 'xml' => $xml, 'hata' => $hata];
    }

    /**
     * API hata gövdesinden okunabilir mesaj üret. Türetilen sınıflar ezebilir.
     * Desteklenen biçimler: errors[] | message/... | error.errors[].title (Trendyol)
     */
    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if (is_array($veri)) {
            if (!empty($veri['errors']) && is_array($veri['errors'])) {
                $parcalar = [];
                foreach ($veri['errors'] as $e) {
                    $parcalar[] = is_array($e)
                        ? ($e['message'] ?? $e['errorMessage'] ?? $e['title'] ?? json_encode($e, JSON_UNESCAPED_UNICODE))
                        : (string)$e;
                }
                return 'HTTP ' . $httpKod . ': ' . implode(' | ', $parcalar);
            }
            if (!empty($veri['error']['errors']) && is_array($veri['error']['errors'])) {
                $parcalar = [];
                foreach ($veri['error']['errors'] as $e) {
                    $parcalar[] = $e['title'] ?? $e['message'] ?? ($e['type'] ?? '');
                }
                return 'HTTP ' . $httpKod . ': ' . implode(' | ', array_filter($parcalar));
            }
            foreach (['message', 'Message', 'errorMessage', 'error', 'title', 'detail'] as $anahtar) {
                if (!empty($veri[$anahtar]) && is_string($veri[$anahtar])) {
                    return 'HTTP ' . $httpKod . ': ' . $veri[$anahtar];
                }
            }
        }

        $ozet = mb_substr(trim((string)$hamYanit), 0, 500);
        return 'HTTP ' . $httpKod . ($ozet !== '' ? ': ' . $ozet : '');
    }

    // =================================================================
    // Loglama ve maskeleme
    // =================================================================

    /** EntegrasyonLoglari (OrnekFirma kolonları); kimlik bilgileri maskelenir */
    protected function logla($islem, $method, $url, $body, $hamYanit, $httpKod, $sureMs, $hata)
    {
        try {
            $istekJson = json_encode([
                'method' => $method,
                'url'    => $this->maskeleMetin($url),
                'body'   => is_array($body) ? $this->maskeleDizi($body) : null
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $yanitOzet = mb_substr($this->maskeleMetin((string)$hamYanit), 0, self::LOG_YANIT_LIMIT);

            $this->db->insert('EntegrasyonLoglari', [
                'Log_Kanal_Id'     => $this->kanalId,
                'Log_Islem'        => mb_substr($islem, 0, 200),
                'Log_Alici'        => '',
                'Log_Konu'         => $this->pazaryeriAdi,
                'Log_Mesaj'        => mb_substr($method . ' ' . $this->maskeleMetin($url), 0, 500),
                'Log_Istek'        => $istekJson,
                'Log_Yanit'        => $yanitOzet,
                'Log_Durum'        => $hata === '' ? 'BASARILI' : 'HATA',
                'Log_Hata_Kodu'    => $httpKod ? (string)$httpKod : null,
                'Log_Hata_Mesaj'   => $hata !== '' ? mb_substr($hata, 0, 2000) : null,
                'Log_Sure_Ms'      => $sureMs,
                'Log_IP'           => $_SERVER['REMOTE_ADDR'] ?? null,
                'Log_Kullanici_Id' => $this->userId,
            ]);
        } catch (Throwable $e) {
            error_log('PazaryeriApi log hatası: ' . $e->getMessage());
        }
    }

    protected function maskeleDizi(array $veri)
    {
        foreach ($veri as $anahtar => $deger) {
            if (is_array($deger)) {
                $veri[$anahtar] = $this->maskeleDizi($deger);
            } elseif (in_array($anahtar, self::GIZLI_ANAHTARLAR, true)) {
                $veri[$anahtar] = '***';
            }
        }
        return $veri;
    }

    protected function maskeleMetin($metin)
    {
        $metin = (string)$metin;
        foreach (self::GIZLI_ANAHTARLAR as $anahtar) {
            $deger = $this->config[$anahtar] ?? '';
            if (is_string($deger) && strlen($deger) >= 6) {
                $metin = str_replace($deger, '***', $metin);
            }
        }
        return $metin;
    }

    // =================================================================
    // Sipariş / iade / soru / ürün yazıcıları — ORTAK
    // =================================================================

    /**
     * Siparişi ve kalemlerini kaydet (varsa güncelle).
     * Yerel bağlar (Cari_id, Fatura_id, Siparis_id) güncellemede EZİLMEZ.
     *
     * Nötr sipariş: disId, siparisNo, paketNo, durumKod, hamDurum, toplamTutar, paraBirimi,
     *   musteriAdi, aliciAdi, eposta, telefon, tckn, vergiNo, vergiDairesi, il, ilce, semt,
     *   teslimatAdresi{}, faturaAdresi{}, kargoFirmasi, kargoTakipNo, kargoBarkod,
     *   kargoyaVerilme, desi, siparisTarihi, terminTarihi, tahminiTeslim, kalemler[], ham
     *
     * @return int|false sipariş id
     */
    protected function siparisKaydet(array $s)
    {
        $disId = (string)($s['disId'] ?? '');
        if ($disId === '') {
            return false;
        }

        $simdi = date('Y-m-d H:i:s');

        $veri = [
            'PazaryeriSiparisleri_SiparisNo'      => $s['siparisNo']      ?? null,
            'PazaryeriSiparisleri_PaketNo'        => $s['paketNo']        ?? null,
            'PazaryeriSiparisleri_DurumKod'       => $s['durumKod']       ?? null,
            'PazaryeriSiparisleri_HamDurum'       => $s['hamDurum']       ?? null,
            'PazaryeriSiparisleri_ToplamTutar'    => $s['toplamTutar']    ?? null,
            'PazaryeriSiparisleri_ParaBirimi'     => $s['paraBirimi']     ?? 'TRY',
            'PazaryeriSiparisleri_MusteriAdi'     => $s['musteriAdi']     ?? null,
            'PazaryeriSiparisleri_AliciAdi'       => $s['aliciAdi']       ?? null,
            'PazaryeriSiparisleri_Eposta'         => $s['eposta']         ?? null,
            'PazaryeriSiparisleri_Telefon'        => $s['telefon']        ?? null,
            'PazaryeriSiparisleri_TCKN'           => $s['tckn']           ?? null,
            'PazaryeriSiparisleri_VergiNo'        => $s['vergiNo']        ?? null,
            'PazaryeriSiparisleri_VergiDairesi'   => $s['vergiDairesi']   ?? null,
            'PazaryeriSiparisleri_Il'             => $s['il']             ?? null,
            'PazaryeriSiparisleri_Ilce'           => $s['ilce']           ?? null,
            'PazaryeriSiparisleri_Semt'           => $s['semt']           ?? null,
            'PazaryeriSiparisleri_TeslimatAdresi' => isset($s['teslimatAdresi']) ? json_encode($s['teslimatAdresi'], JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriSiparisleri_FaturaAdresi'   => isset($s['faturaAdresi'])   ? json_encode($s['faturaAdresi'],   JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriSiparisleri_KargoFirmasi'   => $s['kargoFirmasi']   ?? null,
            'PazaryeriSiparisleri_KargoTakipNo'   => $s['kargoTakipNo']   ?? null,
            'PazaryeriSiparisleri_KargoBarkod'    => $s['kargoBarkod']    ?? null,
            'PazaryeriSiparisleri_KargoyaVerilme' => $s['kargoyaVerilme'] ?? null,
            'PazaryeriSiparisleri_Desi'           => $s['desi']           ?? null,
            'PazaryeriSiparisleri_SiparisTarihi'  => $s['siparisTarihi']  ?? null,
            'PazaryeriSiparisleri_TerminTarihi'   => $s['terminTarihi']   ?? null,
            'PazaryeriSiparisleri_TahminiTeslim'  => $s['tahminiTeslim']  ?? null,
            'PazaryeriSiparisleri_HamVeri'        => isset($s['ham']) ? json_encode($s['ham'], JSON_UNESCAPED_UNICODE) : null,
            'Durum'                               => 1
        ];

        $mevcut = $this->db->fetchOne("
            SELECT PazaryeriSiparisleri_id FROM dbo.PazaryeriSiparisleri
            WHERE kanal_id = ? AND PazaryeriSiparisleri_DisId = ?
        ", [$this->kanalId, $disId]);

        if ($mevcut) {
            $veri['GuncelleyenKullanici'] = $this->userId;
            $veri['GuncellemeTarihi']     = $simdi;
            $this->db->update('PazaryeriSiparisleri', $veri,
                ['PazaryeriSiparisleri_id' => $mevcut['PazaryeriSiparisleri_id']]);
            $siparisId = (int)$mevcut['PazaryeriSiparisleri_id'];
        } else {
            $veri['kanal_id']                   = $this->kanalId;
            $veri['Pazaryerleri_id']            = $this->pazaryeriId;
            $veri['PazaryeriSiparisleri_DisId'] = $disId;
            $veri['OlusturanKullanici']         = $this->userId;
            $veri['OlusturmaTarihi']            = $simdi;
            $this->db->insert('PazaryeriSiparisleri', $veri);
            $siparisId = (int)$this->db->getLastInsertId();
        }

        if ($siparisId > 0 && !empty($s['kalemler'])) {
            $this->siparisKalemleriKaydet($siparisId, $s['kalemler']);
        }

        return $siparisId;
    }

    /**
     * Sipariş kalemlerini yaz (mevcutlar silinip yeniden yazılır).
     * Yerel ürün eşleşmesi barkod → PazaryeriUrunleri.urun_hizmet_id üzerinden bulunur.
     */
    protected function siparisKalemleriKaydet($siparisId, array $kalemler)
    {
        $this->db->delete('PazaryeriSiparisKalemleri', ['PazaryeriSiparisleri_id' => $siparisId]);
        $simdi = date('Y-m-d H:i:s');

        foreach ($kalemler as $k) {
            $urunId = $k['urunId'] ?? null;
            if ($urunId === null && !empty($k['barkod'])) {
                $esli   = $this->db->fetchOne("
                    SELECT urun_hizmet_id FROM dbo.PazaryeriUrunleri
                    WHERE kanal_id = ? AND PazaryeriUrunleri_Barkod = ? AND urun_hizmet_id IS NOT NULL
                ", [$this->kanalId, $k['barkod']]);
                $urunId = $esli['urun_hizmet_id'] ?? null;
            }

            $this->db->insert('PazaryeriSiparisKalemleri', [
                'PazaryeriSiparisleri_id'                => $siparisId,
                'PazaryeriSiparisKalemleri_DisId'        => $k['disId']        ?? null,
                'PazaryeriSiparisKalemleri_Barkod'       => $k['barkod']       ?? null,
                'PazaryeriSiparisKalemleri_StokKodu'     => $k['stokKodu']     ?? null,
                'PazaryeriSiparisKalemleri_DisUrunId'    => $k['disUrunId']    ?? null,
                'PazaryeriSiparisKalemleri_UrunAdi'      => $k['urunAdi']      ?? null,
                'urun_hizmet_id'                         => $urunId,
                'PazaryeriSiparisKalemleri_Miktar'       => $k['miktar']       ?? 1,
                'PazaryeriSiparisKalemleri_BirimFiyat'   => $k['birimFiyat']   ?? null,
                'PazaryeriSiparisKalemleri_ToplamTutar'  => $k['toplamTutar']  ?? null,
                'PazaryeriSiparisKalemleri_IndirimTutar' => $k['indirimTutar'] ?? null,
                'PazaryeriSiparisKalemleri_KdvOran'      => $k['kdvOran']      ?? null,
                'PazaryeriSiparisKalemleri_KdvTutar'     => $k['kdvTutar']     ?? null,
                'PazaryeriSiparisKalemleri_KomisyonOran' => $k['komisyonOran'] ?? null,
                'PazaryeriSiparisKalemleri_KomisyonTutar'=> $k['komisyonTutar']?? null,
                'PazaryeriSiparisKalemleri_Desi'         => $k['desi']         ?? null,
                'PazaryeriSiparisKalemleri_HamDurum'     => $k['hamDurum']     ?? null,
                'PazaryeriSiparisKalemleri_HamVeri'      => isset($k['ham']) ? json_encode($k['ham'], JSON_UNESCAPED_UNICODE) : null,
                'OlusturanKullanici'                     => $this->userId,
                'OlusturmaTarihi'                        => $simdi,
                'Durum'                                  => 1
            ]);
        }
    }

    /** İade kaydet (varsa güncelle) */
    protected function iadeKaydet(array $i)
    {
        $disId = (string)($i['disId'] ?? '');
        if ($disId === '') {
            return false;
        }

        $simdi = date('Y-m-d H:i:s');

        $veri = [
            'PazaryeriIadeler_SiparisNo'  => $i['siparisNo']  ?? null,
            'PazaryeriIadeler_PaketNo'    => $i['paketNo']    ?? null,
            'PazaryeriIadeler_DurumKod'   => $i['durumKod']   ?? null,
            'PazaryeriIadeler_HamDurum'   => $i['hamDurum']   ?? null,
            'PazaryeriIadeler_Sebep'      => $i['sebep']      ?? null,
            'PazaryeriIadeler_Aciklama'   => $i['aciklama']   ?? null,
            'PazaryeriIadeler_MusteriAdi' => $i['musteriAdi'] ?? null,
            'PazaryeriIadeler_Tutar'      => $i['tutar']      ?? null,
            'PazaryeriIadeler_IadeTarihi' => $i['iadeTarihi'] ?? null,
            'PazaryeriIadeler_Urunler'    => isset($i['kalemler']) ? json_encode($i['kalemler'], JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriIadeler_HamVeri'    => isset($i['ham'])      ? json_encode($i['ham'],      JSON_UNESCAPED_UNICODE) : null,
            'Durum'                       => 1
        ];

        $mevcut = $this->db->fetchOne("
            SELECT PazaryeriIadeler_id FROM dbo.PazaryeriIadeler
            WHERE kanal_id = ? AND PazaryeriIadeler_DisId = ?
        ", [$this->kanalId, $disId]);

        if ($mevcut) {
            $veri['GuncelleyenKullanici'] = $this->userId;
            $veri['GuncellemeTarihi']     = $simdi;
            $this->db->update('PazaryeriIadeler', $veri,
                ['PazaryeriIadeler_id' => $mevcut['PazaryeriIadeler_id']]);
            return (int)$mevcut['PazaryeriIadeler_id'];
        }

        $veri['kanal_id']               = $this->kanalId;
        $veri['Pazaryerleri_id']        = $this->pazaryeriId;
        $veri['PazaryeriIadeler_DisId'] = $disId;
        $veri['OlusturanKullanici']     = $this->userId;
        $veri['OlusturmaTarihi']        = $simdi;
        $this->db->insert('PazaryeriIadeler', $veri);
        return (int)$this->db->getLastInsertId();
    }

    /**
     * Müşteri sorusunu kaydet (varsa güncelle).
     * Yerel olarak verilmiş cevap, pazaryeri tarafı boş dönerse KORUNUR.
     */
    protected function soruKaydet(array $q)
    {
        $disId = (string)($q['disId'] ?? '');
        if ($disId === '') {
            return false;
        }

        $simdi = date('Y-m-d H:i:s');
        $cevap = $q['cevap'] ?? null;

        $veri = [
            'PazaryeriSorulari_DisUrunId'  => $q['disUrunId']  ?? null,
            'PazaryeriSorulari_UrunAdi'    => $q['urunAdi']    ?? null,
            'PazaryeriSorulari_MusteriAdi' => $q['musteriAdi'] ?? null,
            'PazaryeriSorulari_Konu'       => $q['konu']       ?? null,
            'PazaryeriSorulari_Soru'       => $q['soru']       ?? null,
            'PazaryeriSorulari_DurumKod'   => $q['durumKod']   ?? 'acik',
            'PazaryeriSorulari_HamDurum'   => $q['hamDurum']   ?? null,
            'PazaryeriSorulari_SoruTarihi' => $q['soruTarihi'] ?? null,
            'PazaryeriSorulari_Gorseller'  => isset($q['gorseller']) ? json_encode($q['gorseller'], JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriSorulari_HamVeri'    => isset($q['ham'])       ? json_encode($q['ham'],       JSON_UNESCAPED_UNICODE) : null,
            'Durum'                        => 1
        ];

        if ($cevap !== null && trim((string)$cevap) !== '') {
            $veri['PazaryeriSorulari_Cevap']       = $cevap;
            $veri['PazaryeriSorulari_CevapTarihi'] = $q['cevapTarihi'] ?? $simdi;
        }

        $mevcut = $this->db->fetchOne("
            SELECT PazaryeriSorulari_id FROM dbo.PazaryeriSorulari
            WHERE kanal_id = ? AND PazaryeriSorulari_DisId = ?
        ", [$this->kanalId, $disId]);

        if ($mevcut) {
            $veri['GuncelleyenKullanici'] = $this->userId;
            $veri['GuncellemeTarihi']     = $simdi;
            $this->db->update('PazaryeriSorulari', $veri,
                ['PazaryeriSorulari_id' => $mevcut['PazaryeriSorulari_id']]);
            return (int)$mevcut['PazaryeriSorulari_id'];
        }

        $veri['kanal_id']                = $this->kanalId;
        $veri['Pazaryerleri_id']         = $this->pazaryeriId;
        $veri['PazaryeriSorulari_DisId'] = $disId;
        $veri['OlusturanKullanici']      = $this->userId;
        $veri['OlusturmaTarihi']         = $simdi;
        $this->db->insert('PazaryeriSorulari', $veri);
        return (int)$this->db->getLastInsertId();
    }

    /**
     * Pazaryerinden çekilen satılabilir birimi (barkod) PazaryeriUrunleri'ne yaz (UPSERT).
     * OrnekFirma'ya özgü: ürünler pazaryerinden listelenir; yerel eşleşme (urun_hizmet_id),
     * stok kodu ve ana ürün kodu kullanıcı tarafından atanır, güncellemede EZİLMEZ.
     *
     * Nötr ürün: barkod, stokKodu, anaUrunKodu, varyantDegeri, disUrunId, disUrunKodu, urunUrl,
     *   adi, markaAdi, kategoriAdi, renk, listeFiyat, satisFiyat, stok, kdvOran, gorselUrl,
     *   onayli (bool|null), redSebebi, hamDurum, ham
     *
     * @return string|false 'yeni' | 'guncellendi' | false
     */
    protected function urunKaydet(array $u)
    {
        $barkod = trim((string)($u['barkod'] ?? ''));
        if ($barkod === '') {
            return false;
        }

        $simdi = date('Y-m-d H:i:s');

        $veri = [
            'PazaryeriUrunleri_VaryantDegeri'    => $u['varyantDegeri'] ?? null,
            'PazaryeriUrunleri_DisUrunId'        => isset($u['disUrunId'])   ? (string)$u['disUrunId']   : null,
            'PazaryeriUrunleri_DisUrunKodu'      => isset($u['disUrunKodu']) ? (string)$u['disUrunKodu'] : null,
            'PazaryeriUrunleri_UrunUrl'          => $u['urunUrl']       ?? null,
            'PazaryeriUrunleri_Adi'              => $u['adi']           ?? null,
            'PazaryeriUrunleri_MarkaAdi'         => $u['markaAdi']      ?? null,
            'PazaryeriUrunleri_KategoriAdi'      => $u['kategoriAdi']   ?? null,
            'PazaryeriUrunleri_Renk'             => $u['renk']          ?? null,
            'PazaryeriUrunleri_ListeFiyat'       => $u['listeFiyat']    ?? null,
            'PazaryeriUrunleri_SatisFiyat'       => $u['satisFiyat']    ?? null,
            'PazaryeriUrunleri_Stok'             => isset($u['stok']) ? (int)$u['stok'] : null,
            'PazaryeriUrunleri_KdvOran'          => $u['kdvOran']       ?? null,
            'PazaryeriUrunleri_GorselUrl'        => $u['gorselUrl']     ?? null,
            'PazaryeriUrunleri_Onayli'           => isset($u['onayli']) ? ($u['onayli'] ? 1 : 0) : null,
            'PazaryeriUrunleri_RedSebebi'        => $u['redSebebi']     ?? null,
            'PazaryeriUrunleri_HamDurum'         => $u['hamDurum']      ?? null,
            'PazaryeriUrunleri_SonSenkronTarihi' => $simdi,
            'PazaryeriUrunleri_HamVeri'          => isset($u['ham']) ? json_encode($u['ham'], JSON_UNESCAPED_UNICODE) : null,
            'Durum'                              => 1
        ];

        $mevcut = $this->db->fetchOne("
            SELECT PazaryeriUrunleri_id, PazaryeriUrunleri_StokKodu, PazaryeriUrunleri_AnaUrunKodu
            FROM dbo.PazaryeriUrunleri
            WHERE kanal_id = ? AND PazaryeriUrunleri_Barkod = ?
        ", [$this->kanalId, $barkod]);

        if ($mevcut) {
            // Kullanıcı tarafından atanmış alanlar boşsa pazaryeri değeriyle doldurulur
            if (empty($mevcut['PazaryeriUrunleri_StokKodu'])) {
                $veri['PazaryeriUrunleri_StokKodu'] = $u['stokKodu'] ?? null;
            }
            if (empty($mevcut['PazaryeriUrunleri_AnaUrunKodu'])) {
                $veri['PazaryeriUrunleri_AnaUrunKodu'] = $u['anaUrunKodu'] ?? null;
            }
            $veri['GuncelleyenKullanici'] = $this->userId;
            $veri['GuncellemeTarihi']     = $simdi;
            $this->db->update('PazaryeriUrunleri', $veri,
                ['PazaryeriUrunleri_id' => $mevcut['PazaryeriUrunleri_id']]);
            return 'guncellendi';
        }

        $veri['kanal_id']                      = $this->kanalId;
        $veri['Pazaryerleri_id']               = $this->pazaryeriId;
        $veri['PazaryeriUrunleri_Barkod']      = $barkod;
        $veri['PazaryeriUrunleri_StokKodu']    = $u['stokKodu']    ?? null;
        $veri['PazaryeriUrunleri_AnaUrunKodu'] = $u['anaUrunKodu'] ?? null;
        $veri['PazaryeriUrunleri_SenkronDurum']= 'pazaryerinden';
        $veri['OlusturanKullanici']            = $this->userId;
        $veri['OlusturmaTarihi']               = $simdi;
        $this->db->insert('PazaryeriUrunleri', $veri);
        return 'yeni';
    }

    // =================================================================
    // Toplu istek (batch) takibi — ORTAK
    // =================================================================

    /** Gönderilen toplu isteği takip tablosuna yaz */
    protected function batchKaydet($batchId, $tip, $itemSayisi)
    {
        try {
            $this->db->insert('PazaryeriBatchIstekleri', [
                'Pazaryerleri_id'                    => $this->pazaryeriId,
                'kanal_id'                           => $this->kanalId,
                'PazaryeriBatchIstekleri_BatchId'    => $batchId,
                'PazaryeriBatchIstekleri_Tip'        => $tip,
                'PazaryeriBatchIstekleri_ItemSayisi' => $itemSayisi,
                'PazaryeriBatchIstekleri_Durum'      => 'beklemede',
                'OlusturanKullanici'                 => $this->userId,
                'OlusturmaTarihi'                    => date('Y-m-d H:i:s'),
                'Durum'                              => 1
            ]);
        } catch (Throwable $e) {
            error_log('Batch kaydı hatası: ' . $e->getMessage());
        }
    }

    /**
     * Batch sonucunu işle.
     * Nötr sonuç: ['tamamlandi'=>bool, 'kalemler'=>[['barkod'=>, 'basarili'=>bool, 'hata'=>], ...]]
     */
    protected function batchSonucIsle($batchId, array $sonuc)
    {
        $simdi = date('Y-m-d H:i:s');
        $basarili = 0;
        $hatali   = 0;

        foreach ($sonuc['kalemler'] ?? [] as $kalem) {
            $barkod = (string)($kalem['barkod'] ?? '');
            if ($barkod === '') {
                continue;
            }

            if (!empty($kalem['basarili'])) {
                $basarili++;
                $this->db->update('PazaryeriUrunleri', [
                    'PazaryeriUrunleri_SenkronDurum'     => 'onaylandi',
                    'PazaryeriUrunleri_SonHata'          => null,
                    'PazaryeriUrunleri_SonSenkronTarihi' => $simdi,
                    'GuncellemeTarihi'                   => $simdi
                ], ['kanal_id' => $this->kanalId, 'PazaryeriUrunleri_Barkod' => $barkod]);
            } else {
                $hatali++;
                $this->db->update('PazaryeriUrunleri', [
                    'PazaryeriUrunleri_SenkronDurum'     => 'hata',
                    'PazaryeriUrunleri_SonHata'          => mb_substr((string)($kalem['hata'] ?? ''), 0, 4000),
                    'PazaryeriUrunleri_SonSenkronTarihi' => $simdi,
                    'GuncellemeTarihi'                   => $simdi
                ], ['kanal_id' => $this->kanalId, 'PazaryeriUrunleri_Barkod' => $barkod]);
            }
        }

        $batchDurum = 'beklemede';
        if (!empty($sonuc['tamamlandi'])) {
            $batchDurum = $hatali > 0 ? 'hata' : 'tamamlandi';
        }

        $this->db->update('PazaryeriBatchIstekleri', [
            'PazaryeriBatchIstekleri_Durum'        => $batchDurum,
            'PazaryeriBatchIstekleri_BasariliSayi' => $basarili,
            'PazaryeriBatchIstekleri_HataliSayi'   => $hatali,
            'PazaryeriBatchIstekleri_SonKontrol'   => $simdi,
            'GuncellemeTarihi'                     => $simdi
        ], ['kanal_id' => $this->kanalId, 'PazaryeriBatchIstekleri_BatchId' => $batchId]);

        return [
            'basarili'      => true,
            'hata'          => '',
            'durum'         => $batchDurum,
            'basarili_adet' => $basarili,
            'hatali_adet'   => $hatali
        ];
    }

    // =================================================================
    // Ortak yardımcılar
    // =================================================================

    /**
     * Milisaniye epoch → 'Y-m-d H:i:s' (gerçek UTC epoch; TZ Europe/Istanbul ile yerel saate çevrilir).
     * DİKKAT: Trendyol orderDate yerel saati UTC gibi taşır; onun için msYerelTarih() kullanılır.
     */
    protected function msTarih($ms)
    {
        if (empty($ms)) {
            return null;
        }
        return date('Y-m-d H:i:s', (int)((int)$ms / 1000));
    }

    /** Yerel (İstanbul) saati UTC epoch gibi taşıyan ms değeri → 'Y-m-d H:i:s' (tuzak 22) */
    protected function msYerelTarih($ms)
    {
        if (empty($ms)) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', (int)((int)$ms / 1000));
    }

    /** ISO 8601 → 'Y-m-d H:i:s' */
    protected function isoTarih($metin)
    {
        $metin = trim((string)$metin);
        if ($metin === '') {
            return null;
        }
        $zaman = strtotime($metin);
        return $zaman ? date('Y-m-d H:i:s', $zaman) : null;
    }

    // =================================================================
    // İş metotları — varsayılan olarak desteklenmiyor döner.
    // Alt sınıf yalnızca kendi desteklediklerini ezer.
    // Senkron metotları: ['basarili'=>bool, 'hata'=>string, 'adet'=>int, ...]
    // =================================================================

    public function baglantiTest()                                                  { return $this->desteklenmiyor('bağlantı testi'); }
    public function siparisleriSenkronla($gunSayisi = 2)                            { return $this->desteklenmiyor('sipariş senkronu'); }
    public function iadeleriSenkronla($gunSayisi = 30)                              { return $this->desteklenmiyor('iade senkronu'); }
    public function sorulariSenkronla($gunSayisi = 30)                              { return $this->desteklenmiyor('soru senkronu'); }
    public function urunleriSenkronla()                                             { return $this->desteklenmiyor('ürün senkronu'); }
    public function urunleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)    { return $this->desteklenmiyor('ürün listeleme'); }
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50) { return $this->desteklenmiyor('sipariş listeleme'); }
    public function iadeleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)    { return $this->desteklenmiyor('iade listeleme'); }
    public function sorulariListele(array $filtre = [], $sayfa = 0, $boyut = 50)    { return $this->desteklenmiyor('soru listeleme'); }
    public function fiyatStokGuncelle(array $items)                                 { return $this->desteklenmiyor('fiyat/stok güncelleme'); }
    public function batchDurumGuncelle($batchId)                                    { return $this->desteklenmiyor('toplu işlem sorgulama'); }
    public function soruCevapla($soruDisId, $cevap)                                 { return $this->desteklenmiyor('soru cevaplama'); }
    public function paketDurumGuncelle($paketId, $durum, array $kalemler = [], array $ek = []) { return $this->desteklenmiyor('paket durumu'); }
    public function faturaLinkGonder($paketId, array $fatura)                       { return $this->desteklenmiyor('fatura bildirimi'); }
    public function kargoEtiketiOlustur($kargoTakipNo)                              { return $this->desteklenmiyor('kargo etiketi'); }
    public function kargoEtiketiGetir($kargoTakipNo)                                { return $this->desteklenmiyor('kargo etiketi'); }
}
