<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ExactApi.php';

if (isset($_GET['error'])) {
    $msg = 'OAuth error: ' . ($_GET['error_description'] ?? $_GET['error']);
    redirectWithMessage('index.php', $msg, 'error');
}

$state = $_GET['state'] ?? '';
if ($state === '' || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    redirectWithMessage('index.php', 'Invalid OAuth state value.', 'error');
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    redirectWithMessage('index.php', 'Missing authorization code.', 'error');
}

try {
    $client = new ExactApi($config);
    $token = $client->exchangeCodeForToken($code);
    saveToken($config['token_file'], $token);
    redirectWithMessage('index.php', 'Connected successfully. Token saved.');
} catch (Throwable $e) {
    redirectWithMessage('index.php', 'Could not exchange token: ' . $e->getMessage(), 'error');
}
