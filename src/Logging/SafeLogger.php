<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Logging;

use Psr\Log\LoggerInterface;

/** Logging must never turn an already completed business operation into a write retry. */
final class SafeLogger
{
    public function __construct(private readonly ?LoggerInterface $logger) {}

    public function log(string $level, string $event, array $context): void
    {
        try { $this->logger?->log($level, $event, $context); }
        catch (\Throwable) { /* The application must monitor its logger; never change the API result. */ }
    }
}
