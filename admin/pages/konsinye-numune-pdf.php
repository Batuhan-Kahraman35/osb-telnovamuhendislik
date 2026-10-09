<?php
/**
 * Numune / Konsinye Teslim Formu - PDF Çıktısı
 * Aynı fişe ait kalemler (cari + oluşturan + OlusturmaTarihi) tek forma basılır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/CariDevirHelper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Yetki: Konsinye & Numune sayfası yetkisi ile aynı
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    'konsinye-numune.php'
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$knId = intval($_GET['id'] ?? 0);
if ($knId <= 0) {
    die('Geçersiz kayıt ID.');
}

// Tıklanan kaydı çek (fiş anahtarını bulmak için)
$anaKayit = $db->fetchOne("
    SELECT kn_id, kn_tip, kn_cari_id, kn_tarih, kn_aciklama, OlusturanKullanici, OlusturmaTarihi
    FROM UrunKonsinye_Numune
    WHERE kn_id = ? AND Durum = 1
", [$knId]);

if (!$anaKayit) {
    die('Kayıt bulunamadı.');
}

// Sadece kendi kayıtlarını görebilen kullanıcı kontrolü
// (kendisine devredilen carilerin kayıtları da görülebilir)
if (!empty($pagePermissions['can_view_own_records'])
    && (int)$anaKayit['OlusturanKullanici'] !== (int)$user['kullanici_id']
    && !in_array((int)$anaKayit['kn_cari_id'], CariDevirHelper::getCariIdler((int)$user['kullanici_id']), true)) {
    PageAuth::accessDenied('Bu kaydı görüntüleme yetkiniz bulunmamaktadır.');
}

// Aynı fişe ait tüm kalemler
$kalemler = $db->fetchAll("
    SELECT
        kn.kn_id,
        kn.kn_miktar,
        kn.kn_aciklama,
        CONVERT(VARCHAR(10), kn.kn_tarih, 104) as tarih_fmt,
        u.urun_hizmet_adi,
        u.urun_hizmet_kodu,
        u.urun_hizmet_model,
        u.urun_hizmet_beden,
        u.urun_hizmet_renk,
        u.urun_hizmet_birim
    FROM UrunKonsinye_Numune kn
    LEFT JOIN Urun_Hizmet u ON kn.kn_urun_id = u.urun_hizmet_id
    WHERE kn.Durum = 1
      AND kn.kn_cari_id = ?
      AND kn.OlusturanKullanici = ?
      AND kn.OlusturmaTarihi = ?
      AND kn.kn_tip = ?
    ORDER BY kn.kn_id
", [
    $anaKayit['kn_cari_id'],
    $anaKayit['OlusturanKullanici'],
    $anaKayit['OlusturmaTarihi'],
    $anaKayit['kn_tip']
]);

if (empty($kalemler)) {
    die('Fişe ait kalem bulunamadı.');
}

// Cari bilgileri: firma adı ve adres otomatik basılır (talep #239).
// Telefon/e-posta ve teslim eden-alan alanları form üzerinde elle doldurulur.
$cari = $db->fetchOne("
    SELECT
        ISNULL(c.cari_unvan, c.cari_adi) as cari_ad,
        c.cari_adres,
        i.IlceAdi,
        s.SehirAdi
    FROM Cari c
    LEFT JOIN Adres_Ilceler  i ON c.cari_ilceler  = i.ilceId
    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
    WHERE c.cari_id = ?
", [$anaKayit['kn_cari_id']]);

$cariAd = trim((string)($cari['cari_ad'] ?? ''));

// Adres: açık adres + ilçe / şehir
$adresParcalari = [];
$acikAdres = trim((string)($cari['cari_adres'] ?? ''));
if ($acikAdres !== '') {
    $adresParcalari[] = preg_replace('/\s+/u', ' ', $acikAdres);
}
$ilceSehir = array_filter([
    trim((string)($cari['IlceAdi'] ?? '')),
    trim((string)($cari['SehirAdi'] ?? '')),
], fn($v) => $v !== '');
if ($ilceSehir) {
    $adresParcalari[] = implode(' / ', $ilceSehir);
}
$cariAdres = implode(' - ', $adresParcalari);

// Logo (site ayarlarından)
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_logo_url, site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");

$logoDiskYolu = null;
$logoUrlRaw   = trim((string)($siteAyarlari['site_ayarlari_logo_url'] ?? ''));

if ($logoUrlRaw !== '' && strpos($logoUrlRaw, 'http') !== 0) {
    // sidebar.php ile aynı yol çözümleme mantığı, disk yoluna çevrilmiş hali
    if (strpos($logoUrlRaw, 'assets/') === 0) {
        $rel = $logoUrlRaw;
    } elseif (strpos($logoUrlRaw, '/') !== 0) {
        $rel = 'assets/' . $logoUrlRaw;
    } else {
        $rel = ltrim(preg_replace('#^/admin/#i', '', $logoUrlRaw), '/');
    }
    $aday = __DIR__ . '/../' . $rel;
    if (is_file($aday)) {
        $logoDiskYolu = realpath($aday);
    }
}

// Fiş No: gruptaki en küçük kn_id üzerinden türetilir
$fisKnId  = (int)$kalemler[0]['kn_id'];
$tipKisa  = ($anaKayit['kn_tip'] === 'KONSINYE') ? 'KON' : 'NUM';
$fisYil   = date('Y', strtotime($anaKayit['kn_tarih']));
$fisNo    = $tipKisa . '-' . $fisYil . '-' . str_pad((string)$fisKnId, 4, '0', STR_PAD_LEFT);
$formBaslik = ($anaKayit['kn_tip'] === 'KONSINYE') ? 'KONSİNYE TESLİM FORMU' : 'NUMUNE TESLİM FORMU';
$blokBaslik = ($anaKayit['kn_tip'] === 'KONSINYE') ? 'KONSİNYE BİLGİLERİ' : 'NUMUNE BİLGİLERİ';
$kodBaslik  = ($anaKayit['kn_tip'] === 'KONSINYE') ? 'Konsinye Kodu' : 'Numune Kodu';

// Kalem sayısı fazlaysa yazı tipi küçültülüp tek sayfada kalması denenir
$kalemSayisi = count($kalemler);
$kalemFont   = $kalemSayisi > 18 ? '7.5px' : ($kalemSayisi > 12 ? '8px' : '8.5px');
$kalemPad    = $kalemSayisi > 12 ? '3px 4px' : '5px 4px';

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Bilgi tablosu hücresi: değer varsa basar, yoksa elle doldurulacak noktalı satır
function dolguHucre(string $deger): string {
    return $deger !== ''
        ? '<td>' . e($deger) . '</td>'
        : '<td class="cizgi">.............................................................</td>';
}

// Kalem açıklaması: model / beden / renk bilgilerini de göster
function kalemAciklama(array $k): string {
    $parcalar = [];
    foreach (['urun_hizmet_model' => 'Model', 'urun_hizmet_beden' => 'Beden', 'urun_hizmet_renk' => 'Renk'] as $kolon => $etiket) {
        $deger = trim((string)($k[$kolon] ?? ''));
        if ($deger !== '') {
            $parcalar[] = $etiket . ': ' . $deger;
        }
    }
    $aciklama = trim((string)($k['kn_aciklama'] ?? ''));
    if ($aciklama !== '') {
        $parcalar[] = $aciklama;
    }
    return implode(' / ', $parcalar);
}

$logoHtml = $logoDiskYolu
    ? '<img src="' . e($logoDiskYolu) . '" class="logo-buyuk" alt="logo">'
    : '<div class="logo-yazi">' . e($siteAyarlari['site_ayarlari_site_title'] ?? '') . '</div>';

$html = '<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<style>
    /* Üst boşluk: 2. ve sonraki sayfalara basılan logo için yer bırakır */
    @page { margin: 46px 30px 22px 30px; }
    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 10px;
        color: #000;
        margin: 0;
    }
    .logo-alani { text-align: center; margin-bottom: 2px; }
    .logo-buyuk { height: 84px; }
    .logo-yazi { font-size: 18px; font-weight: bold; letter-spacing: 1px; }

    h1.form-baslik {
        text-align: center;
        font-size: 14px;
        font-weight: bold;
        letter-spacing: 1.5px;
        margin: 2px 0 10px 0;
    }
    h2.blok-baslik {
        font-size: 11px;
        font-weight: normal;
        margin: 12px 0 4px 0;
    }
    .fis-bilgi {
        width: 100%;
        margin-bottom: 8px;
        font-size: 9.5px;
    }
    .fis-bilgi td { padding: 0; }
    .fis-bilgi .sag { text-align: right; }

    table.bilgi {
        width: 78%;
        border-collapse: collapse;
        margin: 0 auto 4px auto;
    }
    table.bilgi td {
        border: 1px solid #1f3864;
        padding: 4px 7px;
        font-size: 9.5px;
    }
    table.bilgi td.etiket {
        width: 32%;
        background: #f2f2f2;
        font-weight: normal;
    }

    table.kalem {
        width: 100%;
        border-collapse: collapse;
        margin-top: 4px;
    }
    table.kalem th {
        border: 1px solid #1f3864;
        background: #dce6f1;
        padding: ' . $kalemPad . ';
        font-size: ' . $kalemFont . ';
        font-weight: bold;
        text-align: left;
    }
    table.kalem td {
        border: 1px solid #1f3864;
        padding: ' . $kalemPad . ';
        font-size: ' . $kalemFont . ';
    }
    .ortala { text-align: center; }

    .teslim-sekli {
        border: 1px solid #1f3864;
        background: #f2f2f2;
        padding: 6px 9px;
        font-size: 9.5px;
        margin-bottom: 12px;
    }
    .kutu { font-size: 11px; }

    .teslim-blok { page-break-inside: avoid; }

    table.imza {
        width: 100%;
        border-collapse: collapse;
        page-break-inside: avoid;
    }
    table.imza th {
        border: 1px solid #1f3864;
        background: #dce6f1;
        padding: 6px 8px;
        font-size: 10px;
        text-align: left;
        width: 50%;
    }
    table.imza td {
        border: 1px solid #1f3864;
        padding: 7px 8px;
        font-size: 9.5px;
        height: 28px;
        vertical-align: top;
    }
    .cizgi { color: #555; }
    .dipnot {
        margin-top: 14px;
        font-size: 8px;
        color: #555;
        text-align: center;
    }
</style>
</head>
<body>

<!-- ================= ÜST BLOK ================= -->
<div>

    <div class="logo-alani">' . $logoHtml . '</div>

    <h1 class="form-baslik">' . e($formBaslik) . '</h1>

    <table class="fis-bilgi">
        <tr>
            <td><b>Fiş No:</b> ' . e($fisNo) . '</td>
            <td class="sag"><b>Tarih:</b> ' . e($kalemler[0]['tarih_fmt']) . '</td>
        </tr>
    </table>

    <table class="bilgi">
        <tr>
            <td class="etiket">Firma Adı:</td>
            ' . dolguHucre($cariAd) . '
        </tr>
        <tr>
            <td class="etiket">Adres:</td>
            ' . dolguHucre($cariAdres) . '
        </tr>
        <tr>
            <td class="etiket">Telefon:</td>
            <td class="cizgi">.............................................................</td>
        </tr>
        <tr>
            <td class="etiket">E-posta:</td>
            <td class="cizgi">.............................................................</td>
        </tr>
    </table>

    <h2 class="blok-baslik">' . e($blokBaslik) . '</h2>

    <table class="kalem">
        <thead>
            <tr>
                <th style="width:12%">Tarih</th>
                <th style="width:20%">' . e($kodBaslik) . '</th>
                <th style="width:38%">Ürün Adı</th>
                <th style="width:8%" class="ortala">Adet</th>
                <th style="width:22%">Açıklama</th>
            </tr>
        </thead>
        <tbody>';

foreach ($kalemler as $k) {
    $html .= '
            <tr>
                <td>' . e($k['tarih_fmt']) . '</td>
                <td>' . e($k['urun_hizmet_kodu'] ?? '') . '</td>
                <td>' . e($k['urun_hizmet_adi'] ?? '') . '</td>
                <td class="ortala">' . e($k['kn_miktar']) . '</td>
                <td>' . e(kalemAciklama($k)) . '</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>

</div>

<!-- ================= TESLİM BLOĞU ================= -->
<div class="teslim-blok">

    <h2 class="blok-baslik">TESLİM ALAN BİLGİLERİ</h2>

    <table class="bilgi" style="width:70%; margin-left:0;">
        <tr>
            <td class="etiket">Firma / Kişi Adı:</td>
            <td class="cizgi">.......................................................</td>
        </tr>
        <tr>
            <td class="etiket">Yetkili:</td>
            <td class="cizgi">.......................................................</td>
        </tr>
        <tr>
            <td class="etiket">Telefon:</td>
            <td class="cizgi">.......................................................</td>
        </tr>
    </table>

    <h2 class="blok-baslik">TESLİM ŞEKLİ</h2>

    <div class="teslim-sekli">
        <span class="kutu">&#9744;</span> Elden Teslim &nbsp;&nbsp;&nbsp;
        <span class="kutu">&#9744;</span> Kargo &nbsp;&nbsp;&nbsp;
        <span class="kutu">&#9744;</span> Kurye &nbsp;&nbsp;&nbsp;
        <span class="kutu">&#9744;</span> Diğer: ...............................
    </div>

    <table class="imza">
        <thead>
            <tr>
                <th>Teslim Eden</th>
                <th>Teslim Alan</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Ad Soyad: ..........................................</td>
                <td>Ad Soyad: ..........................................</td>
            </tr>
            <tr>
                <td>İmza:</td>
                <td>İmza:</td>
            </tr>
            <tr>
                <td>Tarih: ..........................................</td>
                <td>Tarih: ..........................................</td>
            </tr>
        </tbody>
    </table>

    <div class="dipnot">
        Yukarıda belirtilen ürünler eksiksiz ve hasarsız olarak teslim alınmıştır.<br>
        Bu form sistem üzerinden üretilmiştir. Fiş No: ' . e($fisNo) . ' &nbsp;|&nbsp; Toplam Kalem: ' . $kalemSayisi . '
    </div>

</div>

</body>
</html>';

// DOMPDF ile PDF oluştur
$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->setChroot(__DIR__ . '/../../');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// 2. ve sonraki sayfaların sağ üstüne logo bas (1. sayfada zaten büyük logo var)
if ($logoDiskYolu) {
    $olcu    = @getimagesize($logoDiskYolu);
    $oran    = ($olcu && !empty($olcu[0])) ? $olcu[1] / $olcu[0] : 0.4;
    $logoGen = 58;                       // pt
    $logoYuk = $logoGen * $oran;
    $sagBosl = 22.5;                     // pt (@page sağ margin karşılığı)

    $canvas = $dompdf->getCanvas();
    $canvas->page_script(
        function ($pageNumber, $pageCount, $canvas) use ($logoDiskYolu, $logoGen, $logoYuk, $sagBosl) {
            if ($pageNumber > 1) {
                $canvas->image(
                    $logoDiskYolu,
                    $canvas->get_width() - $sagBosl - $logoGen,
                    8,
                    $logoGen,
                    $logoYuk
                );
            }
        }
    );
}

$dosyaAdi = $fisNo . '.pdf';
$dosyaAdi = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $dosyaAdi);

// Tarayıcıda aç (yazdırmaya uygun)
$dompdf->stream($dosyaAdi, ['Attachment' => false]);
exit;
