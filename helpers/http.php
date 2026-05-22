<?php
/**
 * cURL HTTP client with retry mechanism
 */

declare(strict_types=1);

/**
 * Perform HTTP request with retries
 *
 * @param string $method GET|POST|PUT|PATCH|DELETE
 * @param string $url
 * @param array<string, mixed> $options headers, body, json, bearer, timeout
 * @return array{success: bool, status: int, body: string, json: ?array, error: ?string}
 */
function httpRequest(string $method, string $url, array $options = []): array
{
    $maxRetries = (int) ($options['retries'] ?? HTTP_RETRY_MAX);
    $delayMs = (int) ($options['retry_delay_ms'] ?? HTTP_RETRY_DELAY_MS);
    $timeout = (int) ($options['timeout'] ?? HTTP_TIMEOUT);

    $lastResult = [
        'success' => false,
        'status' => 0,
        'body' => '',
        'json' => null,
        'error' => 'No attempt made',
    ];

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $lastResult = httpRequestOnce($method, $url, $options, $timeout);

        // Success or client error (4xx) — do not retry
        if ($lastResult['success'] || ($lastResult['status'] >= 400 && $lastResult['status'] < 500)) {
            return $lastResult;
        }

        if ($attempt < $maxRetries) {
            usleep($delayMs * 1000 * $attempt);
            logWarning(sprintf(
                'HTTP retry %d/%d for %s %s (status %d)',
                $attempt,
                $maxRetries,
                $method,
                $url,
                $lastResult['status']
            ));
        }
    }

    return $lastResult;
}

/**
 * Single HTTP attempt
 */
function httpRequestOnce(string $method, string $url, array $options, int $timeout): array
{
    $ch = curl_init();

    $headers = $options['headers'] ?? [];

    if (!empty($options['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $options['bearer'];
    }

    if (!empty($options['json'])) {
        $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif (isset($options['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [
            'success' => false,
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => $error ?: 'cURL request failed',
        ];
    }

    $decoded = json_decode($body, true);

    return [
        'success' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
        'error' => null,
    ];
}

/**
 * GET request shorthand
 */
function httpGet(string $url, array $options = []): array
{
    return httpRequest('GET', $url, $options);
}

/**
 * POST request shorthand
 */
function httpPost(string $url, array $options = []): array
{
    return httpRequest('POST', $url, $options);
}

/**
 * PUT request shorthand
 */
function httpPut(string $url, array $options = []): array
{
    return httpRequest('PUT', $url, $options);
}
