<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Tests;

use Cmi\Ksefv\KsefStatusPoller;
use Cmi\Ksefv\KsefvClient;
use Cmi\Ksefv\Exception\KsefvException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KsefStatusPollerTest extends TestCase
{
    private array $sent = [];
    private PollingClock $clock;

    public function testProcessingThenAcceptedUsesOnlyGetAndRemainingHttpBudget(): void
    {
        $poller = $this->poller([$this->statusResponse('processing'), $this->statusResponse('accepted')]);
        $result = $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE, timeoutSeconds:10);
        self::assertSame('accepted', $result->reason); self::assertTrue($result->isAccepted());
        self::assertSame(2, $result->attempts); self::assertSame(3.0, $this->clock->time);
        self::assertSame([10.0, 7.0], array_column(array_column($this->sent, 'options'), 'max_duration'));
        foreach ($this->sent as $request) {
            self::assertSame('GET', $request['method']);
            self::assertSame('https://api.ksefv.example/api/ksef/invoice/'.Fixtures::INVOICE.'/status', $request['url']);
            self::assertContains('X-API-Key: '.Fixtures::KEY, $request['options']['headers']);
        }
    }

    public static function stopStates(): iterable
    {
        yield ['rejected', false, 'rejected'];
        yield ['not_submitted', false, 'not_submitted'];
        yield ['processing', true, 'requires_review'];
    }

    #[DataProvider('stopStates')]
    public function testStatesRequiringNoFurtherPollingStopImmediately(string $outcome, bool $review, string $reason): void
    {
        $result = $this->poller([$this->statusResponse($outcome, $review)])->waitForResult(Fixtures::connection(), Fixtures::INVOICE);
        self::assertSame($reason, $result->reason); self::assertFalse($result->isAccepted());
        self::assertSame(1, $result->attempts); self::assertSame([], $this->clock->sleeps);
    }

    public function testAttemptLimitStopsWithoutAnotherRequestOrSleep(): void
    {
        $result = $this->poller([$this->statusResponse('processing'), $this->statusResponse('processing')])
            ->waitForResult(Fixtures::connection(), Fixtures::INVOICE, maxAttempts:2);
        self::assertSame('attempt_limit', $result->reason); self::assertSame(2, $result->attempts);
        self::assertCount(2, $this->sent); self::assertSame(3.0, $this->clock->time);
    }

    public function testDeadlineCapsSleepAndDoesNotPerformARequestAfterExpiry(): void
    {
        $result = $this->poller([$this->statusResponse('processing')])->waitForResult(Fixtures::connection(), Fixtures::INVOICE, timeoutSeconds:2);
        self::assertSame('timeout', $result->reason); self::assertSame(2.0, $this->clock->time); self::assertCount(1, $this->sent);
    }

    public function testCancellationBeforeFirstRequestHasNoNetworkEffect(): void
    {
        $result = $this->poller([])->waitForResult(Fixtures::connection(), Fixtures::INVOICE, isCancelled:static fn()=>true);
        self::assertSame('cancelled', $result->reason); self::assertSame(0, $result->attempts); self::assertSame([], $this->sent);
    }

    public function testCancellationIsCheckedBetweenSleepSlices(): void
    {
        $poller = $this->poller([$this->statusResponse('processing')]);
        $result = $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE, isCancelled:fn()=>$this->clock->time >= 1);
        self::assertSame('cancelled', $result->reason); self::assertSame(1.0, $this->clock->time); self::assertCount(1, $this->sent);
    }

    public function testRetryAfterIsHonouredBeforeRetryingARead(): void
    {
        $poller = $this->poller([new MockResponse('{}', ['http_code'=>503, 'response_headers'=>['Retry-After'=>'2']]), $this->statusResponse('accepted')]);
        $result = $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE, timeoutSeconds:5);
        self::assertTrue($result->isAccepted()); self::assertSame(2.0, $this->clock->time);
        self::assertSame([5.0, 3.0], array_column(array_column($this->sent, 'options'), 'max_duration'));
    }

    public function testRetryAfterOutsideBudgetReturnsHintWithoutRetryingEarly(): void
    {
        $result = $this->poller([new MockResponse('{}', ['http_code'=>429, 'response_headers'=>['Retry-After'=>'10']])])
            ->waitForResult(Fixtures::connection(), Fixtures::INVOICE, timeoutSeconds:5);
        self::assertSame('retry_later', $result->reason); self::assertSame(10, $result->retryAfterSeconds);
        self::assertSame(0.0, $this->clock->time); self::assertCount(1, $this->sent);
    }

    public function testRefusalAndMalformedResponsesAreNotRetried(): void
    {
        foreach ([new MockResponse('{"code":"unsupported"}', ['http_code'=>400]), new MockResponse('{}')] as $answer) {
            $this->sent = [];
            try { $this->poller([$answer])->waitForResult(Fixtures::connection(), Fixtures::INVOICE); self::fail('Expected API failure.'); }
            catch (KsefvException $error) { self::assertFalse($error->isRetryLater()); self::assertCount(1, $this->sent); self::assertSame([], $this->clock->sleeps); }
        }
    }

    public function testTransportFailureIsPropagatedWithoutResendingAnything(): void
    {
        $poller = $this->poller([static fn()=>throw new TransportException('fixture timeout')]);
        try { $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE); self::fail('Expected transport failure.'); }
        catch (KsefvException $error) { self::assertTrue($error->isUncertain()); self::assertCount(1, $this->sent); }
    }

    public static function invalidOptions(): iterable
    {
        yield [0.0, 3.0, 10]; yield [301.0, 3.0, 10]; yield [INF, 3.0, 10]; yield [NAN, 3.0, 10];
        yield [30.0, 0.0, 10]; yield [30.0, NAN, 10]; yield [30.0, 3.0, 0]; yield [30.0, 3.0, 1001];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidLimitsAreRefusedBeforeIo(float $timeout, float $interval, int $attempts): void
    {
        $poller = $this->poller([]);
        try { $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE, $timeout, $interval, $attempts); self::fail('Invalid limits accepted.'); }
        catch (\InvalidArgumentException) { self::assertSame([], $this->sent); }
    }

    public function testBrokenInjectedClockCannotSpinForever(): void
    {
        $client = new KsefvClient(new MockHttpClient($this->statusResponse('processing')));
        $poller = new KsefStatusPoller($client, static fn()=>0.0, static fn(float $seconds)=>null);
        $this->expectException(\LogicException::class);
        $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE);
    }

    public function testHttpLatencyConsumesTheOverallBudget(): void
    {
        $poller = $this->poller([function() { $this->clock->time += 2.0; return $this->statusResponse('processing'); }, $this->statusResponse('accepted')]);
        $result = $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE, timeoutSeconds:4, intervalSeconds:1);
        self::assertTrue($result->isAccepted());
        self::assertSame([4.0, 1.0], array_column(array_column($this->sent, 'options'), 'max_duration'));
    }

    public function testSlowReadCannotCauseAnotherRequestAfterTheDeadline(): void
    {
        $poller = $this->poller([function() { $this->clock->time += 4.5; return $this->statusResponse('processing'); }]);
        $result = $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE, timeoutSeconds:4);
        self::assertSame('timeout', $result->reason); self::assertCount(1, $this->sent); self::assertSame([], $this->clock->sleeps);
    }

    public function testAcceptedWithoutAMinistryNumberCannotFinishAsSuccess(): void
    {
        $poller = $this->poller([new MockResponse('{"outcome":"accepted","processingStatus":200,"ksefNumber":null}')]);
        $this->expectException(KsefvException::class);
        $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE);
    }

    public function testInvalidClockStopsBeforeIo(): void
    {
        $client = new KsefvClient(new MockHttpClient(static fn()=>throw new \LogicException('Network must not be reached')));
        $poller = new KsefStatusPoller($client, static fn()=>INF);
        $this->expectException(\LogicException::class); $this->expectExceptionMessage('finite seconds');
        $poller->waitForResult(Fixtures::connection(), Fixtures::INVOICE);
    }

    public function testAttemptLimitAlsoAppliesToRetryableFailures(): void
    {
        $result = $this->poller([new MockResponse('{}', ['http_code'=>503, 'response_headers'=>['Retry-After'=>'2']])])
            ->waitForResult(Fixtures::connection(), Fixtures::INVOICE, maxAttempts:1);
        self::assertSame('attempt_limit', $result->reason); self::assertSame(2, $result->retryAfterSeconds);
        self::assertCount(1, $this->sent); self::assertSame([], $this->clock->sleeps);
    }

    public function testLegacyExceptionFactoriesKeepThePreviousCause(): void
    {
        $cause = new TransportException('fixture timeout');
        $error = KsefvException::uncertain('fixture', null, $cause);
        self::assertSame($cause, $error->getPrevious()); self::assertNull($error->serverCode);
        self::assertFalse($error->isRetryLater());
    }

    private function poller(array $answers): KsefStatusPoller
    {
        $this->clock = new PollingClock();
        $http = new MockHttpClient(function(string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->sent[] = compact('method', 'url', 'options');
            $answer = array_shift($answers) ?? throw new \LogicException('Unexpected request');
            return $answer instanceof \Closure ? $answer() : $answer;
        });
        return new KsefStatusPoller(new KsefvClient($http), $this->clock->now(...), $this->clock->sleep(...));
    }

    private function statusResponse(string $outcome, bool $review=false): MockResponse
    {
        return new MockResponse(json_encode(['outcome'=>$outcome, 'processingStatus'=>match($outcome) {'accepted'=>200,'rejected'=>422,'not_submitted'=>null,default=>100},
            'ksefNumber'=>$outcome==='accepted'?'fictional-ksef-number':null, 'requiresReview'=>$review], JSON_THROW_ON_ERROR));
    }
}

final class PollingClock
{
    public float $time = 0.0;
    public array $sleeps = [];
    public function now(): float { return $this->time; }
    public function sleep(float $seconds): void { $this->sleeps[] = $seconds; $this->time += $seconds; }
}
