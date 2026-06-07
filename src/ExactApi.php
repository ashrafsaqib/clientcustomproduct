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
