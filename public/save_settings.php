<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage('index.php', 'Invalid request method.', 'error');
}

function cleanValue(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

$values = [
    'EXACT_CLIENT_ID' => cleanValue((string)($_POST['exact_client_id'] ?? '')),
    'EXACT_CLIENT_SECRET' => cleanValue((string)($_POST['exact_client_secret'] ?? '')),
    'EXACT_REDIRECT_URI' => cleanValue((string)($_POST['exact_redirect_uri'] ?? '')),
    'EXACT_BASE_URL' => cleanValue((string)($_POST['exact_base_url'] ?? 'https://start.exactonline.nl')),
    'EXACT_SCOPE' => cleanValue((string)($_POST['exact_scope'] ?? 'exactonlineapi offline_access')),
    'WEBHOOK_SECRET' => cleanValue((string)($_POST['webhook_secret'] ?? '')),
];

$required = [
    'EXACT_CLIENT_ID',
    'EXACT_CLIENT_SECRET',
    'EXACT_REDIRECT_URI',
    'EXACT_BASE_URL',
    'EXACT_SCOPE',
];

foreach ($required as $key) {
    if ($values[$key] === '') {
        redirectWithMessage('index.php', 'Missing required field: ' . $key, 'error');
    }
}

$envLines = [];
foreach ($values as $key => $value) {
    $envLines[] = $key . '=' . $value;
}

$envPath = dirname(__DIR__) . '/.env';
$written = file_put_contents($envPath, implode(PHP_EOL, $envLines) . PHP_EOL);
if ($written === false) {
    redirectWithMessage('index.php', 'Could not save settings to .env.', 'error');
}

$action = (string)($_POST['action'] ?? 'save');
if ($action === 'save_and_test') {
    header('Location: test_connection.php');
    exit;
}

redirectWithMessage('index.php', 'Settings saved successfully.');
