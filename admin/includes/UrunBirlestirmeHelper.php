<?php
/**
 * Ürün Birleştirme Yardımcısı
 *
 * Mükerrer açılmış ürünlerin bağlı kayıtlarını tek ürüne taşır, stok miktarını birleştirir,
 * eski ürünü pasife alır ve işlemi geri alınabilir şekilde dbo.UrunBirlestirmeLog tablosuna yazar.
 */
class UrunBirlestirmeHelper
{
    /** Ürüne bağlı tablolar: anahtar => tablo / ürün kolonu / PK kolonu / etiket */
    public const TABLOLAR = [
        'stok_hareket' => ['tablo' => 'Urun_Stok_Hareketleri',     'kolon' => 'Urun_id',                               'pk' => 'Stok_Hareket_id',              'etiket' => 'Stok Hareketi'],
        'giden_fatura' => ['tablo' => 'FaturalarGidenKalemleri',   'kolon' => 'FaturalarGidenKalemleri_UrunHizmet_id', 'pk' => 'FaturalarGidenKalemleri_id',   'etiket' => 'Giden Fatura Kalemi'],
        'gelen_fatura' => ['tablo' => 'FaturalarGelenKalemleri',   'kolon' => 'FaturalarGelenKalemleri_UrunHizmet_id', 'pk' => 'FaturalarGelenKalemleri_id',   'etiket' => 'Gelen Fatura Kalemi'],
        'teklif'       => ['tablo' => 'Teklif_Kalemleri',          'kolon' => 'Teklif_Kalemleri_urun_hizmet_id',       'pk' => 'Teklif_Kalemleri_id',          'etiket' => 'Teklif Kalemi'],
        'ted_siparis'  => ['tablo' => 'TedarikciSiparisKalemleri', 'kolon' => 'TedarikciSiparisKalemleri_Urun_id',     'pk' => 'TedarikciSiparisKalemleri_id', 'etiket' => 'Tedarikçi Sipariş Kalemi'],
        'konsinye'     => ['tablo' => 'UrunKonsinye_Numune',       'kolon' => 'kn_urun_id',                            'pk' => 'kn_id',                        'etiket' => 'Konsinye / Numune'],
        'pazaryeri'    => ['tablo' => 'PazaryeriUrunleri',         'kolon' => 'urun_hizmet_id',                        'pk' => 'PazaryeriUrunleri_id',         'etiket' => 'Pazaryeri Ürünü'],
        'pz_kalem'     => ['tablo' => 'PazaryeriSiparisKalemleri', 'kolon' => 'urun_hizmet_id',                        'pk' => 'PazaryeriSiparisKalemleri_id', 'etiket' => 'Pazaryeri Sipariş Kalemi'],
        'pz_soru'      => ['tablo' => 'PazaryeriSorulari',         'kolon' => 'urun_hizmet_id',                        'pk' => 'PazaryeriSorulari_id',         'etiket' => 'Pazaryeri Sorusu'],
        'alt_urun'     => ['tablo' => 'Urun_Hizmet',               'kolon' => 'urun_hizmet_ust_id',                    'pk' => 'urun_hizmet_id',               'etiket' => 'Alt Ürün'],
    ];

    private const PARCA = 500;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** Ürün kodunu karşılaştırma için normalize eden SQL ifadesi (-, boşluk, ., /, _ atılır) */
    public static function normKodSql(string $kolon): string
    {
        return "CAST(UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM($kolon)), '-', ''), ' ', ''), '.', ''), '/', ''), '_', '')) AS NVARCHAR(100))";
    }

    public function urunGetir(int $id, bool $kilitle = false): ?array
    {
        $kilit = $kilitle ? 'WITH (UPDLOCK, HOLDLOCK)' : '';
        $row = $this->satir("
            SELECT urun_hizmet_id, urun_hizmet_kodu, urun_hizmet_adi, urun_hizmet_durum, urun_hizmet_tip,
                   ISNULL(Urun_Stok_Miktari, 0) AS stok, urun_hizmet_ust_id
            FROM dbo.Urun_Hizmet $kilit
            WHERE urun_hizmet_id = ?", [$id]);
        return $row ?: null;
    }

    /** Verilen ürünlerin tablo bazında kullanım sayıları: [urun_id => [anahtar => sayi]] */
    public function kullanimSayilari(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $sonuc = [];
        foreach ($ids as $id) {
            $sonuc[$id] = array_fill_keys(array_keys(self::TABLOLAR), 0);
        }
        foreach (array_chunk($ids, self::PARCA) as $parca) {
            $yer = implode(',', array_fill(0, count($parca), '?'));
            foreach (self::TABLOLAR as $anahtar => $t) {
                $rows = $this->satirlar(
                    "SELECT {$t['kolon']} AS uid, COUNT(*) AS sayi FROM dbo.{$t['tablo']} WHERE {$t['kolon']} IN ($yer) GROUP BY {$t['kolon']}",
                    $parca
                );
                foreach ($rows as $r) {
                    $sonuc[(int)$r['uid']][$anahtar] = (int)$r['sayi'];
                }
            }
        }
        return $sonuc;
    }

    /** Birleştirme yapılmadan ne olacağını raporlar */
    public function onizleme(int $yeniId, array $eskiIdler): array
    {
        $eskiIdler = array_values(array_unique(array_filter(array_map('intval', $eskiIdler))));
        $yeni = $this->urunGetir($yeniId);
        if (!$yeni) throw new Exception('Kalacak ürün bulunamadı.');
        if (empty($eskiIdler)) throw new Exception('Birleştirilecek en az bir ürün seçin.');
        if (in_array($yeniId, $eskiIdler, true)) throw new Exception('Kalacak ürün birleştirilecekler arasında olamaz.');

        $kullanim = $this->kullanimSayilari(array_merge([$yeniId], $eskiIdler));
        $stok = (float)$yeni['stok'];
        $eskiler = [];

        foreach ($eskiIdler as $eskiId) {
            $eski = $this->urunGetir($eskiId);
            if (!$eski) throw new Exception("#$eskiId numaralı ürün bulunamadı.");

            $uyarilar = [];
            if ((int)$eski['urun_hizmet_durum'] === 0) $uyarilar[] = 'Bu ürün zaten pasif.';
            if ((int)$eski['urun_hizmet_tip'] !== (int)$yeni['urun_hizmet_tip']) $uyarilar[] = 'Ürün tipleri farklı.';
            if ($kullanim[$eskiId]['pazaryeri'] > 0 && $kullanim[$yeniId]['pazaryeri'] > 0) {
                $uyarilar[] = 'İki ürünün de pazaryeri kaydı var; pazaryeri eşleşmeleri kalan ürüne toplanacak.';
            }
            if ($this->aktifLog($eskiId)) $uyarilar[] = 'Bu ürün daha önce birleştirilmiş.';

            $ozTransfer = (int)($this->satir("
                SELECT COUNT(*) AS sayi
                FROM dbo.Urun_Stok_Hareketleri c
                JOIN dbo.Urun_Stok_Hareketleri g ON g.Stok_Hareket_id = c.Ref_Hareket_id
                WHERE c.Durum = 1 AND c.Islem_Tipi = 'CIKIS'
                  AND ((c.Urun_id = ? AND g.Urun_id = ?) OR (c.Urun_id = ? AND g.Urun_id = ?))",
                [$eskiId, $yeniId, $yeniId, $eskiId])['sayi'] ?? 0);
            if ($ozTransfer > 0) {
                $uyarilar[] = "Bu iki ürün arasında $ozTransfer transfer var; birleştirme sonrası kaynak ve hedefi aynı ürün olacak (stok etkisi sıfır).";
            }

            $stok += (float)$eski['stok'];
            $eskiler[] = [
                'urun'     => $eski,
                'kullanim' => $kullanim[$eskiId],
                'uyarilar' => $uyarilar,
            ];
        }

        return [
            'yeni'           => $yeni,
            'yeni_kullanim'  => $kullanim[$yeniId],
            'eskiler'        => $eskiler,
            'stok_sonra'     => $stok,
            'etiketler'      => array_map(fn($t) => $t['etiket'], self::TABLOLAR),
        ];
    }

    /** Eski ürünü yeni ürüne birleştirir. Tek transaction, log id döner. */
    public function birlestir(int $yeniId, int $eskiId, int $kullaniciId, string $aciklama = ''): array
    {
        if ($yeniId <= 0 || $eskiId <= 0 || $yeniId === $eskiId) {
            throw new Exception('Geçersiz ürün seçimi.');
        }

        $this->db->beginTransaction();
        try {
            $yeni = $this->urunGetir($yeniId, true);
            $eski = $this->urunGetir($eskiId, true);
            if (!$yeni) throw new Exception('Kalacak ürün bulunamadı.');
            if (!$eski) throw new Exception('Birleştirilecek ürün bulunamadı.');
            if ($this->aktifLog($eskiId)) {
                throw new Exception('"' . $eski['urun_hizmet_kodu'] . '" daha önce birleştirilmiş. Önce o işlemi geri alın.');
            }

            $tasinan = [];
            foreach (self::TABLOLAR as $anahtar => $t) {
                $ek     = $anahtar === 'alt_urun' ? ' AND urun_hizmet_id <> ?' : '';
                $params = $anahtar === 'alt_urun' ? [$eskiId, $yeniId] : [$eskiId];

                // PazaryeriUrunleri'nde (kanal_id, urun_hizmet_id) tekildir: kalan ürünün zaten
                // eşleşmesi olan kanaldaki bağ taşınmaz (eski üründe kalır), aksi halde UPDATE reddedilir.
                if ($anahtar === 'pazaryeri') {
                    $ek     = ' AND kanal_id NOT IN (SELECT kanal_id FROM dbo.PazaryeriUrunleri WHERE urun_hizmet_id = ?)';
                    $params = [$eskiId, $yeniId];
                }

                $rows = $this->satirlar(
                    "SELECT {$t['pk']} AS id FROM dbo.{$t['tablo']} WITH (UPDLOCK, HOLDLOCK) WHERE {$t['kolon']} = ?$ek",
                    $params
                );
                if (!$rows) continue;

                $this->calistir(
                    "UPDATE dbo.{$t['tablo']} SET {$t['kolon']} = ? WHERE {$t['kolon']} = ?$ek",
                    array_merge([$yeniId], $params)
                );
                $tasinan[$anahtar] = array_map(fn($r) => (int)$r['id'], $rows);
            }

            $meta  = [];
            $simdi = date('Y-m-d H:i:s');

            // Kalan ürün, birleştirilen ürünün alt ürünüyse döngü oluşmasın
            if ((int)$yeni['urun_hizmet_ust_id'] === $eskiId) {
                $this->calistir("UPDATE dbo.Urun_Hizmet SET urun_hizmet_ust_id = NULL WHERE urun_hizmet_id = ?", [$yeniId]);
                $meta['yeni_ust_id'] = $eskiId;
            }

            $eskiStok     = (float)$eski['stok'];
            $yeniStokOnce = (float)$yeni['stok'];

            $this->calistir("
                UPDATE dbo.Urun_Hizmet
                SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) + ?,
                    urun_hizmet_guncelleme_tarihi = ?, urun_hizmet_guncelleyen_kullanici_id = ?
                WHERE urun_hizmet_id = ?", [$eskiStok, $simdi, $kullaniciId, $yeniId]);

            $this->calistir("
                UPDATE dbo.Urun_Hizmet
                SET Urun_Stok_Miktari = 0, urun_hizmet_durum = 0,
                    urun_hizmet_guncelleme_tarihi = ?, urun_hizmet_guncelleyen_kullanici_id = ?
                WHERE urun_hizmet_id = ?", [$simdi, $kullaniciId, $eskiId]);

            $logId = $this->db->insert('dbo.UrunBirlestirmeLog', [
                'UrunBirlestirmeLog_EskiUrunId'      => $eskiId,
                'UrunBirlestirmeLog_YeniUrunId'      => $yeniId,
                'UrunBirlestirmeLog_EskiKod'         => $eski['urun_hizmet_kodu'],
                'UrunBirlestirmeLog_EskiAd'          => $eski['urun_hizmet_adi'],
                'UrunBirlestirmeLog_YeniKod'         => $yeni['urun_hizmet_kodu'],
                'UrunBirlestirmeLog_YeniAd'          => $yeni['urun_hizmet_adi'],
                'UrunBirlestirmeLog_EskiStok'        => $eskiStok,
                'UrunBirlestirmeLog_YeniStokOnce'    => $yeniStokOnce,
                'UrunBirlestirmeLog_YeniStokSonra'   => $yeniStokOnce + $eskiStok,
                'UrunBirlestirmeLog_EskiDurum'       => (int)$eski['urun_hizmet_durum'],
                'UrunBirlestirmeLog_TasinanKayitlar' => json_encode(['tablolar' => $tasinan, 'meta' => $meta]),
                'UrunBirlestirmeLog_Aciklama'        => $aciklama !== '' ? mb_substr($aciklama, 0, 500) : null,
                'UrunBirlestirmeLog_GeriAlindi'      => 0,
                'OlusturanKullanici'                 => $kullaniciId,
                'OlusturmaTarihi'                    => $simdi,
                'Durum'                              => 1,
            ]);

            $this->db->commit();

            return [
                'log_id'     => (int)$logId,
                'tasinan'    => array_map('count', $tasinan),
                'stok_sonra' => $yeniStokOnce + $eskiStok,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Birleştirmeyi tersine çevirir */
    public function geriAl(int $logId, int $kullaniciId): void
    {
        $this->db->beginTransaction();
        try {
            $log = $this->satir("SELECT * FROM dbo.UrunBirlestirmeLog WITH (UPDLOCK, HOLDLOCK) WHERE UrunBirlestirmeLog_id = ? AND Durum = 1", [$logId]);
            if (!$log) throw new Exception('Log kaydı bulunamadı.');
            if ((int)$log['UrunBirlestirmeLog_GeriAlindi'] === 1) throw new Exception('Bu birleştirme zaten geri alınmış.');

            $eskiId = (int)$log['UrunBirlestirmeLog_EskiUrunId'];
            $yeniId = (int)$log['UrunBirlestirmeLog_YeniUrunId'];

            $sonraki = $this->satir("
                SELECT TOP 1 UrunBirlestirmeLog_id, UrunBirlestirmeLog_EskiKod, UrunBirlestirmeLog_YeniKod
                FROM dbo.UrunBirlestirmeLog
                WHERE Durum = 1 AND UrunBirlestirmeLog_GeriAlindi = 0 AND UrunBirlestirmeLog_id > ?
                  AND (UrunBirlestirmeLog_EskiUrunId IN (?, ?) OR UrunBirlestirmeLog_YeniUrunId = ?)
                ORDER BY UrunBirlestirmeLog_id DESC", [$logId, $eskiId, $yeniId, $eskiId]);
            if ($sonraki) {
                throw new Exception('Bu ürünleri etkileyen daha sonraki bir birleştirme var ('
                    . $sonraki['UrunBirlestirmeLog_EskiKod'] . ' → ' . $sonraki['UrunBirlestirmeLog_YeniKod'] . '). Önce onu geri alın.');
            }

            if (!$this->urunGetir($eskiId, true) || !$this->urunGetir($yeniId, true)) {
                throw new Exception('Ürünlerden biri silinmiş, geri alma yapılamaz.');
            }

            $veri = json_decode($log['UrunBirlestirmeLog_TasinanKayitlar'] ?? '', true) ?: [];
            foreach (($veri['tablolar'] ?? []) as $anahtar => $idler) {
                if (!isset(self::TABLOLAR[$anahtar])) continue;
                $t = self::TABLOLAR[$anahtar];
                foreach (array_chunk(array_map('intval', $idler), self::PARCA) as $parca) {
                    $yer = implode(',', array_fill(0, count($parca), '?'));
                    $this->calistir(
                        "UPDATE dbo.{$t['tablo']} SET {$t['kolon']} = ? WHERE {$t['kolon']} = ? AND {$t['pk']} IN ($yer)",
                        array_merge([$eskiId, $yeniId], $parca)
                    );
                }
            }

            if (!empty($veri['meta']['yeni_ust_id'])) {
                $this->calistir(
                    "UPDATE dbo.Urun_Hizmet SET urun_hizmet_ust_id = ? WHERE urun_hizmet_id = ? AND urun_hizmet_ust_id IS NULL",
                    [(int)$veri['meta']['yeni_ust_id'], $yeniId]
                );
            }

            $simdi    = date('Y-m-d H:i:s');
            $eskiStok = (float)$log['UrunBirlestirmeLog_EskiStok'];

            $this->calistir("
                UPDATE dbo.Urun_Hizmet
                SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) - ?,
                    urun_hizmet_guncelleme_tarihi = ?, urun_hizmet_guncelleyen_kullanici_id = ?
                WHERE urun_hizmet_id = ?", [$eskiStok, $simdi, $kullaniciId, $yeniId]);

            $this->calistir("
                UPDATE dbo.Urun_Hizmet
                SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) + ?, urun_hizmet_durum = ?,
                    urun_hizmet_guncelleme_tarihi = ?, urun_hizmet_guncelleyen_kullanici_id = ?
                WHERE urun_hizmet_id = ?", [$eskiStok, (int)$log['UrunBirlestirmeLog_EskiDurum'], $simdi, $kullaniciId, $eskiId]);

            $this->calistir("
                UPDATE dbo.UrunBirlestirmeLog
                SET UrunBirlestirmeLog_GeriAlindi = 1, UrunBirlestirmeLog_GeriAlanKullanici = ?, UrunBirlestirmeLog_GeriAlmaTarihi = ?,
                    GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                WHERE UrunBirlestirmeLog_id = ?", [$kullaniciId, $simdi, $kullaniciId, $simdi, $logId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function aktifLog(int $eskiId): bool
    {
        return (bool)$this->satir(
            "SELECT TOP 1 1 AS var FROM dbo.UrunBirlestirmeLog WHERE Durum = 1 AND UrunBirlestirmeLog_GeriAlindi = 0 AND UrunBirlestirmeLog_EskiUrunId = ?",
            [$eskiId]
        );
    }

    // Database::fetch*/execute hata durumunda sessizce false/[] döndüğü için transaction içinde hata fırlatan sarmalayıcılar

    private function sorgu(string $sql, array $params)
    {
        $stmt = sqlsrv_query($this->db->getConnection(), $sql, $params);
        if ($stmt === false) {
            $err = sqlsrv_errors(SQLSRV_ERR_ERRORS);
            error_log('UrunBirlestirmeHelper SQL Hatası: ' . print_r($err, true));
            throw new Exception('Veritabanı hatası: ' . ($err[0]['message'] ?? 'Bilinmeyen hata'));
        }
        return $stmt;
    }

    private function satirlar(string $sql, array $params = []): array
    {
        $stmt = $this->sorgu($sql, $params);
        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach ($r as $k => $v) {
                if (is_resource($v)) $r[$k] = stream_get_contents($v);
                elseif ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d H:i:s');
            }
            $rows[] = $r;
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    private function satir(string $sql, array $params = []): ?array
    {
        return $this->satirlar($sql, $params)[0] ?? null;
    }

    private function calistir(string $sql, array $params = []): void
    {
        sqlsrv_free_stmt($this->sorgu($sql, $params));
    }
}
