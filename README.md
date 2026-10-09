# OSB Yönetim Paneli

PHP 8.3 + MSSQL ile yazılmış, AdminLTE 4 tabanlı kurumsal yönetim paneli.
Bir mühendislik firmasının cari, teklif, fatura, stok, ödeme ve pazaryeri süreçlerini tek panelde toplar.

## Modüller

- **Cari yönetimi:** cari kartlar, devir, görüşme notları, cari tarama
- **Teklif ve sipariş:** teklif formu, tedarikçi siparişleri, konsinye / numune çıkışı
- **e-Fatura / e-Arşiv:** Türkbelge (i2i SOAP) entegrasyonu ile fatura kesme, gelen / giden fatura senkronu, UBL-TR 2.1 üretimi
- **Ödeme ve banka:** ödeme takibi, banka hareketleri ve otomatik eşleştirme, sanal POS ödeme linkleri
- **Pazaryeri:** Trendyol, Hepsiburada ve Idefix ürün, sipariş, iade ve soru yönetimi
- **Stok:** ürün / stok hareketleri, depo sayımı, ürün birleştirme, kârlılık raporu
- **Destek:** ticket sistemi ve bildirimler
- **Cron:** tek giriş noktalı görev çalıştırıcı (`cron/runner.php`, `cron/tasks.php`)

## Kurulum

1. Depoyu IIS (veya Apache) altında bir siteye kopyalayın.
2. `composer install`
3. `config/database.example.php` dosyasını `config/database.php` olarak kopyalayıp bağlantı bilgilerini girin.
4. `config/ticket-api.example.php` dosyasını `config/ticket-api.php` olarak kopyalayın.
5. Entegrasyon kimlik bilgileri (Türkbelge, pazaryerleri, banka) panelde **Entegrasyon Yönetimi** ekranından veritabanına girilir; dosyada tutulmaz.
6. PHP için `date.timezone="Europe/Istanbul"` ayarlayın (`.user.ini`).

### Gereksinimler

- PHP 8.3, eklentiler: `sqlsrv`, `soap`, `dom`, `xsl`, `curl`, `mbstring`
- Microsoft SQL Server 2019+
- IIS URL Rewrite (kurallar `web.config` içinde)

## Notlar

- Veritabanı şeması bu depoda yer almaz.
- e-Fatura görsel şablonları (`admin/assets/xslt/*.xslt`) firmaya özel olduğu için depoda yoktur; kendi XSLT şablonunuzu bu klasöre eklemelisiniz.
- `config/`, `temp/`, `logs/` klasörleri `web.config` kuralıyla web erişimine kapatılmıştır.

## Geliştirici

[Batuhan KAHRAMAN](https://github.com/Batuhan-Kahraman35)