<?php
/**
 * Destek Bildirim Proxy (Server-Side)
 * Frontend polling endpoint'i. API anahtarini ifsa etmeden
 * kullanicinin ticket listesini ve "yeni yanit" sayisini doner.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketHelper.php';
requireAuth();

header('Content-Type: application/json');

$db   = Database::getInstance();
$user = Auth::user();

// Kullanicinin en son kontrol zamani (ISO 8601)
$sonKontrolRaw = trim((string)($_GET['son_kontrol'] ?? $_POST['son_kontrol'] ?? ''));
$sonKontrolTs  = 0;
if ($sonKontrolRaw !== '') {
    try {
        $sonKontrolTs = (new DateTimeImmutable($sonKontrolRaw))->getTimestamp();
    } catch (Exception $e) {
        $sonKontrolTs = 0;
    }
}

// API kullanici payload
$kullaniciDetay = $db->fetchOne(
    "SELECT TOP 1 kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon FROM dbo.kullanicilar WHERE kullanici_id = ?",
    [(int)$user['kullanici_id']]
);

$apiKullanici = [
    'kaynak_kullanici_id' => (int)$user['kullanici_id'],
    'ad'      => trim((string)($kullaniciDetay['kullanici_ad'] ?? '')),
    'soyad'   => trim((string)($kullaniciDetay['kullanici_soyad'] ?? '')),
    'eposta'  => trim((string)($kullaniciDetay['kullanici_email'] ?? ($user['email'] ?? ''))),
    'telefon' => trim((string)($kullaniciDetay['kullanici_telefon'] ?? '')),
];

try {
    // Yerel api_id -> Tickets_id eslesmesi (detay linki dogru calissin)
    $yerelMap  = [];
    $yerelRows = $db->fetchAll(
        "SELECT Tickets_id, Tickets_api_id FROM dbo.Tickets WHERE Tickets_kullanici_id = ? AND Durum = 1",
        [(int)$user['kullanici_id']]
    );
    foreach ($yerelRows as $r) {
        if (!empty($r['Tickets_api_id'])) {
            $yerelMap[(int)$r['Tickets_api_id']] = (int)$r['Tickets_id'];
        }
    }

    $helper = new TicketHelper($db);
    $resp   = $helper->ticketListe($apiKullanici);

    // API yanitindan liste cikar (defensive)
    $data  = $resp['data'] ?? $resp;
    $liste = [];
    if (isset($data[0]) && is_array($data[0])) {
        $liste = $data;
    } else {
        foreach (['tickets', 'ticketlar', 'liste', 'rows', 'items'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $liste = $data[$k];
                break;
            }
        }
    }

    $appTz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Istanbul');

    $parseTs = static function ($value) use ($appTz): int {
        $raw = trim((string)$value);
        if ($raw === '') {
            return 0;
        }
        try {
            if (preg_match('/(Z|[+\-]\d{2}:\d{2})$/', $raw) === 1) {
                return (new DateTimeImmutable($raw))->getTimestamp();
            }
            return (new DateTimeImmutable($raw, $appTz))->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    };

    $ticketlar  = [];
    $yeniSayisi = 0;

    foreach ($liste as $t) {
        if (!is_array($t)) {
            continue;
        }

        $apiId   = (int)($t['Tickets_api_id'] ?? $t['ticket_id'] ?? $t['api_id'] ?? $t['id'] ?? 0);
        $yerelId = $yerelMap[$apiId] ?? (int)($t['Tickets_id'] ?? $apiId);
        $konu    = trim((string)($t['Tickets_konu'] ?? $t['konu'] ?? $t['subject'] ?? $t['title'] ?? ''));
        $no      = trim((string)($t['Tickets_no'] ?? $t['ticket_no'] ?? $t['no'] ?? ''));
        $durumAd = trim((string)($t['durum_adi'] ?? $t['durum'] ?? $t['status'] ?? ''));

        $sonYanitRaw = trim((string)(
            $t['son_yanit_tarihi'] ?? $t['son_yanit'] ?? $t['last_reply'] ?? $t['guncelleme_tarihi'] ?? ''
        ));
        $sonYanitTs = $parseTs($sonYanitRaw);

        // Son yanitin yonu/tipi (API donduruyorsa) — kendi yanitimizi "yeni" sayma
        $sonYanitTip = mb_strtolower(trim((string)(
            $t['son_yanit_tip'] ?? $t['son_yanit_yonu'] ?? $t['son_yanit_kaynak'] ?? ''
        )), 'UTF-8');
        $kendiYaniti = in_array($sonYanitTip, ['musteri', 'customer', 'self', 'osb', 'kullanici'], true);

        $yeni = ($sonKontrolTs > 0 && $sonYanitTs > $sonKontrolTs && !$kendiYaniti);
        if ($yeni) {
            $yeniSayisi++;
        }

        $ticketlar[] = [
            'id'               => $apiId,
            'yerel_id'         => $yerelId,
            'konu'             => $konu !== '' ? $konu : ('Ticket #' . $apiId),
            'ticket_no'        => $no,
            'durum_adi'        => $durumAd,
            'son_yanit_tarihi' => $sonYanitRaw,
            'yeni'             => $yeni,
        ];
    }

    // En son yanitlanan ustte, dropdown icin ilk 10
    usort($ticketlar, static function ($a, $b) use ($parseTs) {
        return $parseTs($b['son_yanit_tarihi']) <=> $parseTs($a['son_yanit_tarihi']);
    });
    $ticketlar = array_slice($ticketlar, 0, 10);

    echo json_encode([
        'success'     => true,
        'yeni_sayisi' => $yeniSayisi,
        'ticketlar'   => $ticketlar,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success'     => false,
        'yeni_sayisi' => 0,
        'ticketlar'   => [],
        'message'     => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
