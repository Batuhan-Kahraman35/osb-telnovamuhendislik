<?php
/**
 * Veritabanı bağlantı ayarları (örnek)
 * Bu dosyayı config/database.php olarak kopyalayıp kendi bilgilerinizi girin.
 */

return [
    'default' => 'sqlsrv',

    'connections' => [
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => 'localhost\SQLEXPRESS',
            'port' => '',
            'database' => 'ornek_osb_DB',
            'username' => 'ornek_kullanici',
            'password' => 'sifreniz',
            'charset' => 'utf8',
            'prefix' => '',
            'options' => [
                'encrypt' => false,
                'trust_server_certificate' => true,
            ]
        ]
    ],

    'server_info' => [
        'url' => 'panel.ornekfirma.com',
        'ip' => '127.0.0.1',
        'environment' => 'production'
    ]
];