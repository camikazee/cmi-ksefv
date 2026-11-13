<?php

declare(strict_types=1);

namespace Cmi\Ksefv;

use Cmi\Ksefv\Dto\InvoiceSummary;
use Cmi\Ksefv\Dto\IssuedInvoice;
use Cmi\Ksefv\Dto\KsefStatus;
use Cmi\Ksefv\Dto\OrderInvoice;
use Cmi\Ksefv\Dto\OrderReceipt;
use Cmi\Ksefv\Exception\KsefvException;
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
 * Errors are classified, not just thrown (see KsefvException): a refusal changed nothing, a
 * retry-later changed nothing yet, an uncertain outcome may have taken effect. Submitting and
 * issuing are safe to repeat with the same data; sending to KSeF is not — after an uncertain
 * send, read `ksefStatus()` before sending again.
 */
final class KsefvClient
{
    /** KSeFV drops Retry-After from its problem+json errors; this is the pause to use then. */
    public const int DEFAULT_RETRY_AFTER = 3;
    private const int MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;
    private const string UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /** `POST /api/integrations/orders` (scope `invoices.write`). */
    public function submitOrder(KsefvConnection $connection, OrderInvoice $order): OrderReceipt
    {
        $data = $this->json($connection, 'POST', '/api/integrations/orders', [200, 201], $order->toJson());

        return $this->receiptFrom($data);
    }

    /** `GET /api/integrations/orders/{id}` (scope `invoices.read`). */
    public function receipt(KsefvConnection $connection, string $receiptId): OrderReceipt
    {
        return $this->receiptFrom($this->json($connection, 'GET', '/api/integrations/orders/'.self::id($receiptId), [200]));
    }

    /** `POST /api/integrations/orders/{id}/issue` (scopes `invoices.write` + `invoices.issue`). */
    public function issue(KsefvConnection $connection, string $receiptId): IssuedInvoice
    {
        $data = $this->json($connection, 'POST', '/api/integrations/orders/'.self::id($receiptId).'/issue', [200, 201], '{}');
        if (!is_string($data['invoiceId'] ?? null) || !is_string($data['number'] ?? null) || 'issued' !== ($data['state'] ?? null)) {
            throw KsefvException::uncertain('KSeFV potwierdziło wystawienie bez identyfikatora lub numeru faktury.');
        }

        return new IssuedInvoice((string) ($data['id'] ?? $receiptId), $data['invoiceId'], $data['number'], true === ($data['replayed'] ?? false));
    }

    /**
     * `POST /api/ksef/invoice/{id}/send` (scope `ksef.send`): hands the frozen XML to KSeFV's worker.
     *
     * Success means queued, not accepted by KSeF. KSeFV answers an invoice already sent with 400,
     * like an unsupported one — so after an uncertain send, ask `ksefStatus()` instead of repeating.
     */
    public function sendToKsef(KsefvConnection $connection, string $invoiceId): void
    {
        $this->json($connection, 'POST', '/api/ksef/invoice/'.self::id($invoiceId).'/send', [202]);
    }

    /** `GET /api/ksef/invoice/{id}/status` (scope `ksef.download`). */
    public function ksefStatus(KsefvConnection $connection, string $invoiceId): KsefStatus
    {
        $data = $this->json($connection, 'GET', '/api/ksef/invoice/'.self::id($invoiceId).'/status', [200]);
        $outcome = $data['outcome'] ?? null;
        if (!in_array($outcome, [KsefStatus::ACCEPTED, KsefStatus::REJECTED, KsefStatus::PROCESSING], true) || !is_int($data['processingStatus'] ?? null)) {
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
        $body = $this->document($connection, '/api/invoices/'.self::id($invoiceId).'/pdf', 'application/pdf');
        if (!str_starts_with($body, '%PDF-')) {
            throw KsefvException::uncertain('KSeFV zwróciło PDF bez nagłówka %PDF.');
        }

        return $body;
    }

    /** `GET /api/ksef/invoice/{id}/upo` (scope `ksef.download`): the UPO XML (signature not verified by KSeFV). */
    public function upo(KsefvConnection $connection, string $invoiceId): string
    {
        return $this->document($connection, '/api/ksef/invoice/'.self::id($invoiceId).'/upo', 'application/xml');
    }

    /**
     * @param list<int> $success
     * @return array<string, mixed>
     */
    private function json(KsefvConnection $connection, string $method, string $path, array $success, ?string $body = null): array
    {
        $response = $this->send($connection, $method, $path, 'application/json', $body);
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

    private function send(KsefvConnection $connection, string $method, string $path, string $accept, ?string $body): ResponseInterface
    {
        $headers = ['X-API-Key' => $connection->apiKey, 'Accept' => $accept];
        $options = ['headers' => $headers, 'timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0];
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
        $message = sprintf('KSeFV: HTTP %d%s', $status, is_string($detail) && '' !== $detail ? ' — '.$detail : '');

        if (429 === $status || 503 === $status) {
            $header = $response->getHeaders(false)['retry-after'][0] ?? null;
            $retry = null !== $header && 1 === preg_match('/^\d{1,5}$/D', $header) ? max(1, (int) $header) : self::DEFAULT_RETRY_AFTER;

            return KsefvException::retryLater($message, $status, $retry);
        }
        if ($status >= 500 || 408 === $status) {
            return KsefvException::uncertain($message, $status);
        }
        if ($status >= 300 && $status < 400) {
            return KsefvException::uncertain($message.' (nieoczekiwane przekierowanie)', $status);
        }

        return KsefvException::refused($message, $status);
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

    private static function id(string $id): string
    {
        if (1 !== preg_match(self::UUID, $id)) {
            throw new \InvalidArgumentException('Identyfikator KSeFV musi być UUID.');
        }

        return strtolower($id);
    }
}
