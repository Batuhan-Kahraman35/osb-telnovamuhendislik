<?php
/**
 * Ticket (Destek) API Helper
 * destek.ornekyazilim.com entegrasyonu
 */
class TicketHelper
{
    private $db;
    private $apiKey;
    private $apiUrl;
    private $kaynakKod;

    public function __construct($db)
    {
        $this->db = $db;
        $this->loadConfig();
    }

    // ─────────────────────────────────────────────
    // YAPILANDIRMA
    // ─────────────────────────────────────────────

    private function loadConfig(): void
    {
        try {
            $ticketAyar = $this->db->fetchOne(" 
                SELECT TOP 1
                    Ticket_Ayar_ApiKey,
                    Ticket_Ayar_ApiUrl,
                    Ticket_Ayar_KaynakKod
                FROM dbo.Ticket_Ayar
                WHERE Durum = 1
                ORDER BY Ticket_Ayar_id DESC
            ");
        } catch (Exception $e) {
            $ticketAyar = null;
        }

        if ($ticketAyar && !empty($ticketAyar['Ticket_Ayar_ApiKey'])) {
            $this->apiKey    = $ticketAyar['Ticket_Ayar_ApiKey'];
            $this->apiUrl    = $ticketAyar['Ticket_Ayar_ApiUrl'] ?: 'https://destek.ornekyazilim.com/api/tickets.php';
            $this->kaynakKod = $ticketAyar['Ticket_Ayar_KaynakKod'] ?: 'OSB';
            return;
        }

        try {
            $kanal = $this->db->fetchOne(" 
                                SELECT ek.Kanal_Parametreler, ek.Kanal_ApiKey, ek.Kanal_Endpoint
                FROM EntegrasyonKanallari ek
                INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
                WHERE e.Entegrasyon_Kod = 'TICKET_API'
                  AND ek.Kanal_Varsayilan = 1
                  AND ek.Kanal_Durum = 1
            ");
        } catch (Exception $e) {
            $kanal = null;
        }

        if ($kanal && !empty($kanal['Kanal_Parametreler'])) {
            $params = json_decode($kanal['Kanal_Parametreler'], true) ?? [];
            $this->apiKey    = $params['api_key']    ?? ($kanal['Kanal_ApiKey'] ?? '');
            $this->apiUrl    = $params['api_url']    ?? ($kanal['Kanal_Endpoint'] ?? 'https://destek.ornekyazilim.com/api/tickets.php');
            $this->kaynakKod = $params['kaynak_kod'] ?? 'OSB';
        } elseif ($kanal) {
            $this->apiKey = $kanal['Kanal_ApiKey'] ?? '';
            $this->apiUrl = $kanal['Kanal_Endpoint'] ?? 'https://destek.ornekyazilim.com/api/tickets.php';
            $this->kaynakKod = 'OSB';
        } else {
            $this->loadFallbackConfig();
        }

        if (empty($this->apiKey)) {
            throw new Exception('Ticket API anahtari yapilandirilmamis. Ticket_Ayar, Entegrasyon kanali veya config/ticket-api.php kontrol edin.');
        }
    }

    private function loadFallbackConfig(): void
    {
        $fallback = [];

        $cfgPath = __DIR__ . '/../../config/ticket-api.php';
        if (file_exists($cfgPath)) {
            $loaded = require $cfgPath;
            if (is_array($loaded)) {
                $fallback = $loaded;
            }
        }

        $envKey = getenv('TICKET_API_KEY') ?: '';
        $envUrl = getenv('TICKET_API_URL') ?: '';
        $envKaynak = getenv('TICKET_KAYNAK_KOD') ?: '';

        $this->apiKey = $fallback['api_key'] ?? '';
        if (!empty($envKey)) {
            $this->apiKey = $envKey;
        }

        $this->apiUrl = $fallback['api_url'] ?? 'https://destek.ornekyazilim.com/api/tickets.php';
        if (!empty($envUrl)) {
            $this->apiUrl = $envUrl;
        }

        $this->kaynakKod = $fallback['kaynak_kod'] ?? 'OSB';
        if (!empty($envKaynak)) {
            $this->kaynakKod = $envKaynak;
        }
    }

    // ─────────────────────────────────────────────
    // API İSTEKLERİ
    // ─────────────────────────────────────────────

    private function defaultHeaders(bool $json = true): array
    {
        $headers = [
            'X-API-KEY: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: OrnekFirma-OSB-TicketClient/1.0',
        ];

        if ($json) {
            $headers[] = 'Content-Type: application/json';
        }

        return $headers;
    }

    private function httpErrorMessage(int $httpCode, $response): string
    {
        $decoded = json_decode((string)$response, true);
        $apiMessage = '';
        $detay = '';

        if (is_array($decoded)) {
            $apiMessage = (string)($decoded['message'] ?? $decoded['error'] ?? '');

            // API v1.7.0+ hatali alanlari 'errors' dizisinde doner. Teshis icin ekle.
            $errors = $decoded['errors'] ?? $decoded['data']['errors'] ?? null;
            if (is_array($errors) && !empty($errors)) {
                $parcalar = [];
                foreach ($errors as $alan => $mesaj) {
                    $parcalar[] = is_string($alan)
                        ? $alan . ': ' . (is_array($mesaj) ? implode(', ', $mesaj) : (string)$mesaj)
                        : (is_array($mesaj) ? implode(', ', $mesaj) : (string)$mesaj);
                }
                $detay = ' [' . implode(' | ', $parcalar) . ']';
            }
        }

        if ($apiMessage !== '') {
            return 'HTTP ' . $httpCode . ' - ' . $apiMessage . $detay;
        }

        return 'HTTP ' . $httpCode . ' hatasi.' . $detay;
    }

    private function postJson(array $data): array
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $this->defaultHeaders(true),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('cURL hatası: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new Exception($this->httpErrorMessage($httpCode, $response));
        }

        $result = json_decode($response, true);
        if ($result === null) {
            throw new Exception('API geçersiz JSON döndürdü.');
        }

        return $result;
    }

    private function postMultipart(array $data, array $files = []): array
    {
        $postData = $data;

        foreach ($files as $field => $fileList) {
            $normalizedField = preg_replace('/\[\]$/', '', (string)$field);
            foreach ((array)$fileList as $i => $file) {
                if (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                    $postData[$normalizedField . '[' . $i . ']'] = new CURLFile(
                        $file['tmp_name'],
                        $file['type'] ?: 'application/octet-stream',
                        $file['name']
                    );
                }
            }
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_HTTPHEADER     => $this->defaultHeaders(false),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('cURL hatası: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new Exception($this->httpErrorMessage($httpCode, $response));
        }

        $result = json_decode($response, true);
        if ($result === null) {
            throw new Exception('API geçersiz JSON döndürdü.');
        }

        return $result;
    }

    private function getJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $this->defaultHeaders(true),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('cURL hatasi: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new Exception($this->httpErrorMessage($httpCode, $response));
        }

        $result = json_decode((string)$response, true);
        if (!is_array($result)) {
            throw new Exception('API gecersiz JSON dondurdu.');
        }

        return $result;
    }

    private function normalizeRefList(array $list): array
    {
        $normalized = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? $item['kategori_id'] ?? $item['oncelik_id'] ?? null;
            $ad = $item['ad'] ?? $item['adi'] ?? $item['name'] ?? $item['title'] ?? null;

            if ($id === null || $ad === null) {
                continue;
            }

            $normalized[] = [
                'id' => (int)$id,
                'ad' => (string)$ad,
            ];
        }

        return $normalized;
    }

    private function firstAvailableList(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_array($value)) {
                return $value;
            }
        }
        return [];
    }

    private function normalizeMetaItems(array $list, string $type): array
    {
        $normalized = [];

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item['id']
                ?? $item[$type . '_id']
                ?? null;
            $ad = $item['ad']
                ?? $item['adi']
                ?? $item['name']
                ?? $item['title']
                ?? null;

            if ($id === null || $ad === null) {
                continue;
            }

            $normalized[] = [
                'id'    => (int)$id,
                'ad'    => (string)$ad,
                'renk'  => (string)($item['renk'] ?? $item['color'] ?? 'secondary'),
                'sira'  => (int)($item['sira'] ?? $item['order'] ?? $item['sort'] ?? 0),
                'durum' => (int)($item['durum'] ?? 1),
            ];
        }

        return $normalized;
    }

    private function upsertTanimTablosu(string $table, string $prefix, array $items, int $kullaniciId): int
    {
        if (empty($items)) {
            return 0;
        }

        $insertList = [];

        foreach ($items as $item) {
            $id = (int)$item['id'];
            if ($id <= 0) {
                continue;
            }

            $exists = $this->db->fetchOne(
                "SELECT {$prefix}_id AS id FROM dbo.{$table} WHERE {$prefix}_id = ?",
                [$id]
            );

            if ($exists) {
                $this->db->execute(
                    "UPDATE dbo.{$table}
                     SET {$prefix}_ad = ?,
                         {$prefix}_renk = ?,
                         {$prefix}_sira = ?,
                         Durum = ?,
                         GuncelleyenKullanici = ?,
                         GuncellemeTarihi = GETDATE()
                     WHERE {$prefix}_id = ?",
                    [
                        $item['ad'],
                        $item['renk'],
                        $item['sira'],
                        $item['durum'] ? 1 : 0,
                        $kullaniciId,
                        $id,
                    ]
                );
            } else {
                $insertList[] = $item;
            }
        }

        if (!empty($insertList)) {
            $this->db->execute("SET IDENTITY_INSERT dbo.{$table} ON");
            try {
                foreach ($insertList as $item) {
                    $this->db->execute(
                        "INSERT INTO dbo.{$table}
                         ({$prefix}_id, {$prefix}_ad, {$prefix}_renk, {$prefix}_sira, OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi, Durum)
                         VALUES (?, ?, ?, ?, ?, GETDATE(), ?, GETDATE(), ?)",
                        [
                            (int)$item['id'],
                            $item['ad'],
                            $item['renk'],
                            (int)$item['sira'],
                            $kullaniciId,
                            $kullaniciId,
                            $item['durum'] ? 1 : 0,
                        ]
                    );
                }
            } finally {
                $this->db->execute("SET IDENTITY_INSERT dbo.{$table} OFF");
            }
        }

        return count($items);
    }

    /**
     * Kategori/Oncelik seceneklerini API dokumanindan okur.
     * API bu alanlari dondurmezse bos dizi dondurur.
     */
    public function ticketFormSecenekleri(): array
    {
        $docsUrl = preg_replace('/tickets\.php$/i', 'ticket-docs.php?action=full', $this->apiUrl);
        if (!$docsUrl) {
            $docsUrl = 'https://destek.ornekyazilim.com/api/ticket-docs.php?action=full';
        }

        $json = $this->getJson($docsUrl);
        $data = $json['data'] ?? [];

        $candidateKategori =
            $data['kategoriler']
            ?? $data['kategori_listesi']
            ?? ($data['references']['kategoriler'] ?? null)
            ?? ($data['refs']['kategoriler'] ?? null)
            ?? [];

        $candidateOncelik =
            $data['oncelikler']
            ?? $data['oncelik_listesi']
            ?? ($data['references']['oncelikler'] ?? null)
            ?? ($data['refs']['oncelikler'] ?? null)
            ?? [];

        return [
            'success'     => true,
            'kategoriler' => $this->normalizeRefList(is_array($candidateKategori) ? $candidateKategori : []),
            'oncelikler'  => $this->normalizeRefList(is_array($candidateOncelik) ? $candidateOncelik : []),
            'meta'        => $data['meta'] ?? [],
        ];
    }

    public function ticketMeta(): array
    {
        return $this->postJson([
            'action' => 'ticket_meta',
        ]);
    }

    public function senkronizeMetaTanimlari(int $kullaniciId): array
    {
        $meta403 = false;
        $kategoriler = [];
        $oncelikler = [];
        $durumlar = [];

        try {
            $metaResult = $this->ticketMeta();
            if (empty($metaResult['success'])) {
                throw new Exception($metaResult['message'] ?? 'ticket_meta hatasi.');
            }
            $data = $metaResult['data'] ?? [];
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'HTTP 403') !== false) {
                $meta403 = true;
                $secenekler = $this->ticketFormSecenekleri();
                $kategorilerSimple = $secenekler['kategoriler'] ?? [];
                $onceliklerSimple  = $secenekler['oncelikler'] ?? [];

                $kategoriler = array_map(static function ($x) {
                    return [
                        'id' => (int)$x['id'],
                        'ad' => (string)$x['ad'],
                        'renk' => 'secondary',
                        'sira' => 0,
                        'durum' => 1,
                    ];
                }, $kategorilerSimple);

                $oncelikler = array_map(static function ($x) {
                    return [
                        'id' => (int)$x['id'],
                        'ad' => (string)$x['ad'],
                        'renk' => 'secondary',
                        'sira' => 0,
                        'durum' => 1,
                    ];
                }, $onceliklerSimple);

                $durumlar = [];
            } else {
                throw $e;
            }
        }

        if (!$meta403) {
            $kategoriler = $this->normalizeMetaItems(
                $this->firstAvailableList($data, [
                    ['kategoriler'],
                    ['kategori_listesi'],
                    ['meta', 'kategoriler'],
                    ['ticket_meta', 'kategoriler'],
                ]),
                'kategori'
            );

            $oncelikler = $this->normalizeMetaItems(
                $this->firstAvailableList($data, [
                    ['oncelikler'],
                    ['oncelik_listesi'],
                    ['meta', 'oncelikler'],
                    ['ticket_meta', 'oncelikler'],
                ]),
                'oncelik'
            );

            $durumlar = $this->normalizeMetaItems(
                $this->firstAvailableList($data, [
                    ['durumlar'],
                    ['durum_listesi'],
                    ['meta', 'durumlar'],
                    ['ticket_meta', 'durumlar'],
                ]),
                'durum'
            );
        }

        if (empty($kategoriler) || empty($oncelikler)) {
            throw new Exception('ticket_meta/docs yanitinda kategori/oncelik listesi bulunamadi.');
        }

        $kategoriCount = $this->upsertTanimTablosu('Ticket_Kategoriler', 'Ticket_Kategoriler', $kategoriler, $kullaniciId);
        $oncelikCount  = $this->upsertTanimTablosu('Ticket_Oncelikler', 'Ticket_Oncelikler', $oncelikler, $kullaniciId);
        $durumCount    = $this->upsertTanimTablosu('Ticket_Durumlar', 'Ticket_Durumlar', $durumlar, $kullaniciId);

        return [
            'success' => true,
            'counts' => [
                'kategoriler' => $kategoriCount,
                'oncelikler'  => $oncelikCount,
                'durumlar'    => $durumCount,
            ],
        ];
    }

    // ─────────────────────────────────────────────
    // PUBLIC METOTLAR
    // ─────────────────────────────────────────────

    /**
     * Yeni ticket oluşturur.
     * @param array $kullanici ['kaynak_kullanici_id', 'ad', 'soyad', 'eposta', 'telefon']
     * @param array $data      ['konu', 'aciklama', 'kategori_id', 'oncelik_id']
     * @param array $files     $_FILES dizisi (opsiyonel)
     */
    public function ticketOlustur(array $kullanici, array $data, array $files = []): array
    {
        // Dosyaları base64 olarak ekler dizisine dönüştür
        $ekler = [];
        if (!empty($files['dosyalar']['tmp_name'])) {
            foreach ($files['dosyalar']['tmp_name'] as $i => $tmp) {
                if (empty($tmp) || !is_uploaded_file($tmp)) continue;
                $ekler[] = [
                    'dosya_adi'     => $files['dosyalar']['name'][$i] ?? 'dosya',
                    'mime_turu'     => $files['dosyalar']['type'][$i] ?: 'application/octet-stream',
                    'icerik_base64' => base64_encode(file_get_contents($tmp)),
                ];
            }
        }

        $payload = [
            'action'      => 'create_ticket',
            'kullanici'   => [
                'ad'      => $kullanici['ad']      ?? '',
                'soyad'   => $kullanici['soyad']   ?? '',
                'eposta'  => $kullanici['eposta']  ?? '',
                'telefon' => $kullanici['telefon'] ?? '',
            ],
            'konu'        => $data['konu'],
            'mesaj'       => $data['aciklama'],
            'kategori_id' => (int)$data['kategori_id'],
            'oncelik_id'  => (int)$data['oncelik_id'],
        ];

        if (!empty($ekler)) {
            $payload['ekler'] = $ekler;
        }

        return $this->postJson($payload);

    }

    /**
     * Ticket'a yanıt yazar.
     */
    public function ticketYanit(int $ticketApiId, $kullanici, string $mesaj, array $files = []): array
    {
        $kullaniciPayload = $this->buildKullaniciPayload($kullanici);

        $hasFiles = !empty($files['dosyalar']['tmp_name'][0]);

        if ($hasFiles) {
            $kullaniciJson = json_encode($kullaniciPayload, JSON_UNESCAPED_UNICODE);
            // API, kullanici alanlarini hem 'kullanici' nesnesi icinde hem de root seviyesinde bekliyor.
            $multipart = [
                'action'              => 'reply_ticket',
                'ticket_id'           => (string)$ticketApiId,
                'kaynak_kullanici_id' => $kullaniciPayload['kaynak_kullanici_id'],
                'ad'                  => $kullaniciPayload['ad'],
                'soyad'               => $kullaniciPayload['soyad'],
                'eposta'              => $kullaniciPayload['eposta'],
                'telefon'             => $kullaniciPayload['telefon'],
                'kullanici'           => $kullaniciJson,
                'mesaj'               => $mesaj,
            ];
            $uploadedFiles = $this->reformatFiles($files['dosyalar']);
            return $this->postMultipart($multipart, ['dosyalar' => $uploadedFiles]);
        }

        // API, kullanici alanlarini hem 'kullanici' nesnesi icinde hem de root seviyesinde bekliyor.
        return $this->postJson([
            'action'              => 'reply_ticket',
            'ticket_id'           => $ticketApiId,
            'kaynak_kullanici_id' => $kullaniciPayload['kaynak_kullanici_id'],
            'ad'                  => $kullaniciPayload['ad'],
            'soyad'               => $kullaniciPayload['soyad'],
            'eposta'              => $kullaniciPayload['eposta'],
            'telefon'             => $kullaniciPayload['telefon'],
            'kullanici'           => $kullaniciPayload,
            'mesaj'               => $mesaj,
        ]);
    }

    /**
     * Ticket detayını döndürür.
     */
    public function ticketDetay(int $ticketApiId, $kullanici): array
    {
        $kPayload = $this->buildKullaniciPayload($kullanici);
        // API, kullanici alanlarını hem kullanici nesnesi içinde hem de root seviyesinde bekliyor.
        return $this->postJson([
            'action'               => 'ticket_detail',
            'ticket_id'            => $ticketApiId,
            'kaynak_kullanici_id'  => $kPayload['kaynak_kullanici_id'],
            'ad'                   => $kPayload['ad'],
            'soyad'                => $kPayload['soyad'],
            'eposta'               => $kPayload['eposta'],
            'telefon'              => $kPayload['telefon'],
            'kullanici'            => $kPayload,
        ]);
    }

    /**
     * Ticket mesajlarını listeler.
     */
    public function ticketMesajlar(int $ticketApiId, $kullanici): array
    {
        return $this->postJson([
            'action'    => 'ticket_messages',
            'ticket_id' => $ticketApiId,
            'kullanici' => $this->buildKullaniciPayload($kullanici),
        ]);
    }

    /**
     * Kullanıcının ticket listesini API'den çeker (son yanıt tarihi dahil).
     * Bildirim/polling amaçlı kullanılır.
     * API yalnızca root seviyesinde 'eposta' bekler.
     */
    public function ticketListe($kullanici): array
    {
        if (is_array($kullanici)) {
            $eposta = trim((string)($kullanici['eposta'] ?? $kullanici['kullanici_email'] ?? $kullanici['email'] ?? ''));
        } else {
            $eposta = trim((string)$kullanici);
        }

        if ($eposta === '') {
            throw new Exception('list_tickets icin eposta zorunludur.');
        }

        return $this->postJson([
            'action' => 'list_tickets',
            'eposta' => $eposta,
        ]);
    }

    /**
     * Kullanicinin bilgilendirme (CC) yoluyla erisebildigi taleplerin
     * destek sistemi tarafindaki ID'lerini (Tickets_api_id karsiligi) doner.
     *
     * Yerel liste sorgusunu genisletmek icin kullanilir. Destek sistemine
     * ulasilamazsa sayfa calismaya devam etsin diye bos dizi doner;
     * bu durumda yalnizca CC kayitlari listelenmez.
     *
     * Ayni istek icinde birden fazla cagrilirsa API'ye tek kez gidilir.
     */
    public function ccTicketApiIdleri($kullanici): array
    {
        static $onbellek = [];

        if (is_array($kullanici)) {
            $eposta = trim((string)($kullanici['eposta'] ?? $kullanici['kullanici_email'] ?? $kullanici['email'] ?? ''));
        } else {
            $eposta = trim((string)$kullanici);
        }

        if ($eposta === '') {
            return [];
        }

        $anahtar = mb_strtolower($eposta, 'UTF-8');
        if (array_key_exists($anahtar, $onbellek)) {
            return $onbellek[$anahtar];
        }

        $idler = [];

        try {
            $cevap = $this->ticketListe($eposta);
            $kayitlar = $cevap['data'] ?? [];

            if (is_array($kayitlar)) {
                foreach ($kayitlar as $kayit) {
                    if (!is_array($kayit) || empty($kayit['cc_mi'])) {
                        continue;
                    }
                    $apiId = (int)($kayit['Tickets_id'] ?? 0);
                    if ($apiId > 0) {
                        $idler[] = $apiId;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('CC ticket listesi alinamadi: ' . $e->getMessage());
            $idler = [];
        }

        $idler = array_values(array_unique($idler));
        $onbellek[$anahtar] = $idler;

        return $idler;
    }

    // ─────────────────────────────────────────────
    // YARDIMCILAR
    // ─────────────────────────────────────────────

    private function reformatFiles(array $filesField): array
    {
        $result = [];
        if (isset($filesField['tmp_name']) && is_array($filesField['tmp_name'])) {
            foreach ($filesField['tmp_name'] as $i => $tmp) {
                if (!empty($tmp) && is_uploaded_file($tmp)) {
                    $result[] = [
                        'tmp_name' => $tmp,
                        'name'     => $filesField['name'][$i],
                        'type'     => $filesField['type'][$i],
                    ];
                }
            }
        }
        return $result;
    }

    private function buildKullaniciPayload($kullanici): array
    {
        $kaynakKullaniciId = is_array($kullanici)
            ? (int)($kullanici['kaynak_kullanici_id'] ?? $kullanici['id'] ?? 0)
            : (int)$kullanici;

        if ($kaynakKullaniciId <= 0) {
            throw new Exception('kaynak_kullanici_id gecersiz.');
        }

        $payload = [
            'kaynak_kullanici_id' => (string)$kaynakKullaniciId,
            'ad' => '',
            'soyad' => '',
            'eposta' => '',
            'telefon' => '',
        ];

        if (is_array($kullanici)) {
            $ad = trim((string)($kullanici['ad'] ?? $kullanici['kullanici_ad'] ?? ''));
            $soyad = trim((string)($kullanici['soyad'] ?? $kullanici['kullanici_soyad'] ?? ''));
            $eposta = trim((string)($kullanici['eposta'] ?? $kullanici['kullanici_email'] ?? $kullanici['email'] ?? ''));
            $telefon = trim((string)($kullanici['telefon'] ?? $kullanici['kullanici_telefon'] ?? ''));

            $payload['ad'] = $ad;
            $payload['soyad'] = $soyad;
            $payload['eposta'] = $eposta;
            $payload['telefon'] = $telefon;
        }

        return $payload;
    }
}
