<?php
/**
 * Banka Hareketi - Ödeme Eşleştirme (cari mutabakat)
 *
 * Banka hesabına giren tahsilatları (tip = A) Odeme_Kalemleri kayıtlarıyla
 * eşleştirir. Sistem yalnızca skorlu ADAY üretir; eşleşmeyi kullanıcı onaylar.
 * Otomatik yazma yapılmaz — yanlış eşleşen bir tahsilat cari bakiyesini bozar.
 *
 * Skor bileşenleri (toplam 100 üzerinden):
 *   Tutar     : 50  — kuruşu kuruşuna eşitse tam puan
 *   Tarih     : 30  — aynı gün tam puan, uzaklaştıkça azalır
 *   VKN/TCKN  : +40 aynıysa, -25 farklıysa
 *   Ödeme tipi: ±10 — banka grubu artı, nakit eksi (nakit banka hesabında görünmez)
 *
 * Unvan/ad benzerliği KULLANILMAZ. Bankadaki karşı taraf adı ile cari kartındaki
 * unvan sıklıkla tutmuyor (CARYA TURİZM ↔ REGNUM HOTEL) ve benzer adlı farklı
 * mükellefleri karıştırma riski taşıyor; kimlik ölçütü yalnızca VKN/TCKN'dir.
 *
 * @see admin/includes/BankaHareketHelper.php
 */

class BankaEslestirmeHelper
{
    /** Aday listesinde gösterilecek asgari skor */
    const ASGARI_SKOR = 25;

    /** "Güçlü aday" sayılan eşik — arayüzde vurgulanır */
    const GUCLU_SKOR = 70;

    /** Aday aranırken kullanılan tarih penceresi (gün) */
    const TARIH_PENCERE = 30;

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────
    // ADAY ÜRETİMİ
    // ─────────────────────────────────────────────

    /**
     * Bir banka hareketi için skorlu ödeme kalemi adaylarını döner.
     *
     * @return array Skora göre azalan sıralı aday listesi
     */
    public function adaylar(int $hareketId, int $limit = 20): array
    {
        $hareket = $this->hareket($hareketId);

        if (!$hareket) {
            throw new Exception('Banka hareketi bulunamadı.');
        }

        $tutar  = (float)$hareket['BankaHareketleri_Tutar'];
        $tarih  = $hareket['BankaHareketleri_IslemTarihi'];
        $tarihM = $tarih instanceof DateTime ? $tarih->format('Y-m-d') : substr((string)$tarih, 0, 10);

        // Tutar asli kriterdir: yalnızca ±%5 bandındaki kalemler havuza girer.
        // (Tarihi tutan her kalem havuza alınırsa, tutarı hiç uymayan kayıtlar
        //  "aynı gün" puanıyla listeye sızıyor ve öneri listesi gürültüye boğuluyor.)
        // Tarih penceresi bunu daraltır; kalemde tarih yoksa yalnız tutara bakılır.
        $kalemler = $this->db->fetchAll("
            SELECT
                ok.Odeme_Kalemleri_id          AS kalem_id,
                ok.Odeme_Kalemleri_tip         AS tip,
                ok.Odeme_Kalemleri_tutar       AS tutar,
                ok.Odeme_Kalemleri_belge_no    AS belge_no,
                ok.Odeme_Kalemleri_odendi      AS odendi,
                CONVERT(VARCHAR(10), ok.Odeme_Kalemleri_odeme_tar, 120) AS odeme_tar,
                CONVERT(VARCHAR(10), ok.Odeme_Kalemleri_vade, 120)      AS vade,
                ok.Odeme_Kalemleri_aciklama    AS aciklama,
                o.Odemeler_id                  AS odeme_id,
                o.Odemeler_cari_id             AS cari_id,
                ISNULL(c.cari_unvan, c.cari_adi) AS cari_unvan,
                c.cari_adi                     AS cari_adi,
                c.cari_vergi_no                AS cari_vergi_no
            FROM dbo.Odeme_Kalemleri ok
            INNER JOIN dbo.Odemeler o ON o.Odemeler_id = ok.Odeme_Kalemleri_odeme_id
            LEFT JOIN dbo.Cari c ON c.cari_id = o.Odemeler_cari_id
            WHERE ok.Durum = 1
              AND o.Durum = 1
              AND ok.Odeme_Kalemleri_tutar IS NOT NULL
              AND NOT EXISTS (
                    SELECT 1 FROM dbo.BankaHareketleri b
                    WHERE b.BankaHareketleri_OdemeKalem_id = ok.Odeme_Kalemleri_id
                      AND b.Durum = 1
              )
              AND ABS(ok.Odeme_Kalemleri_tutar - ?) <= (? * 0.05)
              AND (
                    ISNULL(ok.Odeme_Kalemleri_odeme_tar, ok.Odeme_Kalemleri_vade) IS NULL
                 OR ABS(DATEDIFF(DAY, ISNULL(ok.Odeme_Kalemleri_odeme_tar, ok.Odeme_Kalemleri_vade), ?)) <= ?
              )
        ", [$tutar, $tutar, $tarihM, self::TARIH_PENCERE]);

        $adaylar = [];

        foreach ($kalemler as $kalem) {
            $degerlendirme = $this->skorla($hareket, $kalem);

            if ($degerlendirme['skor'] < self::ASGARI_SKOR) {
                continue;
            }

            $kalem['skor']    = $degerlendirme['skor'];
            $kalem['gerekce'] = $degerlendirme['gerekce'];
            $kalem['guclu']   = $degerlendirme['skor'] >= self::GUCLU_SKOR;

            $adaylar[] = $kalem;
        }

        usort($adaylar, function ($a, $b) {
            return $b['skor'] <=> $a['skor'];
        });

        return array_slice($adaylar, 0, $limit);
    }

    /**
     * Tek bir hareket-kalem çifti için skor ve gerekçe üretir.
     *
     * @return array{skor:int,gerekce:array}
     */
    private function skorla(array $hareket, array $kalem): array
    {
        $skor    = 0;
        $gerekce = [];

        // ── Tutar (50) ──────────────────────────────
        $hareketTutar = (float)$hareket['BankaHareketleri_Tutar'];
        $kalemTutar   = (float)$kalem['tutar'];
        $fark         = abs($hareketTutar - $kalemTutar);

        if ($fark <= 0.01) {
            $skor += 50;
            $gerekce[] = 'Tutar birebir aynı';
        } elseif ($hareketTutar > 0 && $fark / $hareketTutar <= 0.01) {
            $skor += 30;
            $gerekce[] = 'Tutar farkı %1 altında';
        } elseif ($hareketTutar > 0 && $fark / $hareketTutar <= 0.05) {
            $skor += 15;
            $gerekce[] = 'Tutar farkı %5 altında';
        } else {
            // Tutar tutmayan kalem aday değildir; tarih ve unvan puanı
            // tek başına yanlış eşleşmeye yol açar.
            return ['skor' => 0, 'gerekce' => []];
        }

        // ── Tarih (30) ──────────────────────────────
        $hareketTarih = $hareket['BankaHareketleri_IslemTarihi'];
        $hareketTarih = $hareketTarih instanceof DateTime
            ? $hareketTarih->format('Y-m-d')
            : substr((string)$hareketTarih, 0, 10);

        $kalemTarih = $kalem['odeme_tar'] ?: $kalem['vade'];

        if ($hareketTarih && $kalemTarih) {
            $gunFark = (int)round(
                (strtotime($hareketTarih) - strtotime($kalemTarih)) / 86400
            );
            $mutlak = abs($gunFark);

            if ($mutlak === 0) {
                $skor += 30;
                $gerekce[] = 'Aynı gün';
            } elseif ($mutlak <= 3) {
                $skor += 20;
                $gerekce[] = $mutlak . ' gün fark';
            } elseif ($mutlak <= 7) {
                $skor += 10;
                $gerekce[] = $mutlak . ' gün fark';
            } elseif ($mutlak <= 15) {
                $skor += 5;
                $gerekce[] = $mutlak . ' gün fark';
            }
        }

        // ── Kimlik (40) ─────────────────────────────
        // Yalnızca VKN/TCKN karşılaştırılır. Unvan benzerliği kullanılmaz:
        // bankadaki ad ile cari kartındaki unvan sıklıkla tutmuyor
        // (CARYA TURİZM ↔ REGNUM HOTEL, MURADİYE ORGANİZE SANAYİ ↔ MURADİYE OSGB)
        // ve benzer adlı farklı mükellefleri birbirine karıştırma riski taşıyor.
        $hareketVkn = $this->normalizeVkn($hareket['BankaHareketleri_VknTckn'] ?? '');
        $cariVkn    = $this->normalizeVkn($kalem['cari_vergi_no'] ?? '');

        if ($hareketVkn !== '' && $cariVkn !== '') {
            if ($hareketVkn === $cariVkn) {
                $skor += 40;
                $gerekce[] = 'VKN/TCKN aynı';
            } else {
                $skor -= 25;
                $gerekce[] = 'VKN/TCKN farklı';
            }
        }

        // ── Ödeme tipi (±10) ────────────────────────
        $bankaTipleri = ['BANKA_HAVALE', 'CEK', 'SENET', 'KREDI_KARTI'];
        $tip          = strtoupper((string)($kalem['tip'] ?? ''));

        if (in_array($tip, $bankaTipleri, true)) {
            $skor += 10;
        } elseif ($tip === 'NAKIT') {
            // Elden yapılan tahsilat banka hesabında hareket oluşturmaz
            $skor -= 10;
            $gerekce[] = 'Nakit kalem (banka hareketiyle örtüşmesi beklenmez)';
        }

        return [
            'skor'    => max(0, min(100, $skor)),
            'gerekce' => $gerekce,
        ];
    }

    /**
     * VKN/TCKN karşılaştırma biçimi.
     *
     * Rakam dışı karakterler atılır ve baştaki sıfırlar kırpılır: bankadan gelen
     * numara 11 haneye sıfırla doldurulmuş olabiliyor (04700590107), cari kartında
     * ise 10 hane duruyor (4700590107) — aynı mükellef farklı görünüyordu.
     */
    private function normalizeVkn($deger): string
    {
        $rakam = preg_replace('/\D/', '', (string)$deger);

        return ltrim((string)$rakam, '0');
    }

    /**
     * Metni karşılaştırma için sadeleştirir (Türkçe harfler, noktalama, şirket ekleri).
     *
     * Yalnızca banka adını ödeme formundaki kanal adıyla eşlemekte kullanılır
     * (bankaKanalAdi). Cari eşleştirmesinde ad karşılaştırması yapılmaz.
     */
    private function normalizeAd(string $metin): string
    {
        $metin = mb_strtoupper(trim($metin), 'UTF-8');

        $metin = strtr($metin, [
            'İ' => 'I', 'Ş' => 'S', 'Ğ' => 'G', 'Ü' => 'U', 'Ö' => 'O', 'Ç' => 'C',
            'ı' => 'I', 'ş' => 'S', 'ğ' => 'G', 'ü' => 'U', 'ö' => 'O', 'ç' => 'C',
        ]);

        // Şirket türü ekleri
        $ekler = [
            'ANONIM SIRKETI', 'ANONIM SIRKET', 'LIMITED SIRKETI', 'LIMITED SIRKET',
            'A.S.', 'A.S', 'AS.', 'LTD.STI.', 'LTD STI', 'LTD.', 'STI.', 'STI',
            'SAN.', 'TIC.', 'SANAYI', 'TICARET', 'VE',
        ];

        $metin = str_replace($ekler, ' ', $metin);
        $metin = preg_replace('/[^A-Z0-9 ]/u', ' ', $metin);
        $metin = preg_replace('/\s+/', ' ', $metin);

        return trim((string)$metin);
    }

    /**
     * Hareketin VKN/TCKN değerinden cari kartını bulur.
     *
     * Ödeme kaydı hiç girilmemiş tahsilatlarda bile karşı tarafı kesin olarak
     * tespit eder; arayüzde tek tıkla cari atamak için kullanılır.
     */
    public function vknIleCari(int $hareketId): ?array
    {
        $hareket = $this->hareket($hareketId);

        if (!$hareket) {
            return null;
        }

        $vkn = $this->normalizeVkn($hareket['BankaHareketleri_VknTckn'] ?? '');

        if ($vkn === '') {
            return null;
        }

        // Baştaki sıfırlar iki tarafta da kırpılarak karşılaştırılır
        $cari = $this->db->fetchOne("
            SELECT TOP 1 cari_id, ISNULL(cari_unvan, cari_adi) AS cari_unvan, cari_vergi_no
            FROM dbo.Cari
            WHERE TRY_CONVERT(BIGINT, REPLACE(REPLACE(ISNULL(cari_vergi_no, ''), ' ', ''), '-', '')) = TRY_CONVERT(BIGINT, ?)
            ORDER BY cari_id
        ", [$vkn]);

        return $cari ?: null;
    }

    // ─────────────────────────────────────────────
    // EŞLEŞTİRME İŞLEMLERİ
    // ─────────────────────────────────────────────

    /**
     * Hareketi bir ödeme kalemine bağlar.
     * Kalemin cari kaydı harekete de yazılır, böylece cari ekstresi tek sorguyla çıkar.
     */
    public function eslestir(int $hareketId, int $kalemId, int $kullaniciId, ?string $not = null): void
    {
        $hareket = $this->hareket($hareketId);

        if (!$hareket) {
            throw new Exception('Banka hareketi bulunamadı.');
        }

        $kalem = $this->db->fetchOne("
            SELECT ok.Odeme_Kalemleri_id, o.Odemeler_cari_id
            FROM dbo.Odeme_Kalemleri ok
            INNER JOIN dbo.Odemeler o ON o.Odemeler_id = ok.Odeme_Kalemleri_odeme_id
            WHERE ok.Odeme_Kalemleri_id = ? AND ok.Durum = 1
        ", [$kalemId]);

        if (!$kalem) {
            throw new Exception('Ödeme kalemi bulunamadı.');
        }

        // Aynı kalem başka bir harekete bağlıysa çift tahsilat oluşur
        $bagli = $this->db->fetchOne("
            SELECT BankaHareketleri_id
            FROM dbo.BankaHareketleri
            WHERE BankaHareketleri_OdemeKalem_id = ?
              AND BankaHareketleri_id <> ?
              AND Durum = 1
        ", [$kalemId, $hareketId]);

        if ($bagli) {
            throw new Exception('Bu ödeme kalemi başka bir banka hareketine bağlı.');
        }

        $skor = null;

        foreach ($this->adaylar($hareketId, 50) as $aday) {
            if ((int)$aday['kalem_id'] === $kalemId) {
                $skor = (int)$aday['skor'];
                break;
            }
        }

        $this->db->update('BankaHareketleri', [
            'BankaHareketleri_OdemeKalem_id'       => $kalemId,
            'BankaHareketleri_Cari_id'             => $kalem['Odemeler_cari_id'] ?: null,
            'BankaHareketleri_EslesmeDurum'        => BankaHareketHelper::ESLESME_MANUEL,
            'BankaHareketleri_EslesmeSkor'         => $skor,
            'BankaHareketleri_EslesmeNot'          => $not,
            'BankaHareketleri_EslestirenKullanici' => $kullaniciId,
            'BankaHareketleri_EslestirmeTarihi'    => date('Y-m-d H:i:s'),
            'GuncelleyenKullanici'                 => $kullaniciId,
            'GuncellemeTarihi'                     => date('Y-m-d H:i:s'),
        ], ['BankaHareketleri_id' => $hareketId]);
    }

    /**
     * Hareketi bir cariye bağlar.
     *
     * Varsayılan davranışta ödeme kaydı da üretilir; aksi halde hareket cariye
     * bağlı görünür ama ödeme formunun "Ödeme Yöntemleri" bölümünde satır
     * oluşmaz ve tahsilat bakiyeye girmez. İzlenen sıra cron ile aynıdır:
     *
     *   1. Aynı gün ve aynı tutarlı, bağlanmamış bir kalem varsa ona bağlanır.
     *   2. Yoksa Odemeler + Odeme_Kalemleri (BANKA_HAVALE) oluşturulup bağlanır.
     *
     * $odemeOlustur = false verildiğinde eski davranış korunur: yalnız cari
     * yazılır, kalem bağlanmaz. Karşılığı sonra girilecek hareketler (avans,
     * teminat, virman) için kullanılır.
     *
     * Ödeme kalemi yalnız tahsilat (tip = A) hareketleri için üretilir.
     *
     * @return array{islem:string,kalem_id:?int} islem: eslesti | olusturuldu | cari_atandi
     */
    public function cariAta(int $hareketId, int $cariId, int $kullaniciId, ?string $not = null, bool $odemeOlustur = true): array
    {
        $cari = $this->db->fetchOne("SELECT cari_id FROM dbo.Cari WHERE cari_id = ?", [$cariId]);

        if (!$cari) {
            throw new Exception('Cari kartı bulunamadı.');
        }

        $hareket = $this->hareket($hareketId);

        if (!$hareket) {
            throw new Exception('Banka hareketi bulunamadı.');
        }

        if ($odemeOlustur && ($hareket['BankaHareketleri_Tip'] ?? '') === 'A') {
            $kalem = $this->ayniGunKalem($hareket, $cariId);

            if ($kalem) {
                $kalemId = (int)$kalem['kalem_id'];

                $this->bagla($hareketId, $kalemId, $cariId, $kullaniciId,
                    $not ?: 'Cari atanırken aynı gün ve tutardaki mevcut ödeme kalemine bağlandı.',
                    BankaHareketHelper::ESLESME_MANUEL);

                return ['islem' => 'eslesti', 'kalem_id' => $kalemId];
            }

            $kalemId = $this->odemeOlustur($hareket, $cariId, $kullaniciId,
                BankaHareketHelper::ESLESME_MANUEL,
                $not ?: 'Ödeme kaydı banka hareketinden cari atama sırasında oluşturuldu.');

            return ['islem' => 'olusturuldu', 'kalem_id' => $kalemId];
        }

        $this->db->update('BankaHareketleri', [
            'BankaHareketleri_Cari_id'             => $cariId,
            'BankaHareketleri_OdemeKalem_id'       => null,
            'BankaHareketleri_EslesmeDurum'        => BankaHareketHelper::ESLESME_MANUEL,
            'BankaHareketleri_EslesmeNot'          => $not,
            'BankaHareketleri_EslestirenKullanici' => $kullaniciId,
            'BankaHareketleri_EslestirmeTarihi'    => date('Y-m-d H:i:s'),
            'GuncelleyenKullanici'                 => $kullaniciId,
            'GuncellemeTarihi'                     => date('Y-m-d H:i:s'),
        ], ['BankaHareketleri_id' => $hareketId]);

        return ['islem' => 'cari_atandi', 'kalem_id' => null];
    }

    /** Hareketi mutabakat dışında bırakır (banka masrafı, virman vb.) */
    public function yoksay(int $hareketId, int $kullaniciId, ?string $not = null): void
    {
        $this->db->update('BankaHareketleri', [
            'BankaHareketleri_EslesmeDurum'        => BankaHareketHelper::ESLESME_YOKSAYILDI,
            'BankaHareketleri_OdemeKalem_id'       => null,
            'BankaHareketleri_EslesmeNot'          => $not,
            'BankaHareketleri_EslestirenKullanici' => $kullaniciId,
            'BankaHareketleri_EslestirmeTarihi'    => date('Y-m-d H:i:s'),
            'GuncelleyenKullanici'                 => $kullaniciId,
            'GuncellemeTarihi'                     => date('Y-m-d H:i:s'),
        ], ['BankaHareketleri_id' => $hareketId]);
    }

    /** Eşleşmeyi geri alır */
    public function kaldir(int $hareketId, int $kullaniciId): void
    {
        $this->db->update('BankaHareketleri', [
            'BankaHareketleri_OdemeKalem_id'       => null,
            'BankaHareketleri_Cari_id'             => null,
            'BankaHareketleri_EslesmeDurum'        => BankaHareketHelper::ESLESME_BEKLIYOR,
            'BankaHareketleri_EslesmeSkor'         => null,
            'BankaHareketleri_EslesmeNot'          => null,
            'BankaHareketleri_EslestirenKullanici' => null,
            'BankaHareketleri_EslestirmeTarihi'    => null,
            'GuncelleyenKullanici'                 => $kullaniciId,
            'GuncellemeTarihi'                     => date('Y-m-d H:i:s'),
        ], ['BankaHareketleri_id' => $hareketId]);
    }

    // ─────────────────────────────────────────────
    // OTOMATİK İŞLEME (cron)
    // ─────────────────────────────────────────────

    /**
     * Eşleşme bekleyen tahsilatları VKN üzerinden otomatik işler.
     *
     * Karşı tarafın VKN/TCKN değeri bir carinin vergi numarasıyla birebir
     * tutuyorsa iki yoldan biri izlenir:
     *
     *   1. O cariye ait, tutarı ve ödeme tarihi AYNI olan bağlanmamış bir
     *      ödeme kalemi varsa → ödeme zaten girilmiştir, hareket o kaleme
     *      bağlanır ve "Otomatik" işaretlenir.
     *
     *   2. Böyle bir kalem yoksa → ödeme kaydı hiç girilmemiştir, yeni bir
     *      Odemeler başlığı ve BANKA_HAVALE kalemi oluşturulup harekete bağlanır.
     *
     * VKN'si carilerde bulunmayan hareketler (grup içi transfer, tedarikçi,
     * elektronik para kuruluşu vb.) dokunulmadan bekliyor durumunda kalır.
     *
     * Aynı gün aynı cariden ödeme kaydı bulunup tutarı tutmuyorsa hiçbir şey
     * yapılmaz ("belirsiz"): kısmi ödeme veya hatalı giriş olabilir, çift
     * tahsilat yaratmamak için karar kullanıcıya bırakılır.
     *
     * @param  bool $sadeceRapor true ise hiçbir kayıt yazılmaz, yalnız sayım döner
     * @return array{eslesen:int,olusturulan:int,belirsiz:int,atlanan:int,hata:int,detay:array}
     */
    public function otomatikIsle(int $kullaniciId = 1, bool $sadeceRapor = false): array
    {
        $hareketler = $this->db->fetchAll("
            SELECT h.BankaHareketleri_id AS id
            FROM dbo.BankaHareketleri h
            WHERE h.Durum = 1
              AND h.BankaHareketleri_Tip = 'A'
              AND h.BankaHareketleri_EslesmeDurum = " . BankaHareketHelper::ESLESME_BEKLIYOR . "
              AND ISNULL(h.BankaHareketleri_VknTckn, '') <> ''
            ORDER BY h.BankaHareketleri_IslemTarihi
        ");

        $sonuc = [
            'eslesen'     => 0,
            'olusturulan' => 0,
            'belirsiz'    => 0,
            'atlanan'     => 0,
            'hata'        => 0,
            'detay'       => [],
        ];

        foreach ($hareketler as $satir) {
            $hareketId = (int)$satir['id'];

            try {
                $hareket = $this->hareket($hareketId);

                if (!$hareket) {
                    $sonuc['atlanan']++;
                    continue;
                }

                $cari = $this->vknIleCari($hareketId);

                if (!$cari) {
                    // Cari kartı yok — karşı taraf müşteri değil, elle değerlendirilir
                    $sonuc['atlanan']++;
                    continue;
                }

                $cariId = (int)$cari['cari_id'];
                $kalem  = $this->ayniGunKalem($hareket, $cariId);

                if ($kalem) {
                    if (!$sadeceRapor) {
                        $this->bagla($hareketId, (int)$kalem['kalem_id'], $cariId, $kullaniciId,
                            'Mevcut ödeme kalemi ile otomatik eşleşti (VKN, tutar ve tarih aynı).');
                    }

                    $sonuc['eslesen']++;
                    $sonuc['detay'][] = [
                        'hareket_id' => $hareketId,
                        'islem'      => 'eslesti',
                        'cari'       => $cari['cari_unvan'],
                        'karsi'      => $hareket['BankaHareketleri_KarsiTaraf'],
                        'vkn'        => $hareket['BankaHareketleri_VknTckn'],
                        'tutar'      => (float)$hareket['BankaHareketleri_Tutar'],
                    ];
                } elseif ($this->ayniGunOdemeVar($hareket, $cariId)) {
                    // Aynı gün aynı cariden ödeme kaydı var ama tutar tutmuyor:
                    // kısmi ödeme veya hatalı giriş olabilir, otomatik kayıt açılmaz.
                    $sonuc['belirsiz']++;
                    $sonuc['detay'][] = [
                        'hareket_id' => $hareketId,
                        'islem'      => 'belirsiz',
                        'cari'       => $cari['cari_unvan'],
                        'karsi'      => $hareket['BankaHareketleri_KarsiTaraf'],
                        'vkn'        => $hareket['BankaHareketleri_VknTckn'],
                        'tutar'      => (float)$hareket['BankaHareketleri_Tutar'],
                    ];
                } else {
                    if (!$sadeceRapor) {
                        $this->odemeOlustur($hareket, $cariId, $kullaniciId);
                    }

                    $sonuc['olusturulan']++;
                    $sonuc['detay'][] = [
                        'hareket_id' => $hareketId,
                        'islem'      => 'olusturuldu',
                        'cari'       => $cari['cari_unvan'],
                        'karsi'      => $hareket['BankaHareketleri_KarsiTaraf'],
                        'vkn'        => $hareket['BankaHareketleri_VknTckn'],
                        'tutar'      => (float)$hareket['BankaHareketleri_Tutar'],
                    ];
                }
            } catch (Throwable $e) {
                $sonuc['hata']++;
                $sonuc['detay'][] = [
                    'hareket_id' => $hareketId,
                    'islem'      => 'hata',
                    'mesaj'      => $e->getMessage(),
                ];

                error_log('Banka otomatik eşleştirme hatası (#' . $hareketId . '): ' . $e->getMessage());
            }
        }

        return $sonuc;
    }

    /**
     * Cariye ait, ödeme tarihi hareketle AYNI GÜN olan bağlanmamış kalemi bulur.
     * Tarihte tolerans yoktur; tutarda ise binde 1'lik sapma kabul edilir.
     *
     * @param float $tutarTolerans Oransal tutar toleransı (0.001 = binde 1)
     */
    private function ayniGunKalem(array $hareket, int $cariId, float $tutarTolerans = 0.001): ?array
    {
        $tarih = $hareket['BankaHareketleri_IslemTarihi'];
        $tarih = $tarih instanceof DateTime ? $tarih->format('Y-m-d') : substr((string)$tarih, 0, 10);
        $tutar = (float)$hareket['BankaHareketleri_Tutar'];

        // Kuruş farkları (54.052,30 ↔ 54.052,00) aynı ödemedir; yalnız 1 kuruş
        // aranırsa mevcut kalem bulunamayıp ikinci kez tahsilat kaydı açılır.
        $sapma = max(0.01, abs($tutar) * $tutarTolerans);

        $kayit = $this->db->fetchOne("
            SELECT TOP 1 ok.Odeme_Kalemleri_id AS kalem_id, ok.Odeme_Kalemleri_tutar AS tutar
            FROM dbo.Odeme_Kalemleri ok
            INNER JOIN dbo.Odemeler o ON o.Odemeler_id = ok.Odeme_Kalemleri_odeme_id
            WHERE ok.Durum = 1
              AND o.Durum = 1
              AND o.Odemeler_cari_id = ?
              AND ABS(ok.Odeme_Kalemleri_tutar - ?) <= ?
              AND CONVERT(DATE, ISNULL(ok.Odeme_Kalemleri_odeme_tar, ok.Odeme_Kalemleri_vade)) = CONVERT(DATE, ?)
              AND NOT EXISTS (
                    SELECT 1 FROM dbo.BankaHareketleri b
                    WHERE b.BankaHareketleri_OdemeKalem_id = ok.Odeme_Kalemleri_id
                      AND b.Durum = 1
              )
            ORDER BY ABS(ok.Odeme_Kalemleri_tutar - ?), ok.Odeme_Kalemleri_id
        ", [$cariId, $tutar, $sapma, $tarih, $tutar]);

        return $kayit ?: null;
    }

    /**
     * Aynı cari için o gün girilmiş HERHANGİ bir ödeme kalemi var mı?
     *
     * Otomatik kayıt açmadan önceki son güvenlik kontrolü: tutarı tutmasa bile
     * aynı gün aynı cariden bir ödeme kaydı varsa, bu büyük olasılıkla aynı
     * tahsilattır (kısmi giriş, yuvarlama, iki kalemli ödeme). Böyle bir durumda
     * kayıt açılmaz, hareket elle değerlendirilsin diye bekliyor bırakılır.
     */
    private function ayniGunOdemeVar(array $hareket, int $cariId): bool
    {
        $tarih = $hareket['BankaHareketleri_IslemTarihi'];
        $tarih = $tarih instanceof DateTime ? $tarih->format('Y-m-d') : substr((string)$tarih, 0, 10);

        $kayit = $this->db->fetchOne("
            SELECT TOP 1 ok.Odeme_Kalemleri_id
            FROM dbo.Odeme_Kalemleri ok
            INNER JOIN dbo.Odemeler o ON o.Odemeler_id = ok.Odeme_Kalemleri_odeme_id
            WHERE ok.Durum = 1
              AND o.Durum = 1
              AND o.Odemeler_cari_id = ?
              AND CONVERT(DATE, ISNULL(ok.Odeme_Kalemleri_odeme_tar, ok.Odeme_Kalemleri_vade)) = CONVERT(DATE, ?)
        ", [$cariId, $tarih]);

        return (bool)$kayit;
    }

    /**
     * Hareketi kaleme bağlar.
     *
     * $durum ile kaydın nasıl işaretleneceği belirlenir: cron çağrılarında
     * "Otomatik", kullanıcının cari atamasında "Manuel".
     */
    private function bagla(int $hareketId, int $kalemId, int $cariId, int $kullaniciId, string $not, int $durum = BankaHareketHelper::ESLESME_OTOMATIK): void
    {
        $this->db->update('BankaHareketleri', [
            'BankaHareketleri_OdemeKalem_id'       => $kalemId,
            'BankaHareketleri_Cari_id'             => $cariId,
            'BankaHareketleri_EslesmeDurum'        => $durum,
            'BankaHareketleri_EslesmeSkor'         => 100,
            'BankaHareketleri_EslesmeNot'          => $not,
            'BankaHareketleri_EslestirenKullanici' => $kullaniciId,
            'BankaHareketleri_EslestirmeTarihi'    => date('Y-m-d H:i:s'),
            'GuncelleyenKullanici'                 => $kullaniciId,
            'GuncellemeTarihi'                     => date('Y-m-d H:i:s'),
        ], ['BankaHareketleri_id' => $hareketId]);
    }

    /**
     * Banka hareketinden yeni ödeme kaydı üretir (Odemeler + Odeme_Kalemleri)
     * ve hareketi bu kaleme bağlar. Üçü tek işlemde yazılır; biri başarısız
     * olursa yarım ödeme kaydı kalmaz.
     *
     * @return int Oluşturulan ödeme kaleminin id'si
     */
    private function odemeOlustur(array $hareket, int $cariId, int $kullaniciId, int $durum = BankaHareketHelper::ESLESME_OTOMATIK, ?string $not = null): int
    {
        $hareketId = (int)$hareket['BankaHareketleri_id'];
        $tutar     = (float)$hareket['BankaHareketleri_Tutar'];
        $simdi     = date('Y-m-d H:i:s');

        $tarih = $hareket['BankaHareketleri_IslemTarihi'];
        $tarih = $tarih instanceof DateTime ? $tarih->format('Y-m-d') : substr((string)$tarih, 0, 10);

        $aciklama = 'Banka hareketinden otomatik oluşturuldu — '
            . ($hareket['BankaHareketleri_BankaAd'] ?? '') . ' / '
            . ($hareket['BankaHareketleri_KarsiTaraf'] ?? '');

        $conn = $this->db->getConnection();
        sqlsrv_begin_transaction($conn);

        try {
            $this->db->insert('Odemeler', [
                'Odemeler_cari_id'     => $cariId,
                'Odemeler_tarih'       => $tarih,
                'Odemeler_toplam'      => $tutar,
                'Odemeler_aciklama'    => mb_substr($aciklama, 0, 250),
                'Odemeler_durum'       => 1,
                'OlusturanKullanici'   => $kullaniciId,
                'OlusturmaTarihi'      => $simdi,
                'GuncelleyenKullanici' => $kullaniciId,
                'GuncellemeTarihi'     => $simdi,
                'Durum'                => 1,
            ]);

            $odemeId = (int)$this->db->getLastInsertId();

            if ($odemeId <= 0) {
                throw new Exception('Ödeme başlığı oluşturulamadı.');
            }

            $this->db->insert('Odeme_Kalemleri', [
                'Odeme_Kalemleri_odeme_id'  => $odemeId,
                'Odeme_Kalemleri_tip'       => 'BANKA_HAVALE',
                'Odeme_Kalemleri_belge_no'  => mb_substr((string)($hareket['BankaHareketleri_ReferansNo'] ?? ''), 0, 100),
                'Odeme_Kalemleri_vade'      => null,
                'Odeme_Kalemleri_tutar'     => $tutar,
                'Odeme_Kalemleri_banka'     => $this->bankaKanalAdi($hareket['BankaHareketleri_BankaAd'] ?? null),
                'Odeme_Kalemleri_aciklama'  => mb_substr((string)($hareket['BankaHareketleri_Aciklama'] ?? ''), 0, 250),
                'Odeme_Kalemleri_odendi'    => 1,
                'Odeme_Kalemleri_odeme_tar' => $tarih,
                'OlusturanKullanici'        => $kullaniciId,
                'OlusturmaTarihi'           => $simdi,
                'GuncelleyenKullanici'      => $kullaniciId,
                'GuncellemeTarihi'          => $simdi,
                'Durum'                     => 1,
            ]);

            $kalemId = (int)$this->db->getLastInsertId();

            if ($kalemId <= 0) {
                throw new Exception('Ödeme kalemi oluşturulamadı.');
            }

            $this->bagla($hareketId, $kalemId, $cariId, $kullaniciId,
                $not ?: 'Ödeme kaydı banka hareketinden otomatik oluşturuldu (VKN eşleşmesi).',
                $durum);

            sqlsrv_commit($conn);
        } catch (Throwable $e) {
            sqlsrv_rollback($conn);
            throw $e;
        }

        return $kalemId;
    }

    /**
     * Hareketteki banka adını ödeme formunun kullandığı kanal adına çevirir.
     *
     * Odeme_Kalemleri_banka alanı ID değil metin tutar ve ödeme formu bu değeri
     * EntegrasyonKanallari (Entegrasyon_Id = 13) listesinden seçer. Karşılığı
     * bulunamazsa hareketteki ad olduğu gibi yazılır — bilgi kaybolmaz, yalnız
     * formdaki açılır liste o satırda boş görünebilir.
     */
    private function bankaKanalAdi(?string $bankaAd): string
    {
        if (!$bankaAd) {
            return '';
        }

        static $kanallar = null;

        if ($kanallar === null) {
            $kanallar = [];

            foreach ($this->db->fetchAll("
                SELECT Kanal_Adi FROM dbo.EntegrasyonKanallari WHERE Kanal_Entegrasyon_Id = 13
            ") as $k) {
                $kanallar[] = (string)$k['Kanal_Adi'];
            }
        }

        $hedef = $this->normalizeAd($bankaAd);

        foreach ($kanallar as $kanal) {
            $ad = $this->normalizeAd($kanal);

            if ($ad === $hedef || ($ad !== '' && (strpos($ad, $hedef) !== false || strpos($hedef, $ad) !== false))) {
                return $kanal;
            }
        }

        return $bankaAd;
    }

    // ─────────────────────────────────────────────
    // YARDIMCI
    // ─────────────────────────────────────────────

    public function hareket(int $hareketId): ?array
    {
        $satir = $this->db->fetchOne("
            SELECT h.*, ISNULL(c.cari_unvan, c.cari_adi) AS cari_unvan
            FROM dbo.BankaHareketleri h
            LEFT JOIN dbo.Cari c ON c.cari_id = h.BankaHareketleri_Cari_id
            WHERE h.BankaHareketleri_id = ? AND h.Durum = 1
        ", [$hareketId]);

        return $satir ?: null;
    }
}
