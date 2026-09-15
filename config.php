<?php
// config.php - Configuration for Azure SSO and Database

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

return [
    'azure' => [
        'clientId'     => 'mock_client_id',
        'clientSecret' => 'mock_client_secret',
        'redirectUri'  => 'http://127.0.0.1/callback.php',
        'tenantId'     => 'common',
    ],
    'db' => [
        'local' => [
            'dbhost' => '127.0.0.1',
            'dbname' => 'base_framework',
            'dbuser' => 'framework_user',
            'dbpass' => 'framework_pass',
        ]
    ]
];
