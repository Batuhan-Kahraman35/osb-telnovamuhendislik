<?php
/**
 * Cron Görevi: Günlük Ödeme Yönetimi Rapor Maili
 *
 * Görev kodu: odeme_rapor_mail
 * Parametreler:
 *   alicilar (kullanici_coklu, zorunlu) — mail alıcıları; ID saklanır, adres çalışma anında okunur
 *   dry      (checkbox)                 — deneme modu, mail göndermez
 *
 * İçerik: kalanı olan cariler — Firma Adı, Giden Fatura, Kalan, Personel.
 *
 * Hesaplama, admin/pages/odeme-yonetimi.php sayfasının 'list' sorgusuyla BİREBİR
 * aynı olmak ZORUNDADIR. Sapma yaratmış ve tek tek düzeltilmiş kurallar:
 *   - Toplam borç SUM(...) ile hesaplanır, SUM(DISTINCT ...) DEĞİL. DISTINCT aynı
 *     tutarlı iki farklı faturayı tek sayıyordu.
 *   - Ödenen tutara Odeme_Kalemleri_odendi = 1 filtresi uygulanır; işaretsiz kalemler
 *     henüz tahsil edilmemiş taahhütlerdir ve "ödenmiş" sayılmamalıdır.
 *   - FaturalarGiden_HariciIptal = 1 olan faturalar borca yazılmaz. GİB İptal/İade
 *     Portalı'ndan iptal edilen faturada zarf durumu SUCCEED kalır, yani
 *     FaturalarGiden_Durum != 'CANCELLED' filtresi bunları YAKALAMAZ.
 *   - FaturalarGiden_StokIslendi = 1 olmayan fatura borca yazılmaz (stok çıkışı
 *     yapılmamış / geri alınmış fatura).
 *   - Tutarlar TL karşılığına çevrilerek toplanır (FaturaKur.php sabitleri).
 *     Ham FaturalarGiden_Tutar kendi para biriminde tutulur.
 *   - Cariden gelen faturalar (FaturalarGelen, VKN eşleşmeli) kalan borçtan SINIRLI
 *     mahsup edilir: mahsup o carinin borcunu AŞAMAZ. Sınırsız mahsupta yalnız
 *     tedarikçi olan bir cari (ör. 56 milyon TL alış, müşteri borcu yok) tüm müşteri
 *     alacağını silip toplamı eksiye çekiyordu. Mahsup cari bazında yapılır, toplam
 *     üzerinden ASLA yapılmaz.
 *     KALAN = Giden Fatura - Tahsil Edilen - MIN(Gelen Fatura, Giden Fatura - Tahsil Edilen)
 * Bu kurallardan biri sayfada değişirse burası da güncellenmelidir.
 */

require_once __DIR__ . '/../../admin/includes/EntegrasyonHelper.php';
require_once __DIR__ . '/../../admin/includes/FaturaKur.php';

if (!function_exists('odemeTutarFmt')) {
    function odemeTutarFmt($v): string
    {
        return number_format((float)$v, 2, ',', '.');
    }
}

function gorev_odeme_rapor_mail(array $params, $db): array
{
    ob_start();

    $helper = new EntegrasyonHelper($db);
    $dryRun = !empty($params['dry']);

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

    // 2. Cari bazlı borç / ödeme dökümü
    // Sayfadaki list sorgusuyla AYNI sinirli mahsup kurali (bkz. odeme-yonetimi.php):
    // gelen fatura kalan borctan dusulur ama o carinin borcunu ASAMAZ. Mahsup cari
    // bazinda yapilir; toplam uzerinden yapilsaydi tek bir buyuk tedarikcinin alis
    // faturalari tum musteri alacagini silerdi.
    $liste = $db->fetchAll("
        SELECT t.*,
            CASE WHEN t.brut_kalan <= 0 THEN 0
                 WHEN t.toplam_gelen >= t.brut_kalan THEN t.brut_kalan
                 ELSE t.toplam_gelen END AS mahsup_edilen,
            CASE WHEN t.brut_kalan <= 0 THEN t.brut_kalan
                 WHEN t.toplam_gelen >= t.brut_kalan THEN 0
                 ELSE t.brut_kalan - t.toplam_gelen END AS kalan
        FROM (
        SELECT
            ISNULL(c.cari_unvan, c.cari_adi) AS cari_adi,
            ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                            THEN " . FG_TL_TUTAR . " ELSE 0 END), 0) AS toplam_borc,
            ISNULL(od_agg.toplam_odenen, 0) AS toplam_odenen,
            ISNULL(gel_agg.toplam_gelen, 0) AS toplam_gelen,
            ISNULL(SUM(CASE WHEN ISNULL(fg.FaturalarGiden_StokIslendi, 0) = 1
                            THEN " . FG_TL_TUTAR . " ELSE 0 END), 0)
                - ISNULL(od_agg.toplam_odenen, 0) AS brut_kalan,
            MAX(pers.personel_adi) AS personel_teklif,
            MAX(oks.olusturan_adi) AS personel_olusturan
        FROM Cari c
        LEFT JOIN FaturalarGiden fg
               ON fg.FaturalarGiden_Cari_id = c.cari_id
              AND fg.Durum = 1
              AND UPPER(ISNULL(fg.FaturalarGiden_Durum, '')) != 'CANCELLED'
              AND ISNULL(fg.FaturalarGiden_HariciIptal, 0) = 0
        " . FG_KUR_APPLY . "
        OUTER APPLY (
            SELECT ISNULL(SUM(ok.Odeme_Kalemleri_tutar), 0) AS toplam_odenen
            FROM Odemeler o
            JOIN Odeme_Kalemleri ok ON ok.Odeme_Kalemleri_odeme_id = o.Odemeler_id
            WHERE o.Odemeler_cari_id = c.cari_id
              AND o.Durum = 1
              AND ok.Durum = 1
              AND ok.Odeme_Kalemleri_odendi = 1
        ) od_agg
        OUTER APPLY (
            -- Gelen fatura cariye id ile degil VKN uzerinden baglidir.
            -- NULLIF zorunlu: vergi no'su bos olan cariler aksi halde
            -- birbirinin faturalarini ustlenirdi.
            SELECT COUNT(*) AS gelen_sayisi,
                   ISNULL(SUM(CASE WHEN ISNULL(fge.FaturalarGelen_StokIslendi, 0) = 1
                                   THEN " . FGE_TL_TUTAR . " ELSE 0 END), 0) AS toplam_gelen
            FROM FaturalarGelen fge
            " . FGE_KUR_APPLY . "
            WHERE fge.FaturalarGelen_VKN_TCKN = NULLIF(c.cari_vergi_no, '')
              AND fge.Durum = 1
        ) gel_agg
        OUTER APPLY (
            SELECT TOP 1 k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi
            FROM Teklifler t2
            JOIN kullanicilar k ON t2.Teklifler_hazirlayan_id = k.kullanici_id
            WHERE t2.Teklifler_cari_id = c.cari_id AND t2.Durum = 1
            ORDER BY t2.Teklifler_id DESC
        ) pers
        OUTER APPLY (
            SELECT TOP 1 ko.kullanici_ad + ' ' + ko.kullanici_soyad AS olusturan_adi
            FROM kullanicilar ko
            WHERE ko.kullanici_id = c.cari_olusturan_kullanici
        ) oks
        WHERE c.cari_aktif = 1
          AND (fg.FaturalarGiden_id IS NOT NULL
               OR EXISTS (SELECT 1 FROM Odemeler o WHERE o.Odemeler_cari_id = c.cari_id AND o.Durum = 1)
               OR ISNULL(gel_agg.gelen_sayisi, 0) > 0)
        GROUP BY c.cari_id, c.cari_unvan, c.cari_adi, od_agg.toplam_odenen, gel_agg.toplam_gelen
        ) t
        ORDER BY kalan DESC
    ");

    // 3. Toplamlar TÜM cariler üzerinden hesaplanır, liste ise yalnızca kalanı olanları gösterir.
    //    Aksi halde borcunu tamamen kapatmış cariler listeden elendiği için tahsilat da
    //    toplamdan düşüyor ve "Tahsil Edilen" 0,00 gibi yanıltıcı görünüyordu.
    $tBorc  = 0.0;
    $tOde   = 0.0;
    $tGelen  = 0.0;
    $tMahsup = 0.0;
    $tKalan = 0.0;
    $borclu = [];

    foreach ($liste as $r) {
        $tBorc  += (float)$r['toplam_borc'];
        $tOde   += (float)$r['toplam_odenen'];
        $tGelen  += (float)$r['toplam_gelen'];
        $tMahsup += (float)$r['mahsup_edilen'];
        $tKalan += (float)$r['kalan'];

        if ((float)$r['kalan'] > 0.01) {
            $borclu[] = $r;
        }
    }

    $toplamCari = count($liste);
    $adet       = count($borclu);

    if ($adet === 0) {
        echo "[INFO] Kalanı olan cari bulunmuyor, mail gönderilmedi.\n";

        return [
            'durum' => CRON_DURUM_BASARILI,
            'sonuc' => 'Kalanı olan cari yok, mail gönderilmedi.',
            'cikti' => ob_get_clean(),
        ];
    }

    // Satır limiti: uzun listelerde mail sağlayıcıları içeriği kırpar
    // (Gmail ~102 KB üzerini "Message clipped" ile keser). 0 = sınırsız.
    $limit = isset($params['limit']) ? (int)$params['limit'] : 100;

    if ($limit > 0 && $adet > $limit) {
        $gosterilen = array_slice($borclu, 0, $limit);
        $kirpildi   = $adet - $limit;
    } else {
        $gosterilen = $borclu;
        $kirpildi   = 0;
    }

    // 4. HTML içeriği
    $icerik = "
    <div style='font-family: Arial, sans-serif; color: #333; max-width: 900px;'>
        <h2 style='color: #0056b3; margin-bottom:4px;'>Günlük Ödeme Durumu Raporu</h2>
        <p style='color:#666; margin-top:0;'>Rapor Tarihi: " . date('d.m.Y') . "</p>

        <table cellpadding='0' cellspacing='10' style='width:100%;'>
            <tr>
                <td style='width:25%; background:#e7f1ff; border:1px solid #b6d4fe; border-radius:6px; padding:15px;'>
                    <div style='font-size:13px; color:#0056b3; font-weight:bold;'>GİDEN FATURA</div>
                    <div style='font-size:22px; font-weight:bold; color:#0d6efd; margin-top:6px;'>" . odemeTutarFmt($tBorc) . " ₺</div>
                </td>
                <td style='width:25%; background:#e8f5e9; border:1px solid #c3e6cb; border-radius:6px; padding:15px;'>
                    <div style='font-size:13px; color:#1b5e20; font-weight:bold;'>TAHSİL EDİLEN</div>
                    <div style='font-size:22px; font-weight:bold; color:#198754; margin-top:6px;'>" . odemeTutarFmt($tOde) . " ₺</div>
                </td>
                <td style='width:25%; background:#f1f3f5; border:1px solid #dee2e6; border-radius:6px; padding:15px;'>
                    <div style='font-size:13px; color:#495057; font-weight:bold;'>MAHSUP EDİLEN</div>
                    <div style='font-size:22px; font-weight:bold; color:#6c757d; margin-top:6px;'>" . odemeTutarFmt($tMahsup) . " ₺</div>
                    <div style='font-size:13px; color:#555;'>Gelen fatura: " . odemeTutarFmt($tGelen) . " ₺</div>
                </td>
                <td style='width:25%; background:#fff3cd; border:1px solid #ffe69c; border-radius:6px; padding:15px;'>
                    <div style='font-size:13px; color:#856404; font-weight:bold;'>KALAN</div>
                    <div style='font-size:22px; font-weight:bold; color:#fd7e14; margin-top:6px;'>" . odemeTutarFmt($tKalan) . " ₺</div>
                    <div style='font-size:13px; color:#555;'>Borçlu firma: <strong>{$adet}</strong> / {$toplamCari}</div>
                </td>
            </tr>
        </table>
        <p style='font-size:11px; color:#999; margin-top:4px;'>
            * Özet kutular tüm aktif carileri kapsar; aşağıdaki liste yalnızca kalanı olan firmaları gösterir.
        </p>

        <h3 style='color: #0056b3; margin-top:25px;'>Kalanı Olan Firmalar</h3>
        <table border='1' cellpadding='8' cellspacing='0' style='width: 100%; border-collapse: collapse; font-size: 13px;'>
            <thead>
                <tr style='background-color: #f8f9fa;'>
                    <th align='left'>#</th>
                    <th align='left'>Firma Adı</th>
                    <th align='right'>Giden Fatura</th>
                    <th align='right'>Kalan</th>
                    <th align='left'>Personel</th>
                </tr>
            </thead>
            <tbody>";

    $sira      = 0;
    $listeBorc = 0.0;
    $listeKal  = 0.0;

    foreach ($gosterilen as $r) {
        $sira++;
        $listeBorc += (float)$r['toplam_borc'];
        $listeKal  += (float)$r['kalan'];

        // Önce teklifi hazırlayan; yoksa cariyi oluşturan kullanıcı (soluk gösterilir)
        $teklifci  = trim((string)($r['personel_teklif'] ?? ''));
        $olusturan = trim((string)($r['personel_olusturan'] ?? ''));

        if ($teklifci !== '') {
            $personel = htmlspecialchars($teklifci);
        } elseif ($olusturan !== '') {
            $personel = "<span style='color:#999;' title='Teklif kaydı yok, cariyi sisteme ekleyen kullanıcı'>"
                      . htmlspecialchars($olusturan) . '</span>';
        } else {
            $personel = "<span style='color:#ccc;'>—</span>";
        }

        $satirRenk = $sira % 2 === 0 ? " style='background:#fbfbfb;'" : '';

        $icerik .= "
                <tr{$satirRenk}>
                    <td>{$sira}</td>
                    <td>" . htmlspecialchars($r['cari_adi'] ?? '-') . "</td>
                    <td align='right'>" . odemeTutarFmt($r['toplam_borc']) . " ₺</td>
                    <td align='right'><strong>" . odemeTutarFmt($r['kalan']) . " ₺</strong></td>
                    <td>{$personel}</td>
                </tr>";
    }

    $icerik .= "
            </tbody>
            <tfoot>
                <tr style='background:#f1f3f5; font-weight:bold;'>
                    <td colspan='2' align='right'>LİSTE TOPLAMI</td>
                    <td align='right'>" . odemeTutarFmt($listeBorc) . " ₺</td>
                    <td align='right'>" . odemeTutarFmt($listeKal) . " ₺</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>";

    if ($kirpildi > 0) {
        $icerik .= "
        <p style='background:#fff3cd; border:1px solid #ffe69c; border-radius:4px; padding:10px; font-size:13px; color:#856404;'>
            En yüksek kalana sahip <strong>{$limit}</strong> firma listelenmiştir.
            <strong>{$kirpildi}</strong> firma daha bulunuyor; tam listeyi Ödeme Yönetimi sayfasından görebilirsiniz.
        </p>";
    }

    $icerik .= "
        <p style='font-size:11px; color:#999; margin-top:8px;'>
            * Yalnızca kalanı olan aktif firmalar listelenmiştir. İptal edilen (GİB portal iptalleri dahil)
            ve stoğa işlenmemiş faturalar hariçtir. Dövizli faturalar TL karşılığına çevrilerek toplanır.
            Kalan = Giden Fatura - Tahsil Edilen - Gelen Fatura. Firmalardan gelen alış faturaları
            (toplam " . odemeTutarFmt($tGelen) . " ₺) kalan borçtan mahsup edilir; mahsup edilen tutar
            " . odemeTutarFmt($tMahsup) . " ₺. Mahsup firmanın borcunu aşamaz, aşan kısım bu rapora girmez.
            Soluk yazılmış personel isimleri, teklif kaydı bulunmadığı için cariyi sisteme ekleyen kullanıcıyı gösterir.
        </p>

        <br>
        <p>Detaylı incelemek için:
            <a href='https://panel.ornekfirma.com/admin/odeme-yonetimi' target='_blank'>Ödeme Yönetimi Sayfasına Git</a>
        </p>
        <br>
        <p style='font-size: 12px; color: #777;'>Bu e-posta sistem tarafından otomatik olarak oluşturulmuştur. Lütfen yanıtlamayınız.</p>
    </div>";

    $konu = 'Günlük Ödeme Durumu Raporu - ' . date('d.m.Y');
    $ozet = "{$adet} borçlu firma, kalan: " . odemeTutarFmt($tKalan) . ' TL'
          . ($kirpildi > 0 ? " (listede ilk {$limit})" : '');

    // 5. Gönderim
    if ($dryRun) {
        echo "[DRY-RUN] Mail GÖNDERİLMEDİ. Alıcılar olacaktı: " . implode(', ', array_keys($aliciListesi)) . "\n";
        echo "[DRY-RUN] Toplam cari: {$toplamCari} | borçlu: {$adet} | listelenen: " . count($gosterilen)
           . ($kirpildi > 0 ? " (limit {$limit}, {$kirpildi} firma kırpıldı)" : '') . "\n";
        echo "[DRY-RUN] Toplam borç: " . odemeTutarFmt($tBorc) . " TL | Tahsil: " . odemeTutarFmt($tOde)
           . " TL | Kalan: " . odemeTutarFmt($tKalan) . " TL\n";
        echo '[DRY-RUN] Mail boyutu: ' . number_format(mb_strlen($icerik) / 1024, 1) . " KB\n\n";
        echo $icerik;

        return [
            'durum' => CRON_DURUM_BASARILI,
            'sonuc' => 'Deneme modu — mail gönderilmedi. ' . $ozet,
            'cikti' => ob_get_clean(),
        ];
    }

    $sonuc = $helper->mailGonder($aliciListesi, $konu, $icerik);

    if (!empty($sonuc['success'])) {
        echo "[OK] Rapor maili gönderildi: " . implode(', ', array_keys($aliciListesi)) . "\n";
        echo "[OZET] {$adet} firma | toplam borç: " . odemeTutarFmt($tBorc)
           . " TL | kalan: " . odemeTutarFmt($tKalan) . " TL\n";

        return [
            'durum' => CRON_DURUM_BASARILI,
            'sonuc' => $ozet . ', ' . count($aliciListesi) . ' alıcıya gönderildi.',
            'cikti' => ob_get_clean(),
        ];
    }

    echo "[HATA] Mail gönderilemedi: " . ($sonuc['message'] ?? 'bilinmeyen hata') . "\n";

    return [
        'durum' => CRON_DURUM_HATA,
        'sonuc' => 'Mail gönderilemedi: ' . ($sonuc['message'] ?? 'bilinmeyen hata'),
        'cikti' => ob_get_clean(),
    ];
}
