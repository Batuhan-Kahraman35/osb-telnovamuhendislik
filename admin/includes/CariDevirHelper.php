<?php
/**
 * Cari Devir Helper
 *
 * Bir cari başka bir personele devredildiğinde (Cari.cari_devredilen_kullanici),
 * devralan kullanıcı o cariye ait geçmiş kayıtları (teklif, fatura, ödeme,
 * konsinye, görüşme notu) kendisi oluşturmamış olsa bile görebilmelidir.
 *
 * Geçmiş kayıtların hazırlayan/oluşturan alanları DEĞİŞTİRİLMEZ; personel bazlı
 * raporların geçmişi bozulmaz. Görünürlük yalnızca cari üzerinden açılır.
 *
 * Kullanım (sayfalarda):
 *   if (!empty($pagePermissions['can_view_own_records'])) {
 *       $baseWhere .= " AND (" . CariDevirHelper::orDevir(
 *           "t.Teklifler_hazirlayan_id = ?", $user['kullanici_id'], 't.Teklifler_cari_id'
 *       ) . ")";
 *       $baseParams[] = $user['kullanici_id'];
 *   }
 */

class CariDevirHelper
{
    /** Devir yapabilen departmanlar: 1 = Administrator, 22 = Satış Müdürü */
    private const DEVIR_DEPARTMANLARI = [1, 22];

    /** @var array<int, int[]> Kullanıcı bazlı cari id önbelleği (istek ömrü boyunca) */
    private static array $cache = [];

    /**
     * Kullanıcı cari devri yapabilir mi?
     * Devir yetkisi sınırlı tutulur; aksi halde personel kendi üzerine cari alabilir.
     *
     * @param int|null $departmanId
     * @return bool
     */
    public static function devirYetkisiVarMi(?int $departmanId): bool
    {
        return in_array((int)$departmanId, self::DEVIR_DEPARTMANLARI, true);
    }

    /**
     * Kullanıcıya devredilmiş carilerin id listesi.
     *
     * @param int $kullaniciId
     * @return int[]
     */
    public static function getCariIdler(int $kullaniciId): array
    {
        if ($kullaniciId <= 0) {
            return [];
        }
        if (isset(self::$cache[$kullaniciId])) {
            return self::$cache[$kullaniciId];
        }

        $db   = Database::getInstance();
        $rows = $db->fetchAll("
            SELECT cari_id
            FROM dbo.Cari
            WHERE cari_devredilen_kullanici = ?
        ", [$kullaniciId]);

        $ids = array_map('intval', array_column($rows, 'cari_id'));

        return self::$cache[$kullaniciId] = $ids;
    }

    /**
     * Kullanıcıya devredilmiş carilerin vergi no listesi.
     * Cari eşleşmesi VKN üzerinden yapılan sorgular için (giden e-faturalar).
     *
     * @param int $kullaniciId
     * @return string[]
     */
    public static function getVergiNolar(int $kullaniciId): array
    {
        if ($kullaniciId <= 0) {
            return [];
        }

        $db   = Database::getInstance();
        $rows = $db->fetchAll("
            SELECT cari_vergi_no
            FROM dbo.Cari
            WHERE cari_devredilen_kullanici = ?
              AND ISNULL(cari_vergi_no, '') <> ''
        ", [$kullaniciId]);

        return array_values(array_unique(array_column($rows, 'cari_vergi_no')));
    }

    /**
     * Verilen sahiplik koşuluna, devredilen cariler için OR koşulu ekler.
     * Devredilmiş cari yoksa koşul olduğu gibi döner.
     *
     * Cari id listesi tamsayıya çevrildiği için doğrudan SQL'e gömülür;
     * çağıran tarafın parametre sırası bozulmaz.
     *
     * @param string $sahiplikKosulu  örn: "t.Teklifler_hazirlayan_id = ?"
     * @param int    $kullaniciId
     * @param string $cariIdKolonu    örn: "t.Teklifler_cari_id"
     * @return string  Parantezsiz koşul metni
     */
    public static function orDevir(string $sahiplikKosulu, int $kullaniciId, string $cariIdKolonu): string
    {
        $ids = self::getCariIdler($kullaniciId);
        if (empty($ids)) {
            return $sahiplikKosulu;
        }

        return $sahiplikKosulu . " OR {$cariIdKolonu} IN (" . implode(',', $ids) . ")";
    }

    /**
     * VKN üzerinden eşleşen sorgular için OR koşulu ekler.
     *
     * @param string $sahiplikKosulu
     * @param int    $kullaniciId
     * @param string $vknKolonu   örn: "f.FaturalarGiden_VKN_TCKN"
     * @return string
     */
    public static function orDevirVkn(string $sahiplikKosulu, int $kullaniciId, string $vknKolonu): string
    {
        $vknler = self::getVergiNolar($kullaniciId);
        if (empty($vknler)) {
            return $sahiplikKosulu;
        }

        $liste = implode(',', array_map(function ($vkn) {
            return "N'" . str_replace("'", "''", $vkn) . "'";
        }, $vknler));

        return $sahiplikKosulu . " OR {$vknKolonu} IN ({$liste})";
    }

    /**
     * Devir bilgisini kaydeder. Devredilen NULL verilirse devir kaldırılır.
     *
     * @param int      $cariId
     * @param int|null $devredilenKullaniciId
     * @param int      $islemYapanId
     * @return void
     */
    public static function devret(int $cariId, ?int $devredilenKullaniciId, int $islemYapanId): void
    {
        $db = Database::getInstance();

        if ($devredilenKullaniciId === null || $devredilenKullaniciId <= 0) {
            $db->execute("
                UPDATE dbo.Cari
                SET cari_devredilen_kullanici  = NULL,
                    cari_devreden_kullanici    = NULL,
                    cari_devir_tarihi          = NULL,
                    cari_guncelleyen_kullanici = ?,
                    cari_guncelleme_tarihi     = GETDATE()
                WHERE cari_id = ?
            ", [$islemYapanId, $cariId]);
            return;
        }

        $mevcut = $db->fetchOne("
            SELECT cari_olusturan_kullanici, cari_devredilen_kullanici
            FROM dbo.Cari WHERE cari_id = ?
        ", [$cariId]);

        if (!$mevcut || (int)($mevcut['cari_devredilen_kullanici'] ?? 0) === $devredilenKullaniciId) {
            return; // Değişiklik yok
        }

        // Devreden: varsa önceki devralan, yoksa cariyi oluşturan personel
        $devreden = (int)($mevcut['cari_devredilen_kullanici'] ?? 0)
            ?: (int)($mevcut['cari_olusturan_kullanici'] ?? 0);

        $db->execute("
            UPDATE dbo.Cari
            SET cari_devredilen_kullanici  = ?,
                cari_devreden_kullanici    = ?,
                cari_devir_tarihi          = GETDATE(),
                cari_guncelleyen_kullanici = ?,
                cari_guncelleme_tarihi     = GETDATE()
            WHERE cari_id = ?
        ", [$devredilenKullaniciId, $devreden ?: null, $islemYapanId, $cariId]);
    }
}
