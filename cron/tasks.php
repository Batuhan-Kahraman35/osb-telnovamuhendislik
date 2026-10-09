<?php
/**
 * Merkezi Cron Sistemi - Çekirdek
 *
 * Cron ifade ayrıştırıcı, dinamik tarih çözücü, log yardımcıları,
 * overlap koruması, catch-up (telafi) ve görev dispatcher'ı.
 *
 * Kullanan dosyalar:
 *   cron/runner.php               -> Plesk'in dakikada 1 çağırdığı tek giriş noktası
 *   cron/worker.php               -> Tekil görev/zamanlama tetikleyici
 *   admin/pages/cron-yonetimi.php -> Panel (manuel çalıştır)
 *
 * Görev dosyaları: cron/gorevler/<gorev-kodu>.php
 *   Görev kodu   : toplu_mail_gonder
 *   Dosya adı    : toplu-mail-gonder.php
 *   Fonksiyon adı: gorev_toplu_mail_gonder(array $params, $db): array
 *
 * @see temp/cron-sistemi-tasima.md (v2)
 */

require_once __DIR__ . '/../admin/db.php';

date_default_timezone_set('Europe/Istanbul');

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// Çalışma durumu sabitleri (CronCalismaLog_CalismaDurum)
const CRON_DURUM_CALISIYOR = 0;
const CRON_DURUM_BASARILI  = 1;
const CRON_DURUM_HATA      = 2;

// Tetikleyen türü (CronCalismaLog_TetikleyenTur)
const CRON_TETIK_MANUEL   = 0;
const CRON_TETIK_OTOMATIK = 1;


// ═══════════════════════════════════════════════════════════
// KONSOL ÇIKTISI
// ═══════════════════════════════════════════════════════════

/**
 * CLI çıktısı yazar.
 *
 * Plesk'in "Scheduled Tasks" çıktı ekranı ve bildirim mailleri UTF-8'i
 * Windows kod sayfası olarak yorumluyor; Türkçe karakterler "BAŞLA" yerine
 * "BAÅžLA" gibi görünüyor. Görev hata verdiğinde gelen bildirim mailinin
 * okunabilir olması için CLI çıktısında harfler ASCII karşılıklarına çevrilir.
 *
 * Bu yalnızca konsol çıktısını etkiler — CronCalismaLog'a yazılan Sonuc ve
 * Cikti alanları ile panel gösterimi tam Türkçe kalır.
 */
function cronYaz(string $metin): void
{
    if (PHP_SAPI === 'cli') {
        $metin = strtr($metin, [
            'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U',
            '—' => '-', '–' => '-', '’' => "'", '“' => '"', '”' => '"', '…' => '...',
        ]);
    }

    echo $metin;
}


// ═══════════════════════════════════════════════════════════
// AYARLAR
// ═══════════════════════════════════════════════════════════

/**
 * CronAyarlar tablosundan ayar okur (istek başına cache'lenir).
 */
function cronAyar($db, string $anahtar, ?string $varsayilan = null): ?string
{
    static $cache = [];

    if (array_key_exists($anahtar, $cache)) {
        return $cache[$anahtar] ?? $varsayilan;
    }

    $satir = $db->fetchOne("
        SELECT CronAyarlar_Deger
        FROM dbo.CronAyarlar
        WHERE CronAyarlar_Anahtar = ? AND Durum = 1
    ", [$anahtar]);

    $cache[$anahtar] = $satir['CronAyarlar_Deger'] ?? null;

    return $cache[$anahtar] ?? $varsayilan;
}

/**
 * Web erişiminde ?key= parametresini doğrular. CLI'da kontrol yapılmaz.
 * Başarısızsa 403 ile script sonlanır.
 */
function cronKeyDogrula($db): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $beklenen = (string)cronAyar($db, 'cron_secret_key', '');
    $gelen    = (string)($_GET['key'] ?? '');

    if ($beklenen === '' || !hash_equals($beklenen, $gelen)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("403 - Yetkisiz erisim.\n");
    }
}


// ═══════════════════════════════════════════════════════════
// CRON İFADE AYRIŞTIRICI
// ═══════════════════════════════════════════════════════════

/**
 * 5 alanlı cron ifadesi verilen ana uyuyor mu?
 * Desteklenen: *  |  5  |  0,30  |  1-5  |  * / 15  |  10-50/5
 * Desteklenmeyen: MON/JAN kısaltmaları, L/W/# uzantıları, 6 alanlı (saniyeli) ifade
 */
function cronEslesiyor(string $ifade, DateTime $dt): bool
{
    $parts = preg_split('/\s+/', trim($ifade));

    if (!is_array($parts) || count($parts) !== 5) {
        return false;
    }

    [$dk, $sa, $gun, $ay, $hgn] = $parts;

    return cronAlanEslesiyor($dk,  (int)$dt->format('i'))
        && cronAlanEslesiyor($sa,  (int)$dt->format('G'))
        && cronAlanEslesiyor($gun, (int)$dt->format('j'))
        && cronAlanEslesiyor($ay,  (int)$dt->format('n'))
        && cronAlanEslesiyor($hgn, (int)$dt->format('w'));   // 0 = Pazar
}

function cronAlanEslesiyor(string $alan, int $deger): bool
{
    $alan = trim($alan);

    if ($alan === '*' || $alan === '?') {
        return true;
    }

    foreach (explode(',', $alan) as $parca) {
        $parca = trim($parca);

        if ($parca === '') {
            continue;
        }

        if (str_contains($parca, '/')) {
            [$aralik, $adim] = explode('/', $parca, 2);
            $adim = (int)$adim;

            if ($adim < 1) {
                continue;
            }

            if ($aralik === '*' || $aralik === '') {
                $bas = 0;
                $son = 59;
            } elseif (str_contains($aralik, '-')) {
                [$bas, $son] = array_map('intval', explode('-', $aralik, 2));
            } else {
                $bas = (int)$aralik;
                $son = 59;
            }

            if ($deger >= $bas && $deger <= $son && ($deger - $bas) % $adim === 0) {
                return true;
            }
        } elseif (str_contains($parca, '-')) {
            [$bas, $son] = array_map('intval', explode('-', $parca, 2));

            if ($deger >= $bas && $deger <= $son) {
                return true;
            }
        } elseif ((int)$parca === $deger) {
            return true;
        }
    }

    return false;
}

/**
 * Cron ifadesinin geçerliliğini kontrol eder (panel doğrulaması için).
 * @return string|null Hata mesajı, geçerliyse null
 */
function cronIfadeDogrula(string $ifade): ?string
{
    $parts = preg_split('/\s+/', trim($ifade));

    if (!is_array($parts) || count($parts) !== 5) {
        return 'Cron ifadesi 5 alandan oluşmalıdır (dakika saat gün ay haftagünü).';
    }

    $sinirlar = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    $adlar    = ['Dakika', 'Saat', 'Ayın Günü', 'Ay', 'Haftanın Günü'];

    foreach ($parts as $i => $alan) {
        if (!preg_match('/^(\*|\?|[0-9]+)([-\/][0-9]+)?((,[0-9]+([-\/][0-9]+)?)*)$|^\*\/[0-9]+$/', $alan)) {
            return $adlar[$i] . ' alanı geçersiz: ' . $alan;
        }

        // Sayısal değerlerin aralık kontrolü
        preg_match_all('/[0-9]+/', explode('/', $alan)[0], $sayilar);

        foreach ($sayilar[0] as $sayi) {
            if ((int)$sayi < $sinirlar[$i][0] || (int)$sayi > $sinirlar[$i][1]) {
                return $adlar[$i] . ' alanı ' . $sinirlar[$i][0] . '-' . $sinirlar[$i][1] . ' aralığında olmalıdır.';
            }
        }
    }

    return null;
}

/**
 * Cron ifadesinden sonraki çalışma zamanını bulur (panelde göstermek için).
 * Bulunamazsa null döner.
 *
 * Dakika dakika taramak yerine eşleşmeyen alanı bir üst birimden atlar:
 * ay tutmuyorsa sonraki ayın 1'ine, gün tutmuyorsa ertesi güne, saat
 * tutmuyorsa sonraki saate sıçranır. Böylece "0 0 29 2 *" (29 Şubat) gibi
 * nadir ifadeler saniyeler yerine milisaniyelerde çözülür.
 *
 * Tarama penceresi 2 yıldır — artık yıla denk gelen ifadelerin bulunabilmesi için
 * 1 yıl yetersizdir.
 */
function cronSonrakiCalisma(string $ifade, ?DateTime $baslangic = null): ?DateTime
{
    $parts = preg_split('/\s+/', trim($ifade));

    if (!is_array($parts) || count($parts) !== 5) {
        return null;
    }

    [$dk, $sa, $gun, $ay, $hgn] = $parts;

    $dt = $baslangic ? clone $baslangic : new DateTime();
    $dt->setTime((int)$dt->format('G'), (int)$dt->format('i'), 0);
    $dt->modify('+1 minute');

    $limit  = (clone $dt)->modify('+2 years');
    $koruma = 0;

    while ($dt < $limit && $koruma++ < 500000) {
        if (!cronAlanEslesiyor($ay, (int)$dt->format('n'))) {
            $dt->modify('first day of next month')->setTime(0, 0, 0);
            continue;
        }

        // Gün ve hafta günü AND ile değerlendirilir (cronEslesiyor ile aynı mantık)
        if (!cronAlanEslesiyor($gun, (int)$dt->format('j'))
            || !cronAlanEslesiyor($hgn, (int)$dt->format('w'))) {
            $dt->modify('+1 day')->setTime(0, 0, 0);
            continue;
        }

        if (!cronAlanEslesiyor($sa, (int)$dt->format('G'))) {
            $dt->modify('+1 hour')->setTime((int)$dt->format('G'), 0, 0);
            continue;
        }

        if (!cronAlanEslesiyor($dk, (int)$dt->format('i'))) {
            $dt->modify('+1 minute');
            continue;
        }

        return $dt;
    }

    return null;
}


// ═══════════════════════════════════════════════════════════
// DİNAMİK TARİH PARAMETRELERİ
// ═══════════════════════════════════════════════════════════

/**
 * {bugun}, {dun} gibi yer tutucuları çalışma anındaki tarihe çevirir.
 */
function dinamikTarih(string $deger): string
{
    $bugun = new DateTime();

    $map = [
        '{bugun}'         => fn() => $bugun->format('d.m.Y'),
        '{dun}'           => fn() => (clone $bugun)->modify('-1 day')->format('d.m.Y'),
        '{yarin}'         => fn() => (clone $bugun)->modify('+1 day')->format('d.m.Y'),
        '{7gun_once}'     => fn() => (clone $bugun)->modify('-7 days')->format('d.m.Y'),
        '{30gun_once}'    => fn() => (clone $bugun)->modify('-30 days')->format('d.m.Y'),
        '{ay_basi}'       => fn() => $bugun->format('01.m.Y'),
        '{ay_sonu}'       => fn() => (new DateTime('last day of this month'))->format('d.m.Y'),
        '{gecen_ay_basi}' => fn() => (new DateTime('first day of last month'))->format('d.m.Y'),
        '{gecen_ay_sonu}' => fn() => (new DateTime('last day of last month'))->format('d.m.Y'),
        '{3ay_once}'      => fn() => (clone $bugun)->modify('-3 months')->format('d.m.Y'),
        '{6ay_once}'      => fn() => (clone $bugun)->modify('-6 months')->format('d.m.Y'),
        '{yil_basi}'      => fn() => $bugun->format('01.01.Y'),
    ];

    return isset($map[$deger]) ? ($map[$deger])() : $deger;
}

function dinamikParamCoz(array $params): array
{
    return array_map(fn($v) => is_string($v) ? dinamikTarih($v) : $v, $params);
}

/**
 * "1,2,5" biçimindeki kullanıcı ID listesini güncel e-posta adreslerine çevirir.
 *
 * Zamanlayıcıda ID saklanır, adres çalışma anında okunur: kullanıcı e-postasını
 * değiştirdiğinde veya pasife alındığında zamanlayıcıya dokunmak gerekmez.
 * Pasif kullanıcılar ve e-postası boş olanlar listeye alınmaz.
 *
 * @return array [email => 'Ad Soyad'] — EntegrasyonHelper::mailGonder() bu yapıyı bekler
 */
function cronKullaniciMailleri($db, ?string $idListesi): array
{
    $idler = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)$idListesi))
    )));

    if (!$idler) {
        return [];
    }

    $yerTutucu = implode(',', array_fill(0, count($idler), '?'));

    $satirlar = $db->fetchAll("
        SELECT kullanici_email, kullanici_ad + ' ' + kullanici_soyad AS AdSoyad
        FROM kullanicilar
        WHERE kullanici_id IN ({$yerTutucu})
          AND kullanici_durum = 1
          AND kullanici_email IS NOT NULL
          AND kullanici_email <> ''
        ORDER BY kullanici_ad
    ", $idler);

    $liste = [];

    foreach ($satirlar as $s) {
        $liste[$s['kullanici_email']] = $s['AdSoyad'];
    }

    return $liste;
}

/**
 * Panelde gösterilecek yer tutucu listesi (statik liste sayfada tekrarlanmasın).
 */
function dinamikTarihListesi(): array
{
    return [
        '{bugun}'         => 'Bugün',
        '{dun}'           => 'Dün',
        '{yarin}'         => 'Yarın',
        '{7gun_once}'     => '7 gün önce',
        '{30gun_once}'    => '30 gün önce',
        '{ay_basi}'       => 'Ayın 1\'i',
        '{ay_sonu}'       => 'Ay sonu',
        '{gecen_ay_basi}' => 'Geçen ay başı',
        '{gecen_ay_sonu}' => 'Geçen ay sonu',
        '{3ay_once}'      => '3 ay önce',
        '{6ay_once}'      => '6 ay önce',
        '{yil_basi}'      => 'Yıl başı',
    ];
}


// ═══════════════════════════════════════════════════════════
// LOG YARDIMCILARI
// ═══════════════════════════════════════════════════════════

function cronLogOlustur($db, int $gorevId, ?int $zamanlamaId, array $params, int $tetikTur, ?int $kullanici = null): int
{
    $simdi = date('Y-m-d H:i:s');

    return (int)$db->insert('CronCalismaLog', [
        'CronCalismaLog_GorevId'             => $gorevId,
        'CronCalismaLog_ZamanlamaId'         => $zamanlamaId,
        'CronCalismaLog_BaslangicTarihi'     => $simdi,
        'CronCalismaLog_Parametreler'        => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        'CronCalismaLog_CalismaDurum'        => CRON_DURUM_CALISIYOR,
        'CronCalismaLog_TetikleyenTur'       => $tetikTur,
        'CronCalismaLog_TetikleyenKullanici' => $kullanici,
        'OlusturanKullanici'                 => $kullanici ?? 0,
        'OlusturmaTarihi'                    => $simdi,
        'GuncelleyenKullanici'               => $kullanici ?? 0,
        'GuncellemeTarihi'                   => $simdi,
        'Durum'                              => 1,
    ]);
}

function cronLogBitir($db, int $logId, int $durum, string $sonuc, float $basZaman, string $cikti = ''): void
{
    $simdi = date('Y-m-d H:i:s');

    $db->update('CronCalismaLog', [
        'CronCalismaLog_BitisTarihi'  => $simdi,
        'CronCalismaLog_SureSaniye'   => (int)(microtime(true) - $basZaman),
        'CronCalismaLog_CalismaDurum' => $durum,
        'CronCalismaLog_Sonuc'        => mb_substr($sonuc, 0, 2000),
        'CronCalismaLog_Cikti'        => $cikti !== '' ? mb_substr($cikti, 0, 100000) : null,
        'GuncelleyenKullanici'        => 0,
        'GuncellemeTarihi'            => $simdi,
    ], ['CronCalismaLog_Id' => $logId]);
}


// ═══════════════════════════════════════════════════════════
// OVERLAP KORUMASI
// ═══════════════════════════════════════════════════════════

/**
 * Görev şu anda çalışıyor mu? Çalışıyorsa tetik atlanmalıdır.
 *
 * Zombi kayıtlar (process çökmüş, log CalismaDurum=0 takılı kalmış)
 * MaxSureSn x 1.5 eşiğiyle otomatik kapatılır ve yol açılır.
 *
 * NOT: Süre farkı SQL tarafında DATEDIFF ile hesaplanır. sqlsrv sürücüsü
 *      DATETIME kolonlarını PHP DateTime NESNESİ döndürdüğü için strtotime()
 *      çalışmaz; ayrıca DB ve web sunucusu farklı makinelerdeyse saat kayması
 *      hesabı bozar.
 *
 * @return array|null Çalışıyorsa ['LogId'=>..,'GecenSaniye'=>..,'BaslangicMetin'=>..], değilse null
 */
function cronCalisanKayit($db, int $gorevId, int $maxSureSn): ?array
{
    $kayit = $db->fetchOne("
        SELECT TOP 1
            CronCalismaLog_Id AS LogId,
            DATEDIFF(SECOND, CronCalismaLog_BaslangicTarihi, GETDATE()) AS GecenSaniye,
            CONVERT(VARCHAR(19), CronCalismaLog_BaslangicTarihi, 120) AS BaslangicMetin
        FROM dbo.CronCalismaLog
        WHERE CronCalismaLog_GorevId = ?
          AND CronCalismaLog_CalismaDurum = ?
        ORDER BY CronCalismaLog_Id DESC
    ", [$gorevId, CRON_DURUM_CALISIYOR]);

    if (!$kayit) {
        return null;
    }

    // Zombi eşiği: görevin kendi max süresinin 1.5 katı (en az 120 sn)
    $zombiEsik   = max(120, (int)($maxSureSn * 1.5));
    $gecenSaniye = (int)$kayit['GecenSaniye'];

    if ($gecenSaniye > $zombiEsik) {
        $simdi = date('Y-m-d H:i:s');

        $db->update('CronCalismaLog', [
            'CronCalismaLog_BitisTarihi'  => $simdi,
            'CronCalismaLog_SureSaniye'   => $gecenSaniye,
            'CronCalismaLog_CalismaDurum' => CRON_DURUM_HATA,
            'CronCalismaLog_Sonuc'        => 'Zombi kayıt: ' . $zombiEsik . ' sn eşiği aşıldı, otomatik kapatıldı. '
                                           . 'Görev süreci beklenmedik şekilde sonlanmış olabilir.',
            'GuncelleyenKullanici'        => 0,
            'GuncellemeTarihi'            => $simdi,
        ], ['CronCalismaLog_Id' => (int)$kayit['LogId']]);

        return null;   // yol açıldı
    }

    return [
        'LogId'          => (int)$kayit['LogId'],
        'GecenSaniye'    => $gecenSaniye,
        'BaslangicMetin' => (string)$kayit['BaslangicMetin'],
    ];
}

function cronCalisiyorMu($db, int $gorevId, int $maxSureSn): bool
{
    return cronCalisanKayit($db, $gorevId, $maxSureSn) !== null;
}


// ═══════════════════════════════════════════════════════════
// CATCH-UP (TELAFİ)
// ═══════════════════════════════════════════════════════════

/**
 * Son çalışmadan bu yana kaçırılmış bir tetik var mı?
 *
 * Kaç tetik kaçmış olursa olsun EN FAZLA BİR KEZ telafi edilir; sunucu 3 gün
 * kapalı kaldıysa 3 günlük raporu arka arkaya göndermenin değeri yok, zararı var.
 *
 * @param string|null $sonCalisma 'Y-m-d H:i:s' formatında metin (SQL'de CONVERT ..., 120)
 * @param int         $sinirSaat  Kaç saat geriye kadar telafi edilsin
 */
function cronKacirilanTetik(string $ifade, ?string $sonCalisma, int $sinirSaat, DateTime $simdi): bool
{
    // Hiç çalışmamış zamanlamada telafi YAPILMAZ.
    // "Hiç çalışmamış" ≠ "tetik kaçırmış": referans noktası olmadan geriye
    // bakmak, yeni kurulan/yeni eklenen her zamanlamayı kurulumdan hemen sonra
    // tetikler. Örn. saat 13:00'te eklenen "0 10 * * *" zamanlaması, 6 saatlik
    // sınır içinde 10:00'ı görüp o günün raporunu ikinci kez gönderirdi.
    // İlk çalışma normal cron eşleşmesiyle olur, sonrakiler telafi kapsamına girer.
    if ($sonCalisma === null || $sonCalisma === '') {
        return false;
    }

    if ($sinirSaat < 1) {
        $sinirSaat = 1;
    }

    $enErken = (clone $simdi)->modify("-{$sinirSaat} hours");

    $tarama = new DateTime($sonCalisma);
    $tarama->setTime((int)$tarama->format('G'), (int)$tarama->format('i'), 0);
    $tarama->modify('+1 minute');

    // Çok eski SonCalisma değerlerinde döngüyü sınırla
    if ($tarama < $enErken) {
        $tarama = clone $enErken;
        $tarama->setTime((int)$tarama->format('G'), (int)$tarama->format('i'), 0);
    }

    $simdiDakika = (clone $simdi)->setTime((int)$simdi->format('G'), (int)$simdi->format('i'), 0);

    $maxAdim = ($sinirSaat * 60) + 1;
    $adim    = 0;

    while ($tarama < $simdiDakika && $adim < $maxAdim) {
        if (cronEslesiyor($ifade, $tarama)) {
            return true;
        }
        $tarama->modify('+1 minute');
        $adim++;
    }

    return false;
}


// ═══════════════════════════════════════════════════════════
// GÖREV DISPATCHER
// ═══════════════════════════════════════════════════════════

/**
 * Görev kodunu ilgili dosya + fonksiyona yönlendirir.
 *
 * Konvansiyon (kodda görev listesi TUTULMAZ, yeni görev = yeni dosya):
 *   kod      : toplu_mail_gonder
 *   dosya    : cron/gorevler/toplu-mail-gonder.php
 *   fonksiyon: gorev_toplu_mail_gonder(array $params, $db): array
 *
 * @return array ['durum' => 1|2, 'sonuc' => string, 'cikti' => string]
 */
function gorevCalistir(string $gorevKodu, array $params, $db): array
{
    // Path traversal koruması — kod yalnızca küçük harf, rakam ve alt çizgi içerebilir
    if (!preg_match('/^[a-z0-9_]+$/', $gorevKodu)) {
        throw new RuntimeException('Geçersiz görev kodu: ' . $gorevKodu);
    }

    $dosya = __DIR__ . '/gorevler/' . str_replace('_', '-', $gorevKodu) . '.php';

    if (!is_file($dosya)) {
        throw new RuntimeException('Görev dosyası bulunamadı: gorevler/' . basename($dosya));
    }

    require_once $dosya;

    $fonksiyon = 'gorev_' . $gorevKodu;

    if (!function_exists($fonksiyon)) {
        throw new RuntimeException('Görev fonksiyonu bulunamadı: ' . $fonksiyon . '()');
    }

    $sonuc = $fonksiyon($params, $db);

    if (!is_array($sonuc)) {
        throw new RuntimeException($fonksiyon . '() beklenen dizi yapısını döndürmedi.');
    }

    return [
        'durum' => (int)($sonuc['durum'] ?? CRON_DURUM_BASARILI),
        'sonuc' => (string)($sonuc['sonuc'] ?? ''),
        'cikti' => (string)($sonuc['cikti'] ?? ''),
    ];
}

/**
 * Görevi overlap kontrolü + log açma/kapatma ile birlikte çalıştırır.
 * runner.php, worker.php ve panel bu tek fonksiyonu kullanır.
 *
 * @param array $gorev CronGorevler satırı (Id, GorevKodu, MaxSureSn)
 * @return array ['durum','sonuc','cikti','log_id','atlandi']
 */
function cronCalistirVeLogla(
    $db,
    array $gorev,
    ?int $zamanlamaId,
    array $params,
    int $tetikTur,
    ?int $kullaniciId = null,
    bool $telafi = false
): array {
    $gorevId = (int)$gorev['CronGorevler_Id'];
    $maxSure = (int)($gorev['CronGorevler_MaxSureSn'] ?? 600);

    // Overlap koruması — SonCalisma güncellemesinden SONRA, göreve girmeden ÖNCE
    $calisan = cronCalisanKayit($db, $gorevId, $maxSure);

    if ($calisan !== null) {
        return [
            'durum'   => CRON_DURUM_CALISIYOR,
            'sonuc'   => 'Bu görev şu anda zaten çalışıyor (başlangıç: '
                       . $calisan['BaslangicMetin'] . ', ' . $calisan['GecenSaniye'] . ' sn). Tetik atlandı.',
            'cikti'   => '',
            'log_id'  => null,
            'atlandi' => true,
        ];
    }

    $logId = cronLogOlustur($db, $gorevId, $zamanlamaId, $params, $tetikTur, $kullaniciId);
    $bas   = microtime(true);
    $onek  = $telafi ? '[TELAFİ] ' : '';

    try {
        @set_time_limit($maxSure);

        $sonuc = gorevCalistir((string)$gorev['CronGorevler_GorevKodu'], $params, $db);

        cronLogBitir($db, $logId, $sonuc['durum'], $onek . $sonuc['sonuc'], $bas, $sonuc['cikti']);

        return [
            'durum'   => $sonuc['durum'],
            'sonuc'   => $onek . $sonuc['sonuc'],
            'cikti'   => $sonuc['cikti'],
            'log_id'  => $logId,
            'atlandi' => false,
        ];
    } catch (Throwable $e) {
        // Throwable yakalanır, Exception değil: görev içinde TypeError/Error fırlarsa
        // catch(Exception) bunu kaçırır, log CalismaDurum=0 takılı kalır ve
        // overlap koruması o görevi zombi eşiğine kadar bloke eder.
        $mesaj = 'HATA: ' . $e->getMessage();

        // Görev ob_start() açıp kapatamadan çöktüyse buffer'ı topla
        $cikti = '';
        while (ob_get_level() > 0) {
            $cikti = (string)ob_get_clean() . $cikti;
        }

        cronLogBitir($db, $logId, CRON_DURUM_HATA, $onek . $mesaj, $bas, $cikti);

        error_log('Cron görev hatası (' . $gorev['CronGorevler_GorevKodu'] . '): '
                . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        return [
            'durum'   => CRON_DURUM_HATA,
            'sonuc'   => $onek . $mesaj,
            'cikti'   => $cikti,
            'log_id'  => $logId,
            'atlandi' => false,
        ];
    }
}

/**
 * Görev kaydını koda göre getirir.
 */
function cronGorevGetir($db, string $gorevKodu): ?array
{
    $gorev = $db->fetchOne("
        SELECT CronGorevler_Id, CronGorevler_Ad, CronGorevler_GorevKodu,
               CronGorevler_Parametreler, CronGorevler_MaxSureSn
        FROM dbo.CronGorevler
        WHERE CronGorevler_GorevKodu = ? AND Durum = 1
    ", [$gorevKodu]);

    return $gorev ?: null;
}

/**
 * Görevin parametre şemasına göre gelen parametreleri doğrular ve süzer.
 *
 * @throws RuntimeException Zorunlu alan eksikse
 */
function cronParamDogrula(?string $semaJson, array $gelen): array
{
    if (empty($semaJson)) {
        return [];
    }

    $sema = json_decode($semaJson, true);

    if (!is_array($sema)) {
        return [];
    }

    $sonuc = [];

    foreach ($sema as $alan) {
        $ad = $alan['ad'] ?? null;

        if (!$ad) {
            continue;
        }

        $deger = $gelen[$ad] ?? ($alan['varsayilan'] ?? null);
        $tip   = $alan['tip'] ?? '';

        if ($tip === 'checkbox') {
            $sonuc[$ad] = !empty($deger) ? 1 : 0;
            continue;
        }

        // Çoklu ID seçimi (kullanıcı / marka): panelden dizi, URL/CLI'dan virgüllü metin gelir.
        // Yalnızca ID saklanır, ad ve e-posta çalışma anında okunur — böylece kayıt
        // güncellendiğinde zamanlayıcıya dokunmak gerekmez.
        if ($tip === 'kullanici_coklu' || $tip === 'marka_coklu') {
            $idler = is_array($deger) ? $deger : explode(',', (string)$deger);
            $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));

            if (!$idler && !empty($alan['zorunlu'])) {
                throw new RuntimeException(($alan['etiket'] ?? $ad) . ' alanı zorunludur.');
            }

            if ($idler) {
                $sonuc[$ad] = implode(',', $idler);
            }

            continue;
        }

        if (!empty($alan['zorunlu']) && ($deger === null || $deger === '')) {
            throw new RuntimeException(($alan['etiket'] ?? $ad) . ' alanı zorunludur.');
        }

        if ($deger !== null && $deger !== '') {
            $sonuc[$ad] = $deger;
        }
    }

    return $sonuc;
}
