<?php
/**
 * Giden fatura tutarlarinin TL karsiligi icin ortak SQL parcalari.
 *
 * FaturalarGiden_Tutar faturanin KENDI para biriminde tutulur. Tutar kur ile
 * carpilmadan TL gibi gosterilirse bakiye onlarca kat dusuk cikar
 * (ornek: 237,60 USD ekranda 237,60 TL, gercegi 10.707,71 TL).
 *
 * Bu sabitler odeme-yonetimi.php ve odeme-form.php tarafindan PAYLASILIR.
 * Daha once hesap yalnizca odeme-yonetimi.php icinde tanimliydi ve odeme-form
 * ham tutari gosteriyordu; ayni hesabi iki yerde tutmak sapmaya yol acti.
 *
 * Sorguda ZORUNLU alias'lar: FaturalarGiden -> fg, FG_KUR_APPLY -> kur
 *
 * Kur kaynak sirasi:
 *   1) Faturanin kendi kuru (FaturalarGiden_DolarKur / _EuroKur)
 *      Not: GIB senkronu bu kolonlari yazmiyor, pratikte hep NULL'dur.
 *      Yalnizca panel icinden kesilen faturalarda dolabilir.
 *   2) Faturaya ait CIKIS stok hareketindeki kur (fiilen calisan kaynak)
 *   3) Hicbiri yoksa 1 -> tutar TL kabul edilir ve FG_KURSUZ ile isaretlenir.
 *
 * Ucuncu bir kur kaynagi yoktur: tanim_ParaBirimi tablosunda kur kolonu
 * bulunmaz ve senkron GetInvoice cagrisini HEADER_ONLY = Y ile yaptigi icin
 * GIB'den de kur donmez (PAYABLE_AMOUNT sadece tutar + currencyID verir).
 */

/** Fatura tutarinin TL karsiligi. */
const FG_TL_TUTAR = "fg.FaturalarGiden_Tutar * CASE UPPER(ISNULL(fg.FaturalarGiden_ParaBirimi, 'TRY'))
        WHEN 'USD' THEN ISNULL(NULLIF(fg.FaturalarGiden_DolarKur, 0), ISNULL(NULLIF(kur.DolarKur, 0), 1))
        WHEN 'EUR' THEN ISNULL(NULLIF(fg.FaturalarGiden_EuroKur,  0), ISNULL(NULLIF(kur.EuroKur,  0), 1))
        ELSE 1 END";

/** Hesaplamada kullanilan kur (1 = kur bulunamadi / TL fatura). Ekranda gostermek icin. */
const FG_KUR = "CASE UPPER(ISNULL(fg.FaturalarGiden_ParaBirimi, 'TRY'))
        WHEN 'USD' THEN ISNULL(NULLIF(fg.FaturalarGiden_DolarKur, 0), ISNULL(NULLIF(kur.DolarKur, 0), 1))
        WHEN 'EUR' THEN ISNULL(NULLIF(fg.FaturalarGiden_EuroKur,  0), ISNULL(NULLIF(kur.EuroKur,  0), 1))
        ELSE 1 END";

/** Kuru cozulemeyen doviz faturasi mi? (uyari rozeti icin) */
const FG_KURSUZ = "CASE WHEN UPPER(ISNULL(fg.FaturalarGiden_ParaBirimi, 'TRY')) IN ('USD', 'EUR')
        AND ISNULL(NULLIF(fg.FaturalarGiden_DolarKur, 0), NULLIF(kur.DolarKur, 0)) IS NULL
        AND ISNULL(NULLIF(fg.FaturalarGiden_EuroKur,  0), NULLIF(kur.EuroKur,  0)) IS NULL
    THEN 1 ELSE 0 END";

/** Fatura kurunun yedek kaynagi: cikis stok hareketine yazilan kur. */
const FG_KUR_APPLY = "OUTER APPLY (
        SELECT TOP 1 DolarKur, EuroKur
        FROM dbo.Urun_Stok_Hareketleri
        WHERE Fatura_id = fg.FaturalarGiden_id AND Islem_Tipi = 'CIKIS' AND Durum = 1
        ORDER BY Stok_Hareket_id DESC
    ) kur";

/* --------------------------------------------------------------------------
 * GELEN FATURALAR
 *
 * FaturalarGelen tablosunda kur kolonu YOKTUR; tek kaynak faturaya ait GIRIS
 * stok hareketidir (bkz. gelen-efaturalar.php). Cari baglantisi da id ile
 * degil VKN uzerinden kurulur: FaturalarGelen_VKN_TCKN = Cari.cari_vergi_no
 *
 * Sorguda ZORUNLU alias'lar: FaturalarGelen -> fge, FGE_KUR_APPLY -> kurg
 * -------------------------------------------------------------------------- */

/** Gelen fatura tutarinin TL karsiligi. */
const FGE_TL_TUTAR = "fge.FaturalarGelen_Tutar * CASE UPPER(ISNULL(fge.FaturalarGelen_ParaBirimi, 'TRY'))
        WHEN 'USD' THEN ISNULL(NULLIF(kurg.DolarKur, 0), 1)
        WHEN 'EUR' THEN ISNULL(NULLIF(kurg.EuroKur,  0), 1)
        ELSE 1 END";

/** Hesaplamada kullanilan kur (1 = kur bulunamadi / TL fatura). */
const FGE_KUR = "CASE UPPER(ISNULL(fge.FaturalarGelen_ParaBirimi, 'TRY'))
        WHEN 'USD' THEN ISNULL(NULLIF(kurg.DolarKur, 0), 1)
        WHEN 'EUR' THEN ISNULL(NULLIF(kurg.EuroKur,  0), 1)
        ELSE 1 END";

/** Kuru cozulemeyen doviz gelen faturasi mi? (uyari rozeti icin) */
const FGE_KURSUZ = "CASE WHEN UPPER(ISNULL(fge.FaturalarGelen_ParaBirimi, 'TRY')) IN ('USD', 'EUR')
        AND NULLIF(kurg.DolarKur, 0) IS NULL
        AND NULLIF(kurg.EuroKur,  0) IS NULL
    THEN 1 ELSE 0 END";

/** Gelen fatura kurunun tek kaynagi: giris stok hareketine yazilan kur. */
const FGE_KUR_APPLY = "OUTER APPLY (
        SELECT TOP 1 DolarKur, EuroKur
        FROM dbo.Urun_Stok_Hareketleri
        WHERE Fatura_id = fge.FaturalarGelen_id AND Islem_Tipi = 'GIRIS' AND Durum = 1
        ORDER BY Stok_Hareket_id DESC
    ) kurg";
