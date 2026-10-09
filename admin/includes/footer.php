<?php
/**
 * Admin Panel - Footer Component
 * Portal Örnek Yazılım
 */

// Veritabanından site ayarlarını çek
if (!isset($db)) {
    $db = Database::getInstance();
}

$footerYazi = '';
$siteAyarlari = null;

try {
    $siteAyarlari = $db->fetchOne("
        SELECT TOP 1 site_ayarlari_footer_yazi 
        FROM dbo.tanim_site_ayarlari 
        ORDER BY site_ayarlari_id DESC
    ");
} catch (Exception $e) {
    error_log("Footer SQL hatası: " . $e->getMessage());
}

if ($siteAyarlari && !empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
    $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Yazılım Portal. Tüm hakları saklıdır.';
}

// En son versiyon numarasını çek
$sonVersiyon = null;
$versiyonNo = '1.0.0';

try {
    $sonVersiyon = $db->fetchOne("
        SELECT TOP 1 surum_versiyon 
        FROM Sistem_Surum_Notlari 
        ORDER BY surum_id DESC
    ");
    if ($sonVersiyon) {
        $versiyonNo = $sonVersiyon['surum_versiyon'];
    }
} catch (Exception $e) {
    error_log("Versiyon SQL hatası: " . $e->getMessage());
}
?>
<!--begin::Footer-->
<footer class="app-footer" style="display: block; visibility: visible; height: auto;">
    <div class="float-start">
        <strong><?= $footerYazi ?? '© 2026 Örnek Yazılım Portal' ?></strong>
    </div>
    <div class="float-end">
        <a href="/admin/surum-notlari" class="text-decoration-none" title="Sürüm notlarını görüntüle">
            <i class="bi bi-info-circle me-1"></i>Versiyon <?= htmlspecialchars($versiyonNo ?? '1.0.0') ?>
        </a>
    </div>
</footer>
<!--end::Footer-->

<style>
.destek-header-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin: 4px 6px;
    padding: 6px 14px !important;
    border-radius: 999px;
    background: #0d6efd;
    color: #fff !important;
    font-weight: 600;
    line-height: 1;
}
.destek-header-btn:hover,
.destek-header-btn:focus {
    background: #0b5ed7;
    color: #fff !important;
}
.destek-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 18px;
    text-align: center;
    border: 2px solid #fff;
}
.destek-yeni-etiket {
    display: inline-block;
    background: #dc3545;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 4px;
    margin-right: 4px;
}
@keyframes destek-shake {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-3px); }
    40%, 80% { transform: translateX(3px); }
}
.destek-header-btn.shake { animation: destek-shake .5s ease-in-out; }
</style>

<?php if (isset($hasNotificationAccess) && $hasNotificationAccess): ?>
<!-- Bildirim Sistemi -->
<script>
// jQuery yüklenene kadar bekle
(function checkJQuery() {
    if (typeof jQuery !== 'undefined') {
        initNotifications();
    } else {
        setTimeout(checkJQuery, 50);
    }
})();

function initNotifications() {
    // Bildirimleri yükle
    function loadNotifications() {
        $.get('/admin/api/notifications.php?action=get_notifications', function(response) {
            if (response.success) {
                const count = response.count || 0;
                const bildirimler = response.data || [];
                
                // Badge güncelle
                const badge = $('#notificationBadge');
                if (count > 0) {
                    badge.text(count > 9 ? '9+' : count).show();
                } else {
                    badge.hide();
                }
                
                // Header güncelle
                $('#notificationHeader').html(`<i class="bi bi-bell"></i> ${count} Bildirim`);
                
                // Liste güncelle
                const liste = $('#notificationList');
                liste.empty();
                
                if (bildirimler.length > 0) {
                    bildirimler.forEach(bildirim => {
                        const item = `
                            <a href="${bildirim.link}" class="dropdown-item">
                                <i class="bi ${bildirim.icon} me-2 text-${bildirim.renk}"></i>
                                <div class="d-inline-block text-truncate" style="max-width: 250px;">
                                    <strong>${bildirim.baslik}:</strong> ${bildirim.mesaj}
                                </div>
                                <span class="float-end text-muted text-sm">${bildirim.zaman}</span>
                            </a>
                            <div class="dropdown-divider"></div>
                        `;
                        liste.append(item);
                    });
                } else {
                    liste.html('<div class="dropdown-item text-center text-muted py-3">Yeni bildirim yok</div>');
                }
            }
        }).fail(function() {
            $('#notificationHeader').html('<i class="bi bi-bell"></i> Bildirimler yüklenemedi');
            $('#notificationList').html('<div class="dropdown-item text-center text-danger py-3">Hata oluştu</div>');
        });
    }
    
    // Sayfa yüklendiğinde bildirimleri yükle
    $(document).ready(function() {
        loadNotifications();
        
        // Her 60 saniyede bir güncelle
        setInterval(loadNotifications, 60000);
        
        // Dropdown açıldığında yenile
        $('#notificationDropdown').on('click', function() {
            loadNotifications();
        });
    });
}
</script>
<?php endif; ?>

<!-- Destek Bildirim Sistemi (Polling) -->
<script>
(function () {
    var btn = document.getElementById('destekDropdown');
    if (!btn) return;

    var badge = document.getElementById('destekBadge');
    var liste = document.getElementById('destekListe');
    var oncekiYeni = 0;

    // Baseline hic yoksa "su an" olarak kur; bundan sonraki yanitlar "yeni" sayilir.
    try {
        if (!localStorage.getItem('destek_son_kontrol')) {
            localStorage.setItem('destek_son_kontrol', new Date().toISOString());
        }
    } catch (e) {}

    function sonKontrol() {
        try { return localStorage.getItem('destek_son_kontrol') || ''; }
        catch (e) { return ''; }
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function render(ticketlar) {
        if (!liste) return;
        if (!ticketlar || !ticketlar.length) {
            liste.innerHTML = '<div class="dropdown-item text-center text-muted py-3">Destek talebiniz yok</div>';
            return;
        }
        var html = '';
        ticketlar.forEach(function (t) {
            var yeni  = t.yeni ? '<span class="destek-yeni-etiket">Yeni</span>' : '';
            var durum = t.durum_adi ? '<span class="float-end text-muted text-sm">' + escHtml(t.durum_adi) + '</span>' : '';
            html += '<a href="/admin/pages/ticket-detay.php?id=' + encodeURIComponent(t.yerel_id || t.id) + '" class="dropdown-item">'
                  + yeni
                  + '<span class="d-inline-block text-truncate align-middle" style="max-width:210px;">' + escHtml(t.konu) + '</span>'
                  + durum
                  + '</a><div class="dropdown-divider"></div>';
        });
        liste.innerHTML = html;
    }

    function hata(msg) {
        if (liste) {
            liste.innerHTML = '<div class="dropdown-item text-center text-danger py-3" style="white-space:normal;">'
                + escHtml(msg || 'Talepler yüklenemedi') + '</div>';
        }
    }

    function poll() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/admin/api/destek-bildirim.php?son_kontrol=' + encodeURIComponent(sonKontrol()), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            if (xhr.status !== 200) {
                hata('Sunucu hatası (HTTP ' + xhr.status + ')');
                return;
            }

            var r;
            try {
                r = JSON.parse(xhr.responseText);
            } catch (e) {
                hata('Geçersiz yanıt: ' + (xhr.responseText || '').substring(0, 120));
                return;
            }

            if (!r.success) {
                hata(r.message || 'API hatası');
                return;
            }

            render(r.ticketlar);

            var yeni = r.yeni_sayisi || 0;
            if (badge) {
                if (yeni > 0) {
                    badge.textContent = yeni > 9 ? '9+' : yeni;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            }

            // Yeni bildirim ilk dustugunde sallanma animasyonu
            if (yeni > oncekiYeni && yeni > 0) {
                btn.classList.remove('shake');
                void btn.offsetWidth;
                btn.classList.add('shake');
            }
            oncekiYeni = yeni;
        };
        xhr.onerror = function () { hata('Ağ hatası'); };
        xhr.send();
    }

    poll();
    setInterval(poll, 60000);
})();
</script>
<?php
// --- Oturum Sure Takibi ---
// Sure bitimine 30 sn kala uyari (uzatma / cikis), ayrica oturumu bitmis
// AJAX isteklerinin (401) yakalanmasi. Script kosulsuz yuklenir: kalan sure
// sifir olsa bile 401 yakalayicisinin devrede olmasi gerekir.
if (Auth::check()):
    $_oturumKalan = !empty($_SESSION['login_time'])
        ? oturumTimeoutSaniye() - (time() - $_SESSION['login_time'])
        : 0;
?>
<script>
window.OTURUM_TAKIP = {
    kalan: <?= max(0, (int)$_oturumKalan) ?>,
    omur:  <?= (int)oturumTimeoutSaniye() ?>
};
</script>
<script src="/admin/assets/js/oturum-takip.js?v=<?= @filemtime(__DIR__ . '/../assets/js/oturum-takip.js') ?>" defer></script>
<?php endif; ?>