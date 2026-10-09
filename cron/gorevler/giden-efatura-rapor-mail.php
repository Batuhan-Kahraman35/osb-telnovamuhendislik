<?php
/**
 * Cron Görevi: Günlük Giden e-Fatura Rapor Maili
 *
 * Görev kodu: giden_efatura_rapor_mail
 * Parametreler:
 *   dry            (checkbox)        — işaretliyse mail göndermez, raporu çıktıya basar
 *   alicilar       (kullanici_coklu) — raporun gönderileceği kullanıcılar
 *   marka_ids      (marka_coklu) — satış grubu kırılımında ayrı gösterilecek markalar
 *   marka_grup_adi (text)        — o grubun rapordaki başlığı (boşsa marka adları yazılır)
 *   diger_grup_adi (text)        — pazaryeri dışı, seçili marka dışındaki satışların başlığı
 *
 * Bölümler:
 *   1) Aylık Faturalar    : Ayın 1'inden bugüne (TRY toplam tutar / adet)
 *   2) Haftalık           : Son 7 gün
 *   3) Günlük             : Dünden bugüne + liste
 *   4) Satış Grubu Kırılımı : Bu Ay / Tüm Zamanlar — İnternet, seçili marka, diğer
 *
 * Kural: Durum=1, FaturaNo LIKE 'TLN%', İptal (CANCELLED/CANCEL) hariç.
 *        Toplam tutarlar yalnızca TRY faturalardan hesaplanır.
 *
 * TUTAR BAZI: Raporun tamamı KDV HARİÇ tutar (matrah) üzerinden hesaplanır.
 *        Fatura bazlı bölümlerde FaturalarGiden_NetTutar, kalem bazlı bölümlerde
 *        miktar × birim fiyat kullanılır.
 *
 * Satış grubu kırılımı birbirini kesmeyen 3 gruptan oluşur:
 *   İnternet — PazaryeriSiparisleri eşleşmesi olan faturalar (fatura matrahı)
 *   Marka    — pazaryeri dışı, kalemin ürünü seçili markalardan biri (kalem tutarı)
 *   Diğer    — pazaryeri dışı, seçili marka dışındaki kalemler (kalem tutarı)
 *
 * Marka eşleşmesi kalemin stok kodu ile Urun_Hizmet.urun_hizmet_kodu üzerinden yapılır;
 * ürün kartında karşılığı olmayan kalemler "Diğer" grubuna düşer. Kalem toplamı ile
 * fatura matrahı arasında iskontolu faturalarda küçük fark oluşabilir.
 */

require_once __DIR__ . '/../../admin/includes/EntegrasyonHelper.php';

if (!function_exists('efaturaTutarFmt')) {
    function efaturaTutarFmt($v): string
    {
        return number_format((float)$v, 2, ',', '.');
    }
}

function gorev_giden_efatura_rapor_mail(array $params, $db): array
{
    ob_start();

    $helper = new EntegrasyonHelper($db);
    $dryRun = !empty($params['dry']);

    // Tüm rapor KDV HARİÇ tutar üzerinden hesaplanır (matrah).
    // NetTutar dolu değilse fatura tutarından KDV düşülür, o da yoksa fatura
    // tutarı kullanılır — hiçbir faturanın tutarsız (sıfır) görünmemesi için.
    $KDV_HARIC = "
        COALESCE(
            NULLIF(f.FaturalarGiden_NetTutar, 0),
            NULLIF(f.FaturalarGiden_Tutar - ISNULL(f.FaturalarGiden_ToplamKDV, 0), 0),
            f.FaturalarGiden_Tutar
        )
    ";

    // Ortak filtre: geçerli TLN faturaları, iptaller hariç
    $ORTAK_FILTRE = "
        f.Durum = 1
        AND f.FaturalarGiden_FaturaNo LIKE 'TLN%'
        AND ISNULL(f.FaturalarGiden_Durum, '') NOT IN ('CANCELLED', 'CANCEL')
        AND ISNULL(f.FaturalarGiden_GIBDurum, '') NOT IN ('CANCELLED', 'CANCEL')
    ";

    // 1. Alıcılar — ID listesinden güncel adresler
    $aliciListesi = cronKullaniciMailleri($db, $params['alicilar'] ?? null);

    if (empty($aliciListesi)) {
        echo "[HATA] Geçerli alıcı bulunamadı. Zamanlayıcıda en az bir aktif kullanıcı seçili olmalı.\n";
        echo "[BILGI] Seçili ID'ler: " . ($params['alicilar'] ?? '(boş)') . "\n";

        return [
            'durum' => CRON_DURUM_HATA,
            'sonuc' => 'Geçerli alıcı yok — zamanlayıcıdaki kullanıcı seçimi kontrol edilmeli.',
            'cikti' => ob_get_clean(),
        ];
    }

    // 2. Aylık özet (ayın 1'inden bugüne)
    $aylik = $db->fetchOne("
        SELECT
            COUNT(*) AS Adet,
            SUM(CASE WHEN ISNULL(f.FaturalarGiden_ParaBirimi, 'TRY') = 'TRY' THEN $KDV_HARIC ELSE 0 END) AS ToplamTRY
        FROM dbo.FaturalarGiden f
        WHERE $ORTAK_FILTRE
          AND f.FaturalarGiden_Tarih >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1)
          AND f.FaturalarGiden_Tarih < DATEADD(DAY, 1, CAST(GETDATE() AS DATE))
    ");

    // 3. Haftalık özet (son 7 gün)
    $haftalikOzet = $db->fetchOne("
        SELECT
            COUNT(*) AS Adet,
            SUM(CASE WHEN ISNULL(f.FaturalarGiden_ParaBirimi, 'TRY') = 'TRY' THEN $KDV_HARIC ELSE 0 END) AS ToplamTRY
        FROM dbo.FaturalarGiden f
        WHERE $ORTAK_FILTRE
          AND f.FaturalarGiden_Tarih >= CAST(DATEADD(DAY, -7, GETDATE()) AS DATE)
          AND f.FaturalarGiden_Tarih < DATEADD(DAY, 1, CAST(GETDATE() AS DATE))
    ");

    // 3.1 Günlük özet (dünden itibaren)
    $gunlukOzet = $db->fetchOne("
        SELECT
            COUNT(*) AS Adet,
            SUM(CASE WHEN ISNULL(f.FaturalarGiden_ParaBirimi, 'TRY') = 'TRY' THEN $KDV_HARIC ELSE 0 END) AS ToplamTRY
        FROM dbo.FaturalarGiden f
        WHERE $ORTAK_FILTRE
          AND f.FaturalarGiden_Tarih >= CAST(DATEADD(DAY, -1, GETDATE()) AS DATE)
          AND f.FaturalarGiden_Tarih < DATEADD(DAY, 1, CAST(GETDATE() AS DATE))
    ");

    // 4. Günlük liste
    $gunlukListe = $db->fetchAll("
        SELECT
            f.FaturalarGiden_FaturaNo   AS FaturaNo,
            CONVERT(VARCHAR(10), f.FaturalarGiden_Tarih, 104) AS Tarih,
            f.FaturalarGiden_Unvan      AS Unvan,
            $KDV_HARIC                  AS Tutar,
            ISNULL(f.FaturalarGiden_ParaBirimi, 'TRY') AS ParaBirimi,
            pz.pazaryeri_adi            AS PazaryeriAdi,
            LTRIM(RTRIM(COALESCE(
                kh.kullanici_ad + ' ' + kh.kullanici_soyad,
                ko.kullanici_ad + ' ' + ko.kullanici_soyad
            ))) AS PersonelAdi
        FROM dbo.FaturalarGiden f
        OUTER APPLY (
            SELECT TOP 1 p.Pazaryerleri_Ad AS pazaryeri_adi
            FROM dbo.PazaryeriSiparisleri ps
            INNER JOIN dbo.tanim_Pazaryerleri p ON p.Pazaryerleri_id = ps.Pazaryerleri_id
            WHERE ps.PazaryeriSiparisleri_Fatura_id = f.FaturalarGiden_id AND ps.Durum = 1
        ) pz
        LEFT JOIN dbo.Teklifler t ON f.FaturalarGiden_Teklif_id = t.Teklifler_id
        LEFT JOIN dbo.kullanicilar kh ON t.Teklifler_hazirlayan_id = kh.kullanici_id
        LEFT JOIN dbo.kullanicilar ko ON f.OlusturanKullanici = ko.kullanici_id
        WHERE $ORTAK_FILTRE
          AND f.FaturalarGiden_Tarih >= CAST(DATEADD(DAY, -1, GETDATE()) AS DATE)
          AND f.FaturalarGiden_Tarih < DATEADD(DAY, 1, CAST(GETDATE() AS DATE))
        ORDER BY f.FaturalarGiden_Tarih DESC, f.FaturalarGiden_id DESC
    ");

    // 4.1 Satış grubu kırılımı (Bu Ay / Tüm Zamanlar)
    //
    // Marka listesi zamanlayıcı parametresinden gelir; kodda marka adı sabitlenmez.
    // Parametre boşsa marka satırı üretilmez, kırılım İnternet + Diğer olarak kalır.
    $markaIdler = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($params['marka_ids'] ?? '')))
    )));

    $markaAdlari = [];

    if ($markaIdler) {
        $yerTutucu = implode(',', array_fill(0, count($markaIdler), '?'));

        $markaSatirlari = $db->fetchAll("
            SELECT marka_adi
            FROM dbo.UrunHizmet_Markalar
            WHERE marka_id IN ({$yerTutucu})
            ORDER BY marka_sira_no, marka_adi
        ", $markaIdler);

        $markaAdlari = array_column($markaSatirlari, 'marka_adi');
    }

    // Grup başlıkları: parametrede verilmemişse marka adlarından türetilir
    $markaGrupAdi = trim((string)($params['marka_grup_adi'] ?? ''));

    if ($markaGrupAdi === '') {
        $markaGrupAdi = $markaAdlari ? implode(' / ', $markaAdlari) . ' Satışları' : '';
    }

    $digerGrupAdi = trim((string)($params['diger_grup_adi'] ?? ''));

    if ($digerGrupAdi === '') {
        $digerGrupAdi = 'Diğer Satışlar (Personel)';
    }

    // Pazaryeri eşleşmesi — grupları birbirinden ayıran tek koşul
    $PAZARYERI_VAR = "
        EXISTS (
            SELECT 1 FROM dbo.PazaryeriSiparisleri ps
            WHERE ps.PazaryeriSiparisleri_Fatura_id = f.FaturalarGiden_id AND ps.Durum = 1
        )
    ";

    // İnternet: pazaryeri eşleşmeli faturalar — fatura başlığı tutarı üzerinden (KDV hariç)
    $internet = $db->fetchOne("
        SELECT
            COUNT(CASE WHEN f.FaturalarGiden_Tarih >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1)
                       THEN 1 END) AS BuAyAdet,
            SUM(CASE WHEN f.FaturalarGiden_Tarih >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1)
                     THEN $KDV_HARIC ELSE 0 END) AS BuAyTutar,
            COUNT(*) AS TumAdet,
            SUM($KDV_HARIC) AS TumTutar
        FROM dbo.FaturalarGiden f
        WHERE $ORTAK_FILTRE
          AND ISNULL(f.FaturalarGiden_ParaBirimi, 'TRY') = 'TRY'
          AND $PAZARYERI_VAR
    ");

    // Pazaryeri dışı satışlar — kalem bazlı, marka grubuna göre ikiye ayrılır.
    // Tutar miktar × birim fiyat ile hesaplanır; NetTutar alanı gerçek satış
    // toplamını yansıtmadığı için kullanılmaz (bkz. musteri-faturalari.php).
    //
    // Marka grubu alt sorguda hesaplanır: SQL Server, GROUP BY içindeki parametreli
    // CASE ifadesini SELECT listesindekiyle eşleştiremediğinden sorgu hata verir.
    // ID'ler intval'landığı için listeyi doğrudan gömmek güvenlidir.
    $markaKosul = $markaIdler
        ? 'up.urun_hizmet_marka_id IN (' . implode(',', $markaIdler) . ')'
        : '1 = 0';

    $kalemGruplari = $db->fetchAll("
        SELECT
            k.MarkaGrubu,
            COUNT(DISTINCT CASE WHEN k.Tarih >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1)
                                THEN k.FaturaId END) AS BuAyAdet,
            SUM(CASE WHEN k.Tarih >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1)
                     THEN k.KalemTutar ELSE 0 END) AS BuAyTutar,
            COUNT(DISTINCT k.FaturaId) AS TumAdet,
            SUM(k.KalemTutar) AS TumTutar
        FROM (
            SELECT
                CASE WHEN $markaKosul THEN 1 ELSE 0 END AS MarkaGrubu,
                fd.FaturalarGiden_id    AS FaturaId,
                fd.FaturalarGiden_Tarih AS Tarih,
                fdk.FaturalarGidenKalemleri_Miktar * fdk.FaturalarGidenKalemleri_BirimFiyat AS KalemTutar
            FROM dbo.FaturalarGidenKalemleri fdk
            INNER JOIN dbo.FaturalarGiden fd ON fd.FaturalarGiden_id = fdk.FaturalarGidenKalemleri_Fatura_id
            OUTER APPLY (
                SELECT TOP 1 u.urun_hizmet_marka_id
                FROM dbo.Urun_Hizmet u
                WHERE u.urun_hizmet_kodu = fdk.FaturalarGidenKalemleri_StokKodu
                  AND fdk.FaturalarGidenKalemleri_StokKodu IS NOT NULL
                  AND fdk.FaturalarGidenKalemleri_StokKodu <> ''
                ORDER BY u.urun_hizmet_id
            ) up
            WHERE fd.Durum = 1
              AND fd.FaturalarGiden_FaturaNo LIKE 'TLN%'
              AND ISNULL(fd.FaturalarGiden_Durum, '') NOT IN ('CANCELLED', 'CANCEL')
              AND ISNULL(fd.FaturalarGiden_GIBDurum, '') NOT IN ('CANCELLED', 'CANCEL')
              AND ISNULL(fd.FaturalarGiden_ParaBirimi, 'TRY') = 'TRY'
              AND NOT EXISTS (
                  SELECT 1 FROM dbo.PazaryeriSiparisleri ps
                  WHERE ps.PazaryeriSiparisleri_Fatura_id = fd.FaturalarGiden_id AND ps.Durum = 1
              )
        ) k
        GROUP BY k.MarkaGrubu
    ");

    $bosGrup   = ['BuAyAdet' => 0, 'BuAyTutar' => 0, 'TumAdet' => 0, 'TumTutar' => 0];
    $markaGrup = $bosGrup;
    $digerGrup = $bosGrup;

    foreach ($kalemGruplari as $g) {
        if ((int)$g['MarkaGrubu'] === 1) {
            $markaGrup = $g;
        } else {
            $digerGrup = $g;
        }
    }

    $satisGruplari = [
        ['ad' => 'İnternet Satışları', 'veri' => $internet ?: $bosGrup],
    ];

    if ($markaIdler) {
        $satisGruplari[] = ['ad' => $markaGrupAdi, 'veri' => $markaGrup];
    }

    $satisGruplari[] = ['ad' => $digerGrupAdi, 'veri' => $digerGrup];

    // 5. HTML içeriği
    $aylikAdet  = (int)($aylik['Adet'] ?? 0);
    $aylikTutar = efaturaTutarFmt($aylik['ToplamTRY'] ?? 0);
    $haftaAdet  = (int)($haftalikOzet['Adet'] ?? 0);
    $haftaTutar = efaturaTutarFmt($haftalikOzet['ToplamTRY'] ?? 0);
    $gunAdet    = (int)($gunlukOzet['Adet'] ?? 0);
    $gunTutar   = efaturaTutarFmt($gunlukOzet['ToplamTRY'] ?? 0);

    $icerik = "
    <div style='font-family: Arial, sans-serif; color: #333; max-width: 800px;'>
        <h2 style='color: #0056b3;'>Günlük Giden e-Fatura Raporu</h2>
        <p style='color:#666;'>Rapor Tarihi: " . date('d.m.Y') . "</p>

        <table cellpadding='0' cellspacing='10' style='width:100%;'>
            <tr>
                <td style='width:33%; background:#e7f1ff; border:1px solid #b6d4fe; border-radius:6px; padding:15px;'>
                    <div style='font-size:13px; color:#0056b3; font-weight:bold;'>AYLIK FATURALAR (Ayın 1'inden bugüne, KDV hariç)</div>
                    <div style='font-size:22px; font-weight:bold; color:#0d6efd; margin-top:6px;'>{$aylikTutar} ₺</div>
                    <div style='font-size:13px; color:#555;'>Kesilen Fatura: <strong>{$aylikAdet}</strong> adet</div>
                </td>
                <td style='width:33%; background:#e8f5e9; border:1px solid #c3e6cb; border-radius:6px; padding:15px;'>
                    <div style='font-size:13px; color:#1b5e20; font-weight:bold;'>HAFTALIK RAPOR (Son 7 Gün, KDV hariç)</div>
                    <div style='font-size:22px; font-weight:bold; color:#198754; margin-top:6px;'>{$haftaTutar} ₺</div>
                    <div style='font-size:13px; color:#555;'>Kesilen Fatura: <strong>{$haftaAdet}</strong> adet</div>
                </td>
                <td style='width:33%; background:#fff3cd; border:1px solid #ffe69c; border-radius:6px; padding:15px;'>
                    <div style='font-size:13px; color:#856404; font-weight:bold;'>GÜNLÜK RAPOR (Düne ait, KDV hariç)</div>
                    <div style='font-size:22px; font-weight:bold; color:#fd7e14; margin-top:6px;'>{$gunTutar} ₺</div>
                    <div style='font-size:13px; color:#555;'>Kesilen Fatura: <strong>{$gunAdet}</strong> adet</div>
                </td>
            </tr>
        </table>
        <p style='font-size:11px; color:#999; margin-top:4px;'>* Tüm tutarlar KDV hariçtir. Yalnızca TRY faturalar hesaplanmıştır, iptal edilen faturalar hariçtir.</p>

        <h3 style='color: #0056b3; margin-top:25px;'>Satış Grubu Kırılımı</h3>
        <table border='1' cellpadding='8' cellspacing='0' style='width: 100%; border-collapse: collapse; font-size: 13px;'>
            <thead>
                <tr style='background-color: #f8f9fa;'>
                    <th align='left' rowspan='2'>Satış Grubu</th>
                    <th align='center' colspan='2' style='background-color:#e7f1ff;'>Bu Ay</th>
                    <th align='center' colspan='2' style='background-color:#e8f5e9;'>Tüm Zamanlar</th>
                </tr>
                <tr style='background-color: #f8f9fa;'>
                    <th align='right' style='background-color:#e7f1ff;'>Tutar</th>
                    <th align='right' style='background-color:#e7f1ff;'>Adet</th>
                    <th align='right' style='background-color:#e8f5e9;'>Tutar</th>
                    <th align='right' style='background-color:#e8f5e9;'>Adet</th>
                </tr>
            </thead>
            <tbody>";

    foreach ($satisGruplari as $grup) {
        $v = $grup['veri'];

        $icerik .= "
                <tr>
                    <td><strong>" . htmlspecialchars($grup['ad']) . "</strong></td>
                    <td align='right'>" . efaturaTutarFmt($v['BuAyTutar'] ?? 0) . " ₺</td>
                    <td align='right'>" . (int)($v['BuAyAdet'] ?? 0) . "</td>
                    <td align='right'>" . efaturaTutarFmt($v['TumTutar'] ?? 0) . " ₺</td>
                    <td align='right'>" . (int)($v['TumAdet'] ?? 0) . "</td>
                </tr>";
    }

    $icerik .= "
            </tbody>
        </table>
        <p style='font-size:11px; color:#999; margin-top:4px;'>
            * Tüm tutarlar KDV hariçtir. İnternet satışları pazaryeri siparişiyle eşleşen faturalardır ve fatura
            matrahı üzerinden hesaplanır. Diğer gruplar fatura kalemi bazında (miktar × birim fiyat) hesaplandığından
            iskontolu faturalarda üstteki aylık toplamla küçük farklar oluşabilir. Grup ayrımı kalemin stok kodu ile
            ürün kartı eşleştirmesine dayanır; ürün kartında karşılığı olmayan kalemler son gruba dahil edilir.
            Bir fatura birden fazla grupta ürün içeriyorsa tutarı gruplara dağıtılır, adet ise her grupta bir kez sayılır.
        </p>

        <h3 style='color: #0056b3; margin-top:25px;'>Düne Ait Faturalar (KDV hariç)</h3>
        <table border='1' cellpadding='8' cellspacing='0' style='width: 100%; border-collapse: collapse; font-size: 13px;'>
            <thead>
                <tr style='background-color: #f8f9fa;'>
                    <th align='left'>Tarih</th>
                    <th align='left'>Fatura No</th>
                    <th align='left'>Ünvan</th>
                    <th align='right'>Tutar</th>
                    <th align='left'>Satış Kanalı</th>
                </tr>
            </thead>
            <tbody>";

    if (empty($gunlukListe)) {
        $icerik .= "<tr><td colspan='5' align='center' style='color:#999;'>Düne ait fatura bulunmuyor.</td></tr>";
    } else {
        foreach ($gunlukListe as $h) {
            $kanal = !empty($h['PazaryeriAdi'])
                ? htmlspecialchars($h['PazaryeriAdi'])
                : htmlspecialchars(trim($h['PersonelAdi'] ?? '') !== '' ? $h['PersonelAdi'] : 'Satış Yetkilisi');

            $icerik .= "
                <tr>
                    <td>" . htmlspecialchars($h['Tarih'] ?? '-') . "</td>
                    <td>" . htmlspecialchars($h['FaturaNo'] ?? '-') . "</td>
                    <td>" . htmlspecialchars($h['Unvan'] ?? '-') . "</td>
                    <td align='right'>" . efaturaTutarFmt($h['Tutar'] ?? 0) . " " . htmlspecialchars($h['ParaBirimi']) . "</td>
                    <td>" . $kanal . "</td>
                </tr>";
        }
    }

    $icerik .= "
            </tbody>
        </table>
        <br>
        <p>Detaylı incelemek için: <a href='https://panel.ornekfirma.com/admin/giden-efaturalar' target='_blank'>Giden e-Faturalar Sayfasına Git</a></p>
        <br>
        <p style='font-size: 12px; color: #777;'>Bu e-posta sistem tarafından otomatik olarak oluşturulmuştur. Lütfen yanıtlamayınız.</p>
    </div>";

    $konu = "Günlük Giden e-Fatura Raporu - " . date('d.m.Y');
    $ozet = "Aylık: {$aylikAdet} adet / {$aylikTutar} ₺ — Günlük: {$gunAdet} adet / {$gunTutar} ₺";

    // 6. Gönderim (veya dry-run)
    if ($dryRun) {
        echo "[DRY-RUN] Mail GÖNDERİLMEDİ. Alıcılar olacaktı: " . implode(', ', array_keys($aliciListesi)) . "\n\n";
        echo $icerik;

        return [
            'durum' => CRON_DURUM_BASARILI,
            'sonuc' => 'Deneme modu — mail gönderilmedi. ' . $ozet,
            'cikti' => ob_get_clean(),
        ];
    }

    $sonuc = $helper->mailGonder($aliciListesi, $konu, $icerik);

    if ($sonuc['success']) {
        echo "[OK] Rapor maili gönderildi: " . implode(', ', array_keys($aliciListesi)) . "\n";

        return [
            'durum' => CRON_DURUM_BASARILI,
            'sonuc' => $ozet,
            'cikti' => ob_get_clean(),
        ];
    }

    echo "[HATA] Mail gönderilemedi: " . $sonuc['message'] . "\n";

    return [
        'durum' => CRON_DURUM_HATA,
        'sonuc' => 'Mail gönderilemedi: ' . $sonuc['message'],
        'cikti' => ob_get_clean(),
    ];
}
