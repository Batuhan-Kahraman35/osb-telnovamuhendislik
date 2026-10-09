<?php
/**
 * HalkÖde (Platformode Payment Gateway) Servisi
 *
 * Link ile tahsilat: ödeme linki üretir, müşteriye SMS/e-posta ile iletir,
 * ödeme sonucunu Sale WebHook veya durum sorgulama ile karşılayıp
 * Odeme_Kalemleri kaydını açar.
 *
 * Kimlik bilgileri (Entegrasyon Yönetimi → HalkÖde kanalı):
 *   Kanal_Endpoint : https://app.halkode.com.tr/ccpayment
 *   Kanal_ApiKey   : APP_ID       (uygulama anahtarı)
 *   Kanal_Sifre    : APP_SECRET   (uygulama parolası)
 *   params.merchantKey : Üyeişyeri Anahtarı ($2y$10$... ile başlayan değer)
 *   params.saleWebHookKey : merchant panelinde tanımlı Sale WebHook key adı
 *
 * Servisler:
 *   POST /purchase/link   → ödeme linki (form-urlencoded, invoice JSON string)
 *   POST /api/token       → Bearer token (2 saat geçerli, kanal ayarında saklanır)
 *   POST /api/checkstatus → sipariş durumu (Bearer + hash_key)
 *
 * hash_key: AES-256-CBC ile "iv:salt:sifreli" üçlüsü; '/' karakteri '__' ile
 * değiştirilir. Çözülmüş gövde "status|total|invoice_id|order_id|currency".
 *
 * Mükerrer tahsilat koruması:
 *   - invoice_id (SiparisNo) ve order_id (ProcessId) tabloda UNIQUE
 *   - kalem açma OUTPUT'lu koşullu UPDATE ile tek sefer çalışır; webhook,
 *     return sayfası ve cron aynı işlemi bildirse de ikinci kalem oluşmaz
 *
 * @see temp/HalkOde/Platformode Payment Gateway  Integration V2.pdf
 * @see admin/api/halkode-callback.php
 */

class HalkodeService
{
    /** Entegrasyon tablosundaki kod */
    const ENTEGRASYON_KOD = 'HALKODE';

    /** Link durumları (Halkode_Odeme_Linkleri_DurumKod) */
    const DURUM_OLUSTURULDU = 'OLUSTURULDU';
    const DURUM_GONDERILDI  = 'GONDERILDI';
    const DURUM_ODENDI      = 'ODENDI';
    const DURUM_BASARISIZ   = 'BASARISIZ';
    const DURUM_IPTAL       = 'IPTAL';

    /** Ödeme kaleminin yazılacağı tip */
    const KALEM_TIP = 'KREDI_KARTI';

    /** Platformode başarı durum kodu */
    const BASARI_KODU = '100';

    /** Servis yolları */
    const YOL_LINK    = '/purchase/link';
    const YOL_TOKEN   = '/api/token';
    const YOL_DURUM   = '/api/checkstatus';

    private $db;
    private $kanalCache = null;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────
    // KANAL / AYAR
    // ─────────────────────────────────────────────

    /**
     * Varsayılan HALKODE kanalını (endpoint + anahtarlar) getirir.
     * Anahtarlar Entegrasyon Yönetimi ekranından girilir, kodda tutulmaz.
     */
    private function kanal(): array
    {
        if ($this->kanalCache !== null) {
            return $this->kanalCache;
        }

        $kanal = $this->db->fetchOne("
            SELECT TOP 1 ek.*
            FROM EntegrasyonKanallari ek
            INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
            WHERE e.Entegrasyon_Kod = ?
              AND ek.Kanal_Varsayilan = 1
              AND ek.Kanal_Durum = 1
              AND ek.Durum = 1
            ORDER BY ek.Kanal_Id
        ", [self::ENTEGRASYON_KOD]);

        if (!$kanal) {
            throw new Exception('HalkÖde entegrasyon kanalı bulunamadı veya pasif.');
        }

        $kanal['params'] = !empty($kanal['Kanal_Parametreler'])
            ? (json_decode($kanal['Kanal_Parametreler'], true) ?? [])
            : [];

        if (empty($kanal['Kanal_ApiKey']) || empty($kanal['Kanal_Sifre'])) {
            throw new Exception('HalkÖde kanalında Uygulama Anahtarı (ApiKey) ve Parolası (Şifre) tanımlı değil.');
        }

        if (empty($kanal['params']['merchantKey'])) {
            throw new Exception('HalkÖde kanal parametrelerinde merchantKey (Üyeişyeri Anahtarı) tanımlı değil.');
        }

        $this->kanalCache = $kanal;
        return $kanal;
    }

    private function appId(): string
    {
        return (string)$this->kanal()['Kanal_ApiKey'];
    }

    private function appSecret(): string
    {
        return (string)$this->kanal()['Kanal_Sifre'];
    }

    private function merchantKey(): string
    {
        return (string)$this->kanal()['params']['merchantKey'];
    }

    /** Callback ve return adreslerinin türetildiği site kökü */
    private function siteKoku(): string
    {
        $kanal = $this->kanal();

        if (!empty($kanal['params']['siteUrl'])) {
            return rtrim($kanal['params']['siteUrl'], '/');
        }

        $ayar = $this->db->fetchOne("
            SELECT TOP 1 site_ayarlari_site_url AS url
            FROM dbo.tanim_site_ayarlari
            ORDER BY site_ayarlari_id DESC
        ");

        if (empty($ayar['url'])) {
            throw new Exception('Site adresi bulunamadı; kanal parametrelerine siteUrl ekleyin.');
        }

        // Site ayarındaki adres panel köküne (…/admin) işaret edebiliyor.
        // Callback yolu /admin ile başladığı için sondaki bu parça kırpılır.
        return preg_replace('#/admin/?$#i', '', rtrim($ayar['url'], '/'));
    }

    /** Ödeme kaleminde yazılacak banka adı */
    private function kalemBanka(): string
    {
        return $this->kanal()['params']['odemeKalemBanka'] ?? 'Halkbank';
    }

    // ─────────────────────────────────────────────
    // HASH KEY
    // ─────────────────────────────────────────────

    /**
     * İstek imzası üretir: "invoice_id|merchant_key" verisi APP_SECRET ile
     * AES-256-CBC şifrelenir, "iv:salt:şifreli" biçiminde döner ('/' → '__').
     */
    public function hashKeyUret(string $invoiceId): string
    {
        $veri  = $invoiceId . '|' . $this->merchantKey();
        $iv    = substr(sha1((string)mt_rand()), 0, 16);
        $salt  = substr(sha1((string)mt_rand()), 0, 4);
        $parola = sha1($this->appSecret());
        $saltliParola = hash('sha256', $parola . $salt);

        $sifreli = openssl_encrypt($veri, 'aes-256-cbc', $saltliParola, 0, $iv);

        return str_replace('/', '__', $iv . ':' . $salt . ':' . $sifreli);
    }

    /**
     * Gelen hash_key değerini çözer.
     *
     * @return array|null ['status' => ..., 'total' => ..., 'invoice_id' => ...,
     *                     'order_id' => ..., 'currency' => ...] veya null
     */
    public function hashKeyCoz(string $hashKey): ?array
    {
        if ($hashKey === '') {
            return null;
        }

        $hashKey  = str_replace('__', '/', $hashKey);
        $parcalar = explode(':', $hashKey);

        if (count($parcalar) < 3) {
            return null;
        }

        $iv           = $parcalar[0] ?? '';
        $salt         = $parcalar[1] ?? '';
        $sifreli      = $parcalar[2] ?? '';
        $parola       = sha1($this->appSecret());
        $saltliParola = hash('sha256', $parola . $salt);

        $cozulmus = openssl_decrypt($sifreli, 'aes-256-cbc', $saltliParola, 0, $iv);

        if ($cozulmus === false || strpos($cozulmus, '|') === false) {
            return null;
        }

        $alan = explode('|', $cozulmus);

        return [
            'status'     => $alan[0] ?? '',
            'total'      => $alan[1] ?? 0,
            'invoice_id' => $alan[2] ?? '',
            'order_id'   => $alan[3] ?? '',
            'currency'   => $alan[4] ?? '',
        ];
    }

    // ─────────────────────────────────────────────
    // İSTEK
    // ─────────────────────────────────────────────

    /**
     * Platformode servisine istek gönderir.
     *
     * @param  string $yol    Servis yolu (self::YOL_*)
     * @param  array  $govde  İstek alanları
     * @param  string $bicim  'json' veya 'form'
     * @param  string $token  Bearer token (gerekiyorsa)
     * @return array          Çözümlenmiş yanıt
     */
    private function istek(string $yol, array $govde, string $bicim = 'json', string $token = ''): array
    {
        $baslangic = (int)(microtime(true) * 1000);
        $kanal     = $this->kanal();
        $url       = rtrim($kanal['Kanal_Endpoint'], '/') . $yol;

        $basliklar = ['Accept: application/json'];

        if ($token !== '') {
            $basliklar[] = 'Authorization: Bearer ' . $token;
        }

        if ($bicim === 'form') {
            $veri        = http_build_query($govde);
            $basliklar[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $veri        = json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $basliklar[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $veri,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $basliklar,
        ]);

        $ham      = curl_exec($ch);
        $httpKod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        curl_close($ch);

        // Gizli alanlar loglanmaz
        $logIstek = $govde;
        unset($logIstek['app_secret'], $logIstek['merchant_key']);

        if ($curlHata) {
            $this->log($yol, $logIstek, ['hata' => $curlHata], 'HATA', 'cURL: ' . $curlHata, $baslangic);
            throw new Exception('HalkÖde bağlantı hatası: ' . $curlHata);
        }

        $yanit = json_decode((string)$ham, true);

        if (!is_array($yanit)) {
            $this->log($yol, $logIstek, ['http' => $httpKod, 'govde' => mb_substr((string)$ham, 0, 1000)], 'HATA',
                'Yanıt çözümlenemedi (HTTP ' . $httpKod . ')', $baslangic);
            throw new Exception('HalkÖde servisi beklenmeyen yanıt döndü (HTTP ' . $httpKod . ').');
        }

        // Başarı ölçütü servise göre değişir; log için genel bir değerlendirme
        $basarili = (string)($yanit['status_code'] ?? '') === self::BASARI_KODU
                 || strtolower((string)($yanit['status'] ?? '')) === 'true'
                 || !empty($yanit['link']);

        $this->log($yol, $logIstek, $yanit, $basarili ? 'BASARILI' : 'HATA',
            $basarili ? null : ($yanit['status_description'] ?? $yanit['message'] ?? 'Bilinmeyen hata'),
            $baslangic);

        return $yanit;
    }

    /** EntegrasyonLoglari kaydı — anahtarlar loglanmaz */
    private function log(string $islem, $istek, $yanit, string $durum, ?string $hata, int $baslangic): void
    {
        try {
            $this->db->insert('EntegrasyonLoglari', [
                'Log_Kanal_Id'     => (int)$this->kanal()['Kanal_Id'],
                'Log_Islem'        => $islem,
                'Log_Alici'        => '',
                'Log_Konu'         => 'HalkÖde',
                'Log_Mesaj'        => mb_substr(json_encode($istek, JSON_UNESCAPED_UNICODE), 0, 500),
                'Log_Istek'        => $istek ? json_encode($istek, JSON_UNESCAPED_UNICODE) : null,
                'Log_Yanit'        => $yanit ? json_encode($yanit, JSON_UNESCAPED_UNICODE) : null,
                'Log_Durum'        => $durum,
                'Log_Hata_Mesaj'   => $hata,
                'Log_Sure_Ms'      => (int)((microtime(true) * 1000) - $baslangic),
                'Log_IP'           => $_SERVER['REMOTE_ADDR'] ?? null,
                'Log_Kullanici_Id' => $_SESSION['kullanici_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('HalkÖde log kaydı hatası: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    // TOKEN
    // ─────────────────────────────────────────────

    /**
     * Bearer token döner. Token 2 saat geçerlidir ve kanal parametrelerinde
     * saklanır; süresi dolmadan yeniden istenmez.
     */
    private function token(): string
    {
        $kanal  = $this->kanal();
        $params = $kanal['params'];

        if (!empty($params['_token']) && !empty($params['_token_gecerlilik'])
            && strtotime($params['_token_gecerlilik']) > time() + 60) {
            return (string)$params['_token'];
        }

        $yanit = $this->istek(self::YOL_TOKEN, [
            'app_id'     => $this->appId(),
            'app_secret' => $this->appSecret(),
        ]);

        $token = $yanit['data']['token'] ?? '';

        if ((string)($yanit['status_code'] ?? '') !== self::BASARI_KODU || $token === '') {
            throw new Exception('HalkÖde token alınamadı: ' . ($yanit['status_description'] ?? 'bilinmeyen hata'));
        }

        // Token'ı kanal parametrelerine yaz (panelden düzenlenirse yeniden alınır)
        $params['_token'] = $token;
        $params['_token_gecerlilik'] = $yanit['expires_at']
            ?? date('Y-m-d H:i:s', time() + 7000);

        $this->db->update('EntegrasyonKanallari', [
            'Kanal_Parametreler' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'GuncellemeTarihi'   => date('Y-m-d H:i:s'),
        ], [
            'Kanal_Id' => (int)$kanal['Kanal_Id'],
        ]);

        $this->kanalCache['params'] = $params;

        return $token;
    }

    /** RFC 4122 sürüm 4 UUID */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ─────────────────────────────────────────────
    // LİNK ÜRETİMİ
    // ─────────────────────────────────────────────

    /**
     * Cari için ödeme linki üretir ve takip kaydını açar.
     *
     * Taksit sayısı gönderilmezse müşteri ödeme sayfasında kartının
     * desteklediği taksit seçeneklerinden seçim yapabilir.
     *
     * @param  array $veri cari_id, tutar, taksit, aciklama, telefon, email, kullanici_id
     * @return array       ['link_id' => int, 'link' => string, 'siparis_no' => string]
     */
    public function linkOlustur(array $veri): array
    {
        $cariId    = (int)($veri['cari_id'] ?? 0);
        $tutar     = round((float)($veri['tutar'] ?? 0), 2);
        $taksit    = max(0, (int)($veri['taksit'] ?? 0));   // 0 = müşteri seçsin
        $aciklama  = trim((string)($veri['aciklama'] ?? ''));
        $telefon   = preg_replace('/\D/', '', (string)($veri['telefon'] ?? ''));
        $email     = trim((string)($veri['email'] ?? ''));
        $kullanici = (int)($veri['kullanici_id'] ?? 0);

        if (!$cariId)    throw new Exception('Cari seçilmedi.');
        if ($tutar <= 0) throw new Exception('Tutar sıfırdan büyük olmalıdır.');

        $cari = $this->db->fetchOne("
            SELECT cari_id, ISNULL(cari_unvan, cari_adi) AS unvan, cari_telefon, cari_email
            FROM Cari WHERE cari_id = ?
        ", [$cariId]);

        if (!$cari) {
            throw new Exception('Cari bulunamadı.');
        }

        // invoice_id yalnız harf/rakam içermeli; tire kabul edilmeyebiliyor
        $siparisNo = 'OSB' . date('YmdHis') . random_int(100, 999);
        $simdi     = date('Y-m-d H:i:s');

        // Takip kaydı istek öncesinde açılır: servis yanıt vermese bile hangi
        // sipariş numarasının gönderildiği kayıt altında kalır.
        $linkId = $this->db->insert('Halkode_Odeme_Linkleri', [
            'Halkode_Odeme_Linkleri_Cari_id'    => $cariId,
            'Halkode_Odeme_Linkleri_SiparisNo'  => $siparisNo,
            'Halkode_Odeme_Linkleri_Tutar'      => $tutar,
            'Halkode_Odeme_Linkleri_ParaBirimi' => 'TRY',
            // 0 = taksit ödeme sayfasında müşteri tarafından seçilir
            'Halkode_Odeme_Linkleri_Taksit'     => $taksit,
            'Halkode_Odeme_Linkleri_DurumKod'   => self::DURUM_OLUSTURULDU,
            'Halkode_Odeme_Linkleri_Telefon'    => $telefon ?: null,
            'Halkode_Odeme_Linkleri_Email'      => $email ?: null,
            'Halkode_Odeme_Linkleri_Aciklama'   => $aciklama ?: null,
            'OlusturanKullanici'                => $kullanici,
            'OlusturmaTarihi'                   => $simdi,
            'GuncelleyenKullanici'              => $kullanici,
            'GuncellemeTarihi'                  => $simdi,
            'Durum'                             => 1,
        ]);

        $kok    = $this->siteKoku();
        $sonuc  = $kok . '/admin/api/halkode-callback.php?tur=return';
        $unvan  = trim((string)$cari['unvan']);
        $params = $this->kanal()['params'];

        // Servis name ve surname alanlarını 30 karakterden kısa istiyor.
        // Tüzel kişi unvanları bu sınırı aştığı için unvan ikiye bölünüp
        // her parça kırpılır; tam unvan fatura açıklamasında yer alır.
        $parca = preg_split('/\s+/', $unvan, 2);
        $ad    = mb_substr($parca[0] ?? $unvan, 0, 29);
        $soyad = mb_substr(trim((string)($parca[1] ?? '')), 0, 29);

        if ($ad === '')    $ad    = 'Musteri';
        if ($soyad === '') $soyad = '-';

        $fatura = [
            'invoice_id'          => $siparisNo,
            'invoice_description' => mb_substr($aciklama !== '' ? $aciklama : ('Cari tahsilatı: ' . $unvan), 0, 200),
            'total'               => number_format($tutar, 2, '.', ''),
            'items'               => [[
                'name'        => 'Tahsilat',
                'price'       => number_format($tutar, 2, '.', ''),
                'quantity'    => 1,
                'description' => mb_substr($aciklama !== '' ? $aciklama : $unvan, 0, 100),
            ]],
            'return_url'          => $sonuc,
            'cancel_url'          => $sonuc,
        ];

        if ($email !== '' || !empty($cari['cari_email'])) {
            $fatura['bill_email'] = $email ?: $cari['cari_email'];
        }
        if ($telefon !== '' || !empty($cari['cari_telefon'])) {
            $fatura['bill_phone'] = $telefon ?: preg_replace('/\D/', '', (string)$cari['cari_telefon']);
        }
        if (!empty($params['saleWebHookKey'])) {
            $fatura['sale_web_hook_key'] = $params['saleWebHookKey'];
        }

        $govde = [
            'merchant_key'  => $this->merchantKey(),
            'invoice'       => json_encode($fatura, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'currency_code' => 'TRY',
            'name'          => $ad,
            'surname'       => $soyad,
        ];

        // Taksit gönderilirse ödeme sayfasında taksit seçimi gösterilmez.
        if ($taksit > 1) {
            $govde['installments_number'] = $taksit;
        }

        try {
            $yanit = $this->istek(self::YOL_LINK, $govde, 'form');
        } catch (Throwable $e) {
            $this->durumYaz($linkId, self::DURUM_BASARISIZ, ['sonuc_mesaj' => $e->getMessage()], $kullanici);
            throw $e;
        }

        $link = $yanit['link'] ?? ($yanit['data']['link'] ?? '');

        if ($link === '') {
            $mesaj = $yanit['success_message']
                  ?? $yanit['status_description']
                  ?? $yanit['message']
                  ?? 'Ödeme linki üretilemedi.';

            $this->durumYaz($linkId, self::DURUM_BASARISIZ, [
                'sonuc_kod'   => $yanit['status_code'] ?? null,
                'sonuc_mesaj' => $mesaj,
            ], $kullanici);

            throw new Exception('HalkÖde: ' . $mesaj);
        }

        $this->db->update('Halkode_Odeme_Linkleri', [
            'Halkode_Odeme_Linkleri_Link' => $link,
            'GuncelleyenKullanici'        => $kullanici,
            'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
        ], [
            'Halkode_Odeme_Linkleri_id' => $linkId,
        ]);

        return [
            'link_id'    => (int)$linkId,
            'link'       => $link,
            'siparis_no' => $siparisNo,
        ];
    }

    /** Link kaydına durum ve sonuç alanlarını yazar */
    private function durumYaz(int $linkId, string $durumKod, array $alan, int $kullaniciId = 0): void
    {
        $veri = [
            'Halkode_Odeme_Linkleri_DurumKod' => $durumKod,
            'GuncelleyenKullanici'            => $kullaniciId,
            'GuncellemeTarihi'                => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('sonuc_kod', $alan)) {
            $veri['Halkode_Odeme_Linkleri_SonucKod'] = $alan['sonuc_kod'];
        }
        if (array_key_exists('sonuc_mesaj', $alan)) {
            $veri['Halkode_Odeme_Linkleri_SonucMesaj'] = mb_substr((string)$alan['sonuc_mesaj'], 0, 500);
        }

        $this->db->update('Halkode_Odeme_Linkleri', $veri, ['Halkode_Odeme_Linkleri_id' => $linkId]);
    }

    // ─────────────────────────────────────────────
    // GÖNDERİM
    // ─────────────────────────────────────────────

    /**
     * Üretilen linki müşteriye iletir. HalkÖde API ile üretilen linkte
     * gönderim üye işyerine aittir; SMS ve e-posta kendi kanallarımızdan çıkar.
     *
     * @param  array $kanallar ['sms', 'mail'] alt kümesi
     * @param  array $hedef    ['telefon' => ..., 'email' => ...] — verilirse kayıttaki
     *                         değerlerin yerine kullanılır ve kayda işlenir
     *                         (cari kartı yerine yetkili kişiye gönderim)
     * @return array           ['sms' => bool|null, 'mail' => bool|null, 'hatalar' => string[]]
     */
    public function linkGonder(int $linkId, array $kanallar, int $kullaniciId = 0, array $hedef = []): array
    {
        require_once __DIR__ . '/EntegrasyonHelper.php';

        $link = $this->linkGetir($linkId);

        if (!$link) {
            throw new Exception('Ödeme linki kaydı bulunamadı.');
        }
        if (empty($link['Halkode_Odeme_Linkleri_Link'])) {
            throw new Exception('Bu kayıt için henüz ödeme linki üretilmemiş.');
        }

        // Gönderim hedefi değiştirildiyse kayda yazılır; sonraki gönderimler ve
        // liste ekranı kime iletildiğini gösterir.
        $yeniTelefon = preg_replace('/\D/', '', (string)($hedef['telefon'] ?? ''));
        $yeniEmail   = trim((string)($hedef['email'] ?? ''));

        if ($yeniTelefon !== '' || $yeniEmail !== '') {
            $guncel = ['GuncelleyenKullanici' => $kullaniciId, 'GuncellemeTarihi' => date('Y-m-d H:i:s')];

            if ($yeniTelefon !== '') {
                $guncel['Halkode_Odeme_Linkleri_Telefon'] = $yeniTelefon;
                $link['Halkode_Odeme_Linkleri_Telefon']   = $yeniTelefon;
            }
            if ($yeniEmail !== '') {
                $guncel['Halkode_Odeme_Linkleri_Email'] = $yeniEmail;
                $link['Halkode_Odeme_Linkleri_Email']   = $yeniEmail;
            }

            $this->db->update('Halkode_Odeme_Linkleri', $guncel, ['Halkode_Odeme_Linkleri_id' => $linkId]);
        }

        $helper = new EntegrasyonHelper($this->db);
        $tutar  = number_format((float)$link['Halkode_Odeme_Linkleri_Tutar'], 2, ',', '.');
        $unvan  = $link['cari_unvan'] ?? '';
        $adres  = $link['Halkode_Odeme_Linkleri_Link'];
        $sonuc  = ['sms' => null, 'mail' => null, 'hatalar' => []];

        if (in_array('sms', $kanallar, true)) {
            $telefon = $link['Halkode_Odeme_Linkleri_Telefon']
                ?: preg_replace('/\D/', '', (string)($link['cari_telefon'] ?? ''));

            if ($telefon === '') {
                $sonuc['hatalar'][] = 'Cari kartında telefon yok, SMS gönderilemedi.';
            } else {
                $mesaj = $tutar . ' TL tutarindaki odemeniz icin guvenli odeme linki: ' . $adres;
                $yanit = $helper->smsGonder($telefon, $mesaj);
                $sonuc['sms'] = !empty($yanit['success']);

                if (empty($yanit['success'])) {
                    $sonuc['hatalar'][] = 'SMS: ' . ($yanit['message'] ?? 'gönderilemedi');
                }
            }
        }

        if (in_array('mail', $kanallar, true)) {
            $email = $link['Halkode_Odeme_Linkleri_Email'] ?: trim((string)($link['cari_email'] ?? ''));

            if ($email === '') {
                $sonuc['hatalar'][] = 'Cari kartında e-posta yok, mail gönderilemedi.';
            } else {
                $icerik = '<p>Sayın ' . htmlspecialchars($unvan) . ',</p>'
                        . '<p><b>' . $tutar . ' TL</b> tutarındaki ödemenizi aşağıdaki güvenli bağlantı üzerinden '
                        . 'kredi kartınızla gerçekleştirebilirsiniz.</p>'
                        . '<p><a href="' . htmlspecialchars($adres) . '">Ödeme yapmak için tıklayın</a></p>'
                        . '<p style="color:#888;font-size:12px">Bağlantı belirli bir süre sonra geçersiz olur.</p>';

                $yanit = $helper->mailGonder($email, 'Ödeme Bağlantınız', $icerik);
                $sonuc['mail'] = !empty($yanit['success']);

                if (empty($yanit['success'])) {
                    $sonuc['hatalar'][] = 'E-posta: ' . ($yanit['message'] ?? 'gönderilemedi');
                }
            }
        }

        if ($sonuc['sms'] || $sonuc['mail']) {
            $kanalAd = [];
            if ($sonuc['sms'])  $kanalAd[] = 'SMS';
            if ($sonuc['mail']) $kanalAd[] = 'E-posta';

            $this->db->update('Halkode_Odeme_Linkleri', [
                'Halkode_Odeme_Linkleri_DurumKod'       => self::DURUM_GONDERILDI,
                'Halkode_Odeme_Linkleri_GonderimKanali' => implode(' + ', $kanalAd),
                'Halkode_Odeme_Linkleri_GonderimTarihi' => date('Y-m-d H:i:s'),
                'GuncelleyenKullanici'                  => $kullaniciId,
                'GuncellemeTarihi'                      => date('Y-m-d H:i:s'),
            ], [
                'Halkode_Odeme_Linkleri_id' => $linkId,
            ]);
        }

        return $sonuc;
    }

    /**
     * Gönderim için seçilebilecek kişileri döner: cari kartının kendisi ve
     * cariye bağlı yetkililer. Telefonu ve e-postası olmayan yetkili de
     * listelenir; hangi bilginin eksik olduğu arayüzde görülür.
     */
    public function iletisimKisileri(int $cariId): array
    {
        $liste = [];

        $cari = $this->db->fetchOne("
            SELECT ISNULL(cari_unvan, cari_adi) AS unvan, cari_telefon, cari_email
            FROM Cari WHERE cari_id = ?
        ", [$cariId]);

        if ($cari) {
            $liste[] = [
                'id'      => 'cari',
                'ad'      => trim((string)$cari['unvan']),
                'gorev'   => 'Cari kartı',
                'telefon' => preg_replace('/\D/', '', (string)($cari['cari_telefon'] ?? '')),
                'email'   => trim((string)($cari['cari_email'] ?? '')),
            ];
        }

        $yetkililer = $this->db->fetchAll("
            SELECT y.CariYetkili_Id      AS id,
                   y.CariYetkili_Adi     AS adi,
                   y.CariYetkili_Soyadi  AS soyadi,
                   y.CariYetkili_Telefon AS telefon,
                   y.CariYetkili_Telefon2 AS telefon2,
                   y.CariYetkili_Email   AS email,
                   b.CariBirim_Adi       AS birim
            FROM dbo.CariYetkili y
            LEFT JOIN dbo.Cari_Birimler b ON b.CariBirim_Id = y.CariYetkili_BirimId
            WHERE y.CariYetkili_CariId = ?
              AND ISNULL(y.Durum, 1) = 1
            ORDER BY y.CariYetkili_Adi, y.CariYetkili_Soyadi
        ", [$cariId]);

        foreach ($yetkililer as $y) {
            $telefon = preg_replace('/\D/', '', (string)($y['telefon'] ?? ''));

            if ($telefon === '') {
                $telefon = preg_replace('/\D/', '', (string)($y['telefon2'] ?? ''));
            }

            $liste[] = [
                'id'      => (int)$y['id'],
                'ad'      => trim(($y['adi'] ?? '') . ' ' . ($y['soyadi'] ?? '')),
                'gorev'   => trim((string)($y['birim'] ?? '')) ?: 'Yetkili',
                'telefon' => $telefon,
                'email'   => trim((string)($y['email'] ?? '')),
            ];
        }

        return $liste;
    }

    /** Link kaydını cari bilgileriyle getirir */
    public function linkGetir(int $linkId): ?array
    {
        $satir = $this->db->fetchOne("
            SELECT h.*, ISNULL(c.cari_unvan, c.cari_adi) AS cari_unvan, c.cari_telefon, c.cari_email
            FROM dbo.Halkode_Odeme_Linkleri h
            LEFT JOIN dbo.Cari c ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
            WHERE h.Halkode_Odeme_Linkleri_id = ?
        ", [$linkId]);

        return $satir ?: null;
    }

    /** Sipariş numarasından (invoice_id) link kaydını getirir */
    public function linkGetirSiparisNo(string $siparisNo): ?array
    {
        $satir = $this->db->fetchOne("
            SELECT h.*, ISNULL(c.cari_unvan, c.cari_adi) AS cari_unvan, c.cari_telefon, c.cari_email
            FROM dbo.Halkode_Odeme_Linkleri h
            LEFT JOIN dbo.Cari c ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
            WHERE h.Halkode_Odeme_Linkleri_SiparisNo = ?
        ", [$siparisNo]);

        return $satir ?: null;
    }

    // ─────────────────────────────────────────────
    // SONUÇ İŞLEME
    // ─────────────────────────────────────────────

    /**
     * checkstatus servisiyle güncel durumu sorgular ve sonucu işler.
     * Webhook gelmediği durumlarda mutabakat yolu budur.
     *
     * @return array ['islendi' => bool, 'durum' => string, 'kalem_id' => int|null]
     */
    public function durumSorgulaVeIsle(int $linkId, int $kullaniciId = 0): array
    {
        $link = $this->linkGetir($linkId);

        if (!$link) {
            throw new Exception('Ödeme linki kaydı bulunamadı.');
        }

        $invoiceId = (string)$link['Halkode_Odeme_Linkleri_SiparisNo'];

        $yanit = $this->istek(self::YOL_DURUM, [
            'invoice_id'   => $invoiceId,
            'merchant_key' => $this->merchantKey(),
            'hash_key'     => $this->hashKeyUret($invoiceId),
        ], 'json', $this->token());

        $tamamlandi = (string)($yanit['status_code'] ?? '') === self::BASARI_KODU
                   && strtolower((string)($yanit['transaction_status'] ?? '')) === 'completed';

        return $this->sonucIsle($link, [
            'basarili'    => $tamamlandi,
            'order_id'    => $yanit['order_id'] ?? null,
            'islem_no'    => $yanit['transaction_id'] ?? null,
            'durum_kodu'  => $yanit['status_code'] ?? null,
            'mesaj'       => $yanit['status_description'] ?? ($yanit['reason'] ?? null),
            'tutar'       => $yanit['product_price'] ?? null,
            'ref_no'      => $yanit['ref_number'] ?? null,
        ], $kullaniciId);
    }

    /**
     * Ödeme sonucunu link kaydına yazar; başarılı tahsilatta ödeme kalemi açar.
     *
     * Kalem açma tek sefer çalışır: link kaydının OdemeKalem_id alanı NULL iken
     * OUTPUT'lu UPDATE ile rezerve edilir. Webhook, return sayfası ve cron aynı
     * işlemi aynı anda bildirse bile yalnızca biri kalemi oluşturur.
     *
     * @param  array $sonuc basarili, order_id, islem_no, durum_kodu, mesaj, tutar, ref_no
     * @return array ['islendi' => bool, 'durum' => string, 'kalem_id' => int|null]
     */
    public function sonucIsle(array $link, array $sonuc, int $kullaniciId = 0): array
    {
        $linkId   = (int)$link['Halkode_Odeme_Linkleri_id'];
        $basarili = !empty($sonuc['basarili']);
        $simdi    = date('Y-m-d H:i:s');

        // Sonuç alanları her bildirimde tazelenir (kalem açılmış olsa da)
        $this->db->update('Halkode_Odeme_Linkleri', [
            'Halkode_Odeme_Linkleri_ProcessId'      => $sonuc['order_id'] ?? $link['Halkode_Odeme_Linkleri_ProcessId'],
            'Halkode_Odeme_Linkleri_ProcReturnCode' => mb_substr((string)($sonuc['durum_kodu'] ?? ''), 0, 2) ?: null,
            'Halkode_Odeme_Linkleri_SonucKod'       => mb_substr((string)($sonuc['durum_kodu'] ?? ''), 0, 4) ?: null,
            'Halkode_Odeme_Linkleri_SonucMesaj'     => mb_substr((string)($sonuc['mesaj'] ?? ''), 0, 500),
            'Halkode_Odeme_Linkleri_AuthCode'       => mb_substr((string)($sonuc['ref_no'] ?? ''), 0, 6) ?: null,
            'Halkode_Odeme_Linkleri_Rrn'            => mb_substr((string)($sonuc['islem_no'] ?? ''), 0, 12) ?: null,
            'Halkode_Odeme_Linkleri_OdemeTarihi'    => $basarili ? $simdi : null,
            'Halkode_Odeme_Linkleri_DurumKod'       => $basarili ? self::DURUM_ODENDI : self::DURUM_BASARISIZ,
            'GuncelleyenKullanici'                  => $kullaniciId,
            'GuncellemeTarihi'                      => $simdi,
        ], [
            'Halkode_Odeme_Linkleri_id' => $linkId,
        ]);

        if (!$basarili) {
            return ['islendi' => false, 'durum' => self::DURUM_BASARISIZ, 'kalem_id' => null];
        }

        if (!empty($link['Halkode_Odeme_Linkleri_OdemeKalem_id'])) {
            // Zaten işlenmiş (ikinci bildirim)
            return [
                'islendi'  => false,
                'durum'    => self::DURUM_ODENDI,
                'kalem_id' => (int)$link['Halkode_Odeme_Linkleri_OdemeKalem_id'],
            ];
        }

        return $this->kalemOlustur($link, $sonuc, $kullaniciId);
    }

    /**
     * Ödeme başlığı ve kalemini açar, link kaydına bağlar.
     * Üçü tek transaction içinde yazılır; bağlama koşullu olduğu için yarış
     * durumunda ikinci çağrı kendi kaydını geri alır.
     */
    private function kalemOlustur(array $link, array $sonuc, int $kullaniciId): array
    {
        $linkId = (int)$link['Halkode_Odeme_Linkleri_id'];
        $cariId = (int)$link['Halkode_Odeme_Linkleri_Cari_id'];

        // Kalem tutarı link tutarıdır; servisin döndüğü işlem tutarı komisyon
        // içerebildiği için cari alacağını bozmaması adına kullanılmaz.
        $tutar  = round((float)$link['Halkode_Odeme_Linkleri_Tutar'], 2);
        $simdi  = date('Y-m-d H:i:s');
        $bugun  = date('Y-m-d');

        $aciklamaParca = ['HalkÖde link ile tahsilat'];

        if (!empty($sonuc['islem_no'])) {
            $aciklamaParca[] = 'İşlem: ' . $sonuc['islem_no'];
        }
        if (!empty($link['Halkode_Odeme_Linkleri_Aciklama'])) {
            $aciklamaParca[] = $link['Halkode_Odeme_Linkleri_Aciklama'];
        }

        $belgeNo = (string)($sonuc['order_id'] ?? ($sonuc['ref_no'] ?? ''));

        $conn = $this->db->getConnection();
        sqlsrv_begin_transaction($conn);

        try {
            $odemeId = $this->db->insert('Odemeler', [
                'Odemeler_cari_id'     => $cariId,
                'Odemeler_tarih'       => $bugun,
                'Odemeler_toplam'      => $tutar,
                'Odemeler_aciklama'    => 'HalkÖde ödeme linki (' . $link['Halkode_Odeme_Linkleri_SiparisNo'] . ')',
                'Odemeler_durum'       => 1,
                'OlusturanKullanici'   => $kullaniciId,
                'OlusturmaTarihi'      => $simdi,
                'GuncelleyenKullanici' => $kullaniciId,
                'GuncellemeTarihi'     => $simdi,
                'Durum'                => 1,
            ]);

            $kalemId = $this->db->insert('Odeme_Kalemleri', [
                'Odeme_Kalemleri_odeme_id'    => $odemeId,
                'Odeme_Kalemleri_tip'         => self::KALEM_TIP,
                'Odeme_Kalemleri_belge_no'    => mb_substr($belgeNo, 0, 50),
                'Odeme_Kalemleri_vade'        => $bugun,
                'Odeme_Kalemleri_tutar'       => $tutar,
                'Odeme_Kalemleri_personel_id' => null,
                'Odeme_Kalemleri_banka'       => $this->kalemBanka(),
                'Odeme_Kalemleri_dokuman'     => '',
                'Odeme_Kalemleri_aciklama'    => mb_substr(implode(' · ', $aciklamaParca), 0, 500),
                'Odeme_Kalemleri_odendi'      => 1,
                'Odeme_Kalemleri_odeme_tar'   => $bugun,
                'OlusturanKullanici'          => $kullaniciId,
                'OlusturmaTarihi'             => $simdi,
                'GuncelleyenKullanici'        => $kullaniciId,
                'GuncellemeTarihi'            => $simdi,
                'Durum'                       => 1,
            ]);

            // Bağlama yalnızca alan boşken yapılır: eşzamanlı ikinci bildirim
            // buradan satır alamaz ve kendi kaydını geri alır.
            $bagli = $this->db->fetchOne("
                UPDATE dbo.Halkode_Odeme_Linkleri
                SET Halkode_Odeme_Linkleri_OdemeKalem_id = ?,
                    GuncelleyenKullanici                 = ?,
                    GuncellemeTarihi                     = ?
                OUTPUT INSERTED.Halkode_Odeme_Linkleri_id AS id
                WHERE Halkode_Odeme_Linkleri_id = ?
                  AND Halkode_Odeme_Linkleri_OdemeKalem_id IS NULL
            ", [$kalemId, $kullaniciId, $simdi, $linkId]);

            if (!$bagli) {
                sqlsrv_rollback($conn);

                $guncel = $this->linkGetir($linkId);

                return [
                    'islendi'  => false,
                    'durum'    => self::DURUM_ODENDI,
                    'kalem_id' => (int)($guncel['Halkode_Odeme_Linkleri_OdemeKalem_id'] ?? 0) ?: null,
                ];
            }

            sqlsrv_commit($conn);
        } catch (Throwable $e) {
            sqlsrv_rollback($conn);
            throw $e;
        }

        return ['islendi' => true, 'durum' => self::DURUM_ODENDI, 'kalem_id' => (int)$kalemId];
    }

    // ─────────────────────────────────────────────
    // WEBHOOK / RETURN
    // ─────────────────────────────────────────────

    /**
     * Sale WebHook veya return isteğini çözer ve doğrular.
     *
     * Her iki istek de açık uçtan geldiği için hash_key ile doğrulanır:
     * çözülmüş gövdedeki invoice_id, istekteki invoice_id ile eşleşmelidir.
     *
     * @param  array $veri $_POST + $_GET birleşimi
     * @return array       ['invoice_id' => ..., 'basarili' => bool, 'order_id' => ..., ...]
     */
    public function bildirimCoz(array $veri): array
    {
        $invoiceId = trim((string)($veri['invoice_id'] ?? ''));
        $hashKey   = trim((string)($veri['hash_key'] ?? ''));

        if ($invoiceId === '') {
            throw new Exception('Bildirimde invoice_id yok.');
        }
        if ($hashKey === '') {
            throw new Exception('Bildirimde hash_key yok.');
        }

        $cozulmus = $this->hashKeyCoz($hashKey);

        if (!$cozulmus) {
            throw new Exception('hash_key çözülemedi; istek doğrulanamadı.');
        }

        if ((string)$cozulmus['invoice_id'] !== $invoiceId) {
            throw new Exception('hash_key içindeki sipariş numarası istekle uyuşmuyor.');
        }

        // Durum iki kaynaktan da okunur: hash içindeki değer imzalıdır,
        // istekteki payment_status ise okunabilir biçimdedir.
        $hashDurum = strtolower((string)$cozulmus['status']);
        $basarili  = ($hashDurum === 'completed' || $hashDurum === '1')
                  && (string)($veri['payment_status'] ?? '1') === '1';

        return [
            'invoice_id' => $invoiceId,
            'basarili'   => $basarili,
            'order_id'   => $veri['order_id'] ?? ($veri['order_no'] ?? $cozulmus['order_id']),
            'islem_no'   => $veri['transaction_id'] ?? null,
            'durum_kodu' => $veri['status_code'] ?? ($veri['error_code'] ?? null),
            'mesaj'      => $veri['status_description'] ?? ($veri['error'] ?? null),
            'tutar'      => $cozulmus['total'] ?? null,
            'ref_no'     => $veri['ref_number'] ?? null,
        ];
    }
}
