<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Tests;

use Cmi\Ksefv\Exception\KsefvException;
use Cmi\Ksefv\KsefvClient;
use Cmi\Ksefv\KsefvConnection;
use Cmi\Ksefv\KsefStatusPoller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class KsefPollingTransportTest extends TestCase
{
    public function testDrippingLoopbackResponseCannotKeepPollingAliveBeyondTheHttpBudget(): void
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/fixtures/dripping-status-server.php'],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            stream_set_timeout($pipes[1], 3);
            $port = trim((string) fgets($pipes[1]));
            self::assertMatchesRegularExpression('/^[0-9]{1,5}$/D', $port, 'Loopback fixture did not start.');
            $client = new KsefvClient(new NativeHttpClient());
            $poller = new KsefStatusPoller($client);
            $started = hrtime(true);
            try {
                $poller->waitForResult(new KsefvConnection('http://127.0.0.1:'.$port, Fixtures::KEY), Fixtures::INVOICE, timeoutSeconds:0.25);
                self::fail('Slow HTTP response was accepted without hitting the request budget.');
            } catch (KsefvException $error) {
                self::assertTrue($error->isUncertain());
                self::assertInstanceOf(TransportExceptionInterface::class, $error->getPrevious());
                // Server drips data every50ms for4s: an inactivity timeout alone is insufficient.
                self::assertLessThan(2.5, (hrtime(true) - $started) / 1_000_000_000);
            }
        } finally {
            proc_terminate($process);
            foreach ([1,2] as $pipe) if (is_resource($pipes[$pipe])) fclose($pipes[$pipe]);
            proc_close($process);
        }
    }
}
