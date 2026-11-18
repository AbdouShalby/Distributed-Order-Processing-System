<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

final class IntegrationHttpClient
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $baseUrl = rtrim((string) getenv('INTEGRATION_BASE_URL'), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('INTEGRATION_BASE_URL is required.');
        }

        $requestHeaders = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name.': '.$value;
        }
        $content = '';
        if ($body !== null) {
            $requestHeaders[] = 'Content-Type: application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $requestHeaders),
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);
        $raw = file_get_contents($baseUrl.$path, false, $context);
        if ($raw === false || ! isset($http_response_header[0])) {
            throw new \RuntimeException("HTTP request to {$path} failed without a response.");
        }

        preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches);

        return [
            'status' => (int) ($matches[1] ?? 0),
            'body' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR),
        ];
    }
}
