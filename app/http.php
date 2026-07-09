<?php

declare(strict_types=1);

function erdet_fetch_text(string $url, ?int $timeoutSeconds = null): string
{
    $config = erdet_config();
    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Kunne ikke starte cURL');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds ?? $config['http_timeout'],
        CURLOPT_TIMEOUT => $timeoutSeconds ?? $config['http_timeout'],
        CURLOPT_USERAGENT => $config['user_agent'],
        CURLOPT_HTTPHEADER => ['Accept: text/html, application/rss+xml, application/xml;q=0.9, */*;q=0.8'],
    ]);

    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException($error !== '' ? $error : 'Kilden kunne ikke hentes');
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Kilden svarte med HTTP ' . $status);
    }

    return (string) $body;
}

function erdet_post_json(string $url, array $payload, array $headers = [], ?int $timeoutSeconds = null): array
{
    $config = erdet_config();
    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Kunne ikke starte cURL');
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded)) {
        throw new RuntimeException('Kunne ikke serialisere JSON');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds ?? $config['http_timeout'],
        CURLOPT_TIMEOUT => $timeoutSeconds ?? $config['http_timeout'],
        CURLOPT_USERAGENT => $config['user_agent'],
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $headers),
    ]);

    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException($error !== '' ? $error : 'API-kall feilet');
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('OpenAI svarte med HTTP ' . $status);
    }

    $decoded = json_decode((string) $body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('API svarte ikke med gyldig JSON');
    }

    return $decoded;
}

