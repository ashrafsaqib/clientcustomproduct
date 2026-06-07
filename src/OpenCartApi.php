<?php

declare(strict_types=1);

class OpenCartApi
{
    private string $baseUrl;
    private string $username;
    private string $apiKey;

    public function __construct(string $baseUrl, string $username, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->apiKey = $apiKey;
    }

    public function hasCredentials(): bool
    {
        return $this->baseUrl !== '' && $this->username !== '' && $this->apiKey !== '';
    }

    public function testConnection(): array
    {
        if (!$this->hasCredentials()) {
            throw new RuntimeException('OpenCart credentials are not configured in .env');
        }

        $response = $this->post('/index.php?route=api/login', [
            'username' => $this->username,
            'key' => $this->apiKey,
        ]);

        if ($response['status'] >= 300) {
            throw new RuntimeException('OpenCart login HTTP error ' . $response['status'] . ': ' . $response['body']);
        }

        if (!empty($response['json']['error'])) {
            throw new RuntimeException('OpenCart login failed: ' . json_encode($response['json']['error']));
        }

        if (empty($response['json']['api_token'])) {
            throw new RuntimeException('OpenCart login did not return api_token. Ensure API user is enabled and has permissions.');
        }

        return [
            'status' => 'ok',
            'api_token_prefix' => substr((string)$response['json']['api_token'], 0, 8) . '...',
            'raw' => $response['json'],
        ];
    }

    private function post(string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL for OpenCart.');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('OpenCart cURL error: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $json = [];
        }

        return [
            'status' => $status,
            'body' => $body,
            'json' => $json,
        ];
    }
}
