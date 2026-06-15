<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ExactApi.php';

$token = loadToken($config['token_file']);
if (!$token || empty($token['refresh_token'])) {
    redirectWithMessage('index.php', 'No refresh token available. Connect first.', 'error');
}

try {
    $client = new ExactApi($config);
    $newToken = $client->refreshAccessToken((string)$token['refresh_token']);

    if (empty($newToken['refresh_token'])) {
        $newToken['refresh_token'] = $token['refresh_token'];
    }

    saveToken($config['token_file'], $newToken);
    redirectWithMessage('index.php', 'Token refreshed successfully.');
} catch (Throwable $e) {
    redirectWithMessage('index.php', 'Refresh failed: ' . $e->getMessage(), 'error');
}
