<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/PazaryeriFabrika.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok.');
}

$pageInfo   = $db->fetchOne("SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1", ['%' . $currentPageFile]);
$pageTitle  = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri Siparişler';
$menuAdi    = $pageInfo['menu_adi'] ?? 'Pazaryeri';
$siteTitle  = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC")['site_ayarlari_site_title'] ?? 'Portal';

// Pazaryeri kanalları — sabit liste yok; tanim_Pazaryerleri + aktif kanallardan üretilir
$kanallar = PazaryeriFabrika::kanallar();

/** Kanal için API nesnesi; kurulamazsa sebebiyle istisna fırlatır */
function pazaryeriApi(int $kanalId, $userId): PazaryeriApi
{
    $api = PazaryeriFabrika::olusturKanaldan($kanalId, $userId);
    if (!$api || !$api->hazirMi()) {
        throw new Exception('Pazaryeri bağlantısı kurulamadı: ' . ($api ? $api->getHata() : PazaryeriFabrika::sonHata()));
    }
    return $api;
}

// ── AJAX ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // Özet kutular — tablo sorgusundan ayrı, tek tablodan sayım
            case 'stats':
                $kId = (int)($_POST['kanal_id'] ?? 0);
                $w   = $kId ? 'AND kanal_id = ?' : '';
                $p   = $kId ? [$kId] : [];
                $r = $db->fetchOne("
                    SELECT COUNT(*) AS toplam,
                           SUM(CASE WHEN PazaryeriSiparisleri_DurumKod = 'yeni' THEN 1 ELSE 0 END) AS yeni,
                           SUM(CASE WHEN PazaryeriSiparisleri_DurumKod IN ('hazirlaniyor','kargoda') THEN 1 ELSE 0 END) AS kargoda,
                           SUM(CASE WHEN PazaryeriSiparisleri_DurumKod = 'teslim' THEN 1 ELSE 0 END) AS teslim
                    FROM PazaryeriSiparisleri WHERE Durum = 1 $w
                ", $p);
                echo json_encode(['success' => true, 'data' => [
                    'toplam'  => (int)($r['toplam']  ?? 0),
                    'yeni'    => (int)($r['yeni']    ?? 0),
                    'kargoda' => (int)($r['kargoda'] ?? 0),
                    'teslim'  => (int)($r['teslim']  ?? 0),
                ]]);
                break;

            // Server-side liste — 1) ucuz CTE: filtre + sıralama + sayfa ID'leri, 2) dış SELECT: detay
            case 'list':
                $where  = ['s.Durum = 1'];
                $params = [];

                $kId = (int)($_POST['filtre_kanal'] ?? 0);
                if ($kId) { $where[] = 's.kanal_id = ?'; $params[] = $kId; }

                $durum = trim($_POST['filtre_durum'] ?? '');
                if ($durum !== '') { $where[] = 's.PazaryeriSiparisleri_HamDurum = ?'; $params[] = $durum; }

                $filtreFatura = trim($_POST['filtre_fatura'] ?? '');
                if ($filtreFatura === '1')     { $where[] = 's.PazaryeriSiparisleri_Fatura_id IS NOT NULL'; }
                elseif ($filtreFatura === '0') { $where[] = 's.PazaryeriSiparisleri_Fatura_id IS NULL'; }

                $tarihBas = trim($_POST['filtre_tarih_bas'] ?? '');
                $tarihBit = trim($_POST['filtre_tarih_bit'] ?? '');
                if ($tarihBas) { $where[] = 's.PazaryeriSiparisleri_SiparisTarihi >= ?'; $params[] = $tarihBas . ' 00:00:00'; }
                if ($tarihBit) { $where[] = 's.PazaryeriSiparisleri_SiparisTarihi <= ?'; $params[] = $tarihBit . ' 23:59:59'; }

                $arama = trim($_POST['filtre_arama'] ?? '');
                if ($arama !== '') {
                    $where[] = '(s.PazaryeriSiparisleri_SiparisNo LIKE ? OR s.PazaryeriSiparisleri_MusteriAdi LIKE ? OR s.PazaryeriSiparisleri_KargoTakipNo LIKE ?)';
                    array_push($params, "%$arama%", "%$arama%", "%$arama%");
                }
                $wSql = 'WHERE ' . implode(' AND ', $where);

                // Sıralama: kolon index → kolon adı whitelist; yön normalize
                $siralama = [
                    0 => 's.Pazaryerleri_id',
                    1 => 's.PazaryeriSiparisleri_SiparisNo',
                    2 => 's.PazaryeriSiparisleri_HamDurum',
                    3 => 's.PazaryeriSiparisleri_Fatura_id',
                    4 => 's.PazaryeriSiparisleri_MusteriAdi',
                    5 => 's.PazaryeriSiparisleri_Il',
                    6 => 's.PazaryeriSiparisleri_ToplamTutar',
                    7 => 's.PazaryeriSiparisleri_KargoFirmasi',
                    8 => 's.PazaryeriSiparisleri_SiparisTarihi',
                ];
                $kolon = $siralama[(int)($_POST['order'][0]['column'] ?? 8)] ?? $siralama[8];
                $yon   = strtoupper($_POST['order'][0]['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                $length = $length > 0 ? min($length, 500) : 25;

                $toplam   = (int)$db->fetchOne("SELECT COUNT(*) AS n FROM PazaryeriSiparisleri WHERE Durum = 1")['n'];
                $filtreli = (int)$db->fetchOne("SELECT COUNT(*) AS n FROM PazaryeriSiparisleri s $wSql", $params)['n'];

                $rows = $db->fetchAll("
                    WITH sayfa AS (
                        SELECT s.PazaryeriSiparisleri_id
                        FROM PazaryeriSiparisleri s
                        $wSql
                        ORDER BY $kolon $yon, s.PazaryeriSiparisleri_id DESC
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT
                        s.PazaryeriSiparisleri_id                 AS ps_id,
                        s.kanal_id                                AS ps_kanal_id,
                        ek.Kanal_Adi,
                        p.Pazaryerleri_Ad                         AS Entegrasyon_Adi,
                        p.Pazaryerleri_Renk                       AS Entegrasyon_Renk,
                        s.PazaryeriSiparisleri_SiparisNo          AS ps_siparis_no,
                        s.PazaryeriSiparisleri_PaketNo            AS ps_kargo_paket_id,
                        s.PazaryeriSiparisleri_HamDurum           AS ps_durum,
                        s.PazaryeriSiparisleri_ToplamTutar        AS ps_toplam_tutar,
                        s.PazaryeriSiparisleri_MusteriAdi         AS ps_musteri_adi,
                        s.PazaryeriSiparisleri_Il                 AS ps_musteri_sehir,
                        s.PazaryeriSiparisleri_KargoFirmasi       AS ps_kargo_firmasi,
                        s.PazaryeriSiparisleri_KargoTakipNo       AS ps_kargo_takip_no,
                        CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS ps_siparis_tarihi,
                        s.PazaryeriSiparisleri_Fatura_id          AS ps_fatura_id
                    FROM sayfa
                    INNER JOIN PazaryeriSiparisleri s   ON s.PazaryeriSiparisleri_id = sayfa.PazaryeriSiparisleri_id
                    INNER JOIN EntegrasyonKanallari ek  ON ek.Kanal_Id = s.kanal_id
                    INNER JOIN tanim_Pazaryerleri p     ON p.Pazaryerleri_id = s.Pazaryerleri_id
                    ORDER BY $kolon $yon, s.PazaryeriSiparisleri_id DESC
                ", array_merge($params, [$start, $length]));

                echo json_encode([
                    'draw'            => (int)($_POST['draw'] ?? 0),
                    'recordsTotal'    => $toplam,
                    'recordsFiltered' => $filtreli,
                    'data'            => $rows,
                ]);
                break;

            case 'detay':
                $id  = (int)($_POST['ps_id'] ?? 0);
                $row = $db->fetchOne("
                    SELECT
                        s.PazaryeriSiparisleri_id                 AS ps_id,
                        s.kanal_id                                AS ps_kanal_id,
                        s.PazaryeriSiparisleri_SiparisNo          AS ps_siparis_no,
                        s.PazaryeriSiparisleri_PaketNo            AS ps_kargo_paket_id,
                        s.PazaryeriSiparisleri_HamDurum           AS ps_durum,
                        s.PazaryeriSiparisleri_ToplamTutar        AS ps_toplam_tutar,
                        s.PazaryeriSiparisleri_MusteriAdi         AS ps_musteri_adi,
                        s.PazaryeriSiparisleri_Il                 AS ps_musteri_sehir,
                        s.PazaryeriSiparisleri_TeslimatAdresi     AS ps_teslimat_adresi,
                        s.PazaryeriSiparisleri_KargoFirmasi       AS ps_kargo_firmasi,
                        s.PazaryeriSiparisleri_KargoTakipNo       AS ps_kargo_takip_no,
                        CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_KargoyaVerilme, 120) AS ps_kargoya_verilme_tarihi,
                        s.PazaryeriSiparisleri_Fatura_id          AS ps_fatura_id,
                        s.PazaryeriSiparisleri_Cari_id            AS ps_cari_id,
                        s.PazaryeriSiparisleri_HamVeri            AS ps_ham_veri,
                        CONVERT(VARCHAR(19), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS siparis_tarihi_str,
                        ek.Kanal_Adi,
                        p.Pazaryerleri_Ad   AS Entegrasyon_Adi,
                        p.Pazaryerleri_Renk AS Entegrasyon_Renk,
                        p.Pazaryerleri_Yetenekler,
                        fg.FaturalarGiden_FaturaNo, fg.FaturalarGiden_GibDurum, fg.FaturalarGiden_UUID
                    FROM PazaryeriSiparisleri s
                    INNER JOIN EntegrasyonKanallari ek ON ek.Kanal_Id = s.kanal_id
                    INNER JOIN tanim_Pazaryerleri p    ON p.Pazaryerleri_id = s.Pazaryerleri_id
                    LEFT JOIN FaturalarGiden fg        ON fg.FaturalarGiden_id = s.PazaryeriSiparisleri_Fatura_id
                    WHERE s.PazaryeriSiparisleri_id = ?
                ", [$id]);

                if ($row) {
                    // Kalemler normalize tablodan — arayüz ps_urunler JSON biçimini bekler
                    $kalemler = $db->fetchAll("
                        SELECT PazaryeriSiparisKalemleri_UrunAdi AS adi, PazaryeriSiparisKalemleri_Barkod AS barkod,
                               PazaryeriSiparisKalemleri_Miktar AS miktar, PazaryeriSiparisKalemleri_BirimFiyat AS fiyat,
                               PazaryeriSiparisKalemleri_HamDurum AS durum
                        FROM PazaryeriSiparisKalemleri
                        WHERE PazaryeriSiparisleri_id = ? AND Durum = 1
                        ORDER BY PazaryeriSiparisKalemleri_id
                    ", [$id]);
                    $row['ps_urunler'] = json_encode($kalemler, JSON_UNESCAPED_UNICODE);
                    $row['yetenekler'] = json_decode($row['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: [];
                    unset($row['Pazaryerleri_Yetenekler']);
                }
                echo json_encode(['success' => (bool)$row, 'data' => $row]);
                break;

            case 'kargo_etiketi':
                $psId = (int)($_POST['ps_id'] ?? 0);
                if (!$psId) throw new Exception('Sipariş ID boş.');

                $sip = $db->fetchOne(
                    "SELECT kanal_id, PazaryeriSiparisleri_KargoTakipNo AS takip, PazaryeriSiparisleri_HamDurum AS durum
                     FROM PazaryeriSiparisleri WHERE PazaryeriSiparisleri_id = ?", [$psId]);
                if (!$sip) throw new Exception('Sipariş bulunamadı.');
                // Ortak etiket kargo takip numarasıyla çalışır (paket ID ile değil)
                if (empty($sip['takip'])) throw new Exception('Kargo takip numarası bulunamadı. Siparişleri çekin.');
                if (!in_array($sip['durum'], ['Picking', 'Invoiced'], true)) {
                    throw new Exception('Kargo etiketi sadece Picking veya Invoiced statüsündeki paketler için oluşturulabilir.');
                }

                $api = pazaryeriApi((int)$sip['kanal_id'], $user['kullanici_id']);
                if (!$api->yetenek('kargoEtiketi')) throw new Exception($api->getPazaryeriAdi() . ' kargo etiketi desteklemiyor.');

                // Önce mevcut etiketi dene, yoksa talep oluşturup tekrar al
                $etiket = $api->kargoEtiketiGetir($sip['takip']);
                $talep  = null;
                if (!$etiket['basarili']) {
                    $talep = $api->kargoEtiketiOlustur($sip['takip']);
                    if ($talep['basarili']) {
                        $etiket = $api->kargoEtiketiGetir($sip['takip']);
                    }
                }

                // Ortak etiket yetkisi yoksa (COMMON_LABEL_NOT_ALLOWED) → panel URL döndür
                if (!$etiket['basarili']) {
                    $sebep = ($talep['hata'] ?? '') ?: ($etiket['hata'] ?? '');
                    echo json_encode([
                        'success'   => false,
                        'panel_url' => 'https://partner.trendyol.com/package/delivery-packages',
                        'error'     => 'Ortak etiket alınamadı' . ($sebep ? " ({$sebep})" : '') . ' — Trendyol paneline yönlendiriliyorsunuz.',
                    ]);
                    break;
                }

                echo json_encode(['success' => true, 'zpl' => $etiket['zpl'], 'error' => '']);
                break;

            case 'fatura_hazirla':
                $psId = (int)($_POST['ps_id'] ?? 0);
                if (!$psId) throw new Exception('Sipariş ID boş.');

                $sip = $db->fetchOne("
                    SELECT PazaryeriSiparisleri_id AS ps_id, PazaryeriSiparisleri_Cari_id AS ps_cari_id,
                           PazaryeriSiparisleri_HamVeri AS ps_ham_veri
                    FROM PazaryeriSiparisleri WHERE PazaryeriSiparisleri_id = ?
                ", [$psId]);
                if (!$sip) throw new Exception('Sipariş bulunamadı.');

                $ham = json_decode($sip['ps_ham_veri'] ?? '{}', true);
                $fatAdres = $ham['invoiceAddress'] ?? $ham['shipmentAddress'] ?? [];

                // VKN / TCKN belirle
                $taxNo  = trim($ham['taxNumber'] ?? '');
                $tckn   = trim($ham['identityNumber'] ?? '');
                $kurumsal = false;
                $anonim   = false; // 11111111111 durumu

                if ($taxNo && $taxNo !== 'YOK' && preg_match('/^\d{10}$/', $taxNo)) {
                    $vergiNo  = $taxNo;          // Kurumsal — VKN ile ara
                    $kurumsal = true;
                } elseif (preg_match('/^\d{11}$/', $tckn) && $tckn !== '11111111111') {
                    $vergiNo = $tckn;            // Gerçek TCKN — kişiyle eşleştir
                } elseif ($tckn === '11111111111') {
                    $vergiNo = '11111111111';    // Anonim bireysel müşteri — her sipariş için ayrı Cari
                    $anonim  = true;
                } else {
                    $vergiNo = preg_replace('/\D/', '', $tckn ?: $taxNo);
                }

                $ad     = mb_strtoupper(trim(($fatAdres['firstName'] ?? '') . ' ' . ($fatAdres['lastName'] ?? '')), 'UTF-8');
                $sirket = mb_strtoupper(trim($fatAdres['company'] ?? ''), 'UTF-8');
                $unvan  = ($kurumsal && $sirket) ? $sirket : $ad;

                // ─── Cari Bul ─── (bağlı cari varsa doğrula — yanlış kişi atanmış olabilir)
                $cariId = null;

                if ($sip['ps_cari_id'] && $vergiNo) {
                    $mevcut = $db->fetchOne("SELECT cari_id, cari_vergi_no, cari_adi FROM Cari WHERE cari_id = ?", [(int)$sip['ps_cari_id']]);
                    if ($mevcut) {
                        $vergiEsles = ($mevcut['cari_vergi_no'] === $vergiNo);
                        $adEsles    = (mb_strtoupper($mevcut['cari_adi'], 'UTF-8') === $ad);
                        if ($anonim ? ($vergiEsles && $adEsles) : $vergiEsles) {
                            $cariId = (int)$mevcut['cari_id'];
                        }
                    }
                }

                if (!$cariId && $vergiNo) {
                    $mevcut = $anonim
                        ? $db->fetchOne("SELECT cari_id FROM Cari WHERE cari_vergi_no = ? AND cari_adi = ?", [$vergiNo, $ad])
                        : $db->fetchOne("SELECT cari_id FROM Cari WHERE cari_vergi_no = ?", [$vergiNo]);
                    if ($mevcut) $cariId = (int)$mevcut['cari_id'];
                }

                if (!$cariId) {
                    $ulke   = $db->fetchOne("SELECT TOP 1 UlkeId FROM Adres_Ulkeler WHERE UlkeAdi LIKE '%Türkiye%' OR UlkeAdi LIKE '%Turkey%'");
                    $ulkeId = $ulke ? (int)$ulke['UlkeId'] : null;

                    $sehirId = null; $ilceId = null;
                    $sehirAdi = trim($fatAdres['city'] ?? '');
                    $ilceAdi  = trim($fatAdres['district'] ?? '');

                    if ($sehirAdi) {
                        $sehirRow = $db->fetchOne("SELECT TOP 1 SehirId FROM Adres_Sehirler WHERE SehirAdi LIKE ?", ['%' . $sehirAdi . '%']);
                        if ($sehirRow) {
                            $sehirId = (int)$sehirRow['SehirId'];
                            if ($ilceAdi) {
                                $ilceRow = $db->fetchOne("SELECT TOP 1 ilceId FROM Adres_Ilceler WHERE IlceAdi LIKE ? AND SehirId = ?", ['%' . $ilceAdi . '%', $sehirId]);
                                if ($ilceRow) $ilceId = (int)$ilceRow['ilceId'];
                            }
                        }
                    }

                    $db->insert('Cari', [
                        'cari_tipi_id'               => 4, // Pazaryeri
                        'cari_adi'                   => $ad ?: $unvan,
                        'cari_unvan'                 => $unvan ?: $ad,
                        'cari_vergi_no'              => $vergiNo,
                        'cari_vergi_dairesi'         => '',
                        'cari_telefon'               => preg_replace('/\D/', '', $ham['customerPhone'] ?? ''),
                        'cari_email'                 => $ham['customerEmail'] ?? '',
                        'cari_adres'                 => $fatAdres['fullAddress'] ?? $fatAdres['address1'] ?? '',
                        'cari_posta_kodu'            => $fatAdres['postalCode'] ?? '',
                        'cari_ulke'                  => $ulkeId,
                        'cari_sehirler'              => $sehirId,
                        'cari_ilceler'               => $ilceId,
                        'cari_aktif'                 => 1,
                        'cari_olusturma_tarihi'      => date('Y-m-d H:i:s'),
                        'cari_olusturan_kullanici'   => $user['kullanici_id'],
                        'cari_guncelleme_tarihi'     => date('Y-m-d H:i:s'),
                        'cari_guncelleyen_kullanici' => $user['kullanici_id'],
                    ]);
                    $cariId = (int)$db->getLastInsertId();
                }

                $db->execute(
                    "UPDATE PazaryeriSiparisleri SET PazaryeriSiparisleri_Cari_id = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE PazaryeriSiparisleri_id = ?",
                    [$cariId, $user['kullanici_id'], $psId]
                );

                echo json_encode([
                    'success' => true,
                    'url'     => "/Admin/fatura-form?pazaryeri_siparis_id={$psId}&cari_id={$cariId}",
                    'cari_id' => $cariId,
                ]);
                break;

            case 'paket_durum':
                $psId   = (int)($_POST['ps_id'] ?? 0);
                $status = trim($_POST['status'] ?? '');
                if (!$psId || !$status) throw new Exception('Eksik parametre.');
                if (!in_array($status, ['Picking', 'Invoiced'], true)) throw new Exception('Geçersiz statü.');

                $sip = $db->fetchOne("
                    SELECT kanal_id, PazaryeriSiparisleri_PaketNo AS paket
                    FROM PazaryeriSiparisleri WHERE PazaryeriSiparisleri_id = ?", [$psId]);
                if (!$sip) throw new Exception('Sipariş bulunamadı.');
                if (empty($sip['paket'])) throw new Exception('Paket ID bulunamadı. Siparişleri çekin.');

                // Paket satırları normalize tablodan (lineId = kalem DisId)
                $lines = [];
                foreach ($db->fetchAll("SELECT PazaryeriSiparisKalemleri_DisId AS lid, PazaryeriSiparisKalemleri_Miktar AS adet
                                        FROM PazaryeriSiparisKalemleri WHERE PazaryeriSiparisleri_id = ? AND Durum = 1", [$psId]) as $k) {
                    if ($k['lid']) $lines[] = ['lineId' => (int)$k['lid'], 'quantity' => (int)$k['adet']];
                }

                $ek = [];
                $faturaNo = $faturaTarih = $faturaLink = '';
                if ($status === 'Invoiced') {
                    $faturaNo    = trim($_POST['fatura_no']    ?? '');
                    $faturaTarih = trim($_POST['fatura_tarih'] ?? date('Y-m-d'));
                    $faturaLink  = trim($_POST['fatura_link']  ?? '');
                    if ($faturaNo === '') throw new Exception('Invoiced statüsü için fatura numarası zorunludur.');
                    $ek['invoiceNumber'] = $faturaNo;
                }

                $api = pazaryeriApi((int)$sip['kanal_id'], $user['kullanici_id']);
                if (!$api->yetenek('paketDurum')) throw new Exception($api->getPazaryeriAdi() . ' paket durumu güncellemeyi desteklemiyor.');

                $s = $api->paketDurumGuncelle($sip['paket'], $status, $lines, $ek);
                $sonuc = ['success' => $s['basarili'], 'message' => $s['basarili'] ? $s['mesaj'] : ('API Hatası: ' . $s['hata'])];

                if ($s['basarili']) {
                    $db->execute("
                        UPDATE PazaryeriSiparisleri
                        SET PazaryeriSiparisleri_HamDurum = ?, PazaryeriSiparisleri_DurumKod = 'hazirlaniyor',
                            GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                        WHERE PazaryeriSiparisleri_id = ?", [$status, $user['kullanici_id'], $psId]);

                    // Fatura linki varsa bildir — sonuç kullanıcıya iletilir, ana işlemi bloklamaz
                    if ($status === 'Invoiced' && $faturaLink !== '' && $api->yetenek('faturaBildirim')) {
                        $link = $api->faturaLinkGonder($sip['paket'], [
                            'numara' => $faturaNo,
                            'tarih'  => strtotime($faturaTarih) * 1000,
                            'link'   => $faturaLink,
                        ]);
                        $sonuc['message'] .= $link['basarili']
                            ? ' Fatura linki iletildi.'
                            : ' UYARI: Fatura linki iletilemedi: ' . $link['hata'];
                    }
                }

                echo json_encode($sonuc);
                break;

            case 'cek_siparisler':
                // Hepsiburada her sipariş için ayrı detay isteği atar; geniş aralıkta 60 sn'yi aşabilir
                set_time_limit(300);
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $gun     = max(1, min(14, (int)($_POST['gun'] ?? 1)));   // sipariş aralığı en fazla 14 gün

                // Kanal seçilmediyse sipariş yeteneği olan tüm aktif kanallar
                $hedefler = $kanalId ? [$kanalId] : array_column(
                    array_filter($kanallar, fn($k) => !empty($k['yetenekler']['siparis'])), 'kanal_id');

                $toplam = 0; $yeni = 0; $hatali = 0; $hatalar = [];
                foreach ($hedefler as $kid) {
                    $api = pazaryeriApi((int)$kid, $user['kullanici_id']);
                    $r   = $api->siparisleriSenkronla($gun);
                    if (!$r['basarili']) { $hatalar[] = $api->getKanalAdi() . ': ' . $r['hata']; continue; }
                    $toplam += (int)$r['adet'];
                    $yeni   += (int)($r['yeni'] ?? 0);
                    $hatali += (int)($r['hatali'] ?? 0);
                }

                if ($hatalar && $toplam === 0) throw new Exception(implode(' | ', $hatalar));

                echo json_encode([
                    'success'     => true,
                    'mesaj'       => "Son {$gun} günün siparişleri çekildi.",
                    'toplam'      => $toplam,
                    'yeni'        => $yeni,
                    'guncellenen' => $toplam - $yeni,
                    'hata'        => $hatali + count($hatalar),
                ]);
                break;

            case 'faturasiz_urunler':
                // Faturasız siparişlerdeki kalemler — SQL'de gruplanır
                $rows = $db->fetchAll("
                    SELECT MAX(k.PazaryeriSiparisKalemleri_UrunAdi)              AS adi,
                           MAX(ISNULL(k.PazaryeriSiparisKalemleri_Barkod, '—'))  AS barkod,
                           SUM(k.PazaryeriSiparisKalemleri_Miktar)               AS toplam_adet,
                           COUNT(*)                                              AS siparis_sayisi,
                           SUM(ISNULL(k.PazaryeriSiparisKalemleri_BirimFiyat, 0) * k.PazaryeriSiparisKalemleri_Miktar) AS toplam_tutar
                    FROM PazaryeriSiparisKalemleri k
                    INNER JOIN PazaryeriSiparisleri s ON s.PazaryeriSiparisleri_id = k.PazaryeriSiparisleri_id
                    WHERE s.Durum = 1 AND k.Durum = 1 AND s.PazaryeriSiparisleri_Fatura_id IS NULL
                      AND ISNULL(k.PazaryeriSiparisKalemleri_UrunAdi, '') <> ''
                    GROUP BY LOWER(LTRIM(RTRIM(k.PazaryeriSiparisKalemleri_UrunAdi)))
                    ORDER BY SUM(k.PazaryeriSiparisKalemleri_Miktar) DESC
                ");
                foreach ($rows as &$r) {
                    $r['toplam_adet']    = (int)$r['toplam_adet'];
                    $r['siparis_sayisi'] = (int)$r['siparis_sayisi'];
                    $r['toplam_tutar']   = (float)$r['toplam_tutar'];
                }
                unset($r);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()]);
    }
    exit;
}

// Durum filtresi seçenekleri — sabit liste değil, kayıtlı durumlardan üretilir (etiketler JS'te)
$durumlar = array_column($db->fetchAll("
    SELECT DISTINCT PazaryeriSiparisleri_HamDurum AS d
    FROM PazaryeriSiparisleri
    WHERE Durum = 1 AND PazaryeriSiparisleri_HamDurum IS NOT NULL
    ORDER BY PazaryeriSiparisleri_HamDurum
"), 'd');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .durum-badge { font-size:.75rem; padding:3px 8px; border-radius:20px; font-weight:500; }
        .durum-Created   { background:#fff3cd; color:#856404; }
        .durum-Picking   { background:#cff4fc; color:#055160; }
        .durum-Invoiced  { background:#cff4fc; color:#055160; }
        .durum-Shipped   { background:#cfe2ff; color:#084298; }
        .durum-Delivered { background:#d1e7dd; color:#0a3622; }
        .durum-UnDelivered { background:#f8d7da; color:#842029; }
        .durum-Returned  { background:#e2e3e5; color:#41464b; }
        .durum-Cancelled { background:#f8d7da; color:#842029; }
        .durum-ClaimCreated { background:#fff3cd; color:#856404; }
        .durum-ClaimAccepted { background:#d1e7dd; color:#0a3622; }
        .durum-ClaimRejected { background:#f8d7da; color:#842029; }
        .durum-UnPacked  { background:#e2e3e5; color:#41464b; }
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
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
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
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-bag"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Sipariş</span>
                                <span class="info-box-number" id="stat-toplam">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Yeni</span>
                                <span class="info-box-number" id="stat-yeni">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-truck"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Hazırlanan / Kargoda</span>
                                <span class="info-box-number" id="stat-kargoda">–</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Teslim Edildi</span>
                                <span class="info-box-number" id="stat-teslim">–</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
                <div class="card mb-3">
                    <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePaneli">
                        <h3 class="card-title"><i class="bi bi-funnel me-2"></i>Filtrele</h3>
                        <div class="card-tools"><button type="button" class="btn btn-tool"><i class="bi bi-chevron-down"></i></button></div>
                    </div>
                    <div class="collapse" id="filtrePaneli">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label small">Platform</label>
                                    <select class="form-select form-select-sm select2" id="filtre_kanal">
                                        <option value="">Tüm Platformlar</option>
                                        <?php foreach ($kanallar as $k): ?>
                                        <option value="<?= (int)$k['kanal_id'] ?>"><?= htmlspecialchars($k['Pazaryerleri_Ad'] . ' — ' . $k['kanal_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Durum</label>
                                    <select class="form-select form-select-sm select2" id="filtre_durum">
                                        <option value="">Tümü</option>
                                        <?php foreach ($durumlar as $d): ?>
                                        <option value="<?= htmlspecialchars($d) ?>" data-durum="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Fatura</label>
                                    <select class="form-select form-select-sm select2" id="filtre_fatura">
                                        <option value="">Tümü</option>
                                        <option value="1">Faturalı</option>
                                        <option value="0">Faturasız</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Tarih Başlangıç</label>
                                    <input type="date" class="form-control form-control-sm" id="filtre_tarih_bas">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Tarih Bitiş</label>
                                    <input type="date" class="form-control form-control-sm" id="filtre_tarih_bit">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Ara (sipariş no, müşteri, takip no)</label>
                                    <input type="text" class="form-control form-control-sm" id="filtre_arama" placeholder="Ara…">
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-primary btn-sm" onclick="listeYukle()"><i class="bi bi-search me-1"></i>Filtrele</button>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="filtreTemizle()"><i class="bi bi-x-circle me-1"></i>Temizle</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h3 class="card-title me-auto"><i class="bi bi-table me-2"></i>Siparişler</h3>
                        <div class="d-flex gap-2 align-items-center">
                            <select class="form-select form-select-sm select2" id="cek_gun" style="width:130px">
                                <option value="1">Son 1 Gün</option>
                                <option value="3" selected>Son 3 Gün</option>
                                <option value="7">Son 7 Gün</option>
                                <option value="14">Son 14 Gün</option>
                            </select>
                            <button class="btn btn-outline-warning btn-sm" onclick="faturasizUrunleriGoster()">
                                <i class="bi bi-receipt me-1"></i>Faturasız Ürünler
                            </button>
                            <button class="btn btn-success btn-sm" id="btnCekSiparisler" onclick="cekSiparisler()">
                                <i class="bi bi-cloud-download me-1"></i>Siparişleri Çek
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="sipTable" class="table table-bordered table-striped table-sm mb-0 w-100">
                            <thead>
                                <tr>
                                    <th>Platform</th>
                                    <th>Sipariş No</th>
                                    <th>Durum</th>
                                    <th>Fatura</th>
                                    <th>Müşteri</th>
                                    <th>Şehir</th>
                                    <th>Tutar</th>
                                    <th>Kargo</th>
                                    <th>Sipariş Tarihi</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- MODAL: Sipariş Detayı -->
<div class="modal fade" id="detayModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bag me-2"></i>Sipariş Detayı — <span id="detay-no"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detayIcerik">
                <div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat spin fs-3"></i></div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="d-flex gap-2" id="modalAksiyonlar">
                    <button type="button" id="btnHazirla" class="btn btn-warning btn-sm" style="display:none"
                        onclick="paketDurum('Picking')">
                        <i class="bi bi-box-seam me-1"></i>Hazırlamaya Başla
                    </button>
                    <button type="button" id="btnFatura" class="btn btn-info btn-sm" style="display:none"
                        onclick="faturaKes()">
                        <i class="bi bi-receipt me-1"></i>Fatura Kes
                    </button>
                    <a id="btnKargoTakip" href="#" target="_blank"
                        class="btn btn-outline-secondary btn-sm" style="display:none">
                        <i class="bi bi-truck me-1"></i>Kargo Takip
                    </a>
                    <button type="button" id="btnKargoEtiketi" class="btn btn-outline-primary btn-sm" style="display:none"
                        onclick="kargoEtiketiIndir()"
                        title="API yetkisi varsa ZPL indirir, yoksa pazaryeri panelini açar">
                        <i class="bi bi-printer me-1"></i>Kargo Etiketi
                    </button>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Faturasız Ürünler -->
<div class="modal fade" id="faturasizUrunlerModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt me-2 text-warning"></i>Faturasız Siparişlerdeki Ürünler</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="faturasizYukleniyor" class="text-center py-4 text-muted">
                    <i class="bi bi-arrow-repeat spin fs-3"></i><div class="mt-2">Yükleniyor...</div>
                </div>
                <div id="faturasizIcerik" style="display:none">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <small class="text-muted" id="faturasizOzet"></small>
                        <input type="text" class="form-control form-control-sm w-auto" id="faturasizAra" placeholder="Ürün/barkod ara…" style="min-width:220px">
                    </div>
                    <table id="faturasizTable" class="table table-bordered table-striped table-sm mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Ürün Adı</th>
                                <th>Barkod</th>
                                <th class="text-center">Toplam Adet</th>
                                <th class="text-center">Sipariş Satırı</th>
                                <th class="text-end">Toplam Tutar</th>
                            </tr>
                        </thead>
                        <tbody id="faturasizTbody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-success btn-sm" onclick="faturasizExcel()">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel İndir
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
let table;
const detayModal = new bootstrap.Modal(document.getElementById('detayModal'));

const durumMap = {
    'Created':        ['Yeni sipariş',         'durum-Created'],
    'Awaiting':       ['Onay bekliyor',        'durum-Created'],
    'Picking':        ['Sipariş hazırlanıyor', 'durum-Picking'],
    'Invoiced':       ['Fatura kesildi',       'durum-Invoiced'],
    'Shipped':        ['Kargoya verildi',      'durum-Shipped'],
    'Delivered':      ['Teslim edildi',        'durum-Delivered'],
    'AtCollectionPoint': ['Teslim noktasında', 'durum-Shipped'],
    'UNDELIVERED':    ['Teslim edilemedi',     'durum-UnDelivered'],
    'UnDelivered':    ['Teslim edilemedi',     'durum-UnDelivered'],
    'RETURNED':       ['İade edildi',          'durum-Returned'],
    'Returned':       ['İade edildi',          'durum-Returned'],
    'CANCELLED':      ['İptal',                'durum-Cancelled'],
    'Cancelled':      ['İptal',                'durum-Cancelled'],
    'UnSupplied':     ['Tedarik edilemedi',    'durum-Cancelled'],
    'CLAIM_CREATED':  ['İade oluşturuldu',     'durum-ClaimCreated'],
    'CLAIM_ACCEPTED': ['İade kabul',           'durum-ClaimAccepted'],
    'CLAIM_REJECTED': ['İade ret',             'durum-ClaimRejected'],
    'UnPacked':       ['Paketlenmemiş',        'durum-UnPacked']
};

function durumBadge(d) {
    const [label, cls] = durumMap[d] || [d, 'durum-UnPacked'];
    return `<span class="durum-badge ${cls}">${escHtml(label)}</span>`;
}

function statYukle() {
    $.post('', { action: 'stats', kanal_id: $('#filtre_kanal').val() }, function(r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-yeni').text(r.data.yeni);
        $('#stat-kargoda').text(r.data.kargoda);
        $('#stat-teslim').text(r.data.teslim);
    });
}

// Server-side DataTables — filtreler her istekte panelden okunur
function tabloKur() {
    table = $('#sipTable').DataTable({
        serverSide: true,
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        order: [[8, 'desc']],
        pageLength: 25,
        ajax: {
            url: '',
            type: 'POST',
            data: function(d) {
                d.action           = 'list';
                d.filtre_kanal     = $('#filtre_kanal').val();
                d.filtre_durum     = $('#filtre_durum').val();
                d.filtre_fatura    = $('#filtre_fatura').val();
                d.filtre_tarih_bas = $('#filtre_tarih_bas').val();
                d.filtre_tarih_bit = $('#filtre_tarih_bit').val();
                d.filtre_arama     = $('#filtre_arama').val();
            },
            error: function() { showToast('Veriler yüklenemedi.', 'danger'); }
        },
        columns: [
            { data: 'Entegrasyon_Adi', render: (d, t, row) =>
                `<span class="badge" style="background:${escHtml(row.Entegrasyon_Renk ?? '#666')}">${escHtml(d)}</span>`
            },
            { data: 'ps_siparis_no', render: d => `<code class="small">${escHtml(d ?? '—')}</code>` },
            { data: 'ps_durum', render: d => durumBadge(d) },
            { data: 'ps_fatura_id', render: d =>
                d ? `<span class="badge bg-success"><i class="bi bi-receipt-cutoff me-1"></i>Kesildi</span>`
                  : `<span class="badge bg-light text-secondary border">Yok</span>`
            },
            { data: 'ps_musteri_adi', render: d => escHtml(d ?? '—') },
            { data: 'ps_musteri_sehir', render: d => escHtml(d ?? '—') },
            { data: 'ps_toplam_tutar', render: d => d ? `<strong>${parseFloat(d).toLocaleString('tr-TR', {minimumFractionDigits:2})} ₺</strong>` : '—' },
            { data: 'ps_kargo_firmasi', render: (d, t, row) => {
                let s = escHtml(d ?? '—');
                if (row.ps_kargo_takip_no) s += `<br><small class="text-muted">${escHtml(row.ps_kargo_takip_no)}</small>`;
                return s;
            }},
            { data: 'ps_siparis_tarihi', render: d => `<small>${escHtml(d ?? '—')}</small>` },
            { data: 'ps_id', orderable: false, render: d =>
                `<button class="btn btn-xs btn-outline-info btn-sm" onclick="sipDetay(${d})"><i class="bi bi-eye"></i></button>`
            },
        ]
    });
}

function listeYukle() {
    if (table) { table.ajax.reload(); } else { tabloKur(); }
}

function filtreTemizle() {
    $('#filtre_kanal, #filtre_durum, #filtre_fatura').val('').trigger('change');
    $('#filtre_tarih_bas, #filtre_tarih_bit, #filtre_arama').val('');
    listeYukle();
    statYukle();
}

function sipDetay(id) {
    $('#detay-no').text('#' + id);
    $('#detayIcerik').html('<div class="text-center py-4 text-muted"><i class="bi bi-arrow-repeat spin fs-3"></i></div>');
    detayModal.show();

    $.post('', { action: 'detay', ps_id: id }, function(r) {
        if (!r.success || !r.data) {
            $('#detayIcerik').html('<div class="alert alert-danger">Kayıt bulunamadı.</div>');
            return;
        }
        const d = r.data;
        const yt = d.yetenekler || {};

        // Ürünler
        let urunlerHtml = '—';
        try {
            const urunler = JSON.parse(d.ps_urunler || '[]');
            if (urunler.length) {
                urunlerHtml = '<table class="table table-sm table-bordered mb-0"><thead><tr><th>Ürün</th><th>Barkod</th><th>Adet</th><th>Birim Fiyat</th><th>Durum</th></tr></thead><tbody>';
                urunler.forEach(u => {
                    urunlerHtml += `<tr><td>${escHtml(u.adi ?? '—')}</td><td><code class="small">${escHtml(u.barkod ?? '—')}</code></td><td>${u.miktar ?? 1}</td><td>${parseFloat(u.fiyat ?? 0).toLocaleString('tr-TR', {minimumFractionDigits:2})} ₺</td><td>${escHtml(u.durum ?? '—')}</td></tr>`;
                });
                urunlerHtml += '</tbody></table>';
            }
        } catch(e) {}

        // Adres
        let adresHtml = '—';
        try {
            const adres = JSON.parse(d.ps_teslimat_adresi || '{}');
            adresHtml = [adres.address, adres.district, adres.city, adres.countryCode].filter(Boolean).join(', ');
        } catch(e) {}

        // Ham veri parse — buton ve adres detayları için (ÖNCE yapılmalı)
        let ham = {};
        try { ham = JSON.parse(d.ps_ham_veri || '{}'); } catch(e) {}

        // Modal aksiyon butonları — durum + pazaryeri yeteneğine göre
        $('#btnHazirla, #btnFatura, #btnKargoEtiketi, #btnKargoTakip').hide().data('ps_id', d.ps_id);

        const trackLink = ham.cargoTrackingLink || '';
        if (trackLink) {
            $('#btnKargoTakip').attr('href', trackLink).show();
        } else if (d.ps_kargo_takip_no) {
            $('#btnKargoTakip').attr('href', `https://kargotakip.com/?takip=${encodeURIComponent(d.ps_kargo_takip_no)}`).show();
        }

        if (yt.kargoEtiketi && d.ps_kargo_takip_no && ['Picking','Invoiced'].includes(d.ps_durum)) {
            $('#btnKargoEtiketi').show().data('ps_id', d.ps_id);
        }

        if (d.ps_kargo_paket_id) {
            if (yt.paketDurum && d.ps_durum === 'Created') $('#btnHazirla').show().data('ps_id', d.ps_id);
            if (['Picking','Shipped','Delivered','Invoiced','UnDelivered','Returned'].includes(d.ps_durum) || d.ps_fatura_id) {
                const faturaVar = !!d.ps_fatura_id;
                $('#btnFatura')
                    .show()
                    .data('ps_id', d.ps_id)
                    .data('ps_fatura_id', d.ps_fatura_id || 0)
                    .html(faturaVar
                        ? '<i class="bi bi-receipt-cutoff me-1"></i>Mevcut Faturası'
                        : '<i class="bi bi-receipt me-1"></i>Fatura Kes'
                    )
                    .removeClass('btn-info btn-success')
                    .addClass(faturaVar ? 'btn-success' : 'btn-info');
            }
        }

        const teslimatAdres = ham.shipmentAddress ?? {};
        const faturaAdres   = ham.invoiceAddress  ?? {};
        const musAd   = escHtml((ham.customerFirstName ?? '') + ' ' + (ham.customerLastName ?? '')).trim() || escHtml(d.ps_musteri_adi ?? '—');
        const musEmail = escHtml(ham.customerEmail ?? '—');
        const musTelRaw = teslimatAdres.phone ?? teslimatAdres.phoneNumber ?? ham.customerPhone ?? null;
        const musTel    = musTelRaw
            ? escHtml(musTelRaw)
            : '<span class="text-muted small fst-italic">Pazaryeri tarafından gizleniyor</span>';
        const musId   = ham.customerId ? `<code class="small">${ham.customerId}</code>` : '—';

        // Vergi / kimlik bilgileri
        const sirket     = faturaAdres.company || '';
        const vkn        = ham.taxNumber       || '';
        const tckn       = ham.identityNumber  || '';
        const vergiDaire = faturaAdres.taxOffice || ham.taxOffice || '';
        const kurumsal   = sirket && vkn;

        const faturaTam = faturaAdres.address1
            ? [faturaAdres.address1, faturaAdres.district, faturaAdres.city].filter(Boolean).join(', ')
            : null;

        const takipUrl = (d.ps_kargo_takip_no && d.ps_kargo_firmasi)
            ? `<a href="https://kargotakip.com/?takip=${escHtml(d.ps_kargo_takip_no)}" target="_blank" class="small">${escHtml(d.ps_kargo_takip_no)} <i class="bi bi-box-arrow-up-right"></i></a>`
            : `<code>${escHtml(d.ps_kargo_takip_no ?? '—')}</code>`;

        const faturaHtml = d.FaturalarGiden_FaturaNo ? `
            <div class="col-12 mt-1 mb-2">
                <div class="alert alert-success py-2 mb-0 d-flex align-items-center justify-content-between shadow-sm border-success">
                    <div>
                        <i class="bi bi-receipt-cutoff me-2 fs-5"></i><strong>Fatura No:</strong> <code>${escHtml(d.FaturalarGiden_FaturaNo)}</code>
                        <span class="ms-3 badge bg-white text-success border border-success">${escHtml(d.FaturalarGiden_GibDurum || 'Kesildi')}</span>
                    </div>
                    <a href="/admin/pages/fatura-goruntule.php?fatura_no=${escHtml(d.FaturalarGiden_FaturaNo)}" target="_blank" class="btn btn-sm btn-success">
                        <i class="bi bi-eye me-1"></i> Faturayı Görüntüle
                    </a>
                </div>
            </div>
        ` : '';

        $('#detayIcerik').html(`
            <div class="row g-3">
                ${faturaHtml}

                <div class="col-md-6">
                    <h6 class="text-muted border-bottom pb-1 mb-2"><i class="bi bi-bag me-1"></i>Sipariş Bilgileri</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:38%">Platform</td><td><span class="badge" style="background:${escHtml(d.Entegrasyon_Renk??'#666')}">${escHtml(d.Entegrasyon_Adi??'')}</span></td></tr>
                        <tr><td class="text-muted">Sipariş No</td><td><code>${escHtml(d.ps_siparis_no??'—')}</code></td></tr>
                        <tr><td class="text-muted">Durum</td><td>${durumBadge(d.ps_durum)}</td></tr>
                        <tr><td class="text-muted">Sipariş Tarihi</td><td>${escHtml(d.siparis_tarihi_str??'—')}</td></tr>
                        <tr><td class="text-muted">Tutar</td><td><strong>${d.ps_toplam_tutar ? parseFloat(d.ps_toplam_tutar).toLocaleString('tr-TR',{minimumFractionDigits:2})+' ₺' : '—'}</strong></td></tr>
                        ${ham.deliveryType ? `<tr><td class="text-muted">Teslimat Tipi</td><td>${escHtml(ham.deliveryType)}</td></tr>` : ''}
                        ${ham.agreedDeliveryDate ? `<tr><td class="text-muted">Tahmini Teslim</td><td class="small">${new Date(ham.agreedDeliveryDate).toLocaleDateString('tr-TR')}</td></tr>` : ''}
                    </table>
                </div>

                <div class="col-md-6">
                    <h6 class="text-muted border-bottom pb-1 mb-2"><i class="bi bi-truck me-1"></i>Kargo Bilgileri</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:38%">Kargo Firması</td><td>${escHtml(d.ps_kargo_firmasi??'—')}</td></tr>
                        <tr><td class="text-muted">Takip No</td><td>${takipUrl}</td></tr>
                        ${d.ps_kargoya_verilme_tarihi ? `<tr><td class="text-muted">Kargoya Verilme</td><td class="small">${escHtml(d.ps_kargoya_verilme_tarihi)}</td></tr>` : ''}
                        ${d.ps_kargo_paket_id ? `<tr><td class="text-muted">Paket ID</td><td><code class="small">${escHtml(d.ps_kargo_paket_id)}</code></td></tr>` : ''}
                        ${ham.shipmentNumber ? `<tr><td class="text-muted">Sevkiyat No</td><td><code class="small">${ham.shipmentNumber}</code></td></tr>` : ''}
                    </table>
                </div>

                <div class="col-md-6">
                    <h6 class="text-muted border-bottom pb-1 mb-2"><i class="bi bi-person me-1"></i>Müşteri Bilgileri</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:38%">Ad Soyad</td><td><strong>${musAd}</strong></td></tr>
                        ${kurumsal ? `<tr><td class="text-muted">Firma</td><td><strong>${escHtml(sirket)}</strong></td></tr>` : ''}
                        <tr><td class="text-muted">E-posta</td><td class="small">${musEmail}</td></tr>
                        <tr><td class="text-muted">Telefon</td><td>${musTel}</td></tr>
                        <tr><td class="text-muted">Müşteri ID</td><td>${musId}</td></tr>
                        <tr><td class="text-muted">Şehir</td><td>${escHtml(d.ps_musteri_sehir??'—')}</td></tr>
                        ${kurumsal ? `
                            <tr><td class="text-muted">Vergi No (VKN)</td><td><code class="small">${escHtml(vkn)}</code></td></tr>
                            ${vergiDaire ? `<tr><td class="text-muted">Vergi Dairesi</td><td>${escHtml(vergiDaire)}</td></tr>` : ''}
                        ` : (tckn && tckn !== '11111111111' ? `<tr><td class="text-muted">TC Kimlik</td><td><code class="small">${escHtml(tckn)}</code></td></tr>` : '')}
                    </table>
                </div>

                <div class="col-md-6">
                    <h6 class="text-muted border-bottom pb-1 mb-2"><i class="bi bi-geo-alt me-1"></i>Teslimat Adresi</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:38%">Alıcı</td><td>${escHtml((teslimatAdres.firstName??'')+' '+(teslimatAdres.lastName??'')).trim()||musAd}</td></tr>
                        <tr><td class="text-muted">Adres</td><td class="small">${escHtml(teslimatAdres.fullAddress || teslimatAdres.address1 || adresHtml || '—')}</td></tr>
                        <tr><td class="text-muted">İlçe / İl</td><td>${escHtml([teslimatAdres.district, teslimatAdres.city].filter(Boolean).join(' / ')||'—')}</td></tr>
                        <tr><td class="text-muted">Posta Kodu</td><td>${escHtml(teslimatAdres.postalCode??'—')}</td></tr>
                        <tr><td class="text-muted">Ülke</td><td>${escHtml(teslimatAdres.countryCode??'TR')}</td></tr>
                        ${faturaTam ? `<tr><td class="text-muted">Fatura Adresi</td><td class="small text-muted">${escHtml(faturaTam)}</td></tr>` : ''}
                        ${teslimatAdres.neighborhood ? `<tr><td class="text-muted">Mahalle</td><td class="small">${escHtml(teslimatAdres.neighborhood)}</td></tr>` : ''}
                    </table>
                </div>

                <div class="col-12">
                    <h6 class="text-muted border-bottom pb-1 mb-2"><i class="bi bi-list-ul me-1"></i>Sipariş Kalemleri</h6>
                    ${urunlerHtml}
                </div>
            </div>
        `);
    });
}

function paketDurum(status) {
    const psId = $('#btnHazirla').data('ps_id') || $('#btnFatura').data('ps_id');
    const etiketler = { 'Picking': 'Hazırlamaya Başla', 'Invoiced': 'Fatura Kesildi' };

    Swal.fire({
        title: etiketler[status] || status,
        text: `Sipariş statüsü "${etiketler[status] || status}" olarak güncellenecek. Emin misiniz?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Evet, Güncelle',
        cancelButtonText: 'Vazgeç',
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('', { action:'paket_durum', ps_id:psId, status }, function(res) {
            if (!res.success) { showToast('Hata: ' + res.message, 'danger'); return; }
            showToast(res.message, 'success');
            if (status === 'Picking') { $('#btnHazirla').hide(); $('#btnFatura').show(); }
            if (status === 'Invoiced') { $('#btnFatura').hide(); }
            statYukle(); listeYukle();
        }).fail(() => showToast('Sunucu hatası.', 'danger'));
    });
}

function faturaKes() {
    const psId     = $('#btnFatura').data('ps_id');
    const faturaId = $('#btnFatura').data('ps_fatura_id');
    const btn      = $('#btnFatura');

    // Mükerrer önleme: kayıtlı fatura varsa doğrudan aç
    if (faturaId && parseInt(faturaId) > 0) {
        Swal.fire({
            title: 'Fatura Zaten Var',
            html: `Bu sipariş için <strong>#${faturaId}</strong> numaralı fatura oluşturulmuş.`,
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-eye me-1"></i>Faturasını Aç',
            cancelButtonText: 'Kapat',
            confirmButtonColor: '#198754',
        }).then(r => {
            if (r.isConfirmed) window.open(`/Admin/fatura-form?id=${faturaId}`, '_blank');
        });
        return;
    }

    btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Hazırlanıyor...');
    $.post('', { action: 'fatura_hazirla', ps_id: psId }, function(r) {
        btn.prop('disabled', false).html('<i class="bi bi-receipt me-1"></i>Fatura Kes');
        if (!r.success) { showToast('Hata: ' + (r.error || r.message), 'danger'); return; }
        window.open(r.url, '_blank');
        showToast('Fatura formu açıldı.', 'info');
    }).fail(() => {
        btn.prop('disabled', false).html('<i class="bi bi-receipt me-1"></i>Fatura Kes');
        showToast('Sunucu hatası.', 'danger');
    });
}

function kargoEtiketiIndir() {
    const psId = $('#btnKargoEtiketi').data('ps_id');
    const btn  = $('#btnKargoEtiketi');
    btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Yükleniyor...');

    $.post('', { action:'kargo_etiketi', ps_id:psId }, function(r) {
        btn.prop('disabled', false).html('<i class="bi bi-printer me-1"></i>Kargo Etiketi');

        if (r.panel_url) {
            showToast(r.error || 'Pazaryeri panelinde etiket yazdırın.', 'info');
            window.open(r.panel_url, '_blank');
            return;
        }
        if (!r.success) { showToast('Hata: ' + (r.error || r.message), 'danger'); return; }

        if (r.zpl) {
            const blob = new Blob([r.zpl], { type: 'application/octet-stream' });
            const link = document.createElement('a');
            link.href  = URL.createObjectURL(blob);
            link.download = `kargo-etiketi-${psId}.zpl`;
            link.click();
            showToast('ZPL etiket indirildi. Zebra yazıcınıza gönderin.', 'success');
        } else {
            showToast('Etiket alınamadı.', 'warning');
        }
    }).fail(() => {
        btn.prop('disabled', false).html('<i class="bi bi-printer me-1"></i>Kargo Etiketi');
        showToast('Sunucu hatası.', 'danger');
    });
}

function cekSiparisler() {
    const btn = $('#btnCekSiparisler');
    btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Çekiliyor…');

    $.post('', {
        action:   'cek_siparisler',
        kanal_id: $('#filtre_kanal').val(),
        gun:      $('#cek_gun').val(),
    }, function(r) {
        btn.prop('disabled', false).html('<i class="bi bi-cloud-download me-1"></i>Siparişleri Çek');

        if (!r.success) { showToast('Hata: ' + (r.message ?? r.mesaj), 'danger'); return; }

        Swal.fire({
            icon: 'success',
            title: 'Tamamlandı',
            html: `<table class="table table-sm text-start mt-2">
                <tr><td>Toplam İşlenen</td><td><strong>${r.toplam}</strong></td></tr>
                <tr><td>Yeni Eklenen</td><td class="text-success"><strong>${r.yeni}</strong></td></tr>
                <tr><td>Güncellenen</td><td class="text-info"><strong>${r.guncellenen}</strong></td></tr>
                <tr><td>Hatalı</td><td class="text-danger"><strong>${r.hata}</strong></td></tr>
            </table>`,
            confirmButtonText: 'Tamam',
        }).then(() => { statYukle(); listeYukle(); });
    }).fail(function() {
        btn.prop('disabled', false).html('<i class="bi bi-cloud-download me-1"></i>Siparişleri Çek');
        showToast('Sunucu hatası oluştu.', 'danger');
    });
}

let faturasizVeriler = [];
const faturasizModal = new bootstrap.Modal(document.getElementById('faturasizUrunlerModal'));

function faturasizUrunleriGoster() {
    faturasizModal.show();
    $('#faturasizYukleniyor').show();
    $('#faturasizIcerik').hide();

    $.post('', { action: 'faturasiz_urunler' }, function(r) {
        $('#faturasizYukleniyor').hide();
        if (!r.success) { showToast('Veriler yüklenemedi.', 'danger'); return; }
        faturasizVeriler = r.data;
        faturasizTabloGuncelle(faturasizVeriler);
        $('#faturasizIcerik').show();
    }).fail(() => {
        $('#faturasizYukleniyor').hide();
        showToast('Sunucu hatası.', 'danger');
    });
}

function faturasizTabloGuncelle(data) {
    let html = '';
    let toplamAdet = 0, toplamTutar = 0;

    data.forEach((u, i) => {
        toplamAdet  += u.toplam_adet;
        toplamTutar += u.toplam_tutar;
        html += `<tr>
            <td class="text-muted small">${i + 1}</td>
            <td>${escHtml(u.adi)}</td>
            <td><code class="small">${escHtml(u.barkod)}</code></td>
            <td class="text-center"><strong>${u.toplam_adet}</strong></td>
            <td class="text-center text-muted">${u.siparis_sayisi}</td>
            <td class="text-end">${u.toplam_tutar > 0 ? parseFloat(u.toplam_tutar).toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺' : '—'}</td>
        </tr>`;
    });

    $('#faturasizTbody').html(html || '<tr><td colspan="6" class="text-center text-muted">Faturasız ürün bulunamadı.</td></tr>');
    $('#faturasizOzet').html(
        `<strong>${data.length}</strong> farklı ürün — toplam <strong>${toplamAdet}</strong> adet` +
        (toplamTutar > 0 ? ` — <strong>${toplamTutar.toLocaleString('tr-TR', {minimumFractionDigits:2})} ₺</strong>` : '')
    );
}

$('#faturasizAra').on('input', function() {
    const q = $(this).val().toLowerCase();
    if (!q) { faturasizTabloGuncelle(faturasizVeriler); return; }
    faturasizTabloGuncelle(faturasizVeriler.filter(u =>
        (u.adi ?? '').toLowerCase().includes(q) || (u.barkod ?? '').toLowerCase().includes(q)
    ));
});

function faturasizExcel() {
    const aramaVal = $('#faturasizAra').val().toLowerCase();
    const data = aramaVal
        ? faturasizVeriler.filter(u => (u.adi ?? '').toLowerCase().includes(aramaVal) || (u.barkod ?? '').toLowerCase().includes(aramaVal))
        : faturasizVeriler;

    if (!data.length) { showToast('Dışa aktarılacak veri yok.', 'warning'); return; }

    const satirlar = data.map((u, i) => ({
        'Sıra':             i + 1,
        'Ürün Adı':         u.adi,
        'Barkod':           u.barkod,
        'Toplam Adet':      u.toplam_adet,
        'Sipariş Satırı':   u.siparis_sayisi,
        'Toplam Tutar (₺)': u.toplam_tutar > 0 ? parseFloat(u.toplam_tutar.toFixed(2)) : 0,
    }));

    const ws = XLSX.utils.json_to_sheet(satirlar);
    ws['!cols'] = [{ wch: 6 }, { wch: 50 }, { wch: 20 }, { wch: 14 }, { wch: 16 }, { wch: 18 }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Faturasız Ürünler');
    XLSX.writeFile(wb, `faturasiz-urunler-${new Date().toISOString().slice(0, 10)}.xlsx`);
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

$(function() {
    // Durum seçeneklerine okunur etiket (değerler DB'den gelir)
    $('#filtre_durum option[data-durum]').each(function() {
        const m = durumMap[$(this).data('durum')];
        if (m) $(this).text(m[0]);
    });
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
    statYukle();
    tabloKur();
    $('#filtre_arama').on('keypress', function(e) { if (e.which === 13) listeYukle(); });
    $('#filtre_kanal').on('change', function() { statYukle(); });
});
</script>
</body>
</html>
