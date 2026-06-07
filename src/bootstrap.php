<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$root = dirname(__DIR__);
$envFile = $root . '/.env';

if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

$config = [
    'client_id' => getenv('EXACT_CLIENT_ID') ?: '',
    'client_secret' => getenv('EXACT_CLIENT_SECRET') ?: '',
    'redirect_uri' => getenv('EXACT_REDIRECT_URI') ?: 'http://localhost:8000/oauth_callback.php',
    'base_url' => rtrim(getenv('EXACT_BASE_URL') ?: 'https://start.exactonline.nl', '/'),
    'scope' => getenv('EXACT_SCOPE') ?: 'exactonlineapi offline_access',
    'webhook_secret' => getenv('WEBHOOK_SECRET') ?: '',
    'opencart_base_url' => rtrim(getenv('OPENCART_BASE_URL') ?: '', '/'),
    'opencart_api_username' => getenv('OPENCART_API_USERNAME') ?: '',
    'opencart_api_key' => getenv('OPENCART_API_KEY') ?: '',
    'token_file' => $root . '/storage/token.json',
];

if (!is_dir(dirname($config['token_file']))) {
    mkdir(dirname($config['token_file']), 0775, true);
}

function loadToken(string $tokenFile): ?array
{
    if (!is_file($tokenFile)) {
        return null;
    }

    $content = file_get_contents($tokenFile);
    if ($content === false) {
        return null;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function saveToken(string $tokenFile, array $token): bool
{
    return file_put_contents($tokenFile, json_encode($token, JSON_PRETTY_PRINT)) !== false;
}

function isTokenExpired(array $token): bool
{
    if (!isset($token['expires_at'])) {
        return true;
    }

    return (int)$token['expires_at'] <= time() + 30;
}

function redirectWithMessage(string $location, string $message, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $location);
    exit;
}

function appendConnectionLog(string $message, string $level = 'INFO'): void
{
    $logDir = dirname(__DIR__) . '/storage';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $line = sprintf("[%s] [%s] %s\n", date('c'), strtoupper($level), $message);
    file_put_contents($logDir . '/connection.log', $line, FILE_APPEND);
}

function readConnectionLogs(int $maxLines = 80): array
{
    $logFile = dirname(__DIR__) . '/storage/connection.log';
    if (!is_file($logFile)) {
        return [];
    }

    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    if (count($lines) <= $maxLines) {
        return $lines;
    }

    return array_slice($lines, -$maxLines);
}
