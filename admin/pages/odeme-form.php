<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/FaturaKur.php';
requireAuth();
$user = Auth::user();
$db   = Database::getInstance();
$currentPageFile = 'odeme-yonetimi.php'; // Yetkileri odeme-yonetimi'dan al
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Yetki yok']); exit; }
    PageAuth::accessDenied();
}
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
$pageTitle = 'Ödeme Formu';

require_once __DIR__ . '/../includes/CariDevirHelper.php';

/**
 * kendini_gor yetkisinde cari kısıtı.
 * Ödeme dekontu eklemek için fatura şartı aranmaz; kullanıcının temas ettiği
 * tüm cariler açılır: fatura kestiği + cari kartını açtığı + teklif hazırladığı
 * + kendisine devredilenler. Yetki yoksa boş döner.
 */
function odemeFormYetkiKosul(string $cariKolon, array &$params): string
{
    global $pagePermissions, $user;

    if (empty($pagePermissions['can_view_own_records'])) {
        return '';
    }

    $uid = (int)$user['kullanici_id'];

    // Soru isareti sirasi asagidaki EXISTS bloklariyla ayni olmali
    $params[] = $uid; // fatura kesen
    $params[] = $uid; // cari kartini acan
    $params[] = $uid; // teklif hazirlayan

    $sahiplik = "EXISTS (
            SELECT 1 FROM dbo.FaturalarGiden fgy
            WHERE fgy.FaturalarGiden_Cari_id = {$cariKolon}
              AND fgy.Durum = 1
              AND fgy.OlusturanKullanici = ?
        )
        OR EXISTS (
            SELECT 1 FROM dbo.Cari cy
            WHERE cy.cari_id = {$cariKolon}
              AND cy.cari_olusturan_kullanici = ?
        )
        OR EXISTS (
            SELECT 1 FROM dbo.Teklifler ty
            WHERE ty.Teklifler_cari_id = {$cariKolon}
              AND ty.Durum = 1
              AND ty.Teklifler_hazirlayan_id = ?
        )";

    return " AND (" . CariDevirHelper::orDevir($sahiplik, $uid, $cariKolon) . ") ";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    register_shutdown_function(function(){
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            echo json_encode(['success'=>false,'message'=>'PHP Fatal: '.$err['message'].' ('.$err['file'].':'.$err['line'].')']);
        }
    });
    $action = $_POST['action'] ?? '';
    try {
        // kendini_gor yetkisinde cari veya link kullanıcının kümesinde olmalı
        if (!empty($pagePermissions['can_view_own_records'])) {
            if (intval($_POST['cari_id'] ?? 0) > 0) {
                $erisimParams = [intval($_POST['cari_id'])];
                $erisimYetki  = odemeFormYetkiKosul('c.cari_id', $erisimParams);
                if (!$db->fetchOne("SELECT 1 AS var FROM dbo.Cari c WHERE c.cari_id = ? $erisimYetki", $erisimParams)) {
                    throw new Exception('Bu cari için işlem yetkiniz yok.');
                }
            }
            if (intval($_POST['link_id'] ?? 0) > 0) {
                $erisimParams = [intval($_POST['link_id'])];
                $erisimYetki  = odemeFormYetkiKosul('h.Halkode_Odeme_Linkleri_Cari_id', $erisimParams);
                if (!$db->fetchOne("SELECT 1 AS var FROM dbo.Halkode_Odeme_Linkleri h WHERE h.Halkode_Odeme_Linkleri_id = ? $erisimYetki", $erisimParams)) {
                    throw new Exception('Bu ödeme linkine erişim yetkiniz yok.');
                }
            }
        }

        switch ($action) {
            case 'get_faturalar':
                $cari_id = intval($_POST['cari_id'] ?? 0);
                // Tutar faturanin kendi para biriminde tutulur. Liste eskiden ham
                // tutari '₺' etiketiyle gosteriyordu (237,60 USD -> "237,60 ₺").
                // Artik odeme-yonetimi ile ayni hesap kullanilir (bkz. FaturaKur.php).
                $rows = $db->fetchAll("
                    SELECT fg.FaturalarGiden_id, fg.FaturalarGiden_FaturaNo,
                           fg.FaturalarGiden_Durum,
                           fg.FaturalarGiden_Tutar AS doviz_tutar,
                           UPPER(ISNULL(fg.FaturalarGiden_ParaBirimi, 'TRY')) AS para_birimi,
                           " . FG_TL_TUTAR . " AS tutar,
                           " . FG_KUR . "      AS kur,
                           " . FG_KURSUZ . "   AS kursuz,
                           ISNULL(fg.FaturalarGiden_StokIslendi, 0) AS stok_islendi,
                           -- Satis faturasinin stok cikisi geri alindiysa iade (GIRIS) kaydi olusur.
                           CASE WHEN EXISTS (
                               SELECT 1 FROM Urun_Stok_Hareketleri sh
                               WHERE sh.Fatura_id = fg.FaturalarGiden_id
                                 AND sh.Islem_Tipi = 'GIRIS' AND sh.Durum = 1
                           ) THEN 1 ELSE 0 END AS stok_iade,
                           CONVERT(VARCHAR(10), ISNULL(fg.FaturalarGiden_Tarih, fg.OlusturmaTarihi), 104) AS tarih
                    FROM FaturalarGiden fg
                    " . FG_KUR_APPLY . "
                    WHERE fg.FaturalarGiden_Cari_id = $cari_id
                      AND fg.Durum = 1
                      AND UPPER(ISNULL(fg.FaturalarGiden_Durum, '')) != 'CANCELLED'
                      -- GİB İptal/İade Portalı üzerinden iptal edilen faturada zarf durumu
                      -- SUCCEED kalır; borç listesinden bu bayrakla düşürülür.
                      AND ISNULL(fg.FaturalarGiden_HariciIptal, 0) = 0
                    ORDER BY fg.FaturalarGiden_id DESC
                ");
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;

            case 'get_gelen_faturalar':
                // Gelen fatura cariye id ile degil VKN uzerinden baglidir
                // (FaturalarGelen tablosunda cari_id kolonu yoktur).
                $cari_id = intval($_POST['cari_id'] ?? 0);
                $rows = $db->fetchAll("
                    SELECT fge.FaturalarGelen_id, fge.FaturalarGelen_FaturaNo,
                           fge.FaturalarGelen_Durum,
                           fge.FaturalarGelen_Tutar AS doviz_tutar,
                           UPPER(ISNULL(fge.FaturalarGelen_ParaBirimi, 'TRY')) AS para_birimi,
                           " . FGE_TL_TUTAR . " AS tutar,
                           " . FGE_KUR . "      AS kur,
                           " . FGE_KURSUZ . "   AS kursuz,
                           ISNULL(fge.FaturalarGelen_StokIslendi, 0) AS stok_islendi,
                           -- Alis faturasinin stok girisi geri alindiysa iade (CIKIS) kaydi olusur.
                           CASE WHEN EXISTS (
                               SELECT 1 FROM Urun_Stok_Hareketleri sh
                               WHERE sh.Fatura_id = fge.FaturalarGelen_id
                                 AND sh.Islem_Tipi = 'CIKIS' AND sh.Durum = 1
                           ) THEN 1 ELSE 0 END AS stok_iade,
                           CONVERT(VARCHAR(10), ISNULL(fge.FaturalarGelen_Tarih, fge.OlusturmaTarihi), 104) AS tarih
                    FROM FaturalarGelen fge
                    JOIN Cari c ON fge.FaturalarGelen_VKN_TCKN = c.cari_vergi_no
                    " . FGE_KUR_APPLY . "
                    WHERE c.cari_id = $cari_id
                      AND fge.Durum = 1
                    ORDER BY fge.FaturalarGelen_id DESC
                ");
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;




            case 'get_personeller':
                $rows = $db->fetchAll("SELECT kullanici_id as id, kullanici_ad+' '+kullanici_soyad as text FROM kullanicilar WHERE kullanici_durum=1 ORDER BY kullanici_ad");
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;

            case 'get_bankalar':
                $rows = $db->fetchAll("SELECT Kanal_Adi as id, Kanal_Adi as text FROM EntegrasyonKanallari WHERE Kanal_Entegrasyon_Id = '13' ORDER BY Kanal_Adi");
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;

            // Formun ust kismindaki uc InfoBox icin cari ozeti.
            // DIKKAT: Bu sayfa carinin TUM odeme kalemlerini tabloda duzenlettigi icin
            // (bkz. get_odeme_gecmisi) buradaki "Net Borc" tahsilati ICERMEZ; aksi halde
            // ayni tutar hem kutuda hem tabloda dusulup cift sayilirdi.
            //   Net Borc            = Giden Fatura - MIN(Gelen Fatura, Giden Fatura)
            //   Bu Odeme Toplami    = tablodaki kalemler (JS tarafinda)
            //   Odeme Sonrasi Kalan = Net Borc - Bu Odeme Toplami
            // Mahsup sinirli uygulanir; gelen fatura giden faturayi asamaz (bkz.
            // odeme-yonetimi.php SINIRLI MAHSUP kurali).
            case 'get_cari_ozet':
                $cari_id = intval($_POST['cari_id'] ?? 0);
                $ozet = $db->fetchOne("
                    SELECT
                        ISNULL(gd.tutar, 0) AS giden_toplam,
                        ISNULL(gd.adet, 0)  AS giden_adet,
                        ISNULL(gl.tutar, 0) AS gelen_toplam,
                        ISNULL(gl.adet, 0)  AS gelen_adet,
                        CASE WHEN ISNULL(gl.tutar, 0) >= ISNULL(gd.tutar, 0)
                             THEN ISNULL(gd.tutar, 0) ELSE ISNULL(gl.tutar, 0) END AS mahsup,
                        ISNULL(gd.tutar, 0)
                            - CASE WHEN ISNULL(gl.tutar, 0) >= ISNULL(gd.tutar, 0)
                                   THEN ISNULL(gd.tutar, 0) ELSE ISNULL(gl.tutar, 0) END AS net_borc
                    FROM Cari c
                    OUTER APPLY (
                        SELECT ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                                               THEN " . FG_TL_TUTAR . " ELSE 0 END), 0) AS tutar,
                               COUNT(*) AS adet
                        FROM FaturalarGiden fg
                        " . FG_KUR_APPLY . "
                        WHERE fg.FaturalarGiden_Cari_id = c.cari_id
                          AND fg.Durum = 1
                          AND UPPER(ISNULL(fg.FaturalarGiden_Durum, '')) != 'CANCELLED'
                          AND ISNULL(fg.FaturalarGiden_HariciIptal, 0) = 0
                    ) gd
                    OUTER APPLY (
                        SELECT ISNULL(SUM(CASE WHEN ISNULL(fge.FaturalarGelen_StokIslendi, 0) = 1
                                               THEN " . FGE_TL_TUTAR . " ELSE 0 END), 0) AS tutar,
                               COUNT(*) AS adet
                        FROM FaturalarGelen fge
                        " . FGE_KUR_APPLY . "
                        WHERE fge.FaturalarGelen_VKN_TCKN = NULLIF(c.cari_vergi_no, '')
                          AND fge.Durum = 1
                    ) gl
                    WHERE c.cari_id = ?
                ", [$cari_id]);
                echo json_encode(['success'=>true,'data'=>$ozet]);
                break;

            case 'get_odeme_gecmisi':
                $cari_id = intval($_POST['cari_id'] ?? 0);
                $rows = $db->fetchAll("
                    SELECT
                        ok.Odeme_Kalemleri_id,
                        ok.Odeme_Kalemleri_tip,
                        ok.Odeme_Kalemleri_belge_no,
                        CONVERT(VARCHAR(10), ok.Odeme_Kalemleri_vade, 23) AS vade,
                        ok.Odeme_Kalemleri_tutar,
                        ISNULL(k.kullanici_ad+' '+k.kullanici_soyad, '') AS personel_adi,
                        ok.Odeme_Kalemleri_personel_id,
                        ok.Odeme_Kalemleri_banka,
                        ok.Odeme_Kalemleri_dokuman,
                        ok.Odeme_Kalemleri_aciklama,
                        ok.Odeme_Kalemleri_odendi,
                        CONVERT(VARCHAR(10), ok.Odeme_Kalemleri_odeme_tar, 23) AS odeme_tarihi,
                        CONVERT(VARCHAR(10), o.OlusturmaTarihi, 23) AS kayit_tarihi
                    FROM Odeme_Kalemleri ok
                    JOIN Odemeler o ON ok.Odeme_Kalemleri_odeme_id = o.Odemeler_id
                    LEFT JOIN kullanicilar k ON ok.Odeme_Kalemleri_personel_id = k.kullanici_id
                    WHERE o.Odemeler_cari_id = $cari_id AND o.Durum = 1 AND ok.Durum = 1
                    ORDER BY ok.Odeme_Kalemleri_id DESC
                ");
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;


            case 'upload':
                if (!isset($_FILES['dosya'])) throw new Exception('Dosya alınamadı. Dosya boyutu sunucu limitini aşmış olabilir.');
                $f = $_FILES['dosya'];
                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $hatalar = [
                        UPLOAD_ERR_INI_SIZE   => 'Dosya boyutu sunucu limitini aşıyor.',
                        UPLOAD_ERR_FORM_SIZE  => 'Dosya boyutu form limitini aşıyor.',
                        UPLOAD_ERR_PARTIAL    => 'Dosya kısmen yüklendi, tekrar deneyin.',
                        UPLOAD_ERR_NO_FILE    => 'Dosya seçilmedi.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Sunucu geçici klasörü bulunamadı.',
                        UPLOAD_ERR_CANT_WRITE => 'Dosya diske yazılamadı.',
                        UPLOAD_ERR_EXTENSION  => 'Yükleme bir eklenti tarafından durduruldu.',
                    ];
                    throw new Exception($hatalar[$f['error']] ?? 'Dosya yüklenemedi.');
                }
                if (!is_uploaded_file($f['tmp_name'])) throw new Exception('Geçersiz dosya.');

                $izinliUzantilar = ['jpg', 'jpeg', 'png', 'pdf'];
                $maxBoyut = 10 * 1024 * 1024; // 10 MB
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $izinliUzantilar, true)) {
                    throw new Exception('Sadece JPG, PNG ve PDF dosyaları yüklenebilir.');
                }
                if ($f['size'] > $maxBoyut) {
                    throw new Exception('Dosya boyutu en fazla 10 MB olabilir.');
                }
                $izinliMime = ['image/jpeg', 'image/png', 'application/pdf'];
                $mime = function_exists('finfo_open')
                    ? finfo_file(($fi = finfo_open(FILEINFO_MIME_TYPE)), $f['tmp_name'])
                    : null;
                if (isset($fi)) finfo_close($fi);
                if ($mime !== null && !in_array($mime, $izinliMime, true)) {
                    throw new Exception('Dosya içeriği izin verilen türlerle uyuşmuyor.');
                }

                $dir = __DIR__ . '/../../uploads/odeme/';
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new Exception('Yükleme klasörü oluşturulamadı.');
                }
                $name = 'odeme_' . time() . '_' . rand(100,999) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
                    throw new Exception('Dosya sunucuya kaydedilemedi.');
                }
                echo json_encode(['success'=>true,'path'=>'/uploads/odeme/'.$name,'name'=>$f['name']]);
                break;

            // ── HalkÖde ödeme linki ────────────────────────────
            // Link VPOS'ta üretilir, gönderimi biz yaparız (VPOS SMS/mail atmaz).
            // Ödeme sonucu callback ile Odeme_Kalemleri'ne düşer.
            case 'halkode_link_olustur':
                if (!$pagePermissions['can_add']) throw new Exception('Yetki yok.');
                require_once __DIR__ . '/../includes/HalkodeService.php';

                $servis   = new HalkodeService($db);
                $kanallar = array_values(array_intersect(
                    (array)json_decode($_POST['kanallar'] ?? '[]', true),
                    ['sms', 'mail']
                ));

                $uretilen = $servis->linkOlustur([
                    'cari_id'      => intval($_POST['cari_id'] ?? 0),
                    'tutar'        => floatval(str_replace(',', '.', $_POST['tutar'] ?? 0)),
                    'taksit'       => intval($_POST['taksit'] ?? 1),
                    'aciklama'     => trim($_POST['aciklama'] ?? ''),
                    'telefon'      => trim($_POST['telefon'] ?? ''),
                    'email'        => trim($_POST['email'] ?? ''),
                    'kullanici_id' => $user['kullanici_id'],
                ]);

                $gonderim = ['hatalar' => []];

                if ($kanallar) {
                    $gonderim = $servis->linkGonder($uretilen['link_id'], $kanallar, $user['kullanici_id']);
                }

                echo json_encode([
                    'success'  => true,
                    'link'     => $uretilen['link'],
                    'link_id'  => $uretilen['link_id'],
                    'gonderim' => $gonderim,
                    'message'  => 'Ödeme linki oluşturuldu.',
                ]);
                break;

            case 'halkode_linkler':
                $cari_id = intval($_POST['cari_id'] ?? 0);
                $rows = $db->fetchAll("
                    SELECT TOP 20
                           h.Halkode_Odeme_Linkleri_id       AS id,
                           h.Halkode_Odeme_Linkleri_Tutar    AS tutar,
                           h.Halkode_Odeme_Linkleri_Taksit   AS taksit,
                           h.Halkode_Odeme_Linkleri_DurumKod AS durum,
                           h.Halkode_Odeme_Linkleri_Link     AS link,
                           h.Halkode_Odeme_Linkleri_GonderimKanali AS kanal,
                           h.Halkode_Odeme_Linkleri_OdemeKalem_id  AS kalem_id,
                           CONVERT(VARCHAR(16), h.OlusturmaTarihi, 120)                   AS olusturma,
                           CONVERT(VARCHAR(16), h.Halkode_Odeme_Linkleri_OdemeTarihi, 120) AS odeme_tarihi
                    FROM dbo.Halkode_Odeme_Linkleri h
                    WHERE h.Durum = 1 AND h.Halkode_Odeme_Linkleri_Cari_id = ?
                    ORDER BY h.Halkode_Odeme_Linkleri_id DESC
                ", [$cari_id]);

                // Gönderim hedefi cari kartı veya cariye bağlı yetkililerden seçilir
                require_once __DIR__ . '/../includes/HalkodeService.php';
                $kisiler = (new HalkodeService($db))->iletisimKisileri($cari_id);

                echo json_encode(['success'=>true, 'data'=>$rows, 'kisiler'=>$kisiler]);
                break;

            case 'halkode_durum_sorgula':
                require_once __DIR__ . '/../includes/HalkodeService.php';

                $servis = new HalkodeService($db);
                $sonuc  = $servis->durumSorgulaVeIsle(intval($_POST['link_id'] ?? 0), $user['kullanici_id']);

                echo json_encode([
                    'success' => true,
                    'durum'   => $sonuc['durum'],
                    'islendi' => $sonuc['islendi'],
                    'message' => $sonuc['durum'] === 'ODENDI'
                        ? ($sonuc['islendi'] ? 'Ödeme alındı, ödeme kalemi oluşturuldu.' : 'Ödeme daha önce işlenmiş.')
                        : 'Ödeme henüz tamamlanmamış.',
                ]);
                break;

            case 'save':
                if (!$pagePermissions['can_add']) throw new Exception('Yetki yok.');
                $cari_id   = intval($_POST['cari_id'] ?? 0);
                $aciklama  = trim($_POST['aciklama'] ?? '');
                $satirlar  = json_decode($_POST['satirlar'] ?? '[]', true);
                $silinenler = array_filter(array_map('intval', json_decode($_POST['silinenler'] ?? '[]', true)));
                if (!$cari_id) throw new Exception('Cari seçilmedi.');

                // Hiçbir alanı doldurulmamış YENİ satırlar kaydedilmez (boş kayıt birikmesini önler).
                // Kayıtlı satırlar (kalem_id dolu) filtreye girmez; onlar silme işlemiyle kaldırılır.
                $satirBosMu = function ($s) {
                    return floatval($s['tutar'] ?? 0) == 0
                        && trim((string)($s['belge_no'] ?? '')) === ''
                        && trim((string)($s['vade'] ?? '')) === ''
                        && trim((string)($s['dokuman'] ?? '')) === ''
                        && trim((string)($s['aciklama'] ?? '')) === ''
                        && intval($s['personel_id'] ?? 0) === 0
                        && trim((string)($s['banka'] ?? '')) === ''
                        && empty($s['odendi'])
                        && trim((string)($s['odeme_tarihi'] ?? '')) === '';
                };
                $satirlar = array_values(array_filter((array)$satirlar,
                    fn($s) => !empty($s['kalem_id']) || !$satirBosMu($s)));

                // Ödeme tipi tanım tablosunda aktif bir kod olmalıdır
                $gecerliTipler = array_column($db->fetchAll("SELECT tanim_OdemeTipleri_Kod FROM dbo.tanim_OdemeTipleri WHERE Durum = 1"), 'tanim_OdemeTipleri_Kod');
                foreach ((array)$satirlar as $i => $s) {
                    if (!in_array($s['tip'] ?? '', $gecerliTipler, true)) {
                        throw new Exception(($i + 1) . '. satırda geçerli bir ödeme tipi seçilmelidir.');
                    }
                }

                // Ödendi işaretli satırlarda ödeme tarihi zorunludur
                foreach ((array)$satirlar as $i => $s) {
                    $odendi = isset($s['odendi']) && $s['odendi'];
                    if ($odendi && trim($s['odeme_tarihi'] ?? '') === '') {
                        throw new Exception(($i + 1) . '. satır "Ödendi" işaretli, ödeme tarihi girilmelidir.');
                    }
                }

                $conn = $db->getConnection();
                sqlsrv_begin_transaction($conn);
                try {
                    // 1. Silinen kalem satırları
                    foreach ($silinenler as $kalem_id) {
                        $db->execute("UPDATE Odeme_Kalemleri SET Durum=0, GuncelleyenKullanici=?, GuncellemeTarihi=? WHERE Odeme_Kalemleri_id=?",
                            [$user['kullanici_id'], date('Y-m-d H:i:s'), $kalem_id]);
                    }

                    // 2. Güncellenecek satırlar (kalem_id mevcut)
                    $guncellenenler = array_filter($satirlar, fn($s) => !empty($s['kalem_id']));
                    foreach ($guncellenenler as $s) {
                        $params = [
                            $s['tip'],
                            $s['belge_no'] ?? '',
                            ($s['vade'] ?? '') ?: null,
                            floatval($s['tutar'] ?? 0),
                            intval($s['personel_id'] ?? 0) ?: null,
                            $s['banka'] ?? '',
                        ];
                        // Döküman yalnızca yeni bir değer geldiyse güncellenir;
                        // boş gelirse mevcut kayıt korunur (üzerine yazıp silmemek için).
                        $dokumanSet = '';
                        if (trim($s['dokuman'] ?? '') !== '') {
                            $dokumanSet = 'Odeme_Kalemleri_dokuman=?,';
                            $params[]   = trim($s['dokuman']);
                        }
                        array_push($params,
                            $s['aciklama'] ?? '',
                            isset($s['odendi']) && $s['odendi'] ? 1 : 0,
                            ($s['odeme_tarihi'] ?? '') ?: null,
                            $user['kullanici_id'],
                            date('Y-m-d H:i:s'),
                            intval($s['kalem_id'])
                        );
                        $db->execute("
                            UPDATE Odeme_Kalemleri SET
                                Odeme_Kalemleri_tip=?, Odeme_Kalemleri_belge_no=?, Odeme_Kalemleri_vade=?,
                                Odeme_Kalemleri_tutar=?, Odeme_Kalemleri_personel_id=?, Odeme_Kalemleri_banka=?,
                                $dokumanSet
                                Odeme_Kalemleri_aciklama=?, Odeme_Kalemleri_odendi=?,
                                Odeme_Kalemleri_odeme_tar=?, GuncelleyenKullanici=?, GuncellemeTarihi=?
                            WHERE Odeme_Kalemleri_id=?", $params);
                    }

                    // 3. Yeni satırlar (kalem_id yok)
                    $yeniler = array_values(array_filter($satirlar, fn($s) => empty($s['kalem_id'])));
                    if (!empty($yeniler)) {
                        $toplam = array_sum(array_column($yeniler, 'tutar'));
                        $db->insert('Odemeler', [
                            'Odemeler_cari_id'     => $cari_id,
                            'Odemeler_tarih'       => date('Y-m-d'),
                            'Odemeler_toplam'      => $toplam,
                            'Odemeler_aciklama'    => $aciklama,
                            'Odemeler_durum'       => 1,
                            'OlusturanKullanici'   => $user['kullanici_id'],
                            'OlusturmaTarihi'      => date('Y-m-d H:i:s'),
                            'GuncelleyenKullanici' => $user['kullanici_id'],
                            'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                            'Durum'                => 1,
                        ]);
                        $odeme_id = $db->getLastInsertId();
                        foreach ($yeniler as $s) {
                            $db->insert('Odeme_Kalemleri', [
                                'Odeme_Kalemleri_odeme_id'    => $odeme_id,
                                'Odeme_Kalemleri_tip'         => $s['tip'],
                                'Odeme_Kalemleri_belge_no'    => $s['belge_no'] ?? '',
                                'Odeme_Kalemleri_vade'        => ($s['vade'] ?? '') ?: null,
                                'Odeme_Kalemleri_tutar'       => floatval($s['tutar'] ?? 0),
                                'Odeme_Kalemleri_personel_id' => intval($s['personel_id'] ?? 0) ?: null,
                                'Odeme_Kalemleri_banka'       => $s['banka'] ?? '',
                                'Odeme_Kalemleri_dokuman'     => $s['dokuman'] ?? '',
                                'Odeme_Kalemleri_aciklama'    => $s['aciklama'] ?? '',
                                'Odeme_Kalemleri_odendi'      => isset($s['odendi']) && $s['odendi'] ? 1 : 0,
                                'Odeme_Kalemleri_odeme_tar'   => ($s['odeme_tarihi'] ?? '') ?: null,
                                'OlusturanKullanici'          => $user['kullanici_id'],
                                'OlusturmaTarihi'             => date('Y-m-d H:i:s'),
                                'GuncelleyenKullanici'        => $user['kullanici_id'],
                                'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
                                'Durum'                       => 1,
                            ]);
                        }
                    }

                    sqlsrv_commit($conn);
                    echo json_encode(['success'=>true,'message'=>'Ödeme kaydedildi.']);
                } catch (\Throwable $ex) { sqlsrv_rollback($conn); throw $ex; }
                break;

            default: throw new Exception('Geçersiz işlem.');
        }
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

$cariParams = [];
$cariYetki  = odemeFormYetkiKosul('c.cari_id', $cariParams);
$cariler    = $db->fetchAll("SELECT c.cari_id, ISNULL(c.cari_unvan, c.cari_adi) as cari_adi FROM Cari c WHERE c.cari_aktif=1 $cariYetki ORDER BY ISNULL(c.cari_unvan, c.cari_adi)", $cariParams);
$personellerDB = $db->fetchAll("SELECT kullanici_id as id, kullanici_ad+' '+kullanici_soyad as text FROM kullanicilar WHERE kullanici_durum=1 ORDER BY kullanici_ad");
$bankalarDB    = $db->fetchAll("SELECT Kanal_Adi as id, Kanal_Adi as text FROM EntegrasyonKanallari WHERE Kanal_Entegrasyon_Id = '13' ORDER BY Kanal_Adi");
$odemeTipleriDB = $db->fetchAll("SELECT tanim_OdemeTipleri_Kod, tanim_OdemeTipleri_Adi, tanim_OdemeTipleri_Hesap FROM dbo.tanim_OdemeTipleri WHERE Durum = 1 ORDER BY tanim_OdemeTipleri_Sira");
$odemeTipleriJS = [];
foreach ($odemeTipleriDB as $t) {
    $odemeTipleriJS[$t['tanim_OdemeTipleri_Kod']] = ['label' => $t['tanim_OdemeTipleri_Adi'], 'tip' => $t['tanim_OdemeTipleri_Hesap']];
}
$cari_id_get = intval($_GET['cari_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
<link rel="stylesheet" href="/Admin/assets/css/custom.css">
<style>
.odeme-tablo th { white-space:nowrap; font-size:.8rem; }
.odeme-tablo td { vertical-align:middle; padding:.3rem .4rem; }
.odeme-tablo input, .odeme-tablo select { font-size:.82rem; }

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
            <li class="breadcrumb-item"><a href="/Admin/odeme-yonetimi">Ödeme Yönetimi</a></li>
            <li class="breadcrumb-item active">Yeni Ödeme</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
  <div class="app-content">
    <div class="container-fluid">

      <!-- 1. Cari Seçimi -->
      <div class="card card-primary card-outline mb-3">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-building"></i> 1. Firma (Cari) Bilgileri</h3></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Cari <span class="text-danger">*</span></label>
              <select id="cari_id" class="form-select">
                <option value="">Seçiniz...</option>
                <?php foreach($cariler as $c): ?>
                <option value="<?=$c['cari_id']?>" <?=$c['cari_id']==$cari_id_get?'selected':''?>><?=htmlspecialchars($c['cari_adi'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Genel Açıklama</label>
              <input type="text" id="aciklama" class="form-control" placeholder="Bu ödeme için not...">
            </div>
          </div>
        </div>
      </div>


      <!-- Cari ozeti: cari secilince gorunur, tutar girildikce canli guncellenir -->
      <div class="row mb-3" id="ozet-bolum" style="display:none">
        <div class="col-sm-6 col-lg-4 mb-3">
          <div class="info-box text-bg-primary h-100">
            <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
            <div class="info-box-content">
              <span class="info-box-text" title="Giden faturalar toplamı; cariden gelen faturalar mahsup edilmiştir">Net Borç</span>
              <span class="info-box-number" id="ozet_net_borc">0,00 &#8378;</span>
              <span class="progress-description" id="ozet_net_borc_alt">&nbsp;</span>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-4 mb-3">
          <div class="info-box text-bg-success h-100">
            <span class="info-box-icon"><i class="bi bi-credit-card-2-front"></i></span>
            <div class="info-box-content">
              <span class="info-box-text" title="Aşağıdaki tabloda girilen tüm ödeme kalemlerinin toplamı">Bu Ödeme Toplamı</span>
              <span class="info-box-number" id="ozet_odeme">0,00 &#8378;</span>
              <span class="progress-description" id="ozet_odeme_alt">&nbsp;</span>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-4 mb-3">
          <div class="info-box text-bg-warning h-100">
            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
            <div class="info-box-content">
              <span class="info-box-text" title="Net Borç - Bu Ödeme Toplamı">Ödeme Sonrası Kalan</span>
              <span class="info-box-number" id="ozet_kalan">0,00 &#8378;</span>
              <span class="progress-description" id="ozet_kalan_alt">&nbsp;</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-outline card-info mb-3" id="fatura-bolum" style="display:none">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-receipt"></i> 2. Giden Faturalar</h3>
          <div class="card-tools"><small class="text-muted">Cari'ye ait açık faturalar (Bilgi amaçlıdır)</small></div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>Fatura No</th>
                  <th>Tarih</th>
                  <th>Durum</th>
                  <th class="text-center">Para Birimi</th>
                  <th class="text-end">Fatura Tutarı</th>
                  <th class="text-end">Kur</th>
                  <th class="text-end">TL Karşılığı</th>
                </tr>
              </thead>
              <tbody id="fatura-listesi"></tbody>
              <tfoot class="table-light">
                <tr>
                  <td colspan="6" class="text-end fw-bold">TOPLAM (TL karşılığı):</td>
                  <td class="text-end fw-bold" id="giden-toplam">0,00 ₺</td>
                </tr>
                <tr id="giden-haric-satir" style="display:none">
                  <td colspan="6" class="text-end small text-muted">Bakiye dışı (İptal / İade / İşlenmemiş):</td>
                  <td class="text-end small text-muted" id="giden-haric">0,00 ₺</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <div class="card card-outline card-warning mb-3" id="gelen-fatura-bolum" style="display:none">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-receipt-cutoff"></i> 3. Gelen Faturalar</h3>
          <div class="card-tools"><small class="text-muted">Cari'den gelen faturalar (Bilgi amaçlıdır)</small></div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>Fatura No</th>
                  <th>Tarih</th>
                  <th>Durum</th>
                  <th class="text-center">Para Birimi</th>
                  <th class="text-end">Fatura Tutarı</th>
                  <th class="text-end">Kur</th>
                  <th class="text-end">TL Karşılığı</th>
                </tr>
              </thead>
              <tbody id="gelen-fatura-listesi"></tbody>
              <tfoot class="table-light">
                <tr>
                  <td colspan="6" class="text-end fw-bold">TOPLAM (TL karşılığı):</td>
                  <td class="text-end fw-bold" id="gelen-toplam">0,00 ₺</td>
                </tr>
                <tr id="gelen-haric-satir" style="display:none">
                  <td colspan="6" class="text-end small text-muted">Bakiye dışı (İptal / İade / İşlenmemiş):</td>
                  <td class="text-end small text-muted" id="gelen-haric">0,00 ₺</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <!-- 4. Ödeme Yöntemleri -->
      <div class="card card-primary card-outline mb-3">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-credit-card-2-front"></i> 4. Ödeme Yöntemleri</h3>
          <div class="card-tools">
            <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="halkodeAc()">
              <i class="bi bi-link-45deg"></i> Ödeme Linki Gönder
            </button>
            <button type="button" class="btn btn-sm btn-success" onclick="satirEkle()"><i class="bi bi-plus-lg"></i> Satır Ekle</button>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0 odeme-tablo">
              <thead class="table-light">
                <tr>
                  <th style="width:130px">Ödeme Tipi</th>
                  <th style="width:110px">Belge No</th>
                  <th style="width:110px">Vade Tarihi</th>
                  <th style="width:100px">Tutar (₺)</th>
                  <th style="width:160px">Personel / Banka</th>
                  <th style="width:120px">Döküman</th>
                  <th>Açıklama</th>
                  <th style="width:60px" class="text-center">Ödendi</th>
                  <th style="width:110px">Ödeme Tarihi</th>
                  <th style="width:36px"></th>
                </tr>
              </thead>
              <tbody id="odeme-satirlar"></tbody>
              <tfoot>
                <tr class="table-light">
                  <td colspan="3" class="text-end fw-bold">TOPLAM:</td>
                  <td class="fw-bold text-end" id="toplam-tutar">0,00 ₺</td>
                  <td colspan="6"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-between mb-4">
        <a href="/Admin/odeme-yonetimi" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Geri</a>
        <button type="button" class="btn btn-primary btn-lg" id="btnKaydet"><i class="bi bi-save"></i> Kaydet</button>
      </div>

<!-- HalkÖde ödeme linki -->
<div class="modal fade" id="halkodeModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-credit-card-2-front"></i> HalkÖde Ödeme Linki</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Fatura (isteğe bağlı)</label>
            <select id="hk_fatura" class="form-select">
              <option value="">Fatura seçilmedi</option>
            </select>
            <div class="form-text">Seçilirse tutar ve açıklama faturadan doldurulur; ikisi de elle değiştirilebilir.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Tutar (₺) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0.01" id="hk_tutar" class="form-control text-end">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Taksit</label>
            <select id="hk_taksit" class="form-select">
              <option value="0" selected>Müşteri seçsin (kart taksitleri)</option>
              <option value="1">Tek Çekim (taksit yok)</option>
              <?php for ($i = 2; $i <= 12; $i++): ?>
                <option value="<?= $i ?>"><?= $i ?> Taksit (sabit)</option>
              <?php endfor; ?>
            </select>
            <div class="form-text">
              Varsayılanda müşteri, ödeme sayfasında kartının desteklediği taksitleri görüp seçer.
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Açıklama</label>
            <input type="text" id="hk_aciklama" class="form-control" placeholder="Fatura no, dönem...">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Gönderilecek Kişi</label>
            <select id="hk_kisi" class="form-select">
              <option value="">Cari kartı bilgileri</option>
            </select>
            <div class="form-text">Cari kartı ya da cariye kayıtlı yetkililerden seçin; bilgiler aşağıya dolar.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Telefon</label>
            <input type="tel" id="hk_telefon" class="form-control" placeholder="5xxxxxxxxx">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">E-posta</label>
            <input type="email" id="hk_email" class="form-control" placeholder="musteri@ornek.com">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold d-block">Gönderim</label>
            <div class="form-check form-switch d-inline-block me-4">
              <input class="form-check-input" type="checkbox" role="switch" id="hk_sms" value="1" checked>
              <label class="form-check-label" for="hk_sms">SMS gönder</label>
            </div>
            <div class="form-check form-switch d-inline-block">
              <input class="form-check-input" type="checkbox" role="switch" id="hk_mail" value="1" checked>
              <label class="form-check-label" for="hk_mail">E-posta gönder</label>
            </div>
            <div class="form-text">Her ikisi de kapalıysa link yalnızca ekranda gösterilir.</div>
          </div>
        </div>

        <div class="alert alert-success mt-3 d-none" id="hk_sonuc">
          <div class="fw-semibold mb-1"><i class="bi bi-check-circle"></i> Ödeme linki hazır</div>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control" id="hk_link" readonly>
            <button class="btn btn-outline-secondary" type="button" onclick="halkodeKopyala()">
              <i class="bi bi-clipboard"></i> Kopyala
            </button>
          </div>
          <div class="small mt-2" id="hk_gonderim_bilgi"></div>
        </div>

        <hr>
        <div class="fw-semibold mb-2"><i class="bi bi-clock-history"></i> Bu carinin son linkleri</div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Tarih</th>
                <th class="text-end">Tutar</th>
                <th class="text-center">Taksit</th>
                <th>Durum</th>
                <th>Gönderim</th>
                <th style="width:90px"></th>
              </tr>
            </thead>
            <tbody id="hk_gecmis"><tr><td colspan="6" class="text-center text-muted">Kayıt yok</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
        <button type="button" class="btn btn-primary" id="hk_btnOlustur">
          <i class="bi bi-link-45deg"></i> Link Oluştur
        </button>
      </div>
    </div>
  </div>
</div>


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
<script src="/Admin/assets/js/custom.js"></script>
<script>
let satirSayac = 0;

// Ödeme tipleri dbo.tanim_OdemeTipleri tablosundan geliyor
const TIPLER = <?= json_encode((object)$odemeTipleriJS, JSON_UNESCAPED_UNICODE) ?>;
const VARSAYILAN_TIP = Object.keys(TIPLER)[0] || '';

// Personel ve banka listeleri PHP'den sayfa yükleme anında geliyor
let personeller = <?= json_encode($personellerDB, JSON_UNESCAPED_UNICODE) ?>;
let bankalar    = <?= json_encode($bankalarDB,    JSON_UNESCAPED_UNICODE) ?>;
// Seçili fatura mantığı kaldırıldı



// Cari select2
$('#cari_id').select2({theme:'bootstrap-5', width:'100%', placeholder:'Cari seçiniz...'});
$('#cari_id').on('change', function(){
    const cid = $(this).val();
    if(!cid){ $('#fatura-bolum, #gelen-fatura-bolum, #ozet-bolum').hide(); netBorc = 0; return; }

    // Mevcut ödemeleri editable satır olarak yükle
    $('#odeme-satirlar').find('.satir-kayitli').remove();
    $.post(window.location.href, {action:'get_odeme_gecmisi', cari_id:cid}, function(res){
        if(!res.success || !res.data.length) return;
        res.data.forEach(r => {
            const idx = satirSayac++;   // satirEkle() ile aynı sayaç mantığı (id çakışmasını önler)
            const tip = r.Odeme_Kalemleri_tip || VARSAYILAN_TIP;
            const isBanka = TIPLER[tip]?.tip === 'BANKA';
            let pbHtml;
            if(isBanka) {
                let opts = '<option value="">Banka Seçiniz...</option>';
                bankalar.forEach(b => opts += `<option value="${b.id}"${b.id===r.Odeme_Kalemleri_banka?' selected':''}>${b.text}</option>`);
                pbHtml = `<select class="form-select form-select-sm select2-dropdown" data-field="banka" onchange="guncelle()">${opts}</select>`;
            } else {
                let opts = '<option value="">Personel Seçiniz...</option>';
                personeller.forEach(p => opts += `<option value="${p.id}"${p.id==r.Odeme_Kalemleri_personel_id?' selected':''}>${p.text}</option>`);
                pbHtml = `<select class="form-select form-select-sm select2-dropdown" data-field="personel_id" onchange="guncelle()">${opts}</select>`;
            }
            let tipOpts = '';
            Object.entries(TIPLER).forEach(([k,v]) => {
                tipOpts += `<option value="${k}"${k===tip?' selected':''}>${v.label}</option>`;
            });
            const odendiChecked = r.Odeme_Kalemleri_odendi == 1 ? 'checked' : '';
            const otarDisabled = r.Odeme_Kalemleri_odendi == 1 ? '' : 'disabled';
            const row = `<tr id="satir-${idx}" class="satir-kayitli">
                <td><input type="hidden" data-field="kalem_id" value="${r.Odeme_Kalemleri_id}">
                    <select class="form-select form-select-sm" data-field="tip" onchange="tipDegisti(this,${idx})">${tipOpts}</select></td>
                <td><input type="text" class="form-control form-control-sm" data-field="belge_no" value="${r.Odeme_Kalemleri_belge_no||''}" onchange="guncelle()"></td>
                <td><input type="date" class="form-control form-control-sm" data-field="vade" value="${r.vade||''}" onchange="guncelle()"></td>
                <td><input type="number" step="0.01" class="form-control form-control-sm" data-field="tutar" value="${r.Odeme_Kalemleri_tutar||''}" oninput="toplamHesapla()"></td>
                <td id="pb-${idx}">${pbHtml}</td>
                <td>
                    <div class="d-flex gap-1 align-items-center">
                        <input type="file" id="dok-${idx}" class="d-none" onchange="dosyaYukle(this,${idx})">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="$('#dok-${idx}').click()" title="Döküman Yükle"><i class="bi bi-paperclip"></i></button>
                        <small id="dok-ad-${idx}" class="text-muted text-truncate" style="max-width:70px"></small>
                        <input type="hidden" id="dok-yol-${idx}" data-field="dokuman" value="${r.Odeme_Kalemleri_dokuman||''}">
                    </div>
                </td>
                <td><input type="text" class="form-control form-control-sm" data-field="aciklama" value="${r.Odeme_Kalemleri_aciklama||''}" onchange="guncelle()"></td>
                <td class="text-center"><input type="checkbox" class="form-check-input" data-field="odendi" ${odendiChecked} onchange="odendiToggle(this,${idx})"></td>
                <td><input type="date" class="form-control form-control-sm" id="otar-${idx}" data-field="odeme_tarihi" value="${r.odeme_tarihi||''}" ${otarDisabled} onchange="guncelle()"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="satirSil(${idx})"><i class="bi bi-trash"></i></button></td>
            </tr>`;
            $('#odeme-satirlar').prepend(row);
            initSelect2(`#satir-${idx} .select2-dropdown`);
            dokumanLinkGoster(idx, r.Odeme_Kalemleri_dokuman || '');
            // Eski kayıtlarda ödendi işaretli olup tarihi boş olanları kullanıcıya işaret et
            if(r.Odeme_Kalemleri_odendi == 1 && !r.odeme_tarihi) $(`#otar-${idx}`).addClass('is-invalid');
        });
        toplamHesapla();
    });

    // Giden ve gelen faturalar aynı kolon düzenini kullanır; tek render ile
    // çizilir, aksi halde iki blok zamanla birbirinden ayrışıyordu.
    faturaBolumYukle('get_faturalar',       cid, '#fatura-listesi',       '#fatura-bolum',       'FaturalarGiden_FaturaNo', 'FaturalarGiden_Durum', 'text-primary');
    faturaBolumYukle('get_gelen_faturalar', cid, '#gelen-fatura-listesi', '#gelen-fatura-bolum', 'FaturalarGelen_FaturaNo', 'FaturalarGelen_Durum', 'text-warning');
    ozetYukle(cid);

});

function faturaBolumYukle(action, cid, tbodySel, bolumSel, noAlan, durumAlan, noRenk) {
    $.post(window.location.href, {action:action, cari_id:cid}, function(res){
        if(!res.success || !res.data.length){ $(bolumSel).hide(); return; }
        let html = '';
        let dahil = 0, haric = 0;
        const SEMBOL = {TRY:'₺', USD:'$', EUR:'€'};
        res.data.forEach(f => {
            const pb      = f.para_birimi || 'TRY';
            const dovizli = pb !== 'TRY';
            const kursuz  = parseInt(f.kursuz||0) === 1;
            const tutar   = parseFloat(f.tutar||0).toLocaleString('tr-TR',{minimumFractionDigits:2, maximumFractionDigits:2});
            const dovizT  = parseFloat(f.doviz_tutar||0).toLocaleString('tr-TR',{minimumFractionDigits:2, maximumFractionDigits:2});
            const kur     = parseFloat(f.kur||0);

            // Kuru cozulemeyen doviz faturasinda TL karsiligi ham tutara esittir.
            // Bunu sessizce dogru gostermek eksik bakiyeye yol acar; rozetle uyar.
            const kurHucre = kursuz
                ? '<span class="badge bg-warning text-dark" title="Kur bilgisi bulunamadı, tutar TL kabul edildi">Kur yok</span>'
                : (dovizli ? kur.toLocaleString('tr-TR',{minimumFractionDigits:4, maximumFractionDigits:4}) : '-');

            // Stok islenmemis fatura bakiyeye girmez. Nedeni ayirt edilir:
            // iptal (fatura iptal edilmis), iade (stok hareketi geri alinmis),
            // islenmemis (hic stoga islenmemis).
            const durumMetni = (f[durumAlan] || '').toString().toUpperCase();
            const iptalMi    = durumMetni.indexOf('CANCEL') > -1 || durumMetni.indexOf('İPTAL') > -1 || durumMetni.indexOf('IPTAL') > -1;
            const bakiyeDisi = f.stok_islendi !== undefined && parseInt(f.stok_islendi||0) !== 1;

            let bakiyeRozet = '';
            if(bakiyeDisi) {
                let etiket, renk, aciklama;
                if(iptalMi) {
                    etiket = 'İPTAL';      renk = 'bg-danger';
                    aciklama = 'Fatura iptal edilmiş, cari bakiyesine dahil değil';
                } else if(parseInt(f.stok_iade||0) === 1) {
                    etiket = 'İADE';       renk = 'bg-warning text-dark';
                    aciklama = 'Stok hareketi geri alınmış, cari bakiyesine dahil değil';
                } else {
                    etiket = 'İŞLENMEMİŞ'; renk = 'bg-secondary';
                    aciklama = 'Stoğa işlenmediği için cari bakiyesine dahil değil';
                }
                bakiyeRozet = ` <span class="badge ${renk}" title="${aciklama}">${etiket}</span>`;
            }


            // Bakiyeye giren / girmeyen ayrimi tablo alti toplami icin biriktirilir.
            if(bakiyeDisi) { haric += parseFloat(f.tutar||0); } else { dahil += parseFloat(f.tutar||0); }

            html += `<tr${bakiyeDisi ? ' class="text-muted"' : ''}>
                <td class="align-middle fw-semibold ${noRenk}">${f[noAlan] || '-'}${bakiyeRozet}</td>
                <td class="align-middle">${f.tarih}</td>
                <td class="align-middle">${f[durumAlan] || '-'}</td>
                <td class="text-center align-middle">${pb}</td>
                <td class="text-end align-middle">${dovizT} ${SEMBOL[pb] || pb}</td>
                <td class="text-end align-middle">${kurHucre}</td>
                <td class="text-end align-middle fw-bold ${kursuz ? 'text-warning' : ''}">${tutar} ₺</td>
            </tr>`;
        });
        $(tbodySel).html(html);

        // Tablo alti toplam: yalniz bakiyeye giren faturalar toplanir, bakiye disi
        // (iptal / iade / islenmemis) satirlar ayri bir satirda bilgi olarak gosterilir.
        // Iki toplam ayni yerde verilmezse tablodaki rakamlar kutulardaki Net Borc
        // ile tutmuyor gibi gorunuyordu.
        const onEk = tbodySel.indexOf('gelen') > -1 ? 'gelen' : 'giden';
        $('#' + onEk + '-toplam').text(fmtTL(dahil));
        if(haric > 0.005) {
            $('#' + onEk + '-haric').text(fmtTL(haric));
            $('#' + onEk + '-haric-satir').show();
        } else {
            $('#' + onEk + '-haric-satir').hide();
        }

        $(bolumSel).show();
    });
}

// Tutar bicimleyici: tablolar ve kutular ayni formati kullanir.
function fmtTL(v) {
    return (parseFloat(v)||0).toLocaleString('tr-TR',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' ₺';
}
function olusturPersonelBanka(idx, tip) {
    const isBanka = TIPLER[tip]?.tip === 'BANKA';
    if(isBanka) {
        let opts = '<option value="">Banka Seçiniz...</option>';
        bankalar.forEach(b => opts += `<option value="${b.id}">${b.text}</option>`);
        return `<select class="form-select form-select-sm select2-dropdown" data-field="banka" onchange="guncelle()">${opts}</select>`;
    } else {
        let opts = '<option value="">Personel Seçiniz...</option>';
        personeller.forEach(p => opts += `<option value="${p.id}">${p.text}</option>`);
        return `<select class="form-select form-select-sm select2-dropdown" data-field="personel_id" onchange="guncelle()">${opts}</select>`;
    }
}

function satirEkle() {
    const idx = satirSayac++;
    const tipOpts = Object.entries(TIPLER).map(([k,v]) => `<option value="${k}">${v.label}</option>`).join('');
    const row = `<tr id="satir-${idx}">
        <td>
            <select class="form-select form-select-sm" data-field="tip" onchange="tipDegisti(this,${idx})">
                ${tipOpts}
            </select>
        </td>
        <td><input type="text" class="form-control form-control-sm" data-field="belge_no" onchange="guncelle()"></td>
        <td><input type="date" class="form-control form-control-sm" data-field="vade" onchange="guncelle()"></td>
        <td><input type="number" class="form-control form-control-sm text-end" data-field="tutar" min="0" step="0.01" oninput="toplamHesapla()" onchange="guncelle()"></td>
        <td id="pb-${idx}">${olusturPersonelBanka(idx, VARSAYILAN_TIP)}</td>
        <td>
            <div class="d-flex gap-1 align-items-center">
                <input type="file" id="dok-${idx}" class="d-none" onchange="dosyaYukle(this,${idx})">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="$('#dok-${idx}').click()" title="Döküman Yükle"><i class="bi bi-paperclip"></i></button>
                <small id="dok-ad-${idx}" class="text-muted text-truncate" style="max-width:70px"></small>
                <input type="hidden" id="dok-yol-${idx}" data-field="dokuman">
            </div>
        </td>
        <td><input type="text" class="form-control form-control-sm" data-field="aciklama" onchange="guncelle()"></td>
        <td class="text-center">
            <input type="checkbox" class="form-check-input" data-field="odendi" onchange="odendiToggle(this,${idx})">
        </td>
        <td><input type="date" class="form-control form-control-sm" id="otar-${idx}" data-field="odeme_tarihi" disabled onchange="guncelle()"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="satirSil(${idx})"><i class="bi bi-trash"></i></button></td>
    </tr>`;
    $('#odeme-satirlar').append(row);
    
    // Select2'leri başlat
    initSelect2(`#satir-${idx} .select2-dropdown`);
    toplamHesapla();   // kalem sayisi degisti, kutular guncellenir
}

function initSelect2(selector) {
    $(selector).select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownAutoWidth: true
    });
}

function tipDegisti(sel, idx) {
    const tip = $(sel).val();
    $(`#pb-${idx}`).html(olusturPersonelBanka(idx, tip));
    initSelect2(`#pb-${idx} .select2-dropdown`);
}

function odendiToggle(cb, idx) {
    const alan = $(`#otar-${idx}`);
    alan.prop('disabled', !cb.checked).prop('required', cb.checked);
    if(cb.checked){
        // Ödendi işaretlendiğinde tarih zorunlu; boşsa kullanıcıya işaret et
        if(!alan.val()) alan.addClass('is-invalid');
        alan.off('change.odemeTarih').on('change.odemeTarih', function(){
            $(this).toggleClass('is-invalid', !$(this).val());
        });
    } else {
        alan.val('').removeClass('is-invalid');
    }
}

let deletedKalemIds = [];

function satirSil(idx) {
    const tr = $(`#satir-${idx}`);
    const kalemId = tr.find('[data-field="kalem_id"]').val();
    if(kalemId) deletedKalemIds.push(kalemId);
    tr.remove();
    toplamHesapla();
}

// Carinin net borcu. ozetYukle() ile doldurulur, kutu hesaplarinda kullanilir.
let netBorc = 0;

function ozetYukle(cid) {
    if(!cid) { $('#ozet-bolum').hide(); netBorc = 0; return; }
    $.post(window.location.href, {action:'get_cari_ozet', cari_id:cid}, function(res){
        if(!res.success || !res.data) { $('#ozet-bolum').hide(); return; }
        const d = res.data;
        netBorc = parseFloat(d.net_borc||0);

        $('#ozet_net_borc').text(fmtTL(netBorc));
        let altYazi = (parseInt(d.giden_adet||0)) + ' giden fatura';
        if(parseFloat(d.mahsup||0) > 0.005) {
            altYazi += ' · ' + fmtTL(d.mahsup) + ' mahsup';
        }
        $('#ozet_net_borc_alt').text(altYazi);

        $('#ozet-bolum').show();
        toplamHesapla();
    });
}

function toplamHesapla() {
    let top = 0;
    $('#odeme-satirlar [data-field="tutar"]').each(function(){ top += parseFloat($(this).val()||0); });
    $('#toplam-tutar').text(top.toLocaleString('tr-TR',{minimumFractionDigits:2}) + ' ₺');

    // Kutular tablo ile ayni anda guncellenir; tutar alanina yazdikca canli degisir.
    const kalemSayisi = $('#odeme-satirlar tr').length;
    $('#ozet_odeme').text(fmtTL(top));
    $('#ozet_odeme_alt').text(kalemSayisi + ' kalem');

    const sonra = netBorc - top;
    $('#ozet_kalan').text(fmtTL(sonra));
    $('#ozet_kalan_alt').text(sonra < -0.005 ? 'Fazla ödeme' : 'Net Borç - Bu Ödeme');
}

let yuklemeSayaci = 0;

// Yüklenen dökümanı tıklanabilir link olarak gösterir
function dokumanLinkGoster(idx, yol) {
    const hedef = $(`#dok-ad-${idx}`);
    if(!yol){ hedef.html(''); return; }
    const ad = yol.split('/').pop();
    hedef.html(`<a href="${yol}" target="_blank" rel="noopener" title="${ad}">${ad}</a>`);
}

function kaydetButonuDurum() {
    const yukleniyor = yuklemeSayaci > 0;
    $('#btnKaydet').prop('disabled', yukleniyor)
        .html(yukleniyor
            ? '<span class="spinner-border spinner-border-sm"></span> Döküman yükleniyor...'
            : '<i class="bi bi-save"></i> Kaydet');
}

function dosyaYukle(input, idx) {
    if(!input.files.length) return;
    const fd = new FormData();
    fd.append('action','upload');
    fd.append('dosya', input.files[0]);

    yuklemeSayaci++;
    kaydetButonuDurum();
    $(`#dok-ad-${idx}`).html('<span class="text-muted">Yükleniyor...</span>');

    $.ajax({
        url: window.location.href, method:'POST', data:fd,
        processData:false, contentType:false, dataType:'json',
        success:function(res){
            if(res.success){
                $(`#dok-yol-${idx}`).val(res.path);
                dokumanLinkGoster(idx, res.path);
            } else {
                dokumanLinkGoster(idx, $(`#dok-yol-${idx}`).val());
                showError('Hata', res.message);
            }
        },
        error:function(){
            dokumanLinkGoster(idx, $(`#dok-yol-${idx}`).val());
            showError('Hata', 'Döküman yüklenemedi. Bağlantınızı kontrol edip tekrar deneyin.');
        },
        complete:function(){
            input.value = '';
            yuklemeSayaci--;
            kaydetButonuDurum();
        }
    });
}

function guncelle() { toplamHesapla(); }

$('#btnKaydet').on('click', function(){
    const cid = $('#cari_id').val();
    if(!cid){ showError('Hata','Lütfen cari seçin.'); return; }
    const rows = $('#odeme-satirlar tr');
    if(!rows.length){ showError('Hata','En az bir ödeme satırı ekleyin.'); return; }

    const satirlar = [];
    let hatali = null;
    rows.each(function(i){
        const tr = $(this);
        const s = {
            kalem_id:     tr.find('[data-field="kalem_id"]').val() || '',
            tip:          tr.find('[data-field="tip"]').val(),
            belge_no:     tr.find('[data-field="belge_no"]').val(),
            vade:         tr.find('[data-field="vade"]').val(),
            tutar:        tr.find('[data-field="tutar"]').val(),
            personel_id:  tr.find('[data-field="personel_id"]').val() || '',
            banka:        tr.find('[data-field="banka"]').val() || '',
            dokuman:      tr.find('[data-field="dokuman"]').val() || '',
            aciklama:     tr.find('[data-field="aciklama"]').val(),
            odendi:       tr.find('[data-field="odendi"]').is(':checked') ? 1 : 0,
            odeme_tarihi: tr.find('[data-field="odeme_tarihi"]').val(),
        };
        // Hiçbir alanı doldurulmamış yeni satırlar gönderilmez
        const bosSatir = !s.kalem_id && !parseFloat(s.tutar || 0)
            && !s.belge_no && !s.vade && !s.dokuman && !s.aciklama
            && !s.personel_id && !s.banka && !s.odendi && !s.odeme_tarihi;
        if(bosSatir) return;

        // Ödendi işaretliyse ödeme tarihi zorunlu
        const tarihAlani = tr.find('[data-field="odeme_tarihi"]');
        if(s.odendi && !s.odeme_tarihi){
            tarihAlani.addClass('is-invalid');
            if(hatali === null) hatali = { sira: i + 1, alan: tarihAlani };
        } else {
            tarihAlani.removeClass('is-invalid');
        }

        satirlar.push(s);
    });

    if(hatali){
        showError('Eksik Bilgi', hatali.sira + '. satır "Ödendi" olarak işaretli. Lütfen ödeme tarihini girin.');
        hatali.alan.focus();
        return;
    }

    if(!satirlar.length && !deletedKalemIds.length){
        showError('Hata','Kaydedilecek dolu bir ödeme satırı bulunamadı. Lütfen en az bir satırı doldurun.');
        return;
    }

    $.post(window.location.href, {
        action: 'save',
        cari_id: cid,
        aciklama: $('#aciklama').val(),
        satirlar: JSON.stringify(satirlar),
        silinenler: JSON.stringify(deletedKalemIds)
    }, function(res){
        if(res.success){
            showSuccess('Başarılı', res.message);
            setTimeout(() => window.location.href = '/Admin/odeme-yonetimi', 1500);
        } else { showError('Hata', res.message); }
    }).fail(function(xhr){
        showError('Hata', 'Sunucu hatası oluştu. Lütfen tekrar deneyin.');
    });
});

// ── HalkÖde ödeme linki ─────────────────────────────────────
// Link VPOS'ta üretilir; SMS/e-posta gönderimini biz yaparız.
// Ödeme tamamlanınca callback ödeme kalemini kendisi açar.
let hkKisiler   = [];
let hkFaturalar = [];

const HK_DURUM = {
    OLUSTURULDU: ['Oluşturuldu', 'secondary'],
    GONDERILDI:  ['Gönderildi',  'info'],
    ODENDI:      ['Ödendi',      'success'],
    BASARISIZ:   ['Başarısız',   'danger'],
    IPTAL:       ['İptal',       'dark'],
};

function halkodeAc(){
    const cid = $('#cari_id').val();

    if(!cid){
        showError('Cari Seçilmedi', 'Önce ödeme linki gönderilecek cariyi seçin.');
        return;
    }

    $('#hk_sonuc').addClass('d-none');
    $('#hk_link').val('');
    $('#hk_gonderim_bilgi').html('');
    $('#hk_aciklama').val($('#aciklama').val() || '');
    $('#hk_taksit').val('0');   // varsayilan: taksiti musteri odeme sayfasinda secer
    $('#hk_tutar').val('');

    halkodeGecmisYukle(cid);
    halkodeFaturalariYukle(cid);

    const modal = new bootstrap.Modal(document.getElementById('halkodeModal'));
    modal.show();

    // Select2 modal icinde acilirken modalin icine bagli olmali.
    // Modal birden fazla kez acilabildigi icin once mevcut ornek yikilir.
    $('#hk_fatura, #hk_kisi').each(function(){
        if($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy');
    }).select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#halkodeModal')
    });
}

// Cariye ait giden faturalar; secim tutar ve aciklamayi doldurur
function halkodeFaturalariYukle(cid){
    $.post(window.location.href, {action:'get_faturalar', cari_id:cid}, function(res){
        hkFaturalar = (res.success && res.data) ? res.data : [];

        const sel = $('#hk_fatura').empty().append('<option value="">Fatura seçilmedi</option>');

        hkFaturalar.forEach(function(f, i){
            const tutar = parseFloat(f.tutar || 0).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
            const pb    = f.para_birimi || 'TRY';
            const doviz = pb !== 'TRY'
                ? ' (' + parseFloat(f.doviz_tutar || 0).toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ' + pb + ')'
                : '';

            sel.append($('<option>', {
                value: i,
                text : f.FaturalarGiden_FaturaNo + ' · ' + (f.tarih || '') + ' · ' + tutar + ' ₺' + doviz
            }));
        });

        $('#hk_fatura').trigger('change.select2');
    }, 'json');
}

function halkodeGecmisYukle(cid){
    $.post(window.location.href, {action:'halkode_linkler', cari_id:cid}, function(res){
        if(!res.success) return;

        // Kişi listesi: cari kartı + yetkililer
        hkKisiler = res.kisiler || [];

        const kisiSelect = $('#hk_kisi').empty();

        hkKisiler.forEach(function(k, i){
            const eksik = [];
            if(!k.telefon) eksik.push('telefon yok');
            if(!k.email)   eksik.push('e-posta yok');

            kisiSelect.append($('<option>', {
                value: i,
                text : k.ad + ' — ' + k.gorev + (eksik.length ? ' (' + eksik.join(', ') + ')' : '')
            }));
        });

        if(hkKisiler.length){
            kisiSelect.val(0).trigger('change').trigger('change.select2');
        }

        const tbody = $('#hk_gecmis').empty();

        if(!res.data.length){
            tbody.append('<tr><td colspan="6" class="text-center text-muted">Kayıt yok</td></tr>');
            return;
        }

        res.data.forEach(function(r){
            const d = HK_DURUM[r.durum] || [r.durum, 'secondary'];
            const bekliyor = (r.durum === 'OLUSTURULDU' || r.durum === 'GONDERILDI');

            tbody.append(
                '<tr>' +
                  '<td class="small">' + (r.olusturma || '') + '</td>' +
                  '<td class="text-end">' + Number(r.tutar).toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺</td>' +
                  '<td class="text-center">' + (r.taksit > 1 ? r.taksit : (r.taksit == 1 ? 'Tek' : 'Müşteri')) + '</td>' +
                  '<td><span class="badge bg-' + d[1] + '">' + d[0] + '</span></td>' +
                  '<td class="small">' + (r.kanal || '-') + '</td>' +
                  '<td class="text-center">' +
                    (r.link ? '<button class="btn btn-sm btn-outline-secondary" title="Linki kopyala" onclick="halkodeKopyalaDeger(\'' + r.link + '\')"><i class="bi bi-clipboard"></i></button> ' : '') +
                    (bekliyor ? '<button class="btn btn-sm btn-outline-primary" title="Durumu sorgula" onclick="halkodeSorgula(' + r.id + ')"><i class="bi bi-arrow-clockwise"></i></button>' : '') +
                  '</td>' +
                '</tr>'
            );
        });
    }, 'json');
}

// Fatura seçimi tutar ve açıklamayı doldurur; ikisi de elle değiştirilebilir
$('#hk_fatura').on('change', function(){
    const f = hkFaturalar[parseInt($(this).val(), 10)];

    if(!f) return;

    // Tutar faturanın TL karşılığıdır (dövizli faturada kur uygulanmış hali)
    $('#hk_tutar').val(parseFloat(f.tutar || 0).toFixed(2));
    $('#hk_aciklama').val('Fatura No: ' + f.FaturalarGiden_FaturaNo);
});

// Kişi seçimi telefon ve e-posta alanlarını doldurur; alanlar elle de düzenlenebilir
$('#hk_kisi').on('change', function(){
    const kisi = hkKisiler[parseInt($(this).val(), 10)];

    if(!kisi) return;

    $('#hk_telefon').val(kisi.telefon || '');
    $('#hk_email').val(kisi.email || '');
});

$('#hk_btnOlustur').on('click', function(){
    const cid   = $('#cari_id').val();
    const tutar = parseFloat($('#hk_tutar').val() || 0);

    if(!cid){ showError('Hata', 'Cari seçilmedi.'); return; }
    if(!(tutar > 0)){ showError('Hata', 'Tutar sıfırdan büyük olmalıdır.'); return; }

    const kanallar = [];
    if($('#hk_sms').is(':checked'))  kanallar.push('sms');
    if($('#hk_mail').is(':checked')) kanallar.push('mail');

    const btn = $(this).prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm"></span> Oluşturuluyor...');

    $.post(window.location.href, {
        action:   'halkode_link_olustur',
        cari_id:  cid,
        tutar:    tutar,
        taksit:   $('#hk_taksit').val(),
        aciklama: $('#hk_aciklama').val(),
        telefon:  $('#hk_telefon').val(),
        email:    $('#hk_email').val(),
        kanallar: JSON.stringify(kanallar)
    }, function(res){
        if(!res.success){ showError('Hata', res.message); return; }

        $('#hk_link').val(res.link);
        $('#hk_sonuc').removeClass('d-none');

        const g = res.gonderim || {};
        let bilgi = [];
        if(g.sms  === true)  bilgi.push('<span class="text-success">SMS gönderildi</span>');
        if(g.mail === true)  bilgi.push('<span class="text-success">E-posta gönderildi</span>');
        (g.hatalar || []).forEach(h => bilgi.push('<span class="text-danger">' + h + '</span>'));

        $('#hk_gonderim_bilgi').html(bilgi.join(' · '));
        showSuccess('Başarılı', res.message);
        halkodeGecmisYukle(cid);
    }, 'json').fail(function(){
        showError('Hata', 'Sunucu hatası oluştu. Lütfen tekrar deneyin.');
    }).always(function(){
        btn.prop('disabled', false).html('<i class="bi bi-link-45deg"></i> Link Oluştur');
    });
});

function halkodeSorgula(linkId){
    $.post(window.location.href, {action:'halkode_durum_sorgula', link_id:linkId}, function(res){
        if(!res.success){ showError('Hata', res.message); return; }

        if(res.durum === 'ODENDI'){
            showSuccess('Ödeme Alındı', res.message);
        } else {
            showError('Bekliyor', res.message);
        }

        halkodeGecmisYukle($('#cari_id').val());
    }, 'json');
}

function halkodeKopyala(){ halkodeKopyalaDeger($('#hk_link').val()); }

function halkodeKopyalaDeger(deger){
    if(!deger) return;

    navigator.clipboard.writeText(deger).then(
        () => showSuccess('Kopyalandı', 'Ödeme linki panoya kopyalandı.'),
        () => showError('Hata', 'Link kopyalanamadı, elle seçip kopyalayın.')
    );
}

<?php if ($cari_id_get): ?>
$(function(){ $('#cari_id').trigger('change'); });
<?php endif; ?>

satirEkle(); // ilk satırı otomatik ekle
</script>
</body>
</html>
