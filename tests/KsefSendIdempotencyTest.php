<?php
declare(strict_types=1);
namespace Cmi\Ksefv\Tests;

use Cmi\Ksefv\KsefvClient;
use Cmi\Ksefv\Exception\KsefvException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KsefSendIdempotencyTest extends TestCase
{
    private const KEY='fictional-idempotency-001';
    public function testReadinessReportsSupportBeforeFirstWriteAndDefaultsOldServerToFalse(): void
    {
        $body=['company'=>['nip'=>'5260250274','name'=>'Fixture'],'scopes'=>['ksef.send'],'apiPlanActive'=>true,'integrationOrders'=>['enabled'=>true,'issueEnabled'=>true]];
        $client=new KsefvClient(new MockHttpClient([
            new MockResponse(json_encode($body+['capabilities'=>['ksefSendIdempotency'=>true]])),
            new MockResponse(json_encode($body)),
        ]));
        self::assertTrue($client->testConnection(Fixtures::connection())->ksefSendIdempotency);
        self::assertFalse($client->testConnection(Fixtures::connection())->ksefSendIdempotency);
    }
    public function testExplicitSameKeyCanBeReusedAndReturnsTheSameSubmission(): void
    {
        $calls=0;
        $http=new MockHttpClient(function(string $method,string $url,array $options)use(&$calls):MockResponse {
            self::assertContains('Idempotency-Key: '.self::KEY,$options['headers']); ++$calls;
            return new MockResponse(json_encode(['idempotent'=>true,'outcome'=>'queued','submissionId'=>Fixtures::RECEIPT]),
                ['http_code'=>202,'response_headers'=>['Idempotency-Replayed: '.($calls===1?'false':'true')]]);
        });
        $client=new KsefvClient($http);
        $first=$client->sendToKsef(Fixtures::connection(),Fixtures::INVOICE,idempotencyKey:self::KEY);
        $second=$client->sendToKsef(Fixtures::connection(),Fixtures::INVOICE,idempotencyKey:self::KEY);
        self::assertSame(Fixtures::RECEIPT,$first->submissionId); self::assertSame($first->submissionId,$second->submissionId);
        self::assertFalse($first->replayed); self::assertTrue($second->replayed); self::assertSame(2,$calls);
    }
    public function testOldServerIgnoringTheKeyIsNotReportedAsDurableSuccess(): void
    {
        $client=new KsefvClient(new MockHttpClient(new MockResponse('{"message":"queued"}',['http_code'=>202])));
        try { $client->sendToKsef(Fixtures::connection(),Fixtures::INVOICE,idempotencyKey:self::KEY); self::fail('Old server is uncertain'); }
        catch(KsefvException $e) { self::assertTrue($e->isUncertain()); }
    }
    public function testMalformedKeyFailsBeforeHttp(): void
    {
        $client=new KsefvClient(new MockHttpClient(static fn()=>throw new \LogicException('Unexpected HTTP')));
        $this->expectException(\InvalidArgumentException::class);
        $client->sendToKsef(Fixtures::connection(),Fixtures::INVOICE,idempotencyKey:'PRIVATE?bad');
    }
    public function testMalformedSubmissionConfirmationIsUncertain(): void
    {
        $client=new KsefvClient(new MockHttpClient(new MockResponse('{"idempotent":true,"outcome":"queued","submissionId":"PRIVATE"}',['http_code'=>202])));
        try { $client->sendToKsef(Fixtures::connection(),Fixtures::INVOICE,idempotencyKey:self::KEY); self::fail('Malformed confirmation'); }
        catch(KsefvException $e) { self::assertTrue($e->isUncertain()); self::assertStringNotContainsString('PRIVATE',$e->getMessage()); }
    }
    public function testLoggerNeverIncludesTheIdempotencyKey(): void
    {
        $logger=new class extends \Psr\Log\AbstractLogger {
            public array $records=[];
            public function log($level,string|\Stringable $message,array $context=[]):void { $this->records[]=[$message,$context]; }
        };
        $client=new KsefvClient(new MockHttpClient(new MockResponse(json_encode(['idempotent'=>true,'outcome'=>'queued','submissionId'=>Fixtures::RECEIPT]),['http_code'=>202])),logger:$logger);
        $result=$client->sendToKsef(Fixtures::connection(),Fixtures::INVOICE,idempotencyKey:self::KEY);
        self::assertSame('queued',$result->outcome); self::assertStringNotContainsString(self::KEY,json_encode($logger->records));
    }
    public function testConflictIsRefusedWithoutAutomaticRetry(): void
    {
        $calls=0;
        $client=new KsefvClient(new MockHttpClient(function()use(&$calls):MockResponse { ++$calls; return new MockResponse('{"error":"Conflict"}',['http_code'=>409]); }));
        try { $client->sendToKsef(Fixtures::connection(),Fixtures::INVOICE,idempotencyKey:self::KEY); self::fail('Conflict'); }
        catch(KsefvException $e) { self::assertSame('refused',$e->kind); self::assertSame(1,$calls); }
    }
}
