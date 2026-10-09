<?php

require_once __DIR__ . '/TurkbelgeAPI.php';

class CariTaramaHelper
{
    private $db;
    private $sektorler = null;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function processNextJob(): array
    {
        $job = $this->db->fetchOne(
            "SELECT TOP 1 *
             FROM dbo.CariTaramaIsleri
             WHERE CariTaramaIsleri_Durum IN ('Bekliyor', 'Devam Ediyor')
               AND Durum = 1
             ORDER BY CASE WHEN CariTaramaIsleri_Durum = 'Devam Ediyor' THEN 0 ELSE 1 END,
                      CariTaramaIsleri_Id ASC"
        );

        if (!$job) {
            return ['success' => true, 'message' => 'Bekleyen tarama işi bulunamadı.'];
        }

        return $this->runJob((int)$job['CariTaramaIsleri_Id']);
    }

    public function runJob(int $jobId): array
    {
        $job = $this->db->fetchOne(
            "SELECT * FROM dbo.CariTaramaIsleri WHERE CariTaramaIsleri_Id = ? AND Durum = 1",
            [$jobId]
        );

        if (!$job) {
            throw new Exception('Tarama işi bulunamadı.');
        }

        $maxPages = max(1, (int)($job['CariTaramaIsleri_MaksimumSayfa'] ?? 50));
        $startUrl = $this->normalizeUrl($job['CariTaramaIsleri_BaslangicUrl'] ?: $job['CariTaramaIsleri_Domain']);

        $this->db->update('CariTaramaIsleri', [
            'CariTaramaIsleri_Durum' => 'Devam Ediyor',
            'CariTaramaIsleri_BaslamaTarihi' => $job['CariTaramaIsleri_BaslamaTarihi'] ?: date('Y-m-d H:i:s'),
            'CariTaramaIsleri_HataMesaji' => null,
            'GuncellemeTarihi' => date('Y-m-d H:i:s')
        ], [
            'CariTaramaIsleri_Id' => $jobId
        ]);

        try {
            $crawl = $this->crawlSite($startUrl, $maxPages, $jobId);
            $aggregated = $this->aggregateSiteData($crawl['pages']);
            $this->saveResults($jobId, $aggregated, $crawl);

            $this->db->update('CariTaramaIsleri', [
                'CariTaramaIsleri_Durum' => 'Tamamlandi',
                'CariTaramaIsleri_TarananSayfa' => $crawl['visited_count'],
                'CariTaramaIsleri_ToplamKayit' => $aggregated['company'] ? 1 : 0,
                'CariTaramaIsleri_ToplamYetkili' => count($aggregated['contacts']),
                'CariTaramaIsleri_SonIslenenUrl' => $crawl['last_url'],
                'CariTaramaIsleri_BitisTarihi' => date('Y-m-d H:i:s'),
                'GuncellemeTarihi' => date('Y-m-d H:i:s')
            ], [
                'CariTaramaIsleri_Id' => $jobId
            ]);

            return [
                'success' => true,
                'message' => 'Tarama tamamlandı.',
                'job_id' => $jobId,
                'visited_count' => $crawl['visited_count'],
                'contact_count' => count($aggregated['contacts'])
            ];
        } catch (Exception $e) {
            $this->db->update('CariTaramaIsleri', [
                'CariTaramaIsleri_Durum' => 'Hata',
                'CariTaramaIsleri_HataMesaji' => mb_substr($e->getMessage(), 0, 1000),
                'CariTaramaIsleri_BitisTarihi' => date('Y-m-d H:i:s'),
                'GuncellemeTarihi' => date('Y-m-d H:i:s')
            ], [
                'CariTaramaIsleri_Id' => $jobId
            ]);

            throw $e;
        }
    }

    public function importRecord(int $kayitId, array $yetkiliIds, int $kullaniciId): array
    {
        $kayit = $this->db->fetchOne(
            "SELECT * FROM dbo.CariTaramaKayitlari WHERE CariTaramaKayitlari_Id = ? AND Durum = 1",
            [$kayitId]
        );

        if (!$kayit) {
            throw new Exception('Aktarılacak firma kaydı bulunamadı.');
        }

        $cariId = null;
        if (!empty($kayit['CariTaramaKayitlari_VergiNo'])) {
            $mevcutCari = $this->db->fetchOne(
                "SELECT cari_id FROM dbo.Cari WHERE cari_vergi_no = ?",
                [$kayit['CariTaramaKayitlari_VergiNo']]
            );
            $cariId = $mevcutCari['cari_id'] ?? null;
        }

        $cariData = [
            'cari_adi' => $kayit['CariTaramaKayitlari_FirmaAdi'] ?: $kayit['CariTaramaKayitlari_Unvan'],
            'cari_unvan' => $kayit['CariTaramaKayitlari_Unvan'] ?: $kayit['CariTaramaKayitlari_FirmaAdi'],
            'cari_vergi_no' => $kayit['CariTaramaKayitlari_VergiNo'],
            'cari_vergi_dairesi' => $kayit['CariTaramaKayitlari_VergiDairesi'],
            'cari_mersis_no' => $kayit['CariTaramaKayitlari_MersisNo'],
            'cari_ticaret_sicil_no' => $kayit['CariTaramaKayitlari_TicaretSicilNo'],
            'cari_telefon' => $kayit['CariTaramaKayitlari_Telefon'],
            'cari_email' => $kayit['CariTaramaKayitlari_Email'],
            'cari_adres' => $kayit['CariTaramaKayitlari_Adres'],
            'cari_sektor_id' => $kayit['CariTaramaKayitlari_SektorId'] ?: null,
            'cari_efatura_mukellef' => (int)($kayit['CariTaramaKayitlari_EfaturaMukellef'] ?? 0),
            'cari_efatura_etiket' => $kayit['CariTaramaKayitlari_EfaturaEtiket'],
            'cari_efatura_tip' => $kayit['CariTaramaKayitlari_EfaturaTip'],
            'cari_efatura_kayit_tarihi' => $kayit['CariTaramaKayitlari_EfaturaKayitTarihi'],
            'cari_efatura_kontrol_tarihi' => !empty($kayit['CariTaramaKayitlari_VergiNo']) ? date('Y-m-d H:i:s') : null,
            'cari_aktif' => 1,
            'cari_SSAAktif' => 0
        ];

        if ($cariId) {
            $cariData['cari_guncelleme_tarihi'] = date('Y-m-d H:i:s');
            $cariData['cari_guncelleyen_kullanici'] = $kullaniciId;
            $this->db->update('Cari', $cariData, ['cari_id' => $cariId]);
        } else {
            $cariData['cari_olusturma_tarihi'] = date('Y-m-d H:i:s');
            $cariData['cari_olusturan_kullanici'] = $kullaniciId;
            $cariId = (int)$this->db->insert('Cari', $cariData);
        }

        $aktarilanYetkililer = [];
        foreach ($yetkiliIds as $yetkiliId) {
            $yetkili = $this->db->fetchOne(
                "SELECT * FROM dbo.CariTaramaYetkilileri WHERE CariTaramaYetkilileri_Id = ? AND Durum = 1",
                [(int)$yetkiliId]
            );

            if (!$yetkili) {
                continue;
            }

            $mevcutYetkili = null;
            if (!empty($yetkili['CariTaramaYetkilileri_Email'])) {
                $mevcutYetkili = $this->db->fetchOne(
                    "SELECT CariYetkili_Id FROM dbo.CariYetkili WHERE CariYetkili_CariId = ? AND CariYetkili_Email = ?",
                    [$cariId, $yetkili['CariTaramaYetkilileri_Email']]
                );
            }

            $yetkiliData = [
                'CariYetkili_CariId' => $cariId,
                'CariYetkili_BirimId' => $yetkili['CariTaramaYetkilileri_BirimId'] ?: null,
                'CariYetkili_Adi' => $yetkili['CariTaramaYetkilileri_Adi'],
                'CariYetkili_Soyadi' => $yetkili['CariTaramaYetkilileri_Soyadi'],
                'CariYetkili_Email' => $yetkili['CariTaramaYetkilileri_Email'],
                'CariYetkili_Telefon' => $yetkili['CariTaramaYetkilileri_Telefon'],
                'CariYetkili_Telefon2' => $yetkili['CariTaramaYetkilileri_Telefon2'],
                'CariYetkili_Ulke' => $yetkili['CariTaramaYetkilileri_Ulke'] ?: null,
                'CariYetkili_Sehir' => $yetkili['CariTaramaYetkilileri_Sehir'] ?: null,
                'CariYetkili_Ilce' => $yetkili['CariTaramaYetkilileri_Ilce'] ?: null,
                'CariYetkili_Not' => $yetkili['CariTaramaYetkilileri_Not'],
                'Durum' => 1
            ];

            if ($mevcutYetkili) {
                $yetkiliData['GuncelleyenKullanici'] = $kullaniciId;
                $yetkiliData['GuncellemeTarihi'] = date('Y-m-d H:i:s');
                $this->db->update('CariYetkili', $yetkiliData, ['CariYetkili_Id' => $mevcutYetkili['CariYetkili_Id']]);
                $aktarilanYetkililer[] = (int)$mevcutYetkili['CariYetkili_Id'];
            } else {
                $yetkiliData['OlusturanKullanici'] = $kullaniciId;
                $yetkiliData['OlusturmaTarihi'] = date('Y-m-d H:i:s');
                $aktarilanYetkiliId = (int)$this->db->insert('CariYetkili', $yetkiliData);
                $aktarilanYetkililer[] = $aktarilanYetkiliId;
            }

            $this->db->update('CariTaramaYetkilileri', [
                'CariTaramaYetkilileri_AktarildiYetkiliId' => end($aktarilanYetkililer),
                'CariTaramaYetkilileri_Aktarildi' => 1,
                'GuncelleyenKullanici' => $kullaniciId,
                'GuncellemeTarihi' => date('Y-m-d H:i:s')
            ], [
                'CariTaramaYetkilileri_Id' => (int)$yetkiliId
            ]);
        }

        $this->db->update('CariTaramaKayitlari', [
            'CariTaramaKayitlari_AktarildiCariId' => $cariId,
            'CariTaramaKayitlari_Aktarildi' => 1,
            'GuncelleyenKullanici' => $kullaniciId,
            'GuncellemeTarihi' => date('Y-m-d H:i:s')
        ], [
            'CariTaramaKayitlari_Id' => $kayitId
        ]);

        return [
            'success' => true,
            'cari_id' => $cariId,
            'yetkili_ids' => $aktarilanYetkililer
        ];
    }

    private function crawlSite(string $startUrl, int $maxPages, int $jobId): array
    {
        $parsedStart = parse_url($startUrl);
        if (empty($parsedStart['host'])) {
            throw new Exception('Geçersiz başlangıç adresi.');
        }

        $host = strtolower($parsedStart['host']);
        $allowedHosts = $this->buildAllowedHosts($host);
        $queue = [$startUrl];
        $visited = [];
        $pages = [];
        $lastUrl = $startUrl;

        while (!empty($queue) && count($visited) < $maxPages) {
            $url = array_shift($queue);
            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $lastUrl = $url;

            $fetched = $this->fetchUrl($url);
            if (!$fetched['success']) {
                $pages[] = [
                    'url' => $url,
                    'title' => '',
                    'html' => '',
                    'text' => '',
                    'status_code' => $fetched['status_code'],
                    'error' => $fetched['error']
                ];
                continue;
            }

            $page = $this->extractPageData($url, $fetched['body']);
            $pages[] = $page;

            $discovered = $this->extractLinks($fetched['body'], $url, $allowedHosts);
            $priorityLinks = [];
            $normalLinks = [];
            foreach ($discovered as $link) {
                if (isset($visited[$link]) || in_array($link, $queue, true)) {
                    continue;
                }
                if ($this->isPriorityPage($link)) {
                    $priorityLinks[] = $link;
                } else {
                    $normalLinks[] = $link;
                }
            }
            // Öncelikli sayfaları (iletisim, hakkimizda, ekip vb.) kuyruğun başına al
            $queue = array_merge($priorityLinks, $queue, $normalLinks);

            $this->db->update('CariTaramaIsleri', [
                'CariTaramaIsleri_TarananSayfa' => count($visited),
                'CariTaramaIsleri_SonIslenenUrl' => $url,
                'GuncellemeTarihi' => date('Y-m-d H:i:s')
            ], [
                'CariTaramaIsleri_Id' => $jobId
            ]);
        }

        return [
            'pages' => $pages,
            'visited_count' => count($visited),
            'last_url' => $lastUrl
        ];
    }

    private function aggregateSiteData(array $pages): array
    {
        $allText = [];
        $allEmails = [];
        $allPhones = [];
        $allTaxIds = [];
        $allMersis = [];
        $allSicil = [];
        $allTitles = [];
        $contacts = [];
        $firmaKaynakUrl = null;
        $adres = null;

        foreach ($pages as $page) {
            if (empty($page['text'])) {
                continue;
            }

            $allText[] = $page['text'];
            if (!empty($page['title'])) {
                $allTitles[] = $page['title'];
            }

            $emails = $this->extractEmails($page['html']);
            $phones = $this->extractPhones($page['text']);
            $taxIds = $this->extractTaxIds($page['text']);
            $mersis = $this->extractMersis($page['text']);
            $sicil = $this->extractTicaretSicil($page['text']);
            $pageContacts = $this->extractContacts($page['text'], $page['html'] ?? '', $page['url'], $emails, $phones);

            if (($firmaKaynakUrl === null && (!empty($emails) || !empty($phones) || !empty($taxIds))) || $this->isPriorityPage($page['url'])) {
                $firmaKaynakUrl = $page['url'];
            }

            if ($adres === null) {
                $adres = $this->extractAddress($page['text']);
            }

            foreach ($emails as $email) {
                $allEmails[$email] = $email;
            }
            foreach ($phones as $phone) {
                $allPhones[$phone] = $phone;
            }
            foreach ($taxIds as $taxId) {
                $allTaxIds[$taxId] = $taxId;
            }
            foreach ($mersis as $mersisNo) {
                $allMersis[$mersisNo] = $mersisNo;
            }
            foreach ($sicil as $sicilNo) {
                $allSicil[$sicilNo] = $sicilNo;
            }
            foreach ($pageContacts as $contact) {
                $key = ($contact['email'] ?: '') . '|' . ($contact['telefon'] ?: '') . '|' . ($contact['adi'] ?: '') . '|' . ($contact['soyadi'] ?: '');
                if (!isset($contacts[$key]) || $contacts[$key]['guven_skoru'] < $contact['guven_skoru']) {
                    $contacts[$key] = $contact;
                }
            }
        }

        $siteText = trim(implode("\n", $allText));
        $ogSiteNames = array_values(array_filter(array_column($pages, 'og_site_name')));
        $firmaAdi = $this->resolveCompanyName($allTitles, $siteText, $ogSiteNames);
        $unvan = $firmaAdi;
        $sektor = $this->resolveSector($siteText);
        $primaryDomain = $this->extractPrimaryDomainFromPages($pages);

        // API kullanmadan Google/LinkedIn/sosyal kaynaklardan sinyal topla
        $external = $this->collectExternalSignals($primaryDomain, $firmaAdi);
        foreach ($external['emails'] as $email) {
            $allEmails[$email] = $email;
        }
        foreach ($external['phones'] as $phone) {
            $allPhones[$phone] = $phone;
        }
        foreach ($external['contacts'] as $contact) {
            $key = ($contact['email'] ?: '') . '|' . ($contact['telefon'] ?: '') . '|' . ($contact['adi'] ?: '') . '|' . ($contact['soyadi'] ?: '');
            if (!isset($contacts[$key]) || $contacts[$key]['guven_skoru'] < $contact['guven_skoru']) {
                $contacts[$key] = $contact;
            }
        }

        $company = [
            'kaynak_url' => $firmaKaynakUrl,
            'firma_adi' => $firmaAdi,
            'unvan' => $unvan,
            'vergi_no' => array_key_first($allTaxIds),
            'vergi_dairesi' => $this->extractVergiDairesi($siteText),
            'mersis_no' => array_key_first($allMersis),
            'ticaret_sicil_no' => array_key_first($allSicil),
            'telefon' => array_key_first($allPhones),
            'email' => array_key_first($allEmails),
            'adres' => $adres,
            'sektor_id' => $sektor['id'],
            'sektor_metni' => $sektor['matched_text'],
            'sektor_skoru' => $sektor['score'],
            'sektor_onerileri' => $sektor['suggestions'],
            'efatura_mukellef' => 0,
            'efatura_etiket' => null,
            'efatura_tip' => null,
            'efatura_kayit_tarihi' => null,
            'ham_veri' => json_encode([
                'sayfa_sayisi' => count($pages),
                'basliklar' => array_values(array_unique($allTitles)),
                'emails' => array_values($allEmails),
                'phones' => array_values($allPhones),
                'harici_kaynaklar' => $external
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];

        if (!empty($company['vergi_no'])) {
            $this->enrichWithTurkbelge($company);
            if (!empty($company['turkbelge_unvan'])) {
                $company['unvan'] = $company['turkbelge_unvan'];
                if (empty($company['firma_adi'])) {
                    $company['firma_adi'] = $company['turkbelge_unvan'];
                }
            }
        }

        return [
            'company' => $company,
            'contacts' => array_values($contacts)
        ];
    }

    private function saveResults(int $jobId, array $aggregated, array $crawl): void
    {
        $this->db->execute("DELETE FROM dbo.CariTaramaYetkilileri WHERE CariTaramaYetkilileri_KayitId IN (SELECT CariTaramaKayitlari_Id FROM dbo.CariTaramaKayitlari WHERE CariTaramaKayitlari_IsId = ?)", [$jobId]);
        $this->db->execute("DELETE FROM dbo.CariTaramaKayitlari WHERE CariTaramaKayitlari_IsId = ?", [$jobId]);

        if (empty($aggregated['company'])) {
            return;
        }

        $company = $aggregated['company'];
        $kayitId = (int)$this->db->insert('CariTaramaKayitlari', [
            'CariTaramaKayitlari_IsId' => $jobId,
            'CariTaramaKayitlari_KaynakUrl' => $company['kaynak_url'],
            'CariTaramaKayitlari_FirmaAdi' => $company['firma_adi'],
            'CariTaramaKayitlari_Unvan' => $company['unvan'],
            'CariTaramaKayitlari_VergiNo' => $company['vergi_no'],
            'CariTaramaKayitlari_VergiDairesi' => $company['vergi_dairesi'],
            'CariTaramaKayitlari_MersisNo' => $company['mersis_no'],
            'CariTaramaKayitlari_TicaretSicilNo' => $company['ticaret_sicil_no'],
            'CariTaramaKayitlari_Telefon' => $company['telefon'],
            'CariTaramaKayitlari_Email' => $company['email'],
            'CariTaramaKayitlari_Adres' => $company['adres'],
            'CariTaramaKayitlari_SektorId' => $company['sektor_id'] ?: null,
            'CariTaramaKayitlari_SektorMetni' => $company['sektor_metni'],
            'CariTaramaKayitlari_SektorSkoru' => $company['sektor_skoru'],
            'CariTaramaKayitlari_SektorOnerileri' => $company['sektor_onerileri'],
            'CariTaramaKayitlari_EfaturaMukellef' => $company['efatura_mukellef'],
            'CariTaramaKayitlari_EfaturaEtiket' => $company['efatura_etiket'],
            'CariTaramaKayitlari_EfaturaTip' => $company['efatura_tip'],
            'CariTaramaKayitlari_EfaturaKayitTarihi' => $company['efatura_kayit_tarihi'],
            'CariTaramaKayitlari_HamVeri' => $company['ham_veri'],
            'CariTaramaKayitlari_Aktarildi' => 0,
            'OlusturanKullanici' => 1,
            'OlusturmaTarihi' => date('Y-m-d H:i:s'),
            'GuncelleyenKullanici' => 1,
            'GuncellemeTarihi' => date('Y-m-d H:i:s'),
            'Durum' => 1
        ]);

        foreach ($aggregated['contacts'] as $contact) {
            $this->db->insert('CariTaramaYetkilileri', [
                'CariTaramaYetkilileri_KayitId' => $kayitId,
                'CariTaramaYetkilileri_KaynakUrl' => $contact['kaynak_url'],
                'CariTaramaYetkilileri_BirimId' => null,
                'CariTaramaYetkilileri_Adi' => $contact['adi'],
                'CariTaramaYetkilileri_Soyadi' => $contact['soyadi'],
                'CariTaramaYetkilileri_Gorev' => $contact['gorev'],
                'CariTaramaYetkilileri_Email' => $contact['email'],
                'CariTaramaYetkilileri_Telefon' => $contact['telefon'],
                'CariTaramaYetkilileri_Telefon2' => $contact['telefon2'],
                'CariTaramaYetkilileri_Not' => $contact['not'],
                'CariTaramaYetkilileri_GuvenSkoru' => $contact['guven_skoru'],
                'CariTaramaYetkilileri_HamVeri' => $contact['ham_veri'],
                'CariTaramaYetkilileri_Aktarildi' => 0,
                'OlusturanKullanici' => 1,
                'OlusturmaTarihi' => date('Y-m-d H:i:s'),
                'GuncelleyenKullanici' => 1,
                'GuncellemeTarihi' => date('Y-m-d H:i:s'),
                'Durum' => 1
            ]);
        }
    }

    private function normalizeUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new Exception('Domain veya URL boş olamaz.');
        }

        if (!preg_match('~^https?://~i', $value)) {
            $value = 'https://' . $value;
        }

        return rtrim($value, '/');
    }

    private function fetchUrl(string $url): array
    {
        $headers = [
            'User-Agent: Mozilla/5.0 (compatible; OrnekBot/1.0; +https://ornekfirma.com)',
            'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8'
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => $headers
            ]);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($body === false || $error) {
                return ['success' => false, 'error' => $error ?: 'Sayfa alınamadı.', 'status_code' => $statusCode];
            }

            if (stripos($contentType, 'text/html') === false && stripos($contentType, 'application/xhtml') === false) {
                return ['success' => false, 'error' => 'HTML olmayan içerik atlandı.', 'status_code' => $statusCode];
            }

            return ['success' => true, 'body' => $body, 'status_code' => $statusCode];
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers)
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['success' => false, 'error' => 'Sayfa alınamadı.', 'status_code' => 0];
        }
        return ['success' => true, 'body' => $body, 'status_code' => 200];
    }

    private function extractPageData(string $url, string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $title = '';
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        $text = preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // og:site_name veya application-name meta etiketi (firma adı tespiti için en güvenilir kaynak)
        $ogSiteName = '';
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//meta[@property="og:site_name"]') ?: [] as $metaNode) {
            if ($metaNode instanceof DOMElement) {
                $ogSiteName = trim((string)$metaNode->getAttribute('content'));
            }
            break;
        }
        if (!$ogSiteName) {
            foreach ($xpath->query('//meta[@name="application-name"]') ?: [] as $metaNode) {
                if ($metaNode instanceof DOMElement) {
                    $ogSiteName = trim((string)$metaNode->getAttribute('content'));
                }
                break;
            }
        }

        return [
            'url' => $url,
            'title' => $title,
            'og_site_name' => $ogSiteName,
            'html' => $html,
            'text' => $text,
            'status_code' => 200,
            'error' => null
        ];
    }

    private function extractLinks(string $html, string $currentUrl, array $allowedHosts): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $links = [];
        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = trim((string)$link->getAttribute('href'));
            if ($href === '' || $this->startsWith($href, '#') || $this->startsWith($href, 'mailto:') || $this->startsWith($href, 'tel:') || $this->startsWith($href, 'javascript:')) {
                continue;
            }

            $absoluteUrl = $this->makeAbsoluteUrl($currentUrl, $href);
            if (!$absoluteUrl) {
                continue;
            }

            $parsed = parse_url($absoluteUrl);
            $candidateHost = mb_strtolower((string)($parsed['host'] ?? ''), 'UTF-8');
            if ($candidateHost === '' || !in_array($candidateHost, $allowedHosts, true)) {
                continue;
            }

            $path = strtolower($parsed['path'] ?? '/');
            if (preg_match('/\.(pdf|jpg|jpeg|png|gif|svg|webp|zip|rar|doc|docx|xls|xlsx|ppt|pptx|mp4|avi|mp3)$/i', $path)) {
                continue;
            }

            $links[] = $this->removeFragment($absoluteUrl);
        }

        return array_values(array_unique($links));
    }

    private function makeAbsoluteUrl(string $base, string $relative): ?string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $relative)) {
            return $relative;
        }

        $baseParts = parse_url($base);
        if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        if ($this->startsWith($relative, '//')) {
            return $scheme . ':' . $relative;
        }

        if ($this->startsWith($relative, '/')) {
            return $scheme . '://' . $host . $port . $relative;
        }

        if ($this->startsWith($relative, '?')) {
            $pathOnly = $baseParts['path'] ?? '/';
            return $scheme . '://' . $host . $port . $pathOnly . $relative;
        }

        $path = $baseParts['path'] ?? '/';
        $dir = preg_replace('~/[^/]*$~', '/', $path);
        $joinedPath = $this->normalizePath($dir . $relative);
        return $scheme . '://' . $host . $port . $joinedPath;
    }

    private function normalizePath(string $path): string
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $result = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($result);
                continue;
            }
            $result[] = $segment;
        }

        return '/' . implode('/', $result);
    }

    private function buildAllowedHosts(string $host): array
    {
        $host = mb_strtolower(trim($host), 'UTF-8');
        if ($host === '') {
            return [];
        }

        $hosts = [$host => $host];
        if ($this->startsWith($host, 'www.')) {
            $base = substr($host, 4);
            if ($base !== '') {
                $hosts[$base] = $base;
            }
        } else {
            $hosts['www.' . $host] = 'www.' . $host;
        }

        return array_values($hosts);
    }

    private function removeFragment(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts) {
            return $url;
        }

        $rebuilt = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '/';
        if (!empty($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        return $rebuilt;
    }

    private function extractEmails(string $content): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $content, $matches);
        $emails = [];
        foreach ($matches[0] ?? [] as $email) {
            $email = mb_strtolower(trim($email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = $email;
            }
        }
        return array_values($emails);
    }

    private function extractPhones(string $content): array
    {
        preg_match_all('/(?:\+?90|0)?\s*\(?\d{3,4}\)?[\s\-\.]*\d{3}[\s\-\.]*\d{2}[\s\-\.]*\d{2}/u', $content, $matches);
        $phones = [];
        foreach ($matches[0] ?? [] as $phone) {
            $normalized = preg_replace('/[^0-9]/', '', $phone);
            if ($this->startsWith($normalized, '90') && strlen($normalized) > 10) {
                $normalized = substr($normalized, 2);
            }
            if ($this->startsWith($normalized, '0') && strlen($normalized) > 10) {
                $normalized = substr($normalized, 1);
            }
            if (strlen($normalized) >= 10 && strlen($normalized) <= 11) {
                $phones[$normalized] = $normalized;
            }
        }
        return array_values($phones);
    }

    private function extractTaxIds(string $content): array
    {
        $patterns = [
            '/(?:vkn|vergi\s*no|vergi\s*kimlik\s*no(?:su)?|tckn|tc\s*kimlik\s*no(?:su)?)\s*[:\-]?\s*(\d{10,11})/iu',
            '/\b\d{10}\b/u'
        ];

        $values = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            foreach ($matches[1] ?? $matches[0] ?? [] as $value) {
                $value = preg_replace('/[^0-9]/', '', $value);
                if (strlen($value) >= 10 && strlen($value) <= 11) {
                    $values[$value] = $value;
                }
            }
        }

        return array_values($values);
    }

    private function extractMersis(string $content): array
    {
        preg_match_all('/(?:mersis(?:\s*no(?:su)?)?)\s*[:\-]?\s*(\d{16})/iu', $content, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    private function extractTicaretSicil(string $content): array
    {
        preg_match_all('/(?:ticaret\s*sicil\s*no(?:su|sı)?)\s*[:\-]?\s*([A-Z0-9\-\/]{3,30})/iu', $content, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    private function extractVergiDairesi(string $content): ?string
    {
        if (preg_match('/vergi\s*dairesi\s*[:\-]?\s*([\p{L}\s]{3,80})/iu', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function extractAddress(string $content): ?string
    {
        if (preg_match('/adres\s*[:\-]?\s*(.{20,220}?)(?:telefon|e-?posta|mail|fax|$)/iu', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function extractContacts(string $content, string $html, string $url, array $emails, array $phones): array
    {
        $contacts = [];

        // HTML kart yapısından kişileri çıkar (ekip/yönetim sayfaları için)
        foreach ($this->extractPersonsFromHtml($html, $url) as $person) {
            $key = ($person['adi'] ?: '') . '|' . ($person['soyadi'] ?: '') . '|' . ($person['email'] ?: '');
            $contacts[$key] = $person;
        }

        foreach ($emails as $email) {
            $local = strstr($email, '@', true) ?: $email;
            $role = $this->guessRoleFromText($content, $local);
            $nameParts = $this->guessNameFromLocalPart($local);

            // HTML'den bulunan kişiyle e-posta eşleştirmesi
            $matched = false;
            if ($nameParts['adi']) {
                foreach ($contacts as &$existing) {
                    if (!empty($existing['adi']) &&
                        mb_strtolower($existing['adi'], 'UTF-8') === mb_strtolower($nameParts['adi'], 'UTF-8') &&
                        empty($existing['email'])) {
                        $existing['email'] = $email;
                        $existing['guven_skoru'] = min(100, $existing['guven_skoru'] + 15);
                        $matched = true;
                        break;
                    }
                }
                unset($existing);
            }

            if (!$matched) {
                $eKey = $email . '|' . ($nameParts['adi'] ?: '') . '|' . ($nameParts['soyadi'] ?: '');
                $contacts[$eKey] = [
                    'kaynak_url' => $url,
                    'adi' => $nameParts['adi'],
                    'soyadi' => $nameParts['soyadi'],
                    'gorev' => $role,
                    'email' => $email,
                    'telefon' => $phones[0] ?? null,
                    'telefon2' => $phones[1] ?? null,
                    'not' => 'Site taramasından otomatik bulundu',
                    'guven_skoru' => $nameParts['adi'] ? 80 : 55,
                    'ham_veri' => json_encode(['email' => $email, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ];
            }
        }

        if (empty($contacts) && !empty($phones)) {
            foreach ($phones as $phone) {
                $contacts[$phone] = [
                    'kaynak_url' => $url,
                    'adi' => null,
                    'soyadi' => null,
                    'gorev' => $this->guessRoleFromText($content, $phone),
                    'email' => null,
                    'telefon' => $phone,
                    'telefon2' => null,
                    'not' => 'Telefon bilgisi site taramasından otomatik bulundu',
                    'guven_skoru' => 40,
                    'ham_veri' => json_encode(['telefon' => $phone, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ];
            }
        }

        return array_values($contacts);
    }

    private function extractPersonsFromHtml(string $html, string $url): array
    {
        if (empty($html)) {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $persons = [];

        // Yaygın kişi/ekip kart class isimlerini ara
        $classPatterns = ['team', 'member', 'person', 'staff', 'ekip', 'kadro', 'personel', 'yonetim', 'yönetim'];
        foreach ($classPatterns as $pattern) {
            $nodes = $xpath->query(
                '//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"' . $pattern . '")]'
            );
            if (!$nodes || $nodes->length === 0) {
                continue;
            }
            foreach ($nodes as $node) {
                $person = $this->parsePersonNode($node, $xpath, $url);
                if ($person) {
                    $key = ($person['adi'] ?: '') . '|' . ($person['soyadi'] ?: '');
                    $persons[$key] = $person;
                }
            }
            if (!empty($persons)) {
                break; // İlk eşleşen seçici yeterli
            }
        }

        // Fallback: metin kalıbından Türkçe isim + görev çıkar
        if (empty($persons)) {
            foreach ($this->extractPersonsFromText(strip_tags($html), $url) as $person) {
                $key = ($person['adi'] ?: '') . '|' . ($person['soyadi'] ?: '');
                $persons[$key] = $person;
            }
        }

        return array_values($persons);
    }

    private function parsePersonNode(DOMNode $node, DOMXPath $xpath, string $url): ?array
    {
        $text = trim($node->textContent ?? '');
        if (mb_strlen($text, 'UTF-8') < 5) {
            return null;
        }

        // İsim için heading / strong ara
        $nameText = '';
        foreach ($xpath->query('.//h2|.//h3|.//h4|.//h5|.//strong', $node) ?: [] as $nameNode) {
            $candidate = trim($nameNode->textContent ?? '');
            if (mb_strlen($candidate, 'UTF-8') >= 3 && preg_match('/\p{Lu}/u', $candidate)) {
                $nameText = $candidate;
                break;
            }
        }

        // Görev için title/position/role/gorev/unvan class'lı element ara
        $roleText = '';
        foreach ($xpath->query('.//*[contains(@class,"title") or contains(@class,"position") or contains(@class,"role") or contains(@class,"gorev") or contains(@class,"unvan")]', $node) ?: [] as $roleNode) {
            $roleText = trim($roleNode->textContent ?? '');
            if ($roleText !== '') {
                break;
            }
        }

        // E-posta ve telefon ara
        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text, $emailMatch);
        $email = isset($emailMatch[0]) ? mb_strtolower($emailMatch[0], 'UTF-8') : null;

        preg_match('/(?:\+?90|0)?\s*\(?\d{3,4}\)?[\s\-\.]*\d{3}[\s\-\.]*\d{2}[\s\-\.]*\d{2}/u', $text, $phoneMatch);
        $rawPhone = isset($phoneMatch[0]) ? preg_replace('/[^0-9]/', '', $phoneMatch[0]) : null;

        // İsim bulunamadıysa metinden Türkçe isim kalıbı çıkar
        if (!$nameText) {
            if (preg_match('/\b([A-ZÇĞIÖŞÜ][a-zçğıöşü]{1,20})\s+([A-ZÇĞIÖŞÜ][a-zçğıöşü]{1,20})\b/u', $text, $nameMatch)) {
                $nameText = $nameMatch[0];
            }
        }

        if (!$nameText) {
            return null;
        }

        $nameParts = preg_split('/\s+/u', trim($nameText), 3) ?: [];
        $adi = $nameParts[0] ?? null;
        $soyadi = isset($nameParts[2])
            ? ($nameParts[1] . ' ' . $nameParts[2])
            : ($nameParts[1] ?? null);

        if (!$adi || mb_strlen($adi, 'UTF-8') < 2) {
            return null;
        }

        return [
            'kaynak_url' => $url,
            'adi' => $adi,
            'soyadi' => $soyadi,
            'gorev' => $roleText ?: null,
            'email' => $email,
            'telefon' => $rawPhone,
            'telefon2' => null,
            'not' => 'HTML kart yapısından otomatik bulundu',
            'guven_skoru' => 85,
            'ham_veri' => json_encode(['isim' => $nameText, 'gorev' => $roleText, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
    }

    private function extractPersonsFromText(string $text, string $url): array
    {
        $persons = [];
        $roleKeywords = [
            'müdür', 'mudur', 'direktör', 'direktor', 'uzman', 'mühendis', 'muhendis',
            'yetkili', 'sorumlu', 'koordinatör', 'koordinator', 'yönetici', 'yonetici',
            'başkan', 'baskan', 'kurucu', 'founder', 'ceo', 'cfo', 'cto',
            'teknik', 'muhasebe', 'finans', 'satış', 'satis', 'proje', 'partner'
        ];
        $skipWords = [
            'Vergi', 'Dairesi', 'Sicil', 'Ticaret', 'Merkez', 'Şube', 'Adres',
            'Telefon', 'Faks', 'Email', 'Posta', 'Türkiye', 'İstanbul', 'Ankara'
        ];

        preg_match_all(
            '/\b([A-ZÇĞIÖŞÜ][a-zçğıöşü]{1,20})\s+([A-ZÇĞIÖŞÜ][a-zçğıöşü]{1,20})\b/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $idx => $matchData) {
            [$fullName, $offset] = $matchData;
            $adi = $matches[1][$idx][0];
            $soyadi = $matches[2][$idx][0];

            if (in_array($adi, $skipWords, true) || in_array($soyadi, $skipWords, true)) {
                continue;
            }

            $windowStart = max(0, $offset - 150);
            $window = mb_substr($text, $windowStart, $offset - $windowStart + mb_strlen($fullName, 'UTF-8') + 150, 'UTF-8');
            $windowLower = mb_strtolower($window, 'UTF-8');

            $foundRole = null;
            foreach ($roleKeywords as $keyword) {
                if (mb_stripos($windowLower, $keyword, 0, 'UTF-8') !== false) {
                    if (preg_match('/(' . preg_quote($keyword, '/') . '[^,\n\r\.]{0,50})/iu', $window, $roleMatch)) {
                        $foundRole = trim($roleMatch[1]);
                    } else {
                        $foundRole = mb_strtoupper(mb_substr($keyword, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($keyword, 1, null, 'UTF-8');
                    }
                    break;
                }
            }

            if ($foundRole) {
                $key = $adi . '|' . $soyadi;
                if (!isset($persons[$key])) {
                    $persons[$key] = [
                        'kaynak_url' => $url,
                        'adi' => $adi,
                        'soyadi' => $soyadi,
                        'gorev' => $foundRole,
                        'email' => null,
                        'telefon' => null,
                        'telefon2' => null,
                        'not' => 'Metin kalıbından otomatik bulundu',
                        'guven_skoru' => 65,
                        'ham_veri' => json_encode(['isim' => $fullName, 'gorev' => $foundRole, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ];
                }
            }
        }

        return array_values($persons);
    }

    private function guessRoleFromText(string $content, string $needle): ?string
    {
        $haystack = mb_strtolower($content, 'UTF-8');
        $position = mb_stripos($haystack, mb_strtolower($needle, 'UTF-8'), 0, 'UTF-8');
        $window = $position !== false ? mb_substr($haystack, max(0, $position - 120), 240, 'UTF-8') : $haystack;

        $roles = ['muhasebe', 'satis', 'satış', 'destek', 'teknik', 'yonetim', 'yönetim', 'ik', 'insan kaynaklari', 'insan kaynakları', 'operasyon', 'finans', 'genel mudur', 'genel müdür'];
        foreach ($roles as $role) {
            if (mb_stripos($window, $role, 0, 'UTF-8') !== false) {
                return mb_strtoupper($role, 'UTF-8');
            }
        }

        return null;
    }

    private function guessNameFromLocalPart(string $local): array
    {
        $local = preg_replace('/[^a-zA-Z\.\-_]/', ' ', $local);
        $parts = preg_split('/[\.\-_\s]+/', trim($local)) ?: [];
        $blocked = ['info', 'iletisim', 'contact', 'destek', 'support', 'admin', 'sales', 'satis', 'muhasebe', 'ik', 'hr', 'kariyer'];
        $filtered = [];
        foreach ($parts as $part) {
            if (strlen($part) < 2 || in_array(mb_strtolower($part, 'UTF-8'), $blocked, true)) {
                continue;
            }
            $filtered[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
        }

        return [
            'adi' => $filtered[0] ?? null,
            'soyadi' => $filtered[1] ?? null
        ];
    }

    private function resolveCompanyName(array $titles, string $siteText, array $ogSiteNames = []): ?string
    {
        // Önce og:site_name — en güvenilir kaynak
        foreach ($ogSiteNames as $name) {
            $name = trim($name);
            if (mb_strlen($name, 'UTF-8') >= 3) {
                return $name;
            }
        }

        foreach ($titles as $title) {
            $title = trim(preg_replace('/\s*[\|\-–].*/u', '', $title));
            if (mb_strlen($title, 'UTF-8') >= 3) {
                return $title;
            }
        }

        if (preg_match('/([\p{L}\s]+(?:ltd\.?\s*şti\.?|anonim\s*şirketi|a\.ş\.?|sanayi\s*ve\s*ticaret))/iu', $siteText, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function resolveSector(string $siteText): array
    {
        $sectorRows = $this->getSektorler();
        $text = mb_strtolower($siteText, 'UTF-8');
        $scores = [];

        foreach ($sectorRows as $sector) {
            $sectorName = (string)$sector['CariSektor_Adi'];
            $tokens = preg_split('/\s+/u', mb_strtolower($sectorName, 'UTF-8')) ?: [];
            $score = 0;
            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token === '' || mb_strlen($token, 'UTF-8') < 3) {
                    continue;
                }
                if (mb_stripos($text, $token, 0, 'UTF-8') !== false) {
                    $score += 20;
                }
            }

            if ($score > 0) {
                $scores[] = [
                    'id' => (int)$sector['CariSektor_Id'],
                    'name' => $sectorName,
                    'score' => min($score, 100)
                ];
            }
        }

        usort($scores, static function ($left, $right) {
            return $right['score'] <=> $left['score'];
        });

        return [
            'id' => $scores[0]['id'] ?? null,
            'matched_text' => $scores[0]['name'] ?? null,
            'score' => $scores[0]['score'] ?? 0,
            'suggestions' => json_encode(array_slice(array_column($scores, 'name'), 0, 3), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
    }

    private function getSektorler(): array
    {
        if ($this->sektorler === null) {
            $this->sektorler = $this->db->fetchAll(
                "SELECT CariSektor_Id, CariSektor_Adi FROM dbo.Cari_Sektorler WHERE Durum = 1 ORDER BY CariSektor_Sira, CariSektor_Adi"
            );
        }

        return $this->sektorler;
    }

    private function enrichWithTurkbelge(array &$company): void
    {
        try {
            $api = new TurkbelgeAPI($this->db, 'PROD');
            $efaturaInfo = $api->checkUser($company['vergi_no']);
            if ($efaturaInfo) {
                $company['turkbelge_unvan'] = $efaturaInfo['unvan'] ?? null;
                $company['efatura_mukellef'] = !empty($efaturaInfo['efatura_mukellef']) ? 1 : 0;
                $company['efatura_etiket'] = $efaturaInfo['etiket'] ?? null;
                $company['efatura_tip'] = $efaturaInfo['tip'] ?? null;
                $company['efatura_kayit_tarihi'] = !empty($efaturaInfo['kayit_tarihi'])
                    ? date('Y-m-d H:i:s', strtotime($efaturaInfo['kayit_tarihi']))
                    : null;
            }
        } catch (Exception $e) {
            error_log('CariTarama Turkbelge hatası: ' . $e->getMessage());
        }
    }

    private function isPriorityPage(string $url): bool
    {
        $url = mb_strtolower($url, 'UTF-8');
        return preg_match('/iletisim|hakkimizda|kurumsal|kvkk|gizlilik|ekip|yonetim|sube|şube/u', $url) === 1;
    }

    private function extractPrimaryDomainFromPages(array $pages): ?string
    {
        foreach ($pages as $page) {
            if (empty($page['url'])) {
                continue;
            }
            $parts = parse_url((string)$page['url']);
            if (!empty($parts['host'])) {
                return mb_strtolower((string)$parts['host'], 'UTF-8');
            }
        }
        return null;
    }

    private function collectExternalSignals(?string $domain, ?string $firmaAdi): array
    {
        $result = [
            'domain' => $domain,
            'linkedin_urls' => [],
            'google_maps_urls' => [],
            'social_urls' => [],
            'emails' => [],
            'phones' => [],
            'contacts' => [],
            'source_hits' => []
        ];

        if (!$domain) {
            return $result;
        }

        $companyTerm = trim((string)$firmaAdi);
        if ($companyTerm === '') {
            $companyTerm = $domain;
        }

        $queries = [
            '"' . $domain . '" "linkedin.com/company"',
            '"' . $domain . '" "linkedin.com/in"',
            '"' . $companyTerm . '" "google.com/maps"',
            '"' . $domain . '" "facebook.com" OR "instagram.com" OR "x.com"',
        ];

        foreach ($queries as $query) {
            $hit = $this->searchGoogleWithoutApi($query);
            if (count($hit['urls']) === 0) {
                $hit = $this->searchDuckDuckGoWithoutApi($query);
            }
            $result['source_hits'][] = [
                'query' => $query,
                'result_count' => count($hit['urls'])
            ];

            foreach ($hit['urls'] as $url) {
                $urlLower = mb_strtolower($url, 'UTF-8');
                if (mb_stripos($urlLower, 'linkedin.com/', 0, 'UTF-8') !== false) {
                    $result['linkedin_urls'][$url] = $url;
                }
                if (mb_stripos($urlLower, 'google.com/maps', 0, 'UTF-8') !== false || mb_stripos($urlLower, 'maps.app.goo.gl', 0, 'UTF-8') !== false) {
                    $result['google_maps_urls'][$url] = $url;
                }
                if (mb_stripos($urlLower, 'facebook.com/', 0, 'UTF-8') !== false || mb_stripos($urlLower, 'instagram.com/', 0, 'UTF-8') !== false || mb_stripos($urlLower, 'x.com/', 0, 'UTF-8') !== false) {
                    $result['social_urls'][$url] = $url;
                }
            }

            foreach ($hit['emails'] as $email) {
                $result['emails'][$email] = $email;
            }
            foreach ($hit['phones'] as $phone) {
                $result['phones'][$phone] = $phone;
            }
        }

        $candidateUrls = array_values(array_unique(array_merge(
            array_values($result['linkedin_urls']),
            array_values($result['google_maps_urls']),
            array_values($result['social_urls'])
        )));
        $harvested = $this->harvestExternalContactSignals($candidateUrls);
        foreach ($harvested['emails'] as $email) {
            $result['emails'][$email] = $email;
        }
        foreach ($harvested['phones'] as $phone) {
            $result['phones'][$phone] = $phone;
        }
        $result['contacts'] = $harvested['contacts'];

        $result['linkedin_urls'] = array_values($result['linkedin_urls']);
        $result['google_maps_urls'] = array_values($result['google_maps_urls']);
        $result['social_urls'] = array_values($result['social_urls']);
        $result['emails'] = array_values($result['emails']);
        $result['phones'] = array_values($result['phones']);

        return $result;
    }

    private function searchGoogleWithoutApi(string $query): array
    {
        $url = 'https://www.google.com/search?hl=tr&num=20&q=' . rawurlencode($query);
        $html = $this->fetchRawHtml($url, 12);

        if (!$html) {
            return ['urls' => [], 'emails' => [], 'phones' => []];
        }

        $urls = [];
        if (preg_match_all('/href="\/url\?q=([^"&]+)[^"]*"/iu', $html, $matches)) {
            foreach ($matches[1] as $encoded) {
                $candidate = html_entity_decode(rawurldecode($encoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!preg_match('~^https?://~i', $candidate)) {
                    continue;
                }
                $host = mb_strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''), 'UTF-8');
                if ($host === '' || $host === 'google.com' || preg_match('/(^|\.)google\./i', $host)) {
                    continue;
                }
                $urls[$candidate] = $candidate;
            }
        }

        return [
            'urls' => array_values($urls),
            'emails' => $this->extractEmails($html),
            'phones' => $this->extractPhones($html)
        ];
    }

    private function searchDuckDuckGoWithoutApi(string $query): array
    {
        $url = 'https://duckduckgo.com/html/?q=' . rawurlencode($query);
        $html = $this->fetchRawHtml($url, 12);

        if (!$html) {
            return ['urls' => [], 'emails' => [], 'phones' => []];
        }

        $urls = [];
        if (preg_match_all('/class="result__a"\s+href="([^"]+)"/iu', $html, $matches)) {
            foreach ($matches[1] as $candidate) {
                $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!preg_match('~^https?://~i', $candidate)) {
                    continue;
                }
                $host = mb_strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''), 'UTF-8');
                if ($host === '' || preg_match('/duckduckgo\.com$/i', $host)) {
                    continue;
                }
                $urls[$candidate] = $candidate;
            }
        }

        return [
            'urls' => array_values($urls),
            'emails' => $this->extractEmails($html),
            'phones' => $this->extractPhones($html)
        ];
    }

    private function harvestExternalContactSignals(array $urls): array
    {
        $emails = [];
        $phones = [];
        $contacts = [];

        $limitedUrls = array_slice($urls, 0, 10);
        foreach ($limitedUrls as $url) {
            $html = $this->fetchRawHtml((string)$url, 10);
            if (!$html) {
                continue;
            }

            $pageEmails = $this->extractEmails($html);
            $pageText = preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $pagePhones = $this->extractPhones($pageText);

            foreach ($pageEmails as $email) {
                $emails[$email] = $email;
            }
            foreach ($pagePhones as $phone) {
                $phones[$phone] = $phone;
            }

            $pageContacts = $this->extractContacts($pageText, $html, (string)$url, $pageEmails, $pagePhones);
            foreach ($pageContacts as $contact) {
                $contact['not'] = 'Harici kaynak (Google/LinkedIn/Sosyal) taramasından bulundu';
                $contact['guven_skoru'] = max(45, (int)($contact['guven_skoru'] ?? 0) - 10);
                $key = ($contact['email'] ?: '') . '|' . ($contact['telefon'] ?: '') . '|' . ($contact['adi'] ?: '') . '|' . ($contact['soyadi'] ?: '');
                if (!isset($contacts[$key]) || $contacts[$key]['guven_skoru'] < $contact['guven_skoru']) {
                    $contacts[$key] = $contact;
                }
            }
        }

        return [
            'emails' => array_values($emails),
            'phones' => array_values($phones),
            'contacts' => array_values($contacts)
        ];
    }

    private function fetchRawHtml(string $url, int $timeoutSeconds = 12): ?string
    {
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36',
            'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8'
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => $headers
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return is_string($body) ? $body : null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers)
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $body = @file_get_contents($url, false, $context);
        return $body !== false ? $body : null;
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}