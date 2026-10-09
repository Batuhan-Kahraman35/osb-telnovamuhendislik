<?php
/**
 * Banka Hareketleri Helper
 *
 * Örnek Portal banka hareketleri API'sinden (salt okunur) hesap ve hareket
 * verisini çeker, yerel BankaHesaplari / BankaHareketleri tablolarına yazar.
 *
 * Token ve ayarlar EntegrasyonKanallari tablosunda (Entegrasyon_Kod = BANKA_HAREKET)
 * tutulur; kod dosyasında hiçbir kimlik bilgisi bulunmaz.
 *
 * Kullanım:
 *   $helper = new BankaHareketHelper($db);
 *   $helper->hesaplariSenkronla();
 *   $sonuc = $helper->hareketleriSenkronla();
 *
 * API dokümanı: https://portal.ornekyazilim.com/api/v1/docs.php
 */

class BankaHareketHelper
{
    /** Entegrasyon tablosundaki kod */
    const ENTEGRASYON_KOD = 'BANKA_HAREKET';

    /** Eşleşme durumları (BankaHareketleri_EslesmeDurum) */
    const ESLESME_BEKLIYOR   = 0;
    const ESLESME_OTOMATIK   = 1;
    const ESLESME_MANUEL     = 2;
    const ESLESME_YOKSAYILDI = 3;

    /**
     * Saatlik kotanın tamamı tüketilmez; panelden yapılan manuel isteklere
     * ve dekont indirmelerine pay bırakmak için bu eşiğin altına inilmez.
     */
    const KOTA_GUVENLIK_PAYI = 50;

    private $db;
    private $kanal     = null;
    private $params    = [];
    private $kalanKota = null;   // son yanıttaki X-RateLimit-Remaining

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────
    // KANAL / AYAR
    // ─────────────────────────────────────────────

    private function kanal(): array
    {
        if ($this->kanal !== null) {
            return $this->kanal;
        }

        $kanal = $this->db->fetchOne("
            SELECT ek.*
            FROM EntegrasyonKanallari ek
            INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
            WHERE e.Entegrasyon_Kod = ?
              AND ek.Kanal_Varsayilan = 1
              AND ek.Kanal_Durum = 1
            ORDER BY ek.Kanal_Id
        ", [self::ENTEGRASYON_KOD]);

        if (!$kanal) {
            throw new Exception('Banka hareketleri entegrasyon kanalı bulunamadı veya pasif.');
        }

        if (empty($kanal['Kanal_ApiKey'])) {
            throw new Exception('Banka hareketleri kanalında API token tanımlı değil.');
        }

        $this->params = !empty($kanal['Kanal_Parametreler'])
            ? (json_decode($kanal['Kanal_Parametreler'], true) ?? [])
            : [];

        $this->kanal = $kanal;
        return $kanal;
    }

    public function ayar(string $anahtar, $varsayilan = null)
    {
        $this->kanal();
        return $this->params[$anahtar] ?? $varsayilan;
    }

    /** Son API yanıtındaki kalan saatlik istek hakkı (bilinmiyorsa null) */
    public function kalanKota(): ?int
    {
        return $this->kalanKota;
    }

    // ─────────────────────────────────────────────
    // HTTP
    // ─────────────────────────────────────────────

    /**
     * API'ye GET isteği atar ve JSON gövdeyi dizi olarak döner.
     *
     * Rota, temiz URL yerine PATH_INFO biçiminde eklenir; portal tarafında
     * /api/v1/<rota> için rewrite kuralı tanımlı olmadığından temiz URL 404 döner.
     */
    private function istek(string $rota, array $sorgu = []): array
    {
        $kanal = $this->kanal();
        $url   = rtrim($kanal['Kanal_Endpoint'], '/') . '/' . ltrim($rota, '/');

        if ($sorgu) {
            $url .= '?' . http_build_query($sorgu);
        }

        list($kod, $govde, $basliklar) = $this->curl($url, $kanal['Kanal_ApiKey']);

        $veri = json_decode($govde, true);

        if ($kod !== 200 || !is_array($veri) || empty($veri['success'])) {
            $mesaj = $veri['error']['message'] ?? ('HTTP ' . $kod);
            $detay = $veri['error']['detail']  ?? mb_substr(strip_tags((string)$govde), 0, 200);

            $this->log('GET ' . $rota, $sorgu, $veri ?: $govde, 'ERROR', (string)$kod, $mesaj . ' - ' . $detay);

            throw new Exception('Banka API hatası: ' . $mesaj . ($detay ? ' (' . $detay . ')' : ''));
        }

        return $veri;
    }

    /**
     * Ham GET isteği.
     *
     * @return array [http kodu, gövde, başlıklar]
     */
    private function curl(string $url, string $token, bool $ikili = false): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                // Bazı ara sunucular Authorization başlığını düşürüyor; API aynı
                // token'ı X-Api-Key ile de kabul ediyor, ikisi birlikte gönderilir.
                'X-Api-Key: ' . $token,
                'Accept: ' . ($ikili ? '*/*' : 'application/json'),
            ],
        ]);

        $yanit   = curl_exec($ch);
        $hata    = curl_error($ch);
        $kod     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $basUzun = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($yanit === false) {
            throw new Exception('Banka API bağlantı hatası: ' . $hata);
        }

        $hamBaslik = substr($yanit, 0, $basUzun);
        $govde     = substr($yanit, $basUzun);

        $basliklar = [];

        foreach (explode("\n", $hamBaslik) as $satir) {
            if (strpos($satir, ':') !== false) {
                list($ad, $deger) = explode(':', $satir, 2);
                $basliklar[strtolower(trim($ad))] = trim($deger);
            }
        }

        if (isset($basliklar['x-ratelimit-remaining'])) {
            $this->kalanKota = (int)$basliklar['x-ratelimit-remaining'];
        }

        return [$kod, $govde, $basliklar];
    }

    /** Kota güvenlik payının altına inildiyse true */
    private function kotaTukendi(): bool
    {
        return $this->kalanKota !== null && $this->kalanKota <= self::KOTA_GUVENLIK_PAYI;
    }

    // ─────────────────────────────────────────────
    // LOG
    // ─────────────────────────────────────────────

    private function log(string $islem, $istek, $yanit, string $durum,
                         ?string $hataKodu = null, ?string $hataMesaj = null,
                         ?float $baslangic = null): void
    {
        try {
            $yanitMetin = null;

            if ($yanit !== null) {
                $yanitMetin = is_string($yanit) ? $yanit : json_encode($yanit, JSON_UNESCAPED_UNICODE);
                $yanitMetin = mb_substr($yanitMetin, 0, 4000);
            }

            $this->db->insert('EntegrasyonLoglari', [
                'Log_Kanal_Id'     => $this->kanal['Kanal_Id'] ?? null,
                'Log_Islem'        => $islem,
                'Log_Istek'        => $istek !== null ? json_encode($istek, JSON_UNESCAPED_UNICODE) : null,
                'Log_Yanit'        => $yanitMetin,
                'Log_Durum'        => $durum,
                'Log_Hata_Kodu'    => $hataKodu,
                'Log_Hata_Mesaj'   => $hataMesaj !== null ? mb_substr($hataMesaj, 0, 500) : null,
                'Log_Sure_Ms'      => $baslangic ? (int)round((microtime(true) - $baslangic) * 1000) : null,
                'Log_IP'           => $_SERVER['REMOTE_ADDR'] ?? null,
                'Log_Kullanici_Id' => $_SESSION['kullanici_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('BankaHareketHelper log hatası: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    // API UÇLARI
    // ─────────────────────────────────────────────

    public function firmalar(): array
    {
        return $this->istek('firmalar')['data'] ?? [];
    }

    public function bankalar(): array
    {
        return $this->istek('bankalar')['data'] ?? [];
    }

    public function hesaplar(array $filtre = []): array
    {
        return $this->istek('hesaplar', $filtre)['data'] ?? [];
    }

    public function hareketler(array $filtre = []): array
    {
        return $this->istek('hareketler', $filtre);
    }

    // ─────────────────────────────────────────────
    // SENKRONİZASYON
    // ─────────────────────────────────────────────

    /**
     * Hesapları ve bakiyeleri yerel aynaya yazar.
     */
    public function hesaplariSenkronla(): array
    {
        $baslangic = microtime(true);
        $filtre    = [];
        $firmaId   = (int)$this->ayar('firma_id', 0);

        if ($firmaId > 0) {
            $filtre['firma_id'] = $firmaId;
        }

        $hesaplar = $this->hesaplar($filtre);
        $logolar  = [];

        foreach ($this->bankalar() as $banka) {
            $logolar[(int)$banka['id']] = $banka['logo_url'] ?? null;
        }

        $eklenen     = 0;
        $guncellenen = 0;
        $simdi       = date('Y-m-d H:i:s');

        foreach ($hesaplar as $h) {
            $portalId = (int)($h['id'] ?? 0);

            if ($portalId <= 0) {
                continue;
            }

            $bankaId = isset($h['banka']['id']) ? (int)$h['banka']['id'] : null;

            $satir = [
                'BankaHesaplari_HesapNo'              => $h['hesap_no'] ?? null,
                'BankaHesaplari_Iban'                 => $h['iban'] ?? null,
                'BankaHesaplari_Aciklama'             => $h['aciklama'] ?? null,
                'BankaHesaplari_SubeAdi'              => $h['sube_adi'] ?? null,
                'BankaHesaplari_Bakiye'               => $h['bakiye'] ?? null,
                'BankaHesaplari_KullanilabilirBakiye' => $h['kullanilabilir_bakiye'] ?? null,
                'BankaHesaplari_Bloke'                => $h['bloke'] ?? null,
                'BankaHesaplari_SonSenkron'           => $this->tarih($h['son_senkron'] ?? null),
                'BankaHesaplari_Firma_id'             => isset($h['firma']['id']) ? (int)$h['firma']['id'] : null,
                'BankaHesaplari_FirmaAd'              => $h['firma']['ad'] ?? null,
                'BankaHesaplari_Banka_id'             => $bankaId,
                'BankaHesaplari_BankaAd'              => $h['banka']['ad'] ?? null,
                'BankaHesaplari_LogoUrl'              => $bankaId ? ($logolar[$bankaId] ?? null) : null,
                'GuncelleyenKullanici'                => 1,
                'GuncellemeTarihi'                    => $simdi,
                'Durum'                               => 1,
            ];

            $mevcut = $this->db->fetchOne(
                "SELECT BankaHesaplari_id FROM BankaHesaplari WHERE BankaHesaplari_PortalId = ?",
                [$portalId]
            );

            if ($mevcut) {
                $this->db->update('BankaHesaplari', $satir, ['BankaHesaplari_id' => (int)$mevcut['BankaHesaplari_id']]);
                $guncellenen++;
            } else {
                $satir['BankaHesaplari_PortalId'] = $portalId;
                $satir['OlusturanKullanici']      = 1;
                $satir['OlusturmaTarihi']         = $simdi;

                $this->db->insert('BankaHesaplari', $satir);
                $eklenen++;
            }
        }

        $this->log('HESAP_SENKRON', $filtre,
            ['eklenen' => $eklenen, 'guncellenen' => $guncellenen], 'SUCCESS', null, null, $baslangic);

        return ['eklenen' => $eklenen, 'guncellenen' => $guncellenen];
    }

    /**
     * Hareketleri artımlı çeker.
     *
     * Cursor olarak yerel tablodaki en büyük PortalId kullanılır; ayrı bir durum
     * kaydı tutulmaz, böylece tablo elle temizlense bile senkron tutarlı kalır.
     * İlk çalışmada ilk_cekim_baslangic tarihinden itibaren çekilir.
     *
     * @param int|null $maxSayfa   Bu turda çekilecek azami sayfa (null: ayardan)
     * @param bool     $bastanTara Cursor'ı sıfırlar; mevcut kayıtlar yeniden okunur.
     *                             Yeni kayıt eklemez, yalnızca eksik alanları (VKN)
     *                             tamamlar. API'ye alan eklendiğinde kullanılır.
     */
    public function hareketleriSenkronla(?int $maxSayfa = null, bool $bastanTara = false): array
    {
        $baslangic = microtime(true);
        $limit     = max(1, min(500, (int)$this->ayar('sayfa_limit', 500)));
        $maxSayfa  = $maxSayfa ?? max(1, (int)$this->ayar('max_sayfa', 20));

        if ($bastanTara) {
            $sonId = 0;
        } else {
            $sonSatir = $this->db->fetchOne(
                "SELECT ISNULL(MAX(BankaHareketleri_PortalId), 0) AS SonId FROM BankaHareketleri"
            );

            $sonId = (int)($sonSatir['SonId'] ?? 0);
        }

        $filtre  = ['limit' => $limit];
        $firmaId = (int)$this->ayar('firma_id', 0);

        if ($firmaId > 0) {
            $filtre['firma_id'] = $firmaId;
        }

        // Yalnızca tahsilat (para girişi) çekilir. Mutabakat tahsilat tarafında
        // yapıldığı için çıkış hareketleri tabloda tutulmuyor; filtre API'ye
        // gönderilir ki silinen kayıtlar sonraki turda geri gelmesin.
        if (!empty($this->ayar('sadece_tahsilat'))) {
            $filtre['tip'] = 'A';
        }

        // İlk çalışmada tüm geçmiş yerine belirlenen tarihten itibaren çekilir
        $ilk = $this->ayar('ilk_cekim_baslangic');

        if ($sonId === 0 && $ilk) {
            $filtre['baslangic_tarih'] = $ilk;
        }

        $eklenen  = 0;
        $atlanan  = 0;
        $sayfa    = 0;
        $devamVar = false;
        $kayit    = [];

        try {
            do {
                if ($this->kotaTukendi()) {
                    break;
                }

                $filtre['sonraki_id'] = $sonId;   // id > sonId, artan sırada

                $yanit = $this->hareketler($filtre);
                $kayit = $yanit['data'] ?? [];
                $meta  = $yanit['meta'] ?? [];
                $sayfa++;

                foreach ($kayit as $h) {
                    if ($this->hareketYaz($h)) {
                        $eklenen++;
                    } else {
                        $atlanan++;
                    }

                    $sonId = max($sonId, (int)($h['id'] ?? 0));
                }

                $devamVar = !empty($meta['devam_var']);
            } while ($devamVar && $sayfa < $maxSayfa && $kayit);
        } catch (Throwable $e) {
            // Kısmi ilerleme korunur: yazılan kayıtlar commit edilmiştir,
            // sonraki tur MAX(PortalId) üzerinden kaldığı yerden devam eder.
            $this->log('HAREKET_SENKRON', $filtre,
                ['eklenen' => $eklenen, 'sayfa' => $sayfa], 'ERROR', null, $e->getMessage(), $baslangic);

            throw $e;
        }

        $this->log('HAREKET_SENKRON', $filtre, [
            'eklenen'    => $eklenen,
            'atlanan'    => $atlanan,
            'sayfa'      => $sayfa,
            'son_id'     => $sonId,
            'devam_var'  => $devamVar,
            'kalan_kota' => $this->kalanKota,
        ], 'SUCCESS', null, null, $baslangic);

        $this->kanalKullanimGuncelle();

        return [
            'eklenen'    => $eklenen,
            'atlanan'    => $atlanan,
            'sayfa'      => $sayfa,
            'son_id'     => $sonId,
            'devam_var'  => $devamVar,
            'kalan_kota' => $this->kalanKota,
        ];
    }

    /**
     * API'den gelen kimlik numarasını sadeleştirir (yalnızca rakam, 10-11 hane).
     */
    private function vkn(array $h): ?string
    {
        $ham = preg_replace('/\D/', '', (string)($h['vkn_tckn'] ?? ''));

        return ($ham !== '' && strlen($ham) <= 11) ? $ham : null;
    }

    /**
     * Tek hareketi yazar. Zaten varsa false döner (mükerrer koruması).
     *
     * Mevcut kayıtta VKN boş, gelen veride dolu ise yalnız o alan güncellenir:
     * API'ye vkn_tckn alanı sonradan eklendiği için eski kayıtlar boş kaldı ve
     * bunları yeniden çekmek yerine yerinde tamamlamak gerekiyor.
     */
    private function hareketYaz(array $h): bool
    {
        $portalId = (int)($h['id'] ?? 0);

        if ($portalId <= 0) {
            return false;
        }

        $var = $this->db->fetchOne("
            SELECT BankaHareketleri_id, BankaHareketleri_VknTckn
            FROM BankaHareketleri
            WHERE BankaHareketleri_PortalId = ?
        ", [$portalId]);

        if ($var) {
            $yeniVkn = $this->vkn($h);

            if ($yeniVkn !== null && empty($var['BankaHareketleri_VknTckn'])) {
                $this->db->update('BankaHareketleri',
                    [
                        'BankaHareketleri_VknTckn' => $yeniVkn,
                        'GuncellemeTarihi'         => date('Y-m-d H:i:s'),
                    ],
                    ['BankaHareketleri_id' => (int)$var['BankaHareketleri_id']]
                );
            }

            return false;
        }

        $simdi = date('Y-m-d H:i:s');

        $this->db->insert('BankaHareketleri', [
            'BankaHareketleri_PortalId'     => $portalId,
            'BankaHareketleri_Hesap_id'     => isset($h['hesap_id']) ? (int)$h['hesap_id'] : null,
            'BankaHareketleri_Iban'         => $h['iban'] ?? null,
            'BankaHareketleri_KarsiTaraf'   => $h['karsi_taraf'] ?? null,
            'BankaHareketleri_IslemTarihi'  => $this->tarih($h['islem_tarihi'] ?? null),
            'BankaHareketleri_KayitTarihi'  => $this->tarih($h['kayit_tarihi'] ?? null),
            'BankaHareketleri_Tutar'        => $h['tutar'] ?? null,
            'BankaHareketleri_Tip'          => $h['tip'] ?? null,
            'BankaHareketleri_ParaBirimi'   => $h['para_birimi'] ?? null,
            'BankaHareketleri_Aciklama'     => $h['aciklama'] ?? null,
            'BankaHareketleri_KalanBakiye'  => $h['kalan_bakiye'] ?? null,
            'BankaHareketleri_ReferansNo'   => $h['referans_no'] ?? null,
            'BankaHareketleri_VknTckn'      => $this->vkn($h),
            'BankaHareketleri_MasrafMi'     => !empty($h['masraf_mi']) ? 1 : 0,
            'BankaHareketleri_DekontVar'    => !empty($h['dekont_var']) ? 1 : 0,
            'BankaHareketleri_HesapNo'      => $h['hesap']['no'] ?? null,
            'BankaHareketleri_Firma_id'     => isset($h['firma']['id']) ? (int)$h['firma']['id'] : null,
            'BankaHareketleri_FirmaAd'      => $h['firma']['ad'] ?? null,
            'BankaHareketleri_Banka_id'     => isset($h['banka']['id']) ? (int)$h['banka']['id'] : null,
            'BankaHareketleri_BankaAd'      => $h['banka']['ad'] ?? null,
            'BankaHareketleri_EslesmeDurum' => self::ESLESME_BEKLIYOR,
            'OlusturanKullanici'            => 1,
            'OlusturmaTarihi'               => $simdi,
            'GuncelleyenKullanici'          => 1,
            'GuncellemeTarihi'              => $simdi,
            'Durum'                         => 1,
        ]);

        return true;
    }

    // ─────────────────────────────────────────────
    // DEKONT
    // ─────────────────────────────────────────────

    /**
     * Dekontu indirir ve yerel dosya yolunu döner.
     *
     * Dosya bir kez indirilip BankaHareketleri_DekontYol alanında saklanır;
     * sonraki görüntülemelerde API'ye tekrar gidilmez (saatlik kota korunur).
     *
     * @return string|null Proje köküne göreli yol, dekont yoksa null
     */
    public function dekontIndir(int $portalId, bool $zorla = false): ?string
    {
        $kayit = $this->db->fetchOne("
            SELECT BankaHareketleri_id, BankaHareketleri_DekontVar, BankaHareketleri_DekontYol
            FROM BankaHareketleri
            WHERE BankaHareketleri_PortalId = ?
        ", [$portalId]);

        if (!$kayit || empty($kayit['BankaHareketleri_DekontVar'])) {
            return null;
        }

        $kok = dirname(__DIR__, 2);

        if (!$zorla && !empty($kayit['BankaHareketleri_DekontYol'])
            && is_file($kok . '/' . $kayit['BankaHareketleri_DekontYol'])) {
            return $kayit['BankaHareketleri_DekontYol'];
        }

        $baslangic = microtime(true);
        $kanal     = $this->kanal();
        $url       = rtrim($kanal['Kanal_Endpoint'], '/') . '/hareketler/' . $portalId . '/dekont';

        list($kod, $govde, $basliklar) = $this->curl($url, $kanal['Kanal_ApiKey'], true);

        if ($kod !== 200 || $govde === '') {
            $veri  = json_decode($govde, true);
            $mesaj = $veri['error']['message'] ?? ('HTTP ' . $kod);

            $this->log('DEKONT_INDIR', ['portal_id' => $portalId], $mesaj, 'ERROR', (string)$kod, $mesaj, $baslangic);

            return null;
        }

        $uzanti = 'pdf';
        $tur    = strtolower($basliklar['content-type'] ?? '');

        if (strpos($tur, 'jpeg') !== false || strpos($tur, 'jpg') !== false) {
            $uzanti = 'jpg';
        } elseif (strpos($tur, 'png') !== false) {
            $uzanti = 'png';
        }

        $klasor = $kok . '/uploads/dekont';

        if (!is_dir($klasor) && !mkdir($klasor, 0775, true) && !is_dir($klasor)) {
            throw new Exception('Dekont klasörü oluşturulamadı: uploads/dekont');
        }

        $goreliYol = 'uploads/dekont/dekont_' . $portalId . '.' . $uzanti;

        if (file_put_contents($kok . '/' . $goreliYol, $govde) === false) {
            throw new Exception('Dekont dosyası yazılamadı: ' . $goreliYol);
        }

        $this->db->update('BankaHareketleri',
            [
                'BankaHareketleri_DekontYol' => $goreliYol,
                'GuncellemeTarihi'           => date('Y-m-d H:i:s'),
            ],
            ['BankaHareketleri_id' => (int)$kayit['BankaHareketleri_id']]
        );

        $this->log('DEKONT_INDIR', ['portal_id' => $portalId], $goreliYol, 'SUCCESS', null, null, $baslangic);

        return $goreliYol;
    }

    // ─────────────────────────────────────────────
    // YARDIMCI
    // ─────────────────────────────────────────────

    /** ISO 8601 (2026-08-17T14:06:17) -> MSSQL datetime metni */
    private function tarih(?string $deger): ?string
    {
        if (!$deger) {
            return null;
        }

        $zaman = strtotime($deger);

        return $zaman ? date('Y-m-d H:i:s', $zaman) : null;
    }

    private function kanalKullanimGuncelle(): void
    {
        try {
            $this->db->execute(
                "UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Id = ?",
                [(int)$this->kanal['Kanal_Id']]
            );
        } catch (Throwable $e) {
            error_log('BankaHareketHelper kanal kullanım güncellenemedi: ' . $e->getMessage());
        }
    }
}
