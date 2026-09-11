<?php

declare(strict_types=1);

class ExactApi
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getAuthorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $this->config['scope'],
            'state' => $state,
            'force_login' => 0,
        ]);

        return $this->config['base_url'] . '/api/oauth2/auth?' . $query;
    }

    public function exchangeCodeForToken(string $code): array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
        ]);
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function getCurrentMe(string $accessToken): array
    {
        return $this->apiGet('/api/v1/current/Me', [
            '$select' => 'FullName,Email,CurrentDivision',
        ], $accessToken);
    }

    public function apiGet(string $path, array $query, string $accessToken): array
    {
        $url = $this->config['base_url'] . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ];

        $response = $this->curlJsonRequest('GET', $url, $headers);

        if ($response['status'] >= 300) {
            throw new RuntimeException('API request failed: HTTP ' . $response['status'] . ' ' . $response['body']);
        }

        return $response['json'];
    }

    public function apiPost(string $path, array $body, string $accessToken): array
    {
        $url = $this->config['base_url'] . $path;

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ];

        $response = $this->curlJsonRequest('POST', $url, $headers, json_encode($body, JSON_UNESCAPED_SLASHES));

        if ($response['status'] >= 300) {
            throw new RuntimeException('API POST failed: HTTP ' . $response['status'] . ' ' . $response['body']);
        }

        return $response['json'];
    }

    /** Finds a CRM Account by email, or null if none matches. */
    public function findAccountByEmail(string $division, string $email, string $accessToken): ?array
    {
        $result = $this->apiGet("/api/v1/{$division}/crm/Accounts", [
            '$filter' => "Email eq '" . str_replace("'", "''", $email) . "'",
            '$select' => 'ID,Name,Email',
            '$top' => 1,
        ], $accessToken);

        $results = $result['d']['results'] ?? [];
        return $results[0] ?? null;
    }

    /** Creates a new CRM Account (debtor) and returns the created record. */
    public function createAccount(string $division, array $data, string $accessToken): array
    {
        $result = $this->apiPost("/api/v1/{$division}/crm/Accounts", $data, $accessToken);
        return $result['d'] ?? $result;
    }

    /** Finds a Logistics Item by its code (matched against OpenCart model/sku), or null. */
    public function findItemByCode(string $division, string $code, string $accessToken): ?array
    {
        if ($code === '') {
            return null;
        }

        $result = $this->apiGet("/api/v1/{$division}/logistics/Items", [
            '$filter' => "Code eq '" . str_replace("'", "''", $code) . "'",
            '$select' => 'ID,Code,CurrentStock',
            '$top' => 1,
        ], $accessToken);

        $results = $result['d']['results'] ?? [];
        return $results[0] ?? null;
    }

    /** Creates a Sales Invoice with lines and returns the created record. */
    public function createSalesInvoice(string $division, array $data, string $accessToken): array
    {
        $result = $this->apiPost("/api/v1/{$division}/salesinvoice/SalesInvoices", $data, $accessToken);
        return $result['d'] ?? $result;
    }

    /** Returns Logistics Items with their current stock levels, following pagination up to $maxPages. */
    public function getItemsStock(string $division, string $accessToken, int $maxPages = 20): array
    {
        $items = [];
        $path = "/api/v1/{$division}/logistics/Items";
        $query = [
            '$select' => 'ID,Code,CurrentStock',
            '$filter' => 'IsStockItem eq true',
            '$top' => 60,
        ];

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->apiGet($path, $query, $accessToken);
            $results = $result['d']['results'] ?? [];
            foreach ($results as $item) {
                $items[] = $item;
            }

            $next = $result['d']['__next'] ?? null;
            if (!$next) {
                break;
            }

            // __next is a full URL already containing the query, so switch to raw GET.
            $path = str_replace($this->config['base_url'], '', $next);
            $query = [];
        }

        return $items;
    }

    private function requestToken(array $params): array
    {
        $url = $this->config['base_url'] . '/api/oauth2/token';
        $params['client_id'] = $this->config['client_id'];
        $params['client_secret'] = $this->config['client_secret'];

        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $response = $this->curlJsonRequest('POST', $url, $headers, http_build_query($params));

        if ($response['status'] >= 300) {
            throw new RuntimeException('Token request failed: HTTP ' . $response['status'] . ' ' . $response['body']);
        }

        if (!isset($response['json']['access_token'])) {
            throw new RuntimeException('Token response did not contain access_token.');
        }

        $token = $response['json'];
        $token['expires_at'] = time() + (int)($token['expires_in'] ?? 600);
        return $token;
    }

    private function curlJsonRequest(string $method, string $url, array $headers, ?string $payload = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($body, true);
        if ($json === null && $body !== '' && strtolower(trim($body)) !== 'null') {
            $json = ['raw' => $body];
        }

        return [
            'status' => $status,
            'body' => $body,
            'json' => is_array($json) ? $json : [],
        ];
    }
}
