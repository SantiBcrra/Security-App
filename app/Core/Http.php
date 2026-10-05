<?php
declare(strict_types=1);

namespace App\Core;

/** Cliente HTTP mínimo (cURL) con timeouts cortos, para APIs externas (Expo, WhatsApp). */
final class Http
{
    /** POST con cuerpo binario (Web Push). @return array{status:int, body:string, error:?string} */
    public static function post(string $url, string $body, array $headers = [], int $timeout = 5): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(3, $timeout), CURLOPT_TIMEOUT => $timeout,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $response === false ? curl_error($ch) : null;
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $response, 'error' => $error];
    }

    /** @return array{status:int, body:string, error:?string, json:?array} */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 5): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $body === false ? curl_error($ch) : null;
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        return ['status' => $status, 'body' => (string) $body, 'error' => $error, 'json' => is_array($json) ? $json : null];
    }
}
