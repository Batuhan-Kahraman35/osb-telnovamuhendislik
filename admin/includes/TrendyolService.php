<?php
/**
 * TrendyolService — Trendyol Marketplace API İstemcisi
 *
 * Kullanım:
 *   $trendyol = TrendyolService::fromDb($db);
 *   $sonuc = $trendyol->baglantiTest();
 *   $siparisler = $trendyol->getSiparisler(['startDate' => ..., 'endDate' => ...]);
 *
 * EntegrasyonKanallari:
 *   Kanal_Kullanici = API Key
 *   Kanal_Sifre     = API Secret
 *   Kanal_Parametreler (JSON):
 *     { "satici_id": "1262086" }
 */
class TrendyolService
{
    // Trendyol sipariş listesinde izin verilen azami tarih aralığı (gün)
    public const SIPARIS_MAX_GUN = 14;

    private string $baseUrl   = 'https://apigw.trendyol.com/integration';
    private string $saticiId;
    private string $apiKey;
    private string $apiSecret;
    private string $userAgent;
    private int    $kanalId;
    private        $db;

    // ─────────────────────────────────────────────
    // KURUCU
    // ─────────────────────────────────────────────

    public function __construct($db, array $kanal, array $params)
    {
        $this->db        = $db;
        $this->kanalId   = (int)$kanal['Kanal_Id'];
        $this->apiKey    = $kanal['Kanal_Kullanici'];
        $this->apiSecret = $kanal['Kanal_Sifre'];
        $this->saticiId  = $params['satici_id'] ?? '';
        // User-Agent: Trendyol formatı → "{sellerId} - {EntegrasyonFirmasi}"
        $this->userAgent = $params['user_agent'] ?? ($this->saticiId . ' - SelfIntegration');

        if (empty($this->saticiId)) {
            throw new RuntimeException('Trendyol kanal parametrelerinde satici_id eksik.');
        }
        if (empty($this->apiKey) || empty($this->apiSecret)) {
            throw new RuntimeException('Trendyol API Key veya Secret tanımlı değil.');
        }
    }

    /**
     * DB'den Trendyol kanalını okuyarak örnek oluşturur.
     * Birden fazla kanal varsa $kanalId ile belirtilir.
     */
    public static function fromDb($db, ?int $kanalId = null): self
    {
        $sql = "
            SELECT ek.*
            FROM EntegrasyonKanallari ek
            INNER JOIN Entegrasyon e ON e.Entegrasyon_Id = ek.Kanal_Entegrasyon_Id
            WHERE e.Entegrasyon_Kod = 'TRENDYOL'
              AND ek.Kanal_Durum = 1
        ";
        $args = [];

        if ($kanalId !== null) {
            $sql  .= ' AND ek.Kanal_Id = ?';
            $args[] = $kanalId;
        } else {
            $sql .= ' AND ek.Kanal_Varsayilan = 1';
        }

        $kanal = $db->fetchOne($sql, $args);

        if (!$kanal) {
            throw new RuntimeException('Aktif Trendyol kanalı bulunamadı.');
        }

        $params = !empty($kanal['Kanal_Parametreler'])
            ? (json_decode($kanal['Kanal_Parametreler'], true) ?? [])
            : [];

        return new self($db, $kanal, $params);
    }

    // ─────────────────────────────────────────────
    // MERKEZI cURL (tüm istekler buradan geçer)
    // ─────────────────────────────────────────────

    /**
     * @param  string $method        GET | POST | PUT | DELETE
     * @param  string $path          /order/sellers/{id}/orders  — {id} otomatik yerine konur
     * @param  array  $query         URL parametreleri (GET için)
     * @param  array  $body          İstek gövdesi (POST/PUT için)
     * @param  array  $extraHeaders  Ek HTTP header'ları
     * @return array  ['success' => bool, 'http_code' => int, 'data' => array, 'error' => string]
     */
    public function request(string $method, string $path, array $query = [], array $body = [], array $extraHeaders = []): array
    {
        // {id} yer tutucusunu satici ID ile değiştir
        $path = str_replace('{id}', $this->saticiId, $path);

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $token = base64_encode($this->apiKey . ':' . $this->apiSecret);

        $headers = array_merge([
            'Authorization: Basic ' . $token,
            'Content-Type: application/json',
            'User-Agent: ' . $this->userAgent,
        ], $extraHeaders);

        $method    = strtoupper($method);
        $baslangic = (int)(microtime(true) * 1000);
        $jsonBody  = null;
        if (in_array($method, ['POST', 'PUT'])) {
            // Boş array bile olsa body gönder — Trendyol bazı endpoint'lerde body bekliyor
            $jsonBody = !empty($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : '{}';
        }

        // 429 (limit aşımı) alınırsa 2 ve 4 sn bekleyip en fazla 2 kez tekrar dener
        $deneme = 0;
        do {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            if ($jsonBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            }

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 429 || $deneme >= 2) {
                break;
            }
            sleep(2 ** ($deneme + 1));
            $deneme++;
        } while (true);

        if ($curlError) {
            $this->log($method, $path, $jsonBody, null, 'HATA', 'cURL hatası: ' . $curlError, $baslangic);
            return [
                'success'   => false,
                'http_code' => 0,
                'data'      => [],
                'error'     => 'cURL hatası: ' . $curlError,
            ];
        }

        $data = json_decode($response, true);

        // Kanal son kullanım güncelle
        try {
            $this->db->execute(
                "UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Id = ?",
                [$this->kanalId]
            );
        } catch (Exception $e) {
            // Sessizce geç — kritik değil
        }

        $success = $httpCode >= 200 && $httpCode < 300;
        // Hata metni farklı biçimlerde gelir: message | errors[].message | error.errors[].title (ör. COMMON_LABEL_NOT_ALLOWED)
        $hata    = $success ? '' : ($data['message'] ?? $data['errors'][0]['message']
                 ?? $data['error']['errors'][0]['title'] ?? "HTTP {$httpCode}");

        // Yazma istekleri ve tüm hatalar loglanır; saatlik başarılı GET'ler log şişirmesin diye loglanmaz
        if ($method !== 'GET' || !$success) {
            $this->log($method, $path, $jsonBody, $response, $success ? 'BASARILI' : 'HATA',
                $success ? null : "HTTP {$httpCode}: {$hata}" . ($deneme ? " ({$deneme} tekrar)" : ''), $baslangic);
        }

        return [
            'success'   => $success,
            'http_code' => $httpCode,
            'data'      => is_array($data) ? $data : ['raw' => $response],
            'error'     => $hata,
        ];
    }

    /** EntegrasyonLoglari kaydı — API anahtarı loglanmaz */
    private function log(string $method, string $path, ?string $istek, ?string $yanit, string $durum, ?string $hata, int $baslangic): void
    {
        try {
            $this->db->insert('EntegrasyonLoglari', [
                'Log_Kanal_Id'     => $this->kanalId,
                'Log_Islem'        => mb_substr("{$method} {$path}", 0, 200),
                'Log_Alici'        => '',
                'Log_Konu'         => 'Trendyol',
                'Log_Mesaj'        => mb_substr((string)$istek, 0, 500),
                'Log_Istek'        => $istek,
                'Log_Yanit'        => $yanit !== null ? mb_substr($yanit, 0, 10000) : null,
                'Log_Durum'        => $durum,
                'Log_Hata_Mesaj'   => $hata,
                'Log_Sure_Ms'      => (int)((microtime(true) * 1000) - $baslangic),
                'Log_IP'           => $_SERVER['REMOTE_ADDR'] ?? null,
                'Log_Kullanici_Id' => $_SESSION['kullanici_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('[TrendyolService::log] ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    // BAĞLANTI TESTİ
    // ─────────────────────────────────────────────

    /**
     * /sellers/{id}/addresses endpoint'ine GET atarak bağlantıyı doğrular.
     */
    public function baglantiTest(): array
    {
        $sonuc = $this->request('GET', '/sellers/{id}/addresses');

        return [
            'success'   => $sonuc['success'],
            'http_code' => $sonuc['http_code'],
            'mesaj'     => $sonuc['success']
                ? 'Trendyol API bağlantısı başarılı.'
                : 'Bağlantı hatası: ' . $sonuc['error'],
            'data'      => $sonuc['data'],
        ];
    }

    // ─────────────────────────────────────────────
    // YARDIMCI GETTER'LAR
    // ─────────────────────────────────────────────

    public function getSaticiId(): string { return $this->saticiId; }
    public function getKanalId(): int     { return $this->kanalId; }

    // ─────────────────────────────────────────────
    // SİPARİŞLER
    // ─────────────────────────────────────────────

    /**
     * Trendyol'dan sipariş listesini çeker.
     *
     * @param  array $filtreler  Opsiyonel: startDate, endDate (ms cinsinden), status, page, size
     * @return array ['success' => bool, 'toplam' => int, 'siparisler' => array, 'error' => string]
     */
    public function getSiparisler(array $filtreler = []): array
    {
        $query = [
            'page' => $filtreler['page'] ?? 0,
            'size' => $filtreler['size'] ?? 50,
        ];

        // Tarih filtreleri (milisaniye cinsinden UNIX timestamp)
        if (!empty($filtreler['startDate'])) {
            $query['startDate'] = $filtreler['startDate'];
        }
        if (!empty($filtreler['endDate'])) {
            $query['endDate'] = $filtreler['endDate'];
        }
        if (!empty($filtreler['status'])) {
            $query['status'] = $filtreler['status'];
        }
        if (!empty($filtreler['orderByField'])) {
            $query['orderByField']     = $filtreler['orderByField'];
            $query['orderByDirection'] = $filtreler['orderByDirection'] ?? 'DESC';
        }

        // V2 — V1 (/orders) 15.10.2026'da kapanıyor; yanıt yapısı aynı
        $sonuc = $this->request('GET', '/order/sellers/{id}/v2/orders', $query);

        if (!$sonuc['success']) {
            return [
                'success'    => false,
                'toplam'     => 0,
                'siparisler' => [],
                'error'      => $sonuc['error'],
            ];
        }

        $data = $sonuc['data'];

        return [
            'success'    => true,
            'toplam'     => $data['totalElements'] ?? 0,
            'toplam_sayfa' => $data['totalPages'] ?? 1,
            'siparisler' => $data['content'] ?? [],
            'error'      => '',
        ];
    }

    /**
     * Sipariş satırlarını (lines) ps_urunler JSON yapısına çevirir.
     * Alan adları 02.04.2026'da değişti; eski adlar yedek olarak okunur.
     * Ham veriden (ps_ham_veri) yeniden hesaplamada da kullanılır.
     */
    public static function kalemleriCevir(array $siparis): array
    {
        $kalemler = [];
        foreach ($siparis['lines'] ?? [] as $line) {
            $kalemler[] = [
                'urun_id'     => $line['productId'] ?? $line['contentId'] ?? null,
                'barkod'      => $line['barcode']        ?? null,
                'adi'         => $line['productName']    ?? null,
                'miktar'      => $line['quantity']       ?? 1,
                'fiyat'       => $line['price']          ?? 0,
                'indirimli'   => $line['lineUnitPrice']  ?? $line['discountedPrice'] ?? 0,  // indirim sonrası birim fiyat
                'komisyon'    => $line['commission']     ?? $line['commissionRate']  ?? null, // oran (%)
                'durum'       => $line['orderLineItemStatusName'] ?? $line['status'] ?? null,
            ];
        }
        return $kalemler;
    }

    /**
     * Tek bir Trendyol siparişini PazarYeriSiparisler tablosuna yazar (UPSERT).
     * Zaten varsa günceller, yoksa ekler.
     *
     * @param  array $siparis  getSiparisler() içindeki content[] elemanı
     * @return bool
     */
    public function siparisKaydet(array $siparis): bool
    {
        try {
            // Sipariş kalemleri (lines) — JSON olarak saklıyoruz
            $kalemler = self::kalemleriCevir($siparis);

            // Teslimat adresi — JSON
            $adres = $siparis['shipmentAddress'] ?? $siparis['invoiceAddress'] ?? [];

            // orderDate ms değeri İstanbul yerel saatini UTC gibi taşır → gmdate().
            // Diğer tarih alanları (packageHistories vb.) gerçek UTC'dir → date().
            $siparisTarih = null;
            if (!empty($siparis['orderDate'])) {
                $siparisTarih = gmdate('Y-m-d H:i:s', (int)($siparis['orderDate'] / 1000));
            }

            // Kargoya verilme: paket geçmişindeki ilk Shipped kaydı
            $kargoyaVerilme = null;
            foreach ($siparis['packageHistories'] ?? [] as $h) {
                if (($h['status'] ?? '') === 'Shipped' && !empty($h['createdDate'])) {
                    $kargoyaVerilme = date('Y-m-d H:i:s', (int)($h['createdDate'] / 1000));
                    break;
                }
            }

            $musteri = trim(($siparis['shipmentAddress']['firstName'] ?? '') . ' ' . ($siparis['shipmentAddress']['lastName'] ?? ''));

            $data = [
                'ps_platform_siparis_id'    => (string)($siparis['orderNumber'] ?? $siparis['id'] ?? ''),
                'ps_siparis_no'             => (string)($siparis['orderNumber'] ?? ''),
                'ps_durum'                  => $siparis['status'] ?? null,
                'ps_toplam_tutar'           => $siparis['totalPrice'] ?? null,
                'ps_para_birimi'            => 'TRY',
                'ps_musteri_adi'            => $musteri ?: null,
                'ps_musteri_sehir'          => $adres['city'] ?? null,
                'ps_teslimat_adresi'        => json_encode($adres, JSON_UNESCAPED_UNICODE),
                'ps_kargo_firmasi'          => $siparis['cargoProviderName'] ?? null,
                'ps_kargo_takip_no'         => isset($siparis['cargoTrackingNumber']) ? (string)$siparis['cargoTrackingNumber'] : null,
                'ps_kargo_paket_id'         => (string)($siparis['shipmentPackageId'] ?? $siparis['id'] ?? ''),
                'ps_kargoya_verilme_tarihi' => $kargoyaVerilme,
                'ps_siparis_tarihi'         => $siparisTarih,
                'ps_urunler'                => json_encode($kalemler, JSON_UNESCAPED_UNICODE),
                'ps_ham_veri'               => json_encode($siparis, JSON_UNESCAPED_UNICODE),
                'GuncelleyenKullanici'      => 1,
                'GuncellemeTarihi'          => date('Y-m-d H:i:s'),
            ];

            // Mevcut kayıt var mı?
            $mevcut = $this->db->fetchOne(
                "SELECT ps_id FROM PazarYeriSiparisler WHERE ps_kanal_id = ? AND ps_platform_siparis_id = ?",
                [$this->kanalId, $data['ps_platform_siparis_id']]
            );

            if ($mevcut) {
                // UPDATE
                $setClauses = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
                $this->db->execute(
                    "UPDATE PazarYeriSiparisler SET {$setClauses} WHERE ps_kanal_id = ? AND ps_platform_siparis_id = ?",
                    array_merge(array_values($data), [$this->kanalId, $data['ps_platform_siparis_id']])
                );
            } else {
                // INSERT
                $data['ps_kanal_id']          = $this->kanalId;
                $data['OlusturanKullanici']    = 1;
                $data['OlusturmaTarihi']       = date('Y-m-d H:i:s');
                $data['Durum']                 = 1;

                $kolonlar = implode(', ', array_keys($data));
                $degerler = implode(', ', array_fill(0, count($data), '?'));
                $this->db->execute(
                    "INSERT INTO PazarYeriSiparisler ({$kolonlar}) VALUES ({$degerler})",
                    array_values($data)
                );
            }

            return true;

        } catch (Exception $e) {
            error_log('[TrendyolService::siparisKaydet] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Son $gun günün siparişlerini sayfa sayfa çekip PazarYeriSiparisler'e yazar.
     * Panel butonu ve cron görevi (cron/gorevler/pazaryeri-siparis-cek.php) bunu kullanır.
     *
     * Trendyol startDate-endDate aralığını en fazla 2 hafta kabul eder; gün
     * sayısı SIPARIS_MAX_GUN ile sınırlanır.
     *
     * @return array ['gun', 'toplam', 'yeni', 'guncellenen', 'hata']
     * @throws RuntimeException API hatasında
     */
    public function siparisleriSenkronla(int $gun): array
    {
        $gun       = max(1, min(self::SIPARIS_MAX_GUN, $gun));
        $now       = (int)(microtime(true) * 1000);
        $startDate = $now - ($gun * 24 * 60 * 60 * 1000);

        $toplam = 0; $yeni = 0; $guncellenen = 0; $hata = 0;
        $sayfa  = 0;

        do {
            $cevap = $this->getSiparisler([
                'startDate' => $startDate,
                'endDate'   => $now,
                'page'      => $sayfa,
                'size'      => 200,
            ]);

            if (!$cevap['success']) {
                throw new RuntimeException('API hatası: ' . $cevap['error']);
            }

            foreach ($cevap['siparisler'] as $siparis) {
                $toplam++;
                $mevcut = $this->db->fetchOne(
                    "SELECT ps_id FROM PazarYeriSiparisler WHERE ps_kanal_id = ? AND ps_platform_siparis_id = ?",
                    [$this->kanalId, (string)($siparis['orderNumber'] ?? '')]
                );
                if ($this->siparisKaydet($siparis)) {
                    $mevcut ? $guncellenen++ : $yeni++;
                } else {
                    $hata++;
                }
            }

            $sayfa++;
        } while ($sayfa < ($cevap['toplam_sayfa'] ?? 1) && $toplam < 2000);

        return compact('gun', 'toplam', 'yeni', 'guncellenen', 'hata');
    }

    // ─────────────────────────────────────────────
    // FATURA SERVİSLERİ
    // ─────────────────────────────────────────────

    /**
     * Invoiced statüsü sonrası e-Fatura / e-Arşiv linkini Trendyol'a bildirir.
     * POST /sellers/{id}/seller-invoice-links  (201 başarılı, 409 aynı pakete tekrar)
     *
     * @param  int|string $paketId
     * @param  array      $fatura  ['invoiceNumber', 'invoiceDate' (ms), 'trackingNumber', 'invoiceLink']
     * @return array ['success'=>bool, 'message'=>string]
     */
    public function faturaLinkGonder($paketId, array $fatura): array
    {
        if (!$paketId) return ['success' => false, 'message' => 'Paket ID boş.'];

        $body = [
            'invoiceLink'       => $fatura['invoiceLink'],
            'shipmentPackageId' => (int)$paketId,
            'invoiceDateTime'   => (int)$fatura['invoiceDate'],
            'invoiceNumber'     => $fatura['invoiceNumber'],
        ];

        $sonuc = $this->request('POST', "/sellers/{id}/seller-invoice-links", [], $body);

        $mesaj = $sonuc['data']['message']
            ?? $sonuc['data']['errors'][0]['message']
            ?? ($sonuc['success'] ? 'Fatura linki gönderildi.' : 'HTTP ' . $sonuc['http_code']);

        return ['success' => $sonuc['success'], 'message' => $mesaj];
    }

    // ─────────────────────────────────────────────
    // KARGO ETİKETİ
    // ─────────────────────────────────────────────

    /**
     * Ortak etiket talebi oluşturur (ZPL format).
     * POST /sellers/{id}/common-label/{cargoTrackingNumber}
     * ⚠️ Paket Picking veya Invoiced statüsünde olmalı. Yalnız TEX / Aras (Trendyol ödemeli).
     *
     * @param  string $kargoTakipNo  ps_kargo_takip_no (cargoTrackingNumber)
     * @return array ['success'=>bool, 'message'=>string]
     */
    public function createCommonLabel($kargoTakipNo): array
    {
        if (!$kargoTakipNo) {
            return ['success' => false, 'message' => 'Kargo takip numarası boş.'];
        }

        $sonuc = $this->request('POST', "/sellers/{id}/common-label/{$kargoTakipNo}", [], [
            'format'      => 'ZPL',
            'boxQuantity' => 1,
        ]);

        if ($sonuc['success'] || in_array($sonuc['http_code'], [200, 201, 202])) {
            return ['success' => true, 'message' => 'Etiket talebi oluşturuldu.'];
        }

        $hata = $sonuc['data']['message']
            ?? $sonuc['data']['errors'][0]['message']
            ?? ($sonuc['error'] ?: 'HTTP ' . $sonuc['http_code']);

        return ['success' => false, 'message' => 'Etiket talebi hatası: ' . $hata, 'raw' => $sonuc['data']];
    }

    /**
     * Oluşturulan ortak etiketi (ZPL) çeker.
     * GET /sellers/{id}/common-label/{cargoTrackingNumber}  → {"data":[{"label":"^XA...^XZ","format":"ZPL"}]}
     *
     * @param  string $kargoTakipNo  ps_kargo_takip_no (cargoTrackingNumber)
     * @return array ['success'=>bool, 'zpl'=>string|null, 'error'=>string]
     */
    public function getCommonLabel($kargoTakipNo): array
    {
        if (!$kargoTakipNo) {
            return ['success' => false, 'zpl' => null, 'error' => 'Kargo takip numarası boş.'];
        }

        $sonuc = $this->request('GET', "/sellers/{id}/common-label/{$kargoTakipNo}");

        if (!$sonuc['success'] && $sonuc['http_code'] !== 200) {
            $hata = $sonuc['data']['message']
                ?? $sonuc['data']['errors'][0]['message']
                ?? ($sonuc['error'] ?: 'HTTP ' . $sonuc['http_code']);
            return ['success' => false, 'zpl' => null, 'error' => $hata];
        }

        $data = $sonuc['data'];
        // Resmi yanıt: data[].label (çok kolide birden fazla etiket) — eski biçimler yedek
        $etiketler = array_filter(array_column($data['data'] ?? [], 'label'));
        $zpl = $etiketler ? implode("\n", $etiketler)
             : ($data['content'] ?? $data['barcode'] ?? $data['zpl'] ?? $data[0]['content'] ?? null);

        return [
            'success' => (bool)$zpl,
            'zpl'     => $zpl,
            'raw'     => $data,
            'error'   => $zpl ? '' : 'ZPL içeriği alınamadı. Ham yanıt: ' . json_encode($data),
        ];
    }

    // ─────────────────────────────────────────────
    // PAKET STATÜ GÜNCELLEME
    // ─────────────────────────────────────────────

    /**
     * Shipment package statüsünü günceller.
     * PUT /order/sellers/{id}/shipment-packages/{packageId}
     *
     * @param  string $paketId  ps_kargo_paket_id
     * @param  string $status   Picking | Invoiced
     * @param  array  $lines    [['lineId'=>int,'quantity'=>int], ...]
     * @param  array  $params   Ek parametreler (Invoiced için invoiceNumber vb.)
     * @return array  ['success'=>bool, 'message'=>string]
     */
    public function paketDurumGuncelle(string $paketId, string $status, array $lines = [], array $params = []): array
    {
        if (!$paketId) {
            return ['success' => false, 'message' => 'Paket ID boş.'];
        }

        $body = [
            'status' => $status,
            'lines'  => $lines,
            'params' => (object)$params,
        ];

        $sonuc = $this->request(
            'PUT',
            "/order/sellers/{id}/shipment-packages/{$paketId}",
            [],
            $body
            // storeFrontCode header Trendyol'da geçersiz sayıldı — kaldırıldı
        );

        if ($sonuc['success'] || $sonuc['http_code'] === 200) {
            return ['success' => true, 'message' => "Paket statüsü '{$status}' olarak güncellendi."];
        }

        $hata = $sonuc['error']
            ?: ($sonuc['data']['message'] ?? ($sonuc['data']['errors'][0]['message'] ?? 'HTTP ' . $sonuc['http_code']));

        return [
            'success' => false,
            'message' => 'API Hatası: ' . $hata,
            'raw'     => $sonuc['data'],
        ];
    }

    // ─────────────────────────────────────────────
    // ÜRÜNLER
    // ─────────────────────────────────────────────

    /**
     * Trendyol'dan ürün listesini çeker (Product V2 — V1 /products 15.10.2026'da kapanıyor).
     *
     * approved   : bir kayıt = bir MODEL, barkodlar variants[] içinde
     * unapproved : bir kayıt = bir barkod (düz yapı), status pendingApproval | rejected
     *
     * @param  int    $page  Sayfa no (0'dan başlar)
     * @param  int    $size  Sayfa başı kayıt (max 100, page x size <= 10.000)
     * @param  string $tip   approved | unapproved
     * @return array ['success' => bool, 'toplam' => int, 'toplam_sayfa' => int, 'urunler' => array, 'error' => string]
     */
    public function getUrunler(int $page = 0, int $size = 100, string $tip = 'approved'): array
    {
        $tip   = $tip === 'unapproved' ? 'unapproved' : 'approved';
        $sonuc = $this->request('GET', "/product/sellers/{id}/products/{$tip}", [
            'page' => $page,
            'size' => min(100, $size),
        ]);

        if (!$sonuc['success']) {
            return ['success' => false, 'toplam' => 0, 'toplam_sayfa' => 1, 'urunler' => [], 'error' => $sonuc['error']];
        }

        $data = $sonuc['data'];

        return [
            'success'      => true,
            'toplam'       => $data['totalElements'] ?? 0,
            'toplam_sayfa' => $data['totalPages']    ?? 1,
            'urunler'      => $data['content']       ?? [],
            'error'        => '',
        ];
    }

    /**
     * V2 ürün kaydını satılabilir birimlere (barkod) açar.
     * approved modeli varyant sayısı kadar, unapproved kaydı tek öğe üretir.
     * Dönen her öğe urunKaydet()'in beklediği düz yapıdadır:
     *   model alanları + varyant alanları en üst seviyede (vatRate, barcode, stockCode...)
     *   + _fiyat, _liste_fiyat, _stok, _onay, _platform_id yardımcı alanları.
     *
     * @param  array  $kayit getUrunler() content[] elemanı
     * @param  string $tip   approved | unapproved
     * @return array
     */
    public static function urunleriNotrle(array $kayit, string $tip): array
    {
        if ($tip === 'unapproved') {
            $durum = strtolower((string)($kayit['status'] ?? ''));
            return [$kayit + [
                '_platform_id' => $kayit['barcode'] ?? '',
                '_liste_fiyat' => $kayit['listPrice'] ?? null,
                '_fiyat'       => $kayit['salePrice'] ?? $kayit['listPrice'] ?? null,
                '_stok'        => $kayit['quantity']  ?? 0,
                '_onay'        => ($durum === 'rejected' || !empty($kayit['rejectReasonDetails'])) ? 'rejected' : 'waiting',
            ]];
        }

        $model = $kayit;
        unset($model['variants']);

        $birimler = [];
        foreach ($kayit['variants'] ?? [] as $v) {
            // Varyant öznitelikleri (Beden vb.) model özniteliklerinin üzerine eklenir
            $birim = array_merge($model, $v);
            $birim['attributes']   = array_merge($kayit['attributes'] ?? [], $v['attributes'] ?? []);
            $birim['_platform_id'] = $v['variantId'] ?? '';
            $birim['_liste_fiyat'] = $v['price']['listPrice'] ?? null;
            $birim['_fiyat']       = $v['price']['salePrice'] ?? $v['price']['listPrice'] ?? null;
            $birim['_stok']        = $v['stock']['quantity']  ?? 0;
            $birim['_onay']        = 'approved';
            $birimler[] = $birim;
        }
        return $birimler;
    }

    /**
     * Onaylı + onaysız tüm ürünleri V2'den çekip PazarYeriUrunler'e yazar.
     * Panel "Ürünleri Çek" bunu kullanır.
     *
     * @return array ['toplam', 'yeni', 'guncellenen', 'hata', 'model']
     * @throws RuntimeException API hatasında
     */
    public function urunleriSenkronla(): array
    {
        $toplam = 0; $yeni = 0; $guncellenen = 0; $hata = 0; $model = 0;

        foreach (['approved', 'unapproved'] as $tip) {
            $sayfa = 0;
            do {
                $cevap = $this->getUrunler($sayfa, 100, $tip);
                if (!$cevap['success']) {
                    throw new RuntimeException("API hatası ({$tip}): " . $cevap['error']);
                }

                foreach ($cevap['urunler'] as $kayit) {
                    $model++;
                    foreach (self::urunleriNotrle($kayit, $tip) as $birim) {
                        $toplam++;
                        $mevcut = !empty($birim['barcode']) ? $this->db->fetchOne(
                            "SELECT pu_id FROM PazarYeriUrunler WHERE pu_kanal_id = ? AND pu_barkod = ?",
                            [$this->kanalId, $birim['barcode']]
                        ) : null;

                        if ($this->urunKaydet($birim)) {
                            $mevcut ? $guncellenen++ : $yeni++;
                        } else {
                            $hata++;
                        }
                    }
                }

                $sayfa++;
            } while ($sayfa < ($cevap['toplam_sayfa'] ?? 1) && $sayfa * 100 < 10000);
        }

        return compact('toplam', 'yeni', 'guncellenen', 'hata', 'model');
    }

    /**
     * Tek bir satılabilir birimi (barkod) PazarYeriUrunler tablosuna yazar (UPSERT).
     *
     * @param  array $urun  urunleriNotrle() çıktısındaki bir öğe
     * @return bool
     */
    public function urunKaydet(array $urun): bool
    {
        try {
            $barkod = $urun['barcode'] ?? null;
            if (!$barkod) {
                return false;
            }

            $gorsel = !empty($urun['images'][0]['url']) ? $urun['images'][0]['url'] : null;

            // Renk — model veya varyant özniteliklerinde
            $renk = null;
            foreach ($urun['attributes'] ?? [] as $attr) {
                if (mb_strtolower($attr['attributeName'] ?? '') === 'renk') {
                    $renk = $attr['attributeValue'] ?? null;
                    break;
                }
            }

            // Marka / kategori V2'de {id, name} nesnesi
            $marka    = $urun['brand']    ?? null;
            $kategori = $urun['category'] ?? null;

            // Ham veri: yardımcı alanlar hariç; vatRate üst seviyede kalır (urun_detay okur)
            $ham = array_filter($urun, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY);

            $data = [
                'pu_platform_urun_id' => (string)($urun['_platform_id'] ?? ''),
                'pu_model_kodu'       => $urun['productMainId'] ?? null,
                'pu_barkod'           => $barkod,
                'pu_stok_kodu'        => $urun['stockCode'] ?? null,
                'pu_adi'              => $urun['title']     ?? null,
                'pu_aciklama'         => isset($urun['description']) ? strip_tags($urun['description']) : null,
                'pu_kategori_id'      => is_array($kategori) ? ($kategori['id']   ?? null) : null,
                'pu_kategori_adi'     => is_array($kategori) ? ($kategori['name'] ?? null) : $kategori,
                'pu_marka_id'         => is_array($marka)    ? ($marka['id']      ?? null) : null,
                'pu_marka_adi'        => is_array($marka)    ? ($marka['name']    ?? null) : $marka,
                'pu_renk'             => $renk,
                'pu_kdv_orani'        => isset($urun['vatRate']) ? (float)$urun['vatRate'] : null,
                'pu_fiyat'            => $urun['_liste_fiyat'] ?? null,
                'pu_indirimli_fiyat'  => $urun['_fiyat']       ?? null,
                'pu_stok'             => (int)($urun['_stok']  ?? 0),
                'pu_onay_durumu'      => $urun['_onay']        ?? 'waiting',
                'pu_gorsel_url'       => $gorsel,
                'pu_son_guncelleme'   => date('Y-m-d H:i:s'),
                'pu_ham_veri'         => json_encode($ham, JSON_UNESCAPED_UNICODE),
                'GuncelleyenKullanici'=> 1,
                'GuncellemeTarihi'    => date('Y-m-d H:i:s'),
            ];

            $mevcut = $this->db->fetchOne(
                "SELECT pu_id FROM PazarYeriUrunler WHERE pu_kanal_id = ? AND pu_barkod = ?",
                [$this->kanalId, $barkod]
            );

            if ($mevcut) {
                // UPDATE: kullanıcının elle girdiği alanları Trendyol verisiyle ezme
                $updateData = $data;
                unset(
                    $updateData['pu_stok_kodu'],       // kullanıcı tarafından atanır
                    $updateData['pu_urun_hizmet_id'],   // FK — kullanıcı eşleştirir
                    $updateData['pu_model_kodu']        // kullanıcı tarafından atanır
                );

                $setClauses = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($updateData)));
                $this->db->execute(
                    "UPDATE PazarYeriUrunler SET {$setClauses} WHERE pu_kanal_id = ? AND pu_barkod = ?",
                    array_merge(array_values($updateData), [$this->kanalId, $barkod])
                );
            } else {
                $data['pu_kanal_id']       = $this->kanalId;
                $data['OlusturanKullanici'] = 1;
                $data['OlusturmaTarihi']   = date('Y-m-d H:i:s');
                $data['Durum']             = 1;

                $kolonlar = implode(', ', array_keys($data));
                $degerler = implode(', ', array_fill(0, count($data), '?'));
                $this->db->execute(
                    "INSERT INTO PazarYeriUrunler ({$kolonlar}) VALUES ({$degerler})",
                    array_values($data)
                );
            }

            return true;

        } catch (Exception $e) {
            error_log('[TrendyolService::urunKaydet] ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────
    // STOK / FİYAT GÜNCELLEME
    // ─────────────────────────────────────────────

    /**
     * Trendyol'da stok ve fiyat günceller.
     * POST /inventory/sellers/{id}/products/price-and-inventory  (max 1000 SKU, aynı gövde 15 dk tekrar edilemez)
     *
     * @param  array $items  [['barcode'=>'...','quantity'=>10,'salePrice'=>99.90,'listPrice'=>119.90], ...]
     * @return array ['success'=>bool, 'message'=>string, 'data'=>array]
     */
    public function stokFiyatGuncelle(array $items): array
    {
        if (empty($items)) {
            return ['success' => false, 'message' => 'Güncellenecek ürün listesi boş.'];
        }

        // Trendyol max 100 ürün/istek kabul eder
        $parcalar = array_chunk($items, 100);
        $hatalar  = [];

        foreach ($parcalar as $parca) {
            $sonuc = $this->request('POST', '/inventory/sellers/{id}/products/price-and-inventory', [], [
                'items' => $parca,
            ]);

            if (!$sonuc['success']) {
                $hatalar[] = $sonuc['error'];
            }
        }

        if (!empty($hatalar)) {
            return [
                'success' => false,
                'message' => implode(' | ', $hatalar),
                'data'    => [],
            ];
        }

        return [
            'success' => true,
            'message' => count($items) . ' ürün başarıyla güncellendi.',
            'data'    => [],
        ];
    }

    // ─────────────────────────────────────────────
    // İADELER
    // ─────────────────────────────────────────────

    /**
     * Trendyol'dan iade (claim) listesini çeker.
     * GET /order/sellers/{id}/claims
     */
    public function getClaims(array $filtreler = []): array
    {
        $query = [
            'page' => $filtreler['page'] ?? 0,
            'size' => $filtreler['size'] ?? 50,
        ];
        if (!empty($filtreler['startDate'])) $query['startDate'] = $filtreler['startDate'];
        if (!empty($filtreler['endDate']))   $query['endDate']   = $filtreler['endDate'];
        if (!empty($filtreler['status']))    $query['status']    = $filtreler['status'];

        $sonuc = $this->request('GET', '/order/sellers/{id}/claims', $query);

        if (!$sonuc['success']) {
            return ['success' => false, 'toplam' => 0, 'toplam_sayfa' => 1, 'iadeler' => [], 'error' => $sonuc['error']];
        }

        $data = $sonuc['data'];
        return [
            'success'      => true,
            'toplam'       => $data['totalElements'] ?? count($data['content'] ?? []),
            'toplam_sayfa' => $data['totalPages']    ?? 1,
            'iadeler'      => $data['content']       ?? [],
            'error'        => '',
        ];
    }

    /**
     * Tek bir iade kaydını PazarYeriIadeler_Eski tablosuna yazar (UPSERT).
     */
    public function iadeKaydet(array $iade): bool
    {
        try {
            $iadeId  = (string)($iade['id'] ?? $iade['claimId'] ?? '');
            if (!$iadeId) return false;

            $iadeTarih = null;
            if (!empty($iade['createdDate'])) {
                $iadeTarih = date('Y-m-d H:i:s', (int)($iade['createdDate'] / 1000));
            }

            $musteri = trim(
                ($iade['customerFirstName'] ?? '') . ' ' . ($iade['customerLastName'] ?? '')
            ) ?: null;

            // Ürün kalemleri
            $urunler = [];
            foreach ($iade['claimItems'] ?? [] as $item) {
                $urunler[] = [
                    'barkod'   => $item['barcode']     ?? null,
                    'adi'      => $item['productName'] ?? null,
                    'miktar'   => $item['quantity']    ?? 1,
                    'fiyat'    => $item['price']       ?? 0,
                    'neden'    => $item['claimReason'] ?? null,
                ];
            }

            $data = [
                'pi_platform_iade_id' => $iadeId,
                'pi_siparis_id'       => (string)($iade['orderId']     ?? ''),
                'pi_siparis_no'       => (string)($iade['orderNumber'] ?? ''),
                'pi_durum'            => $iade['status']               ?? null,
                'pi_iade_nedeni'      => $iade['claimItems'][0]['claimReason'] ?? null,
                'pi_iade_aciklamasi'  => $iade['claimItems'][0]['customerExplanation'] ?? null,
                'pi_musteri_adi'      => $musteri,
                'pi_toplam_tutar'     => $iade['claimItems'][0]['price'] ?? null,
                'pi_urunler'          => json_encode($urunler, JSON_UNESCAPED_UNICODE),
                'pi_iade_tarihi'      => $iadeTarih,
                'pi_son_guncelleme'   => date('Y-m-d H:i:s'),
                'pi_ham_veri'         => json_encode($iade, JSON_UNESCAPED_UNICODE),
                'GuncelleyenKullanici'=> 1,
                'GuncellemeTarihi'    => date('Y-m-d H:i:s'),
            ];

            $mevcut = $this->db->fetchOne(
                "SELECT pi_id FROM PazarYeriIadeler_Eski WHERE pi_kanal_id = ? AND pi_platform_iade_id = ?",
                [$this->kanalId, $iadeId]
            );

            if ($mevcut) {
                $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
                $this->db->execute(
                    "UPDATE PazarYeriIadeler_Eski SET $set WHERE pi_kanal_id = ? AND pi_platform_iade_id = ?",
                    array_merge(array_values($data), [$this->kanalId, $iadeId])
                );
            } else {
                $data['pi_kanal_id']       = $this->kanalId;
                $data['OlusturanKullanici'] = 1;
                $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                $data['Durum']              = 1;
                $cols = implode(', ', array_keys($data));
                $vals = implode(', ', array_fill(0, count($data), '?'));
                $this->db->execute(
                    "INSERT INTO PazarYeriIadeler_Eski ($cols) VALUES ($vals)",
                    array_values($data)
                );
            }

            return true;
        } catch (Exception $e) {
            error_log('[TrendyolService::iadeKaydet] ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────
    // MÜŞTERİ SORULARI
    // ─────────────────────────────────────────────

    /**
     * Trendyol'dan müşteri sorularını çeker.
     * GET /qna/sellers/{id}/questions/filter
     */
    public function getSorular(array $filtreler = []): array
    {
        $query = [
            'page' => $filtreler['page'] ?? 0,
            'size' => $filtreler['size'] ?? 50,
        ];
        if (!empty($filtreler['status'])) $query['status'] = $filtreler['status'];

        $sonuc = $this->request('GET', '/qna/sellers/{id}/questions/filter', $query);

        if (!$sonuc['success']) {
            return ['success' => false, 'toplam' => 0, 'toplam_sayfa' => 1, 'sorular' => [], 'error' => $sonuc['error']];
        }

        $data = $sonuc['data'];
        return [
            'success'      => true,
            'toplam'       => $data['totalElements'] ?? 0,
            'toplam_sayfa' => $data['totalPages']    ?? 1,
            'sorular'      => $data['content']       ?? [],
            'error'        => '',
        ];
    }

    /**
     * Trendyol'da müşteri sorusunu cevaplar.
     * POST /qna/sellers/{id}/questions/{questionId}/answers
     */
    public function soruCevapla(int $questionId, string $cevap): array
    {
        $sonuc = $this->request(
            'POST',
            "/qna/sellers/{id}/questions/{$questionId}/answers",
            [],
            ['text' => $cevap]
        );

        return [
            'success' => $sonuc['success'],
            'message' => $sonuc['success'] ? 'Cevap gönderildi.' : $sonuc['error'],
        ];
    }

    /**
     * Tek bir soruyu PazarYeriSorular tablosuna kaydeder (UPSERT).
     */
    public function soruKaydet(array $soru): bool
    {
        try {
            $soruId = (string)($soru['id'] ?? $soru['messageId'] ?? '');
            if (!$soruId) return false;

            $soruTarihi  = !empty($soru['creationDate']) ? date('Y-m-d H:i:s', (int)($soru['creationDate'] / 1000)) : null;

            // Trendyol cevabı "answer" tekil nesne olarak döndürür (answers[] dizisi değil)
            $cevap       = $soru['answer'] ?? null;
            $cevapMetni  = $cevap['text']         ?? null;
            $cevapTarihi = !empty($cevap['creationDate'])
                ? date('Y-m-d H:i:s', (int)($cevap['creationDate'] / 1000))
                : null;

            $data = [
                'ps_platform_soru_id' => $soruId,
                'ps_urun_id'          => (string)($soru['productId']   ?? ''),
                'ps_urun_adi'         => $soru['productName']          ?? null,
                'ps_musteri_adi'      => $soru['customerName']         ?? null,
                'ps_soru_metni'       => $soru['text']                 ?? null,
                'ps_cevap_metni'      => $cevapMetni,
                'ps_durum'            => $soru['status']               ?? 'WAITING_FOR_ANSWER',
                'ps_soru_tarihi'      => $soruTarihi,
                'ps_cevap_tarihi'     => $cevapTarihi,
                'ps_ham_veri'         => json_encode($soru, JSON_UNESCAPED_UNICODE),
                'GuncelleyenKullanici'=> 1,
                'GuncellemeTarihi'    => date('Y-m-d H:i:s'),
            ];


            $mevcut = $this->db->fetchOne(
                "SELECT ps_id FROM PazarYeriSorular WHERE ps_kanal_id = ? AND ps_platform_soru_id = ?",
                [$this->kanalId, $soruId]
            );

            if ($mevcut) {
                // Eğer daha önce cevaplanmışsa ve yeni durumda da WAITING falan gelmiyorsa, durumu bozmamak iyi olabilir
                // Ancak platform "ANSWERED" diyorsa güncelleyelim.
                $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
                $this->db->execute(
                    "UPDATE PazarYeriSorular SET $set WHERE ps_kanal_id = ? AND ps_platform_soru_id = ?",
                    array_merge(array_values($data), [$this->kanalId, $soruId])
                );
            } else {
                $data['ps_kanal_id']        = $this->kanalId;
                $data['OlusturanKullanici'] = 1;
                $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                $data['Durum']              = 1;
                $cols = implode(', ', array_keys($data));
                $vals = implode(', ', array_fill(0, count($data), '?'));
                $this->db->execute(
                    "INSERT INTO PazarYeriSorular ($cols) VALUES ($vals)",
                    array_values($data)
                );
            }

            return true;
        } catch (Exception $e) {
            error_log('[TrendyolService::soruKaydet] ' . $e->getMessage());
            return false;
        }
    }
}

