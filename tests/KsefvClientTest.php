<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Tests;

use Cmi\Ksefv\Dto\KsefStatus;
use Cmi\Ksefv\Exception\KsefvException;
use Cmi\Ksefv\KsefvClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Requests and answers exactly as KSeFV's code (2026-11-13, ksefv-symfony 2340f2e) defines them. */
final class KsefvClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $sent = [];

    public function testSubmittingAnOrderSendsTheExactContractWithTheKey(): void
    {
        $client = $this->client(new MockResponse(json_encode([
            'id' => Fixtures::RECEIPT, 'system' => 'todoly', 'orderId' => 'TD-0190f1c2', 'payloadSha256' => str_repeat('a', 64),
            'state' => 'received', 'invoiceId' => null, 'createdAt' => '2026-11-13T10:00:00Z', 'replayed' => false,
        ], JSON_THROW_ON_ERROR), ['http_code' => 201]));

        $receipt = $client->submitOrder(Fixtures::connection(), Fixtures::order());

        [$request] = $this->sent;
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.ksefv.example/api/integrations/orders', $request['url']);
        self::assertContains('X-API-Key: '.Fixtures::KEY, $request['options']['headers']);
        self::assertContains('Content-Type: application/json', $request['options']['headers']);
        self::assertSame(Fixtures::order()->toJson(), $request['options']['body']);
        self::assertSame(0, $request['options']['max_redirects']);
        self::assertSame([Fixtures::RECEIPT, 'received', null, false, false], [$receipt->id, $receipt->state, $receipt->invoiceId, $receipt->replayed, $receipt->isIssued()]);
    }

    public function testARepeatedOrderIsAReplayNotANewOne(): void
    {
        $client = $this->client(new MockResponse(json_encode([
            'id' => Fixtures::RECEIPT, 'system' => 'todoly', 'orderId' => 'TD-0190f1c2', 'payloadSha256' => str_repeat('a', 64),
            'state' => 'issued', 'invoiceId' => Fixtures::INVOICE, 'createdAt' => '2026-11-13T10:00:00Z', 'replayed' => true,
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]));

        $receipt = $client->submitOrder(Fixtures::connection(), Fixtures::order());

        self::assertTrue($receipt->replayed);
        self::assertTrue($receipt->isIssued());
    }

    public function testIssuingSendsAnEmptyObjectAndReturnsTheNumber(): void
    {
        $client = $this->client(new MockResponse('{"id":"'.Fixtures::RECEIPT.'","invoiceId":"'.Fixtures::INVOICE.'","number":"FV/2026/11/0007","state":"issued","replayed":false}', ['http_code' => 201]));

        $issued = $client->issue(Fixtures::connection(), strtoupper(Fixtures::RECEIPT));

        self::assertSame('https://api.ksefv.example/api/integrations/orders/'.Fixtures::RECEIPT.'/issue', $this->sent[0]['url']);
        self::assertSame('{}', $this->sent[0]['options']['body']);
        self::assertSame([Fixtures::INVOICE, 'FV/2026/11/0007', false], [$issued->invoiceId, $issued->number, $issued->replayed]);
    }

    public function testAnIssueAnswerWithoutANumberIsUncertain(): void
    {
        $client = $this->client(new MockResponse('{"id":"'.Fixtures::RECEIPT.'","state":"issued"}', ['http_code' => 201]));

        $failure = $this->failure(static fn () => $client->issue(Fixtures::connection(), Fixtures::RECEIPT));

        self::assertTrue($failure->isUncertain());
    }

    /** @return iterable<string, array{MockResponse, string, ?int, string}> */
    public static function failures(): iterable
    {
        yield 'bad key, guard shape' => [new MockResponse('{"error":"Nieprawidłowy albo odwołany klucz API."}', ['http_code' => 401]), KsefvException::REFUSED, null, 'odwołany klucz'];
        yield 'missing scope' => [new MockResponse('{"error":"Klucz API nie ma uprawnienia „invoices.issue”."}', ['http_code' => 403]), KsefvException::REFUSED, null, 'invoices.issue'];
        yield 'contract, problem+json' => [new MockResponse('{"type":"https://ksefv.pl/docs/errors","title":"Unprocessable Content","status":422,"detail":"Sprawdź dane zamówienia: buyerNip."}', ['http_code' => 422, 'response_headers' => ['content-type' => 'application/problem+json']]), KsefvException::REFUSED, null, 'buyerNip'];
        yield 'other data under the same id' => [new MockResponse('{"type":"x","title":"Conflict","status":409,"detail":"Zamówienie o tym identyfikatorze ma już inne dane."}', ['http_code' => 409]), KsefvException::REFUSED, null, 'inne dane'];
        yield 'feature off' => [new MockResponse('{"type":"x","title":"Not Found","status":404,"detail":"Funkcja integracji jest wyłączona."}', ['http_code' => 404]), KsefvException::REFUSED, null, 'wyłączona'];
        yield 'rate limit keeps its header' => [new MockResponse('{"error":"Przekroczono limit zapytań."}', ['http_code' => 429, 'response_headers' => ['retry-after' => '7']]), KsefvException::RETRY_LATER, 7, 'limit'];
        yield 'busy, header lost in problem+json' => [new MockResponse('{"type":"x","title":"Service Unavailable","status":503,"detail":"Spróbuj później."}', ['http_code' => 503]), KsefvException::RETRY_LATER, KsefvClient::DEFAULT_RETRY_AFTER, 'później'];
        yield 'server error' => [new MockResponse('{"error":"Nie udało się."}', ['http_code' => 500]), KsefvException::UNCERTAIN, null, 'HTTP 500'];
        yield 'unexpected redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://elsewhere.example']]), KsefvException::UNCERTAIN, null, 'przekierowanie'];
        yield 'html instead of json' => [new MockResponse('<html>proxy</html>', ['http_code' => 201]), KsefvException::UNCERTAIN, null, 'Nieczytelna'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testEveryAnswerIsClassified(MockResponse $answer, string $kind, ?int $retryAfter, string $message): void
    {
        $client = $this->client($answer);

        $failure = $this->failure(static fn () => $client->submitOrder(Fixtures::connection(), Fixtures::order()));

        self::assertSame($kind, $failure->kind);
        self::assertSame($retryAfter, $failure->retryAfterSeconds);
        self::assertStringContainsString($message, $failure->getMessage());
    }

    public function testNoAnswerIsUncertain(): void
    {
        $client = $this->client(static fn () => throw new TransportException('timeout'));

        self::assertTrue($this->failure(static fn () => $client->submitOrder(Fixtures::connection(), Fixtures::order()))->isUncertain());
    }

    public function testSendingToKsefIsQueuedAndAnAlreadySentInvoiceIsARefusal(): void
    {
        $client = $this->client(
            new MockResponse('{"message":"Faktura została przekazana do wysyłki do KSeF."}', ['http_code' => 202]),
            new MockResponse('{"error":"Ta faktura została już wysłana do KSeF."}', ['http_code' => 400]),
        );

        $client->sendToKsef(Fixtures::connection(), Fixtures::INVOICE);
        $again = $this->failure(static fn () => $client->sendToKsef(Fixtures::connection(), Fixtures::INVOICE));

        self::assertSame('https://api.ksefv.example/api/ksef/invoice/'.Fixtures::INVOICE.'/send', $this->sent[0]['url']);
        self::assertTrue($again->isRefused());
        self::assertSame(400, $again->httpStatus);
    }

    public function testKsefStatusReadsTheOutcomeAndTheFallbackBody(): void
    {
        $client = $this->client(
            new MockResponse('{"processingStatus":200,"processingDescription":"Przyjęto","processingStatusDescription":"Przyjęto","ksefNumber":"5260250274-20261113-ABCDEF-01","acquisitionDate":"2026-11-13T10:05:00Z","invoiceReferenceNumber":"R","details":[],"requiresReview":false,"originalKsefNumber":null,"originalSessionReferenceNumber":null,"outcome":"accepted"}'),
            new MockResponse('{"processingStatus":100,"processingDescription":"W toku","outcome":"processing","ksefNumber":null,"session":{"status":100}}'),
            new MockResponse('{"processingStatus":200,"outcome":"queued"}'),
        );

        $accepted = $client->ksefStatus(Fixtures::connection(), Fixtures::INVOICE);
        $pending = $client->ksefStatus(Fixtures::connection(), Fixtures::INVOICE);

        self::assertTrue($accepted->isAccepted());
        self::assertSame('5260250274-20261113-ABCDEF-01', $accepted->ksefNumber);
        self::assertSame([KsefStatus::PROCESSING, null, false], [$pending->outcome, $pending->ksefNumber, $pending->requiresReview]);
        self::assertTrue($this->failure(static fn () => $client->ksefStatus(Fixtures::connection(), Fixtures::INVOICE))->isUncertain());
    }

    public function testThePdfMustBeAPdf(): void
    {
        $client = $this->client(
            new MockResponse("%PDF-1.7\n…", ['response_headers' => ['content-type' => 'application/pdf']]),
            new MockResponse('<html>login</html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('{"error":"Generator PDF jest chwilowo niedostępny."}', ['http_code' => 503, 'response_headers' => ['retry-after' => '30']]),
        );

        self::assertStringStartsWith('%PDF-', $client->invoicePdf(Fixtures::connection(), Fixtures::INVOICE));
        self::assertTrue($this->failure(static fn () => $client->invoicePdf(Fixtures::connection(), Fixtures::INVOICE))->isUncertain());
        self::assertSame(30, $this->failure(static fn () => $client->invoicePdf(Fixtures::connection(), Fixtures::INVOICE))->retryAfterSeconds);
    }

    public function testTheInvoiceSummaryCarriesNumberStatusTotalsAndKsefNumber(): void
    {
        $client = $this->client(new MockResponse('{"id":"'.Fixtures::INVOICE.'","number":"FV/2026/11/0007","status":"issued","currency":"PLN","totalNet":"15.45","totalVat":"3.55","totalGross":"19.00","ksefNumber":null}'));

        $invoice = $client->invoice(Fixtures::connection(), Fixtures::INVOICE);

        self::assertSame(['FV/2026/11/0007', 'issued', '19.00', null], [$invoice->number, $invoice->status, $invoice->totalGross, $invoice->ksefNumber]);
        self::assertContains('Accept: application/json', $this->sent[0]['options']['headers']);
    }

    public function testAnIdThatIsNotAUuidNeverReachesTheNetwork(): void
    {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->issue(Fixtures::connection(), '../../admin');
        } finally {
            self::assertSame([], $this->sent);
        }
    }

    private function client(MockResponse|\Closure ...$answers): KsefvClient
    {
        return new KsefvClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $answer = array_shift($answers) ?? throw new \LogicException("Unexpected request $method $url");

            return $answer instanceof \Closure ? $answer() : $answer;
        }));
    }

    private function failure(\Closure $call): KsefvException
    {
        try {
            $call();
        } catch (KsefvException $failure) {
            return $failure;
        }
        self::fail('Expected a KsefvException.');
    }
}
