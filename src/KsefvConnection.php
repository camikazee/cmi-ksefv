<?php

declare(strict_types=1);

namespace Cmi\Ksefv;

/**
 * Where the KSeFV instance is and the company API key to use there.
 *
 * Passed on every call, so one client serves any number of companies. The library never stores
 * it; keeping the key encrypted is the application's job. The address must be HTTPS — the key
 * travels in a header — except a loopback address for local development.
 */
final readonly class KsefvConnection
{
    public string $baseUrl;

    public function __construct(string $baseUrl, #[\SensitiveParameter] public string $apiKey)
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        if ('' === $host || !('https' === $scheme || ('http' === $scheme && $loopback))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Adres KSeFV musi być adresem HTTPS bez danych logowania, zapytania i fragmentu.');
        }
        if ('' === trim($apiKey) || 1 === preg_match('/[\x00-\x20\x7f]/', $apiKey)) {
            throw new \InvalidArgumentException('Klucz API KSeFV jest pusty albo zawiera niedozwolone znaki.');
        }
        $this->baseUrl = $baseUrl;
    }
}
