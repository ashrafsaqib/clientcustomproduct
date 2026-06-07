<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ExactApi.php';

$token = loadToken($config['token_file']);
if (!$token) {
    appendConnectionLog('Connection test failed: no token found. User must complete OAuth connect first.', 'ERROR');
    redirectWithMessage('/index.php', 'No token found. Connect first.', 'error');
}

try {
    $client = new ExactApi($config);

    if (isTokenExpired($token)) {
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Access token expired and no refresh token exists.');
        }

        $refreshed = $client->refreshAccessToken((string)$token['refresh_token']);
        if (empty($refreshed['refresh_token'])) {
            $refreshed['refresh_token'] = $token['refresh_token'];
        }

        saveToken($config['token_file'], $refreshed);
        $token = $refreshed;
    }

    $result = $client->getCurrentMe((string)$token['access_token']);
    $_SESSION['api_result'] = $result;
    appendConnectionLog('Connection test succeeded against Exact current/Me endpoint.', 'INFO');
    redirectWithMessage('/index.php', 'API test call succeeded.');
} catch (Throwable $e) {
    $_SESSION['api_result'] = ['error' => $e->getMessage()];
    appendConnectionLog('Connection test failed: ' . $e->getMessage(), 'ERROR');
    redirectWithMessage('/index.php', 'API test failed. See result block below.', 'error');
}
