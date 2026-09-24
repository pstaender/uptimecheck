<?php

declare(strict_types=1);

namespace Tests\Support;

use CurlHandle;

/**
 * Minimal http client for the web interface tests. Keeps cookies between requests.
 */
final class Browser
{
    private CurlHandle $curl;

    public function __construct(private string $baseUrl = '')
    {
        $this->baseUrl = $baseUrl ?: Env::webUrl();
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => '', // in-memory cookie jar
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ]);
    }

    /**
     * @return array{status: int, body: string, location: ?string}
     */
    public function get(string $path = '/index.php', array $query = []): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        curl_setopt_array($this->curl, [CURLOPT_URL => $url, CURLOPT_HTTPGET => true]);
        return $this->send();
    }

    /**
     * @return array{status: int, body: string, location: ?string}
     */
    public function post(array $data, string $path = '/index.php'): array
    {
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
        ]);
        return $this->send();
    }

    public function csrf(): string
    {
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $this->get()['body'], $m);
        return $m[1] ?? '';
    }

    /**
     * @return array{status: int, body: string, location: ?string}
     */
    public function login(string $user = 'admin', string $password = Env::PASSWORD): array
    {
        return $this->post(['csrf' => $this->csrf(), 'action' => 'login', 'user' => $user, 'password' => $password]);
    }

    private function send(): array
    {
        $location = null;
        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, function ($curl, string $header) use (&$location): int {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
            return strlen($header);
        });
        $body = (string) curl_exec($this->curl);
        return [
            'status' => curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE),
            'body' => $body,
            'location' => $location,
        ];
    }
}
