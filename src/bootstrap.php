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

$ocConfigFile = $root . '/config.php';
if (is_file($ocConfigFile)) {
    require_once $ocConfigFile;
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
    'db_host' => getenv('DB_HOSTNAME') ?: (defined('DB_HOSTNAME') ? DB_HOSTNAME : 'localhost'),
    'db_port' => getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : '3306'),
    'db_name' => getenv('DB_DATABASE') ?: (defined('DB_DATABASE') ? DB_DATABASE : ''),
    'db_username' => getenv('DB_USERNAME') ?: (defined('DB_USERNAME') ? DB_USERNAME : ''),
    'db_password' => getenv('DB_PASSWORD') ?: (defined('DB_PASSWORD') ? DB_PASSWORD : ''),
    'db_prefix' => getenv('DB_PREFIX') ?: (defined('DB_PREFIX') ? DB_PREFIX : 'oc_'),
    'exact_division' => getenv('EXACT_DIVISION') ?: '',
    'paid_order_status_ids' => array_values(array_filter(array_map('trim', explode(',', getenv('EXACT_PAID_ORDER_STATUS_IDS') ?: '')))),
    'default_gl_account_code' => getenv('EXACT_DEFAULT_GL_ACCOUNT_CODE') ?: '',
    'default_vat_code' => getenv('EXACT_DEFAULT_VAT_CODE') ?: '',
    'sync_batch_size' => (int)(getenv('EXACT_SYNC_BATCH_SIZE') ?: 20),
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

/**
 * Loads the stored Exact token, refreshing it first if it is expired.
 * Returns null if there is no token at all (user must connect first).
 */
function getValidExactToken(array $config, ExactApi $exact): ?array
{
    $token = loadToken($config['token_file']);
    if (!$token) {
        return null;
    }

    if (isTokenExpired($token)) {
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Exact token expired and no refresh token is available. Reconnect to Exact.');
        }

        $refreshed = $exact->refreshAccessToken((string)$token['refresh_token']);
        if (empty($refreshed['refresh_token'])) {
            $refreshed['refresh_token'] = $token['refresh_token'];
        }
        saveToken($config['token_file'], $refreshed);
        $token = $refreshed;
    }

    return $token;
}

/**
 * Resolves the Exact division code to use for division-scoped endpoints.
 * Uses EXACT_DIVISION override if set, otherwise asks Exact for the current division.
 */
function getExactDivision(array $config, ExactApi $exact, string $accessToken): string
{
    if (!empty($config['exact_division'])) {
        return (string)$config['exact_division'];
    }

    if (!empty($_SESSION['exact_division'])) {
        return (string)$_SESSION['exact_division'];
    }

    $me = $exact->getCurrentMe($accessToken);
    $division = (string)($me['CurrentDivision'] ?? '');
    if ($division === '') {
        throw new RuntimeException('Could not resolve Exact division from current/Me response.');
    }

    $_SESSION['exact_division'] = $division;
    return $division;
}
