<?php
/**
 * Cron Yönetimi
 *
 * Merkezi cron sisteminin paneli: görev listesi, zamanlayıcı tanımları,
 * manuel çalıştırma ve çalışma geçmişi.
 *
 * @see cron/tasks.php  — çekirdek fonksiyonlar
 * @see cron/runner.php — Plesk giriş noktası
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../../cron/tasks.php';

requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);

$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// ═══════════════════════════════════════════════════════════
// AJAX İŞLEMLERİ
// ═══════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ── İstatistik kutuları
            case 'stats':
                $stats = $db->fetchOne("
                    SELECT
                        (SELECT COUNT(*) FROM dbo.CronGorevler WHERE Durum = 1) AS Gorev,
                        (SELECT COUNT(*) FROM dbo.CronZamanlamalar z
                          INNER JOIN dbo.CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id
                          WHERE z.Durum = 1 AND g.Durum = 1) AS Zamanlayici,
                        (SELECT COUNT(*) FROM dbo.CronCalismaLog
                          WHERE CronCalismaLog_CalismaDurum = 1
                            AND CronCalismaLog_BaslangicTarihi >= CAST(GETDATE() AS DATE)) AS Basarili,
                        (SELECT COUNT(*) FROM dbo.CronCalismaLog
                          WHERE CronCalismaLog_CalismaDurum = 2
                            AND CronCalismaLog_BaslangicTarihi >= CAST(GETDATE() AS DATE)) AS Hatali,
                        (SELECT COUNT(*) FROM dbo.CronCalismaLog
                          WHERE CronCalismaLog_CalismaDurum = 0) AS Calisiyor
                ");

                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            // ── Görev + zamanlayıcı listesi
            case 'gorevler':
                $gorevler = $db->fetchAll("
                    SELECT
                        CronGorevler_Id           AS Id,
                        CronGorevler_Ad           AS Ad,
                        CronGorevler_GorevKodu    AS Kod,
                        CronGorevler_Aciklama     AS Aciklama,
                        CronGorevler_Parametreler AS Parametreler,
                        CronGorevler_MaxSureSn    AS MaxSure
                    FROM dbo.CronGorevler
                    WHERE Durum = 1
                    ORDER BY CronGorevler_Id
                ");

                $zamanlamalar = $db->fetchAll("
                    SELECT
                        z.CronZamanlamalar_Id                AS Id,
                        z.CronZamanlamalar_GorevId           AS GorevId,
                        z.CronZamanlamalar_Ad                AS Ad,
                        z.CronZamanlamalar_CronIfadesi       AS Ifade,
                        z.CronZamanlamalar_SabitParametreler AS Parametreler,
                        z.CronZamanlamalar_TelafiEt          AS TelafiEt,
                        z.CronZamanlamalar_TelafiSaatSiniri  AS TelafiSaat,
                        z.Durum                              AS Durum,
                        CONVERT(VARCHAR(19), z.CronZamanlamalar_BaslangicTarihi, 120) AS Baslangic,
                        CONVERT(VARCHAR(19), z.CronZamanlamalar_BitisTarihi, 120)     AS Bitis,
                        CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120)      AS SonCalisma,
                        l.CalismaDurum,
                        l.SureSaniye
                    FROM dbo.CronZamanlamalar z
                    INNER JOIN dbo.CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id
                    OUTER APPLY (
                        SELECT TOP 1
                            CronCalismaLog_CalismaDurum AS CalismaDurum,
                            CronCalismaLog_SureSaniye   AS SureSaniye
                        FROM dbo.CronCalismaLog
                        WHERE CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                        ORDER BY CronCalismaLog_Id DESC
                    ) l
                    WHERE g.Durum = 1
                    ORDER BY z.CronZamanlamalar_Id
                ");

                // Sonraki çalışma zamanını hesapla (aktif olanlar için)
                foreach ($zamanlamalar as &$z) {
                    $z['Sonraki'] = null;

                    if ((int)$z['Durum'] === 1 && cronIfadeDogrula($z['Ifade']) === null) {
                        $sonraki = cronSonrakiCalisma($z['Ifade']);
                        $z['Sonraki'] = $sonraki ? $sonraki->format('Y-m-d H:i') : null;
                    }
                }

                unset($z);

                echo json_encode([
                    'success'      => true,
                    'gorevler'     => $gorevler,
                    'zamanlamalar' => $zamanlamalar,
                ]);
                break;

            // ── Sıklık şablonları (dropdown)
            case 'siklik_sablonlari':
                $sablonlar = $db->fetchAll("
                    SELECT CronSiklikSablonlari_Ad AS Ad, CronSiklikSablonlari_Ifade AS Ifade
                    FROM dbo.CronSiklikSablonlari
                    WHERE Durum = 1
                    ORDER BY CronSiklikSablonlari_SiraNo
                ");

                // kullanici_coklu tipindeki parametre alanlarını beslemek için
                $kullanicilar = $db->fetchAll("
                    SELECT kullanici_id AS Id,
                           kullanici_ad + ' ' + kullanici_soyad AS Ad,
                           kullanici_email AS Email
                    FROM kullanicilar
                    WHERE kullanici_durum = 1
                      AND kullanici_email IS NOT NULL
                      AND kullanici_email <> ''
                    ORDER BY kullanici_ad
                ");

                // marka_coklu tipindeki parametre alanlarını beslemek için
                $markalar = $db->fetchAll("
                    SELECT marka_id AS Id, marka_adi AS Ad
                    FROM dbo.UrunHizmet_Markalar
                    WHERE marka_durum = 1
                    ORDER BY marka_sira_no, marka_adi
                ");

                echo json_encode([
                    'success'      => true,
                    'sablonlar'    => $sablonlar,
                    'tarihler'     => dinamikTarihListesi(),
                    'kullanicilar' => $kullanicilar,
                    'markalar'     => $markalar,
                ]);
                break;

            // ── Tek zamanlayıcı getir
            case 'zamanlama_get':
                $id = (int)($_POST['id'] ?? 0);

                $z = $db->fetchOne("
                    SELECT
                        z.CronZamanlamalar_Id                AS Id,
                        z.CronZamanlamalar_GorevId           AS GorevId,
                        z.CronZamanlamalar_Ad                AS Ad,
                        z.CronZamanlamalar_CronIfadesi       AS Ifade,
                        z.CronZamanlamalar_SabitParametreler AS Parametreler,
                        z.CronZamanlamalar_TelafiEt          AS TelafiEt,
                        z.CronZamanlamalar_TelafiSaatSiniri  AS TelafiSaat,
                        CONVERT(VARCHAR(16), z.CronZamanlamalar_BaslangicTarihi, 126) AS Baslangic,
                        CONVERT(VARCHAR(16), z.CronZamanlamalar_BitisTarihi, 126)     AS Bitis,
                        g.CronGorevler_Ad           AS GorevAd,
                        g.CronGorevler_Parametreler AS GorevParametreler
                    FROM dbo.CronZamanlamalar z
                    INNER JOIN dbo.CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id
                    WHERE z.CronZamanlamalar_Id = ?
                ", [$id]);

                if (!$z) {
                    echo json_encode(['success' => false, 'message' => 'Zamanlayıcı bulunamadı.']);
                    break;
                }

                echo json_encode(['success' => true, 'data' => $z]);
                break;

            // ── Zamanlayıcı kaydet (ekle / güncelle)
            case 'zamanlama_kaydet':
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $id      = (int)($_POST['id'] ?? 0);
                $gorevId = (int)($_POST['gorev_id'] ?? 0);
                $ad      = trim((string)($_POST['ad'] ?? ''));
                $ifade   = trim((string)($_POST['ifade'] ?? ''));

                if ($ad === '') {
                    echo json_encode(['success' => false, 'message' => 'Zamanlayıcı adı zorunludur.']);
                    break;
                }

                $ifadeHata = cronIfadeDogrula($ifade);

                if ($ifadeHata !== null) {
                    echo json_encode(['success' => false, 'message' => $ifadeHata]);
                    break;
                }

                $gorev = $db->fetchOne("
                    SELECT CronGorevler_Id, CronGorevler_Parametreler
                    FROM dbo.CronGorevler WHERE CronGorevler_Id = ? AND Durum = 1
                ", [$gorevId]);

                if (!$gorev) {
                    echo json_encode(['success' => false, 'message' => 'Görev bulunamadı veya pasif.']);
                    break;
                }

                $baslangic = !empty($_POST['baslangic']) ? str_replace('T', ' ', $_POST['baslangic']) . ':00' : date('Y-m-d H:i:s');
                $bitis     = !empty($_POST['bitis'])     ? str_replace('T', ' ', $_POST['bitis']) . ':00'     : null;

                if ($bitis !== null && strtotime($bitis) <= strtotime($baslangic)) {
                    echo json_encode(['success' => false, 'message' => 'Bitiş tarihi başlangıçtan sonra olmalıdır.']);
                    break;
                }

                // Sabit parametreler: görev şemasına göre topla (dinamik yer tutucular çözülmeden saklanır)
                $sabitParams = [];
                $sema        = json_decode((string)($gorev['CronGorevler_Parametreler'] ?? ''), true);

                if (is_array($sema)) {
                    foreach ($sema as $alan) {
                        $alanAd = $alan['ad'] ?? null;

                        if (!$alanAd) {
                            continue;
                        }

                        $deger   = $_POST['param_' . $alanAd] ?? null;
                        $alanTip = $alan['tip'] ?? '';

                        if ($alanTip === 'checkbox') {
                            $sabitParams[$alanAd] = !empty($deger) ? 1 : 0;
                        } elseif ($alanTip === 'kullanici_coklu' || $alanTip === 'marka_coklu') {
                            $idler = is_array($deger) ? $deger : explode(',', (string)$deger);
                            $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));

                            if (!$idler && !empty($alan['zorunlu'])) {
                                throw new RuntimeException(($alan['etiket'] ?? $alanAd) . ' alanı zorunludur.');
                            }

                            if ($idler) {
                                $sabitParams[$alanAd] = implode(',', $idler);
                            }
                        } elseif ($deger !== null && trim((string)$deger) !== '') {
                            $sabitParams[$alanAd] = trim((string)$deger);
                        } elseif (!empty($alan['zorunlu'])) {
                            throw new RuntimeException(($alan['etiket'] ?? $alanAd) . ' alanı zorunludur.');
                        }
                    }
                }

                $veri = [
                    'CronZamanlamalar_GorevId'           => $gorevId,
                    'CronZamanlamalar_Ad'                => $ad,
                    'CronZamanlamalar_CronIfadesi'       => $ifade,
                    'CronZamanlamalar_BaslangicTarihi'   => $baslangic,
                    'CronZamanlamalar_BitisTarihi'       => $bitis,
                    'CronZamanlamalar_SabitParametreler' => $sabitParams ? json_encode($sabitParams, JSON_UNESCAPED_UNICODE) : null,
                    'CronZamanlamalar_TelafiEt'          => !empty($_POST['telafi_et']) ? 1 : 0,
                    'CronZamanlamalar_TelafiSaatSiniri'  => max(1, (int)($_POST['telafi_saat'] ?? 6)),
                    'GuncelleyenKullanici'               => $user['kullanici_id'],
                    'GuncellemeTarihi'                   => date('Y-m-d H:i:s'),
                ];

                if ($id > 0) {
                    if (!$pagePermissions['can_edit']) {
                        echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                        break;
                    }

                    $db->update('CronZamanlamalar', $veri, ['CronZamanlamalar_Id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Zamanlayıcı güncellendi.']);
                } else {
                    if (!$pagePermissions['can_add']) {
                        echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                        break;
                    }

                    $veri['OlusturanKullanici'] = $user['kullanici_id'];
                    $veri['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $veri['Durum']              = 1;

                    $db->insert('CronZamanlamalar', $veri);
                    echo json_encode(['success' => true, 'message' => 'Zamanlayıcı eklendi.']);
                }
                break;

            // ── Zamanlayıcı aktif/pasif
            case 'zamanlama_durum':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $id    = (int)($_POST['id'] ?? 0);
                $durum = !empty($_POST['durum']) ? 1 : 0;

                $db->update('CronZamanlamalar', [
                    'Durum'                => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['CronZamanlamalar_Id' => $id]);

                echo json_encode([
                    'success' => true,
                    'message' => $durum ? 'Zamanlayıcı aktifleştirildi.' : 'Zamanlayıcı durduruldu.',
                ]);
                break;

            // ── Zamanlayıcı sil (soft delete)
            case 'zamanlama_sil':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['id'] ?? 0);

                // Log kayıtları ZamanlamaId üzerinden bağlı olduğu için fiziksel silme YAPILMAZ;
                // aksi halde çalışma geçmişindeki referans kopar.
                $db->update('CronZamanlamalar', [
                    'Durum'                => 0,
                    'CronZamanlamalar_Ad'  => 'SİLİNDİ — ' . date('d.m.Y H:i'),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['CronZamanlamalar_Id' => $id]);

                echo json_encode(['success' => true, 'message' => 'Zamanlayıcı silindi.']);
                break;

            // ── Manuel çalıştır (görev veya zamanlayıcı)
            case 'tetikle':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Çalıştırma yetkiniz yok!']);
                    break;
                }

                $zamanlamaId = (int)($_POST['zamanlama_id'] ?? 0);
                $gorevId     = (int)($_POST['gorev_id'] ?? 0);

                if ($zamanlamaId > 0) {
                    // Zamanlayıcı tetiği: parametreler DB'den
                    $z = $db->fetchOne("
                        SELECT
                            z.CronZamanlamalar_Id,
                            z.CronZamanlamalar_SabitParametreler,
                            g.CronGorevler_Id,
                            g.CronGorevler_Ad,
                            g.CronGorevler_GorevKodu,
                            g.CronGorevler_MaxSureSn
                        FROM dbo.CronZamanlamalar z
                        INNER JOIN dbo.CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id
                        WHERE z.CronZamanlamalar_Id = ?
                    ", [$zamanlamaId]);

                    if (!$z) {
                        echo json_encode(['success' => false, 'message' => 'Zamanlayıcı bulunamadı.']);
                        break;
                    }

                    $ham    = json_decode((string)($z['CronZamanlamalar_SabitParametreler'] ?? ''), true);
                    $params = dinamikParamCoz(is_array($ham) ? $ham : []);
                    $gorev  = $z;
                } else {
                    // Görev tetiği: parametreler formdan, şemaya göre doğrulanır
                    $gorev = $db->fetchOne("
                        SELECT CronGorevler_Id, CronGorevler_Ad, CronGorevler_GorevKodu,
                               CronGorevler_Parametreler, CronGorevler_MaxSureSn
                        FROM dbo.CronGorevler
                        WHERE CronGorevler_Id = ? AND Durum = 1
                    ", [$gorevId]);

                    if (!$gorev) {
                        echo json_encode(['success' => false, 'message' => 'Görev bulunamadı veya pasif.']);
                        break;
                    }

                    $gelen = [];

                    foreach ($_POST as $k => $v) {
                        if (str_starts_with($k, 'param_')) {
                            $gelen[substr($k, 6)] = $v;
                        }
                    }

                    $params = dinamikParamCoz(
                        cronParamDogrula($gorev['CronGorevler_Parametreler'] ?? null, $gelen)
                    );
                }

                // Uzun görevlerde AJAX isteği görev bitene kadar bekler
                @set_time_limit((int)($gorev['CronGorevler_MaxSureSn'] ?? 600) + 30);

                $sonuc = cronCalistirVeLogla(
                    $db,
                    $gorev,
                    $zamanlamaId > 0 ? $zamanlamaId : null,
                    $params,
                    CRON_TETIK_MANUEL,
                    $user['kullanici_id']
                );

                echo json_encode([
                    'success' => empty($sonuc['atlandi']) && $sonuc['durum'] === CRON_DURUM_BASARILI,
                    'atlandi' => !empty($sonuc['atlandi']),
                    'durum'   => $sonuc['durum'],
                    'message' => $sonuc['sonuc'],
                    'cikti'   => $sonuc['cikti'],
                    'log_id'  => $sonuc['log_id'],
                    'params'  => $params,
                ]);
                break;

            // ── Çalışma geçmişi
            case 'gecmis':
                $sql    = "
                    SELECT TOP 300
                        l.CronCalismaLog_Id            AS Id,
                        g.CronGorevler_Ad              AS Gorev,
                        z.CronZamanlamalar_Ad          AS Zamanlama,
                        l.CronCalismaLog_CalismaDurum  AS Durum,
                        l.CronCalismaLog_SureSaniye    AS Sure,
                        l.CronCalismaLog_TetikleyenTur AS TetikTur,
                        l.CronCalismaLog_Sonuc         AS Sonuc,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS Kullanici,
                        CONVERT(VARCHAR(19), l.CronCalismaLog_BaslangicTarihi, 120) AS Baslangic
                    FROM dbo.CronCalismaLog l
                    INNER JOIN dbo.CronGorevler g ON l.CronCalismaLog_GorevId = g.CronGorevler_Id
                    LEFT JOIN dbo.CronZamanlamalar z ON l.CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                    LEFT JOIN dbo.kullanicilar k ON l.CronCalismaLog_TetikleyenKullanici = k.kullanici_id
                    WHERE 1 = 1
                ";
                $params = [];

                if (!empty($_POST['gorev_id'])) {
                    $sql     .= " AND l.CronCalismaLog_GorevId = ?";
                    $params[] = (int)$_POST['gorev_id'];
                }

                if (isset($_POST['durum']) && $_POST['durum'] !== '') {
                    $sql     .= " AND l.CronCalismaLog_CalismaDurum = ?";
                    $params[] = (int)$_POST['durum'];
                }

                if (isset($_POST['tetik_tur']) && $_POST['tetik_tur'] !== '') {
                    $sql     .= " AND l.CronCalismaLog_TetikleyenTur = ?";
                    $params[] = (int)$_POST['tetik_tur'];
                }

                if (!empty($_POST['baslangic'])) {
                    $sql     .= " AND CONVERT(date, l.CronCalismaLog_BaslangicTarihi) >= ?";
                    $params[] = $_POST['baslangic'];
                }

                if (!empty($_POST['bitis'])) {
                    $sql     .= " AND CONVERT(date, l.CronCalismaLog_BaslangicTarihi) <= ?";
                    $params[] = $_POST['bitis'];
                }

                $sql .= " ORDER BY l.CronCalismaLog_Id DESC";

                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            // ── Log detayı (tam çıktı)
            case 'gecmis_detay':
                $id = (int)($_POST['id'] ?? 0);

                $log = $db->fetchOne("
                    SELECT
                        l.CronCalismaLog_Id           AS Id,
                        g.CronGorevler_Ad             AS Gorev,
                        z.CronZamanlamalar_Ad         AS Zamanlama,
                        l.CronCalismaLog_CalismaDurum AS Durum,
                        l.CronCalismaLog_SureSaniye   AS Sure,
                        l.CronCalismaLog_Parametreler AS Parametreler,
                        l.CronCalismaLog_Sonuc        AS Sonuc,
                        l.CronCalismaLog_Cikti        AS Cikti,
                        l.CronCalismaLog_TetikleyenTur AS TetikTur,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS Kullanici,
                        CONVERT(VARCHAR(19), l.CronCalismaLog_BaslangicTarihi, 120) AS Baslangic,
                        CONVERT(VARCHAR(19), l.CronCalismaLog_BitisTarihi, 120)     AS Bitis
                    FROM dbo.CronCalismaLog l
                    INNER JOIN dbo.CronGorevler g ON l.CronCalismaLog_GorevId = g.CronGorevler_Id
                    LEFT JOIN dbo.CronZamanlamalar z ON l.CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                    LEFT JOIN dbo.kullanicilar k ON l.CronCalismaLog_TetikleyenKullanici = k.kullanici_id
                    WHERE l.CronCalismaLog_Id = ?
                ", [$id]);

                if (!$log) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı.']);
                    break;
                }

                echo json_encode(['success' => true, 'data' => $log]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }

    exit;
}

// ═══════════════════════════════════════════════════════════
// SAYFA VERİLERİ
// ═══════════════════════════════════════════════════════════

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cron Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Plesk cron tanımı — key veritabanından, kodda hardcode YOK
$cronKey = (string)cronAyar($db, 'cron_secret_key', '');
$sema    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$cronUrl = $sema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/cron/runner.php?key=' . $cronKey;
$logGun  = (string)cronAyar($db, 'log_saklama_gun', '90');

// CLI komutu (önerilen yöntem). PHP yolu, çalışan sürümden türetilir.
$runnerYol = realpath(__DIR__ . '/../../cron/runner.php') ?: (__DIR__ . '/../../cron/runner.php');
$phpYol    = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';

if (!is_file($phpYol)) {
    $phpYol = 'php';
}

$cronKomut = '"' . $phpYol . '" "' . $runnerYol . '"';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="/admin/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/select2/css/select2.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/select2/css/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .cron-bar {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: .5rem;
        }
        .cron-url {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .8rem;
            word-break: break-all;
        }
        .kod-badge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .75rem;
        }
        /* Cron ifadelerindeki boşluklar HTML'de daralmasın: "* * * * *" okunur kalsın */
        .cron-ifade { white-space: pre; }
        .cikti-kutu {
            background: #1e1e1e;
            color: #d4d4d4;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .8rem;
            line-height: 1.5;
            padding: 1rem;
            border-radius: .375rem;
            max-height: 55vh;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .info-box { transition: transform .2s; }
        .info-box:hover { transform: translateY(-4px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .gorev-kart .table > :not(caption) > * > * { padding: .6rem .75rem; }
        .zamanlayici-pasif { opacity: .55; }

        /* modal-dialog-scrollable, modal-body'nin modal-content'in flex çocuğu olmasına
           dayanır. Zamanlayıcı ve Manuel Çalıştır pencerelerinde araya <form> girdiği için
           flex zinciri kırılıyor ve kaydırma çubuğu oluşmuyordu; form da flex kapsayıcı
           yapılarak zincir tamamlanır. min-height:0 olmadan taşan içerik yine kaydırılmaz. */
        .modal-dialog-scrollable .modal-content > form {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }
        .modal-dialog-scrollable .modal-content > form > .modal-body {
            overflow-y: auto;
            min-height: 0;
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
                    <div class="col-sm-6">
                        <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                    </div>
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

                <!-- Plesk Cron Tanımı -->
                <div class="cron-bar p-3 mb-3">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="d-inline-flex align-items-center justify-content-center text-bg-primary rounded"
                              style="width:38px;height:38px;flex:0 0 38px;">
                            <i class="bi bi-gear-fill"></i>
                        </span>
                        <div class="fw-semibold">
                            Plesk Cron — Tek Tanım
                            <span class="badge text-bg-secondary ms-1 kod-badge cron-ifade">* * * * *</span>
                        </div>
                    </div>

                    <!-- CLI: önerilen yöntem -->
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <span class="badge text-bg-success mt-1" style="min-width:82px;">Önerilen</span>
                        <div class="flex-grow-1">
                            <div class="small text-muted mb-1">
                                Görev tipi: <strong>Run a command</strong> (veya "Run a PHP script" → <code>cron/runner.php</code>)
                            </div>
                            <div class="cron-url" id="cronKomut"><?= htmlspecialchars($cronKomut) ?></div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm kopyala" data-hedef="#cronKomut" title="Panoya kopyala">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>

                    <!-- URL: alternatif -->
                    <div class="d-flex align-items-start gap-2">
                        <span class="badge text-bg-secondary mt-1" style="min-width:82px;">Alternatif</span>
                        <div class="flex-grow-1">
                            <div class="small text-muted mb-1">Görev tipi: <strong>Fetch a URL</strong></div>
                            <div class="cron-url text-primary" id="cronUrl"><?= htmlspecialchars($cronUrl) ?></div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm kopyala" data-hedef="#cronUrl" title="Panoya kopyala">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>

                    <div class="alert alert-warning py-2 mb-0 mt-3 small">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>URL yöntemi bu sunucuda 403 veriyor.</strong>
                        Plesk'in "Fetch a URL" görevi <code>User-Agent</code> başlığı göndermiyor; sunucudaki
                        güvenlik kuralı başlıksız istekleri reddediyor. CLI yöntemi HTTP katmanını
                        tamamen atladığı için bu sorundan etkilenmez, ayrıca gizli anahtara da ihtiyaç duymaz.
                    </div>
                </div>

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-list-task"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Tanımlı Görev</span>
                                <span class="info-box-number" id="stat-gorev">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-clock"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Zamanlayıcı</span>
                                <span class="info-box-number" id="stat-zamanlayici">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Başarılı</span>
                                <span class="info-box-number" id="stat-basarili">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Hatalı</span>
                                <span class="info-box-number" id="stat-hatali">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info d-none" id="calisiyorUyari">
                    <i class="bi bi-hourglass-split"></i>
                    Şu anda <strong><span id="calisiyorAdet">0</span></strong> görev çalışıyor.
                </div>

                <!-- Sekmeler -->
                <div class="card card-primary card-outline">
                    <div class="card-header p-0 border-bottom-0">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-gorevler" type="button">
                                    <i class="bi bi-list-ul"></i> Görevler
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-gecmis" type="button" id="btnGecmisTab">
                                    <i class="bi bi-journal-text"></i> Çalışma Geçmişi
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">

                            <!-- ── GÖREVLER ── -->
                            <div class="tab-pane fade show active" id="tab-gorevler">
                                <div id="gorevListesi">
                                    <div class="text-center text-muted py-4">
                                        <div class="spinner-border spinner-border-sm"></div> Yükleniyor...
                                    </div>
                                </div>
                            </div>

                            <!-- ── ÇALIŞMA GEÇMİŞİ ── -->
                            <div class="tab-pane fade" id="tab-gecmis">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <small class="text-muted">
                                        Son 300 kayıt gösterilir. Kayıtlar <?= (int)$logGun ?> gün saklanır.
                                    </small>
                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filtreKart">
                                        <i class="bi bi-funnel"></i> Filtrele
                                    </button>
                                </div>

                                <div class="card card-secondary card-outline mb-3 collapse" id="filtreKart">
                                    <div class="card-body">
                                        <form id="filtreForm" class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Görev</label>
                                                <select class="form-select" id="f_gorev"><option value="">Tümü</option></select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select" id="f_durum">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Başarılı</option>
                                                    <option value="2">Hata</option>
                                                    <option value="0">Çalışıyor</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Tetikleyen</label>
                                                <select class="form-select" id="f_tetik">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Otomatik</option>
                                                    <option value="0">Manuel</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Başlangıç</label>
                                                <input type="date" class="form-control" id="f_baslangic">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Bitiş</label>
                                                <input type="date" class="form-control" id="f_bitis">
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                <button type="button" class="btn btn-secondary" id="btnFiltreTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped table-hover" id="gecmisTablo">
                                        <thead>
                                            <tr>
                                                <th width="60">ID</th>
                                                <th>Görev</th>
                                                <th>Zamanlayıcı</th>
                                                <th width="150">Başlangıç</th>
                                                <th width="70">Süre</th>
                                                <th width="100">Tetikleyen</th>
                                                <th width="90">Durum</th>
                                                <th>Sonuç</th>
                                                <th width="60">Detay</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Modal: Zamanlayıcı Ekle/Düzenle -->
<div class="modal fade" id="modalZamanlama" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock"></i> <span id="zModalBaslik">Zamanlayıcı Ekle</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="zamanlamaForm">
                <div class="modal-body">
                    <input type="hidden" id="z_id">
                    <input type="hidden" id="z_gorev_id">

                    <div class="alert alert-secondary py-2 mb-3">
                        <small class="text-muted">Görev</small>
                        <div class="fw-semibold" id="z_gorev_ad">—</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Zamanlayıcı Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="z_ad" required maxlength="200">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Sıklık Şablonu</label>
                            <select class="form-select" id="z_sablon"><option value="">Seçiniz...</option></select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Cron İfadesi <span class="text-danger">*</span></label>
                            <input type="text" class="form-control kod-badge" id="z_ifade" required placeholder="0 10 * * *">
                            <small class="text-muted">Dakika Saat GünAyı AySayısı HaftaGünü (0=Pazar)</small>
                            <div class="invalid-feedback" id="z_ifade_hata"></div>
                            <div class="form-text text-success d-none" id="z_sonraki"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" id="z_baslangic" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Bitiş Tarihi <small class="text-muted">(boş = sürekli)</small></label>
                            <input type="datetime-local" class="form-control" id="z_bitis">
                        </div>

                        <div class="col-12">
                            <div class="border rounded p-3 bg-body-tertiary">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="z_telafi">
                                    <label class="form-check-label fw-semibold" for="z_telafi">
                                        Kaçırılan tetiği telafi et
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    Sunucu tetik anında çalışmazsa (restart, bakım) görev sonradan
                                    <strong>bir kez</strong> çalıştırılır. Günlük/haftalık raporlar için önerilir;
                                    dakikalık görevlerde kapalı bırakın.
                                </small>
                                <div class="mt-2 d-none" id="z_telafi_saat_alan" style="max-width:220px;">
                                    <label class="form-label mb-1">Telafi sınırı (saat)</label>
                                    <input type="number" class="form-control form-control-sm" id="z_telafi_saat" min="1" max="72" value="6">
                                    <small class="text-muted">Bu süreden eski tetikler telafi edilmez.</small>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 d-none" id="z_param_alan">
                            <hr>
                            <h6 class="fw-semibold"><i class="bi bi-sliders"></i> Sabit Parametreler</h6>
                            <div id="z_param_liste" class="row g-3"></div>
                            <div class="alert alert-light border mt-3 mb-0 py-2">
                                <small><strong>Dinamik Tarih Değerleri:</strong> <span id="z_tarih_listesi"></span></small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Manuel Çalıştır (parametre formu) -->
<div class="modal fade" id="modalTetik" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-play-fill"></i> Manuel Çalıştır</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="tetikForm">
                <div class="modal-body">
                    <input type="hidden" id="t_gorev_id">
                    <div class="alert alert-secondary py-2">
                        <div class="fw-semibold" id="t_gorev_ad">—</div>
                    </div>
                    <div id="t_param_liste" class="row g-3"></div>
                    <div class="alert alert-light border mt-3 mb-0 py-2 d-none" id="t_tarih_bilgi">
                        <small><strong>Dinamik Tarih Değerleri:</strong> <span id="t_tarih_listesi"></span></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-play-fill"></i> Çalıştır</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Çıktı -->
<div class="modal fade" id="modalCikti" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-terminal"></i> <span id="c_baslik">Çalışma Çıktısı</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="c_ozet" class="mb-3"></div>
                <div id="c_meta" class="mb-3 small text-muted"></div>
                <div class="cikti-kutu" id="c_cikti">—</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script src="/admin/assets/vendor/jquery/jquery.min.js"></script>
<script src="/admin/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/vendor/select2/js/select2.min.js"></script>
<script src="/admin/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const permissions = <?= json_encode($pagePermissions) ?>;

const modalZamanlama = new bootstrap.Modal('#modalZamanlama');
const modalTetik     = new bootstrap.Modal('#modalTetik');
const modalCikti     = new bootstrap.Modal('#modalCikti');

let gorevler     = [];
let zamanlamalar = [];
let sablonlar    = [];
let tarihListesi = {};
let kullaniciListesi = [];
let markaListesi     = [];
let gecmisTablo  = null;

const DURUM_ETIKET = {
    0: { metin: 'Çalışıyor', renk: 'warning', ikon: 'hourglass-split' },
    1: { metin: 'Başarılı',  renk: 'success', ikon: 'check-circle' },
    2: { metin: 'Hata',      renk: 'danger',  ikon: 'x-circle' }
};

const esc = s => $('<div>').text(s ?? '').html();

// Süre 0 saniye ise "0s" yerine "<1s" — görev gerçekten çalıştı ama 1 sn'den kısa sürdü
const sureMetin = s => (s === null || s === undefined) ? '—' : (parseInt(s, 10) === 0 ? '&lt;1s' : s + 's');

// ── Plesk tanımını kopyala (CLI komutu / URL)
$('.kopyala').on('click', function () {
    const btn   = $(this);
    const metin = $(btn.data('hedef')).text().trim();

    navigator.clipboard.writeText(metin).then(() => {
        showToast('Panoya kopyalandı.', 'success');
        btn.html('<i class="bi bi-clipboard-check"></i>');
        setTimeout(() => btn.html('<i class="bi bi-clipboard"></i>'), 2000);
    }).catch(() => showError('Kopyalanamadı', 'Tarayıcı panoya erişimi engelledi.'));
});

// ── İstatistikler
function loadStats() {
    $.post('', { action: 'stats' }, r => {
        if (!r.success) return;

        $('#stat-gorev').text(r.data.Gorev);
        $('#stat-zamanlayici').text(r.data.Zamanlayici);
        $('#stat-basarili').text(r.data.Basarili);
        $('#stat-hatali').text(r.data.Hatali);

        const calisiyor = parseInt(r.data.Calisiyor, 10) || 0;
        $('#calisiyorAdet').text(calisiyor);
        $('#calisiyorUyari').toggleClass('d-none', calisiyor === 0);
    }, 'json');
}

// ── Görev + zamanlayıcı listesi
function loadGorevler() {
    $.post('', { action: 'gorevler' }, r => {
        if (!r.success) return showError('Hata', r.message || 'Görevler yüklenemedi.');

        gorevler     = r.gorevler;
        zamanlamalar = r.zamanlamalar;

        renderGorevler();
        doldurGorevFiltre();
    }, 'json');
}

function renderGorevler() {
    const kap = $('#gorevListesi').empty();

    if (!gorevler.length) {
        kap.html('<div class="text-center text-muted py-4">Tanımlı görev yok.</div>');
        return;
    }

    gorevler.forEach(g => {
        const zList = zamanlamalar.filter(z => z.GorevId == g.Id);

        let satirlar = '';

        if (!zList.length) {
            satirlar = `<tr><td colspan="7" class="text-center text-muted py-3">
                Bu göreve tanımlı zamanlayıcı yok.</td></tr>`;
        } else {
            zList.forEach(z => {
                const aktif = parseInt(z.Durum, 10) === 1;
                const d     = z.CalismaDurum === null ? null : DURUM_ETIKET[z.CalismaDurum];

                const durumHtml = d
                    ? `<span class="badge text-bg-${d.renk}">${d.metin}</span>` +
                      (z.SureSaniye !== null ? `<div class="small text-muted">${sureMetin(z.SureSaniye)}</div>` : '')
                    : '<span class="text-muted">—</span>';

                const telafiRozet = parseInt(z.TelafiEt, 10) === 1
                    ? `<span class="badge text-bg-info ms-1" title="Kaçırılan tetik ${z.TelafiSaat} saate kadar telafi edilir">
                         <i class="bi bi-arrow-clockwise"></i> telafi</span>`
                    : '';

                satirlar += `
                <tr class="${aktif ? '' : 'zamanlayici-pasif'}">
                    <td><strong>${esc(z.Ad)}</strong>${telafiRozet}</td>
                    <td><code class="kod-badge cron-ifade">${esc(z.Ifade)}</code></td>
                    <td>
                        ${esc(z.Baslangic || '—')}
                        <div class="small text-muted">${z.Bitis ? esc(z.Bitis) : 'Sürekli'}</div>
                    </td>
                    <td>
                        ${esc(z.SonCalisma || '—')}
                        ${z.Sonraki ? `<div class="small text-success">sonraki: ${esc(z.Sonraki)}</div>` : ''}
                    </td>
                    <td>${durumHtml}</td>
                    <td>${aktif
                        ? '<span class="badge text-bg-success">Aktif</span>'
                        : '<span class="badge text-bg-secondary">Duraklatıldı</span>'}</td>
                    <td class="text-nowrap">
                        ${permissions.can_edit ? `
                        <button class="btn btn-sm btn-outline-warning" onclick="zamanlamaDurum(${z.Id}, ${aktif ? 0 : 1})"
                                title="${aktif ? 'Duraklat' : 'Devam ettir'}">
                            <i class="bi bi-${aktif ? 'pause' : 'play'}-fill"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-success" onclick="tetikleZamanlama(${z.Id}, '${esc(z.Ad)}')"
                                title="Şimdi çalıştır">
                            <i class="bi bi-play-fill"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-primary" onclick="zamanlamaDuzenle(${z.Id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>` : ''}
                        ${permissions.can_delete ? `
                        <button class="btn btn-sm btn-outline-danger" onclick="zamanlamaSil(${z.Id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>` : ''}
                    </td>
                </tr>`;
            });
        }

        kap.append(`
        <div class="card gorev-kart mb-3">
            <div class="card-header d-flex align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-bold">${esc(g.Ad)}</h6>
                <code class="kod-badge badge text-bg-light border">${esc(g.Kod)}</code>
                <span class="text-muted small flex-grow-1">${esc(g.Aciklama || '')}</span>
                ${permissions.can_edit ? `
                <button class="btn btn-sm btn-outline-success" onclick="tetikleGorev(${g.Id})">
                    <i class="bi bi-play-fill"></i> Manuel Çalıştır
                </button>` : ''}
                ${permissions.can_add ? `
                <button class="btn btn-sm btn-primary" onclick="zamanlamaEkle(${g.Id})">
                    <i class="bi bi-plus-circle"></i> Zamanlayıcı Ekle
                </button>` : ''}
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Zamanlayıcı Adı</th>
                            <th width="140">Cron İfadesi</th>
                            <th width="170">Başlangıç / Bitiş</th>
                            <th width="190">Son Çalışma</th>
                            <th width="110">Son Durum</th>
                            <th width="110">Durum</th>
                            <th width="170">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>${satirlar}</tbody>
                </table>
            </div>
        </div>`);
    });
}

// ── Sıklık şablonları + dinamik tarih listesi
function loadSablonlar() {
    $.post('', { action: 'siklik_sablonlari' }, r => {
        if (!r.success) return;

        sablonlar        = r.sablonlar;
        tarihListesi     = r.tarihler;
        kullaniciListesi = r.kullanicilar || [];
        markaListesi     = r.markalar || [];

        const sel = $('#z_sablon').empty().append('<option value="">Seçiniz...</option>');
        sablonlar.forEach(s => sel.append(`<option value="${esc(s.Ifade)}">${esc(s.Ad)} — ${esc(s.Ifade)}</option>`));

        const html = Object.entries(tarihListesi)
            .map(([k, v]) => `<code class="text-danger">${esc(k)}</code> ${esc(v)}`)
            .join(' &nbsp; ');

        $('#z_tarih_listesi, #t_tarih_listesi').html(html);
    }, 'json');
}

// ── Parametre alanlarını şemadan üret
function paramAlanlariOlustur(semaJson, mevcutJson, hedef, onek) {
    const kap = $(hedef).empty();

    let sema = [];
    let mevcut = {};

    try { sema   = semaJson   ? JSON.parse(semaJson)   : []; } catch (e) { sema = []; }
    try { mevcut = mevcutJson ? JSON.parse(mevcutJson) : {}; } catch (e) { mevcut = {}; }

    if (!Array.isArray(sema) || !sema.length) return false;

    sema.forEach(a => {
        const ad     = a.ad;
        const etiket = a.etiket || ad;
        const deger  = mevcut[ad] !== undefined ? mevcut[ad] : (a.varsayilan ?? '');
        const zor    = a.zorunlu ? '<span class="text-danger">*</span>' : '';
        const id     = onek + ad;

        if (a.tip === 'checkbox') {
            kap.append(`
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="${id}" ${deger == 1 ? 'checked' : ''}>
                    <label class="form-check-label" for="${id}">${esc(etiket)}</label>
                </div>
            </div>`);
        } else if (a.tip === 'kullanici_coklu') {
            // Kayıtlı değer "1,2,5" biçiminde ID listesidir
            const secili = String(deger || '').split(',').map(x => x.trim()).filter(Boolean);

            const opsiyonlar = kullaniciListesi.map(k =>
                `<option value="${k.Id}" ${secili.includes(String(k.Id)) ? 'selected' : ''}>` +
                `${esc(k.Ad)} — ${esc(k.Email)}</option>`
            ).join('');

            kap.append(`
            <div class="col-12">
                <label class="form-label">${esc(etiket)} ${zor}</label>
                <select class="form-select coklu-kullanici" id="${id}" multiple ${a.zorunlu ? 'required' : ''}>
                    ${opsiyonlar}
                </select>
                <small class="text-muted">
                    Birden fazla kişi seçebilirsiniz. Yalnızca kullanıcı seçilir;
                    e-posta adresleri gönderim anında güncel haliyle okunur.
                </small>
            </div>`);
        } else if (a.tip === 'marka_coklu') {
            // Kayıtlı değer "23,11" biçiminde marka ID listesidir
            const secili = String(deger || '').split(',').map(x => x.trim()).filter(Boolean);

            const opsiyonlar = markaListesi.map(m =>
                `<option value="${m.Id}" ${secili.includes(String(m.Id)) ? 'selected' : ''}>` +
                `${esc(m.Ad)}</option>`
            ).join('');

            kap.append(`
            <div class="col-12">
                <label class="form-label">${esc(etiket)} ${zor}</label>
                <select class="form-select coklu-marka" id="${id}" multiple ${a.zorunlu ? 'required' : ''}>
                    ${opsiyonlar}
                </select>
                <small class="text-muted">
                    Birden fazla marka seçebilirsiniz. Marka adı değişirse rapora
                    güncel adıyla yansır, zamanlayıcıya dokunmak gerekmez.
                </small>
            </div>`);
        } else {
            const tip = a.tip === 'number' ? 'number' : 'text';
            kap.append(`
            <div class="col-md-6">
                <label class="form-label">${esc(etiket)} ${zor}</label>
                <input type="${tip}" class="form-control" id="${id}" value="${esc(deger)}" ${a.zorunlu ? 'required' : ''}>
            </div>`);
        }
    });

    // Çoklu kullanıcı alanlarına Select2 (aranabilir) uygula.
    // dropdownParent modalın kendisi olmalı, aksi halde açılır liste modalın altında kalır.
    const modalKap = onek === 'zp_' ? $('#modalZamanlama') : $('#modalTetik');

    kap.find('.coklu-kullanici').each(function () {
        $(this).select2({
            theme: 'bootstrap-5',
            dropdownParent: modalKap,
            placeholder: 'Kişi seçiniz...',
            closeOnSelect: false,
            width: '100%',
            language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' }
        });
    });

    kap.find('.coklu-marka').each(function () {
        $(this).select2({
            theme: 'bootstrap-5',
            dropdownParent: modalKap,
            placeholder: 'Marka seçiniz...',
            closeOnSelect: false,
            width: '100%',
            language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' }
        });
    });

    return true;
}

function paramDegerleriTopla(semaJson, onek) {
    const veri = {};

    let sema = [];
    try { sema = semaJson ? JSON.parse(semaJson) : []; } catch (e) { sema = []; }
    if (!Array.isArray(sema)) return veri;

    sema.forEach(a => {
        const el = $('#' + onek + a.ad);
        if (!el.length) return;

        if (a.tip === 'checkbox') {
            veri['param_' + a.ad] = el.is(':checked') ? 1 : 0;
        } else if (a.tip === 'kullanici_coklu' || a.tip === 'marka_coklu') {
            // Select2 multiple dizi döner; sunucuya virgüllü metin olarak gider
            veri['param_' + a.ad] = (el.val() || []).join(',');
        } else {
            veri['param_' + a.ad] = el.val();
        }
    });

    return veri;
}

// ── Zamanlayıcı ekle / düzenle
function zamanlamaEkle(gorevId) {
    const g = gorevler.find(x => x.Id == gorevId);
    if (!g) return;

    $('#zModalBaslik').text('Zamanlayıcı Ekle');
    $('#zamanlamaForm')[0].reset();
    $('#z_id').val('');
    $('#z_gorev_id').val(gorevId);
    $('#z_gorev_ad').text(g.Ad);
    $('#z_ifade').removeClass('is-invalid');
    $('#z_sonraki').addClass('d-none');
    $('#z_telafi').prop('checked', false).trigger('change');
    $('#z_telafi_saat').val(6);

    const simdi = new Date();
    simdi.setMinutes(simdi.getMinutes() - simdi.getTimezoneOffset());
    $('#z_baslangic').val(simdi.toISOString().slice(0, 16));

    const varMi = paramAlanlariOlustur(g.Parametreler, null, '#z_param_liste', 'zp_');
    $('#z_param_alan').toggleClass('d-none', !varMi);

    $('#z_sablon').val('').trigger('change.select2');
    modalZamanlama.show();
}

function zamanlamaDuzenle(id) {
    $.post('', { action: 'zamanlama_get', id: id }, r => {
        if (!r.success) return showError('Hata', r.message);

        const z = r.data;

        $('#zModalBaslik').text('Zamanlayıcı Düzenle');
        $('#z_id').val(z.Id);
        $('#z_gorev_id').val(z.GorevId);
        $('#z_gorev_ad').text(z.GorevAd);
        $('#z_ad').val(z.Ad);
        $('#z_ifade').val(z.Ifade).removeClass('is-invalid');
        $('#z_baslangic').val(z.Baslangic || '');
        $('#z_bitis').val(z.Bitis || '');
        $('#z_telafi').prop('checked', parseInt(z.TelafiEt, 10) === 1).trigger('change');
        $('#z_telafi_saat').val(z.TelafiSaat || 6);
        $('#z_sonraki').addClass('d-none');

        const varMi = paramAlanlariOlustur(z.GorevParametreler, z.Parametreler, '#z_param_liste', 'zp_');
        $('#z_param_alan').toggleClass('d-none', !varMi);

        // Şablon eşleşiyorsa dropdown'da göster
        const eslesen = sablonlar.find(s => s.Ifade === z.Ifade);
        $('#z_sablon').val(eslesen ? eslesen.Ifade : '').trigger('change.select2');

        modalZamanlama.show();
    }, 'json');
}

$('#z_sablon').on('change', function () {
    const v = $(this).val();
    if (v) $('#z_ifade').val(v).trigger('input');
});

$('#z_telafi').on('change', function () {
    $('#z_telafi_saat_alan').toggleClass('d-none', !$(this).is(':checked'));
});

$('#zamanlamaForm').on('submit', function (e) {
    e.preventDefault();

    const gorevId = $('#z_gorev_id').val();
    const g       = gorevler.find(x => x.Id == gorevId);

    const veri = {
        action:      'zamanlama_kaydet',
        id:          $('#z_id').val(),
        gorev_id:    gorevId,
        ad:          $('#z_ad').val(),
        ifade:       $('#z_ifade').val(),
        baslangic:   $('#z_baslangic').val(),
        bitis:       $('#z_bitis').val(),
        telafi_et:   $('#z_telafi').is(':checked') ? 1 : 0,
        telafi_saat: $('#z_telafi_saat').val(),
        ...paramDegerleriTopla(g ? g.Parametreler : null, 'zp_')
    };

    $.post('', veri, r => {
        if (!r.success) {
            $('#z_ifade').addClass('is-invalid');
            $('#z_ifade_hata').text(r.message);
            return showError('Kaydedilemedi', r.message);
        }

        showToast(r.message, 'success');
        modalZamanlama.hide();
        loadGorevler();
        loadStats();
    }, 'json');
});

function zamanlamaDurum(id, durum) {
    $.post('', { action: 'zamanlama_durum', id: id, durum: durum }, r => {
        if (!r.success) return showError('Hata', r.message);

        showToast(r.message, 'success');
        loadGorevler();
        loadStats();
    }, 'json');
}

function zamanlamaSil(id) {
    confirmAction('Zamanlayıcı silinsin mi?', 'Çalışma geçmişi korunur, zamanlayıcı pasife çekilir.', () => {
        $.post('', { action: 'zamanlama_sil', id: id }, r => {
            if (!r.success) return showError('Hata', r.message);

            showToast(r.message, 'success');
            loadGorevler();
            loadStats();
        }, 'json');
    });
}

// ── Manuel çalıştırma
function tetikleZamanlama(zamanlamaId, ad) {
    confirmAction('Şimdi çalıştırılsın mı?', ad + ' zamanlayıcısı hemen çalıştırılacak.', () => {
        calistir({ action: 'tetikle', zamanlama_id: zamanlamaId }, ad);
    });
}

function tetikleGorev(gorevId) {
    const g = gorevler.find(x => x.Id == gorevId);
    if (!g) return;

    const varMi = paramAlanlariOlustur(g.Parametreler, null, '#t_param_liste', 'tp_');

    // Parametresiz görev: doğrudan onay al ve çalıştır
    if (!varMi) {
        confirmAction('Şimdi çalıştırılsın mı?', g.Ad + ' görevi hemen çalıştırılacak.', () => {
            calistir({ action: 'tetikle', gorev_id: gorevId }, g.Ad);
        });
        return;
    }

    $('#t_gorev_id').val(gorevId);
    $('#t_gorev_ad').text(g.Ad);
    $('#t_tarih_bilgi').removeClass('d-none');
    modalTetik.show();
}

$('#tetikForm').on('submit', function (e) {
    e.preventDefault();

    const gorevId = $('#t_gorev_id').val();
    const g       = gorevler.find(x => x.Id == gorevId);

    modalTetik.hide();

    calistir({
        action:   'tetikle',
        gorev_id: gorevId,
        ...paramDegerleriTopla(g ? g.Parametreler : null, 'tp_')
    }, g ? g.Ad : '');
});

function calistir(veri, baslik) {
    showLoading('Görev çalışıyor, lütfen bekleyin...');

    $.post('', veri, r => {
        hideLoading();

        if (r.atlandi) {
            showWarning('Çalıştırılamadı', r.message);
            loadStats();
            return;
        }

        const basarili = r.durum === 1;

        $('#c_baslik').text(baslik || 'Çalışma Çıktısı');
        $('#c_ozet').html(
            `<div class="alert alert-${basarili ? 'success' : 'danger'} mb-0">
                <i class="bi bi-${basarili ? 'check-circle' : 'x-circle'}"></i> ${esc(r.message)}
             </div>`
        );

        const pStr = r.params && Object.keys(r.params).length
            ? ' &nbsp;|&nbsp; Parametreler: <code>' + esc(JSON.stringify(r.params)) + '</code>' : '';

        $('#c_meta').html(`Log #${r.log_id ?? '—'}${pStr}`);
        $('#c_cikti').text(r.cikti && r.cikti.trim() !== '' ? r.cikti : '(çıktı yok)');

        modalCikti.show();

        loadStats();
        loadGorevler();

        if (gecmisTablo) loadGecmis();
    }, 'json').fail((xhr, status) => {
        hideLoading();
        showError('İstek başarısız', status === 'timeout'
            ? 'Görev uzun sürdü, yanıt alınamadı. Çalışma geçmişinden sonucu kontrol edin.'
            : 'Sunucuya ulaşılamadı.');
    });
}

// ── Çalışma geçmişi
function doldurGorevFiltre() {
    const sel = $('#f_gorev').val();

    $('#f_gorev').empty().append('<option value="">Tümü</option>');
    gorevler.forEach(g => $('#f_gorev').append(`<option value="${g.Id}">${esc(g.Ad)}</option>`));
    $('#f_gorev').val(sel).trigger('change.select2');
}

function loadGecmis() {
    const veri = {
        action:    'gecmis',
        gorev_id:  $('#f_gorev').val(),
        durum:     $('#f_durum').val(),
        tetik_tur: $('#f_tetik').val(),
        baslangic: $('#f_baslangic').val(),
        bitis:     $('#f_bitis').val()
    };

    $.post('', veri, r => {
        if (!r.success) return showError('Hata', r.message);

        const satirlar = r.data.map(l => {
            const d = DURUM_ETIKET[l.Durum] || DURUM_ETIKET[2];

            return [
                l.Id,
                esc(l.Gorev),
                l.Zamanlama ? esc(l.Zamanlama) : '<span class="text-muted">manuel</span>',
                esc(l.Baslangic || ''),
                sureMetin(l.Sure),
                parseInt(l.TetikTur, 10) === 1
                    ? '<span class="badge text-bg-secondary">Otomatik</span>'
                    : `<span class="badge text-bg-primary" title="${esc(l.Kullanici || '')}">Manuel</span>`,
                `<span class="badge text-bg-${d.renk}"><i class="bi bi-${d.ikon}"></i> ${d.metin}</span>`,
                esc((l.Sonuc || '').substring(0, 90)),
                `<button class="btn btn-sm btn-outline-secondary" onclick="gecmisDetay(${l.Id})">
                    <i class="bi bi-eye"></i></button>`
            ];
        });

        if (gecmisTablo) {
            gecmisTablo.clear().rows.add(satirlar).draw();
        } else {
            gecmisTablo = $('#gecmisTablo').DataTable({
                data: satirlar,
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    url: '',
                    emptyTable:     'Kayıt bulunamadı',
                    info:           '_TOTAL_ kayıttan _START_ - _END_ arası',
                    infoEmpty:      'Kayıt yok',
                    infoFiltered:   '(_MAX_ kayıt içinden filtrelendi)',
                    lengthMenu:     '_MENU_ kayıt göster',
                    loadingRecords: 'Yükleniyor...',
                    processing:     'İşleniyor...',
                    search:         'Ara:',
                    zeroRecords:    'Eşleşen kayıt bulunamadı',
                    paginate: { first: 'İlk', last: 'Son', next: 'Sonraki', previous: 'Önceki' }
                },
                columnDefs: [{ targets: [8], orderable: false }]
            });
        }
    }, 'json');
}

function gecmisDetay(id) {
    $.post('', { action: 'gecmis_detay', id: id }, r => {
        if (!r.success) return showError('Hata', r.message);

        const l = r.data;
        const d = DURUM_ETIKET[l.Durum] || DURUM_ETIKET[2];

        $('#c_baslik').text(l.Gorev + (l.Zamanlama ? ' / ' + l.Zamanlama : ''));
        $('#c_ozet').html(
            `<div class="alert alert-${d.renk === 'success' ? 'success' : (d.renk === 'danger' ? 'danger' : 'warning')} mb-0">
                <i class="bi bi-${d.ikon}"></i> ${esc(l.Sonuc || d.metin)}
             </div>`
        );

        let meta = `Log #${l.Id} &nbsp;|&nbsp; Başlangıç: ${esc(l.Baslangic || '—')}`;
        meta += ` &nbsp;|&nbsp; Bitiş: ${esc(l.Bitis || '—')}`;
        meta += ` &nbsp;|&nbsp; Süre: ${sureMetin(l.Sure)}`;
        meta += ` &nbsp;|&nbsp; Tetikleyen: ${parseInt(l.TetikTur, 10) === 1 ? 'Otomatik' : 'Manuel' + (l.Kullanici ? ' (' + esc(l.Kullanici) + ')' : '')}`;

        if (l.Parametreler) meta += `<br>Parametreler: <code>${esc(l.Parametreler)}</code>`;

        $('#c_meta').html(meta);
        $('#c_cikti').text(l.Cikti && l.Cikti.trim() !== '' ? l.Cikti : '(çıktı kaydedilmemiş)');

        modalCikti.show();
    }, 'json');
}

$('#filtreForm').on('submit', function (e) {
    e.preventDefault();

    const b = $('#f_baslangic').val();
    const s = $('#f_bitis').val();

    if (b && s && b > s) return showError('Geçersiz tarih', 'Başlangıç tarihi bitiş tarihinden büyük olamaz.');

    loadGecmis();
});

$('#btnFiltreTemizle').on('click', function () {
    $('#filtreForm')[0].reset();
    $('#f_gorev, #f_durum, #f_tetik').val('').trigger('change.select2');
    loadGecmis();
});

$('#btnGecmisTab').on('shown.bs.tab', function () {
    if (!gecmisTablo) loadGecmis();
});

// ── Başlangıç
$(document).ready(function () {
    $('#z_sablon').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#modalZamanlama'),
        placeholder: 'Seçiniz...',
        allowClear: true,
        language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' }
    });

    $('#f_gorev, #f_durum, #f_tetik').select2({
        theme: 'bootstrap-5',
        placeholder: 'Tümü',
        allowClear: true,
        language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' }
    });

    loadStats();
    loadSablonlar();
    loadGorevler();

    // İstatistikleri periyodik tazele (çalışan görev göstergesi için)
    setInterval(loadStats, 60000);
});
</script>
</body>
</html>
