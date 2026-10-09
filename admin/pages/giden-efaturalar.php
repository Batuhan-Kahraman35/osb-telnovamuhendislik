<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/TurkbelgeAPI.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erisim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Erisim yetkiniz yok.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Giden e-Faturalar';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteTitle = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC")['site_ayarlari_site_title'] ?? 'OSB Portal';

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                $kanal = $db->fetchOne("
                    SELECT ek.Kanal_Id, ek.Kanal_Parametreler
                    FROM EntegrasyonKanallari ek
                    INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                    WHERE e.Entegrasyon_Kod = 'TURKBELGE' AND ek.Kanal_Durum = 1
                    ORDER BY ek.Kanal_Varsayilan DESC
                ");
                $kanalId = (int)($kanal['Kanal_Id'] ?? 0);
                $params  = json_decode($kanal['Kanal_Parametreler'] ?? '{}', true);
                $wsdl    = $params['wsdl_efatura'] ?? 'https://efaturaws.turkbelge.com.tr/EFaturaOIB?wsdl';
                if (strpos($wsdl, 'test') !== false) {
                    $wsdl = str_replace('efaturawstest', 'efaturaws', $wsdl);
                }

                $yenile  = (int)($_POST['yenile'] ?? 0);
                $dbSayisi = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.FaturalarGiden")['s'] ?? 0;

                if ($yenile || $dbSayisi == 0) {
                    $api = new TurkbelgeAPI($db);
                    $api->login();
                    $sessionId = $api->getSessionId();

                    $client = new SoapClient($wsdl, [
                        'trace'          => 1,
                        'exceptions'     => true,
                        'cache_wsdl'     => WSDL_CACHE_NONE,
                        'stream_context' => stream_context_create(['ssl' => [
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true
                        ]])
                    ]);

                    if ($yenile) {
                        // Nihai duruma (SUCCEED/FAILED/CANCELLED) ulaşmamış en eski fatura tarihini bul
                        $minBekleyen = $db->fetchOne("
                            SELECT CONVERT(VARCHAR(10), MIN(FaturalarGiden_Tarih), 120) AS min_tarih
                            FROM dbo.FaturalarGiden
                            WHERE Durum = 1
                              AND (
                                  FaturalarGiden_Durum    NOT IN ('SUCCEED','FAILED','CANCELLED')
                               OR FaturalarGiden_GIBDurum NOT IN ('SUCCEED','FAILED','CANCELLED')
                               OR FaturalarGiden_Durum    IS NULL
                               OR FaturalarGiden_GIBDurum IS NULL
                              )
                        ")['min_tarih'] ?? null;
                        $startDate = $minBekleyen ?: date('Y-m-d', strtotime('-30 days'));
                    } else {
                        $sonTarih  = $db->fetchOne("SELECT CONVERT(VARCHAR(10), MAX(FaturalarGiden_Tarih), 120) AS son FROM dbo.FaturalarGiden")['son'] ?? null;
                        $startDate = $sonTarih ?: date('Y-m-d', strtotime('-30 days'));
                    }

                    $apiResp = $client->GetInvoice([
                        'REQUEST_HEADER'     => ['SESSION_ID' => $sessionId, 'APPLICATION_NAME' => 'ORNEK_PORTAL', 'COMPRESSED' => 'N'],
                        'INVOICE_SEARCH_KEY' => ['LIMIT' => 500, 'DIRECTION' => 'OUT', 'READ_INCLUDED' => true, 'START_DATE' => $startDate],
                        'HEADER_ONLY'        => 'Y'
                    ]);

                    if (isset($apiResp->INVOICE)) {
                        $invoices = is_array($apiResp->INVOICE) ? $apiResp->INVOICE : [$apiResp->INVOICE];
                        foreach ($invoices as $inv) {
                            $header   = $inv->HEADER ?? null;
                            if (!$header) continue;
                            $faturaNo = $inv->ID ?? null;
                            if (!$faturaNo || $faturaNo === '-') continue;
                            
                            // Sadece TLN ön ekli faturaları al
                            if (strtoupper(substr($faturaNo, 0, 3)) !== 'TLN') continue;

                            $tutar = 0;
                            $para  = 'TRY';
                            if (isset($header->PAYABLE_AMOUNT)) {
                                if (is_object($header->PAYABLE_AMOUNT)) {
                                    $tutar = (float)$header->PAYABLE_AMOUNT->_;
                                    $para  = $header->PAYABLE_AMOUNT->currencyID ?? 'TRY';
                                } else {
                                    $tutar = (float)$header->PAYABLE_AMOUNT;
                                }
                            }

                            $tarih = !empty($header->ISSUE_DATE) ? substr($header->ISSUE_DATE, 0, 10) : null;

                            $statusDesc    = mb_substr($header->STATUS_DESCRIPTION ?? '', 0, 100);
                            $gibStatusDesc = mb_substr($header->GIB_STATUS_DESCRIPTION ?? '', 0, 100);

                            // GİB'den çekilen faturada cari bağı yoktur; VKN/TCKN ile eşleştirilir.
                            // Bu alan boş kalırsa fatura odeme-yonetimi ve odeme-form
                            // sayfalarında hiç görünmez (o sayfalar Cari_id üzerinden bağlanır).
                            $senkronCariId = null;
                            $senkronVkn    = trim((string)($header->RECEIVER ?? ''));
                            if ($senkronVkn !== '') {
                                $senkronCari = $db->fetchOne(
                                    "SELECT TOP 1 cari_id FROM dbo.Cari WHERE cari_vergi_no = ? ORDER BY cari_id DESC",
                                    [$senkronVkn]
                                );
                                $senkronCariId = $senkronCari['cari_id'] ?? null;
                            }

                            // Mevcut kayıt var mı kontrol et (FaturaNo veya ETTN ile - silinmiş dahil)
                            $mevcutFatura = $db->fetchOne(
                                "SELECT FaturalarGiden_id, Durum FROM FaturalarGiden WHERE (FaturalarGiden_FaturaNo = ? OR FaturalarGiden_ETTN = ?)",
                                [$faturaNo, $inv->UUID ?? '']
                            );

                            if ($mevcutFatura) {
                                // Mevcut kayıt → Durum ve GIB Durum güncelle
                                $db->query("
                                    UPDATE FaturalarGiden SET
                                        FaturalarGiden_Durum    = ?,
                                        FaturalarGiden_GIBDurum = ?,
                                        FaturalarGiden_ETTN     = ISNULL(FaturalarGiden_ETTN, ?),
                                        FaturalarGiden_Tutar    = CASE WHEN FaturalarGiden_Tutar IS NULL OR FaturalarGiden_Tutar = 0 THEN ? ELSE FaturalarGiden_Tutar END,
                                        FaturalarGiden_Cari_id  = ISNULL(FaturalarGiden_Cari_id, ?),
                                        Durum                   = 1,
                                        GuncelleyenKullanici    = ?,
                                        GuncellemeTarihi        = GETDATE()
                                    WHERE FaturalarGiden_id = ?
                                ", [
                                    $statusDesc,
                                    $gibStatusDesc,
                                    $inv->UUID ?? null,
                                    $tutar,
                                    $senkronCariId,
                                    $user['kullanici_id'],
                                    $mevcutFatura['FaturalarGiden_id']
                                ]);
                            } else {
                                // Yeni kayıt → Insert
                                try {
                                    $db->insert('FaturalarGiden', [
                                        'FaturalarGiden_FaturaNo'   => $faturaNo,
                                        'FaturalarGiden_ETTN'       => $inv->UUID ?? null,
                                        'FaturalarGiden_FaturaTipi' => $header->INVOICE_TYPE_CODE ?? null,
                                        'FaturalarGiden_Senaryo'    => $header->PROFILEID ?? null,
                                        'FaturalarGiden_Tarih'      => $tarih,
                                        'FaturalarGiden_Cari_id'    => $senkronCariId,
                                        'FaturalarGiden_VKN_TCKN'   => $header->RECEIVER ?? null,
                                        'FaturalarGiden_Unvan'      => mb_substr($header->CUSTOMER ?? '', 0, 255),
                                        'FaturalarGiden_Tutar'      => $tutar,
                                        'FaturalarGiden_ParaBirimi' => $para,
                                        'FaturalarGiden_Durum'      => $statusDesc,
                                        'FaturalarGiden_GIBDurum'   => $gibStatusDesc,
                                        'FaturalarGiden_Kanal_id'   => $kanalId > 0 ? $kanalId : null,
                                        'OlusturanKullanici'        => $user['kullanici_id'],
                                        'OlusturmaTarihi'           => date('Y-m-d H:i:s'),
                                        'GuncelleyenKullanici'      => $user['kullanici_id'],
                                        'GuncellemeTarihi'          => date('Y-m-d H:i:s'),
                                        'Durum'                     => 1
                                    ]);
                                } catch (Exception $e) { /* duplicate, atla */ }
                            }
                        }
                    }

                    // E-Arşiv faturaların durumlarını çek (GetEArchiveInvoiceList)
                    $earsivWsdl = $params['wsdl_earsiv'] ?? null;
                    if ($earsivWsdl) {
                        if (strpos($earsivWsdl, 'test') !== false) {
                            $earsivWsdl = str_replace('earsivwstest', 'earsivws', $earsivWsdl);
                        }
                        try {
                            $earsivClient = new SoapClient($earsivWsdl, [
                                'trace'          => 1,
                                'exceptions'     => true,
                                'cache_wsdl'     => WSDL_CACHE_NONE,
                                'stream_context' => stream_context_create(['ssl' => [
                                    'verify_peer'       => false,
                                    'verify_peer_name'  => false,
                                    'allow_self_signed' => true
                                ]])
                            ]);

                            $earsivResp = $earsivClient->GetEArchiveInvoiceList([
                                'REQUEST_HEADER' => ['SESSION_ID' => $sessionId, 'APPLICATION_NAME' => 'ORNEK_PORTAL', 'COMPRESSED' => 'N'],
                                'LIMIT'          => 500,
                                'START_DATE'     => $startDate . 'T00:00:00',
                                'PREFIX'         => 'TLN',
                                'HEADER_ONLY'    => 'Y',
                                'READ_INCLUDED'  => 'Y'
                            ]);

                            if (isset($earsivResp->EARCHIVEINV)) {
                                $earsivFaturalar = is_array($earsivResp->EARCHIVEINV) ? $earsivResp->EARCHIVEINV : [$earsivResp->EARCHIVEINV];
                                foreach ($earsivFaturalar as $eaf) {
                                    $eh = $eaf->HEADER ?? null;
                                    if (!$eh) continue;

                                    $earFaturaNo = $eh->INVOICE_ID ?? null;
                                    $earUuid     = $eh->UUID ?? null;
                                    if (!$earFaturaNo && !$earUuid) continue;

                                    $earStatus     = mb_substr((string)($eh->STATUS      ?? ''), 0, 100);
                                    $earStatusCode = mb_substr((string)($eh->STATUS_CODE  ?? ''), 0, 100);

                                    $mevcutEar = $db->fetchOne(
                                        "SELECT FaturalarGiden_id FROM FaturalarGiden WHERE (FaturalarGiden_FaturaNo = ? OR FaturalarGiden_ETTN = ?)",
                                        [$earFaturaNo, $earUuid ?? '']
                                    );

                                    if ($mevcutEar) {
                                        $db->query("
                                            UPDATE FaturalarGiden SET
                                                FaturalarGiden_Durum    = ?,
                                                FaturalarGiden_GIBDurum = ?,
                                                FaturalarGiden_ETTN     = ISNULL(FaturalarGiden_ETTN, ?),
                                                GuncelleyenKullanici    = ?,
                                                GuncellemeTarihi        = GETDATE()
                                            WHERE FaturalarGiden_id = ?
                                        ", [
                                            $earStatus ?: $earStatusCode,
                                            $earStatusCode ?: $earStatus,
                                            $earUuid,
                                            $user['kullanici_id'],
                                            $mevcutEar['FaturalarGiden_id']
                                        ]);
                                    }
                                }
                            }
                        } catch (Exception $earsivEx) {
                            // E-arşiv sorgusunda hata olsa bile e-fatura verileri dönülmeye devam etsin
                            error_log('E-Arşiv durum güncelleme hatası: ' . $earsivEx->getMessage());
                        }
                    }

                    // GİB İptal/İade Portalı iptallerini yakala (EXTERNAL_CANCEL_FLAG).
                    // Bu bilgi yalnız GetInvoiceStatus yanıtında gelir; GetInvoice (liste) ve
                    // GetInvoiceStatusAll bu alanı döndürmez. Portalden iptal edilen faturada
                    // STATUS/GIB_STATUS "SUCCEED / BAŞARIYLA TAMAMLANDI" olarak kalır.
                    // Fatura başına 1 çağrı olduğundan sorgulanacak küme daraltılır:
                    // e-Arşiv hariç, henüz iptal işaretlenmemiş, son 12 ay, günde bir kez.
                    $durumBekleyenler = $db->fetchAll("
                        SELECT TOP 200 FaturalarGiden_id, FaturalarGiden_ETTN
                        FROM dbo.FaturalarGiden
                        WHERE Durum = 1
                          AND FaturalarGiden_ETTN IS NOT NULL
                          AND ISNULL(FaturalarGiden_Senaryo, '') NOT LIKE '%EARSIV%'
                          AND ISNULL(FaturalarGiden_HariciIptal, 0) = 0
                          AND FaturalarGiden_Tarih >= DATEADD(MONTH, -12, GETDATE())
                          AND (FaturalarGiden_DurumSorguTarihi IS NULL
                               OR FaturalarGiden_DurumSorguTarihi < DATEADD(DAY, -1, GETDATE()))
                        ORDER BY FaturalarGiden_DurumSorguTarihi ASC, FaturalarGiden_Tarih DESC
                    ");

                    foreach ($durumBekleyenler as $bekleyen) {
                        try {
                            $stResp = $client->GetInvoiceStatus([
                                'REQUEST_HEADER' => ['SESSION_ID' => $sessionId, 'APPLICATION_NAME' => 'ORNEK_PORTAL', 'COMPRESSED' => 'N'],
                                'INVOICE'        => ['UUID' => $bekleyen['FaturalarGiden_ETTN']]
                            ]);

                            $st = $stResp->INVOICE_STATUS ?? null;
                            if (!$st) continue;

                            $hariciIptal = strtoupper(trim((string)($st->EXTERNAL_CANCEL_FLAG ?? ''))) === 'Y' ? 1 : 0;
                            $yanitKodu   = mb_substr((string)($st->RESPONSE_CODE        ?? ''), 0, 20);
                            $yanitAcikla = mb_substr((string)($st->RESPONSE_DESCRIPTION ?? ''), 0, 255);

                            $db->query("
                                UPDATE FaturalarGiden SET
                                    FaturalarGiden_HariciIptal      = ?,
                                    FaturalarGiden_YanitKodu        = ?,
                                    FaturalarGiden_YanitAciklama    = ?,
                                    FaturalarGiden_DurumSorguTarihi = GETDATE(),
                                    GuncelleyenKullanici            = ?,
                                    GuncellemeTarihi                = GETDATE()
                                WHERE FaturalarGiden_id = ?
                            ", [
                                $hariciIptal,
                                $yanitKodu ?: null,
                                $yanitAcikla ?: null,
                                $user['kullanici_id'],
                                $bekleyen['FaturalarGiden_id']
                            ]);
                        } catch (Exception $stEx) {
                            // Tek faturanın durum sorgusu hata verse de tur devam etsin
                            error_log('GetInvoiceStatus hatasi (id=' . $bekleyen['FaturalarGiden_id'] . '): ' . $stEx->getMessage());
                        }
                    }
                }

                $silinmisleriGoster = (int)($_POST['silinmisler'] ?? 0);
                $durumFiltre = $silinmisleriGoster ? '1=1' : 'f.Durum = 1';

                if (!empty($pagePermissions['can_view_own_records'])) {
                    $kullaniciId = intval($user['kullanici_id']);
                    // Kendi kayıtları + kendisine devredilen carilerin faturaları.
                    // Fatura-cari eşleşmesi hem cari id hem VKN üzerinden kontrol edilir;
                    // eski kayıtlarda FaturalarGiden_Cari_id boş olabiliyor.
                    $sahiplik = "t.Teklifler_hazirlayan_id = $kullaniciId OR f.OlusturanKullanici = $kullaniciId";
                    $sahiplik = CariDevirHelper::orDevir($sahiplik, $kullaniciId, 'f.FaturalarGiden_Cari_id');
                    $sahiplik = CariDevirHelper::orDevirVkn($sahiplik, $kullaniciId, 'f.FaturalarGiden_VKN_TCKN');
                    $durumFiltre .= " AND ($sahiplik)";
                }

                $faturalar = $db->fetchAll("
                    SELECT
                        f.FaturalarGiden_id         AS id,
                        f.FaturalarGiden_FaturaNo   AS fatura_no,
                        f.FaturalarGiden_ETTN       AS ettn,
                        CONVERT(VARCHAR(10), f.FaturalarGiden_Tarih, 120) AS fatura_tarihi,
                        f.FaturalarGiden_VKN_TCKN   AS vkn_tckn,
                        f.FaturalarGiden_Unvan      AS unvan,
                        f.FaturalarGiden_FaturaTipi AS fatura_tipi,
                        f.FaturalarGiden_Senaryo    AS senaryo,
                        f.FaturalarGiden_Tutar      AS tutar,
                        f.FaturalarGiden_ParaBirimi AS para_birimi,
                        f.FaturalarGiden_Durum      AS durum,
                        f.FaturalarGiden_GIBDurum   AS gib_durum,
                        ISNULL(f.FaturalarGiden_HariciIptal, 0) AS harici_iptal,
                        f.FaturalarGiden_YanitAciklama          AS yanit_aciklama,
                        ISNULL(f.FaturalarGiden_StokIslendi, 0) AS stok_islendi,
                        f.Durum                     AS kayit_durum,
                        c.cari_vergi_dairesi        AS vergi_dairesi,
                        c.cari_id                   AS cari_id,
                        c.cari_unvan                AS cari_unvan,
                        f.FaturalarGiden_IadeKaynak_id     AS iade_kaynak_id,
                        ik.FaturalarGelen_FaturaNo         AS iade_kaynak_no,
                        CASE
                            WHEN f.FaturalarGiden_ParaBirimi = 'USD' THEN sh.DolarKur
                            WHEN f.FaturalarGiden_ParaBirimi = 'EUR' THEN sh.EuroKur
                            ELSE NULL
                        END                         AS kur,
                        pz.pazaryeri_adi            AS pazaryeri_adi,
                        ISNULL(th.personel_adi, fk.personel_adi) AS personel_adi
                    FROM dbo.FaturalarGiden f
                    OUTER APPLY (
                        SELECT TOP 1 cari_vergi_dairesi, cari_id, cari_unvan
                        FROM dbo.Cari
                        WHERE cari_vergi_no = f.FaturalarGiden_VKN_TCKN
                        ORDER BY cari_id DESC
                    ) c
                    LEFT JOIN dbo.FaturalarGelen ik ON ik.FaturalarGelen_id = f.FaturalarGiden_IadeKaynak_id
                    OUTER APPLY (
                        SELECT TOP 1 p.Pazaryerleri_Ad AS pazaryeri_adi
                        FROM dbo.PazaryeriSiparisleri ps
                        INNER JOIN dbo.tanim_Pazaryerleri p ON p.Pazaryerleri_id = ps.Pazaryerleri_id
                        WHERE ps.PazaryeriSiparisleri_Fatura_id = f.FaturalarGiden_id AND ps.Durum = 1
                    ) pz
                    OUTER APPLY (
                        SELECT TOP 1 DolarKur, EuroKur
                        FROM dbo.Urun_Stok_Hareketleri
                        WHERE Fatura_id = f.FaturalarGiden_id AND Islem_Tipi = 'CIKIS' AND Durum = 1
                        ORDER BY Stok_Hareket_id DESC
                    ) sh
                    LEFT JOIN dbo.Teklifler t ON f.FaturalarGiden_Teklif_id = t.Teklifler_id
                    OUTER APPLY (
                        SELECT TOP 1 k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi
                        FROM dbo.kullanicilar k
                        WHERE k.kullanici_id = t.Teklifler_hazirlayan_id
                    ) th
                    OUTER APPLY (
                        SELECT TOP 1 k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi
                        FROM dbo.kullanicilar k
                        WHERE k.kullanici_id = f.OlusturanKullanici
                    ) fk
                    WHERE $durumFiltre AND f.FaturalarGiden_FaturaNo LIKE 'TLN%'
                    ORDER BY f.FaturalarGiden_Tarih DESC, f.FaturalarGiden_id DESC
                ");

                echo json_encode(['success' => true, 'data' => $faturalar]);
                break;

            case 'get_products':
                $urunler = $db->fetchAll("SELECT urun_hizmet_id as Urun_id, urun_hizmet_adi as Urun_Adi, urun_hizmet_kodu as Urun_Kodu, ISNULL(Urun_Stok_Miktari, 0) as Stok_Miktari FROM dbo.Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_adi");
                echo json_encode(['success' => true, 'data' => $urunler]);
                break;

            case 'get_sevk_yetkililer':
                // Sevk çıktısı için: VKN'ye eşleşen cari ve yetkilileri (şehir/ilçe/adres ile) döndür
                $vkn = trim($_POST['vkn'] ?? '');
                if (!$vkn) throw new Exception("VKN/TCKN eksik.");

                $cari = $db->fetchOne("
                    SELECT TOP 1 cari_id, cari_adi, cari_unvan, cari_vergi_no, cari_vergi_dairesi,
                           cari_telefon, cari_adres
                    FROM dbo.Cari WHERE cari_vergi_no = ? ORDER BY cari_id DESC
                ", [$vkn]);

                $yetkililer = [];
                if ($cari) {
                    $yetkililer = $db->fetchAll("
                        SELECT
                            y.CariYetkili_Id,
                            y.CariYetkili_Adi,
                            y.CariYetkili_Soyadi,
                            y.CariYetkili_Telefon,
                            y.CariYetkili_Adres,
                            b.CariBirim_Adi,
                            s.SehirAdi,
                            i.IlceAdi
                        FROM CariYetkili y
                        LEFT JOIN Cari_Birimler b ON y.CariYetkili_BirimId = b.CariBirim_Id
                        LEFT JOIN Adres_Sehirler s ON y.CariYetkili_Sehir = s.SehirId
                        LEFT JOIN Adres_Ilceler i ON y.CariYetkili_Ilce = i.ilceId
                        WHERE y.CariYetkili_CariId = ? AND y.Durum = 1
                        ORDER BY y.CariYetkili_Adi
                    ", [$cari['cari_id']]);
                }

                echo json_encode(['success' => true, 'cari' => $cari, 'yetkililer' => $yetkililer], JSON_UNESCAPED_UNICODE);
                break;

            case 'get_invoice_lines':
                $faturaNo = $_POST['fatura_no'] ?? '';
                if (!$faturaNo) throw new Exception("Fatura numarasi eksik.");
                
                $kanal = $db->fetchOne("SELECT ek.Kanal_Parametreler FROM EntegrasyonKanallari ek INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id WHERE e.Entegrasyon_Kod = 'TURKBELGE' AND ek.Kanal_Durum = 1");
                $params = json_decode($kanal['Kanal_Parametreler'] ?? '{}', true);
                $efaturaWsdl = $params['wsdl_efatura'] ?? 'https://efaturaws.turkbelge.com.tr/EFaturaOIB?wsdl';
                if (strpos($efaturaWsdl, 'test') !== false) $efaturaWsdl = str_replace('efaturawstest', 'efaturaws', $efaturaWsdl);

                $api = new TurkbelgeAPI($db);
                $api->login();
                $sessionId = $api->getSessionId();

                $client = new SoapClient($efaturaWsdl, [
                    'trace' => 1, 'exceptions' => true, 'cache_wsdl' => WSDL_CACHE_NONE,
                    'stream_context' => stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]])
                ]);

                $resp = $client->GetInvoice([
                    'REQUEST_HEADER' => ['SESSION_ID' => $sessionId, 'APPLICATION_NAME' => 'ORNEK_PORTAL', 'COMPRESSED' => 'N'],
                    'INVOICE_SEARCH_KEY' => ['LIMIT' => 1, 'ID' => $faturaNo, 'DIRECTION' => 'OUT', 'READ_INCLUDED' => true, 'CONTENT_TYPE' => 'XML'],
                    'HEADER_ONLY' => 'N'
                ]);

                if (!isset($resp->INVOICE->CONTENT)) throw new Exception("Fatura icerigi alinamadi.");
                
                $xmlString = is_object($resp->INVOICE->CONTENT) ? $resp->INVOICE->CONTENT->_ : $resp->INVOICE->CONTENT;
                if (strpos($xmlString, '<?xml') === false && strpos($xmlString, '<Invoice') === false) {
                    $decoded = base64_decode($xmlString);
                    if ($decoded) $xmlString = $decoded;
                }

                $doc = new DOMDocument();
                $doc->loadXML($xmlString);
                $xpath = new DOMXPath($doc);
                $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
                $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

                $lines = $xpath->query('//cac:InvoiceLine');
                $kalemler = [];
                foreach ($lines as $line) {
                    $isim = $xpath->evaluate('string(cac:Item/cbc:Name)', $line);
                    $miktar = $xpath->evaluate('number(cbc:InvoicedQuantity)', $line);
                    if (is_nan($miktar)) $miktar = 0;
                    
                    $birim = $xpath->evaluate('string(cbc:InvoicedQuantity/@unitCode)', $line);
                    
                    $fiyat = $xpath->evaluate('number(cac:Price/cbc:PriceAmount)', $line);
                    if (is_nan($fiyat)) $fiyat = 0;

                    $paraBirimi = $xpath->evaluate('string(cac:Price/cbc:PriceAmount/@currencyID)', $line);
                    if (!$paraBirimi) {
                        $paraBirimi = $xpath->evaluate('string(cbc:LineExtensionAmount/@currencyID)', $line);
                    }

                    // KDV oranı: önce TaxCategory/Percent okunur. Bazı entegratörlerin
                    // XML'inde bu düğüm bulunmadığı için oran, kalemin KDV tutarı ile
                    // matrahından hesaplanarak tamamlanır. Hiçbiri okunamazsa null kalır;
                    // 0 yazmak "bilinmiyor" ile "gerçekten KDV'siz" ayrımını yok ediyordu.
                    $kdv = $xpath->evaluate('number(cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)', $line);

                    if (is_nan($kdv)) {
                        $kdvTutarXml = $xpath->evaluate('number(cac:TaxTotal/cbc:TaxAmount)', $line);
                        $matrahXml   = $xpath->evaluate('number(cbc:LineExtensionAmount)', $line);

                        $kdv = (!is_nan($kdvTutarXml) && !is_nan($matrahXml) && $matrahXml > 0)
                            ? round($kdvTutarXml / $matrahXml * 100, 2)
                            : null;
                    }

                    $kod = $xpath->evaluate('string(cac:Item/cac:SellersItemIdentification/cbc:ID)', $line);
                    if (!$kod) {
                        $kod = $xpath->evaluate('string(cac:Item/cac:BuyersItemIdentification/cbc:ID)', $line);
                    }

                    $kalemler[] = [
                        'isim'        => $isim,
                        'kod'         => $kod,
                        'miktar'      => $miktar,
                        'birim'       => $birim,
                        'fiyat'       => $fiyat,
                        'para_birimi' => $paraBirimi ?: 'TRY',
                        'kdv'         => $kdv
                    ];
                }

                $notes = $xpath->query('//cbc:Note');
                $kurUsd = null;
                $kurEur = null;
                foreach ($notes as $note) {
                    $noteText = $note->nodeValue;
                    if (preg_match('/USD\s*D[OÖ]V[Iİ]Z\s*KUR\s*:?\s*([0-9,.]+)/ui', $noteText, $m)) {
                        $kurUsd = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
                    }
                    if (preg_match('/EUR\s*D[OÖ]V[Iİ]Z\s*KUR\s*:?\s*([0-9,.]+)/ui', $noteText, $m)) {
                        $kurEur = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
                    }
                    if (preg_match('/D[OÖ]V[Iİ]Z\s*KURU?\s*:?\s*([0-9,.]+)/ui', $noteText, $m)) {
                        // Para birimi belirsizse document currency'e göre ata
                        $docCur = $xpath->evaluate('string(//cbc:DocumentCurrencyCode)');
                        $val = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
                        if ($docCur === 'USD' && !$kurUsd) $kurUsd = $val;
                        elseif ($docCur === 'EUR' && !$kurEur) $kurEur = $val;
                    }
                }

                // UBL ExchangeRate elementlerinden kur oku (PricingExchangeRate, TaxExchangeRate, PaymentExchangeRate)
                $erNodes = $xpath->query('//*[contains(local-name(), "ExchangeRate")]');
                foreach ($erNodes as $er) {
                    $src  = $xpath->evaluate('string(cbc:SourceCurrencyCode)', $er);
                    $rate = $xpath->evaluate('number(cbc:CalculationRate)', $er);
                    if (is_nan($rate) || $rate <= 0) continue;
                    if (strtoupper($src) === 'USD' && !$kurUsd) $kurUsd = $rate;
                    if (strtoupper($src) === 'EUR' && !$kurEur) $kurEur = $rate;
                }

                $faturaRow = $db->fetchOne("SELECT FaturalarGiden_id FROM FaturalarGiden WHERE FaturalarGiden_FaturaNo = ?", [$faturaNo]);
                $dbFaturaId = $faturaRow ? intval($faturaRow['FaturalarGiden_id']) : 0;

                if ($dbFaturaId > 0) {
                    // Mevcut eşleşmeleri (Sira -> UrunHizmet_id) kaydet, silince kaybolmasın
                    $mevcutEslesmeler = $db->fetchAll(
                        "SELECT FaturalarGidenKalemleri_Sira, FaturalarGidenKalemleri_UrunHizmet_id FROM FaturalarGidenKalemleri WHERE FaturalarGidenKalemleri_Fatura_id = ? AND FaturalarGidenKalemleri_UrunHizmet_id IS NOT NULL",
                        [$dbFaturaId]
                    );
                    $kayitliEslesmeler = []; // sira => urun_hizmet_id
                    foreach ($mevcutEslesmeler as $me) {
                        $kayitliEslesmeler[(int)$me['FaturalarGidenKalemleri_Sira']] = (int)$me['FaturalarGidenKalemleri_UrunHizmet_id'];
                    }

                    $db->query("DELETE FROM FaturalarGidenKalemleri WHERE FaturalarGidenKalemleri_Fatura_id = ?", [$dbFaturaId]);

                    if (!empty($kalemler)) {
                        foreach ($kalemler as $idx => $kl) {
                            $sira = $idx + 1;
                            $netTutar = floatval($kl['miktar']) * floatval($kl['fiyat']);
                            // Oran okunamadıysa 0 yerine null yazılır; kalem "KDV'siz"
                            // görünüp raporlarda yanlış oran üretmesin.
                            $kdvOran  = ($kl['kdv'] ?? null) !== null ? floatval($kl['kdv']) : null;
                            $kdvTutar = $kdvOran !== null ? $netTutar * ($kdvOran / 100) : 0;
                            $gToplam = $netTutar + $kdvTutar;
                            $urunHizmetId = $kayitliEslesmeler[$sira] ?? null;

                            $db->insert('FaturalarGidenKalemleri', [
                                'FaturalarGidenKalemleri_Fatura_id'   => $dbFaturaId,
                                'FaturalarGidenKalemleri_Sira'        => $sira,
                                'FaturalarGidenKalemleri_StokKodu'    => $kl['kod'] ?? null,
                                'FaturalarGidenKalemleri_UrunAdi'     => $kl['isim'] ?? '',
                                'FaturalarGidenKalemleri_Miktar'      => floatval($kl['miktar']),
                                'FaturalarGidenKalemleri_Birim'       => $kl['birim'] ?? 'ADET',
                                'FaturalarGidenKalemleri_BirimFiyat'  => floatval($kl['fiyat']),
                                'FaturalarGidenKalemleri_KDVOrani'    => $kdvOran,
                                'FaturalarGidenKalemleri_KDVTutar'    => $kdvTutar,
                                'FaturalarGidenKalemleri_NetTutar'    => $netTutar,
                                'FaturalarGidenKalemleri_GToplamTutar'=> $gToplam,
                                'FaturalarGidenKalemleri_UrunHizmet_id' => $urunHizmetId,
                                'OlusturanKullanici'                  => $user['kullanici_id'],
                                'OlusturmaTarihi'                     => date('Y-m-d H:i:s')
                            ]);

                            // Response'a kayıtlı eşleşmeyi ekle
                            $kalemler[$idx]['urun_hizmet_id'] = $urunHizmetId;
                        }
                    }
                }

                echo json_encode([
                    'success' => true,
                    'data'    => $kalemler,
                    'kur_usd' => $kurUsd,
                    'kur_eur' => $kurEur
                ]);
                break;

            case 'stok_cikis_kaydet':
                $faturaNo = $_POST['fatura_no'] ?? '';
                $faturaId = (int)($_POST['fatura_id'] ?? 0);
                $dolarKur = (float)($_POST['dolar_kur'] ?? 0);
                $euroKur = (float)($_POST['euro_kur'] ?? 0);
                $eslesmeler = $_POST['eslesmeler'] ?? [];

                if (!$faturaNo || !$faturaId || empty($eslesmeler)) throw new Exception("Gecersiz veri.");

                // Daha önce bu fatura için stok hareketi varsa: stok miktarını geri yükle ve hareketleri sil
                $eskiHareketler = $db->fetchAll(
                    "SELECT Urun_id, Miktar FROM Urun_Stok_Hareketleri WHERE Fatura_id = ? AND Islem_Tipi = 'CIKIS' AND Durum = 1",
                    [$faturaId]
                );
                foreach ($eskiHareketler as $eh) {
                    $db->query(
                        "UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) + ? WHERE urun_hizmet_id = ?",
                        [(float)$eh['Miktar'], (int)$eh['Urun_id']]
                    );
                }
                $db->query("DELETE FROM Urun_Stok_Hareketleri WHERE Fatura_id = ? AND Islem_Tipi = 'CIKIS'", [$faturaId]);

                foreach ($eslesmeler as $es) {
                    $urunId = (int)$es['urun_id'];
                    $miktar = (float)$es['miktar'];
                    $fiyat = (float)$es['fiyat'];

                    if ($urunId <= 0 || $miktar <= 0) continue;

                    $db->insert('Urun_Stok_Hareketleri', [
                        'Urun_id' => $urunId,
                        'Islem_Tipi' => 'CIKIS',
                        'Miktar' => $miktar,
                        'Birim_Fiyati' => $fiyat,
                        'DolarKur' => $dolarKur > 0 ? $dolarKur : null,
                        'EuroKur' => $euroKur > 0 ? $euroKur : null,
                        'Fatura_id' => $faturaId,
                        'Fatura_No' => $faturaNo,
                        'Aciklama' => 'E-Fatura Satis: ' . $faturaNo,
                        'OlusturanKullanici' => $user['kullanici_id'],
                        'OlusturmaTarihi' => date('Y-m-d H:i:s'),
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi' => date('Y-m-d H:i:s'),
                        'Durum' => 1
                    ]);

                    $db->query("UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) - ? WHERE urun_hizmet_id = ?", [$miktar, $urunId]);

                    // FaturalarGidenKalemleri: UrunHizmet_id ve TRY birim fiyatını güncelle
                    $sira = (int)($es['sira'] ?? 0);
                    if ($sira > 0) {
                        $db->update('FaturalarGidenKalemleri', [
                            'FaturalarGidenKalemleri_UrunHizmet_id' => $urunId,
                            'FaturalarGidenKalemleri_BirimFiyat'    => $fiyat  // JS'den gelen TRY karşılığı
                        ], [
                            'FaturalarGidenKalemleri_Fatura_id' => $faturaId,
                            'FaturalarGidenKalemleri_Sira'      => $sira
                        ]);
                    }
                }

                $db->update('FaturalarGiden', ['FaturalarGiden_StokIslendi' => 1], ['FaturalarGiden_id' => $faturaId]);

                echo json_encode(['success' => true, 'message' => 'Stok cikislari basariyla tamamlandi.']);
                break;


            case 'stok_iade':
                $faturaId = intval($_POST['fatura_id'] ?? 0);
                if (!$faturaId) throw new Exception('Fatura ID gerekli.');

                $fat = $db->fetchOne(
                    "SELECT FaturalarGiden_FaturaNo, FaturalarGiden_Durum, FaturalarGiden_StokIslendi FROM FaturalarGiden WHERE FaturalarGiden_id = ? AND Durum = 1",
                    [$faturaId]
                );
                if (!$fat) throw new Exception('Fatura bulunamadı.');
                if (!$fat['FaturalarGiden_StokIslendi']) throw new Exception('Bu fatura için stok hareketi kaydı bulunamadı.');

                $hareketler = $db->fetchAll(
                    "SELECT Urun_id, Miktar FROM Urun_Stok_Hareketleri WHERE Fatura_id = ? AND Islem_Tipi = 'CIKIS' AND Durum = 1",
                    [$faturaId]
                );
                if (empty($hareketler)) throw new Exception('Bu faturaya ait stok hareketi bulunamadı.');

                $faturaNo = $fat['FaturalarGiden_FaturaNo'];
                foreach ($hareketler as $h) {
                    $urunId = (int)$h['Urun_id'];
                    $miktar = (float)$h['Miktar'];

                    $db->insert('Urun_Stok_Hareketleri', [
                        'Urun_id'             => $urunId,
                        'Islem_Tipi'          => 'GIRIS',
                        'Miktar'              => $miktar,
                        'Fatura_id'           => $faturaId,
                        'Fatura_No'           => $faturaNo,
                        'Aciklama'            => 'E-Fatura İptal İade: ' . $faturaNo,
                        'OlusturanKullanici'  => $user['kullanici_id'],
                        'OlusturmaTarihi'     => date('Y-m-d H:i:s'),
                        'GuncelleyenKullanici'=> $user['kullanici_id'],
                        'GuncellemeTarihi'    => date('Y-m-d H:i:s'),
                        'Durum'               => 1
                    ]);

                    $db->query(
                        "UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari, 0) + ? WHERE urun_hizmet_id = ?",
                        [$miktar, $urunId]
                    );
                }

                $db->query(
                    "UPDATE FaturalarGiden SET FaturalarGiden_StokIslendi = 0, Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE FaturalarGiden_id = ?",
                    [$user['kullanici_id'], $faturaId]
                );

                echo json_encode(['success' => true, 'message' => $faturaNo . ' faturasının stok hareketleri iade edildi ve fatura iptal olarak işaretlendi.']);
                break;

            case 'delete':
                if (!($pagePermissions['can_delete'] ?? false)) {
                    throw new Exception('Silme yetkiniz bulunmamaktadır.');
                }
                $faturaId = intval($_POST['fatura_id'] ?? 0);
                if (!$faturaId) throw new Exception('Fatura ID gerekli.');

                $fat = $db->fetchOne("SELECT FaturalarGiden_Durum FROM FaturalarGiden WHERE FaturalarGiden_id = ? AND Durum = 1", [$faturaId]);
                if (!$fat) throw new Exception('Fatura bulunamadı.');
                if ($fat['FaturalarGiden_Durum'] !== 'Taslak') throw new Exception('Sadece Taslak durumundaki faturalar silinebilir.');

                // Soft delete - Durum = 0
                $db->query("UPDATE FaturalarGiden SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE FaturalarGiden_id = ?", [$user['kullanici_id'], $faturaId]);
                // Kalemleri de sil
                $db->query("DELETE FROM FaturalarGidenKalemleri WHERE FaturalarGidenKalemleri_Fatura_id = ?", [$faturaId]);

                echo json_encode(['success' => true, 'message' => 'Taslak fatura başarıyla silindi.']);
                break;

            case 'restore':
                if (!($pagePermissions['can_delete'] ?? false)) {
                    throw new Exception('Geri alma yetkiniz bulunmamaktadır.');
                }
                $faturaId = intval($_POST['fatura_id'] ?? 0);
                if (!$faturaId) throw new Exception('Fatura ID gerekli.');

                $fat = $db->fetchOne("SELECT FaturalarGiden_id FROM FaturalarGiden WHERE FaturalarGiden_id = ? AND Durum = 0", [$faturaId]);
                if (!$fat) throw new Exception('Silinmiş fatura bulunamadı.');

                $db->query("UPDATE FaturalarGiden SET Durum = 1, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE FaturalarGiden_id = ?", [$user['kullanici_id'], $faturaId]);

                echo json_encode(['success' => true, 'message' => 'Fatura başarıyla geri alındı.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        #faturaTable th.col-durum,
        #faturaTable td.col-durum {
            width: 90px !important;
            min-width: 90px !important;
            max-width: 90px !important;
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        #faturaTable td.col-durum .badge {
            white-space: normal;
            text-align: left;
        }
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?>
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-send"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Fatura</span>
                                <span class="info-box-number" id="stat_toplam">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">GIB Başarılı</span>
                                <span class="info-box-number" id="stat_basarili">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bekleyen</span>
                                <span class="info-box-number" id="stat_bekleyen">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-currency-lira"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Tutar</span>
                                <span class="info-box-number" id="stat_tutar">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <span><i class="bi bi-funnel me-1"></i>Filtrele</span>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div class="collapse" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-2">
                                    <label class="form-label">Fatura No</label>
                                    <input type="text" id="f_fatura_no" class="form-control" placeholder="Fatura No...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">VKN / TCKN</label>
                                    <input type="text" id="f_vkn" class="form-control" placeholder="VKN/TCKN...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Ünvan</label>
                                    <input type="text" id="f_unvan" class="form-control" placeholder="Ünvan...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih Başlangıç</label>
                                    <input type="date" id="f_tarih_bas" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih Bitiş</label>
                                    <input type="date" id="f_tarih_bit" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Satış Kanalı</label>
                                    <select id="f_kanal" class="form-select" style="width:100%">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select id="f_durum" class="form-select" style="width:100%">
                                        <option value="">Tümü</option>
                                        <option value="GIB_IPTAL">GİB İptal</option>
                                        <option value="CANCELLED">İptal (Türkbelge)</option>
                                        <option value="SUCCEED">Başarılı</option>
                                        <option value="WAIT">Bekliyor</option>
                                        <option value="FAILED">Hatalı</option>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end gap-1">
                                    <button id="btnFiltrele" class="btn btn-primary w-100"><i class="bi bi-search"></i> Ara</button>
                                    <button id="btnFiltreTemizle" class="btn btn-outline-secondary" title="Temizle"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="f_silinmisler">
                                        <label class="form-check-label" for="f_silinmisler">Silinen faturaları da göster</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="bi bi-send me-1"></i>Giden e-Faturalar</h3>
                        <div class="d-flex gap-2">
                            <button class="btn btn-success btn-sm" id="btnExcelIndir">
                                <i class="bi bi-file-earmark-excel me-1"></i>Excel İndir
                            </button>
                            <button class="btn btn-primary btn-sm" id="btnFaturaCek">
                                <i class="bi bi-arrow-clockwise me-1"></i>Faturaları Çek
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="loadingOverlay" class="text-center py-4" style="display:none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Turkbelge API'den faturalar çekiliyor...</p>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered align-middle" id="faturaTable" style="width:100%">
                                <thead>
                                <tr>
                                    <th>Fatura No</th>
                                    <th>Tarih</th>
                                    <th>VKN/TCKN</th>
                                    <th>Ünvan</th>
                                    <th>Tip</th>
                                    <th>Senaryo</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="col-durum">Durum</th>
                                    <th>GİB</th>
                                    <th>Satış Kanalı</th>
                                    <th class="text-end">İşlem</th>
                                </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Stok Eşleştirme Modal -->
    <div class="modal fade" id="stokEslestirmeModal" tabindex="-1" aria-labelledby="stokModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="stokModalLabel"><i class="bi bi-box-arrow-up-right me-2"></i>Fatura Stok Çıkışı / Eşleştirme</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 mb-3">
                        Fatura No: <strong id="modalFaturaNo">-</strong>
                        <input type="hidden" id="modalFaturaId" value="">
                        <input type="hidden" id="modalDolarKur" value="">
                        <input type="hidden" id="modalEuroKur" value="">
                    </div>
                    
                    <div id="kurBilgisiContainer" class="alert alert-warning py-2 mb-3" style="display:none;"></div>

                    <div id="modalLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Fatura detayları ve ürünler çekiliyor...</p>
                    </div>

                    <div id="modalContent" style="display:none;">
                        <div class="d-flex justify-content-end mb-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="chkSifirStokGizle" checked>
                                <label class="form-check-label" for="chkSifirStokGizle">Sadece stoku 1 ve üzeri olanları göster</label>
                            </div>
                        </div>
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="35%">Faturadaki Kalem Adı</th>
                                    <th width="15%" class="text-center">Miktar / Birim</th>
                                    <th width="15%" class="text-end">Birim Fiyat</th>
                                    <th width="35%">Sistemdeki Ürün (Eşleştir)</th>
                                </tr>
                            </thead>
                            <tbody id="stokKalemleriBody">
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-success" id="btnStokKaydet" style="display:none;">
                        <i class="bi bi-check-circle me-1"></i>Stoklardan Düş ve Kaydet
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Sevk Çıktısı Modal -->
    <div class="modal fade" id="sevkModal" tabindex="-1" aria-labelledby="sevkModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title" id="sevkModalLabel"><i class="bi bi-truck me-2"></i>Sevk Çıktısı</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 mb-3">
                        Fatura No: <strong id="sevkFaturaNo">-</strong>
                        <span class="ms-3">Ünvan: <strong id="sevkUnvan">-</strong></span>
                        <input type="hidden" id="sevkVkn" value="">
                    </div>

                    <div id="sevkLoading" class="text-center py-4">
                        <div class="spinner-border text-secondary" role="status"></div>
                        <p class="mt-2 text-muted">Yetkililer ve ürünler yükleniyor...</p>
                    </div>

                    <div id="sevkContent" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Sevk Edilecek Yetkili</label>
                            <select id="sevkYetkiliSelect" class="form-select" style="width:100%">
                                <option value="">-- Yetkili Seçiniz --</option>
                            </select>
                            <div class="form-text" id="sevkYetkiliUyari"></div>
                        </div>

                        <div id="sevkYetkiliBilgi" class="card mb-3" style="display:none;">
                            <div class="card-body py-2">
                                <div class="row small">
                                    <div class="col-md-6 mb-1"><i class="bi bi-person me-1"></i><strong id="sevkAdSoyad">-</strong> <span class="text-muted" id="sevkBirim"></span></div>
                                    <div class="col-md-6 mb-1"><i class="bi bi-telephone me-1"></i><span id="sevkTelefon">-</span></div>
                                    <div class="col-md-6 mb-1"><i class="bi bi-geo-alt me-1"></i><span id="sevkSehirIlce">-</span></div>
                                    <div class="col-12 mb-1"><i class="bi bi-house me-1"></i><span id="sevkAdres">-</span></div>
                                </div>
                            </div>
                        </div>

                        <label class="form-label fw-semibold">Ürünler</label>
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:50px" class="text-center">#</th>
                                    <th>Ürün / Hizmet</th>
                                    <th style="width:140px" class="text-end">Miktar</th>
                                </tr>
                            </thead>
                            <tbody id="sevkUrunBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    <button type="button" class="btn btn-primary" id="btnSevkYazdir" style="display:none;">
                        <i class="bi bi-printer me-1"></i>Yazdır
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
const CAN_DELETE = <?= ($pagePermissions['can_delete'] ?? false) ? 'true' : 'false' ?>;
let table;

function fmt(v, p) {
    return parseFloat(v).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ' + (p||'TRY');
}

function excelIndir(rows) {
    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    html += '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head>';
    html += '<body><table border="1"><thead>';
    html += '<tr style="background-color:#0d6efd;color:#fff;font-weight:bold;">';
    ['Fatura No','Tarih','VKN/TCKN','Vergi Dairesi','Alıcı Ünvan','Fatura Tipi','Senaryo','Tutar','Para Birimi','Kur','Durum','GIB Durum','Satış Kanalı']
        .forEach(h => html += '<th>' + h + '</th>');
    html += '</tr></thead><tbody>';
    rows.forEach(r => {
        const tarih = (r.fatura_tarihi || '').split('-');
        const tarihStr = tarih.length === 3 ? tarih[2]+'.'+tarih[1]+'.'+tarih[0] : (r.fatura_tarihi || '');
        html += '<tr>';
        html += '<td>' + esc(r.fatura_no) + '</td>';
        html += '<td>' + esc(tarihStr) + '</td>';
        html += '<td>' + esc(r.vkn_tckn) + '</td>';
        html += '<td>' + esc(r.vergi_dairesi) + '</td>';
        html += '<td>' + esc(r.unvan) + '</td>';
        html += '<td>' + esc(r.fatura_tipi) + '</td>';
        html += '<td>' + esc(r.senaryo) + '</td>';
        html += '<td style="text-align:right">' + parseFloat(r.tutar || 0).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
        html += '<td>' + esc(r.para_birimi || 'TRY') + '</td>';
        const kurStr = (r.para_birimi === 'USD' || r.para_birimi === 'EUR') && r.kur
            ? parseFloat(r.kur).toLocaleString('tr-TR', {minimumFractionDigits:4, maximumFractionDigits:4}) : '';
        html += '<td style="text-align:right">' + kurStr + '</td>';
        html += '<td>' + esc(r.durum) + '</td>';
        html += '<td>' + esc(r.gib_durum) + '</td>';
        html += '<td>' + esc(kanalMetni(r)) + '</td>';
        html += '</tr>';
    });
    html += '</tbody></table></body></html>';

    const blob = new Blob(['﻿' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const ts = new Date().toISOString().slice(0,19).replace(/[T:]/g,'-');
    a.href = url;
    a.download = 'giden_efaturalar_' + ts + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function badgeTip(tip) {
    const m = {SATIS:'primary',IADE:'warning',TEVKIFAT:'info',ISTISNA:'secondary',OZELMATRAH:'dark',IHRACKAYITLI:'success'};
    return '<span class="badge text-bg-' + (m[tip]||'secondary') + '">' + (tip||'-') + '</span>';
}

function badgeDurum(d) {
    if (!d) return '-';
    let r = 'secondary', t = d;
    if (d === 'SUCCEED' || d === 'SUCCESS')      { r='success'; t='Başarılı'; }
    else if (d === 'FAILED' || d === 'ERROR')     { r='danger';  t='Hatalı'; }
    else if (d.indexOf('WAIT') >= 0)              { r='warning text-dark'; t='Bekliyor'; }
    else if (d === 'CANCELLED' || d === 'CANCEL') { r='secondary'; t='İptal'; }
    else if (d === 'Kesildi' || d === 'NEW')      { r='info'; t=d; }
    else if (d === 'e-Arşiv' || d === 'ARCHIVED') { r='info'; t='e-Arşiv'; }
    return '<span class="badge bg-' + r + '" title="' + d + '">' + t + '</span>';
}

function htmlEsc(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function vadeFarkiCariUyari(vkn) {
    Swal.fire({
        icon: 'warning',
        title: 'Cari Kartı Bulunamadı',
        html: 'Vade farkı faturası kesebilmek için <strong>' + htmlEsc(vkn || 'bu VKN') + '</strong> numaralı '
            + 'firmanın sistemde cari kartı olmalıdır.',
        showCancelButton: true,
        confirmButtonText: 'Cari Kartı Aç',
        cancelButtonText: 'Kapat'
    }).then(function(r) {
        if (r.isConfirmed) window.open('/Admin/cari-form', '_blank');
    });
}

// Satış kanalı metni: pazaryeri siparişi ise kanal adı, değilse satışı yapan personel
function kanalMetni(row) {
    if (row.pazaryeri_adi) return 'İnternet — ' + row.pazaryeri_adi;
    return row.personel_adi || 'Satış Yetkilisi';
}

function badgeKanal(row) {
    const deger  = kanalMetni(row);
    const renk   = row.pazaryeri_adi ? 'bg-success' : 'bg-secondary';
    const ikon   = row.pazaryeri_adi ? 'bi-globe' : 'bi-person-badge';
    const aciklama = row.pazaryeri_adi ? 'İnternet üzerinden satış' : 'Satış personeli / doğrudan satış';

    return '<span class="badge ' + renk + ' kanal-filtre" style="cursor:pointer"'
         + ' data-kanal="' + htmlEsc(deger) + '"'
         + ' title="' + htmlEsc(aciklama) + ' — filtrelemek için tıklayın">'
         + '<i class="bi ' + ikon + ' me-1"></i>' + htmlEsc(deger) + '</span>';
}

function filtreUygula() {
    $.fn.dataTable.ext.search = [];
    const fno    = $('#f_fatura_no').val().toLowerCase();
    const fvkn   = $('#f_vkn').val().toLowerCase();
    const funv   = $('#f_unvan').val().toLowerCase();
    const fbas   = $('#f_tarih_bas').val();
    const fbit   = $('#f_tarih_bit').val();
    const fkanal = $('#f_kanal').val() || '';
    const fdurum = $('#f_durum').val() || '';
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex, row) {
        if (settings.nTable.id !== 'faturaTable') return true;
        if (fno  && data[0].toLowerCase().indexOf(fno)  < 0) return false;
        if (fvkn && data[2].toLowerCase().indexOf(fvkn) < 0) return false;
        if (funv && data[3].toLowerCase().indexOf(funv) < 0) return false;
        if (fbas && data[1] < fbas) return false;
        if (fbit && data[1] > fbit) return false;
        if (fkanal && kanalMetni(row) !== fkanal) return false;
        if (fdurum && !durumEslesiyor(row, fdurum)) return false;        return true;
    });
    table.draw();
}

// Durum filtresi. GİB İptal/İade Portalı iptali ayrı bir bayrakta tutulur
// (harici_iptal); zarf durumu bu faturalarda SUCCEED olarak kaldığı için
// durum metnine bakan bir eşleşme onları yakalayamaz.
function durumEslesiyor(row, secim) {
    const hariciIptal = Number(row.harici_iptal) === 1;
    if (secim === 'GIB_IPTAL') return hariciIptal;
    if (hariciIptal) return false;

    const d = ((row.durum || '') + ' ' + (row.gib_durum || '')).toUpperCase();
    if (secim === 'CANCELLED') return d.indexOf('CANCEL') >= 0;
    if (secim === 'WAIT')      return d.indexOf('WAIT') >= 0;
    if (secim === 'FAILED')    return d.indexOf('FAIL') >= 0 || d.indexOf('ERROR') >= 0;
    if (secim === 'SUCCEED')   return d.indexOf('SUCCE') >= 0 || d.indexOf('BAŞARI') >= 0 || d.indexOf('BASARI') >= 0;
    return true;
}

// Satış kanalı filtresini mevcut fatura verisinden dinamik doldur
function kanalFiltresiDoldur(data) {
    const sel = $('#f_kanal');
    const mevcut = sel.val();
    const secenekler = [...new Set(data.map(r => kanalMetni(r)))].sort((a, b) => a.localeCompare(b, 'tr'));

    sel.empty().append('<option value="">Tümü</option>');
    secenekler.forEach(k => sel.append($('<option>').val(k).text(k)));

    if (mevcut && secenekler.indexOf(mevcut) >= 0) sel.val(mevcut);
    sel.trigger('change.select2');
}

function loadFaturalar(yenile) {
    $('#loadingOverlay').show();
    $('#faturaTable').closest('.table-responsive').hide();
    const silinmisler = $('#f_silinmisler').is(':checked') ? 1 : 0;

    $.post('', {action:'list', yenile: yenile ? 1 : 0, silinmisler: silinmisler}, function(resp) {
        $('#loadingOverlay').hide();
        $('#faturaTable').closest('.table-responsive').show();

        if (!resp.success) {
            Swal.fire('Hata', resp.message || 'Fatura çekme hatası', 'error');
            return;
        }

        table.clear().rows.add(resp.data).draw();
        kanalFiltresiDoldur(resp.data);
        const data = resp.data;
        $('#stat_toplam').text(data.length);
        $('#stat_basarili').text(data.filter(d => (d.gib_durum||'').indexOf('BASARI') >= 0 || (d.durum||'') === 'SUCCEED').length);
        $('#stat_bekleyen').text(data.filter(d => (d.durum||'').indexOf('WAIT') >= 0).length);
        $('#stat_tutar').text(fmt(data.reduce((s,d) => s + parseFloat(d.tutar||0), 0), 'TRY'));
    }, 'json').fail(function(xhr) {
        $('#loadingOverlay').hide();
        $('#faturaTable').closest('.table-responsive').show();
        Swal.fire('Hata', 'Bağlantı hatası: ' + xhr.statusText, 'error');
    });
}

$(function() {
    table = $('#faturaTable').DataTable({
        data: [],
        order: [[1, 'desc']],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        pageLength: 25,
        columns: [
            {
                data: 'fatura_no',
                render: function(d, t, row) {
                    let no = d;
                    if (row.kayit_durum == 0) no = '<s class="text-muted">' + d + '</s> <span class="badge bg-danger">Silindi</span>';
                    no = '<strong>' + no + '</strong>';
                    // İade faturasında hangi faturanın iadesi olduğu alt satırda gösterilir
                    if (t === 'display' && row.iade_kaynak_no) {
                        no += '<div class="small text-muted" title="İade edilen fatura">'
                            + '<i class="bi bi-arrow-return-left"></i> ' + row.iade_kaynak_no + '</div>';
                    }
                    return no;
                }
            },
            {
                data: 'fatura_tarihi',
                render: function(d, t) {
                    if (!d) return '-';
                    if (t === 'sort' || t === 'filter') return d;
                    const p = d.split('-');
                    return p.length === 3 ? p[2]+'.'+p[1]+'.'+p[0] : d;
                }
            },
            { 
                data: 'vkn_tckn',
                render: d => d || '-'
            },
            {
                data: 'unvan',
                className: 'text-nowrap',
                render: function(d, t) {
                    if (!d) return '-';
                    if (t !== 'display') return d;   // arama/sıralama tam değerde kalsın
                    return '<span title="' + htmlEsc(d) + '">'
                         + htmlEsc(d.length > 20 ? d.substr(0, 20) + '…' : d) + '</span>';
                }
            },
            { data: 'fatura_tipi', render: d => badgeTip(d) },
            { data: 'senaryo', render: d => d || '-' },
            {
                data: 'tutar',
                className: 'text-end text-nowrap',
                render: function(d, t, row) {
                    if (t === 'sort') return parseFloat(d||0);
                    return '<strong>' + fmt(d, row.para_birimi) + '</strong>';
                }
            },
            { data: 'durum',     className: 'col-durum', render: d => badgeDurum(d) },
            {
                data: 'gib_durum',
                render: (d, t, row) => {
                    // GİB İptal/İade Portalı iptalinde zarf durumu "BAŞARIYLA TAMAMLANDI"
                    // kaldığı için iptal bilgisi ayrı bayraktan gösterilir.
                    if (t !== 'display') return d;
                    if (Number(row.harici_iptal) === 1) {
                        const aciklama = row.yanit_aciklama || 'GİB İptal/İade Portalı üzerinden iptal edildi';
                        return '<span class="badge bg-danger" title="' + htmlEsc(aciklama) + '">GİB İPTAL</span>';
                    }
                    return badgeDurum(d);
                }
            },
            { data: 'pazaryeri_adi', render: (d, t, row) => (t === 'display' ? badgeKanal(row) : kanalMetni(row)) },
            {
                data: null,
                className: 'text-end text-nowrap',
                orderable: false,
                render: function(d, t, row) {
                    // Silinen kayıt
                    if (row.kayit_durum == 0) {
                        if (!CAN_DELETE) return '<span class="text-muted">-</span>';
                        return `<div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-success btn-geri-al" data-id="${row.id}" data-no="${row.fatura_no}" title="Geri Al">
                                        <i class="bi bi-arrow-counterclockwise"></i> Geri Al
                                    </button>
                                </div>`;
                    }

                    const sevkUnvan = (row.cari_unvan || row.unvan || '').replace(/'/g, "\\'");
                    let html = `<div class="btn-group btn-group-sm">
                                    <a href="/admin/pages/fatura-goruntule.php?fatura_no=${row.fatura_no}&direction=OUT" target="_blank" class="btn btn-primary" title="Faturayı Görüntüle">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                    <button type="button" class="btn btn-secondary" onclick="openSevkModal('${row.fatura_no}', '${row.vkn_tckn || ''}', '${sevkUnvan}')" title="Sevk Çıktısı">
                                        <i class="bi bi-truck"></i>
                                    </button>`;
                    
                    if (row.durum === 'Taslak') {
                        html += `<a href="/Admin/fatura-form?id=${row.id}" class="btn btn-info" title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                 </a>`;
                        if (CAN_DELETE) {
                            html += `<button type="button" class="btn btn-danger btn-sil-taslak" data-id="${row.id}" data-no="${row.fatura_no}" title="Taslak Sil">
                                        <i class="bi bi-trash"></i>
                                     </button>`;
                        }
                    } else if (row.stok_islendi == 1) {
                        html += `<button type="button" class="btn btn-success" onclick="openStokModal('${row.fatura_no}', ${row.id})" title="İşlendi - Tekrar açmak için tıklayın">
                                    <i class="bi bi-check-all"></i> İşlendi
                                 </button>
                                 <button type="button" class="btn btn-danger btn-stok-iade" data-id="${row.id}" data-no="${row.fatura_no}" title="Stok hareketini geri al">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                 </button>`;
                    } else {
                        html += `<button type="button" class="btn btn-warning text-dark" onclick="openStokModal('${row.fatura_no}', ${row.id})" title="Ürünleri Eşleştir ve Stoktan Düş">
                                    <i class="bi bi-link-45deg"></i> Eşleştir
                                 </button>`;
                    }

                    // Vade farkı faturası: SATIS tipli giden fatura, tek kalem (Vade Farkı) olarak açılır.
                    // Taslak, iade ve GİB portalından iptal edilmiş faturalarda gösterilmez.
                    if (row.durum !== 'Taslak' && row.fatura_tipi === 'SATIS' && Number(row.harici_iptal) !== 1) {
                        if (row.cari_id) {
                            html += `<a href="/Admin/fatura-form?vade_farki_kaynak=${row.id}" class="btn btn-outline-primary" title="Bu fatura için vade farkı faturası kes">
                                        <i class="bi bi-cash-coin"></i>
                                     </a>`;
                        } else {
                            html += `<button type="button" class="btn btn-outline-secondary" onclick="vadeFarkiCariUyari('${row.vkn_tckn || ''}')" title="Vade farkı faturası için önce cari kartı gerekli">
                                        <i class="bi bi-cash-coin"></i>
                                     </button>`;
                        }
                    }

                    html += `</div>`;
                    return html;
                }
            }
        ]
    });

    $('#f_kanal, #f_durum').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Tümü',
        allowClear: true
    });

    // Filtreler
    $('#btnFiltrele').on('click', filtreUygula);

    // Satış kanalı hücresindeki değere tıklanınca o kanala göre filtrele
    $('#faturaTable tbody').on('click', '.kanal-filtre', function(e) {
        e.stopPropagation();
        const kanal = $(this).data('kanal');
        if ($('#f_kanal option[value="' + kanal.replace(/"/g, '\\"') + '"]').length === 0) {
            $('#f_kanal').append($('<option>').val(kanal).text(kanal));
        }
        $('#f_kanal').val(kanal).trigger('change.select2');
        $('#filtrePanel').collapse('show');
        filtreUygula();
    });

    $('#btnFiltreTemizle').on('click', function() {
        $('#f_fatura_no,#f_vkn,#f_unvan,#f_tarih_bas,#f_tarih_bit').val('');
        $('#f_kanal, #f_durum').val('').trigger('change.select2');
        $.fn.dataTable.ext.search = [];
        table.draw();
    });

    $('#f_silinmisler').on('change', function() {
        loadFaturalar(false);
    });

    $('#btnFaturaCek').on('click', function() {
        Swal.fire({
            title: 'Faturalar çekilsin mi?',
            text: 'Turkbelge API\'den giden faturalar güncellenecek.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet, Çek',
            cancelButtonText: 'Vazgeç'
        }).then(r => { if (r.isConfirmed) loadFaturalar(true); });
    });

    // Excel İndir (DataTables filtrelenmiş veriyi indirir)
    $('#btnExcelIndir').on('click', function() {
        const rows = table.rows({ search: 'applied' }).data().toArray();
        if (!rows.length) {
            Swal.fire('Bilgi', 'İndirilecek kayıt bulunamadı.', 'info');
            return;
        }
        excelIndir(rows);
    });

    $('#btnStokKaydet').on('click', function() {
        kaydetStokCikisi();
    });

    $(document).on('change', '#chkSifirStokGizle', function() {
        if ($('#modalContent').is(':visible')) {
            renderStokKalemleri();
        }
    });

    $.fn.select2.defaults.set("theme", "bootstrap-5");

    loadFaturalar(false);

    // Taslak sil butonu
    $(document).on('click', '.btn-sil-taslak', function() {
        const id = $(this).data('id');
        const no = $(this).data('no');
        Swal.fire({
            title: 'Taslak Silinsin mi?',
            html: '<b>' + no + '</b> numaralı taslak fatura silinecek.<br><span class="text-danger">Bu işlem geri alınamaz!</span>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: '<i class="bi bi-trash"></i> Evet, Sil',
            cancelButtonText: 'Vazgeç'
        }).then(result => {
            if (!result.isConfirmed) return;
            $.post('', { action: 'delete', fatura_id: id }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Silindi!', text: res.message, timer: 1500, showConfirmButton: false });
                    loadFaturalar(false);
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            }, 'json');
        });
    });

    // Stok iade butonu (CANCELLED faturalar)
    $(document).on('click', '.btn-stok-iade', function() {
        const id = $(this).data('id');
        const no = $(this).data('no');
        Swal.fire({
            title: 'Stok İade Edilsin mi?',
            html: '<b>' + no + '</b> numaralı fatura iptal olmuş.<br>Bu faturaya ait stok çıkışları geri alınacak ve stok miktarları artırılacak.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: '<i class="bi bi-arrow-counterclockwise"></i> Evet, Stok İade Et',
            cancelButtonText: 'Vazgeç'
        }).then(result => {
            if (!result.isConfirmed) return;
            $.post('', { action: 'stok_iade', fatura_id: id }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Stok İade Edildi!', text: res.message, timer: 2000, showConfirmButton: false });
                    loadFaturalar(false);
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            }, 'json');
        });
    });

    // Geri al butonu
    $(document).on('click', '.btn-geri-al', function() {
        const id = $(this).data('id');
        const no = $(this).data('no');
        Swal.fire({
            title: 'Fatura Geri Alınsın mı?',
            html: '<b>' + no + '</b> numaralı fatura tekrar aktif hale getirilecek.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            confirmButtonText: '<i class="bi bi-arrow-counterclockwise"></i> Evet, Geri Al',
            cancelButtonText: 'Vazgeç'
        }).then(result => {
            if (!result.isConfirmed) return;
            $.post('', { action: 'restore', fatura_id: id }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Geri Alındı!', text: res.message, timer: 1500, showConfirmButton: false });
                    loadFaturalar(false);
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            }, 'json');
        });
    });
});

// % karakterini SQL LIKE gibi wildcard olarak kullanan Select2 matcher
function urunWildcardMatcher(params, data) {
    if ($.trim(params.term) === '') return data;
    if (typeof data.text === 'undefined') return null;
    const term = params.term.toLowerCase();
    const text = data.text.toLowerCase();
    if (term.indexOf('%') === -1) {
        return text.indexOf(term) > -1 ? data : null;
    }
    const parts = term.split('%').filter(function(p) { return p !== ''; });
    let pos = 0;
    for (let i = 0; i < parts.length; i++) {
        const idx = text.indexOf(parts[i], pos);
        if (idx === -1) return null;
        pos = idx + parts[i].length;
    }
    return data;
}

let sistemUrunleri = [];
let currentInvoiceLines = [];

function getKurForCurrency(cur) {
    if (cur === 'USD') return parseFloat($('#modalDolarKur').val()) || parseFloat($('#manuelKur_USD').val()) || 0;
    if (cur === 'EUR') return parseFloat($('#modalEuroKur').val()) || parseFloat($('#manuelKur_EUR').val()) || 0;
    return parseFloat($('#manuelKur_' + cur).val()) || 0;
}

function buildFiyatHtml(kalem) {
    const pb = kalem.para_birimi || 'TRY';
    const fiyatFmt = parseFloat(kalem.fiyat).toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const dataAttrs = `data-fiyat="${kalem.fiyat}" data-para-birimi="${pb}" data-kdv="${kalem.kdv !== null ? kalem.kdv : ''}"`;

    if (pb === 'TRY') {
        return `<span class="kalem-fiyat" ${dataAttrs}>${fiyatFmt}</span> <small>TRY</small>`;
    }

    const kur = getKurForCurrency(pb);
    if (kur > 0) {
        const tryFiyat = kalem.fiyat * kur;
        const tryFmt = tryFiyat.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        return `<span class="kalem-fiyat" ${dataAttrs}>${fiyatFmt}</span> <small>${pb}</small>
                <br><small class="text-success fw-bold">≈ ${tryFmt} TRY</small>
                <small class="text-muted">(kur: ${kur})</small>`;
    }

    return `<span class="kalem-fiyat" ${dataAttrs}>${fiyatFmt}</span> <small>${pb}</small>
            <br><small class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Kur girilmedi</small>`;
}

function openStokModal(faturaNo, faturaId) {
    $('#modalFaturaNo').text(faturaNo);
    $('#modalFaturaId').val(faturaId);
    $('#stokKalemleriBody').empty();
    $('#modalContent, #btnStokKaydet').hide();
    $('#modalLoading').show();
    
    var myModal = new bootstrap.Modal(document.getElementById('stokEslestirmeModal'));
    myModal.show();

    if (sistemUrunleri.length === 0) {
        $.post('', { action: 'get_products' }, function(resp) {
            if (resp.success) {
                sistemUrunleri = resp.data;
                fetchInvoiceLines(faturaNo);
            } else {
                Swal.fire('Hata', resp.message || 'Ürünler çekilemedi', 'error');
                myModal.hide();
            }
        }, 'json');
    } else {
        fetchInvoiceLines(faturaNo);
    }
}

function fetchInvoiceLines(faturaNo) {
    $.post('', { action: 'get_invoice_lines', fatura_no: faturaNo }, function(resp) {
        $('#modalLoading').hide();
        if (resp.success && resp.data.length > 0) {
            $('#modalDolarKur').val(resp.kur_usd || '');
            $('#modalEuroKur').val(resp.kur_eur || '');

            // Faturadaki benzersiz döviz tipleri (TRY hariç)
            const dovizler = [...new Set(resp.data.map(k => k.para_birimi).filter(p => p && p !== 'TRY'))];

            let kurHtml = '<i class="bi bi-currency-exchange me-2"></i>';
            let manuelGerekli = false;

            if (resp.kur_usd) kurHtml += `<strong>USD Kur:</strong> ${parseFloat(resp.kur_usd).toLocaleString('tr-TR', {minimumFractionDigits:4})} &nbsp;&nbsp;`;
            if (resp.kur_eur) kurHtml += `<strong>EUR Kur:</strong> ${parseFloat(resp.kur_eur).toLocaleString('tr-TR', {minimumFractionDigits:4})} &nbsp;&nbsp;`;

            dovizler.forEach(cur => {
                const kurVar = (cur === 'USD' && resp.kur_usd) || (cur === 'EUR' && resp.kur_eur);
                if (!kurVar) {
                    manuelGerekli = true;
                    kurHtml += `
                        <div class="d-inline-flex align-items-center gap-1 me-2 mt-1">
                            <span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> ${cur}/TRY kuru bulunamadı:</span>
                            <input type="number" id="manuelKur_${cur}" class="form-control form-control-sm manuel-kur-input"
                                   style="width:130px" placeholder="${cur}/TRY" step="0.0001" min="0.0001" data-cur="${cur}">
                        </div>`;
                }
            });

            if (manuelGerekli) {
                kurHtml += `<small class="text-danger d-block mt-1"><i class="bi bi-info-circle"></i> Kayıt yapmadan önce eksik kurları girmeniz zorunludur.</small>`;
                $('#kurBilgisiContainer').removeClass('alert-warning').addClass('alert-danger').html(kurHtml).show();
            } else if (dovizler.length > 0 || resp.kur_usd || resp.kur_eur) {
                $('#kurBilgisiContainer').removeClass('alert-danger').addClass('alert-warning').html(kurHtml).show();
            } else {
                $('#kurBilgisiContainer').hide();
            }

            // Manuel kur girildiğinde kalemleri yenile
            $(document).off('input.manuelkur').on('input.manuelkur', '.manuel-kur-input', function() {
                renderStokKalemleri();
            });

            currentInvoiceLines = resp.data;
            renderStokKalemleri();
        } else {
            Swal.fire('Hata', resp.message || 'Faturada kalem bulunamadı veya okunamadı.', 'error');
        }
    }, 'json').fail(function() {
        $('#modalLoading').hide();
        Swal.fire('Hata', 'Fatura kalemleri çekilirken ağ hatası oluştu.', 'error');
    });
}

function renderStokKalemleri() {
    let html = '';
    const sifirStokGizle = $('#chkSifirStokGizle').is(':checked');

    currentInvoiceLines.forEach((kalem, index) => {
        let optionsHtml = '<option value="">-- Lütfen Eşleşen Ürünü Seçin --</option>';
        let eslesenVar = false;
        
        sistemUrunleri.forEach(u => {
            let stok = parseFloat(u.Stok_Miktari) || 0;
            let isSelected = false;

            // Öncelik: DB'de kayıtlı eşleşme
            if (kalem.urun_hizmet_id && parseInt(kalem.urun_hizmet_id) === parseInt(u.Urun_id)) {
                isSelected = true;
                eslesenVar = true;
            // İkinci: stok kodu eşleşmesi (DB kaydı yoksa)
            } else if (!kalem.urun_hizmet_id && kalem.kod && u.Urun_Kodu && kalem.kod.toString().trim() === u.Urun_Kodu.toString().trim()) {
                isSelected = true;
                eslesenVar = true;
            }

            if (sifirStokGizle && stok < 1 && !isSelected) return;

            optionsHtml += `<option value="${u.Urun_id}" ${isSelected ? 'selected' : ''}>[${u.Urun_Kodu || '-'}] ${u.Urun_Adi} (Stok: ${stok})</option>`;
        });

        let kalemBaslikHtml = `<strong class="d-block ${eslesenVar ? 'text-success' : 'text-primary'}">${kalem.isim || 'İsimsiz Kalem'}</strong>`;
        if (kalem.kod) {
            kalemBaslikHtml += `<span class="badge bg-secondary mb-1">Kod: ${kalem.kod}</span> `;
        }
        if (eslesenVar) {
            const eslesmeMesaj = kalem.urun_hizmet_id
                ? `<i class="bi bi-bookmark-check-fill"></i> Kayıtlı eşleşme yüklendi`
                : `<i class="bi bi-check-circle"></i> Kod ile otomatik eşleşti`;
            kalemBaslikHtml += `<small class="text-success d-block">${eslesmeMesaj}</small>`;
        } else {
            kalemBaslikHtml += `<small class="text-muted d-block">Lütfen uygun ürünle eşleştirin. (Boş bırakılanlar işlenmez)</small>`;
        }

        html += `
            <tr>
                <td>
                    ${kalemBaslikHtml}
                </td>
                <td class="text-center font-monospace">
                    <span class="kalem-miktar" data-miktar="${kalem.miktar}">${parseFloat(kalem.miktar).toLocaleString('tr-TR', { maximumFractionDigits: 0 })}</span> <small>${kalem.birim || 'ADET'}</small>
                </td>
                <td class="text-end font-monospace">
                    ${buildFiyatHtml(kalem)}
                    <br><small class="text-muted">%${kalem.kdv !== null ? kalem.kdv : '?'} KDV</small>
                </td>
                <td>
                    <select class="form-select urun-select" data-index="${index}" style="width: 100%">
                        ${optionsHtml}
                    </select>
                </td>
            </tr>
        `;
    });
    
    $('#stokKalemleriBody').html(html);
    $('#modalContent, #btnStokKaydet').show();

    $('.urun-select').select2({
        dropdownParent: $('#stokEslestirmeModal'),
        placeholder: 'Ürün Arayın...',
        allowClear: true,
        matcher: urunWildcardMatcher
    });
}

function kaydetStokCikisi() {
    let eslesmeler = [];
    let eksikVar = false;
    let kurEksikCur = null;

    $('#stokKalemleriBody tr').each(function(index) {
        let urunId = $(this).find('.urun-select').val();
        let kalemIndex = parseInt($(this).find('.urun-select').data('index'));
        let kalem = currentInvoiceLines[kalemIndex] || {};
        let paraBirimi = kalem.para_birimi || 'TRY';

        let miktar = parseFloat($(this).find('.kalem-miktar').data('miktar') || 0);
        let fiyatOrijinal = parseFloat($(this).find('.kalem-fiyat').data('fiyat') || 0);
        let kdv = $(this).find('.kalem-fiyat').data('kdv');
        kdv = (kdv !== '' && kdv !== undefined && kdv !== null) ? parseFloat(kdv) : null;

        let fiyatTRY = fiyatOrijinal;
        if (paraBirimi !== 'TRY') {
            const kur = getKurForCurrency(paraBirimi);
            if (kur <= 0) {
                if (urunId) kurEksikCur = paraBirimi; // seçili kalemde kur eksik
                return true; // continue each
            }
            fiyatTRY = fiyatOrijinal * kur;
        }

        if (urunId) {
            eslesmeler.push({ sira: index + 1, urun_id: urunId, miktar: miktar, fiyat: fiyatTRY, kdv: kdv });
        } else {
            eksikVar = true;
        }
    });

    if (kurEksikCur) {
        Swal.fire('Kur Eksik', `Eşleştirilen kalemde <strong>${kurEksikCur}/TRY</strong> kuru girilmemiş. Lütfen döviz kurunu girin.`, 'warning');
        $(`#manuelKur_${kurEksikCur}`).focus().addClass('is-invalid');
        return;
    }

    if (eslesmeler.length === 0) {
        Swal.fire('Uyarı', 'Hiçbir ürünü eşleştirmediniz. Lütfen en az bir ürün eşleştirin.', 'warning');
        return;
    }

    if (eksikVar) {
        Swal.fire({
            title: 'Emin misiniz?',
            text: 'Eşleştirilmeyen (boş bırakılan) kalemler var. Bunlar stoktan DÜŞÜLMEYECEK. Devam edilsin mi?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet, Sadece Seçilenleri Düş',
            cancelButtonText: 'İptal'
        }).then((result) => {
            if (result.isConfirmed) {
                gonderStokCikisi(eslesmeler);
            }
        });
    } else {
        gonderStokCikisi(eslesmeler);
    }
}

function gonderStokCikisi(eslesmeler) {
    const faturaNo = $('#modalFaturaNo').text();
    const faturaId = $('#modalFaturaId').val();
    const dolarKur = $('#modalDolarKur').val();
    const euroKur = $('#modalEuroKur').val();

    $('#btnStokKaydet').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> İşleniyor...');

    $.post('', {
        action: 'stok_cikis_kaydet',
        fatura_no: faturaNo,
        fatura_id: faturaId,
        dolar_kur: dolarKur,
        euro_kur: euroKur,
        eslesmeler: eslesmeler
    }, function(resp) {
        $('#btnStokKaydet').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>Stoklardan Düş ve Kaydet');
        if (resp.success) {
            Swal.fire('Başarılı', resp.message, 'success').then(() => {
                $('#stokEslestirmeModal').modal('hide');
                loadFaturalar();
            });
        } else {
            Swal.fire('Hata', resp.message || 'Kayıt sırasında bir sorun oluştu.', 'error');
        }
    }, 'json').fail(function() {
        $('#btnStokKaydet').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>Stoklardan Düş ve Kaydet');
        Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
    });
}

// ========== SEVK ÇIKTISI ==========
const SEVK_FIRMA = <?= json_encode($siteTitle) ?>;
let sevkYetkililer = [];
let sevkUrunler = [];

function sevkEsc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function sevkAdSoyad(y) {
    return ((y.CariYetkili_Adi || '') + ' ' + (y.CariYetkili_Soyadi || '')).trim();
}

function openSevkModal(faturaNo, vkn, unvan) {
    $('#sevkFaturaNo').text(faturaNo);
    $('#sevkUnvan').text(unvan || '-');
    $('#sevkVkn').val(vkn);
    $('#sevkContent, #btnSevkYazdir').hide();
    $('#sevkYetkiliBilgi').hide();
    $('#sevkLoading').show();
    sevkYetkililer = [];
    sevkUrunler = [];

    const myModal = new bootstrap.Modal(document.getElementById('sevkModal'));
    myModal.show();

    const p1 = $.post('', { action: 'get_sevk_yetkililer', vkn: vkn }, null, 'json');
    const p2 = $.post('', { action: 'get_invoice_lines', fatura_no: faturaNo }, null, 'json');

    $.when(p1, p2).done(function(r1, r2) {
        const resp1 = r1[0], resp2 = r2[0];
        $('#sevkLoading').hide();
        $('#sevkContent').show();

        // Yetkili dropdown
        const sel = $('#sevkYetkiliSelect');
        if (sel.hasClass('select2-hidden-accessible')) sel.select2('destroy');
        sel.empty().append('<option value="">-- Yetkili Seçiniz --</option>');
        if (resp1.success && resp1.yetkililer && resp1.yetkililer.length) {
            sevkYetkililer = resp1.yetkililer;
            sevkYetkililer.forEach((y, i) => {
                sel.append('<option value="' + i + '">' + sevkEsc(sevkAdSoyad(y)) +
                    (y.CariBirim_Adi ? ' — ' + sevkEsc(y.CariBirim_Adi) : '') + '</option>');
            });
            $('#sevkYetkiliUyari').text('');
        } else {
            $('#sevkYetkiliUyari').html('<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Bu cariye tanımlı yetkili bulunamadı. Cari kartından yetkili ekleyebilirsiniz.</span>');
        }
        sel.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#sevkModal') });
        sel.val('').trigger('change');

        // Ürünler
        const body = $('#sevkUrunBody');
        body.empty();
        if (resp2.success && resp2.data && resp2.data.length) {
            sevkUrunler = resp2.data;
            sevkUrunler.forEach((k, i) => {
                const miktar = parseFloat(k.miktar || 0).toLocaleString('tr-TR', { maximumFractionDigits: 3 });
                body.append('<tr><td class="text-center">' + (i + 1) + '</td><td>' + sevkEsc(k.isim || '') +
                    '</td><td class="text-end">' + miktar + ' ' + sevkEsc(k.birim || '') + '</td></tr>');
            });
            $('#btnSevkYazdir').show();
        } else {
            body.append('<tr><td colspan="3" class="text-center text-muted">Fatura kalemi bulunamadı.</td></tr>');
        }
    }).fail(function() {
        $('#sevkLoading').hide();
        Swal.fire('Hata', 'Sevk bilgileri alınırken hata oluştu.', 'error');
    });
}

// Yetkili seçilince bilgi kartını doldur
$(document).on('change', '#sevkYetkiliSelect', function() {
    const idx = $(this).val();
    if (idx === '' || !sevkYetkililer[idx]) { $('#sevkYetkiliBilgi').hide(); return; }
    const y = sevkYetkililer[idx];
    $('#sevkAdSoyad').text(sevkAdSoyad(y) || '-');
    $('#sevkBirim').text(y.CariBirim_Adi ? '(' + y.CariBirim_Adi + ')' : '');
    $('#sevkTelefon').text(y.CariYetkili_Telefon || '-');
    const si = [y.SehirAdi, y.IlceAdi].filter(Boolean).join(' / ');
    $('#sevkSehirIlce').text(si || '-');
    $('#sevkAdres').text(y.CariYetkili_Adres || '-');
    $('#sevkYetkiliBilgi').show();
});

// Yazdır
$(document).on('click', '#btnSevkYazdir', function() {
    const idx = $('#sevkYetkiliSelect').val();
    const y = (idx !== '' && sevkYetkililer[idx]) ? sevkYetkililer[idx] : null;
    const faturaNo = $('#sevkFaturaNo').text();
    const unvan = $('#sevkUnvan').text();

    const adSoyad  = y ? sevkAdSoyad(y) : '';
    const birim    = y && y.CariBirim_Adi ? y.CariBirim_Adi : '';
    const telefon  = y ? (y.CariYetkili_Telefon || '') : '';
    const sehirIlce= y ? [y.SehirAdi, y.IlceAdi].filter(Boolean).join(' / ') : '';
    const adres    = y ? (y.CariYetkili_Adres || '') : '';
    const bugun    = new Date().toLocaleDateString('tr-TR');

    let urunRows = '';
    sevkUrunler.forEach((k, i) => {
        const miktar = parseFloat(k.miktar || 0).toLocaleString('tr-TR', { maximumFractionDigits: 3 });
        urunRows += '<tr><td class="c">' + (i + 1) + '</td><td>' + sevkEsc(k.isim || '') +
            '</td><td class="r">' + miktar + ' ' + sevkEsc(k.birim || '') + '</td></tr>';
    });
    if (!urunRows) urunRows = '<tr><td colspan="3" class="c">Ürün bulunamadı</td></tr>';

    const html =
    '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Sevk Çıktısı - ' + sevkEsc(faturaNo) + '</title>' +
    '<style>' +
    '*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#222;margin:24px}' +
    '.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #333;padding-bottom:10px;margin-bottom:16px}' +
    '.firma{font-size:18px;font-weight:bold}.baslik{font-size:16px;font-weight:bold;text-transform:uppercase}' +
    '.meta{font-size:12px;color:#555;text-align:right}' +
    '.box{border:1px solid #ccc;border-radius:6px;padding:10px 12px;margin-bottom:16px}' +
    '.box h4{margin:0 0 6px;font-size:13px;text-transform:uppercase;color:#444;border-bottom:1px solid #eee;padding-bottom:4px}' +
    '.row{display:flex;flex-wrap:wrap}.row div{width:50%;padding:2px 0}.row .full{width:100%}' +
    '.lbl{color:#777;display:inline-block;min-width:90px}' +
    'table{width:100%;border-collapse:collapse;margin-top:4px}th,td{border:1px solid #bbb;padding:6px 8px;font-size:12px}' +
    'th{background:#f0f0f0;text-align:left}.c{text-align:center}.r{text-align:right}' +
    '.imza{display:flex;justify-content:space-between;margin-top:50px}.imza div{width:45%;text-align:center;border-top:1px solid #333;padding-top:6px;font-size:12px}' +
    '@media print{body{margin:12mm}}' +
    '</style></head><body>' +
    '<div class="head"><div><div class="firma">' + sevkEsc(SEVK_FIRMA) + '</div></div>' +
    '<div class="meta"><div class="baslik">Sevk Fişi</div>Fatura No: <b>' + sevkEsc(faturaNo) + '</b><br>Tarih: ' + bugun + '</div></div>' +
    '<div class="box"><h4>Sevk Edilecek</h4>' +
    '<div class="row">' +
    '<div class="full"><span class="lbl">Ünvan:</span> <b>' + sevkEsc(unvan) + '</b></div>' +
    '<div><span class="lbl">Yetkili:</span> ' + sevkEsc(adSoyad || '-') + (birim ? ' (' + sevkEsc(birim) + ')' : '') + '</div>' +
    '<div><span class="lbl">Telefon:</span> ' + sevkEsc(telefon || '-') + '</div>' +
    '<div><span class="lbl">Şehir/İlçe:</span> ' + sevkEsc(sehirIlce || '-') + '</div>' +
    '<div class="full"><span class="lbl">Adres:</span> ' + sevkEsc(adres || '-') + '</div>' +
    '</div></div>' +
    '<table><thead><tr><th class="c" style="width:45px">#</th><th>Ürün / Hizmet</th><th class="r" style="width:130px">Miktar</th></tr></thead>' +
    '<tbody>' + urunRows + '</tbody></table>' +
    '<div class="imza"><div>Teslim Eden</div><div>Teslim Alan</div></div>' +
    '</body></html>';

    const w = window.open('', '_blank', 'width=900,height=650');
    if (!w) { Swal.fire('Uyarı', 'Yazdırma penceresi açılamadı. Pop-up engelleyiciyi kapatın.', 'warning'); return; }
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(function() { w.print(); }, 400);
});
</script>
</body>
</html>
