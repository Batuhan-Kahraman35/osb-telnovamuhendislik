<?php
/**
 * TÜRKBELGE API Helper Class
 * E-Fatura, E-Arşiv ve VKN Sorgulama Servisleri
 * 
 * @author Örnek Yazılım
 * @version 1.0.0
 */

class TurkbelgeAPI
{
    private $db;
    private $kanal;
    private $sessionId = null;
    private $soapClient = null;
    private $soapOptions = [];
    
    /**
     * Constructor
     * @param Database $db Database instance
     * @param string $ortam 'TEST' veya 'PROD'
     */
    public function __construct($db, $ortam = 'PROD')
    {
        $this->db = $db;
        $this->kanal = $this->getKanal($ortam);
        
        $this->soapOptions = [
            'trace' => 1,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ])
        ];
    }
    
    /**
     * Entegrasyon kanalını veritabanından çek
     */
    private function getKanal($ortam)
    {
        $kanal = $this->db->fetchOne("
            SELECT ek.*, e.Entegrasyon_Kod
            FROM EntegrasyonKanallari ek
            INNER JOIN Entegrasyon e ON ek.Kanal_Entegrasyon_Id = e.Entegrasyon_Id
            WHERE e.Entegrasyon_Kod = 'TURKBELGE' 
              AND ek.Kanal_Id = 1 
              AND ek.Kanal_Durum = 1
        ");
        
        if (!$kanal) {
            throw new Exception("TÜRKBELGE {$ortam} kanalı bulunamadı");
        }
        
        // JSON parametreleri parse et
        if (!empty($kanal['Kanal_Parametreler'])) {
            $kanal['params'] = json_decode($kanal['Kanal_Parametreler'], true);
        }
        
        return $kanal;
    }
    
    /**
     * SOAP Client oluştur
     */
    private function getSoapClient()
    {
        if ($this->soapClient === null) {
            $this->soapClient = new SoapClient($this->kanal['Kanal_Endpoint'], $this->soapOptions);
        }
        return $this->soapClient;
    }
    
    /**
     * Login işlemi - Session ID al
     */
    public function login()
    {
        $startTime = microtime(true);
        
        try {
            $client = $this->getSoapClient();
            
            $request = [
                'REQUEST_HEADER' => [
                    'SESSION_ID' => '-1',
                    'APPLICATION_NAME' => 'ORNEK_PORTAL'
                ],
                'USER_NAME' => $this->kanal['Kanal_Kullanici'],
                'PASSWORD' => $this->kanal['Kanal_Sifre']
            ];
            
            $response = $client->Login($request);
            
            if (!empty($response->SESSION_ID)) {
                $this->sessionId = $response->SESSION_ID;
                
                // Son kullanım güncelle
                $this->db->query("UPDATE EntegrasyonKanallari SET Kanal_Son_Kullanim = GETDATE() WHERE Kanal_Id = ?", [$this->kanal['Kanal_Id']]);
                
                // Log kaydet
                $this->log('LOGIN', $request, $response, 'SUCCESS', null, null, $startTime);
                
                return true;
            }
            
            throw new Exception('Session ID alınamadı');
            
        } catch (Exception $e) {
            $this->log('LOGIN', $request ?? [], null, 'ERROR', null, $e->getMessage(), $startTime);
            throw $e;
        }
    }
    
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Logout işlemi
     */
    public function logout()
    {
        if (!$this->sessionId) return;
        
        try {
            $client = $this->getSoapClient();
            
            $request = [
                'REQUEST_HEADER' => [
                    'SESSION_ID' => $this->sessionId,
                    'APPLICATION_NAME' => 'ORNEK_PORTAL'
                ]
            ];
            
            $client->Logout($request);
            $this->sessionId = null;
            
        } catch (Exception $e) {
            // Logout hatası önemsiz, sessizce geç
        }
    }
    
    /**
     * VKN/TCKN ile e-fatura mükellefiyeti sorgula
     * 
     * @param string $vkn Vergi Kimlik Numarası (10-11 hane)
     * @return array|null Mükellef bilgileri veya null
     */
    public function checkUser($vkn)
    {
        $startTime = microtime(true);
        
        // Login kontrolü
        if (!$this->sessionId) {
            $this->login();
        }
        
        try {
            $client = $this->getSoapClient();
            
            $request = [
                'REQUEST_HEADER' => [
                    'SESSION_ID' => $this->sessionId,
                    'APPLICATION_NAME' => 'ORNEK_PORTAL'
                ],
                'USER' => [
                    'IDENTIFIER' => $vkn
                ],
                'DOCUMENT_TYPE' => 'INVOICE'
            ];
            
            $response = $client->CheckUser($request);
            
            // Log kaydet
            $this->log('CHECK_USER', ['vkn' => $vkn], $response, 'SUCCESS', null, null, $startTime);
            
            // Sonucu parse et
            if (isset($response->USER)) {
                $user = $response->USER;
                return [
                    'vkn' => $user->IDENTIFIER ?? $vkn,
                    'unvan' => $user->TITLE ?? null,
                    'tip' => $user->TYPE ?? null,
                    'etiket' => $user->ALIAS ?? null,
                    'kayit_tarihi' => $user->REGISTER_TIME ?? null,
                    'birim' => $user->UNIT ?? null,
                    'efatura_mukellef' => true
                ];
            }
            
            // Mükellef değil
            return [
                'vkn' => $vkn,
                'efatura_mukellef' => false
            ];
            
        } catch (Exception $e) {
            $this->log('CHECK_USER', ['vkn' => $vkn], null, 'ERROR', null, $e->getMessage(), $startTime);
            throw $e;
        }
    }
    
    /**
     * İşlem logu kaydet
     */
    private function log($islem, $istek, $yanit, $durum, $hataKodu = null, $hataMesaj = null, $startTime = null)
    {
        $sure = $startTime ? round((microtime(true) - $startTime) * 1000) : null;
        
        try {
            $this->db->insert('EntegrasyonLoglari', [
                'Log_Kanal_Id' => $this->kanal['Kanal_Id'],
                'Log_Islem' => $islem,
                'Log_Istek' => json_encode($istek, JSON_UNESCAPED_UNICODE),
                'Log_Yanit' => $yanit ? json_encode($yanit, JSON_UNESCAPED_UNICODE) : null,
                'Log_Durum' => $durum,
                'Log_Hata_Kodu' => $hataKodu,
                'Log_Hata_Mesaj' => $hataMesaj,
                'Log_Sure_Ms' => $sure,
                'Log_IP' => $_SERVER['REMOTE_ADDR'] ?? null,
                'Log_Kullanici_Id' => $_SESSION['kullanici_id'] ?? null
            ]);
        } catch (Exception $e) {
            // Log hatası sessizce geç
            error_log("EntegrasyonLog hatası: " . $e->getMessage());
        }
    }
    
    /**
     * Destructor - Logout yap
     */
    public function __destruct()
    {
        $this->logout();
    }
}
