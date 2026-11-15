<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Tests;

use Cmi\Ksefv\Exception\KsefvException;
use Cmi\Ksefv\KsefvClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientLoggingTest extends TestCase
{
    private array $sent = [];

    public function testParsedSuccessHasCorrelatedRequestAndOperationEventsWithoutPrivateData(): void
    {
        $logger = new RecordingClientLogger();
        $client = $this->client($logger, new MockResponse(json_encode([
            'id'=>Fixtures::RECEIPT,'system'=>'todoly','orderId'=>'private-order@example.test',
            'payloadSha256'=>str_repeat('a',64),'state'=>'received','invoiceId'=>null,'createdAt'=>'2026-11-14T12:00:00Z','replayed'=>false,
        ], JSON_THROW_ON_ERROR), ['http_code'=>201,'response_headers'=>['X-Request-ID: '.str_repeat('b',32)]]));
        $receipt = $client->submitOrder(Fixtures::connection(), Fixtures::order());
        self::assertSame('received', $receipt->state); self::assertCount(1, $this->sent);
        self::assertSame(['ksefv.operation.started','ksefv.http.request_started','ksefv.operation.completed'], array_column($logger->records,'message'));
        $context = $logger->records[2]['context'];
        self::assertSame('orders.submit', $context['operation']); self::assertSame(201, $context['httpStatus']);
        self::assertSame('received', $context['outcome']); self::assertFalse($context['replayed']);
        self::assertSame(str_repeat('b',32), $context['serverRequestId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $context['requestId']);
        self::assertContains('X-Request-ID: '.$context['requestId'], $this->sent[0]['options']['headers']);
        self::assertGreaterThanOrEqual(0, $context['durationMs']);
        $this->assertNoPrivateData($logger);
    }

    public function testQueueAndExistingSubmissionDoNotClaimNewMfAcceptance(): void
    {
        $logger = new RecordingClientLogger();
        $client = $this->client($logger, new MockResponse('{"message":"private response"}', ['http_code'=>202]),
            new MockResponse('{"code":"already_submitted","outcome":"processing","requiresReview":true}', ['http_code'=>400]));
        $client->sendToKsef(Fixtures::connection(), Fixtures::INVOICE);
        $client->sendToKsef(Fixtures::connection(), Fixtures::INVOICE);
        $completed = $logger->events('ksefv.operation.completed');
        self::assertSame(['queued','processing'], array_column(array_column($completed,'context'),'outcome'));
        self::assertFalse($completed[0]['context']['alreadySubmitted']); self::assertTrue($completed[1]['context']['alreadySubmitted']);
        self::assertSame(400, $completed[1]['context']['httpStatus']); self::assertCount(2, $this->sent);
        $this->assertNoPrivateData($logger);
    }

    public function testRefusalAndRetryLaterHaveSafeCodesNotRawErrorMessages(): void
    {
        $logger = new RecordingClientLogger();
        $client = $this->client($logger, new MockResponse(json_encode(['code'=>'unsupported','error'=>Fixtures::KEY.' private-order@example.test'],JSON_THROW_ON_ERROR), ['http_code'=>400]),
            new MockResponse('{"code":"ksef_status_unavailable","error":"private response"}', ['http_code'=>503,'response_headers'=>['Retry-After: 7']]));
        foreach ([1,2] as $_) {
            try { $client->ksefStatus(Fixtures::connection(), Fixtures::INVOICE); self::fail('Expected API failure.'); }
            catch (KsefvException) {}
        }
        $errors = $logger->events('ksefv.operation.failed');
        self::assertSame(['unsupported','ksef_status_unavailable'], array_column(array_column($errors,'context'),'serverCode'));
        self::assertSame(['refused','retry_later'], array_column(array_column($errors,'context'),'failureKind'));
        self::assertSame(7, $errors[1]['context']['retryAfterSeconds']);
        $this->assertNoPrivateData($logger);
    }

    public function testMalformedSuccessIsNotLoggedAsCompleted(): void
    {
        $logger = new RecordingClientLogger();
        $client = $this->client($logger, new MockResponse('{}', ['http_code'=>201]));
        try { $client->issue(Fixtures::connection(), Fixtures::RECEIPT); self::fail('Expected invalid answer.'); }
        catch (KsefvException $error) { self::assertTrue($error->isUncertain()); }
        self::assertSame([], $logger->events('ksefv.operation.completed'));
        self::assertCount(1, $logger->events('ksefv.operation.failed'));
    }

    public function testThrowingLoggerCannotChangeTheWriteResultOrSendTwice(): void
    {
        $client = $this->client(new RecordingClientLogger(true), new MockResponse('{"message":"queued"}', ['http_code'=>202]));
        self::assertSame('queued', $client->sendToKsef(Fixtures::connection(), Fixtures::INVOICE)->outcome);
        self::assertCount(1, $this->sent);
    }

    public function testTransportFailureLogsOneUncertainAttemptWithoutExceptionText(): void
    {
        $logger = new RecordingClientLogger();
        $client = $this->client($logger, static fn()=>throw new TransportException('private response '.Fixtures::KEY));
        try { $client->ksefStatus(Fixtures::connection(), Fixtures::INVOICE); self::fail('Expected timeout.'); }
        catch (KsefvException) {}
        $errors = $logger->events('ksefv.operation.failed');
        self::assertCount(1, $errors); self::assertSame('uncertain', $errors[0]['context']['failureKind']);
        self::assertNull($errors[0]['context']['httpStatus']); self::assertCount(1, $this->sent);
        $this->assertNoPrivateData($logger);
    }

    public function testSequentialCallsHaveIndependentIdsAndNoStaleTarget(): void
    {
        $logger = new RecordingClientLogger();
        $client = $this->client($logger, new MockResponse('{"outcome":"processing","processingStatus":100}'), new MockResponse('{"items":[]}'));
        $client->ksefStatus(Fixtures::connection(), Fixtures::INVOICE); $client->paymentMethods(Fixtures::connection());
        $completed = $logger->events('ksefv.operation.completed');
        self::assertNotSame($completed[0]['context']['requestId'], $completed[1]['context']['requestId']);
        self::assertSame(Fixtures::INVOICE, $completed[0]['context']['targetId']); self::assertNull($completed[1]['context']['targetId']);
        self::assertSame('payment_methods.read', $completed[1]['context']['operation']);
    }

    public function testUnknownServerCodeAndInvalidResponseIdDoNotBecomeLogPayload(): void
    {
        $logger = new RecordingClientLogger();
        $client = $this->client($logger, new MockResponse('{"code":"private_lowercase_secret"}', ['http_code'=>400,'response_headers'=>['X-Request-ID: private-header-value']]));
        try { $client->ksefStatus(Fixtures::connection(), Fixtures::INVOICE); self::fail('Expected refusal.'); }
        catch (KsefvException $error) { self::assertSame('private_lowercase_secret', $error->serverCode); }
        $context = $logger->events('ksefv.operation.failed')[0]['context'];
        self::assertSame('other', $context['serverCode']); self::assertNull($context['serverRequestId']);
        self::assertStringNotContainsString('private_', json_encode($logger->records,JSON_THROW_ON_ERROR));
    }

    private function client(RecordingClientLogger $logger, MockResponse|\Closure ...$answers): KsefvClient
    {
        return new KsefvClient(new MockHttpClient(function(string $method,string $url,array $options)use(&$answers):MockResponse{
            $this->sent[]=compact('method','url','options'); $answer=array_shift($answers)??throw new \LogicException('Unexpected request');
            return $answer instanceof \Closure?$answer():$answer;
        }), logger:$logger);
    }

    private function assertNoPrivateData(RecordingClientLogger $logger): void
    {
        $serialized=json_encode($logger->records,JSON_THROW_ON_ERROR);
        foreach ([Fixtures::KEY,'5260250274','Firma Demonstracyjna','private-order@example.test','private response','X-API-Key','Authorization'] as $value) self::assertStringNotContainsString($value,$serialized);
    }
}

final class RecordingClientLogger extends AbstractLogger
{
    public array $records=[];
    public function __construct(private readonly bool $throws=false) {}
    public function log($level, string|\Stringable $message, array $context=[]):void
    {
        if($this->throws) throw new \RuntimeException('logger unavailable');
        $this->records[]=compact('level','message','context');
    }
    public function events(string $message):array { return array_values(array_filter($this->records,fn($row)=>$row['message']===$message)); }
}
