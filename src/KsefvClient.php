<?php

declare(strict_types=1);

namespace Cmi\Ksefv;

use Cmi\Ksefv\Dto\InvoiceSummary;
use Cmi\Ksefv\Dto\ConnectionReadiness;
use Cmi\Ksefv\Dto\PaymentMethod;
use Cmi\Ksefv\Dto\KsefSendResult;
use Cmi\Ksefv\Dto\IssuedInvoice;
use Cmi\Ksefv\Dto\KsefStatus;
use Cmi\Ksefv\Dto\OrderInvoice;
use Cmi\Ksefv\Dto\OrderReceipt;
use Cmi\Ksefv\Exception\KsefvException;
use Cmi\Ksefv\Logging\ObservedHttpClient;
use Cmi\Ksefv\Logging\SafeLogger;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Client of the KSeFV integration API, on a company API key (`X-API-Key`).
 *
 * The flow KSeFV offers to an external system: submit the order (idempotent per system + order
 * id), issue its invoice (idempotent per receipt), send that invoice to KSeF, then poll the KSeF
 * status — KSeFV has no webhooks. Stateless: the connection comes with every call.
 *
 * Errors are classified, not just thrown (see KsefvException): refusals require attention,
 * retry-later carries a backoff hint, an uncertain write may have taken effect. Submitting and
 * issuing are safe to repeat with the same data; sending to KSeF is not — after an uncertain
 * send, read `ksefStatus()` before sending again.
 */
final class KsefvClient
{
    /** Conservative fallback when Retry-After is missing or malformed. */
    public const int DEFAULT_RETRY_AFTER = 3;
    private const int MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;
    private const string UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    public function __construct(private readonly HttpClientInterface $http, private readonly ?LoggerInterface $logger = null)
    {
    }

    /** Read-only API readiness; does not issue a document or validate MF credentials. */
    public function testConnection(KsefvConnection $connection): ConnectionReadiness
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        $data = $this->json($connection, 'GET', '/api/integrations/whoami', [200]);
        $company = $data['company'] ?? null;
        $flags = $data['integrationOrders'] ?? null;
        $scopes = $data['scopes'] ?? null;
        if (!is_array($company) || !array_key_exists('nip', $company) || !array_key_exists('name', $company)
            || !(null === $company['nip'] || is_string($company['nip']))
            || !(null === $company['name'] || is_string($company['name']))
            || !is_bool($data['apiPlanActive'] ?? null) || !is_array($flags)
            || !is_bool($flags['enabled'] ?? null) || !is_bool($flags['issueEnabled'] ?? null)
            || !is_array($scopes) || !array_is_list($scopes)) {
            throw KsefvException::uncertain('KSeFV zwróciło nieczytelną konfigurację integracji.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || '' === $scope) {
                throw KsefvException::uncertain('KSeFV zwróciło nieczytelne zakresy klucza.');
            }
        }
        return new ConnectionReadiness($company['nip'], $company['name'], array_values(array_unique($scopes)),
            $data['apiPlanActive'], $flags['enabled'], $flags['issueEnabled']);
    }

    /** @return list<PaymentMethod> Actual dictionary IDs; never guessed provider codes. */
    public function paymentMethods(KsefvConnection $connection): array
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        $data = $this->json($connection, 'GET', '/api/dictionaries/payment-methods', [200]);
        if (!is_array($data['items'] ?? null) || !array_is_list($data['items'])) {
            throw KsefvException::uncertain('KSeFV zwróciło nieczytelny słownik metod płatności.');
        }
        $methods = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item) || !is_int($item['id'] ?? null) || $item['id'] < 1
                || !is_string($item['name'] ?? null) || !is_bool($item['requiresBankAccount'] ?? null)
                || !array_key_exists('ksefCode', $item) || !(null === $item['ksefCode'] || is_string($item['ksefCode']))) {
                throw KsefvException::uncertain('KSeFV zwróciło nieczytelną metodę płatności.');
            }
            $methods[] = new PaymentMethod($item['id'], $item['name'], $item['ksefCode'], $item['requiresBankAccount']);
        }
        return $methods;
    }

    /** `POST /api/integrations/orders` (scope `invoices.write`). */
    public function submitOrder(KsefvConnection $connection, OrderInvoice $order): OrderReceipt
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        $data = $this->json($connection, 'POST', '/api/integrations/orders', [200, 201], $order->toJson());

        return $this->receiptFrom($data);
    }

    /** `GET /api/integrations/orders/{id}` (scope `invoices.read`). */
    public function receipt(KsefvConnection $connection, string $receiptId): OrderReceipt
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        return $this->receiptFrom($this->json($connection, 'GET', '/api/integrations/orders/'.self::id($receiptId), [200]));
    }

    /** `POST /api/integrations/orders/{id}/issue` (scopes `invoices.write` + `invoices.issue`). */
    public function issue(KsefvConnection $connection, string $receiptId): IssuedInvoice
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        $data = $this->json($connection, 'POST', '/api/integrations/orders/'.self::id($receiptId).'/issue', [200, 201], '{}');
        if (!is_string($data['invoiceId'] ?? null) || !is_string($data['number'] ?? null) || 'issued' !== ($data['state'] ?? null)) {
            throw KsefvException::uncertain('KSeFV potwierdziło wystawienie bez identyfikatora lub numeru faktury.');
        }

        return new IssuedInvoice((string) ($data['id'] ?? $receiptId), $data['invoiceId'], $data['number'], true === ($data['replayed'] ?? false));
    }

    /**
     * `POST /api/ksef/invoice/{id}/send` (scope `ksef.send`): hands the frozen XML to KSeFV's worker.
     *
     * 202 means queued, not accepted by KSeF. Only the explicit already_submitted code
     * is returned as an existing submission; unsupported or generic400 remains a refusal.
     */
    public function sendToKsef(KsefvConnection $connection, string $invoiceId): KsefSendResult
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        $response = $this->send($connection, 'POST', '/api/ksef/invoice/'.self::id($invoiceId).'/send', 'application/json', null);
        [$status, $content] = $this->read($response);
        $data = json_decode($content, true);
        if (202 === $status) {
            if (!is_array($data)) throw KsefvException::uncertain('Nieczytelne potwierdzenie kolejki KSeFV.', $status);
            return new KsefSendResult('queued', false, false);
        }
        if (400 === $status && is_array($data) && 'already_submitted' === ($data['code'] ?? null)) {
            if (!in_array($data['outcome'] ?? null, ['accepted', 'processing'], true) || !is_bool($data['requiresReview'] ?? null)) {
                throw KsefvException::uncertain('KSeFV zgłosiło istniejącą wysyłkę bez rozpoznawalnego stanu.', $status);
            }
            return new KsefSendResult($data['outcome'], true, $data['requiresReview']);
        }
        throw $this->failure($status, $content, $response);
    }

    /** `GET /api/ksef/invoice/{id}/status` (scope `ksef.download`). */
    public function ksefStatus(KsefvConnection $connection, string $invoiceId, ?float $maxDurationSeconds = null): KsefStatus
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        $data = $this->json($connection, 'GET', '/api/ksef/invoice/'.self::id($invoiceId).'/status', [200], maxDurationSeconds:$maxDurationSeconds);
        $outcome = $data['outcome'] ?? null;
        $nullable = in_array($outcome, [KsefStatus::NOT_SUBMITTED, KsefStatus::PROCESSING], true);
        if (!in_array($outcome, [KsefStatus::ACCEPTED, KsefStatus::REJECTED, KsefStatus::PROCESSING, KsefStatus::NOT_SUBMITTED], true)
            || !array_key_exists('processingStatus', $data)
            || !(is_int($data['processingStatus']) || ($nullable && null === $data['processingStatus']))) {
            throw KsefvException::uncertain('KSeFV zwróciło status KSeF bez rozpoznawalnego wyniku.');
        }
        $description = $data['processingDescription'] ?? $data['processingStatusDescription'] ?? null;

        return new KsefStatus(
            $outcome,
            $data['processingStatus'],
            is_string($description) ? $description : null,
            is_string($data['ksefNumber'] ?? null) && '' !== $data['ksefNumber'] ? $data['ksefNumber'] : null,
            is_string($data['acquisitionDate'] ?? null) ? $data['acquisitionDate'] : null,
            true === ($data['requiresReview'] ?? false),
        );
    }

    /** `GET /api/invoices/{id}` (scope `invoices.read`). */
    public function invoice(KsefvConnection $connection, string $invoiceId): InvoiceSummary
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        $data = $this->json($connection, 'GET', '/api/invoices/'.self::id($invoiceId), [200]);
        foreach (['id', 'status', 'currency', 'totalNet', 'totalVat', 'totalGross'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                throw KsefvException::uncertain(sprintf('KSeFV zwróciło fakturę bez pola %s.', $key));
            }
        }

        return new InvoiceSummary(
            $data['id'],
            is_string($data['number'] ?? null) ? $data['number'] : null,
            $data['status'],
            $data['currency'],
            $data['totalNet'],
            $data['totalVat'],
            $data['totalGross'],
            is_string($data['ksefNumber'] ?? null) && '' !== $data['ksefNumber'] ? $data['ksefNumber'] : null,
        );
    }

    /** `GET /api/invoices/{id}/pdf` (scope `invoices.download`): the PDF bytes. */
    public function invoicePdf(KsefvConnection $connection, string $invoiceId): string
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        $body = $this->document($connection, '/api/invoices/'.self::id($invoiceId).'/pdf', 'application/pdf');
        if (!str_starts_with($body, '%PDF-')) {
            throw KsefvException::uncertain('KSeFV zwróciło PDF bez nagłówka %PDF.');
        }

        return $body;
    }

    /** `GET /api/ksef/invoice/{id}/upo` (scope `ksef.download`): the UPO XML (signature not verified by KSeFV). */
    public function upo(KsefvConnection $connection, string $invoiceId): string
    {
        if (null !== $this->logger) return $this->observe(__FUNCTION__, func_get_args());
        return $this->document($connection, '/api/ksef/invoice/'.self::id($invoiceId).'/upo', 'application/xml');
    }

    /**
     * @param list<int> $success
     * @return array<string, mixed>
     */
    private function json(KsefvConnection $connection, string $method, string $path, array $success, ?string $body = null, ?float $maxDurationSeconds = null): array
    {
        $response = $this->send($connection, $method, $path, 'application/json', $body, $maxDurationSeconds);
        [$status, $content] = $this->read($response);
        if (!in_array($status, $success, true)) {
            throw $this->failure($status, $content, $response);
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw KsefvException::uncertain(sprintf('Nieczytelna odpowiedź KSeFV (HTTP %d).', $status), $status);
        }

        return $data;
    }

    private function document(KsefvConnection $connection, string $path, string $type): string
    {
        $response = $this->send($connection, 'GET', $path, $type.', application/json', null);
        [$status, $content] = $this->read($response);
        if (200 !== $status) {
            throw $this->failure($status, $content, $response);
        }
        $actual = strtolower((string) ($response->getHeaders(false)['content-type'][0] ?? ''));
        if (!str_starts_with($actual, $type) || strlen($content) > self::MAX_DOCUMENT_BYTES) {
            throw KsefvException::uncertain(sprintf('KSeFV zwróciło dokument typu „%s” zamiast %s.', $actual, $type), $status);
        }

        return $content;
    }

    private function send(KsefvConnection $connection, string $method, string $path, string $accept, ?string $body, ?float $maxDurationSeconds = null): ResponseInterface
    {
        if (null !== $maxDurationSeconds && (!is_finite($maxDurationSeconds) || $maxDurationSeconds <= 0)) {
            throw new \InvalidArgumentException('Budżet żądania musi być dodatnią, skończoną liczbą sekund.');
        }
        $headers = ['X-API-Key' => $connection->apiKey, 'Accept' => $accept];
        $options = ['headers' => $headers, 'timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0];
        if (null !== $maxDurationSeconds) {
            $options['timeout'] = min(15.0, $maxDurationSeconds);
            $options['max_duration'] = min(30.0, $maxDurationSeconds);
        }
        if (null !== $body) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $body;
        }
        try {
            return $this->http->request($method, $connection->baseUrl.$path, $options);
        } catch (TransportExceptionInterface $noAnswer) {
            throw KsefvException::uncertain('KSeFV nie odpowiedziało.', null, $noAnswer);
        }
    }

    /** @return array{int, string} */
    private function read(ResponseInterface $response): array
    {
        try {
            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface $noAnswer) {
            throw KsefvException::uncertain('KSeFV nie dokończyło odpowiedzi.', null, $noAnswer);
        }
    }

    /**
     * KSeFV answers errors in two shapes: `{"error": "..."}` (authentication, key guard, KSeF and
     * PDF controllers) and problem+json `{"detail": "..."}` (exceptions on /api).
     */
    private function failure(int $status, string $content, ResponseInterface $response): KsefvException
    {
        $data = json_decode($content, true);
        $detail = is_array($data) ? ($data['detail'] ?? $data['error'] ?? $data['message'] ?? null) : null;
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        $serverCode = is_string($code) && 1 === preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) ? $code : null;
        $message = sprintf('KSeFV: HTTP %d%s', $status, is_string($detail) && '' !== $detail ? ' — '.$detail : '');

        if (429 === $status || 503 === $status) {
            $header = $response->getHeaders(false)['retry-after'][0] ?? null;
            $retry = null !== $header && 1 === preg_match('/^\d{1,5}$/D', $header) ? max(1, (int) $header) : self::DEFAULT_RETRY_AFTER;

            return KsefvException::retryLater($message, $status, $retry, $serverCode);
        }
        if ($status >= 500 || 408 === $status) {
            return KsefvException::uncertain($message, $status, serverCode:$serverCode);
        }
        if ($status >= 300 && $status < 400) {
            return KsefvException::uncertain($message.' (nieoczekiwane przekierowanie)', $status, serverCode:$serverCode);
        }

        return KsefvException::refused($message, $status, $serverCode);
    }

    /** @param array<string, mixed> $data */
    private function receiptFrom(array $data): OrderReceipt
    {
        foreach (['id', 'system', 'orderId', 'payloadSha256', 'state', 'createdAt'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                throw KsefvException::uncertain(sprintf('Potwierdzenie KSeFV bez pola %s.', $key));
            }
        }
        try {
            $createdAt = new \DateTimeImmutable($data['createdAt']);
        } catch (\Exception) {
            throw KsefvException::uncertain('Potwierdzenie KSeFV z nieczytelną datą.');
        }

        return new OrderReceipt(
            $data['id'],
            $data['system'],
            $data['orderId'],
            $data['payloadSha256'],
            $data['state'],
            is_string($data['invoiceId'] ?? null) ? $data['invoiceId'] : null,
            $createdAt,
            true === ($data['replayed'] ?? false),
        );
    }

    /** Per-call isolated observer: no connection, key or previous trace is kept on this client. */
    private function observe(string $method, array $arguments): mixed
    {
        [$operation, $verb, $route] = match ($method) {
            'testConnection' => ['connection.test', 'GET', '/api/integrations/whoami'],
            'paymentMethods' => ['payment_methods.read', 'GET', '/api/dictionaries/payment-methods'],
            'submitOrder' => ['orders.submit', 'POST', '/api/integrations/orders'],
            'receipt' => ['orders.receipt', 'GET', '/api/integrations/orders/{receiptId}'],
            'issue' => ['orders.issue', 'POST', '/api/integrations/orders/{receiptId}/issue'],
            'sendToKsef' => ['ksef.send', 'POST', '/api/ksef/invoice/{invoiceId}/send'],
            'ksefStatus' => ['ksef.status', 'GET', '/api/ksef/invoice/{invoiceId}/status'],
            'invoice' => ['invoice.read', 'GET', '/api/invoices/{invoiceId}'],
            'invoicePdf' => ['invoice.pdf', 'GET', '/api/invoices/{invoiceId}/pdf'],
            'upo' => ['ksef.upo', 'GET', '/api/ksef/invoice/{invoiceId}/upo'],
        };
        $target = $arguments[1] ?? null;
        $base = ['requestId'=>bin2hex(random_bytes(16)), 'operation'=>$operation, 'method'=>$verb, 'routeTemplate'=>$route,
            'targetId'=>is_string($target) && 1 === preg_match(self::UUID,$target) ? strtolower($target) : null];
        $logger = new SafeLogger($this->logger);
        $started = hrtime(true);
        $logger->log('info', 'ksefv.operation.started', $base);
        $transport = new ObservedHttpClient($this->http, $base['requestId'], static function() use($logger,$base):void {
            $logger->log('info', 'ksefv.http.request_started', $base);
        });
        // The inner client has no logger, avoiding recursion and preserving all existing parsers.
        $inner = new self($transport);
        try {
            $result = $inner->{$method}(...$arguments);
            $context = $base + $transport->metadata() + ['durationMs'=>max(0,(int)round((hrtime(true)-$started)/1_000_000))];
            $context += match (true) {
                $result instanceof KsefSendResult => ['outcome'=>$result->outcome,'alreadySubmitted'=>$result->alreadySubmitted,'requiresReview'=>$result->requiresReview],
                $result instanceof KsefStatus => ['outcome'=>$result->outcome,'requiresReview'=>$result->requiresReview],
                $result instanceof OrderReceipt => ['outcome'=>'received','replayed'=>$result->replayed],
                $result instanceof IssuedInvoice => ['outcome'=>'issued','replayed'=>$result->replayed],
                $result instanceof InvoiceSummary => ['outcome'=>in_array($result->status,['draft','issued','processing','sent','paid','rejected'],true)?$result->status:'unknown'],
                $result instanceof ConnectionReadiness => ['outcome'=>$result->isReady()?'configured':'not_ready'],
                is_array($result) => ['outcome'=>'read','itemCount'=>count($result)],
                is_string($result) => ['outcome'=>'downloaded','responseBytes'=>strlen($result)],
                default => ['outcome'=>'unknown'],
            };
            $logger->log('info', 'ksefv.operation.completed', $context);
            return $result;
        } catch (\Throwable $failure) {
            $code = $failure instanceof KsefvException ? $failure->serverCode : null;
            $code = null === $code ? null : (in_array($code,['unsupported','already_submitted','ksef_status_unavailable'],true)?$code:'other');
            $context = $base + $transport->metadata() + ['durationMs'=>max(0,(int)round((hrtime(true)-$started)/1_000_000)),
                'failureKind'=>$failure instanceof KsefvException?$failure->kind:'client_error', 'serverCode'=>$code,
                'retryAfterSeconds'=>$failure instanceof KsefvException?$failure->retryAfterSeconds:null, 'exceptionType'=>$failure::class];
            $logger->log($failure instanceof KsefvException && !$failure->isUncertain()?'warning':'error', 'ksefv.operation.failed', $context);
            throw $failure;
        }
    }

    private static function id(string $id): string
    {
        if (1 !== preg_match(self::UUID, $id)) {
            throw new \InvalidArgumentException('Identyfikator KSeFV musi być UUID.');
        }

        return strtolower($id);
    }
}
