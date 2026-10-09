<?php
/**
 * Admin Panel - Depo Stok Sayım & Transfer
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::check()) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Oturum süresi doldu.', 'redirect' => '/Admin/login.php']));
    }
} else {
    requireAuth();
}

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Depo Stok Sayım';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$permissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

/**
 * Excel satırlarını stok koduna göre ürünlerle eşleştirir, uygulanacak değişiklik planını döner.
 * $satirlar: [['kod' => ..., 'miktar' => ...], ...]
 */
function excelStokPlani($db, array $satirlar, bool $sifirla, bool $pasifYap): array
{
    // Excel'de aynı kod birden fazla geçerse miktarlar toplanır
    $excel = [];
    $hatali = [];
    foreach ($satirlar as $i => $s) {
        $kod = trim((string)($s['kod'] ?? ''));
        $ham = trim(str_replace(' ', '', (string)($s['miktar'] ?? '')));
        if ($kod === '' && $ham === '') continue;
        if ($kod === '' && (int)($s['id'] ?? 0) <= 0) { $hatali[] = ['satir' => $i + 2, 'kod' => '', 'neden' => 'Stok kodu boş']; continue; }
        if ($ham === '') $ham = '0'; // Boş miktar = sayımda yok
        if (strpos($ham, ',') !== false) $ham = str_replace(['.', ','], ['', '.'], $ham);
        if (!is_numeric($ham)) { $hatali[] = ['satir' => $i + 2, 'kod' => $kod, 'neden' => 'Miktar geçersiz: "' . mb_substr((string)$s['miktar'], 0, 20) . '"']; continue; }
        if ((float)$ham < 0) { $hatali[] = ['satir' => $i + 2, 'kod' => $kod, 'neden' => 'Miktar negatif']; continue; }
        // Ürün ID kolonu varsa (yedek dosyası) ID ile, yoksa stok koduyla eşleştirilir
        $id      = (int)($s['id'] ?? 0);
        $anahtar = $id > 0 ? 'ID#' . $id : mb_strtoupper($kod, 'UTF-8');
        if (!isset($excel[$anahtar])) $excel[$anahtar] = ['kod' => $kod, 'id' => $id, 'miktar' => 0.0, 'adet' => 0, 'durum' => null];
        $excel[$anahtar]['miktar'] += (float)$ham;
        $excel[$anahtar]['adet']++;

        // Durum kolonu varsa ürün durumu dosyadaki değere çekilir
        $durum = mb_strtolower(trim((string)($s['durum'] ?? '')), 'UTF-8');
        if (in_array($durum, ['aktif', '1', 'evet'], true))      $excel[$anahtar]['durum'] = 1;
        elseif (in_array($durum, ['pasif', '0', 'hayır', 'hayir'], true)) $excel[$anahtar]['durum'] = 0;
    }

    // Pasif ürünler de okunur: eşleşme ve durum değişikliği için gerekir
    $urunler = $db->fetchAll("
        SELECT urun_hizmet_id, urun_hizmet_kodu, urun_hizmet_adi, ISNULL(Urun_Stok_Miktari,0) AS stok,
               CASE WHEN urun_hizmet_durum = 1 THEN 1 ELSE 0 END AS aktif
        FROM dbo.Urun_Hizmet
        WHERE urun_hizmet_tip = 1
    ");
    $kodMap = [];
    $idMap  = [];
    foreach ($urunler as $u) {
        $idMap[(int)$u['urun_hizmet_id']] = $u;
        $k = mb_strtoupper(trim((string)$u['urun_hizmet_kodu']), 'UTF-8');
        if ($k === '') continue;
        $kodMap[$k][] = $u;
    }

    $degisiklikler = [];
    $eslesenIdler  = [];
    $eslesmeyen    = [];
    foreach ($excel as $anahtar => $e) {
        $adaylar = $e['id'] > 0 ? (isset($idMap[$e['id']]) ? [$idMap[$e['id']]] : []) : ($kodMap[$anahtar] ?? []);
        if (empty($adaylar)) { $eslesmeyen[] = ['kod' => $e['kod'] . ($e['id'] ? ' (ID ' . $e['id'] . ')' : ''), 'neden' => 'Ürün bulunamadı']; continue; }

        // Aynı kodda birden fazla ürün varsa aktif olan tercih edilir
        $aktifler = array_values(array_filter($adaylar, fn($u) => (int)$u['aktif'] === 1));
        if (count($aktifler) === 1)      $u = $aktifler[0];
        elseif (count($adaylar) === 1)   $u = $adaylar[0];
        else { $eslesmeyen[] = ['kod' => $e['kod'], 'neden' => 'Bu koda ait birden fazla ürün var']; continue; }

        // Durum: dosyada belirtilmişse o değer, yoksa sayılan pasif ürün aktife alınır (pasifleştirme kapalıyken)
        if ($e['durum'] !== null) $hedefDurum = $e['durum'] !== (int)$u['aktif'] ? ($e['durum'] ? 'aktif' : 'pasif') : null;
        else                      $hedefDurum = (!$pasifYap && (int)$u['aktif'] !== 1) ? 'aktif' : null;

        $eslesenIdler[(int)$u['urun_hizmet_id']] = true;
        $degisiklikler[(int)$u['urun_hizmet_id']] = [
            'urun_id' => (int)$u['urun_hizmet_id'],
            'kod'     => $u['urun_hizmet_kodu'],
            'adi'     => $u['urun_hizmet_adi'],
            'mevcut'  => (float)$u['stok'],
            'yeni'    => $e['miktar'],
            'fark'    => round($e['miktar'] - (float)$u['stok'], 4),
            'kaynak'  => $e['adet'] > 1 ? 'Excel (' . $e['adet'] . ' satır toplandı)' : 'Excel',
            'durum'   => $hedefDurum,
        ];
    }

    if ($sifirla) {
        foreach ($urunler as $u) {
            if ((int)$u['aktif'] !== 1 || isset($eslesenIdler[(int)$u['urun_hizmet_id']]) || (float)$u['stok'] == 0) continue;
            $degisiklikler[(int)$u['urun_hizmet_id']] = [
                'urun_id' => (int)$u['urun_hizmet_id'],
                'kod'     => $u['urun_hizmet_kodu'],
                'adi'     => $u['urun_hizmet_adi'],
                'mevcut'  => (float)$u['stok'],
                'yeni'    => 0.0,
                'fark'    => -(float)$u['stok'],
                'kaynak'  => 'Sıfırlama',
                'durum'   => null,
            ];
        }
    }

    // Pasifleştirme: işlem sonunda stoğu > 0 olan aktif, diğer tüm ürünler pasif
    if ($pasifYap) {
        foreach ($urunler as $u) {
            $id       = (int)$u['urun_hizmet_id'];
            $sonStok  = isset($degisiklikler[$id]) ? $degisiklikler[$id]['yeni'] : (float)$u['stok'];
            $olmali   = $sonStok > 0 ? 1 : 0;
            if ($olmali === (int)$u['aktif']) continue;
            if (!isset($degisiklikler[$id])) {
                $degisiklikler[$id] = [
                    'urun_id' => $id,
                    'kod'     => $u['urun_hizmet_kodu'],
                    'adi'     => $u['urun_hizmet_adi'],
                    'mevcut'  => (float)$u['stok'],
                    'yeni'    => (float)$u['stok'],
                    'fark'    => 0.0,
                    'kaynak'  => 'Durum',
                ];
            }
            $degisiklikler[$id]['durum'] = $olmali ? 'aktif' : 'pasif';
        }
    }

    $degisiklikler = array_values($degisiklikler);
    return compact('degisiklikler', 'eslesmeyen', 'hatali');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'OrnekFirma Portal';

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'stats':
                // Transfer = CIKIS kayıtları ile Ref_Hareket_id IS NOT NULL
                $toplam       = $db->fetchOne("SELECT COUNT(*) as sayi FROM dbo.Urun_Stok_Hareketleri WHERE Durum = 1 AND Islem_Tipi = 'CIKIS' AND Ref_Hareket_id IS NOT NULL")['sayi'] ?? 0;
                $bugun        = $db->fetchOne("SELECT COUNT(*) as sayi FROM dbo.Urun_Stok_Hareketleri WHERE Durum = 1 AND Islem_Tipi = 'CIKIS' AND Ref_Hareket_id IS NOT NULL AND CONVERT(date, OlusturmaTarihi) = CONVERT(date, GETDATE())")['sayi'] ?? 0;
                $toplamMiktar = $db->fetchOne("SELECT ISNULL(SUM(Miktar),0) as toplam FROM dbo.Urun_Stok_Hareketleri WHERE Durum = 1 AND Islem_Tipi = 'CIKIS' AND Ref_Hareket_id IS NOT NULL")['toplam'] ?? 0;
                $bugunMiktar  = $db->fetchOne("SELECT ISNULL(SUM(Miktar),0) as toplam FROM dbo.Urun_Stok_Hareketleri WHERE Durum = 1 AND Islem_Tipi = 'CIKIS' AND Ref_Hareket_id IS NOT NULL AND CONVERT(date, OlusturmaTarihi) = CONVERT(date, GETDATE())")['toplam'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplam', 'bugun', 'toplamMiktar', 'bugunMiktar')]);
                break;

            case 'list':
                $startDate = $_POST['start_date'] ?? '';
                $endDate   = $_POST['end_date']   ?? '';
                $kaynakId  = $_POST['kaynak_urun_id'] ?? '';
                $hedefId   = $_POST['hedef_urun_id']  ?? '';
                $search    = $_POST['search'] ?? '';

                $where  = ["c.Durum = 1", "c.Islem_Tipi = 'CIKIS'", "c.Ref_Hareket_id IS NOT NULL"];
                $params = [];

                if ($startDate) { $where[] = "CONVERT(date, c.OlusturmaTarihi) >= ?"; $params[] = $startDate; }
                if ($endDate)   { $where[] = "CONVERT(date, c.OlusturmaTarihi) <= ?"; $params[] = $endDate; }
                if ($kaynakId)  { $where[] = "c.Urun_id = ?";  $params[] = (int)$kaynakId; }
                if ($hedefId)   { $where[] = "g.Urun_id = ?";  $params[] = (int)$hedefId; }
                if ($search) {
                    $where[] = "(ku.urun_hizmet_adi LIKE ? OR ku.urun_hizmet_kodu LIKE ? OR hu.urun_hizmet_adi LIKE ? OR hu.urun_hizmet_kodu LIKE ? OR c.Aciklama LIKE ?)";
                    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
                }

                $whereClause = implode(' AND ', $where);
                $sql = "
                    SELECT
                        c.Stok_Hareket_id,
                        c.Urun_id        AS kaynak_urun_id,
                        g.Urun_id        AS hedef_urun_id,
                        c.Miktar,
                        c.Aciklama,
                        c.OlusturmaTarihi,
                        ku.urun_hizmet_adi  AS kaynak_urun_adi,
                        ku.urun_hizmet_kodu AS kaynak_urun_kodu,
                        hu.urun_hizmet_adi  AS hedef_urun_adi,
                        hu.urun_hizmet_kodu AS hedef_urun_kodu,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS OlusturanAdi
                    FROM dbo.Urun_Stok_Hareketleri c
                    JOIN dbo.Urun_Stok_Hareketleri g  ON g.Stok_Hareket_id = c.Ref_Hareket_id
                    LEFT JOIN dbo.Urun_Hizmet ku       ON ku.urun_hizmet_id = c.Urun_id
                    LEFT JOIN dbo.Urun_Hizmet hu       ON hu.urun_hizmet_id = g.Urun_id
                    LEFT JOIN dbo.kullanicilar k        ON k.kullanici_id   = c.OlusturanKullanici
                    WHERE $whereClause
                    ORDER BY c.Stok_Hareket_id DESC
                ";
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            case 'get_urunler_search':
                $q   = trim($_POST['q'] ?? '');
                $sql = "SELECT TOP 30
                            urun_hizmet_id AS id,
                            urun_hizmet_adi + ' [' + ISNULL(urun_hizmet_kodu,'') + '] - Stok: ' + CAST(ISNULL(Urun_Stok_Miktari,0) AS NVARCHAR) AS text,
                            ISNULL(Urun_Stok_Miktari,0) AS stok
                        FROM dbo.Urun_Hizmet
                        WHERE urun_hizmet_tip = 1 AND urun_hizmet_durum = 1";
                $params = [];
                if (strlen($q) > 0) {
                    $sql   .= " AND (urun_hizmet_adi LIKE ? OR urun_hizmet_kodu LIKE ? OR urun_hizmet_barkod LIKE ?)";
                    $params = ["%$q%", "%$q%", "%$q%"];
                }
                $sql .= " ORDER BY urun_hizmet_adi";
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            case 'transfer':
                if (!$permissions['can_add']) throw new Exception('Ekleme yetkiniz yok.');

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (empty($rows)) throw new Exception('En az bir transfer satırı ekleyin.');

                $db->beginTransaction();
                try {
                    foreach ($rows as $row) {
                        $kaynakId = (int)($row['kaynak_urun_id'] ?? 0);
                        $hedefId  = (int)($row['hedef_urun_id']  ?? 0);
                        $miktar   = (float)($row['miktar'] ?? 0);
                        $aciklama = trim($row['aciklama'] ?? '');

                        if (!$kaynakId || !$hedefId)   throw new Exception('Kaynak ve hedef ürün zorunludur.');
                        if ($kaynakId === $hedefId)     throw new Exception('Kaynak ve hedef ürün aynı olamaz.');
                        if ($miktar <= 0)               throw new Exception('Miktar 0\'dan büyük olmalıdır.');

                        $kaynak = $db->fetchOne("SELECT urun_hizmet_adi, ISNULL(Urun_Stok_Miktari,0) AS stok FROM dbo.Urun_Hizmet WHERE urun_hizmet_id = ?", [$kaynakId]);
                        $hedef  = $db->fetchOne("SELECT urun_hizmet_adi FROM dbo.Urun_Hizmet WHERE urun_hizmet_id = ?", [$hedefId]);

                        if (!$kaynak) throw new Exception('Kaynak ürün bulunamadı.');
                        if (!$hedef)  throw new Exception('Hedef ürün bulunamadı.');
                        if ((float)$kaynak['stok'] < $miktar)
                            throw new Exception('"' . $kaynak['urun_hizmet_adi'] . '" için yeterli stok yok. Mevcut: ' . $kaynak['stok']);

                        // Önce GIRIS kaydını oluştur
                        $girisId = $db->insert('dbo.Urun_Stok_Hareketleri', [
                            'Urun_id'            => $hedefId,
                            'Islem_Tipi'         => 'GIRIS',
                            'Miktar'             => $miktar,
                            'Aciklama'           => substr('Stok Transferi ← ' . $kaynak['urun_hizmet_adi'] . ($aciklama ? ' / ' . $aciklama : ''), 0, 255),
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                            'Durum'              => 1,
                        ]);

                        // CIKIS kaydını GIRIS'e referansla oluştur
                        $db->insert('dbo.Urun_Stok_Hareketleri', [
                            'Urun_id'            => $kaynakId,
                            'Islem_Tipi'         => 'CIKIS',
                            'Miktar'             => $miktar,
                            'Ref_Hareket_id'     => $girisId,
                            'Aciklama'           => substr('Stok Transferi → ' . $hedef['urun_hizmet_adi'] . ($aciklama ? ' / ' . $aciklama : ''), 0, 255),
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                            'Durum'              => 1,
                        ]);

                        $db->execute("UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari,0) - ? WHERE urun_hizmet_id = ?", [$miktar, $kaynakId]);
                        $db->execute("UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ISNULL(Urun_Stok_Miktari,0) + ? WHERE urun_hizmet_id = ?", [$miktar, $hedefId]);
                    }

                    $db->commit();
                    echo json_encode(['success' => true, 'message' => count($rows) . ' transfer başarıyla kaydedildi.']);
                } catch (Throwable $ex) {
                    $db->rollBack();
                    throw $ex;
                }
                break;

            case 'excel_yedek':
                if (!$permissions['can_edit']) throw new Exception('Stok güncelleme yetkiniz yok.');
                echo json_encode(['success' => true, 'data' => $db->fetchAll("
                    SELECT urun_hizmet_id, urun_hizmet_kodu, urun_hizmet_adi,
                           ISNULL(Urun_Stok_Miktari,0) AS stok,
                           CASE WHEN urun_hizmet_durum = 1 THEN 1 ELSE 0 END AS aktif
                    FROM dbo.Urun_Hizmet
                    WHERE urun_hizmet_tip = 1
                    ORDER BY urun_hizmet_kodu
                ")]);
                break;

            case 'excel_onizle':
            case 'excel_uygula':
                if (!$permissions['can_edit']) throw new Exception('Stok güncelleme yetkiniz yok.');

                $satirlar = json_decode($_POST['rows'] ?? '[]', true);
                if (!is_array($satirlar) || empty($satirlar)) throw new Exception('Excel dosyasında satır bulunamadı.');
                $sifirla  = ($_POST['sifirla'] ?? '0') === '1';
                $pasifYap = ($_POST['pasif_yap'] ?? '0') === '1';

                $plan = excelStokPlani($db, $satirlar, $sifirla, $pasifYap);
                $degisen = array_values(array_filter($plan['degisiklikler'], fn($d) => $d['fark'] != 0 || $d['durum'] !== null));

                if ($action === 'excel_onizle') {
                    echo json_encode(['success' => true, 'data' => [
                        'degisiklikler' => $degisen,
                        'eslesen'       => count(array_filter($plan['degisiklikler'], fn($d) => str_starts_with($d['kaynak'], 'Excel'))),
                        'stok_degisen'  => count(array_filter($degisen, fn($d) => $d['fark'] != 0)),
                        'sifirlanan'    => count(array_filter($degisen, fn($d) => $d['kaynak'] === 'Sıfırlama')),
                        'aktiflesen'    => count(array_filter($degisen, fn($d) => $d['durum'] === 'aktif')),
                        'pasiflesen'    => count(array_filter($degisen, fn($d) => $d['durum'] === 'pasif')),
                        'eslesmeyen'    => $plan['eslesmeyen'],
                        'hatali'        => $plan['hatali'],
                    ]]);
                    break;
                }

                $simdi    = date('Y-m-d H:i:s');
                $aciklama = 'Excel Stok Sayımı' . ($sifirla ? ' (Sıfırlamalı)' : '');
                $islenen  = 0;
                $durumIdleri = ['aktif' => [], 'pasif' => []];

                $db->beginTransaction();
                try {
                    foreach ($degisen as $d) {
                        if ($d['durum'] !== null) $durumIdleri[$d['durum']][] = $d['urun_id'];
                        if ($d['fark'] == 0) continue;
                        $db->insert('dbo.Urun_Stok_Hareketleri', [
                            'Urun_id'            => $d['urun_id'],
                            'Islem_Tipi'         => $d['fark'] > 0 ? 'GIRIS' : 'CIKIS',
                            'Miktar'             => abs($d['fark']),
                            'Aciklama'           => $aciklama . ' - ' . ($d['kaynak'] === 'Sıfırlama' ? 'Excel\'de yok, sıfırlandı' : 'Sayım: ' . $d['yeni']),
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi'    => $simdi,
                            'Durum'              => 1,
                        ]);
                        $db->execute("UPDATE dbo.Urun_Hizmet SET Urun_Stok_Miktari = ? WHERE urun_hizmet_id = ?", [$d['yeni'], $d['urun_id']]);
                        $islenen++;
                    }

                    // Durum değişiklikleri toplu yazılır (MSSQL parametre sınırı için 1000'lik parçalar)
                    foreach ($durumIdleri as $durum => $idler) {
                        foreach (array_chunk($idler, 1000) as $parca) {
                            $db->execute(
                                "UPDATE dbo.Urun_Hizmet SET urun_hizmet_durum = ? WHERE urun_hizmet_id IN (" . implode(',', array_fill(0, count($parca), '?')) . ")",
                                array_merge([$durum === 'aktif' ? 1 : 0], $parca)
                            );
                        }
                    }
                    $db->commit();
                } catch (Throwable $ex) {
                    $db->rollBack();
                    throw $ex;
                }

                echo json_encode(['success' => true, 'message' => $islenen . ' ürünün stoğu güncellendi.'
                    . (count($durumIdleri['aktif']) ? ' Aktife alınan: ' . count($durumIdleri['aktif']) . '.' : '')
                    . (count($durumIdleri['pasif']) ? ' Pasife alınan: ' . count($durumIdleri['pasif']) . '.' : '')
                    . (count($plan['eslesmeyen']) ? ' Eşleşmeyen kod: ' . count($plan['eslesmeyen']) : '')]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
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

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .transfer-row { background: #f8f9fa; border-radius: 6px; padding: 12px; margin-bottom: 8px; border: 1px solid #dee2e6; position: relative; }
        .transfer-row .btn-remove-row { position: absolute; top: 8px; right: 8px; }
        .stok-badge { font-size: 11px; }
        #addRowBtn { border-style: dashed; }
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
                    <div class="col-sm-6 d-flex align-items-center gap-2">
                        <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        <?php if ($permissions['can_edit']): ?>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#excelStokModal">
                            <i class="bi bi-file-earmark-excel"></i> Excel ile Stok Güncelle
                        </button>
                        <?php endif; ?>
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

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-arrow-left-right"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Transfer</span>
                                <span class="info-box-number" id="stat-toplam">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-calendar-day"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün</span>
                                <span class="info-box-number" id="stat-bugun">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-boxes"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Taşınan Adet</span>
                                <span class="info-box-number" id="stat-toplam-miktar">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-boxes"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Taşınan Adet</span>
                                <span class="info-box-number" id="stat-bugun-miktar">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transfer Formu -->
                <?php if ($permissions['can_add']): ?>
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-arrow-left-right"></i> Yeni Stok Transferi</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#transferCard">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse show" id="transferCard">
                        <div id="transferRows"></div>

                        <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-1" id="addRowBtn">
                            <i class="bi bi-plus-lg"></i> Satır Ekle
                        </button>

                        <hr>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" id="clearFormBtn">
                                <i class="bi bi-x-circle"></i> Temizle
                            </button>
                            <button type="button" class="btn btn-primary" id="saveTransferBtn">
                                <i class="bi bi-save"></i> Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Geçmiş -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-clock-history"></i> Transfer Geçmişi</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtrele
                            </button>
                        </div>
                    </div>

                    <div class="collapse" id="filterCard">
                        <div class="card-body border-bottom bg-light">
                            <form id="filterForm">
                                <div class="row g-2">
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç</label>
                                        <input type="date" class="form-control form-control-sm" id="filter_start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş</label>
                                        <input type="date" class="form-control form-control-sm" id="filter_end_date">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Kaynak Ürün</label>
                                        <select class="form-select form-select-sm" id="filter_kaynak_id"></select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Hedef Ürün</label>
                                        <select class="form-select form-select-sm" id="filter_hedef_id"></select>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end gap-1">
                                        <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> Filtrele</button>
                                        <button type="button" class="btn btn-secondary btn-sm" id="clearFilters"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="sayimTable" class="table table-bordered table-striped table-hover table-sm">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Tarih</th>
                                        <th>Kaynak Ürün</th>
                                        <th>Hedef Ürün</th>
                                        <th>Miktar</th>
                                        <th>Açıklama</th>
                                        <th>Kaydeden</th>
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

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<?php if ($permissions['can_edit']): ?>
<!-- Excel ile Stok Güncelleme -->
<div class="modal fade" id="excelStokModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-excel text-success"></i> Excel ile Stok Güncelle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    Dosyada <strong>Stok Kodu</strong> ve <strong>Miktar</strong> kolonları olmalı. Eşleştirme ürünün stok koduna göre yapılır,
                    Excel'deki miktar ürünün yeni stoğu olarak yazılır, boş miktar 0 kabul edilir. Aynı kod birden fazla satırda varsa miktarlar toplanır.
                    Excel'de sayılan pasif ürünler aktife alınır.
                    <a href="#" id="excelSablonIndir" class="ms-1"><i class="bi bi-download"></i> Örnek şablon</a>
                </div>
                <div class="row g-3 align-items-end">
                    <div class="col-md-7">
                        <label class="form-label" for="excelStokDosya">Excel Dosyası</label>
                        <input type="file" class="form-control" id="excelStokDosya" accept=".xlsx,.xls,.csv">
                    </div>
                    <div class="col-md-5">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="excelStokSifirla" name="sifirla" value="1">
                            <label class="form-check-label" for="excelStokSifirla">Tüm stoğu sıfırla, yalnızca Excel'dekileri yükle</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="excelStokPasifYap" name="pasif_yap" value="1">
                            <label class="form-check-label" for="excelStokPasifYap">Tüm ürünleri pasif yap, yalnızca stoğu olanlar aktif kalsın</label>
                        </div>
                    </div>
                </div>
                <div class="alert alert-warning py-2 small mt-3 d-none" id="excelSifirlaUyari">
                    <i class="bi bi-exclamation-triangle"></i> Excel'de bulunmayan tüm ürünlerin stoğu <strong>0</strong> yapılacak.
                </div>
                <div class="alert alert-warning py-2 small mt-2 d-none" id="excelPasifUyari">
                    <i class="bi bi-exclamation-triangle"></i> İşlem sonunda stoğu <strong>0</strong> olan tüm ürünler pasife, stoğu olanlar aktife alınacak.
                </div>

                <div id="excelOnizleme" class="mt-3 d-none">
                    <div class="d-flex flex-wrap gap-2 mb-2" id="excelOzet"></div>
                    <div id="excelUyarilar"></div>
                    <table id="excelOnizlemeTable" class="table table-bordered table-striped table-sm w-100">
                        <thead>
                            <tr>
                                <th>Stok Kodu</th>
                                <th>Ürün</th>
                                <th>Mevcut</th>
                                <th>Yeni</th>
                                <th>Fark</th>
                                <th>Kaynak</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <span class="small text-muted me-auto"><i class="bi bi-shield-check"></i> Güncellemeden önce mevcut stok ve durumların yedeği otomatik indirilir.</span>
                <button type="button" class="btn btn-outline-primary" id="excelYedekIndir">
                    <i class="bi bi-download"></i> Yedek İndir
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-success" id="excelStokUygula" disabled>
                    <i class="bi bi-check2-circle"></i> Stokları Güncelle
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Satır Şablonu -->
<template id="rowTemplate">
    <div class="transfer-row">
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Satırı Sil">
            <i class="bi bi-trash"></i>
        </button>
        <div class="row g-2">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kaynak Ürün <span class="text-danger">*</span></label>
                <select class="form-select form-select-sm select-kaynak" data-placeholder="Kaynak ürün seçin...">
                    <option value=""></option>
                </select>
                <div class="mt-1"><span class="badge bg-secondary stok-badge kaynak-stok-info"></span></div>
            </div>
            <div class="col-md-1 d-flex align-items-center justify-content-center pt-3">
                <i class="bi bi-arrow-right fs-4 text-primary"></i>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Hedef Ürün <span class="text-danger">*</span></label>
                <select class="form-select form-select-sm select-hedef" data-placeholder="Hedef ürün seçin...">
                    <option value=""></option>
                </select>
                <div class="mt-1"><span class="badge bg-secondary stok-badge hedef-stok-info"></span></div>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Miktar <span class="text-danger">*</span></label>
                <input type="number" class="form-control form-control-sm input-miktar" min="0.01" step="0.01" placeholder="0">
            </div>
            <div class="col-md-12">
                <label class="form-label">Açıklama</label>
                <input type="text" class="form-control form-control-sm input-aciklama" placeholder="İsteğe bağlı...">
            </div>
        </div>
    </div>
</template>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
let table;
let currentFilters = {};

function loadStats() {
    $.post('', { action: 'stats' }, function(r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-bugun').text(r.data.bugun);
        $('#stat-toplam-miktar').text(new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2 }).format(r.data.toplamMiktar));
        $('#stat-bugun-miktar').text(new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2 }).format(r.data.bugunMiktar));
    });
}

function initDataTable() {
    table = $('#sayimTable').DataTable({
        processing: true,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: function(data, callback) {
            $.ajax({
                url: '', type: 'POST',
                data: { action: 'list', ...currentFilters },
                success: function(r) { callback({ data: r.success ? r.data : [] }); },
                error:   function()  { callback({ data: [] }); }
            });
        },
        columns: [
            { data: 'Stok_Hareket_id', width: '50px' },
            {
                data: 'OlusturmaTarihi',
                render: function(d) {
                    if (!d) return '-';
                    try {
                        const dt = new Date(d.replace(' ', 'T'));
                        return isNaN(dt) ? d : dt.toLocaleDateString('tr-TR', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' });
                    } catch(e) { return d; }
                }
            },
            { data: 'kaynak_urun_adi', render: function(d, t, row) { return `<span class="text-danger"><i class="bi bi-box-arrow-up me-1"></i></span>[${row.kaynak_urun_kodu || ''}] ${d || '-'}`; } },
            { data: 'hedef_urun_adi',  render: function(d, t, row) { return `<span class="text-success"><i class="bi bi-box-arrow-in-down me-1"></i></span>[${row.hedef_urun_kodu || ''}] ${d || '-'}`; } },
            {
                data: 'Miktar',
                render: function(d, t) {
                    if (t === 'display') return `<span class="badge bg-primary">${new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2 }).format(d || 0)}</span>`;
                    return d;
                }
            },
            { data: 'Aciklama', defaultContent: '-' },
            { data: 'OlusturanAdi', defaultContent: '-' }
        ],
        order: [[0, 'desc']],
        pageLength: 25
    });
}

function urunSelectAjax() {
    return {
        url: '', type: 'POST', delay: 250,
        data: function(p) { return { action: 'get_urunler_search', q: p.term || '' }; },
        processResults: function(r) { return { results: r.success ? r.data.map(u => ({ id: u.id, text: u.text, stok: u.stok })) : [] }; },
        cache: true
    };
}

function initUrunSelect(el) {
    const $sel = $(el);
    $sel.select2({ theme: 'bootstrap-5', placeholder: $sel.data('placeholder') || 'Ürün seçin...', allowClear: true, minimumInputLength: 0, ajax: urunSelectAjax() });

    $sel.on('select2:select', function(e) {
        const data     = e.params.data;
        const row      = $sel.closest('.transfer-row');
        const isKaynak = $sel.hasClass('select-kaynak');
        const $badge   = isKaynak ? row.find('.kaynak-stok-info') : row.find('.hedef-stok-info');
        if (data && data.stok !== undefined) {
            $badge.text('Mevcut stok: ' + new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2 }).format(data.stok))
                  .removeClass('bg-secondary bg-danger bg-success')
                  .addClass(data.stok > 0 ? 'bg-success' : 'bg-danger');
        }
    });
    $sel.on('select2:clear', function() {
        const row = $sel.closest('.transfer-row');
        ($sel.hasClass('select-kaynak') ? row.find('.kaynak-stok-info') : row.find('.hedef-stok-info')).text('').removeClass('bg-success bg-danger').addClass('bg-secondary');
    });
}

function addRow() {
    const clone = document.getElementById('rowTemplate').content.cloneNode(true);
    const $div  = $(clone).find('.transfer-row');

    $div.find('.btn-remove-row').on('click', function() {
        $(this).closest('.transfer-row').remove();
        if ($('#transferRows .transfer-row').length === 0) addRow();
    });

    $('#transferRows').append($div);

    const $last = $('#transferRows .transfer-row').last();
    initUrunSelect($last.find('.select-kaynak')[0]);
    initUrunSelect($last.find('.select-hedef')[0]);
}

function initFilterSelects() {
    ['#filter_kaynak_id', '#filter_hedef_id'].forEach(function(sel) {
        $(sel).select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true, minimumInputLength: 0, ajax: urunSelectAjax() });
    });
}

// ---- Excel ile stok güncelleme ----
let excelSatirlar = [];
let excelTable    = null;
const sayiFmt = v => new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 4 }).format(v || 0);
const esc     = s => $('<div>').text(s ?? '').html();

function basliginiBul(hucre) {
    const h = String(hucre ?? '').toLocaleLowerCase('tr-TR').replace(/[\s_.-]/g, '');
    if (['stokkodu', 'kod', 'ürünkodu', 'urunkodu', 'stokkod'].includes(h)) return 'kod';
    if (['miktar', 'stok', 'adet', 'sayım', 'sayim', 'stokmiktarı', 'stokmiktari', 'sayılanmiktar'].includes(h)) return 'miktar';
    if (h === 'durum') return 'durum';
    if (['ürünid', 'urunid', 'id'].includes(h)) return 'id';
    return null;
}

function excelOku(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const wb    = XLSX.read(new Uint8Array(e.target.result), { type: 'array', raw: true });
                const sheet = wb.Sheets[wb.SheetNames[0]];
                const json  = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: true, defval: '' });
                if (!json.length) return resolve([]);

                let kodIdx = -1, miktarIdx = -1, durumIdx = -1, idIdx = -1;
                json[0].forEach((h, i) => {
                    const t = basliginiBul(h);
                    if (t === 'kod' && kodIdx < 0) kodIdx = i;
                    if (t === 'miktar' && miktarIdx < 0) miktarIdx = i;
                    if (t === 'durum' && durumIdx < 0) durumIdx = i;
                    if (t === 'id' && idIdx < 0) idIdx = i;
                });
                const baslikVar = kodIdx >= 0 && miktarIdx >= 0;
                if (!baslikVar) { kodIdx = 0; miktarIdx = 1; durumIdx = -1; idIdx = -1; }

                resolve((baslikVar ? json.slice(1) : json).map(r => ({
                    kod:    String(r[kodIdx] ?? '').trim(),
                    miktar: String(r[miktarIdx] ?? '').trim(),
                    durum:  durumIdx >= 0 ? String(r[durumIdx] ?? '').trim() : '',
                    id:     idIdx >= 0 ? (parseInt(r[idIdx]) || 0) : 0
                })));
            } catch (err) { reject(err); }
        };
        reader.onerror = reject;
        reader.readAsArrayBuffer(file);
    });
}

function excelSecenekler() {
    return {
        rows:      JSON.stringify(excelSatirlar),
        sifirla:   $('#excelStokSifirla').is(':checked') ? 1 : 0,
        pasif_yap: $('#excelStokPasifYap').is(':checked') ? 1 : 0
    };
}

function excelOnizle() {
    $('#excelStokUygula').prop('disabled', true);
    if (!excelSatirlar.length) { $('#excelOnizleme').addClass('d-none'); return; }

    $.post('', { action: 'excel_onizle', ...excelSecenekler() }, function(r) {
        if (!r.success) { showToast(r.message, 'danger'); return; }
        const d = r.data;

        $('#excelOzet').html(`
            <span class="badge text-bg-primary">Excel satırı: ${excelSatirlar.length}</span>
            <span class="badge text-bg-success">Eşleşen ürün: ${d.eslesen}</span>
            <span class="badge text-bg-info">Stoğu değişecek: ${d.stok_degisen}</span>
            ${d.sifirlanan ? `<span class="badge text-bg-warning">Sıfırlanacak: ${d.sifirlanan}</span>` : ''}
            ${d.aktiflesen ? `<span class="badge text-bg-success">Aktife alınacak: ${d.aktiflesen}</span>` : ''}
            ${d.pasiflesen ? `<span class="badge text-bg-secondary">Pasife alınacak: ${d.pasiflesen}</span>` : ''}
            ${d.eslesmeyen.length ? `<span class="badge text-bg-danger">Eşleşmeyen kod: ${d.eslesmeyen.length}</span>` : ''}
            ${d.hatali.length ? `<span class="badge text-bg-danger">Hatalı satır: ${d.hatali.length}</span>` : ''}
        `);

        let uyari = '';
        if (d.eslesmeyen.length) {
            uyari += `<div class="alert alert-danger py-2 small"><strong>Eşleşmeyen kodlar (işlenmeyecek):</strong> `
                   + d.eslesmeyen.map(e => `${esc(e.kod)} <span class="text-muted">(${esc(e.neden)})</span>`).join(', ') + '</div>';
        }
        if (d.hatali.length) {
            uyari += `<div class="alert alert-danger py-2 small"><strong>Hatalı satırlar (işlenmeyecek):</strong> `
                   + d.hatali.map(e => `Satır ${e.satir} ${esc(e.kod)} <span class="text-muted">(${esc(e.neden)})</span>`).join(', ') + '</div>';
        }
        $('#excelUyarilar').html(uyari);

        if (excelTable) { excelTable.destroy(); $('#excelOnizlemeTable tbody').empty(); }
        excelTable = $('#excelOnizlemeTable').DataTable({
            data: d.degisiklikler,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            pageLength: 10,
            order: [[4, 'asc']],
            columns: [
                { data: 'kod', render: v => esc(v) },
                { data: 'adi', render: v => esc(v) },
                { data: 'mevcut', className: 'text-end', render: (v, t) => t === 'display' ? sayiFmt(v) : v },
                { data: 'yeni',   className: 'text-end', render: (v, t) => t === 'display' ? `<strong>${sayiFmt(v)}</strong>` : v },
                { data: 'fark',   className: 'text-end', render: (v, t) => t === 'display'
                    ? `<span class="badge ${v > 0 ? 'bg-success' : (v < 0 ? 'bg-danger' : 'bg-secondary')}">${v > 0 ? '+' : ''}${sayiFmt(v)}</span>` : v },
                { data: 'kaynak', render: (v, t, row) => {
                    let h = v === 'Sıfırlama' ? `<span class="badge bg-warning text-dark">${esc(v)}</span>` : esc(v);
                    if (row.durum === 'aktif') h += ' <span class="badge bg-success">Aktife alınacak</span>';
                    if (row.durum === 'pasif') h += ' <span class="badge bg-secondary">Pasife alınacak</span>';
                    return h;
                } }
            ]
        });

        $('#excelOnizleme').removeClass('d-none');
        $('#excelStokUygula').prop('disabled', d.degisiklikler.length === 0);
        if (!d.degisiklikler.length) showToast('Değişecek ürün yok.', 'warning');
    }).fail(() => showToast('Sunucu hatası oluştu.', 'danger'));
}

// Mevcut tüm ürün stok/durumlarını xlsx olarak indirir. Dosya aynı modalla geri yüklenebilir (Stok Kodu + Miktar kolonları).
function stokYedekIndir() {
    return new Promise((resolve, reject) => {
        $.post('', { action: 'excel_yedek' }, function(r) {
            if (!r.success) return reject(new Error(r.message));
            const satirlar = r.data.map(u => ({
                'Stok Kodu': u.urun_hizmet_kodu,
                'Miktar':    parseFloat(u.stok),
                'Ürün Adı':  u.urun_hizmet_adi,
                'Durum':     parseInt(u.aktif) === 1 ? 'Aktif' : 'Pasif',
                'Ürün ID':   parseInt(u.urun_hizmet_id)
            }));
            const ws = XLSX.utils.json_to_sheet(satirlar);
            ws['!cols'] = [{ wch: 22 }, { wch: 12 }, { wch: 50 }, { wch: 8 }, { wch: 10 }];
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Stok Yedek');
            const z = new Date(), p = n => String(n).padStart(2, '0');
            XLSX.writeFile(wb, `stok-yedek_${z.getFullYear()}${p(z.getMonth() + 1)}${p(z.getDate())}_${p(z.getHours())}${p(z.getMinutes())}${p(z.getSeconds())}.xlsx`);
            resolve(satirlar.length);
        }).fail(() => reject(new Error('Yedek alınamadı, sunucu hatası.')));
    });
}

function excelModalSifirla() {
    excelSatirlar = [];
    $('#excelStokDosya').val('');
    $('#excelStokSifirla, #excelStokPasifYap').prop('checked', false);
    $('#excelSifirlaUyari, #excelPasifUyari, #excelOnizleme').addClass('d-none');
    $('#excelStokUygula').prop('disabled', true);
}

$(document).ready(function() {
    $('#excelStokModal').on('hidden.bs.modal', excelModalSifirla);

    $('#excelStokDosya').on('change', async function() {
        const file = this.files[0];
        excelSatirlar = [];
        if (!file) { excelOnizle(); return; }
        try {
            excelSatirlar = (await excelOku(file)).filter(r => r.kod !== '' || r.miktar !== '');
            if (!excelSatirlar.length) showToast('Dosyada okunacak satır bulunamadı.', 'warning');
        } catch (err) {
            showToast('Dosya okunamadı: ' + err.message, 'danger');
        }
        excelOnizle();
    });

    $('#excelStokSifirla').on('change', function() {
        $('#excelSifirlaUyari').toggleClass('d-none', !this.checked);
        excelOnizle();
    });

    $('#excelStokPasifYap').on('change', function() {
        $('#excelPasifUyari').toggleClass('d-none', !this.checked);
        excelOnizle();
    });

    $('#excelSablonIndir').on('click', function(e) {
        e.preventDefault();
        const ws = XLSX.utils.aoa_to_sheet([['Stok Kodu', 'Miktar']]);
        ws['!cols'] = [{ wch: 20 }, { wch: 12 }];
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Stok Sayım');
        XLSX.writeFile(wb, 'stok-sayim-sablonu.xlsx');
    });

    $('#excelYedekIndir').on('click', async function() {
        const $btn = $(this).prop('disabled', true);
        try {
            const adet = await stokYedekIndir();
            showToast(adet + ' ürünün yedeği indirildi.', 'success');
        } catch (err) {
            showToast(err.message, 'danger');
        }
        $btn.prop('disabled', false);
    });

    $('#excelStokUygula').on('click', async function() {
        const secenek = excelSecenekler();
        let mesaj = secenek.sifirla
            ? 'Excel\'de olmayan TÜM ürünlerin stoğu 0 yapılacak ve Excel\'dekiler güncellenecek.'
            : 'Excel\'deki ürünlerin stokları güncellenecek.';
        if (secenek.pasif_yap) mesaj += '\nStoğu 0 olan TÜM ürünler pasife alınacak.';
        if (!confirm(mesaj + '\n\nİşlemden önce mevcut stokların yedeği bilgisayarınıza indirilecek.\nEmin misiniz?')) return;

        const $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Yedek alınıyor...');
        try {
            const adet = await stokYedekIndir();
            showToast(adet + ' ürünün yedeği indirildi.', 'success');
        } catch (err) {
            showToast(err.message + ' Güncelleme yapılmadı.', 'danger');
            $btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i> Stokları Güncelle');
            return;
        }

        $btn.html('<i class="bi bi-hourglass-split"></i> Güncelleniyor...');
        $.post('', { action: 'excel_uygula', ...secenek }, function(r) {
            if (r.success) {
                showToast(r.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('excelStokModal')).hide();
            } else {
                showToast(r.message, 'danger');
                $btn.prop('disabled', false);
            }
        }).fail(function() {
            showToast('Sunucu hatası oluştu.', 'danger');
            $btn.prop('disabled', false);
        }).always(function() {
            $btn.html('<i class="bi bi-check2-circle"></i> Stokları Güncelle');
        });
    });
});

$(document).ready(function() {
    loadStats();
    initDataTable();
    initFilterSelects();
    addRow();

    $('#addRowBtn').on('click', addRow);

    $('#clearFormBtn').on('click', function() {
        $('#transferRows').empty();
        addRow();
    });

    $('#saveTransferBtn').on('click', function() {
        const rows  = [];
        let   valid = true;

        $('#transferRows .transfer-row').each(function() {
            const kaynakId = $(this).find('.select-kaynak').val();
            const hedefId  = $(this).find('.select-hedef').val();
            const miktar   = parseFloat($(this).find('.input-miktar').val());
            const aciklama = $(this).find('.input-aciklama').val();

            if (!kaynakId || !hedefId || !(miktar > 0)) { valid = false; return false; }
            rows.push({ kaynak_urun_id: kaynakId, hedef_urun_id: hedefId, miktar, aciklama });
        });

        if (!valid) { showToast('Tüm satırlarda kaynak ürün, hedef ürün ve miktar zorunludur.', 'warning'); return; }
        if (rows.length === 0) { showToast('En az bir satır ekleyin.', 'warning'); return; }

        const $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Kaydediliyor...');
        $.ajax({
            url: '', type: 'POST',
            data: { action: 'transfer', rows: JSON.stringify(rows) },
            success: function(r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    $('#transferRows').empty();
                    addRow();
                    loadStats();
                    table.ajax.reload();
                } else {
                    showToast(r.message, 'danger');
                }
            },
            error: function() { showToast('Sunucu hatası oluştu.', 'danger'); },
            complete: function() { $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Kaydet'); }
        });
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentFilters = {
            start_date:     $('#filter_start_date').val(),
            end_date:       $('#filter_end_date').val(),
            kaynak_urun_id: $('#filter_kaynak_id').val(),
            hedef_urun_id:  $('#filter_hedef_id').val(),
        };
        Object.keys(currentFilters).forEach(k => { if (!currentFilters[k]) delete currentFilters[k]; });
        table.ajax.reload();
    });

    $('#clearFilters').on('click', function() {
        $('#filterForm')[0].reset();
        $('#filter_kaynak_id, #filter_hedef_id').val(null).trigger('change.select2');
        currentFilters = {};
        table.ajax.reload();
    });
});
</script>
</body>
</html>
