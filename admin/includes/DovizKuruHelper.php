<?php
/**
 * Doviz Kuru Helper — TCMB gunluk kurlari
 *
 * CRON KULLANMAZ. Kurlar ilk isteyen sayfa/istek tarafindan TCMB'den cekilir ve
 * tanim_DovizKurlari tablosuna yazilir. Sonraki istekler TTL suresi boyunca
 * dogrudan tablodan okur; boylece her sayfa acilisinda TCMB'ye gidilmez.
 *
 * TTL, TCMB_DOVIZ entegrasyon kanalinin Kanal_Parametreler JSON'undaki
 * "cache_dakika" degerinden okunur (yoksa 30 dk).
 *
 * TCMB gunde bir kez (~15:30) yayin yapar; hafta sonu / tatilde today.xml son
 * is gununun kurlarini dondurur. Bu yuzden kayit tarihi TCMB'nin YAYIN tarihidir
 * (Tarih_Date attribute), istegin yapildigi tarih degil.
 *
 * FATURA KURU AYRIDIR: today.xml "en son yayin"dir, saat 15:30 sonrasi bugunun
 * kurunu dondurur. Fatura TL karsiligi ise VUK geregi fatura tarihinden BIR ONCEKI
 * gunun kuru ile hesaplanir. Bu yuzden fatura tarafi today.xml'i degil
 * faturaKuruGetir() metodunu kullanir; o metot tabloda fatura tarihinden kucuk en
 * yakin yayini arar, bulamazsa TCMB arsivinden ceker.
 *
 * Kullanim:
 *   $helper = new DovizKuruHelper($db);
 *   $sonuc  = $helper->kurlariGetir($kullaniciId);          // sidebar widget
 *   $kur    = $helper->faturaKuruGetir('2026-08-13', 'USD'); // fatura hesabi
 */

class DovizKuruHelper
{
    /** Kanal parametresi yoksa kullanilacak onbellek suresi (dakika). */
    private const VARSAYILAN_CACHE_DAKIKA = 30;

    /** Kanal kaydi yoksa kullanilacak TCMB adresi. */
    private const VARSAYILAN_ENDPOINT = 'https://www.tcmb.gov.tr/kurlar/today.xml';

    /** Arsiv taramasinda fatura tarihinden geriye bakilacak azami gun (tatil payi). */
    private const ARSIV_AZAMI_GUN = 15;

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Gosterilecek kurlari dondurur. Onbellek bayatsa TCMB'den yeniler.
     *
     * @param  int|null $kullaniciId Kayit denetim alanlari icin
     * @return array {
     *     success: bool, kurlar: array, tarih: ?string, kaynak: string, message: ?string
     * }
     */
    public function kurlariGetir(?int $kullaniciId = null): array
    {
        $kanal = $this->kanalGetir();
        $hesap = $this->hesapTipiGetir($kanal);

        if ($this->onbellekBayatMi($kanal)) {
            $cekim = $this->tcmbdenCek($kanal, $kullaniciId);
            // Cekim basarisizsa sessizce dusuyoruz: asagida son bilinen kur okunur.
            if (!$cekim['success']) {
                $veri = $this->veritabanindanOku($hesap);
                $veri['message'] = $cekim['message'];
                $veri['kaynak']  = 'cache';
                return $veri;
            }
        }

        return $this->veritabanindanOku($hesap);
    }

    /**
     * Kullanilacak kur hesabi: kanal parametrelerindeki "hesap" alani.
     * ortalama (alis+satis)/2 | satis | alis  — tanimsizsa 'alis'.
     *
     * Varsayilan 'alis'tir: VUK geregi doviz faturasinin TL karsiligi TCMB doviz
     * ALIS kuru ile hesaplanir. Widget ile fatura ayni degeri gostersin diye hesap
     * tipi tek noktada tutulur; farkli istenirse kanal parametresinden degistirilir.
     */
    private function hesapTipiGetir(?array $kanal): string
    {
        $gecerli = ['ortalama', 'satis', 'alis'];

        if ($kanal && !empty($kanal['Kanal_Parametreler'])) {
            $params = json_decode($kanal['Kanal_Parametreler'], true) ?? [];
            $hesap  = strtolower(trim((string)($params['hesap'] ?? '')));
            if (in_array($hesap, $gecerli, true)) {
                return $hesap;
            }
        }

        return 'alis';
    }

    /**
     * FATURA kurunun hesap tipi. Widget'in "hesap" parametresinden BAGIMSIZDIR:
     * VUK geregi doviz faturasinin TL karsiligi TCMB doviz ALIS kuru ile hesaplanir,
     * widget ise istenirse ortalama veya satis gosterebilir. Ikisi tek parametreye
     * baglanmisti; kanal "ortalama" oldugu icin faturalar (alis+satis)/2 ile kesildi
     * (destek talebi #264, TLN2026000000412).
     *
     * Istisna gerekirse kanal parametresindeki "fatura_hesap" alani bu degeri ezer;
     * boyle bir alan yoksa daima 'alis' kullanilir.
     */
    private function faturaHesapTipiGetir(?array $kanal): string
    {
        $gecerli = ['ortalama', 'satis', 'alis'];

        if ($kanal && !empty($kanal['Kanal_Parametreler'])) {
            $params = json_decode($kanal['Kanal_Parametreler'], true) ?? [];
            $hesap  = strtolower(trim((string)($params['fatura_hesap'] ?? '')));
            if (in_array($hesap, $gecerli, true)) {
                return $hesap;
            }
        }

        return 'alis';
    }

    /** Widget altinda gosterilecek etiket. */
    private function hesapEtiketi(string $hesap): string
    {
        switch ($hesap) {
            case 'ortalama': return 'Ortalama kur';
            case 'alis':     return 'Alış kuru';
            default:         return 'Satış kuru';
        }
    }

    /** Secilen hesap tipine gore gosterim degerini uretir. */
    private function hesapDegeri(string $hesap, ?float $alis, ?float $satis): ?float
    {
        if ($hesap === 'ortalama') {
            if ($alis !== null && $satis !== null) {
                return round(($alis + $satis) / 2, 4);
            }
            return $satis ?? $alis;
        }

        if ($hesap === 'alis') {
            return $alis ?? $satis;
        }

        return $satis ?? $alis;
    }

    // ─────────────────────────────────────────────
    // OKUMA
    // ─────────────────────────────────────────────

    /**
     * En guncel yayin tarihine ait kurlari, tanim_ParaBirimi'nde aktif olan
     * para birimleriyle sinirli olarak okur (TRY haric).
     */
    private function veritabanindanOku(string $hesap = 'satis'): array
    {
        $satirlar = $this->db->fetchAll("
            SELECT
                dk.DovizKurlari_ParaBirimi   AS kod,
                dk.DovizKurlari_ParaBirimiAd AS ad,
                dk.DovizKurlari_Alis         AS alis,
                dk.DovizKurlari_Satis        AS satis,
                CONVERT(VARCHAR(10), dk.DovizKurlari_Tarih, 104) AS tarih
            FROM dbo.tanim_DovizKurlari dk
            INNER JOIN dbo.tanim_ParaBirimi pb
                    ON pb.ParaBirimi_Kod = dk.DovizKurlari_ParaBirimi
                   AND pb.Durum = 1
            WHERE dk.Durum = 1
              AND UPPER(dk.DovizKurlari_ParaBirimi) <> 'TRY'
              AND dk.DovizKurlari_Tarih = (
                    SELECT MAX(DovizKurlari_Tarih) FROM dbo.tanim_DovizKurlari WHERE Durum = 1
              )
            ORDER BY pb.ParaBirimi_id
        ");

        if (empty($satirlar)) {
            return [
                'success'       => false,
                'kurlar'        => [],
                'tarih'         => null,
                'kaynak'        => 'db',
                'hesap_etiketi' => $this->hesapEtiketi($hesap),
                'message'       => 'Kayitli doviz kuru bulunamadi.',
            ];
        }

        $kurlar = [];
        foreach ($satirlar as $r) {
            $alis  = $r['alis']  !== null ? (float)$r['alis']  : null;
            $satis = $r['satis'] !== null ? (float)$r['satis'] : null;

            $kurlar[] = [
                'kod'   => $r['kod'],
                'ad'    => $r['ad'],
                'alis'  => $alis,
                'satis' => $satis,
                'deger' => $this->hesapDegeri($hesap, $alis, $satis),
            ];
        }

        return [
            'success'       => true,
            'kurlar'        => $kurlar,
            'tarih'         => $satirlar[0]['tarih'],
            'kaynak'        => 'db',
            'hesap_etiketi' => $this->hesapEtiketi($hesap),
            'message'       => null,
        ];
    }

    // ─────────────────────────────────────────────
    // ONBELLEK
    // ─────────────────────────────────────────────

    private function onbellekBayatMi(?array $kanal): bool
    {
        $dakika = self::VARSAYILAN_CACHE_DAKIKA;

        if ($kanal && !empty($kanal['Kanal_Parametreler'])) {
            $params = json_decode($kanal['Kanal_Parametreler'], true) ?? [];
            if (!empty($params['cache_dakika']) && (int)$params['cache_dakika'] > 0) {
                $dakika = (int)$params['cache_dakika'];
            }
        }

        // Son yazim zamanina bak; tablo bossa NULL doner -> bayat kabul edilir.
        $satir = $this->db->fetchOne("
            SELECT MAX(ISNULL(GuncellemeTarihi, OlusturmaTarihi)) AS son_yazim
            FROM dbo.tanim_DovizKurlari
            WHERE Durum = 1
        ");

        if (empty($satir['son_yazim'])) {
            return true;
        }

        $sonYazim = $satir['son_yazim'] instanceof DateTimeInterface
            ? $satir['son_yazim']->format('Y-m-d H:i:s')
            : (string)$satir['son_yazim'];

        $gecen = (time() - strtotime($sonYazim)) / 60;

        return $gecen >= $dakika;
    }

    // ─────────────────────────────────────────────
    // TCMB
    // ─────────────────────────────────────────────

    private function kanalGetir(): ?array
    {
        try {
            return $this->db->fetchOne("
                SELECT ek.Kanal_Id, ek.Kanal_Endpoint, ek.Kanal_Parametreler,
                       ek.Kanal_Entegrasyon_Id
                FROM EntegrasyonKanallari ek
                INNER JOIN Entegrasyon e ON e.Entegrasyon_Id = ek.Kanal_Entegrasyon_Id
                WHERE e.Entegrasyon_Kod = 'TCMB_DOVIZ'
                  AND ek.Kanal_Durum = 1 AND ek.Durum = 1 AND e.Entegrasyon_Durum = 1
            ");
        } catch (Exception $e) {
            error_log('TCMB kanal sorgusu hatasi: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * TCMB XML'ini indirir, tum para birimlerini tabloya yazar.
     * @return array ['success' => bool, 'message' => ?string]
     */
    private function tcmbdenCek(?array $kanal, ?int $kullaniciId): array
    {
        $url = $kanal['Kanal_Endpoint'] ?? self::VARSAYILAN_ENDPOINT;

        $indirme = $this->xmlIndir($url);
        if (!$indirme['success']) {
            return $indirme;
        }

        $islem = $this->xmlIsle($indirme['xml'], $kullaniciId);
        if (!$islem['success']) {
            return $islem;
        }

        if ($kanal) {
            try {
                $this->db->execute(
                    "UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Id = ?",
                    [$kanal['Kanal_Id']]
                );
            } catch (Exception $e) {
                error_log('TCMB Kanal_Son_Kullanim guncellenemedi: ' . $e->getMessage());
            }
        }

        return ['success' => true, 'message' => null];
    }

    /**
     * Verilen adresten TCMB XML'ini indirir ve ayristirir.
     * @return array ['success' => bool, 'xml' => ?SimpleXMLElement, 'message' => ?string]
     */
    private function xmlIndir(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $xmlStr   = curl_exec($ch);
        $httpKod  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        curl_close($ch);

        if ($curlHata || $httpKod !== 200 || !$xmlStr) {
            return [
                'success' => false,
                'xml'     => null,
                'message' => 'TCMB kurlari alinamadi' . ($curlHata ? ": {$curlHata}" : " (HTTP {$httpKod})") . '.',
            ];
        }

        $xml = @simplexml_load_string($xmlStr);
        if (!$xml || !isset($xml->Currency)) {
            return ['success' => false, 'xml' => null, 'message' => 'TCMB yaniti okunamadi.'];
        }

        return ['success' => true, 'xml' => $xml, 'message' => null];
    }

    /**
     * XML'deki tum para birimlerini yayin tarihiyle tabloya yazar.
     * @return array ['success' => bool, 'tarih' => ?string, 'message' => ?string]
     */
    private function xmlIsle(SimpleXMLElement $xml, ?int $kullaniciId): array
    {
        // Yayin tarihi: <Tarih_Date Tarih="30.07.2026" ...>
        $yayinTarihi = $this->tarihCoz((string)($xml['Tarih'] ?? ''));
        if ($yayinTarihi === null) {
            return ['success' => false, 'tarih' => null, 'message' => 'TCMB yayin tarihi okunamadi.'];
        }

        $yazilan = 0;
        foreach ($xml->Currency as $cur) {
            $kod = strtoupper(trim((string)$cur['CurrencyCode']));
            if ($kod === '') {
                continue;
            }

            $birim = (float)((string)$cur->Unit ?: 1);
            if ($birim <= 0) {
                $birim = 1;
            }

            // TCMB bazi birimleri 100'luk verir (JPY gibi); 1 birime normalize et.
            $alis    = $this->sayiCoz((string)$cur->ForexBuying);
            $satis   = $this->sayiCoz((string)$cur->ForexSelling);
            $efAlis  = $this->sayiCoz((string)$cur->BanknoteBuying);
            $efSatis = $this->sayiCoz((string)$cur->BanknoteSelling);

            $alis    = $alis    !== null ? round($alis    / $birim, 4) : null;
            $satis   = $satis   !== null ? round($satis   / $birim, 4) : null;
            $efAlis  = $efAlis  !== null ? round($efAlis  / $birim, 4) : null;
            $efSatis = $efSatis !== null ? round($efSatis / $birim, 4) : null;

            if ($alis === null && $satis === null) {
                continue;
            }

            if ($this->kurYaz($yayinTarihi, $kod, (string)$cur->Isim, $alis, $satis, $efAlis, $efSatis, $kullaniciId)) {
                $yazilan++;
            }
        }

        if ($yazilan === 0) {
            return ['success' => false, 'tarih' => null, 'message' => 'TCMB yanitinda islenebilir kur bulunamadi.'];
        }

        return ['success' => true, 'tarih' => $yayinTarihi, 'message' => null];
    }

    // ─────────────────────────────────────────────
    // FATURA KURU (tarih bazli)
    // ─────────────────────────────────────────────

    /**
     * Fatura tarihine gore kullanilacak kur.
     *
     * VUK: doviz faturasinin TL karsiligi, fatura tarihinden BIR ONCEKI gunun TCMB
     * kuru ile hesaplanir. Bu yuzden kosul "< fatura tarihi"dir; tek basina hem
     * TCMB'nin 15:30 yayin saatini hem hafta sonu/tatili cozer (Pazartesi faturasi
     * kendiliginden Cuma kurunu alir).
     *
     * Tabloda uygun yayin yoksa TCMB arsivinden geriye dogru taranir.
     *
     * @param  string   $faturaTarihi Y-m-d
     * @param  string   $paraBirimi   USD, EUR ...
     * @return float|null Kur bulunamazsa null
     */
    public function faturaKuruGetir(string $faturaTarihi, string $paraBirimi, ?int $kullaniciId = null): ?float
    {
        $paraBirimi = strtoupper(trim($paraBirimi));
        if ($paraBirimi === '' || $paraBirimi === 'TRY') {
            return 1.0;
        }

        $tarih = $this->tarihNormalize($faturaTarihi);
        if ($tarih === null) {
            return null;
        }

        $hesap = $this->faturaHesapTipiGetir($this->kanalGetir());

        $kur = $this->tablodanKurOku($tarih, $paraBirimi, $hesap);
        if ($kur !== null) {
            return $kur;
        }

        // Tabloda o doneme ait yayin yok: arsivden doldurup tekrar dene.
        if ($this->arsivdenDoldur($tarih, $kullaniciId)) {
            return $this->tablodanKurOku($tarih, $paraBirimi, $hesap);
        }

        return null;
    }

    /**
     * Fatura tarihinden kucuk en yakin yayina ait kuru okur.
     */
    private function tablodanKurOku(string $tarih, string $paraBirimi, string $hesap): ?float
    {
        $satir = $this->db->fetchOne("
            SELECT TOP 1
                   dk.DovizKurlari_Alis  AS alis,
                   dk.DovizKurlari_Satis AS satis
            FROM dbo.tanim_DovizKurlari dk
            WHERE dk.DovizKurlari_ParaBirimi = ?
              AND dk.DovizKurlari_Tarih < ?
              AND dk.Durum = 1
            ORDER BY dk.DovizKurlari_Tarih DESC
        ", [$paraBirimi, $tarih]);

        if (!$satir) {
            return null;
        }

        $deger = $this->hesapDegeri(
            $hesap,
            $satir['alis']  !== null ? (float)$satir['alis']  : null,
            $satir['satis'] !== null ? (float)$satir['satis'] : null
        );

        return ($deger !== null && $deger > 0) ? $deger : null;
    }

    /**
     * TCMB arsivinden (kurlar/YYYYMM/ddMMyyyy.xml) fatura tarihinden geriye dogru
     * ilk yayini bulup tabloya yazar. Tatil/hafta sonu icin ARSIV_AZAMI_GUN kadar
     * geriye bakilir; ilk basarili gunde durulur.
     */
    private function arsivdenDoldur(string $tarih, ?int $kullaniciId): bool
    {
        try {
            $gun = new DateTime($tarih);
        } catch (Exception $e) {
            return false;
        }

        $kok = $this->arsivKoku();

        for ($i = 0; $i < self::ARSIV_AZAMI_GUN; $i++) {
            $gun->modify('-1 day');

            $url = $kok . $gun->format('Ym') . '/' . $gun->format('dmY') . '.xml';

            $indirme = $this->xmlIndir($url);
            if (!$indirme['success']) {
                continue; // tatil/hafta sonu: o gune ait dosya yok
            }

            if ($this->xmlIsle($indirme['xml'], $kullaniciId)['success']) {
                return true;
            }
        }

        error_log("TCMB arsivinde {$tarih} oncesi kur bulunamadi (" . self::ARSIV_AZAMI_GUN . ' gun tarandi).');
        return false;
    }

    /**
     * Arsiv taban adresi. Kanal endpoint'inden turetilir
     * (".../kurlar/today.xml" -> ".../kurlar/"), cozulemezse varsayilana duser.
     */
    private function arsivKoku(): string
    {
        $kanal = $this->kanalGetir();
        $url   = $kanal['Kanal_Endpoint'] ?? self::VARSAYILAN_ENDPOINT;

        $kok = substr($url, 0, strrpos($url, '/') + 1);

        return $kok !== '' ? $kok : 'https://www.tcmb.gov.tr/kurlar/';
    }

    /** Y-m-d / d.m.Y girdisini Y-m-d'ye cevirir; gecersizse null. */
    private function tarihNormalize(string $tarih): ?string
    {
        $tarih = trim($tarih);
        if ($tarih === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $tarih)) {
            return substr($tarih, 0, 10);
        }

        return $this->tarihCoz($tarih);
    }

    /**
     * Ayni tarih + para birimi kaydi varsa gunceller, yoksa ekler.
     * Es zamanli iki istek ayni kaydi yazmaya calisirsa unique index hatasini yutar.
     */
    private function kurYaz(
        string $tarih,
        string $kod,
        string $ad,
        ?float $alis,
        ?float $satis,
        ?float $efAlis,
        ?float $efSatis,
        ?int $kullaniciId
    ): bool {
        $simdi = date('Y-m-d H:i:s');

        try {
            $mevcut = $this->db->fetchOne("
                SELECT DovizKurlari_id FROM dbo.tanim_DovizKurlari
                WHERE DovizKurlari_Tarih = ? AND DovizKurlari_ParaBirimi = ?
            ", [$tarih, $kod]);

            if ($mevcut) {
                $this->db->update('dbo.tanim_DovizKurlari', [
                    'DovizKurlari_ParaBirimiAd' => $ad,
                    'DovizKurlari_Alis'         => $alis,
                    'DovizKurlari_Satis'        => $satis,
                    'DovizKurlari_EfektifAlis'  => $efAlis,
                    'DovizKurlari_EfektifSatis' => $efSatis,
                    'GuncelleyenKullanici'      => $kullaniciId,
                    'GuncellemeTarihi'          => $simdi,
                    'Durum'                     => 1,
                ], ['DovizKurlari_id' => $mevcut['DovizKurlari_id']]);
            } else {
                $this->db->insert('dbo.tanim_DovizKurlari', [
                    'DovizKurlari_Tarih'        => $tarih,
                    'DovizKurlari_ParaBirimi'   => $kod,
                    'DovizKurlari_ParaBirimiAd' => $ad,
                    'DovizKurlari_Alis'         => $alis,
                    'DovizKurlari_Satis'        => $satis,
                    'DovizKurlari_EfektifAlis'  => $efAlis,
                    'DovizKurlari_EfektifSatis' => $efSatis,
                    'DovizKurlari_Kaynak'       => 'TCMB',
                    'OlusturanKullanici'        => $kullaniciId,
                    'OlusturmaTarihi'           => $simdi,
                    'GuncelleyenKullanici'      => $kullaniciId,
                    'GuncellemeTarihi'          => $simdi,
                    'Durum'                     => 1,
                ]);
            }

            return true;
        } catch (Exception $e) {
            // Es zamanli yazim (unique index) veya gecici DB hatasi
            error_log("Doviz kuru yazilamadi ({$kod} / {$tarih}): " . $e->getMessage());
            return false;
        }
    }

    /** "30.07.2026" -> "2026-07-30" */
    private function tarihCoz(string $tcmbTarih): ?string
    {
        $tcmbTarih = trim($tcmbTarih);
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $tcmbTarih, $p)) {
            return null;
        }
        if (!checkdate((int)$p[2], (int)$p[1], (int)$p[3])) {
            return null;
        }
        return "{$p[3]}-{$p[2]}-{$p[1]}";
    }

    /** TCMB XML'inde ondalik ayirici noktadir; bos alanlar NULL dondurur. */
    private function sayiCoz(string $deger): ?float
    {
        $deger = trim($deger);
        return ($deger === '' || !is_numeric($deger)) ? null : (float)$deger;
    }
}
