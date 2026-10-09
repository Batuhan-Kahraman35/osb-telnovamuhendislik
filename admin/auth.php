<?php
/**
 * Kimlik Doğrulama Fonksiyonları
 * Portal Örnek Yazılım
 */

// Uygulama genel timezone standardi (tek merkez)
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Europe/Istanbul');
}

date_default_timezone_set(APP_TIMEZONE);

// session_start() oncesi DB erisimi gerekir: oturum omru tanim_site_ayarlari'ndan okunur
require_once __DIR__ . '/db.php';

/**
 * Oturum timeout suresi (saniye)
 * Kaynak: dbo.tanim_site_ayarlari.site_ayarlari_session_timeout_min
 * Ayar kaydi yoksa veya okunamazsa varsayilan 120 dk kullanilir.
 */
function oturumTimeoutSaniye(): int {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $dakika = 120; // varsayilan

    try {
        $row = Database::getInstance()->fetchOne(
            "SELECT TOP 1 site_ayarlari_session_timeout_min
             FROM dbo.tanim_site_ayarlari
             ORDER BY site_ayarlari_id DESC"
        );
        if ($row && (int)$row['site_ayarlari_session_timeout_min'] > 0) {
            $dakika = (int)$row['site_ayarlari_session_timeout_min'];
        }
    } catch (Exception $e) {
        // DB erisilemezse varsayilan ile devam et
    }

    $cache = $dakika * 60;
    return $cache;
}

// PHP'nin kendi oturum omru de ayni degere baglanir.
// Aksi halde gc_maxlifetime (varsayilan 1440 sn = 24 dk) uygulama timeout'undan
// once devreye girip oturumu erkenden dusurur.
ini_set('session.gc_maxlifetime', (string)oturumTimeoutSaniye());
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);

session_start();

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Kullanıcı girişi
     */
    public function login($email, $password) {
        $sql = "SELECT k.* FROM kullanicilar k WHERE k.kullanici_email = ? AND k.kullanici_durum = 1";
        $user = $this->db->fetchOne($sql, [$email]);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'E-posta veya şifre hatalı!'
            ];
        }
        
        if (!password_verify($password, $user['kullanici_sifre_hash'])) {
            return [
                'success' => false,
                'message' => 'E-posta veya şifre hatalı!'
            ];
        }
        
        // Son giriş tarihini güncelle
        $updateSql = "UPDATE kullanicilar SET kullanici_son_giris_tarihi = GETDATE() WHERE kullanici_id = ?";
        $this->db->execute($updateSql, [$user['kullanici_id']]);
        
        // Session bilgilerini kaydet
        $_SESSION['user_id'] = $user['kullanici_id'];
        $_SESSION['user_email'] = $user['kullanici_email'];
        $_SESSION['user_name'] = $user['kullanici_ad'] . ' ' . $user['kullanici_soyad'];
        $_SESSION['user_departman_id'] = $user['kullanici_departman_id'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['sifre_degistirmeli'] = $user['kullanici_sifre_degistirmeli'] ?? 0;
        
        // Log kaydı
        $this->logActivity($user['kullanici_id'], 'Başarılı giriş');
        
        return [
            'success' => true,
            'message' => 'Giriş başarılı!',
            'user' => [
                'id' => $user['kullanici_id'],
                'email' => $user['kullanici_email'],
                'name' => $user['kullanici_ad'] . ' ' . $user['kullanici_soyad']
            ]
        ];
    }
    
    /**
     * Oturum kontrolü
     */
    public static function check() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }
        
        // 2 saatlik timeout kontrolü
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > oturumTimeoutSaniye())) {
            self::logout();
            return false;
        }
        
        return true;
    }
    
    /**
     * Çıkış işlemi
     */
    public static function logout() {
        session_unset();
        session_destroy();
        return true;
    }
    
    /**
     * Başka bir kullanıcı olarak giriş yap (Impersonate)
     * Sadece departman_id=1 (Admin) kullanabilir.
     */
    public static function impersonate($hedefKullaniciId) {
        if (!self::check()) return false;
        if (($_SESSION['user_departman_id'] ?? null) != 1) return false;
        // Zaten impersonate modundaysa izin verme
        if (isset($_SESSION['_impersonator'])) return false;

        $db = Database::getInstance();
        $hedef = $db->fetchOne(
            "SELECT k.kullanici_id, k.kullanici_email, k.kullanici_ad, k.kullanici_soyad, k.kullanici_departman_id
             FROM kullanicilar k WHERE k.kullanici_id = ? AND k.kullanici_durum = 1",
            [$hedefKullaniciId]
        );

        if (!$hedef) return false;

        // Mevcut admin session'ını yedekle
        $_SESSION['_impersonator'] = [
            'user_id'          => $_SESSION['user_id'],
            'user_email'       => $_SESSION['user_email'],
            'user_name'        => $_SESSION['user_name'],
            'user_departman_id'=> $_SESSION['user_departman_id'],
            'login_time'       => $_SESSION['login_time'],
        ];

        // Hedef kullanıcıyı session'a yaz
        $_SESSION['user_id']          = $hedef['kullanici_id'];
        $_SESSION['user_email']       = $hedef['kullanici_email'];
        $_SESSION['user_name']        = trim($hedef['kullanici_ad'] . ' ' . $hedef['kullanici_soyad']);
        $_SESSION['user_departman_id']= $hedef['kullanici_departman_id'];
        $_SESSION['login_time']       = time();
        $_SESSION['sifre_degistirmeli'] = 0; // impersonate'de şifre ekranına düşmesin

        return true;
    }

    /**
     * Impersonate modundan çık, orijinal admin hesabına dön
     */
    public static function stopImpersonate() {
        if (!isset($_SESSION['_impersonator'])) return false;

        $imp = $_SESSION['_impersonator'];
        unset($_SESSION['_impersonator']);

        $_SESSION['user_id']          = $imp['user_id'];
        $_SESSION['user_email']       = $imp['user_email'];
        $_SESSION['user_name']        = $imp['user_name'];
        $_SESSION['user_departman_id']= $imp['user_departman_id'];
        $_SESSION['login_time']       = $imp['login_time'];
        $_SESSION['sifre_degistirmeli'] = 0;

        return true;
    }

    /**
     * Şu an impersonate modunda mı?
     */
    public static function isImpersonating() {
        return isset($_SESSION['_impersonator']);
    }

    /**
     * Orijinal admin bilgisini döndür
     */
    public static function impersonator() {
        return $_SESSION['_impersonator'] ?? null;
    }
    
    /**
     * Kullanıcı bilgisi
     */
    public static function user() {
        if (!self::check()) {
            return null;
        }
        
        return [
            'kullanici_id' => $_SESSION['user_id'] ?? null,
            'kullanici_email' => $_SESSION['user_email'] ?? null,
            'departman_id' => $_SESSION['user_departman_id'] ?? null,
            // Geriye dönük uyumluluk
            'id' => $_SESSION['user_id'] ?? null,
            'email' => $_SESSION['user_email'] ?? null,
            'name' => $_SESSION['user_name'] ?? null
        ];
    }
    
    /**
     * Aktivite logu
     */
    private function logActivity($userId, $action) {
        $logFile = __DIR__ . '/../logs/auth_' . date('Y-m-d') . '.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logMessage = sprintf(
            "[%s] User ID: %d | Action: %s | IP: %s | User Agent: %s\n",
            date('Y-m-d H:i:s'),
            $userId,
            $action,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        );
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}

/**
 * Yönlendirme fonksiyonu
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Oturum kontrolü ve yönlendirme
 */
/**
 * Istek AJAX / API cagrisi mi?
 *
 * Boyle bir istege login.php'ye 302 donduruluse tarayici yonlendirmeyi izler,
 * JS'e JSON yerine login sayfasinin HTML'i ulasir ve sayfada anlamsiz bir
 * "sunucu hatasi" belirir. Bu istekler 302 yerine 401 JSON almalidir.
 *
 * Normal form gonderimleri (Sec-Fetch-Dest: document) kapsam disidir.
 */
function istekAjaxMi(): bool {
    // jQuery $.ajax bu basligi kendiliginden ekler
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }

    // Gercek sayfa gezintisi asla AJAX sayilmaz. PWA service worker gezintiyi kendi
    // fetch'i uzerinden gecirdiginde Sec-Fetch-Dest 'document' yerine 'empty' gelebiliyor;
    // bu kontrol olmadan sayfa istegine login yerine JSON donuyordu.
    if (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate') {
        return false;
    }
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false) {
        return false;
    }

    // fetch() / XHR: tarayici bu basligi 'empty' gonderir, sayfa gezintisinde 'document'
    if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty') {
        return true;
    }

    // JSON bekleyen istemciler
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        return true;
    }

    // API uc noktalari
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        return true;
    }

    return false;
}

function requireAuth() {
    if (!Auth::check()) {
        // AJAX / API isteklerine yonlendirme yerine 401 JSON donulur
        if (istekAjaxMi()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'      => false,
                'oturum_bitti' => true,
                'message'      => 'Oturum sureniz doldu. Lutfen tekrar giris yapin.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Giriş sonrası kullanıcının gitmek istediği sayfaya dönebilmek için hedefi sakla.
        // Sadece normal sayfa görüntülemeleri saklanır: POST, AJAX ve API istekleri hariç.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === false) {
            // Oturum zaman aşımında Auth::check() session'ı yok eder; yeniden başlat
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['giris_sonrasi_url'] = $_SERVER['REQUEST_URI'] ?? '';
        }
        // Absolute URL kullan (SEO rewrite sorununu önlemek için)
        redirect('/admin/login.php');
    }
    
    // İlk girişte şifre değiştirme zorunluluğu kontrolü
    if (!empty($_SESSION['sifre_degistirmeli'])) {
        $currentPage = basename($_SERVER['SCRIPT_FILENAME'], '.php');
        // Profil sayfası ve logout hariç her sayfada yönlendir
        if ($currentPage !== 'profil' && $currentPage !== 'logout') {
            // Absolute URL kullan (SEO rewrite sorununu önlemek için)
            redirect('/admin/pages/profil.php?sifre_degistir=1');
        }
    }
}
