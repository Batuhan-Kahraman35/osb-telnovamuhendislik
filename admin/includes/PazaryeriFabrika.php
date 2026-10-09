<?php
/**
 * Pazaryeri API Fabrikası
 *
 * Kaynak: TamRenk ortak sistemi. OrnekFirma uyarlaması: Entegrasyon / EntegrasyonKanallari
 * (Kanal_*) kolonları; firma katmanı yok (sahiplik kanal bazlı).
 *
 * Hangi pazaryeri için hangi sınıfın kullanılacağı KODA GÖMÜLMEZ;
 * tanim_Pazaryerleri.Pazaryerleri_SinifAdi kolonundan okunur.
 * Yeni bir pazaryeri eklemek = 1 PHP sınıfı + 1 veritabanı satırı.
 *
 * Kullanım:
 *   $api = PazaryeriFabrika::olustur('trendyol', $userId);
 *   $api = PazaryeriFabrika::olusturKanaldan($kanalId, $userId);
 *   if (!$api) { ... PazaryeriFabrika::sonHata() ... }
 */

require_once __DIR__ . '/PazaryeriApi.php';

class PazaryeriFabrika
{
    /** Sınıf dosyalarının bulunduğu dizin */
    const SINIF_DIZINI = __DIR__;

    private static $sonHata = '';

    /** Aynı istek içinde tekrar tekrar nesne üretilmesin */
    private static $onbellek = [];

    /**
     * Pazaryeri koduna göre API nesnesi üret.
     *
     * @param string   $pazaryeriKod tanim_Pazaryerleri.Pazaryerleri_Kod
     * @param int|null $userId       işlemi yapan kullanıcı
     * @param int|null $kanalId      belirli bir hesap; null ise varsayılan kanal
     * @return PazaryeriApi|null     üretilemezse null (sebep: sonHata())
     */
    public static function olustur($pazaryeriKod, $userId = null, $kanalId = null)
    {
        self::$sonHata = '';

        $pazaryeriKod = trim((string)$pazaryeriKod);
        if ($pazaryeriKod === '') {
            self::$sonHata = 'Pazaryeri kodu boş.';
            return null;
        }

        $anahtar = $pazaryeriKod . '|' . (int)$userId . '|' . (int)$kanalId;
        if (isset(self::$onbellek[$anahtar])) {
            return self::$onbellek[$anahtar];
        }

        $db = Database::getInstance();

        $row = $db->fetchOne("
            SELECT Pazaryerleri_id, Pazaryerleri_Ad, Pazaryerleri_SinifAdi, Durum
            FROM dbo.tanim_Pazaryerleri
            WHERE Pazaryerleri_Kod = ?
        ", [$pazaryeriKod]);

        if (!$row) {
            self::$sonHata = 'Pazaryeri tanımlı değil: ' . $pazaryeriKod;
            return null;
        }

        $sinifAdi = trim((string)$row['Pazaryerleri_SinifAdi']);
        if ($sinifAdi === '') {
            self::$sonHata = $row['Pazaryerleri_Ad'] . ' için sınıf adı tanımlanmamış '
                           . '(tanim_Pazaryerleri.Pazaryerleri_SinifAdi).';
            return null;
        }

        // Sınıf adı veritabanından geldiği için dosya yolu doğrulanır:
        // yalnızca harf ve rakam kabul edilir, dizin dışına çıkılamaz.
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $sinifAdi)) {
            self::$sonHata = 'Geçersiz sınıf adı: ' . $sinifAdi;
            return null;
        }

        // Pasiflik kontrolünden ÖNCE sınıf dosyası aranır: entegrasyonu yazılmamış
        // bir pazaryerini aktif etmek işe yaramaz, kullanıcı bunu tek seferde bilmeli.
        $sinifDosyasi = self::SINIF_DIZINI . DIRECTORY_SEPARATOR . $sinifAdi . '.php';
        if (!class_exists($sinifAdi, false) && !is_file($sinifDosyasi)) {
            self::$sonHata = $row['Pazaryerleri_Ad'] . ' entegrasyonu henüz yazılmamış '
                           . '(' . $sinifAdi . '.php bulunamadı).';
            return null;
        }

        if ((int)$row['Durum'] !== 1) {
            self::$sonHata = $row['Pazaryerleri_Ad'] . ' pasif durumda.';
            return null;
        }

        if (!class_exists($sinifAdi, false)) {
            require_once $sinifDosyasi;

            if (!class_exists($sinifAdi, false)) {
                self::$sonHata = $sinifAdi . '.php dosyası var ancak içinde '
                               . $sinifAdi . ' sınıfı tanımlı değil.';
                return null;
            }
        }

        if (!is_subclass_of($sinifAdi, 'PazaryeriApi')) {
            self::$sonHata = $sinifAdi . ' sınıfı PazaryeriApi sınıfından türetilmemiş.';
            return null;
        }

        try {
            $api = new $sinifAdi($userId, $kanalId);
        } catch (Throwable $e) {
            self::$sonHata = $sinifAdi . ' oluşturulamadı: ' . $e->getMessage();
            error_log('PazaryeriFabrika: ' . $e->getMessage());
            return null;
        }

        if (!$api->hazirMi()) {
            // Nesne üretildi ama yapılandırma eksik; çağıran getHata() ile ayrıntıya ulaşır.
            self::$sonHata = $api->getHata();
        }

        return self::$onbellek[$anahtar] = $api;
    }

    /**
     * Kanal id'sinden API nesnesi üret.
     * Kanalın hangi pazaryerine ait olduğu entegrasyon kodundan bulunur.
     */
    public static function olusturKanaldan($kanalId, $userId = null)
    {
        self::$sonHata = '';
        $kanalId = (int)$kanalId;

        if ($kanalId <= 0) {
            self::$sonHata = 'Kanal seçilmedi.';
            return null;
        }

        $row = Database::getInstance()->fetchOne("
            SELECT p.Pazaryerleri_Kod
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyon e
                ON e.Entegrasyon_Id = k.Kanal_Entegrasyon_Id
            INNER JOIN dbo.tanim_Pazaryerleri p
                ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyon_Kod
            WHERE k.Kanal_Id = ?
        ", [$kanalId]);

        if (!$row) {
            self::$sonHata = 'Kanal bulunamadı veya bir pazaryerine bağlı değil (kanal ' . $kanalId . ').';
            return null;
        }

        return self::olustur($row['Pazaryerleri_Kod'], $userId, $kanalId);
    }

    /** Son başarısızlığın sebebi */
    public static function sonHata()
    {
        return self::$sonHata;
    }

    /**
     * Arayüzde listelenecek pazaryerleri.
     * Sınıf dosyası var mı, kanalı yapılandırılmış mı bilgisi de döner.
     *
     * @param bool $yalnizAktif yalnızca Durum = 1 olanlar
     */
    public static function pazaryerleri($yalnizAktif = true)
    {
        $sql = "
            SELECT
                p.Pazaryerleri_id,
                p.Pazaryerleri_Kod,
                p.Pazaryerleri_Ad,
                p.Pazaryerleri_Renk,
                p.Pazaryerleri_Ikon,
                p.Pazaryerleri_Sira,
                p.Pazaryerleri_SinifAdi,
                p.Pazaryerleri_Yetenekler,
                p.Durum,
                k.kanal_id,
                k.kanal_adi
            FROM dbo.tanim_Pazaryerleri p
            OUTER APPLY (
                SELECT TOP 1 k2.Kanal_Id AS kanal_id, k2.Kanal_Adi AS kanal_adi
                FROM dbo.Entegrasyon e2
                INNER JOIN dbo.EntegrasyonKanallari k2
                    ON k2.Kanal_Entegrasyon_Id = e2.Entegrasyon_Id
                   AND k2.Kanal_Durum = 1
                WHERE e2.Entegrasyon_Kod = p.Pazaryerleri_EntegrasyonKod
                  AND e2.Entegrasyon_Durum = 1
                ORDER BY k2.Kanal_Varsayilan DESC, k2.Kanal_Id
            ) k
        ";

        if ($yalnizAktif) {
            $sql .= " WHERE p.Durum = 1 ";
        }

        $sql .= " ORDER BY p.Pazaryerleri_Sira, p.Pazaryerleri_Ad";

        $liste = Database::getInstance()->fetchAll($sql);

        foreach ($liste as &$p) {
            $sinif = trim((string)$p['Pazaryerleri_SinifAdi']);
            $p['sinifVar']       = $sinif !== ''
                                && is_file(self::SINIF_DIZINI . DIRECTORY_SEPARATOR . $sinif . '.php');
            $p['kanalVar']       = !empty($p['kanal_id']);
            $p['kullanilabilir'] = $p['sinifVar'] && $p['kanalVar'] && (int)$p['Durum'] === 1;
            $p['yetenekler']     = json_decode($p['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: [];

            if (!$p['sinifVar']) {
                $p['durumMetni'] = 'Entegrasyon yazılmadı';
            } elseif (!$p['kanalVar']) {
                $p['durumMetni'] = 'Kimlik bilgisi girilmedi';
            } elseif ((int)$p['Durum'] !== 1) {
                $p['durumMetni'] = 'Pasif';
            } else {
                $p['durumMetni'] = 'Hazır';
            }
        }
        unset($p);

        return $liste;
    }

    /**
     * Aktif pazaryerlerine ait tüm aktif kanallar (sayfa filtreleri ve cron bunu kullanır).
     * Sayfalara pazaryeri listesi GÖMÜLMEZ; bu fonksiyon tanım tablosundan üretir.
     */
    public static function kanallar($pazaryeriKod = null)
    {
        $params = [];

        $sql = "
            SELECT
                k.Kanal_Id        AS kanal_id,
                k.Kanal_Adi       AS kanal_adi,
                k.Kanal_Varsayilan AS kanal_varsayilan,
                p.Pazaryerleri_id,
                p.Pazaryerleri_Kod,
                p.Pazaryerleri_Ad,
                p.Pazaryerleri_Renk,
                p.Pazaryerleri_Ikon,
                p.Pazaryerleri_SinifAdi,
                p.Pazaryerleri_Yetenekler,
                p.Durum AS Pazaryerleri_Durum
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyon e
                ON e.Entegrasyon_Id = k.Kanal_Entegrasyon_Id
               AND e.Entegrasyon_Durum = 1
            INNER JOIN dbo.tanim_Pazaryerleri p
                ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyon_Kod
               AND p.Durum = 1
            WHERE k.Kanal_Durum = 1
        ";

        if ($pazaryeriKod !== null) {
            $sql .= " AND p.Pazaryerleri_Kod = ? ";
            $params[] = $pazaryeriKod;
        }

        $sql .= " ORDER BY p.Pazaryerleri_Sira, k.Kanal_Varsayilan DESC, k.Kanal_Adi";

        $liste = Database::getInstance()->fetchAll($sql, $params);

        // Arayüz hangi aksiyonu göstereceğine yetenek bayraklarına bakarak karar verir.
        foreach ($liste as &$k) {
            $k['yetenekler'] = json_decode($k['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: [];
            $k['sinifVar']   = !empty($k['Pazaryerleri_SinifAdi'])
                            && is_file(self::SINIF_DIZINI . DIRECTORY_SEPARATOR
                                       . $k['Pazaryerleri_SinifAdi'] . '.php');
        }
        unset($k);

        return $liste;
    }

    /** Bir pazaryerinin beklediği kimlik alanları (sınıf yoksa boş dizi) */
    public static function kimlikAlanlari($pazaryeriKod)
    {
        $row = Database::getInstance()->fetchOne("
            SELECT Pazaryerleri_SinifAdi FROM dbo.tanim_Pazaryerleri
            WHERE Pazaryerleri_Kod = ?
        ", [$pazaryeriKod]);

        $sinifAdi = trim((string)($row['Pazaryerleri_SinifAdi'] ?? ''));
        if ($sinifAdi === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $sinifAdi)) {
            return [];
        }

        if (!class_exists($sinifAdi, false)) {
            $dosya = self::SINIF_DIZINI . DIRECTORY_SEPARATOR . $sinifAdi . '.php';
            if (!is_file($dosya)) {
                return [];
            }
            require_once $dosya;
        }

        if (!is_subclass_of($sinifAdi, 'PazaryeriApi') || !method_exists($sinifAdi, 'kimlikAlanlari')) {
            return [];
        }

        return (array)$sinifAdi::kimlikAlanlari();
    }

    /** Test ve cron akışlarında önbelleği temizlemek için */
    public static function onbellegiTemizle()
    {
        self::$onbellek = [];
    }
}
