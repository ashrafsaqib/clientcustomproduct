<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ExactApi.php';

if ($config['client_id'] === '' || $config['client_secret'] === '') {
    redirectWithMessage('/index.php', 'Set EXACT_CLIENT_ID and EXACT_CLIENT_SECRET in .env first.', 'error');
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$client = new ExactApi($config);
header('Location: ' . $client->getAuthorizationUrl($state));
exit;
