<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ExactApi.php';
require_once __DIR__ . '/../src/OpenCartApi.php';

$report = [
    'timestamp' => date('c'),
    'exact' => ['status' => 'not_checked'],
    'opencart' => ['status' => 'not_checked'],
    'overall' => 'failed',
];

try {
    $token = loadToken($config['token_file']);
    if (!$token) {
        throw new RuntimeException('Exact token not found. Connect Exact first.');
    }

    $exact = new ExactApi($config);

    if (isTokenExpired($token)) {
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Exact token expired and refresh_token is missing.');
        }

        $refreshed = $exact->refreshAccessToken((string)$token['refresh_token']);
        if (empty($refreshed['refresh_token'])) {
            $refreshed['refresh_token'] = $token['refresh_token'];
        }
        saveToken($config['token_file'], $refreshed);
        $token = $refreshed;
    }

    $me = $exact->getCurrentMe((string)$token['access_token']);
    $report['exact'] = [
        'status' => 'ok',
        'me_preview' => $me,
    ];
} catch (Throwable $e) {
    $report['exact'] = [
        'status' => 'failed',
        'error' => $e->getMessage(),
    ];
}

try {
    $openCart = new OpenCartApi(
        (string)($config['opencart_base_url'] ?? ''),
        (string)($config['opencart_api_username'] ?? ''),
        (string)($config['opencart_api_key'] ?? '')
    );

    $report['opencart'] = $openCart->testConnection();
} catch (Throwable $e) {
    $report['opencart'] = [
        'status' => 'failed',
        'error' => $e->getMessage(),
    ];
}

if (($report['exact']['status'] ?? '') === 'ok' && ($report['opencart']['status'] ?? '') === 'ok') {
    $report['overall'] = 'ready';
}

$_SESSION['sync_report'] = $report;
if ($report['overall'] === 'ready') {
    redirectWithMessage('index.php', 'Sync readiness check passed for Exact and OpenCart.');
}

redirectWithMessage('index.php', 'Sync readiness check completed with issues. Review report below.', 'error');
