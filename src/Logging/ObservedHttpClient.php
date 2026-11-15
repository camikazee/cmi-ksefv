<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Logging;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Internal, short-lived transport observer for one public API call. */
final class ObservedHttpClient implements HttpClientInterface
{
    private ?ResponseInterface $response = null;
    private int $requestCount = 0;

    public function __construct(private readonly HttpClientInterface $client, private readonly string $requestId, private readonly \Closure $onRequest) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['headers']['X-Request-ID'] = $this->requestId;
        ++$this->requestCount;
        ($this->onRequest)();
        return $this->response = $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options), $this->requestId, $this->onRequest);
    }

    public function metadata(): array
    {
        $status = null;
        $serverId = null;
        try {
            // getInfo is nonblocking: never force a second read after a timeout or refusal.
            $code = $this->response?->getInfo('http_code');
            if (is_int($code) && $code >= 100 && $code <= 599) $status = $code;
            $headers = $this->response?->getInfo('response_headers');
            foreach (is_array($headers)?array_slice($headers,0,100):[] as $header) {
                if (is_string($header) && 1 === preg_match('/^x-request-id:\s*([0-9a-f]{32})\s*$/iD',$header,$matches)) $serverId=strtolower($matches[1]);
            }
        } catch (\Throwable) { /* Optional metadata cannot change the API result. */ }
        return ['httpStatus'=>$status,'serverRequestId'=>$serverId,'requestCount'=>$this->requestCount];
    }
}
