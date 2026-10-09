<?php
/**
 * Bölge Yetki Helper
 *
 * KullaniciBolgeYetkisi tablosuna göre kullanıcının yetkili olduğu
 * ülke, şehir ve ilçe ID'lerini döndürür.
 *
 * Kullanım (sayfalarda):
 *   $bolgeWhere = BolgeYetkiHelper::getSqlWhere($userId, $pagePermissions, 'c.sehir_id', 'c.ilce_id');
 *   $sql = "SELECT ... FROM Cariler c WHERE c.Durum = 1 $bolgeWhere";
 */

class BolgeYetkiHelper
{
    /**
     * Kullanıcının yetkili şehir ID'lerini döndürür.
     * Admin veya bolge_gor kapalıysa boş array döner (filtre uygulanmaz).
     *
     * @param int   $kullaniciId
     * @param bool  $isAdmin        PageAuth::is_admin
     * @param bool  $bolgeGor       PageAuth::bolge_gor
     * @return int[]
     */
    public static function getSehirIds(int $kullaniciId, bool $isAdmin, bool $bolgeGor): array
    {
        if ($isAdmin || !$bolgeGor) {
            return [];
        }

        $db = Database::getInstance();
        $rows = $db->fetchAll("
            SELECT DISTINCT KullaniciBolgeYetkisi_SehirId
            FROM dbo.KullaniciBolgeYetkisi
            WHERE KullaniciBolgeYetkisi_KullaniciId = ?
              AND Durum = 1
        ", [$kullaniciId]);

        return array_column($rows, 'KullaniciBolgeYetkisi_SehirId');
    }

    /**
     * Kullanıcının yetkili ilçe ID'lerini döndürür (NULL olanlar tüm ilçelere erişim anlamına gelir).
     *
     * @param int   $kullaniciId
     * @param bool  $isAdmin
     * @param bool  $bolgeGor
     * @return int[]  Boş array → filtre uygulanmaz (tüm ilçeler)
     */
    public static function getIlceIds(int $kullaniciId, bool $isAdmin, bool $bolgeGor): array
    {
        if ($isAdmin || !$bolgeGor) {
            return [];
        }

        $db = Database::getInstance();
        $rows = $db->fetchAll("
            SELECT DISTINCT KullaniciBolgeYetkisi_IlceId
            FROM dbo.KullaniciBolgeYetkisi
            WHERE KullaniciBolgeYetkisi_KullaniciId = ?
              AND Durum = 1
              AND KullaniciBolgeYetkisi_IlceId IS NOT NULL
        ", [$kullaniciId]);

        return array_column($rows, 'KullaniciBolgeYetkisi_IlceId');
    }

    /**
     * Sayfadaki ana SQL sorgusuna eklenecek WHERE koşulunu döndürür.
     * Her bölge satırı (şehir + ilçe + mahalle) ayrı bir koşul olarak OR ile birleştirilir.
     *
     * @param int         $kullaniciId
     * @param array       $pagePermissions   PageAuth::checkPagePermissions() çıktısı
     * @param string      $sehirKolonu       SQL'deki şehir kolonu  (örn: "c.cari_sehirler")
     * @param string|null $ilceKolonu        SQL'deki ilçe kolonu   (örn: "c.cari_ilceler")
     * @param string|null $adresKolonu       SQL'deki adres kolonu  (örn: "c.cari_adres") — mahalle LIKE filtresi için
     * @return string  Boş string ya da "AND (...)" şeklinde WHERE parçası
     */
    public static function getSqlWhere(
        int $kullaniciId,
        array $pagePermissions,
        string $sehirKolonu,
        ?string $ilceKolonu  = null,
        ?string $adresKolonu = null
    ): string {
        $isAdmin  = $pagePermissions['is_admin'] ?? false;
        $bolgeGor = $pagePermissions['bolge_gor'] ?? false;

        if ($isAdmin || !$bolgeGor) {
            return '';
        }

        $db   = Database::getInstance();
        $rows = $db->fetchAll("
            SELECT
                KullaniciBolgeYetkisi_SehirId,
                KullaniciBolgeYetkisi_IlceId,
                KullaniciBolgeYetkisi_Mahalle
            FROM dbo.KullaniciBolgeYetkisi
            WHERE KullaniciBolgeYetkisi_KullaniciId = ?
              AND Durum = 1
        ", [$kullaniciId]);

        if (empty($rows)) {
            return 'AND 1 = 0';
        }

        $conditions = [];
        foreach ($rows as $row) {
            $parts = [];

            // Şehir
            $parts[] = "{$sehirKolonu} = " . intval($row['KullaniciBolgeYetkisi_SehirId']);

            // İlçe (NULL ise tüm ilçeler)
            if ($ilceKolonu !== null && !empty($row['KullaniciBolgeYetkisi_IlceId'])) {
                $parts[] = "{$ilceKolonu} = " . intval($row['KullaniciBolgeYetkisi_IlceId']);
            }

            // Mahalle (NULL ise filtre uygulanmaz)
            if ($adresKolonu !== null && !empty($row['KullaniciBolgeYetkisi_Mahalle'])) {
                $mahalle = self::turkishUpper(trim($row['KullaniciBolgeYetkisi_Mahalle']));
                $mahalle = str_replace("'", "''", $mahalle); // SQL escape
                $parts[] = "UPPER({$adresKolonu}) LIKE N'%{$mahalle}%'";
            }

            $conditions[] = '(' . implode(' AND ', $parts) . ')';
        }

        return 'AND (' . implode(' OR ', $conditions) . ')';
    }

    /**
     * Türkçe karakterleri doğru şekilde büyük harfe çevirir.
     */
    private static function turkishUpper(string $str): string
    {
        $str = strtr($str, ['i' => 'İ', 'ı' => 'I']);
        return mb_strtoupper($str, 'UTF-8');
    }

    /**
     * Kullanıcının herhangi bir bölge yetkisi tanımlı mı?
     *
     * @param int $kullaniciId
     * @return bool
     */
    public static function hasAnyYetki(int $kullaniciId): bool
    {
        $db = Database::getInstance();
        $row = $db->fetchOne("
            SELECT TOP 1 KullaniciBolgeYetkisi_id
            FROM dbo.KullaniciBolgeYetkisi
            WHERE KullaniciBolgeYetkisi_KullaniciId = ?
              AND Durum = 1
        ", [$kullaniciId]);

        return $row !== null && $row !== false;
    }
}
