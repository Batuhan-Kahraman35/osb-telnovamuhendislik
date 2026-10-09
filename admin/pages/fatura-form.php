<?php
/**
 * Admin Panel - Fatura Kesme Formu
 * Tekliften fatura oluşturma sayfası
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
require_once __DIR__ . '/../includes/TurkbelgeService.php';
require_once __DIR__ . '/../includes/DovizKuruHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü — herkes erişebilir, GİB gönderim yetkisi ayrıca kontrol edilir
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    'fatura-form.php'
);
// Sayfa erişimi açık — yetki kontrolü sadece GİB'e Gönder action'ında yapılır

$teklifId           = isset($_GET['teklif_id'])           ? intval($_GET['teklif_id'])           : 0;
$faturaId           = isset($_GET['id'])                   ? intval($_GET['id'])                   : 0;
$pazaryeriSiparisId = isset($_GET['pazaryeri_siparis_id']) ? intval($_GET['pazaryeri_siparis_id']) : 0;
$oncekiCariId       = isset($_GET['cari_id'])              ? intval($_GET['cari_id'])              : 0;
$iadeKaynakId       = isset($_GET['iade_kaynak'])          ? intval($_GET['iade_kaynak'])          : 0;
$fiyatFarkiKaynakId = isset($_GET['fiyat_farki_kaynak']) ? intval($_GET['fiyat_farki_kaynak']) : 0;
$vadeFarkiKaynakId  = isset($_GET['vade_farki_kaynak'])  ? intval($_GET['vade_farki_kaynak'])  : 0;

$fatura = null;
$kalemler = [];
$cariDetay = null;
$teklifInfo = null;
$pazaryeriSiparis = null; // pazaryeri modu için kaynak sipariş
$iadeKaynak = null;       // iade modu için kaynak (iade edilen) fatura
$fiyatFarkiKaynak = null; // fiyat farkı modu için kaynak gelen fatura
$vadeFarkiKaynak = null;  // vade farkı modu için kaynak giden fatura

if ($faturaId > 0) {
    // Fatura Düzenleme
    $fatura = $db->fetchOne("
        SELECT f.*, c.cari_adi, c.cari_unvan, c.cari_vergi_no, c.cari_vergi_dairesi,
               ik.FaturalarGelen_FaturaNo AS iade_kaynak_no,
               ik.FaturalarGelen_Tarih    AS iade_kaynak_tarih,
               ff.FaturalarGelen_FaturaNo AS ff_kaynak_no,
               ff.FaturalarGelen_Tarih    AS ff_kaynak_tarih,
               vf.FaturalarGiden_FaturaNo AS vf_kaynak_no,
               vf.FaturalarGiden_Tarih    AS vf_kaynak_tarih
        FROM FaturalarGiden f
        LEFT JOIN Cari c ON f.FaturalarGiden_Cari_id = c.cari_id
        LEFT JOIN FaturalarGelen ik ON ik.FaturalarGelen_id = f.FaturalarGiden_IadeKaynak_id
        LEFT JOIN FaturalarGelen ff ON ff.FaturalarGelen_id = f.FaturalarGiden_FiyatFarkiKaynak_id
        LEFT JOIN FaturalarGiden vf ON vf.FaturalarGiden_id = f.FaturalarGiden_VadeFarkiKaynak_id
        WHERE f.FaturalarGiden_id = ?
    ", [$faturaId]);

    if (!$fatura) {
        header('Location: /Admin/giden-efaturalar');
        exit;
    }

    $kalemler = $db->fetchAll("
        SELECT fk.*, uh.urun_hizmet_kodu, uh.urun_hizmet_adi,
        pb.ParaBirimi_Simge,
        ISNULL(uh.Urun_Stok_Miktari, 0) as stok_miktari
        FROM FaturalarGidenKalemleri fk
        LEFT JOIN Urun_Hizmet uh ON fk.FaturalarGidenKalemleri_StokKodu = uh.urun_hizmet_kodu
        LEFT JOIN tanim_ParaBirimi pb ON uh.urun_hizmet_para_birimi_id = pb.ParaBirimi_id
        WHERE fk.FaturalarGidenKalemleri_Fatura_id = ?
        ORDER BY fk.FaturalarGidenKalemleri_Sira
    ", [$faturaId]);

    if ($fatura['FaturalarGiden_Cari_id']) {
        $cariDetay = $db->fetchOne("
            SELECT c.*, s.SehirAdi, il.IlceAdi 
            FROM Cari c
            LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
            LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
            WHERE c.cari_id = ?
        ", [$fatura['FaturalarGiden_Cari_id']]);
    }

    // Taslak iade faturası düzenleniyorsa kaynak fatura bilgisi korunur
    if (!empty($fatura['FaturalarGiden_IadeKaynak_id'])) {
        $iadeKaynakId = (int)$fatura['FaturalarGiden_IadeKaynak_id'];
        $iadeKaynak = [
            'FaturalarGelen_id'       => $iadeKaynakId,
            'FaturalarGelen_FaturaNo' => $fatura['iade_kaynak_no'],
            'FaturalarGelen_Tarih'    => $fatura['iade_kaynak_tarih'],
        ];
    }

    // Taslak fiyat farkı faturası düzenleniyorsa kaynak fatura bilgisi korunur
    if (!empty($fatura['FaturalarGiden_FiyatFarkiKaynak_id'])) {
        $fiyatFarkiKaynakId = (int)$fatura['FaturalarGiden_FiyatFarkiKaynak_id'];
        $fiyatFarkiKaynak = [
            'FaturalarGelen_id'       => $fiyatFarkiKaynakId,
            'FaturalarGelen_FaturaNo' => $fatura['ff_kaynak_no'],
            'FaturalarGelen_Tarih'    => $fatura['ff_kaynak_tarih'],
        ];
    }

    // Taslak vade farkı faturası düzenleniyorsa kaynak fatura bilgisi korunur
    if (!empty($fatura['FaturalarGiden_VadeFarkiKaynak_id'])) {
        $vadeFarkiKaynakId = (int)$fatura['FaturalarGiden_VadeFarkiKaynak_id'];
        $vadeFarkiKaynak = [
            'FaturalarGiden_id'       => $vadeFarkiKaynakId,
            'FaturalarGiden_FaturaNo' => $fatura['vf_kaynak_no'],
            'FaturalarGiden_Tarih'    => $fatura['vf_kaynak_tarih'],
        ];
    }
} elseif ($pazaryeriSiparisId > 0) {
    // ─── PAZARYERİ SİPARİŞİNDEN FATURA ───
    $pazaryeriSiparis = $db->fetchOne("
        SELECT s.PazaryeriSiparisleri_id        AS ps_id,
               s.PazaryeriSiparisleri_SiparisNo AS ps_siparis_no,
               s.PazaryeriSiparisleri_Cari_id   AS ps_cari_id,
               p.Pazaryerleri_Ad                AS Entegrasyon_Kod
        FROM PazaryeriSiparisleri s
        INNER JOIN tanim_Pazaryerleri p ON p.Pazaryerleri_id = s.Pazaryerleri_id
        WHERE s.PazaryeriSiparisleri_id = ?
    ", [$pazaryeriSiparisId]);

    if (!$pazaryeriSiparis) {
        header('Location: /Admin/pazaryeri-siparisler');
        exit;
    }

    // Cari yükle
    $cariId = $oncekiCariId ?: $pazaryeriSiparis['ps_cari_id'];
    if ($cariId) {
        $cariDetay = $db->fetchOne("
            SELECT c.*, s.SehirAdi, il.IlceAdi
            FROM Cari c
            LEFT JOIN Adres_Sehirler s  ON c.cari_sehirler = s.SehirId
            LEFT JOIN Adres_Ilceler il  ON c.cari_ilceler  = il.ilceId
            WHERE c.cari_id = ?
        ", [$cariId]);
    }

    $fatura = [
        'FaturalarGiden_Tarih'   => date('Y-m-d'),
        'FaturalarGiden_Cari_id' => $cariId,
        'FaturalarGiden_VKN_TCKN'=> $cariDetay['cari_vergi_no']  ?? '',
        'FaturalarGiden_Unvan'   => $cariDetay['cari_unvan']      ?? $cariDetay['cari_adi'] ?? '',
    ];

    // Sipariş kalemlerini (normalize tablo) Urun_Hizmet ile eşleştir.
    // fiyat = indirim sonrası birim fiyat (müşterinin ödediği, KDV dahil)
    $urunler = $db->fetchAll("
        SELECT PazaryeriSiparisKalemleri_Barkod     AS barkod,
               PazaryeriSiparisKalemleri_UrunAdi    AS adi,
               PazaryeriSiparisKalemleri_Miktar     AS miktar,
               PazaryeriSiparisKalemleri_BirimFiyat AS fiyat
        FROM PazaryeriSiparisKalemleri
        WHERE PazaryeriSiparisleri_id = ? AND Durum = 1
        ORDER BY PazaryeriSiparisKalemleri_id
    ", [$pazaryeriSiparisId]);
    foreach ($urunler as $u) {
        $barkod = trim($u['barkod'] ?? '');
        $urunAdi = $u['adi'] ?? '';
        $miktar  = max(1, (float)($u['miktar'] ?? 1));
        $fiyat   = (float)($u['fiyat'] ?? 0);

        // KDV yalnız yerel ürünün urun_hizmet_kdv_id tanımından okunur;
        // pazaryerinin bildirdiği oran kullanılmaz. Eşleşme yoksa %20 gelir, formda düzeltilir.
        $kdvOran = 20;
        $stokKodu = '';
        $stokMiktar = 0;

        if ($barkod) {
            // Eşleşme sırası: pazaryeri ürün eşleşmesi → barkod = ürün kodu → barkod = ürün barkodu
            $urunRow = $db->fetchOne("
                SELECT TOP 1 uh.urun_hizmet_kodu, uh.urun_hizmet_adi,
                       ISNULL(uh.Urun_Stok_Miktari, 0) as stok_miktari,
                       kdv.kdv_oran
                FROM Urun_Hizmet uh
                LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                LEFT JOIN PazaryeriUrunleri pu
                       ON pu.urun_hizmet_id = uh.urun_hizmet_id AND pu.PazaryeriUrunleri_Barkod = ?
                WHERE uh.urun_hizmet_durum = 1
                  AND (pu.PazaryeriUrunleri_id IS NOT NULL OR uh.urun_hizmet_kodu = ? OR uh.urun_hizmet_barkod = ?)
                ORDER BY CASE WHEN pu.PazaryeriUrunleri_id IS NOT NULL THEN 1
                              WHEN uh.urun_hizmet_kodu = ? THEN 2 ELSE 3 END
            ", [$barkod, $barkod, $barkod, $barkod]);

            if ($urunRow) {
                $stokKodu   = $urunRow['urun_hizmet_kodu'];
                $urunAdi    = $urunRow['urun_hizmet_adi'] ?: $urunAdi;
                $stokMiktar = (float)$urunRow['stok_miktari'];
                if ($urunRow['kdv_oran'] !== null) {
                    $kdvOran = (float)$urunRow['kdv_oran'];
                }
            }
        }

        // Trendyol fiyatı KDV DAHİL — KDV hariç birim fiyata çevir
        $kdvCarpan        = 1 + ($kdvOran / 100);          // Örn: %20 → 1.20
        $birimFiyatHaric  = round($fiyat / $kdvCarpan, 4); // KDV hariç birim fiyat
        $nt = round($miktar * $birimFiyatHaric, 2);        // Net tutar (KDV hariç)
        $kt = round($nt * ($kdvOran / 100), 2);            // KDV tutarı

        $kalemler[] = [
            'FaturalarGidenKalemleri_StokKodu'    => $stokKodu,
            'FaturalarGidenKalemleri_UrunAdi'     => $urunAdi,
            'FaturalarGidenKalemleri_Miktar'      => $miktar,
            'FaturalarGidenKalemleri_Birim'       => 'ADET',
            'FaturalarGidenKalemleri_BirimFiyat'  => $birimFiyatHaric,
            'FaturalarGidenKalemleri_KDVOrani'    => $kdvOran,
            'FaturalarGidenKalemleri_KDVTutar'    => $kt,
            'FaturalarGidenKalemleri_NetTutar'    => $nt,
            'FaturalarGidenKalemleri_GToplamTutar'=> $nt + $kt,
            'stok_miktari'          => $stokMiktar,
            'para_birimi_kod'       => 'TRY',
            'orijinal_fiyat'        => $birimFiyatHaric,
            'trendyol_fiyat_dahil'  => $fiyat, // KDV dahil orijinal Trendyol fiyatı
        ];
    }
} elseif ($teklifId > 0) {
    // Tekliften Fatura Oluşturma
    $teklifInfo = $db->fetchOne("
        SELECT t.*, c.cari_adi, c.cari_unvan, c.cari_vergi_no, c.cari_vergi_dairesi,
        c.cari_telefon, c.cari_email, c.cari_sehirler, c.cari_ilceler, c.cari_adres,
        s.SehirAdi, il.IlceAdi
        FROM Teklifler t
        LEFT JOIN Cari c ON t.Teklifler_cari_id = c.cari_id
        LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
        LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
        WHERE t.Teklifler_id = ? AND t.Teklifler_durum_id = 3 AND t.Durum = 1
    ", [$teklifId]);

    if (!$teklifInfo) {
        // Hata: Teklif bulunamadı veya onaylı değil
        header('Location: /Admin/teklif-yonetimi');
        exit;
    }

    $kalemlerInfo = $db->fetchAll("
        SELECT tk.*, uh.urun_hizmet_kodu, uh.urun_hizmet_adi,
        uh.urun_hizmet_birim,
        ISNULL(uh.Urun_Stok_Miktari, 0) as stok_miktari,
        pb.ParaBirimi_Kod, pb.ParaBirimi_Simge,
        kdv.kdv_oran
        FROM Teklif_Kalemleri tk
        LEFT JOIN Urun_Hizmet uh ON tk.Teklif_Kalemleri_urun_hizmet_id = uh.urun_hizmet_id
        LEFT JOIN tanim_ParaBirimi pb ON tk.Teklif_Kalemleri_para_birimi_id = pb.ParaBirimi_id
        LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
        WHERE tk.Teklif_Kalemleri_teklif_id = ? AND tk.Durum = 1
        ORDER BY tk.Teklif_Kalemleri_sira_no
    ", [$teklifId]);

    $fatura = [
        'FaturalarGiden_Tarih' => date('Y-m-d'),
        'FaturalarGiden_Cari_id' => $teklifInfo['Teklifler_cari_id'],
        'FaturalarGiden_Teklif_id' => $teklifInfo['Teklifler_id'],
        'FaturalarGiden_Tutar' => $teklifInfo['Teklifler_genel_toplam'],
        'FaturalarGiden_NetTutar' => $teklifInfo['Teklifler_toplam_tutar'],
        'FaturalarGiden_ToplamKDV' => $teklifInfo['Teklifler_toplam_kdv'],
        'FaturalarGiden_VKN_TCKN' => $teklifInfo['cari_vergi_no'],
        'FaturalarGiden_Unvan' => $teklifInfo['cari_unvan'] ?: $teklifInfo['cari_adi'],
        // Teklifteki "Fatura Notları" faturaya taşınır; kur bilgisi ilk satıra
        // yazıldığında bu not otomatik olarak alt satırda kalır.
        'FaturalarGiden_Notlar' => trim((string)($teklifInfo['Teklifler_fatura_notu'] ?? '')),
    ];

    $cariDetay = $teklifInfo;
    $cariDetay['cari_id'] = $teklifInfo['Teklifler_cari_id'];
    $cariDetay['cari_unvan'] = $teklifInfo['cari_unvan'] ?: $teklifInfo['cari_adi'];

    // Teklif kalemlerini fatura kalemlerine çevir
    foreach ($kalemlerInfo as $tk) {
        $kalemler[] = [
            'FaturalarGidenKalemleri_StokKodu' => $tk['urun_hizmet_kodu'],
            'FaturalarGidenKalemleri_UrunAdi' => $tk['urun_hizmet_adi'],
            'FaturalarGidenKalemleri_Miktar' => $tk['Teklif_Kalemleri_miktar'],
            'FaturalarGidenKalemleri_Birim' => !empty($tk['Teklif_Kalemleri_birim']) ? $tk['Teklif_Kalemleri_birim'] : (!empty($tk['urun_hizmet_birim']) ? $tk['urun_hizmet_birim'] : 'ADET'),
            'FaturalarGidenKalemleri_BirimFiyat' => $tk['Teklif_Kalemleri_birim_fiyat'],
            'FaturalarGidenKalemleri_KDVOrani' => $tk['kdv_oran'] ?? 0,
            'FaturalarGidenKalemleri_KDVTutar' => $tk['Teklif_Kalemleri_kdv_tutar'],
            'FaturalarGidenKalemleri_NetTutar' => $tk['Teklif_Kalemleri_miktar'] * $tk['Teklif_Kalemleri_birim_fiyat'],
            'FaturalarGidenKalemleri_GToplamTutar' => $tk['Teklif_Kalemleri_toplam'],
            'stok_miktari' => $tk['stok_miktari'],
            'para_birimi_kod' => $tk['ParaBirimi_Kod'] ?? 'TRY',
            'orijinal_fiyat' => $tk['Teklif_Kalemleri_birim_fiyat']
        ];
    }
} elseif ($iadeKaynakId > 0) {
    // ─── İADE FATURASI (kaynak: gelen e-Fatura) ───
    // Bize gelen bir faturayı iade ederken IADE tipli giden fatura düzenlenir.
    // Türkbelge kılavuzu gereği senaryo TEMELFATURA olmak zorundadır.
    $iadeKaynak = $db->fetchOne("
        SELECT f.*, c.cari_id, c.cari_unvan, c.cari_adi
        FROM FaturalarGelen f
        LEFT JOIN Cari c ON c.cari_vergi_no = f.FaturalarGelen_VKN_TCKN
        WHERE f.FaturalarGelen_id = ?
    ", [$iadeKaynakId]);

    // Cari kartı yoksa iade faturası kesilemez (fatura formunda cari zorunlu)
    if (!$iadeKaynak || empty($iadeKaynak['cari_id'])) {
        header('Location: /Admin/gelen-efaturalar');
        exit;
    }

    $cariDetay = $db->fetchOne("
        SELECT c.*, s.SehirAdi, il.IlceAdi
        FROM Cari c
        LEFT JOIN Adres_Sehirler s  ON c.cari_sehirler = s.SehirId
        LEFT JOIN Adres_Ilceler  il ON c.cari_ilceler  = il.ilceId
        WHERE c.cari_id = ?
    ", [$iadeKaynak['cari_id']]);

    $fatura = [
        'FaturalarGiden_Tarih'         => date('Y-m-d'),
        'FaturalarGiden_Cari_id'       => $iadeKaynak['cari_id'],
        'FaturalarGiden_FaturaTipi'    => 'IADE',
        'FaturalarGiden_Senaryo'       => 'TEMELFATURA',
        'FaturalarGiden_ParaBirimi'    => $iadeKaynak['FaturalarGelen_ParaBirimi'] ?? 'TRY',
        'FaturalarGiden_VKN_TCKN'      => $iadeKaynak['FaturalarGelen_VKN_TCKN'],
        'FaturalarGiden_Unvan'         => $iadeKaynak['FaturalarGelen_Unvan'],
        'FaturalarGiden_IadeKaynak_id' => $iadeKaynakId,
        'FaturalarGiden_Notlar'        => $iadeKaynak['FaturalarGelen_FaturaNo'] . ' numaralı faturanın iadesidir.',
    ];

    // Kur alanları iade tarihine göre peşinen doldurulur (istemci aynı değeri kullanır)
    $iadeKurHelperBas = new DovizKuruHelper($db);
    $fatura['FaturalarGiden_DolarKur'] = $iadeKurHelperBas->faturaKuruGetir(date('Y-m-d'), 'USD', $user['kullanici_id']) ?: null;
    $fatura['FaturalarGiden_EuroKur']  = $iadeKurHelperBas->faturaKuruGetir(date('Y-m-d'), 'EUR', $user['kullanici_id']) ?: null;

    // Gelen fatura kalemleri — miktarlar düzenlenebilir, satırlar çıkarılabilir.
    // Kalemler yalnızca stok eşleştirmesi yapılmış faturalarda dolu olur; boşsa
    // form uyarı ile açılır ve kalemler elle girilir.
    $gelenKalemler = $db->fetchAll("
        SELECT fk.*, uh.urun_hizmet_kodu, uh.urun_hizmet_adi,
               pb.ParaBirimi_Simge,
               ISNULL(uh.Urun_Stok_Miktari, 0) AS stok_miktari
        FROM FaturalarGelenKalemleri fk
        LEFT JOIN Urun_Hizmet uh ON uh.urun_hizmet_id = fk.FaturalarGelenKalemleri_UrunHizmet_id
        LEFT JOIN tanim_ParaBirimi pb ON uh.urun_hizmet_para_birimi_id = pb.ParaBirimi_id
        WHERE fk.FaturalarGelenKalemleri_Fatura_id = ?
        ORDER BY fk.FaturalarGelenKalemleri_Sira
    ", [$iadeKaynakId]);

    // Gelen faturanın kalem fiyatları kendi para biriminde (UBL'den ham) gelir.
    // Giden fatura TRY olarak kesildiği için fiyatlar İADE FATURASININ tarihine ait
    // TCMB kuruyla TL'ye çevrilir; boylece ilk render'da da dogru tutar gorunur.
    // (Istemcideki kurUygula() ayni hesabi tarih degistiginde yeniden yapar.)
    $iadeParaBirimi = strtoupper(trim($iadeKaynak['FaturalarGelen_ParaBirimi'] ?? 'TRY')) ?: 'TRY';
    $iadeKur = 1.0;
    if ($iadeParaBirimi === 'USD')      $iadeKur = (float)($fatura['FaturalarGiden_DolarKur'] ?? 0);
    elseif ($iadeParaBirimi === 'EUR')  $iadeKur = (float)($fatura['FaturalarGiden_EuroKur']  ?? 0);
    if ($iadeKur <= 0) $iadeKur = 1.0; // kur bulunamazsa ham fiyat korunur, istemci yeniden dener

    // Gelen kalem kolonlarını giden kalem alan adlarına çevir (kolon adları birebir eşleşir)
    foreach ($gelenKalemler as $gk) {
        $kalemler[] = [
            'FaturalarGidenKalemleri_StokKodu'      => $gk['FaturalarGelenKalemleri_StokKodu'],
            'FaturalarGidenKalemleri_UrunAdi'       => $gk['FaturalarGelenKalemleri_UrunAdi'],
            'FaturalarGidenKalemleri_Miktar'        => $gk['FaturalarGelenKalemleri_Miktar'],
            'FaturalarGidenKalemleri_Birim'         => $gk['FaturalarGelenKalemleri_Birim'] ?: 'ADET',
            // Ekranda gösterilen/kaydedilen fiyat TL, orijinal_fiyat ise döviz cinsinden kalır
            'FaturalarGidenKalemleri_BirimFiyat'    => round((float)$gk['FaturalarGelenKalemleri_BirimFiyat'] * $iadeKur, 4),
            'FaturalarGidenKalemleri_KDVOrani'      => $gk['FaturalarGelenKalemleri_KDVOrani'],
            'FaturalarGidenKalemleri_KDVTutar'      => round((float)$gk['FaturalarGelenKalemleri_KDVTutar'] * $iadeKur, 2),
            'FaturalarGidenKalemleri_NetTutar'      => round((float)$gk['FaturalarGelenKalemleri_NetTutar'] * $iadeKur, 2),
            'FaturalarGidenKalemleri_GToplamTutar'  => round((float)$gk['FaturalarGelenKalemleri_GToplamTutar'] * $iadeKur, 2),
            'FaturalarGidenKalemleri_UrunHizmet_id' => $gk['FaturalarGelenKalemleri_UrunHizmet_id'],
            'stok_miktari'                          => $gk['stok_miktari'],
            'para_birimi_kod'                       => $iadeParaBirimi,
            'orijinal_fiyat'                        => $gk['FaturalarGelenKalemleri_BirimFiyat'],
        ];
    }
} elseif ($fiyatFarkiKaynakId > 0) {
    // ─── FİYAT FARKI FATURASI (kaynak: gelen e-Fatura) ───
    // Alım faturası sonrası oluşan fiyat farkı için SATIS tipli giden fatura kesilir.
    // Belge tek kalemden oluşur: "Fiyat Farkı", 1 adet, birim tutar elle girilir.
    $fiyatFarkiKaynak = $db->fetchOne("
        SELECT f.*, c.cari_id, c.cari_unvan, c.cari_adi
        FROM FaturalarGelen f
        LEFT JOIN Cari c ON c.cari_vergi_no = f.FaturalarGelen_VKN_TCKN
        WHERE f.FaturalarGelen_id = ?
    ", [$fiyatFarkiKaynakId]);

    // Cari kartı yoksa fatura kesilemez (fatura formunda cari zorunlu)
    if (!$fiyatFarkiKaynak || empty($fiyatFarkiKaynak['cari_id'])) {
        header('Location: /Admin/gelen-efaturalar');
        exit;
    }

    $cariDetay = $db->fetchOne("
        SELECT c.*, s.SehirAdi, il.IlceAdi
        FROM Cari c
        LEFT JOIN Adres_Sehirler s  ON c.cari_sehirler = s.SehirId
        LEFT JOIN Adres_Ilceler  il ON c.cari_ilceler  = il.ilceId
        WHERE c.cari_id = ?
    ", [$fiyatFarkiKaynak['cari_id']]);

    $fatura = [
        'FaturalarGiden_Tarih'              => date('Y-m-d'),
        'FaturalarGiden_Cari_id'            => $fiyatFarkiKaynak['cari_id'],
        'FaturalarGiden_FaturaTipi'         => 'SATIS',
        'FaturalarGiden_Senaryo'            => 'TEMELFATURA',
        'FaturalarGiden_ParaBirimi'         => 'TRY',
        'FaturalarGiden_VKN_TCKN'           => $fiyatFarkiKaynak['FaturalarGelen_VKN_TCKN'],
        'FaturalarGiden_Unvan'              => $fiyatFarkiKaynak['FaturalarGelen_Unvan'],
        'FaturalarGiden_FiyatFarkiKaynak_id'=> $fiyatFarkiKaynakId,
        'FaturalarGiden_Notlar'             => $fiyatFarkiKaynak['FaturalarGelen_FaturaNo'] . ' numaralı faturaya ait fiyat farkıdır.',
    ];

    // Kur alanları fatura tarihine göre peşinen doldurulur (istemci aynı değeri kullanır)
    $ffKurHelper = new DovizKuruHelper($db);
    $fatura['FaturalarGiden_DolarKur'] = $ffKurHelper->faturaKuruGetir(date('Y-m-d'), 'USD', $user['kullanici_id']) ?: null;
    $fatura['FaturalarGiden_EuroKur']  = $ffKurHelper->faturaKuruGetir(date('Y-m-d'), 'EUR', $user['kullanici_id']) ?: null;

    // KDV oranı kaynak faturanın kalemlerinden alınır; kalem işlenmemişse 20 kabul edilir.
    $ffKdv = $db->fetchOne("
        SELECT TOP 1 FaturalarGelenKalemleri_KDVOrani AS oran
        FROM FaturalarGelenKalemleri
        WHERE FaturalarGelenKalemleri_Fatura_id = ?
        ORDER BY FaturalarGelenKalemleri_Sira
    ", [$fiyatFarkiKaynakId]);
    $ffKdvOrani = $ffKdv && $ffKdv['oran'] !== null ? (float)$ffKdv['oran'] : 20;

    // Kaynak faturanın para birimi taşınır: ürünler döviz fiyat üzerinden satıldığı için
    // fark da döviz cinsinden girilir. Çevrimde KAYNAK FATURANIN KENDİ KURU kullanılır
    // (fiyat farkı, kaynak faturadaki fiyatın düzeltilmesidir; kur farkı değildir).
    $ffParaBirimi = strtoupper(trim($fiyatFarkiKaynak['FaturalarGelen_ParaBirimi'] ?? 'TRY')) ?: 'TRY';
    $ffKaynakTarih = !empty($fiyatFarkiKaynak['FaturalarGelen_Tarih'])
        ? date('Y-m-d', strtotime($fiyatFarkiKaynak['FaturalarGelen_Tarih']))
        : date('Y-m-d');

    // Kaynak faturanın kuru: stok girişi yapılırken kaydedilen kur asıl kaynaktır
    // (gelen faturanın kendi belgesindeki kur budur). Stok girişi yapılmamışsa
    // kaynak faturanın tarihine ait TCMB alış kuruna düşülür.
    $ffStokKur = $db->fetchOne("
        SELECT TOP 1 DolarKur, EuroKur
        FROM dbo.Urun_Stok_Hareketleri
        WHERE Fatura_id = ? AND Islem_Tipi = 'GIRIS' AND Durum = 1
        ORDER BY Stok_Hareket_id DESC
    ", [$fiyatFarkiKaynakId]);

    $ffKaynakKurUsd = (float)($ffStokKur['DolarKur'] ?? 0);
    $ffKaynakKurEur = (float)($ffStokKur['EuroKur']  ?? 0);
    $ffKurKaynagi   = ($ffKaynakKurUsd > 0 || $ffKaynakKurEur > 0) ? 'stok' : 'tcmb';

    if ($ffKurKaynagi === 'tcmb') {
        $ffKaynakKurUsd = (float)($ffKurHelper->faturaKuruGetir($ffKaynakTarih, 'USD', $user['kullanici_id']) ?: 0);
        $ffKaynakKurEur = (float)($ffKurHelper->faturaKuruGetir($ffKaynakTarih, 'EUR', $user['kullanici_id']) ?: 0);
    }

    $ffKurEtiket = $ffKurKaynagi === 'stok'
        ? 'kaynak fatura kuru'
        : date('d.m.Y', strtotime($ffKaynakTarih)) . ' TCMB kuru';

    // Tek kalem: Fiyat Farkı — miktar 1, birim tutar kullanıcı tarafından girilir
    $kalemler[] = [
        'FaturalarGidenKalemleri_StokKodu'      => '',
        'FaturalarGidenKalemleri_UrunAdi'       => 'Fiyat Farkı',
        'FaturalarGidenKalemleri_Miktar'        => 1,
        'FaturalarGidenKalemleri_Birim'         => 'ADET',
        'FaturalarGidenKalemleri_BirimFiyat'    => 0,
        'FaturalarGidenKalemleri_KDVOrani'      => $ffKdvOrani,
        'FaturalarGidenKalemleri_KDVTutar'      => 0,
        'FaturalarGidenKalemleri_NetTutar'      => 0,
        'FaturalarGidenKalemleri_GToplamTutar'  => 0,
        'FaturalarGidenKalemleri_UrunHizmet_id' => null,
        // -1: stok takibi dışı satır — fiyat farkı kalemi stoktan düşmez
        'stok_miktari'                          => -1,
        'para_birimi_kod'                       => $ffParaBirimi,
        'orijinal_fiyat'                        => 0,
        // Fark tutarı döviz alanından girilebilir (TL karşılığı kurdan otomatik hesaplanır);
        // alan yalnızca satırın para birimi TRY dışında seçiliyken görünür.
        'doviz_giris'                           => true,
        'kaynak_kur_usd'                        => $ffKaynakKurUsd,
        'kaynak_kur_eur'                        => $ffKaynakKurEur,
        'kaynak_kur_etiket'                     => $ffKurEtiket,
    ];
} elseif ($vadeFarkiKaynakId > 0) {
    // ─── VADE FARKI FATURASI (kaynak: giden e-Fatura) ───
    // Satış faturası sonrası oluşan vade farkı için SATIS tipli giden fatura kesilir.
    // Belge tek kalemden oluşur: "Vade Farkı", 1 adet, birim tutar elle girilir.
    $vadeFarkiKaynak = $db->fetchOne("
        SELECT f.*, c.cari_id, c.cari_unvan, c.cari_adi
        FROM FaturalarGiden f
        OUTER APPLY (
            -- Faturadaki cari; yoksa listedeki gibi VKN eşleşmesi
            SELECT TOP 1 cari_id, cari_unvan, cari_adi
            FROM Cari
            WHERE cari_id = f.FaturalarGiden_Cari_id
               OR (f.FaturalarGiden_Cari_id IS NULL AND cari_vergi_no = f.FaturalarGiden_VKN_TCKN)
            ORDER BY cari_id DESC
        ) c
        WHERE f.FaturalarGiden_id = ?
    ", [$vadeFarkiKaynakId]);

    // Cari kartı yoksa fatura kesilemez (fatura formunda cari zorunlu)
    if (!$vadeFarkiKaynak || empty($vadeFarkiKaynak['cari_id'])) {
        header('Location: /Admin/giden-efaturalar');
        exit;
    }

    $cariDetay = $db->fetchOne("
        SELECT c.*, s.SehirAdi, il.IlceAdi
        FROM Cari c
        LEFT JOIN Adres_Sehirler s  ON c.cari_sehirler = s.SehirId
        LEFT JOIN Adres_Ilceler  il ON c.cari_ilceler  = il.ilceId
        WHERE c.cari_id = ?
    ", [$vadeFarkiKaynak['cari_id']]);

    $fatura = [
        'FaturalarGiden_Tarih'              => date('Y-m-d'),
        'FaturalarGiden_Cari_id'            => $vadeFarkiKaynak['cari_id'],
        'FaturalarGiden_FaturaTipi'         => 'SATIS',
        'FaturalarGiden_Senaryo'            => $vadeFarkiKaynak['FaturalarGiden_Senaryo'] ?: 'TEMELFATURA',
        'FaturalarGiden_ParaBirimi'         => 'TRY',
        'FaturalarGiden_VKN_TCKN'           => $vadeFarkiKaynak['FaturalarGiden_VKN_TCKN'],
        'FaturalarGiden_Unvan'              => $vadeFarkiKaynak['FaturalarGiden_Unvan'],
        'FaturalarGiden_VadeFarkiKaynak_id' => $vadeFarkiKaynakId,
        'FaturalarGiden_Notlar'             => $vadeFarkiKaynak['FaturalarGiden_FaturaNo'] . ' numaralı faturaya ait vade farkıdır.',
    ];

    // Kur alanları fatura tarihine göre peşinen doldurulur (istemci aynı değeri kullanır)
    $ffKurHelper = new DovizKuruHelper($db);
    $fatura['FaturalarGiden_DolarKur'] = $ffKurHelper->faturaKuruGetir(date('Y-m-d'), 'USD', $user['kullanici_id']) ?: null;
    $fatura['FaturalarGiden_EuroKur']  = $ffKurHelper->faturaKuruGetir(date('Y-m-d'), 'EUR', $user['kullanici_id']) ?: null;

    // KDV oranı kaynak faturanın kalemlerinden alınır; kalem yoksa 20 kabul edilir.
    $ffKdv = $db->fetchOne("
        SELECT TOP 1 FaturalarGidenKalemleri_KDVOrani AS oran
        FROM FaturalarGidenKalemleri
        WHERE FaturalarGidenKalemleri_Fatura_id = ?
        ORDER BY FaturalarGidenKalemleri_Sira
    ", [$vadeFarkiKaynakId]);
    $ffKdvOrani = $ffKdv && $ffKdv['oran'] !== null ? (float)$ffKdv['oran'] : 20;

    // Kaynak faturanın para birimi taşınır; çevrimde KAYNAK FATURANIN KENDİ KURU kullanılır.
    $ffParaBirimi = strtoupper(trim($vadeFarkiKaynak['FaturalarGiden_ParaBirimi'] ?? 'TRY')) ?: 'TRY';
    $ffKaynakTarih = !empty($vadeFarkiKaynak['FaturalarGiden_Tarih'])
        ? date('Y-m-d', strtotime($vadeFarkiKaynak['FaturalarGiden_Tarih']))
        : date('Y-m-d');

    // Kaynak giden faturada kayıtlı kur asıl kaynaktır; yoksa kaynak fatura tarihinin TCMB kuruna düşülür.
    $ffKaynakKurUsd = (float)($vadeFarkiKaynak['FaturalarGiden_DolarKur'] ?? 0);
    $ffKaynakKurEur = (float)($vadeFarkiKaynak['FaturalarGiden_EuroKur']  ?? 0);
    $ffKurKaynagi   = ($ffKaynakKurUsd > 0 || $ffKaynakKurEur > 0) ? 'fatura' : 'tcmb';

    if ($ffKurKaynagi === 'tcmb') {
        $ffKaynakKurUsd = (float)($ffKurHelper->faturaKuruGetir($ffKaynakTarih, 'USD', $user['kullanici_id']) ?: 0);
        $ffKaynakKurEur = (float)($ffKurHelper->faturaKuruGetir($ffKaynakTarih, 'EUR', $user['kullanici_id']) ?: 0);
    }

    $ffKurEtiket = $ffKurKaynagi === 'fatura'
        ? 'kaynak fatura kuru'
        : date('d.m.Y', strtotime($ffKaynakTarih)) . ' TCMB kuru';

    // Tek kalem: Vade Farkı — miktar 1, birim tutar kullanıcı tarafından girilir
    $kalemler[] = [
        'FaturalarGidenKalemleri_StokKodu'      => '',
        'FaturalarGidenKalemleri_UrunAdi'       => 'Vade Farkı',
        'FaturalarGidenKalemleri_Miktar'        => 1,
        'FaturalarGidenKalemleri_Birim'         => 'ADET',
        'FaturalarGidenKalemleri_BirimFiyat'    => 0,
        'FaturalarGidenKalemleri_KDVOrani'      => $ffKdvOrani,
        'FaturalarGidenKalemleri_KDVTutar'      => 0,
        'FaturalarGidenKalemleri_NetTutar'      => 0,
        'FaturalarGidenKalemleri_GToplamTutar'  => 0,
        'FaturalarGidenKalemleri_UrunHizmet_id' => null,
        // -1: stok takibi dışı satır — vade farkı kalemi stoktan düşmez
        'stok_miktari'                          => -1,
        'para_birimi_kod'                       => $ffParaBirimi,
        'orijinal_fiyat'                        => 0,
        'doviz_giris'                           => true,
        'kaynak_kur_usd'                        => $ffKaynakKurUsd,
        'kaynak_kur_eur'                        => $ffKaynakKurEur,
        'kaynak_kur_etiket'                     => $ffKurEtiket,
    ];
}

// Yeni Fatura No Oluştur (TLNYYYYNNNNNNNNN - 16 hane)
$faturaNo = $fatura['FaturalarGiden_FaturaNo'] ?? '';
if (!$faturaNo) {
    $yil = date('Y');
    // Prefix + Yil (TLN2026 gibi)
    $prefix = 'TLN' . $yil;
    
    $sonFatura = $db->fetchOne("
        SELECT TOP 1 FaturalarGiden_FaturaNo 
        FROM FaturalarGiden 
        WHERE FaturalarGiden_FaturaNo LIKE ? 
        ORDER BY FaturalarGiden_FaturaNo DESC
    ", [$prefix . '%']);
    
    if ($sonFatura && strlen($sonFatura['FaturalarGiden_FaturaNo']) == 16) {
        $sonSiraStr = substr($sonFatura['FaturalarGiden_FaturaNo'], 7); // Son 9 hane
        $sira = intval($sonSiraStr) + 1;
    } else {
        $sira = 1;
    }
    $faturaNo = $prefix . str_pad($sira, 9, '0', STR_PAD_LEFT);
}


$isIadeModu = ($iadeKaynak !== null);
$isFiyatFarkiModu = ($fiyatFarkiKaynak !== null);
$isVadeFarkiModu = ($vadeFarkiKaynak !== null);
$pageTitle = $isIadeModu
    ? ($faturaId ? 'İade Faturası Düzenle' : 'İade Faturası Kes')
    : ($isFiyatFarkiModu
        ? ($faturaId ? 'Fiyat Farkı Faturası Düzenle' : 'Fiyat Farkı Faturası Kes')
        : ($isVadeFarkiModu
            ? ($faturaId ? 'Vade Farkı Faturası Düzenle' : 'Vade Farkı Faturası Kes')
            : ($faturaId ? 'Fatura Düzenle' : 'Yeni Fatura Kes')));
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
$paraBirimleri = $db->fetchAll("SELECT ParaBirimi_Kod FROM tanim_ParaBirimi WHERE Durum = 1 ORDER BY ParaBirimi_Kod");


// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Faturalaştır (Turkbelge'ye gönder)
    if (($_POST['action'] ?? '') === 'faturalastir') {
        // GİB'e Gönder için can_delete yetkisi zorunlu
        if (!($pagePermissions['can_delete'] ?? false)) {
            echo json_encode(['success' => false, 'message' => 'GİB\'e gönderme işlemi için yetkiniz bulunmamaktadır.']);
            exit;
        }
        try {
            $fid = intval($_POST['fatura_id'] ?? 0);
            if (!$fid) throw new Exception('Fatura ID gerekli.');

            $isTest = !empty($_POST['is_test']) && $_POST['is_test'] == '1';
            $kanalId = $isTest ? 12 : 1;

            $fat = $db->fetchOne("
                SELECT f.*,
                       c.cari_adres, c.cari_telefon, c.cari_email,
                       c.cari_vergi_dairesi,
                       s.SehirAdi AS cari_sehir_adi,
                       il.IlceAdi AS cari_ilce_adi,
                       ik.FaturalarGiden_FaturaNo AS iade_kaynak_no,
                       ik.FaturalarGiden_Tarih    AS iade_kaynak_tarih
                FROM FaturalarGiden f
                LEFT JOIN Cari c ON c.cari_id = f.FaturalarGiden_Cari_id
                LEFT JOIN FaturalarGiden ik ON ik.FaturalarGiden_id = f.FaturalarGiden_IadeKaynak_id
                LEFT JOIN Adres_Sehirler s  ON s.SehirId  = c.cari_sehirler
                LEFT JOIN Adres_Ilceler  il ON il.ilceId  = c.cari_ilceler
                WHERE f.FaturalarGiden_id = ? AND f.Durum = 1
            ", [$fid]);
            if (!$fat) throw new Exception('Fatura bulunamadı.');
            if ($fat['FaturalarGiden_Durum'] !== 'Taslak') throw new Exception('Sadece Taslak faturalar gönderilebilir.');

            $kalemler = $db->fetchAll("SELECT * FROM FaturalarGidenKalemleri WHERE FaturalarGidenKalemleri_Fatura_id = ? ORDER BY FaturalarGidenKalemleri_Sira", [$fid]);
            if (empty($kalemler)) throw new Exception('Faturada kalem bulunamadı.');

            $tb = TurkbelgeService::fromDb($db, $kanalId);
            $tb->authenticate();

            // Mükellef kontrolü → null ise e-Arşiv, array ise e-Fatura
            $aliciVkn = $fat['FaturalarGiden_VKN_TCKN'] ?? '';
            $mukellef = $aliciVkn ? $tb->mukellefKontrol($aliciVkn) : null;

            // UBL-TR XML oluştur (mukellef null ise e-Arşiv'dir)
            $isEArsiv = ($mukellef === null);

            // İade faturası yalnız e-Fatura mükellefine kesilebilir; e-Arşiv tarafında
            // iade belgesi yoktur, orijinal fatura CancelEArchiveInvoice ile iptal edilir.
            $isIadeFatura = (($fat['FaturalarGiden_FaturaTipi'] ?? '') === 'IADE');
            if ($isIadeFatura && $isEArsiv) {
                throw new Exception('Alıcı e-Fatura mükellefi olmadığından iade faturası gönderilemez. e-Arşiv faturaları için iptal süreci uygulanmalıdır.');
            }

            $buildResult = $tb->buildUblXml($fat, $kalemler, $isEArsiv);
            $xml         = $buildResult['xml'];
            $uuid        = $buildResult['uuid'];
            $senaryo     = $buildResult['senaryo'];

            // Kanal ortamı (TEST mi PROD mu?)
            $kanalOrtam = $db->fetchOne("
                SELECT ek.Kanal_Ortam FROM EntegrasyonKanallari ek
                INNER JOIN Entegrasyon e ON e.Entegrasyon_Id = ek.Kanal_Entegrasyon_Id
                WHERE e.Entegrasyon_Kod = 'TURKBELGE' AND ek.Kanal_Id = ?
            ", [$kanalId]);
            $testOrtami = ($kanalOrtam['Kanal_Ortam'] ?? 'TEST') === 'TEST';

            if ($mukellef) {
                // e-Fatura: LoadInvoice → SendInvoice
                $gonderResult = $tb->efaturaGonder($xml, $uuid, $aliciVkn, $mukellef['alias'], $senaryo);
                $tip = 'e-Fatura';
            } else {
                // e-Arşiv: WriteToArchiveExtended
                $gonderResult = $tb->earsivGonder($xml, $uuid, $testOrtami);
                $tip = 'e-Arşiv';
            }

            // DB güncelle
            $db->execute("
                UPDATE FaturalarGiden SET
                    FaturalarGiden_Durum    = 'Kesildi',
                    FaturalarGiden_UUID     = ?,
                    FaturalarGiden_GibDurum = ?,
                    FaturalarGiden_StokIslendi = 1,
                    GuncelleyenKullanici    = ?,
                    GuncellemeTarihi        = GETDATE()
                WHERE FaturalarGiden_id = ?
            ", [$uuid, $tip, $user['kullanici_id'], $fid]);

            // Otomatik stok çıkışı — iade faturasında da mal bizden çıktığı için yön aynıdır
            $stokAciklama = $isIadeFatura ? 'E-Fatura İade (Otomatik): ' : 'E-Fatura Satış (Otomatik): ';

            foreach ($kalemler as $kl) {
                $stokKodu = $kl['FaturalarGidenKalemleri_StokKodu'] ?? '';
                $miktar   = (float)($kl['FaturalarGidenKalemleri_Miktar'] ?? 0);
                $fiyat    = (float)($kl['FaturalarGidenKalemleri_BirimFiyat'] ?? 0);

                if (empty($stokKodu) || $miktar <= 0) continue;

                // Stok koduna göre ürün bul
                $urun = $db->fetchOne("SELECT urun_hizmet_id FROM Urun_Hizmet WHERE urun_hizmet_kodu = ? AND urun_hizmet_durum = 1", [$stokKodu]);
                if (!$urun) continue;

                $urunId = (int)$urun['urun_hizmet_id'];

                // Stok hareketi kaydet
                $db->insert('Urun_Stok_Hareketleri', [
                    'Urun_id'              => $urunId,
                    'Islem_Tipi'           => 'CIKIS',
                    'Miktar'               => $miktar,
                    'Birim_Fiyati'         => $fiyat,
                    'Fatura_id'            => $fid,
                    'Fatura_No'            => $fat['FaturalarGiden_FaturaNo'],
                    'Aciklama'             => $stokAciklama . $fat['FaturalarGiden_FaturaNo'],
                    'OlusturanKullanici'   => $user['kullanici_id'],
                    'OlusturmaTarihi'      => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                    'Durum'                => 1
                ]);

                // Stok miktarını güncelle
                $db->execute("UPDATE Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) - ? WHERE urun_hizmet_id = ?", [$miktar, $urunId]);
            }

            $db->execute("UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Entegrasyon_Id = (SELECT Entegrasyon_Id FROM Entegrasyon WHERE Entegrasyon_Kod = 'TURKBELGE')");

            // ─── Pazaryeri siparişini güncelle ───
            // POST'tan değil DB'den sorgula — ?id=52 üzerinden gelince de çalışsın
            $pzSip = $db->fetchOne("
                SELECT PazaryeriSiparisleri_id AS id, kanal_id, PazaryeriSiparisleri_PaketNo AS paket
                FROM PazaryeriSiparisleri
                WHERE PazaryeriSiparisleri_Fatura_id = ?
            ", [$fid]);

            if ($pzSip) {
                // Fatura no
                $fatGun = $db->fetchOne("SELECT FaturalarGiden_FaturaNo FROM FaturalarGiden WHERE FaturalarGiden_id = ?", [$fid]);
                $fatNo  = $fatGun['FaturalarGiden_FaturaNo'] ?? $fat['FaturalarGiden_FaturaNo'] ?? '';

                // Pazaryeri bildirimi — sınıf ve yetenekler tanim_Pazaryerleri'nden (platform adı koda gömülmez)
                require_once __DIR__ . '/../includes/PazaryeriFabrika.php';
                $durumYaz = false;
                try {
                    $pzApi = PazaryeriFabrika::olusturKanaldan((int)$pzSip['kanal_id'], $user['kullanici_id']);
                    if (!$pzApi || !$pzApi->hazirMi()) {
                        throw new Exception($pzApi ? $pzApi->getHata() : PazaryeriFabrika::sonHata());
                    }
                    $pzAd = $pzApi->getPazaryeriAdi();

                    if ($pzSip['paket'] && $fatNo && $pzApi->yetenek('paketDurum')) {
                        $lines = [];
                        foreach ($db->fetchAll("SELECT PazaryeriSiparisKalemleri_DisId AS lid, PazaryeriSiparisKalemleri_Miktar AS adet
                                                FROM PazaryeriSiparisKalemleri WHERE PazaryeriSiparisleri_id = ? AND Durum = 1", [$pzSip['id']]) as $k) {
                            if ($k['lid']) $lines[] = ['lineId' => (int)$k['lid'], 'quantity' => (int)$k['adet']];
                        }
                        // Paket Shipped/Delivered ise pazaryeri reddeder — beklenen durum, uyarı gösterilmez
                        $durumYaz = $pzApi->paketDurumGuncelle($pzSip['paket'], 'Invoiced', $lines, ['invoiceNumber' => $fatNo])['basarili'];
                    }

                    // Fatura linki — sonuç kullanıcıya iletilir (başarısızlık sessiz değil)
                    if ($pzSip['paket'] && $fatNo && $pzApi->yetenek('faturaBildirim')) {
                        $link = $pzApi->faturaLinkGonder($pzSip['paket'], [
                            'numara' => $fatNo,
                            'tarih'  => strtotime($fat['FaturalarGiden_Tarih'] ?? date('Y-m-d')) * 1000,
                            'link'   => 'https://panel.ornekfirma.com/admin/pages/fatura-goruntule.php?fatura_no=' . $fatNo,
                        ]);
                        $pazaryeriMesaj = $link['basarili']
                            ? " Fatura linki {$pzAd}'a iletildi."
                            : " UYARI: Fatura linki {$pzAd}'a iletilemedi (" . $link['hata'] . ').';
                    }
                } catch (Exception $ex) {
                    error_log('[fatura-form] Pazaryeri bildirimi hatası: ' . $ex->getMessage());
                    $pazaryeriMesaj = ' UYARI: Pazaryeri bildirimi yapılamadı (' . $ex->getMessage() . ').';
                }

                // Yerel durum yalnız pazaryeri bildirimi başarılıysa değişir; aksi halde gerçek durum korunur
                if ($durumYaz) {
                    $db->execute("
                        UPDATE PazaryeriSiparisleri
                        SET PazaryeriSiparisleri_HamDurum = 'Invoiced', PazaryeriSiparisleri_DurumKod = 'hazirlaniyor',
                            GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                        WHERE PazaryeriSiparisleri_id = ?", [$user['kullanici_id'], $pzSip['id']]);
                }
            }

            echo json_encode(['success' => true, 'message' => "$tip olarak başarıyla gönderildi." . ($pazaryeriMesaj ?? ''), 'uuid' => $uuid, 'tip' => $tip]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'urun_ara':
                $q           = trim($_POST['q'] ?? '');
                $sadece_stok = ($_POST['sadece_stok'] ?? '0') === '1';

                $where  = 'WHERE uh.urun_hizmet_durum = 1';
                $params = [];
                if ($q) {
                    $where   .= ' AND (uh.urun_hizmet_kodu LIKE ? OR uh.urun_hizmet_adi LIKE ? OR uh.urun_hizmet_model LIKE ?)';
                    $params[] = '%' . $q . '%';
                    $params[] = '%' . $q . '%';
                    $params[] = '%' . $q . '%';
                }
                if ($sadece_stok) {
                    $where .= ' AND uh.Urun_Stok_Miktari > 0';
                }

                $rows = $db->fetchAll("
                    SELECT TOP 20
                        uh.urun_hizmet_id,
                        uh.urun_hizmet_kodu,
                        uh.urun_hizmet_adi,
                        uh.urun_hizmet_model,
                        uh.urun_hizmet_birim,
                        ISNULL(uh.Urun_Stok_Miktari, 0) AS stok,
                        ISNULL(kdv.kdv_oran, 20) AS kdv_oran
                    FROM Urun_Hizmet uh
                    LEFT JOIN UrunHizmet_KDVTanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                    {$where}
                    ORDER BY uh.urun_hizmet_adi
                ", $params);

                $results = array_map(fn($r) => [
                    'id'           => $r['urun_hizmet_kodu'],
                    'text'         => $r['urun_hizmet_adi'] . ' [' . $r['urun_hizmet_kodu'] . ']',
                    'urun_hizmet_id' => (int)$r['urun_hizmet_id'],
                    'stok_kodu'    => $r['urun_hizmet_kodu'],
                    'urun_adi'     => $r['urun_hizmet_adi'],
                    'model'        => $r['urun_hizmet_model'] ?? '',
                    'birim'        => $r['urun_hizmet_birim'] ?: 'ADET',
                    'kdv_oran'     => (float)$r['kdv_oran'],
                    'stok'         => (float)$r['stok'],
                ], $rows);

                echo json_encode(['results' => $results]);
                exit;

            case 'get_doviz_kurlari':
                // Kur fatura TARIHINE gore belirlenir: VUK geregi fatura tarihinden bir
                // onceki gunun TCMB kuru gecerlidir. Eskiden today.xml cekiliyordu; o dosya
                // TCMB'nin 15:30 yayinindan sonra bugunun kurunu donduruyor ve ayni gun
                // icinde saate gore farkli kur olusuyordu (bkz. destek talebi #264).
                $kurTarihi = trim($_POST['tarih'] ?? date('Y-m-d'));

                $kurHelper = new DovizKuruHelper($db);
                $dolar = $kurHelper->faturaKuruGetir($kurTarihi, 'USD', $user['kullanici_id']);
                $euro  = $kurHelper->faturaKuruGetir($kurTarihi, 'EUR', $user['kullanici_id']);

                if ($dolar === null && $euro === null) {
                    echo json_encode(['success' => false, 'message' => 'Fatura tarihine ait TCMB kuru bulunamadı.']);
                    exit;
                }

                echo json_encode([
                    'success' => true,
                    'usd'     => $dolar,
                    'eur'     => $euro,
                    'tarih'   => date('d.m.Y', strtotime($kurTarihi)),
                ]);
                exit;

            case 'kaydet':
                $fid = intval($_POST['fatura_id'] ?? 0);
                $tid = intval($_POST['teklif_id'] ?? 0);
                $pzId = intval($_POST['pazaryeri_siparis_id'] ?? 0); // pazaryeri modu
                $cid = intval($_POST['cari_id'] ?? 0);
                $fno = trim($_POST['fatura_no'] ?? '');
                $tarih = trim($_POST['tarih'] ?? date('Y-m-d'));
                $vadeTarih = trim($_POST['vade_tarihi'] ?? '');
                $faturaTipi = trim($_POST['fatura_tipi'] ?? 'SATIS');
                $senaryo = trim($_POST['senaryo'] ?? 'TEMELFATURA');
                $iadeKaynakPost = intval($_POST['iade_kaynak_id'] ?? 0);
                $fiyatFarkiKaynakPost = intval($_POST['fiyat_farki_kaynak_id'] ?? 0);
                $vadeFarkiKaynakPost = intval($_POST['vade_farki_kaynak_id'] ?? 0);
                // İade faturasında TEMELFATURA zorunlu (Türkbelge kılavuzu)
                if ($faturaTipi === 'IADE') {
                    $senaryo = 'TEMELFATURA';
                    if (!$iadeKaynakPost) throw new Exception('İade faturası için kaynak fatura seçilmelidir.');
                }
                $notlar = trim($_POST['notlar'] ?? '');
                // Kur istemciden ALINMAZ: alan salt-okunur olsa da POST edilebilir.
                // Tek dogru kaynak fatura tarihine gore cozulen TCMB kurudur (destek #264).
                $kurHelper = new DovizKuruHelper($db);
                $dolarKur  = (float)($kurHelper->faturaKuruGetir($tarih, 'USD', $user['kullanici_id']) ?? 0);
                $euroKur   = (float)($kurHelper->faturaKuruGetir($tarih, 'EUR', $user['kullanici_id']) ?? 0);
                $iskontoOran = min(100, max(0, floatval(str_replace(',', '.', $_POST['iskonto_oran'] ?? 0))));

                if (!$cid) throw new Exception("Cari seçimi zorunludur.");
                if (!$fno) throw new Exception("Fatura numarası zorunludur.");

                $cariBilgi = $db->fetchOne("SELECT cari_vergi_no, cari_unvan, cari_adi FROM Cari WHERE cari_id = ?", [$cid]);
                if (!$cariBilgi) throw new Exception("Cari bulunamadı.");

                $unvan = $cariBilgi['cari_unvan'] ?: $cariBilgi['cari_adi'];
                $vkn = $cariBilgi['cari_vergi_no'];

                $kalemlerData = json_decode($_POST['kalemler'] ?? '[]', true);
                if (empty($kalemlerData)) throw new Exception("En az bir kalem eklemelisiniz.");

                // Stok Kontrolü (backend)
                $stokHatalari = [];
                foreach ($kalemlerData as $k) {
                    $stokKodu = trim($k['stok_kodu'] ?? '');
                    if (!$stokKodu) continue;
                    $talep = floatval($k['miktar'] ?? 0);
                    $urun = $db->fetchOne("
                        SELECT urun_hizmet_adi, ISNULL(Urun_Stok_Miktari, 0) as stok
                        FROM Urun_Hizmet
                        WHERE urun_hizmet_kodu = ? AND urun_hizmet_durum = 1
                    ", [$stokKodu]);
                    if ($urun && floatval($urun['stok']) < $talep) {
                        $stokHatalari[] = '"' . $urun['urun_hizmet_adi'] . '": Mevcut ' . number_format($urun['stok'], 2, ',', '.') . ', Talep ' . number_format($talep, 2, ',', '.');
                    }
                }
                if (!empty($stokHatalari)) {
                    throw new Exception('Yetersiz stok: ' . implode(' | ', $stokHatalari));
                }

                // Dövizli kalem varsa kur zorunlu kontrolü
                $paraBirimleri = array_column($kalemlerData, 'para_birimi');
                if (in_array('USD', $paraBirimleri) && $dolarKur <= 0) {
                    throw new Exception('USD cinsinden kalem var ancak ' . date('d.m.Y', strtotime($tarih)) . ' tarihine ait TCMB Dolar kuru bulunamadı.');
                }
                if (in_array('EUR', $paraBirimleri) && $euroKur <= 0) {
                    throw new Exception('EUR cinsinden kalem var ancak ' . date('d.m.Y', strtotime($tarih)) . ' tarihine ait TCMB Euro kuru bulunamadı.');
                }

                $netTutar = 0;
                $toplamKdv = 0;

                // Kalem tutarları 2 haneye yuvarlanır; başlık toplamı yuvarlanmış
                // kalemlerden üretilir. Aksi halde DB (decimal 18,2) kalem ve başlığı
                // ayrı ayrı yuvarlar, aradaki kuruş farkı UBL'de negatif
                // AllowanceTotalAmount olarak çıkıp GİB Schematron hatası verir.
                foreach ($kalemlerData as $k) {
                    $m = floatval($k['miktar'] ?? 0);
                    $f = floatval($k['birim_fiyat'] ?? 0);
                    $ko = floatval($k['kdv_oran'] ?? 0);
                    $nt = round($m * $f, 2);
                    $kt = round($nt * ($ko / 100), 2);
                    $netTutar += $nt;
                    $toplamKdv += $kt;
                }
                $netTutar  = round($netTutar, 2);
                $toplamKdv = round($toplamKdv, 2);

                // İskonto uygula
                $iskontoTutar = round($netTutar * ($iskontoOran / 100), 2);
                $netTutar  = round($netTutar - $iskontoTutar, 2);
                $toplamKdv = round($toplamKdv * (1 - $iskontoOran / 100), 2);
                $genelToplam = round($netTutar + $toplamKdv, 2);

                if ($fid > 0) {
                    $db->update('FaturalarGiden', [
                        'FaturalarGiden_FaturaNo' => $fno,
                        'FaturalarGiden_Tarih' => $tarih,
                        'FaturalarGiden_VadeTarihi' => $vadeTarih ?: null,
                        'FaturalarGiden_FaturaTipi' => $faturaTipi,
                        'FaturalarGiden_Senaryo' => $senaryo,
                        'FaturalarGiden_IadeKaynak_id' => $iadeKaynakPost ?: null,
                        'FaturalarGiden_FiyatFarkiKaynak_id' => $fiyatFarkiKaynakPost ?: null,
                        'FaturalarGiden_VadeFarkiKaynak_id' => $vadeFarkiKaynakPost ?: null,
                        'FaturalarGiden_NetTutar' => $netTutar,
                        'FaturalarGiden_ToplamKDV' => $toplamKdv,
                        'FaturalarGiden_Tutar' => $genelToplam,
                        'FaturalarGiden_Notlar' => $notlar,
                        'FaturalarGiden_VKN_TCKN' => $vkn,
                        'FaturalarGiden_Unvan' => $unvan,
                        'FaturalarGiden_DolarKur' => $dolarKur ?: null,
                        'FaturalarGiden_EuroKur'  => $euroKur  ?: null,
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi' => date('Y-m-d H:i:s')
                    ], ['FaturalarGiden_id' => $fid]);
                    
                    $db->query("DELETE FROM FaturalarGidenKalemleri WHERE FaturalarGidenKalemleri_Fatura_id = ?", [$fid]);
                } else {
                    $db->insert('FaturalarGiden', [
                        'FaturalarGiden_FaturaNo' => $fno,
                        'FaturalarGiden_Teklif_id' => $tid ?: null,
                        'FaturalarGiden_Cari_id' => $cid,
                        'FaturalarGiden_Tarih' => $tarih,
                        'FaturalarGiden_VadeTarihi' => $vadeTarih ?: null,
                        'FaturalarGiden_FaturaTipi' => $faturaTipi,
                        'FaturalarGiden_Senaryo' => $senaryo,
                        'FaturalarGiden_IadeKaynak_id' => $iadeKaynakPost ?: null,
                        'FaturalarGiden_FiyatFarkiKaynak_id' => $fiyatFarkiKaynakPost ?: null,
                        'FaturalarGiden_VadeFarkiKaynak_id' => $vadeFarkiKaynakPost ?: null,
                        'FaturalarGiden_NetTutar' => $netTutar,
                        'FaturalarGiden_ToplamKDV' => $toplamKdv,
                        'FaturalarGiden_Tutar' => $genelToplam,
                        'FaturalarGiden_ParaBirimi' => 'TRY',
                        'FaturalarGiden_Durum' => 'Taslak',
                        'FaturalarGiden_Notlar' => $notlar,
                        'FaturalarGiden_VKN_TCKN' => $vkn,
                        'FaturalarGiden_Unvan' => $unvan,
                        'FaturalarGiden_DolarKur' => $dolarKur ?: null,
                        'FaturalarGiden_EuroKur'  => $euroKur  ?: null,
                        'OlusturanKullanici' => $user['kullanici_id'],
                        'OlusturmaTarihi' => date('Y-m-d H:i:s'),
                    ]);
                    $fid = $db->fetchOne("SELECT TOP 1 FaturalarGiden_id FROM FaturalarGiden ORDER BY FaturalarGiden_id DESC")['FaturalarGiden_id'];
                    // BIT kolonu PDO uyumluluğu — INSERT sonrası garanti
                    $db->execute("UPDATE FaturalarGiden SET Durum = 1 WHERE FaturalarGiden_id = ?", [$fid]);
                }

                // Kalemleri Ekle
                $sira = 1;
                foreach ($kalemlerData as $k) {
                    $m = floatval($k['miktar'] ?? 0);
                    $f = floatval($k['birim_fiyat'] ?? 0);
                    $ko = floatval($k['kdv_oran'] ?? 0);
                    // Başlık toplamıyla birebir tutması için aynı yuvarlama kuralı
                    $nt = round($m * $f, 2);
                    $kt = round($nt * ($ko / 100), 2);
                    $gt = round($nt + $kt, 2);

                    $urunHizmetId = (int)($k['urun_hizmet_id'] ?? 0);
                    // Stok kodu varsa UrunHizmet_id'yi yedek olarak DB'den de bul
                    if (!$urunHizmetId && !empty($k['stok_kodu'])) {
                        $uh = $db->fetchOne("SELECT urun_hizmet_id FROM Urun_Hizmet WHERE urun_hizmet_kodu = ? AND urun_hizmet_durum = 1", [trim($k['stok_kodu'])]);
                        if ($uh) $urunHizmetId = (int)$uh['urun_hizmet_id'];
                    }

                    $db->insert('FaturalarGidenKalemleri', [
                        'FaturalarGidenKalemleri_Fatura_id'    => $fid,
                        'FaturalarGidenKalemleri_Sira'          => $sira++,
                        'FaturalarGidenKalemleri_UrunHizmet_id' => $urunHizmetId ?: null,
                        'FaturalarGidenKalemleri_StokKodu'      => $k['stok_kodu'] ?? '',
                        'FaturalarGidenKalemleri_UrunAdi'       => $k['urun_adi'] ?? '',
                        'FaturalarGidenKalemleri_Miktar' => $m,
                        'FaturalarGidenKalemleri_Birim' => (function($b) {
                            $paraBirimiKodlari = ['TRY','USD','EUR','GBP','CHF','JPY','TL'];
                            return in_array(strtoupper(trim($b)), $paraBirimiKodlari) ? 'ADET' : ($b ?: 'ADET');
                        })($k['birim'] ?? 'ADET'),
                        'FaturalarGidenKalemleri_BirimFiyat' => $f,
                        'FaturalarGidenKalemleri_KDVOrani' => $ko,
                        'FaturalarGidenKalemleri_KDVTutar' => $kt,
                        'FaturalarGidenKalemleri_NetTutar' => $nt,
                        'FaturalarGidenKalemleri_GToplamTutar' => $gt,
                        'OlusturanKullanici' => $user['kullanici_id'],
                        'OlusturmaTarihi' => date('Y-m-d H:i:s')
                    ]);
                }

                // PazarYeri siparişini fatura ile ilişkilendir
                if ($pzId > 0) {
                    $db->execute(
                        "UPDATE PazaryeriSiparisleri SET PazaryeriSiparisleri_Fatura_id = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE PazaryeriSiparisleri_id = ?",
                        [$fid, $user['kullanici_id'], $pzId]
                    );
                }

                echo json_encode(['success' => true, 'message' => 'Fatura taslağı kaydedildi.', 'fatura_id' => $fid, 'pazaryeri_siparis_id' => $pzId]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        .kalem-row td { vertical-align: middle; padding: 0.5rem; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">
                    <form id="faturaForm">
                        <input type="hidden" name="action" value="kaydet">
                        <input type="hidden" name="fatura_id" value="<?= $faturaId ?>">
                        <input type="hidden" name="teklif_id" value="<?= $teklifId ?>">
                        <input type="hidden" name="pazaryeri_siparis_id" value="<?= $pazaryeriSiparisId ?>">
                        <input type="hidden" name="iade_kaynak_id" value="<?= $iadeKaynakId ?>">
                        <input type="hidden" name="fiyat_farki_kaynak_id" value="<?= $fiyatFarkiKaynakId ?>">
                        <input type="hidden" name="vade_farki_kaynak_id" value="<?= $vadeFarkiKaynakId ?>">

                        <?php if ($isIadeModu): ?>
                        <div class="alert alert-warning d-flex align-items-center mb-3">
                            <i class="bi bi-arrow-return-left me-2 fs-5"></i>
                            <div>
                                <strong><?= htmlspecialchars($iadeKaynak['FaturalarGelen_FaturaNo']) ?></strong>
                                <?php if (!empty($iadeKaynak['FaturalarGelen_Tarih'])): ?>
                                (<?= htmlspecialchars(date('d.m.Y', strtotime($iadeKaynak['FaturalarGelen_Tarih']))) ?>)
                                <?php endif; ?>
                                numaralı <strong>gelen fatura</strong> için iade faturası düzenleniyor.
                                Gerekmeyen kalemleri satır sonundaki çöp kutusuyla çıkarabilir, miktarları
                                değiştirerek kısmi iade yapabilirsiniz.
                                Fatura GİB'e gönderildiğinde kalemler <span class="badge bg-success">stok çıkışı</span>
                                olarak işlenir.
                            </div>
                        </div>
                        <?php if (empty($kalemler) && !$faturaId): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-3">
                            <i class="bi bi-exclamation-triangle me-2 fs-5"></i>
                            <div>
                                Bu gelen faturanın kalemleri sisteme işlenmemiş (stok eşleştirmesi yapılmamış).
                                İade kalemlerini elle girmeniz gerekiyor. Dilerseniz önce
                                <a href="/Admin/gelen-efaturalar" class="alert-link">Gelen e-Faturalar</a>
                                sayfasından <strong>Eşleştir</strong> işlemini yapıp buraya dönebilirsiniz.
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($isFiyatFarkiModu): ?>
                        <div class="alert alert-info d-flex align-items-center mb-3">
                            <i class="bi bi-cash-coin me-2 fs-5"></i>
                            <div>
                                <strong><?= htmlspecialchars($fiyatFarkiKaynak['FaturalarGelen_FaturaNo']) ?></strong>
                                <?php if (!empty($fiyatFarkiKaynak['FaturalarGelen_Tarih'])): ?>
                                (<?= htmlspecialchars(date('d.m.Y', strtotime($fiyatFarkiKaynak['FaturalarGelen_Tarih']))) ?>)
                                <?php endif; ?>
                                numaralı <strong>gelen fatura</strong> için fiyat farkı faturası düzenleniyor.
                                Belge <span class="badge bg-primary">SATIŞ</span> tipinde kesilir; tek kalem
                                (<strong>Fiyat Farkı</strong>, 1 adet) olarak gelir, fark tutarını
                                <strong>birim fiyat</strong> alanına girmeniz yeterlidir.
                                <?php $ffPb = strtoupper(trim($fiyatFarkiKaynak['FaturalarGelen_ParaBirimi'] ?? 'TRY')) ?: 'TRY'; ?>
                                <?php if (!$faturaId): ?>
                                    <?php if ($ffPb !== 'TRY'): ?>
                                    <br>Kaynak fatura <strong><?= htmlspecialchars($ffPb) ?></strong> cinsinden olduğu için kalem
                                    <strong><?= htmlspecialchars($ffPb) ?></strong> olarak açıldı: fark tutarını döviz alanına girin.
                                    <?php else: ?>
                                    <br>Fark döviz üzerinden belirlendiyse kalemin <strong>para birimini</strong> USD/EUR olarak
                                    değiştirin ve açılan döviz alanına fark tutarını girin.
                                    <?php endif; ?>
                                    TL karşılığı <strong>kaynak faturanın kendi kuruyla</strong> (<?= htmlspecialchars($ffKurEtiket) ?>)
                                    hesaplanır; fatura tarihini değiştirmeniz bu kuru etkilemez.
                                    <?php if ($ffKurKaynagi === 'tcmb'): ?>
                                    <br><span class="text-danger">Kaynak faturada stok girişi yapılmadığı için kayıtlı kur yok;
                                    kaynak fatura tarihine ait TCMB alış kuru kullanıldı.</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($isVadeFarkiModu): ?>
                        <div class="alert alert-info d-flex align-items-center mb-3">
                            <i class="bi bi-cash-coin me-2 fs-5"></i>
                            <div>
                                <strong><?= htmlspecialchars($vadeFarkiKaynak['FaturalarGiden_FaturaNo']) ?></strong>
                                <?php if (!empty($vadeFarkiKaynak['FaturalarGiden_Tarih'])): ?>
                                (<?= htmlspecialchars(date('d.m.Y', strtotime($vadeFarkiKaynak['FaturalarGiden_Tarih']))) ?>)
                                <?php endif; ?>
                                numaralı <strong>giden fatura</strong> için vade farkı faturası düzenleniyor.
                                Belge <span class="badge bg-primary">SATIŞ</span> tipinde kesilir; tek kalem
                                (<strong>Vade Farkı</strong>, 1 adet) olarak gelir, fark tutarını
                                <strong>birim fiyat</strong> alanına girmeniz yeterlidir.
                                <?php $vfPb = strtoupper(trim($vadeFarkiKaynak['FaturalarGiden_ParaBirimi'] ?? 'TRY')) ?: 'TRY'; ?>
                                <?php if (!$faturaId): ?>
                                    <?php if ($vfPb !== 'TRY'): ?>
                                    <br>Kaynak fatura <strong><?= htmlspecialchars($vfPb) ?></strong> cinsinden olduğu için kalem
                                    <strong><?= htmlspecialchars($vfPb) ?></strong> olarak açıldı: fark tutarını döviz alanına girin.
                                    <?php else: ?>
                                    <br>Fark döviz üzerinden belirlendiyse kalemin <strong>para birimini</strong> USD/EUR olarak
                                    değiştirin ve açılan döviz alanına fark tutarını girin.
                                    <?php endif; ?>
                                    TL karşılığı <strong>kaynak faturanın kendi kuruyla</strong> (<?= htmlspecialchars($ffKurEtiket) ?>)
                                    hesaplanır; fatura tarihini değiştirmeniz bu kuru etkilemez.
                                    <?php if ($ffKurKaynagi === 'tcmb'): ?>
                                    <br><span class="text-danger">Kaynak faturada kayıtlı kur yok;
                                    kaynak fatura tarihine ait TCMB alış kuru kullanıldı.</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($pazaryeriSiparis): ?>
                        <div class="alert alert-info d-flex align-items-center mb-3">
                            <i class="bi bi-shop me-2 fs-5"></i>
                            <div>
                                <strong><?= htmlspecialchars($pazaryeriSiparis['Entegrasyon_Kod'] ?? 'Pazaryeri') ?></strong>
                                siparişi #<strong><?= htmlspecialchars($pazaryeriSiparis['ps_siparis_no'] ?? $pazaryeriSiparisId) ?></strong>
                                için fatura oluşturuluyor.
                                Fatura GİB'e gönderilince sipariş durumu otomatik <span class="badge bg-success">Invoiced</span> olacak.
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="card card-primary card-outline mb-4">
                            <div class="card-header">
                                <h3 class="card-title">Fatura Bilgileri</h3>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Cari Seçimi</label>
                                        <select class="form-select" id="cari_id" name="cari_id" required>
                                            <?php if ($cariDetay): ?>
                                            <option value="<?= $cariDetay['cari_id'] ?>" selected>
                                                <?= htmlspecialchars($cariDetay['cari_unvan'] ?: $cariDetay['cari_adi']) ?>
                                            </option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Vergi Dairesi</label>
                                        <input type="text" class="form-control bg-light text-muted" id="cari_vd" readonly tabindex="-1" value="<?= htmlspecialchars($cariDetay['cari_vergi_dairesi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Vergi No / TCKN</label>
                                        <input type="text" class="form-control bg-light text-muted" id="cari_vkn" readonly tabindex="-1" value="<?= htmlspecialchars($cariDetay['cari_vergi_no'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">İl / İlçe</label>
                                        <input type="text" class="form-control bg-light text-muted" id="cari_il_ilce" readonly tabindex="-1" value="<?= htmlspecialchars(trim(($cariDetay['SehirAdi'] ?? '') . ' / ' . ($cariDetay['IlceAdi'] ?? ''), ' /')) ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Adres</label>
                                        <input type="text" class="form-control bg-light text-muted" id="cari_adres" readonly tabindex="-1" value="<?= htmlspecialchars($cariDetay['cari_adres'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="col-md-2 mt-3">
                                        <label class="form-label">Fatura No</label>
                                        <input type="text" class="form-control" name="fatura_no" value="<?= htmlspecialchars($faturaNo) ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tarih</label>
                                        <input type="date" class="form-control" name="tarih" value="<?= htmlspecialchars($fatura['FaturalarGiden_Tarih'] ?? date('Y-m-d')) ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Vade Tarihi</label>
                                        <input type="date" class="form-control" name="vade_tarihi" value="<?= htmlspecialchars($fatura['FaturalarGiden_VadeTarihi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Senaryo</label>
                                        <select class="form-select" name="senaryo" <?= $isIadeModu ? 'disabled' : '' ?>>
                                            <option value="TEMELFATURA" <?= ($fatura['FaturalarGiden_Senaryo'] ?? 'TEMELFATURA') == 'TEMELFATURA' ? 'selected' : '' ?>>TEMELFATURA</option>
                                            <option value="TICARIFATURA" <?= ($fatura['FaturalarGiden_Senaryo'] ?? '') == 'TICARIFATURA' ? 'selected' : '' ?>>TICARIFATURA</option>
                                        </select>
                                        <?php if ($isIadeModu): ?>
                                        <input type="hidden" name="senaryo" value="TEMELFATURA">
                                        <div class="form-text">İade faturasında TEMELFATURA zorunludur.</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Fatura Tipi</label>
                                        <select class="form-select" name="fatura_tipi" <?= $isIadeModu ? 'disabled' : '' ?>>
                                            <option value="SATIS" <?= ($fatura['FaturalarGiden_FaturaTipi'] ?? '') == 'SATIS' ? 'selected' : '' ?>>SATIŞ</option>
                                            <option value="IADE" <?= ($fatura['FaturalarGiden_FaturaTipi'] ?? '') == 'IADE' ? 'selected' : '' ?>>İADE</option>
                                        </select>
                                        <?php if ($isIadeModu): ?>
                                        <input type="hidden" name="fatura_tipi" value="IADE">
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label"><i class="bi bi-currency-dollar text-success"></i> Dolar Kur (₺)</label>
                                        <input type="number" class="form-control bg-light text-muted" id="dolar_kur" name="dolar_kur" step="0.0001" placeholder="0.0000" readonly tabindex="-1" value="<?= htmlspecialchars($fatura['FaturalarGiden_DolarKur'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label"><i class="bi bi-currency-euro text-primary"></i> Euro Kur (₺)</label>
                                        <input type="number" class="form-control bg-light text-muted" id="euro_kur" name="euro_kur" step="0.0001" placeholder="0.0000" readonly tabindex="-1" value="<?= htmlspecialchars($fatura['FaturalarGiden_EuroKur'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2" style="padding-top:32px">
                                        <div class="form-text mb-0" id="kurAciklama">
                                            <i class="bi bi-info-circle"></i>
                                            Kur, fatura tarihinden bir önceki güne ait TCMB alış kurudur; elle değiştirilemez.
                                        </div>
                                    </div>
                                     <div class="col-md-2">
                                        <label class="form-label"><i class="bi bi-percent text-warning"></i> İskonto (%)</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="iskonto_oran" name="iskonto_oran" step="0.01" min="0" max="100" placeholder="0.00" value="0">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Notlar</label>
                                        <textarea class="form-control" id="notlar_input" name="notlar" rows="3"><?= htmlspecialchars($fatura['FaturalarGiden_Notlar'] ?? '') ?></textarea>
                                        <div class="form-text">
                                            <i class="bi bi-info-circle text-info"></i>
                                            Kur bilgisi otomatik olarak <strong>ilk satıra</strong> yazılır. Kendi notunuzu alt satırdan yazın; kur güncellendiğinde yalnızca ilk satır yenilenir, notunuz korunur.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card card-primary card-outline mb-4">
                            <div class="card-header">
                                <h3 class="card-title">Fatura Kalemleri</h3>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0" id="kalemlerTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Stok Kodu</th>
                                                <th>Ürün Adı</th>
                                                <th width="80" class="text-center">Stok</th>
                                                <th width="90">Miktar</th>
                                                <th width="90">Birim</th>
                                                <th width="100">Para Birimi</th>
                                                <th width="140">Birim Fiyat</th>
                                                <th width="80">KDV (%)</th>
                                                <th width="130">Tutar</th>
                                                <th width="50"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($kalemler as $k):
                                                $stok = isset($k['stok_miktari']) ? floatval($k['stok_miktari']) : -1;
                                                $stokKoduVar = !empty($k['FaturalarGidenKalemleri_StokKodu']);
                                                $miktar = floatval($k['FaturalarGidenKalemleri_Miktar'] ?? 1);
                                                if (!$stokKoduVar) { $sc = 'secondary'; $st = '-'; }
                                                elseif ($stok == 0) { $sc = 'danger'; $st = '0'; }
                                                elseif ($stok < $miktar) { $sc = 'warning text-dark'; $st = number_format($stok, 0); }
                                                else { $sc = 'success'; $st = number_format($stok, 0); }
                                                $paraBirimi = $k['para_birimi_kod'] ?? 'TRY';
                                                $orijinalFiyat = floatval($k['orijinal_fiyat'] ?? $k['FaturalarGidenKalemleri_BirimFiyat'] ?? 0);
                                                $pbRenk = $paraBirimi === 'USD' ? 'success' : ($paraBirimi === 'EUR' ? 'primary' : 'secondary');
                                            ?>
                                            <tr class="kalem-row"
                                                data-para-birimi="<?= htmlspecialchars($paraBirimi) ?>"
                                                data-orijinal-fiyat="<?= $orijinalFiyat ?>"
                                                <?php if (!empty($k['kaynak_kur_usd']) || !empty($k['kaynak_kur_eur'])): ?>
                                                data-kaynak-kur-usd="<?= floatval($k['kaynak_kur_usd'] ?? 0) ?>"
                                                data-kaynak-kur-eur="<?= floatval($k['kaynak_kur_eur'] ?? 0) ?>"
                                                data-kaynak-kur-etiket="<?= htmlspecialchars($k['kaynak_kur_etiket'] ?? '') ?>"
                                                <?php endif; ?>
                                                data-trendyol-fiyat="<?= floatval($k['trendyol_fiyat_dahil'] ?? 0) ?>">
                                                <input type="hidden" class="k-urun-id" value="<?= intval($k['FaturalarGidenKalemleri_UrunHizmet_id'] ?? 0) ?>">
                                                <td><input type="text" class="form-control k-kod" value="<?= htmlspecialchars($k['FaturalarGidenKalemleri_StokKodu'] ?? '') ?>"></td>
                                                <td>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control k-ad" value="<?= htmlspecialchars($k['FaturalarGidenKalemleri_UrunAdi'] ?? '') ?>">
                                                        <button type="button" class="btn btn-outline-secondary btn-urun-ara" title="Stoktan Seç"><i class="bi bi-search"></i></button>
                                                    </div>
                                                </td>
                                                <td class="text-center"><span class="badge bg-<?= $sc ?> k-stok-badge" data-stok="<?= $stok ?>"><?= $st ?></span></td>
                                                <td><input type="number" class="form-control k-miktar" value="<?= floatval($k['FaturalarGidenKalemleri_Miktar'] ?? 1) ?>" step="0.01"></td>
                                                <td><input type="text" class="form-control k-birim" value="<?= htmlspecialchars($k['FaturalarGidenKalemleri_Birim'] ?? 'ADET') ?>"></td>
                                                <td>
                                                    <select class="form-select k-pb">
                                                        <?php foreach ($paraBirimleri as $pb): ?>
                                                        <option value="<?= htmlspecialchars($pb['ParaBirimi_Kod']) ?>" <?= $paraBirimi == $pb['ParaBirimi_Kod'] ? 'selected' : '' ?>><?= htmlspecialchars($pb['ParaBirimi_Kod']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <?php if (!empty($k['doviz_giris'])): ?>
                                                <td>
                                                    <div class="input-group input-group-sm mb-1 k-doviz-wrap" style="<?= $paraBirimi === 'TRY' ? 'display:none' : '' ?>">
                                                        <span class="input-group-text k-doviz-etiket"><?= htmlspecialchars($paraBirimi === 'TRY' ? 'USD' : $paraBirimi) ?></span>
                                                        <input type="number" class="form-control k-doviz-fiyat" value="" step="0.0001" placeholder="Fark tutarı" title="Fark tutarını döviz cinsinden girin">
                                                    </div>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">TL</span>
                                                        <input type="number" class="form-control k-fiyat" value="0" step="0.01" title="<?= $paraBirimi === 'TRY' ? 'Fark tutarı' : 'Kaynak faturanın kuruyla hesaplanır' ?>" <?= $paraBirimi === 'TRY' ? '' : 'readonly' ?>>
                                                    </div>
                                                    <small class="text-muted d-block mt-1 k-kur-bilgi"></small>
                                                </td>
                                                <?php else: ?>
                                                <td><input type="number" class="form-control k-fiyat" value="<?= floatval($k['FaturalarGidenKalemleri_BirimFiyat'] ?? 0) ?>" step="0.0001" title="Birim Fiyat"></td>
                                                <?php endif; ?>
                                                <td><input type="number" class="form-control k-kdv" value="<?= floatval($k['FaturalarGidenKalemleri_KDVOrani'] ?? 20) ?>" step="1" disabled></td>
                                                <td class="text-end k-tutar fw-bold pt-3">0.00</td>
                                                <td class="text-center pt-2"><button type="button" class="btn btn-sm btn-danger btn-sil"><i class="bi bi-trash"></i></button></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($kalemler)): ?>
                                            <tr class="kalem-row" data-para-birimi="TRY" data-orijinal-fiyat="0">
                                                <input type="hidden" class="k-urun-id" value="0">
                                                <td><input type="text" class="form-control k-kod"></td>
                                                <td>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control k-ad">
                                                        <button type="button" class="btn btn-outline-secondary btn-urun-ara" title="Stoktan Seç"><i class="bi bi-search"></i></button>
                                                    </div>
                                                </td>
                                                <td class="text-center"><span class="badge bg-secondary k-stok-badge" data-stok="-1">-</span></td>
                                                <td><input type="number" class="form-control k-miktar" value="1" step="0.01"></td>
                                                <td><input type="text" class="form-control k-birim" value="ADET"></td>
                                                <td>
                                                    <select class="form-select k-pb">
                                                        <?php foreach ($paraBirimleri as $pb): ?>
                                                        <option value="<?= htmlspecialchars($pb['ParaBirimi_Kod']) ?>" <?= $pb['ParaBirimi_Kod'] == 'TRY' ? 'selected' : '' ?>><?= htmlspecialchars($pb['ParaBirimi_Kod']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><input type="number" class="form-control k-fiyat" value="0" step="0.0001"></td>
                                                <td><input type="number" class="form-control k-kdv" value="20" step="1" disabled></td>
                                                <td class="text-end k-tutar fw-bold pt-3">0.00</td>
                                                <td class="text-center pt-2"><button type="button" class="btn btn-sm btn-danger btn-sil"><i class="bi bi-trash"></i></button></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="8" class="text-end fw-bold">Ara Toplam:</td>
                                                <td class="text-end fw-bold" id="top_ara">0.00</td>
                                                <td></td>
                                            </tr>
                                            <tr class="text-danger" id="row_iskonto" style="display:none">
                                                <td colspan="8" class="text-end fw-bold">İskonto (<span id="iskonto_goster">0</span>%):</td>
                                                <td class="text-end fw-bold" id="top_iskonto">0.00</td>
                                                <td></td>
                                            </tr>
                                            <tr>
                                                <td colspan="8" class="text-end fw-bold">KDV Toplam:</td>
                                                <td class="text-end fw-bold" id="top_kdv">0.00</td>
                                                <td></td>
                                            </tr>
                                            <tr class="table-light">
                                                <td colspan="8" class="text-end fw-bold fs-5">Genel Toplam:</td>
                                                <td class="text-end fw-bold fs-5 text-primary" id="top_genel">0.00</td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer">
                                <button type="button" class="btn btn-success btn-sm" id="btnYeniSatir"><i class="bi bi-plus-lg"></i> Yeni Satır Ekle</button>
                            </div>
                        </div>

                        <div class="text-end d-flex justify-content-between align-items-center">
                            <div>
                                <?php if ($faturaId > 0 && ($fatura['FaturalarGiden_Durum'] ?? '') === 'Taslak'): ?>
                                <?php if ($pagePermissions['can_delete'] ?? false): ?>
                                <button type="button" class="btn btn-purple" id="btnFaturalastir" style="background:#7b2ff7;color:#fff">
                                    <i class="bi bi-send-check"></i> GİB'e Gönder
                                </button>
                                <button type="button" class="btn btn-warning ms-2 text-dark" id="btnTestFaturalastir">
                                    <i class="bi bi-bug"></i> TEST GİB'e Gönder
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-secondary" id="btnFaturalastir" disabled
                                    title="GİB'e gönderme işlemi için yetkiniz bulunmamaktadır"
                                    data-bs-toggle="tooltip" data-bs-placement="top">
                                    <i class="bi bi-lock"></i> Faturalaştır (GİB'e Gönder)
                                </button>
                                <?php endif; ?>
                                <?php elseif ($faturaId > 0 && ($fatura['FaturalarGiden_Durum'] ?? '') !== 'Taslak'): ?>
                                <span class="badge bg-success fs-6 p-2">
                                    <i class="bi bi-check-circle"></i>
                                    <?= htmlspecialchars($fatura['FaturalarGiden_GibDurum'] ?? 'Kesildi') ?> —
                                    UUID: <code class="text-white"><?= htmlspecialchars($fatura['FaturalarGiden_UUID'] ?? '-') ?></code>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <a href="/Admin/giden-efaturalar" class="btn btn-secondary me-2">İptal</a>
                                <button type="submit" class="btn btn-primary" id="btnKaydet"><i class="bi bi-save"></i> Taslak Olarak Kaydet</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/Admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    $(document).ready(function() {
        $('#cari_id').select2({
            theme: 'bootstrap-5',
            ajax: {
                url: '/Admin/pages/teklif-form.php', // Cari arama aynı yerde
                type: 'POST',
                dataType: 'json',
                delay: 250,
                data: function(params) { return { action: 'cari_ara', q: params.term }; },
                processResults: function(data) { return { results: data.results }; }
            }
        }).on('select2:select', function(e) {
            var data = e.params.data;
            $('#cari_vd').val(data.cari_vergi_dairesi || '');
            $('#cari_vkn').val(data.cari_vergi_no || '');
            $('#cari_adres').val(data.cari_adres || '');
            
            var ilIlce = '';
            if (data.SehirAdi) ilIlce += data.SehirAdi;
            if (data.IlceAdi) ilIlce += (ilIlce ? ' / ' : '') + data.IlceAdi;
            $('#cari_il_ilce').val(ilIlce);
        });

        const pbOptions = <?= json_encode(array_column($paraBirimleri ?? [], 'ParaBirimi_Kod'), JSON_UNESCAPED_UNICODE) ?>;
        function buildPbSelect(secilenKod) {
            return pbOptions.map(k => `<option value="${k}"${k === secilenKod ? ' selected' : ''}>${k}</option>`).join('');
        }

        function hesapla() {
            let ara = 0, kdv = 0;
            const iskonto = parseFloat($('#iskonto_oran').val()) || 0;

            $('.kalem-row').each(function() {
                const m = parseFloat($(this).find('.k-miktar').val()) || 0;
                const f = parseFloat($(this).find('.k-fiyat').val()) || 0;
                const ko = parseFloat($(this).find('.k-kdv').val()) || 0;

                const tutar = m * f;
                const kdvTutari = tutar * (ko/100);

                $(this).find('.k-tutar').text((tutar + kdvTutari).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}));

                ara += tutar;
                kdv += kdvTutari;
            });

            const iskontoTutar = ara * (iskonto / 100);
            const netAra = ara - iskontoTutar;
            const kdvSonIskonto = kdv * (1 - iskonto / 100);
            const genel = netAra + kdvSonIskonto;

            $('#top_ara').text(ara.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}));
            $('#top_kdv').text(kdvSonIskonto.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}));
            $('#top_genel').text(genel.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}));

            if (iskonto > 0) {
                $('#iskonto_goster').text(iskonto.toFixed(2));
                $('#top_iskonto').text('-' + iskontoTutar.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}));
                $('#row_iskonto').show();
            } else {
                $('#row_iskonto').hide();
            }
        }

        $(document).on('input', '.k-miktar, .k-fiyat, .k-kdv', hesapla);
        $(document).on('input', '#iskonto_oran', hesapla);
        $(document).on('input', '.k-miktar', function() { stokBadgeGuncelle($(this).closest('tr')); });

        // Kur alanları salt-okunur; değişim yalnızca kurleriGetir() üzerinden olur.

        // Fiyat farkı modunda çevrim kaynak faturanın kuruyla yapılır; not satırı da onu yazar.
        const ffKaynakKur = <?= ($isFiyatFarkiModu || $isVadeFarkiModu) && !$faturaId
            ? json_encode(['usd' => $ffKaynakKurUsd, 'eur' => $ffKaynakKurEur, 'etiket' => $ffKurEtiket])
            : 'null' ?>;

        // Notlara kur bilgisi ekle/güncelle
        function kurNotGuncelle() {
            let dolar = parseFloat($('#dolar_kur').val()) || 0;
            let euro  = parseFloat($('#euro_kur').val())  || 0;
            let kurEki = '';
            if (ffKaynakKur && (ffKaynakKur.usd > 0 || ffKaynakKur.eur > 0)) {
                dolar = ffKaynakKur.usd || dolar;
                euro  = ffKaynakKur.eur || euro;
                kurEki = ffKaynakKur.etiket ? ' (' + ffKaynakKur.etiket + ')' : '';
            }
            if (!dolar && !euro) return;

            let kurBilgi = 'Kur: ';
            if (dolar > 0) kurBilgi += '1 USD = ' + dolar.toFixed(4) + ' TL';
            if (euro  > 0) kurBilgi += (dolar > 0 ? ' | ' : '') + '1 EUR = ' + euro.toFixed(4) + ' TL';
            kurBilgi += kurEki;

            const notlarEl = $('#notlar_input');
            const mevcutNot = notlarEl.val();
            // Önceki otomatik kur bilgisini çıkar, kullanıcı notunu koru
            let temizNot = mevcutNot;
            if (/^Kur:/i.test(temizNot)) {
                const satirSonu = temizNot.indexOf('\n');
                if (satirSonu >= 0) {
                    // Yeni format: kur bilgisi ilk satırda, kullanıcı notu sonraki satırlarda
                    temizNot = temizNot.slice(satirSonu + 1);
                } else {
                    // Eski format: "Kur: ... - kullanıcı notu" tek satırda
                    temizNot = temizNot.replace(/^Kur:.*?(TL)(\s*\|\s*1 EUR = [\d.]+ TL)?(\s*\([^)]*\))?(\s*-\s*)?/i, '');
                }
            }
            temizNot = temizNot.trim();
            notlarEl.val(kurBilgi + (temizNot ? '\n' + temizNot : ''));
        }

        // Kuru fatura tarihine göre çek. Kur artık elle girilmez; fatura tarihinden
        // bir önceki güne ait TCMB alış kuru geçerlidir (destek talebi #264).
        const aciklamaEl = $('#kurAciklama');
        const aciklamaVarsayilan = aciklamaEl.html();

        function kurlariGetir() {
            const tarih = $('input[name="tarih"]').val();
            if (!tarih) return;

            aciklamaEl.html('<i class="bi bi-hourglass-split"></i> Kur alınıyor...');

            $.post('', { action: 'get_doviz_kurlari', tarih: tarih }, function(res) {
                if (res.success) {
                    $('#dolar_kur').val(res.usd || '');
                    $('#euro_kur').val(res.eur  || '');
                    kurUygula();
                    kurNotGuncelle();
                    hesapla();
                    aciklamaEl.html('<i class="bi bi-check-circle text-success"></i> ' + res.tarih +
                                    ' tarihli faturada geçerli TCMB alış kuru uygulandı.');
                } else {
                    aciklamaEl.html('<i class="bi bi-exclamation-triangle text-warning"></i> ' + res.message);
                }
            }, 'json').fail(function() {
                aciklamaEl.html('<i class="bi bi-exclamation-triangle text-danger"></i> Kur alınamadı, sunucuya ulaşılamadı.');
            });
        }

        // Fatura tarihi değişince kur yeniden belirlenir
        $('input[name="tarih"]').on('change', kurlariGetir);

        // Kaydedilmiş kuru olmayan faturada (yeni kayıt dahil) açılışta bir kez çek
        if (!parseFloat($('#dolar_kur').val()) && !parseFloat($('#euro_kur').val())) {
            kurlariGetir();
        } else {
            aciklamaEl.html(aciklamaVarsayilan);
        }

        function kurUygula() {
            const dolarKur = parseFloat($('#dolar_kur').val()) || 0;
            const euroKur  = parseFloat($('#euro_kur').val())  || 0;
            $('.kalem-row').each(function() {
                const pb = $(this).find('.k-pb').val() || 'TRY';
                const orjFiyat = parseFloat($(this).data('orijinal-fiyat')) || 0;
                // Fiyat farkı kaleminde çevrim kaynak faturanın kendi kuruyla yapılır;
                // fatura tarihi değişse de bu kur sabit kalır.
                const kaynakUsd = parseFloat($(this).attr('data-kaynak-kur-usd')) || 0;
                const kaynakEur = parseFloat($(this).attr('data-kaynak-kur-eur')) || 0;
                const kullanUsd = kaynakUsd > 0 ? kaynakUsd : dolarKur;
                const kullanEur = kaynakEur > 0 ? kaynakEur : euroKur;
                // Fiyat farkı kaleminde TL karşılığı kuruşa yuvarlanır (2 hane)
                const basamak = $(this).find('.k-doviz-fiyat').length ? 2 : 4;
                if (pb === 'USD' && kullanUsd > 0 && orjFiyat > 0) {
                    $(this).find('.k-fiyat').val((orjFiyat * kullanUsd).toFixed(basamak));
                } else if (pb === 'EUR' && kullanEur > 0 && orjFiyat > 0) {
                    $(this).find('.k-fiyat').val((orjFiyat * kullanEur).toFixed(basamak));
                }
                kurBilgiGoster($(this), pb, pb === 'USD' ? kullanUsd : (pb === 'EUR' ? kullanEur : 0));
            });
        }

        // Fiyat farkı kaleminde çevrimde kullanılan kuru satır altında göster
        function kurBilgiGoster(tr, pb, kur) {
            const bilgi = tr.find('.k-kur-bilgi');
            if (!bilgi.length) return;
            if (pb === 'TRY' || !kur) { bilgi.text(''); return; }
            const etiket = tr.attr('data-kaynak-kur-etiket') || 'güncel kur';
            bilgi.text('1 ' + pb + ' = ' + kur.toFixed(4) + ' TL (' + etiket + ')');
        }

        // Fiyat farkı kaleminde para birimi TRY dışına alınırsa fark döviz olarak girilir
        function dovizAlaniGuncelle(tr) {
            const wrap = tr.find('.k-doviz-wrap');
            if (!wrap.length) return;
            const pb = tr.find('.k-pb').val() || 'TRY';
            if (pb === 'TRY') {
                wrap.hide();
                tr.find('.k-doviz-fiyat').val('');
                tr.attr('data-orijinal-fiyat', 0).data('orijinal-fiyat', 0);
                tr.find('.k-fiyat').prop('readonly', false).attr('title', 'Fark tutarı');
            } else {
                wrap.show();
                tr.find('.k-doviz-etiket').text(pb);
                tr.find('.k-fiyat').prop('readonly', true).attr('title', 'Kaynak faturanın kuruyla hesaplanır');
            }
        }

        $(document).on('change', '.k-pb', function() {
            dovizAlaniGuncelle($(this).closest('tr'));
            kurUygula();
            hesapla();
        });

        // Dövizli fiyat farkı kalemi: girilen döviz tutarı satırın orijinal fiyatı olur,
        // TL karşılığı fatura tarihinin TCMB kuruyla hesaplanır (tarih değişince yenilenir).
        $(document).on('input', '.k-doviz-fiyat', function() {
            const tr  = $(this).closest('tr');
            const val = parseFloat($(this).val()) || 0;
            tr.attr('data-orijinal-fiyat', val);
            tr.data('orijinal-fiyat', val);
            if (val <= 0) tr.find('.k-fiyat').val(0);
            kurUygula();
            hesapla();
        });

        // Sayfa açılışında kur varsa uygula
        kurUygula();

        function stokBadgeGuncelle(row) {
            const badge = row.find('.k-stok-badge');
            if (!badge.length) return;
            const stok = parseFloat(badge.data('stok'));
            if (stok < 0) return;
            const miktar = parseFloat(row.find('.k-miktar').val()) || 0;
            badge.removeClass('bg-success bg-warning bg-danger text-dark');
            if (stok === 0) badge.addClass('bg-danger');
            else if (stok < miktar) badge.addClass('bg-warning text-dark');
            else badge.addClass('bg-success');
        }

        // Sayfa açılışında mevcut satırları kontrol et
        $('.kalem-row').each(function() { stokBadgeGuncelle($(this)); });

        $('#btnYeniSatir').click(function() {
            const secilenPb = 'TRY';
            const tr = `
                <tr class="kalem-row" data-para-birimi="TRY" data-orijinal-fiyat="0">
                    <input type="hidden" class="k-urun-id" value="0">
                    <td><input type="text" class="form-control k-kod"></td>
                    <td>
                        <div class="input-group">
                            <input type="text" class="form-control k-ad">
                            <button type="button" class="btn btn-outline-secondary btn-urun-ara" title="Stoktan Seç"><i class="bi bi-search"></i></button>
                        </div>
                    </td>
                    <td class="text-center"><span class="badge bg-secondary k-stok-badge" data-stok="-1">-</span></td>
                    <td><input type="number" class="form-control k-miktar" value="1" step="0.01"></td>
                    <td><input type="text" class="form-control k-birim" value="ADET"></td>
                    <td><select class="form-select k-pb">${buildPbSelect(secilenPb)}</select></td>
                    <td><input type="number" class="form-control k-fiyat" value="0" step="0.0001"></td>
                    <td><input type="number" class="form-control k-kdv" value="20" step="1" disabled></td>
                    <td class="text-end k-tutar fw-bold pt-3">0.00</td>
                    <td class="text-center pt-2"><button type="button" class="btn btn-sm btn-danger btn-sil"><i class="bi bi-trash"></i></button></td>
                </tr>`;
            $('#kalemlerTable tbody').append(tr);
            hesapla();
        });

        $(document).on('click', '.btn-sil', function() {
            if ($('.kalem-row').length > 1) {
                $(this).closest('tr').remove();
                hesapla();
            }
        });

        // ─── Stok kodu blur → ürün adı/KDV otomatik doldur ───
        $(document).on('blur', '.k-kod', function() {
            const kod = $(this).val().trim();
            if (!kod) return;
            const row = $(this).closest('tr');
            if (row.find('.k-ad').val()) return; // Zaten doluysa dokunma
            $.post('', { action: 'urun_ara', q: kod }, function(r) {
                if (!r.results || !r.results.length) return;
                const u = r.results[0];
                if (u.stok_kodu !== kod) return; // Tam eşleşme değilse geçme
                row.find('.k-urun-id').val(u.urun_hizmet_id || 0);
                row.find('.k-ad').val(u.urun_adi);
                row.find('.k-kdv').val(u.kdv_oran);
                row.find('.k-birim').val(u.birim);
                const badge = row.find('.k-stok-badge');
                badge.data('stok', u.stok).text(u.stok >= 0 ? u.stok : '-');
                badge.removeClass('bg-secondary bg-success bg-warning bg-danger text-dark');
                if (u.stok <= 0) badge.addClass('bg-danger');
                else badge.addClass('bg-success');
                hesapla();
            }, 'json');
        });

        // ─── Ürün Adı 🔍 butonuna tıklayınca arama modal ───
        $(document).on('click', '.btn-urun-ara', function() {
            const row      = $(this).closest('tr');
            const mevcutAd = row.find('.k-ad').val().trim();

            function urunAra(q) {
                const sadece_stok = $('#swal-sadece-stok').is(':checked') ? '1' : '0';
                const liste = $('#swal-urun-liste');
                liste.html('<div class="list-group-item text-muted small">Aranıyor...</div>');
                $.post('', { action: 'urun_ara', q, sadece_stok }, function(r) {
                    liste.empty();
                    if (!r.results || !r.results.length) {
                        liste.html('<div class="list-group-item text-muted small">Sonuç bulunamadı.</div>');
                        return;
                    }
                    r.results.forEach(u => {
                        const stokBadge = u.stok > 0
                            ? `<span class="badge bg-success ms-1">${u.stok}</span>`
                            : `<span class="badge bg-danger ms-1">0</span>`;
                        const modelHtml = u.model ? `<div class="text-muted small">${u.model}</div>` : '';
                        $('<button type="button" class="list-group-item list-group-item-action">')
                            .html(`<div class="d-flex justify-content-between align-items-center">
                                <div>${u.urun_adi}${modelHtml}</div>
                                <span class="text-muted small ms-2 text-nowrap">[${u.stok_kodu}] KDV:%${u.kdv_oran}${stokBadge}</span>
                            </div>`)
                            .on('click', function() {
                                row.find('.k-urun-id').val(u.urun_hizmet_id || 0);
                                row.find('.k-kod').val(u.stok_kodu);
                                row.find('.k-ad').val(u.urun_adi);
                                row.find('.k-kdv').val(u.kdv_oran);
                                row.find('.k-birim').val(u.birim);

                                // Trendyol orijinal KDV dahil fiyat varsa → yeni KDV oranıyla yeniden hesapla
                                const trendyolFiyat = parseFloat(row.data('trendyol-fiyat')) || 0;
                                if (trendyolFiyat > 0) {
                                    const yeniBirimFiyat = trendyolFiyat / (1 + u.kdv_oran / 100);
                                    row.find('.k-fiyat').val(yeniBirimFiyat.toFixed(4));
                                }

                                const badge = row.find('.k-stok-badge');
                                badge.data('stok', u.stok).text(u.stok);
                                badge.removeClass('bg-secondary bg-success bg-warning bg-danger text-dark');
                                if (u.stok <= 0) badge.addClass('bg-danger');
                                else badge.addClass('bg-success');
                                hesapla();
                                Swal.close();
                            })
                            .appendTo(liste);
                    });
                }, 'json');
            }

            Swal.fire({
                title: '<i class="bi bi-search me-2"></i>Stoktan Ürün Seç',
                html: `<div class="d-flex align-items-center gap-3 mt-2">
                           <input type="text" id="swal-urun-ara" class="form-control"
                               placeholder="Ürün adı veya stok kodu ile ara..." autocomplete="off"
                               value="${mevcutAd.replace(/"/g, '&quot;')}">
                           <div class="form-check text-nowrap mb-0">
                               <input class="form-check-input" type="checkbox" id="swal-sadece-stok" checked>
                               <label class="form-check-label" for="swal-sadece-stok">Stoktakiler</label>
                           </div>
                       </div>
                       <div id="swal-urun-liste" class="list-group mt-2" style="max-height:320px;overflow-y:auto"></div>`,
                showCancelButton: true,
                showConfirmButton: false,
                cancelButtonText: 'Kapat',
                width: 640,
                didOpen: () => {
                    const el = document.getElementById('swal-urun-ara');
                    el.focus();
                    el.select();
                    urunAra(mevcutAd);
                    let timer;
                    // Arama input
                    $(el).on('input', function() {
                        clearTimeout(timer);
                        timer = setTimeout(() => urunAra($(this).val().trim()), 300);
                    });
                    // Checkbox değişince yeniden ara
                    $('#swal-sadece-stok').on('change', function() {
                        urunAra($('#swal-urun-ara').val().trim());
                    });
                }
            });
        });


        hesapla();

        // Fiyat farkı modunda kaydetme sonrası formda kalınır (GİB'e gönderim için)
        const fiyatFarkiModu = <?= ($isFiyatFarkiModu || $isVadeFarkiModu) ? 'true' : 'false' ?>;

        $('#faturaForm').on('submit', function(e) {
            e.preventDefault();
            
            const kalemler = [];
            $('.kalem-row').each(function() {
                kalemler.push({
                    urun_hizmet_id: parseInt($(this).find('.k-urun-id').val()) || 0,
                    stok_kodu: $(this).find('.k-kod').val(),
                    urun_adi: $(this).find('.k-ad').val(),
                    miktar: $(this).find('.k-miktar').val(),
                    birim: $(this).find('.k-birim').val(),
                    para_birimi: $(this).find('.k-pb').val() || 'TRY',
                    birim_fiyat: $(this).find('.k-fiyat').val(),
                    kdv_oran: $(this).find('.k-kdv').val()
                });
            });

            if (kalemler.length === 0) {
                Swal.fire('Hata', 'En az bir kalem eklemelisiniz', 'warning');
                return;
            }

            // Cari detay validasyonu
            const hatalar = [];
            const vkn = $('#cari_vkn').val().trim();
            const kurumsal = /^\d{10}$/.test(vkn); // VKN = 10 hane → kurumsal

            if (!vkn) hatalar.push("• Vergi No / TCKN");
            // Vergi Dairesi sadece kurumsal (VKN) için zorunlu; TCKN'lilerde gerekmez
            if (kurumsal && !$('#cari_vd').val().trim()) hatalar.push("• Vergi Dairesi");
            if (!$('#cari_adres').val().trim()) hatalar.push("• Adres");

            const ilIlce = $('#cari_il_ilce').val().trim();
            if (!ilIlce || ilIlce === '/' || ilIlce === '') hatalar.push("• İl / İlçe");

            if (hatalar.length > 0) {
                Swal.fire({
                    title: 'Eksik Cari Bilgisi!',
                    html: 'Fatura oluşturabilmek için seçili carinin aşağıdaki bilgileri dolu olmalıdır:<br><br><div class="text-start ms-4 text-danger fw-bold">' + hatalar.join('<br>') + '</div><br>Lütfen Cari Yönetimi sayfasından firmayı düzenleyerek eksikleri tamamlayın.',
                    icon: 'warning'
                });
                return;
            }

            // Stok kontrolü (frontend)
            const stokProblemi = [];
            $('.kalem-row').each(function() {
                const badge = $(this).find('.k-stok-badge');
                if (!badge.length) return;
                const stok = parseFloat(badge.data('stok'));
                if (stok < 0) return;
                const miktar = parseFloat($(this).find('.k-miktar').val()) || 0;
                const ad = $(this).find('.k-ad').val() || 'Kalem';
                if (stok < miktar) stokProblemi.push('• ' + ad + ': Mevcut stok ' + stok + ', Talep ' + miktar);
            });
            if (stokProblemi.length > 0) {
                Swal.fire('Yetersiz Stok!', stokProblemi.join('<br>'), 'error');
                return;
            }

            const data = $(this).serialize() + '&kalemler=' + encodeURIComponent(JSON.stringify(kalemler));
            $('#btnKaydet').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Kaydediliyor...');

            $.post('', data, function(res) {
                if (res.success) {
                    // Yeni fatura ID'sini forma yaz — tekrar kaydet mükerrer oluşturmasın
                    if (res.fatura_id) {
                        $('input[name="fatura_id"]').val(res.fatura_id);
                    }

                    Swal.fire({
                        icon: 'success',
                        title: 'Başarılı!',
                        text: res.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        // Pazaryeri ve fiyat farkı modunda fatura formuna redirect et (ID ile):
                        // taslak kaydedildikten sonra GİB'e Gönder butonu aynı ekranda görünür
                        if ((res.pazaryeri_siparis_id > 0 || fiyatFarkiModu) && res.fatura_id) {
                            window.location.href = `/Admin/fatura-form?id=${res.fatura_id}`;
                        } else {
                            window.location.href = '/Admin/giden-efaturalar';
                        }
                    });
                } else {
                    Swal.fire('Hata', res.message, 'error');
                    $('#btnKaydet').prop('disabled', false).html('<i class="bi bi-save"></i> Taslak Olarak Kaydet');
                }
            }, 'json');
        });

        // Faturalaştır butonu
        const canGonder = <?= ($pagePermissions['can_delete'] ?? false) ? 'true' : 'false' ?>;
        
        function gonder(isTest) {
            if (!canGonder) {
                Swal.fire('Yetki Hatası', 'GİB\'e gönderme işlemi için yetkiniz bulunmamaktadır.', 'warning');
                return;
            }
            const faturaId = $('input[name="fatura_id"]').val();
            if (!faturaId) return;

            const btnText = isTest ? 'TEST GİB\'e Gönder' : 'GİB\'e Gönder';
            const btnColor = isTest ? '#ffc107' : '#7b2ff7';

            Swal.fire({
                title: btnText,
                html: 'Bu fatura <b>Turkbelge üzerinden ' + (isTest ? 'TEST ' : '') + 'GİB\'e</b> gönderilecek.<br>'
                    + 'Alıcının mükellef durumuna göre <b>e-Fatura</b> veya <b>e-Arşiv</b> olarak gönderilir.<br><br>'
                    + (isTest ? '<span class="text-info">Bu test ortamına gidecektir.</span>' : '<span class="text-danger">Bu işlem geri alınamaz!</span>'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: btnColor,
                confirmButtonText: '<i class="bi bi-send-check"></i> Evet, Gönder',
                cancelButtonText: 'İptal'
            }).then(result => {
                if (!result.isConfirmed) return;

                const btn = isTest ? $('#btnTestFaturalastir') : $('#btnFaturalastir');
                const btnOriginalHtml = btn.html();
                btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Gönderiliyor...');

                $.post('', { action: 'faturalastir', fatura_id: faturaId, is_test: isTest ? 1 : 0 }, function(res) {
                    if (res.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Başarılı!',
                            html: res.message + '<br><small class="text-muted">UUID: ' + res.uuid + '</small>',
                            confirmButtonText: 'Tamam'
                        }).then(() => { window.location.reload(); });
                    } else {
                        Swal.fire('Hata', res.message, 'error');
                        btn.prop('disabled', false).html(btnOriginalHtml);
                    }
                }, 'json').fail(function() {
                    Swal.fire('Hata', 'Sunucu hatası oluştu.', 'error');
                    btn.prop('disabled', false).html(btnOriginalHtml);
                });
            });
        }

        $('#btnFaturalastir').on('click', function() { gonder(false); });
        $('#btnTestFaturalastir').on('click', function() { gonder(true); });
    });
    </script>
</body>
</html>
