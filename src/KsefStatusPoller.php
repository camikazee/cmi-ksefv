<?php

declare(strict_types=1);

namespace Cmi\Ksefv;

use Cmi\Ksefv\Dto\KsefPollResult;
use Cmi\Ksefv\Dto\KsefStatus;
use Cmi\Ksefv\Exception\KsefvException;

/** Bounded GET-only polling, preferably in a worker. Never invokes send/issue. */
final class KsefStatusPoller
{
    private readonly \Closure $clock;
    private readonly \Closure $sleep;

    /** Clock returns monotonic seconds; a custom sleeper must advance that clock. */
    public function __construct(private readonly KsefvClient $client, ?callable $clock = null, ?callable $sleep = null)
    {
        $this->clock = null === $clock ? static fn():float => hrtime(true) / 1_000_000_000 : \Closure::fromCallable($clock);
        $this->sleep = null === $sleep ? static function(float $seconds):void { usleep((int) ceil($seconds * 1_000_000)); } : \Closure::fromCallable($sleep);
    }

    /** Cancellation is checked between requests and <=1s sleep slices, not during blocking HTTP. */
    public function waitForResult(
        KsefvConnection $connection,
        string $invoiceId,
        float $timeoutSeconds = 30,
        float $intervalSeconds = 3,
        int $maxAttempts = 10,
        ?callable $isCancelled = null,
    ): KsefPollResult {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 300
            || !is_finite($intervalSeconds) || $intervalSeconds <= 0 || $maxAttempts < 1 || $maxAttempts > 1000) {
            throw new \InvalidArgumentException('Nieprawidłowy limit czasu, odstęp albo liczba odczytów.');
        }
        $deadline = $this->now() + $timeoutSeconds;
        $attempts = 0;
        $lastStatus = null;
        while (true) {
            if ($isCancelled && $isCancelled()) return new KsefPollResult('cancelled', $lastStatus, $attempts);
            $remaining = $deadline - $this->now();
            if ($remaining <= 0) return new KsefPollResult('timeout', $lastStatus, $attempts);
            if ($attempts >= $maxAttempts) return new KsefPollResult('attempt_limit', $lastStatus, $attempts);
            ++$attempts;
            try {
                $lastStatus = $this->client->ksefStatus($connection, $invoiceId, maxDurationSeconds:$remaining);
            } catch (KsefvException $failure) {
                if (!$failure->isRetryLater()) throw $failure;
                $retry = max(1, $failure->retryAfterSeconds ?? KsefvClient::DEFAULT_RETRY_AFTER);
                if ($attempts >= $maxAttempts) return new KsefPollResult('attempt_limit', $lastStatus, $attempts, $retry);
                if ($retry >= $deadline - $this->now()) return new KsefPollResult('retry_later', $lastStatus, $attempts, $retry);
                if (!$this->pause($retry, $deadline, $isCancelled)) return new KsefPollResult('cancelled', $lastStatus, $attempts);
                continue;
            }
            if ($lastStatus->requiresReview) return new KsefPollResult('requires_review', $lastStatus, $attempts);
            if ($lastStatus->isAccepted()) return new KsefPollResult('accepted', $lastStatus, $attempts);
            if (KsefStatus::ACCEPTED === $lastStatus->outcome) throw KsefvException::uncertain('Status accepted bez numeru KSeF wymaga wyjaśnienia.');
            if ($lastStatus->isRejected()) return new KsefPollResult('rejected', $lastStatus, $attempts);
            if ($lastStatus->isNotSubmitted()) return new KsefPollResult('not_submitted', $lastStatus, $attempts);
            if ($attempts >= $maxAttempts) return new KsefPollResult('attempt_limit', $lastStatus, $attempts);
            if (!$this->pause($intervalSeconds, $deadline, $isCancelled)) return new KsefPollResult('cancelled', $lastStatus, $attempts);
        }
    }

    private function pause(float $seconds, float $deadline, ?callable $isCancelled): bool
    {
        $now = $this->now();
        $wake = min($deadline, $now + $seconds);
        while ($now < $wake) {
            if ($isCancelled && $isCancelled()) return false;
            ($this->sleep)(min(1.0, $wake - $now));
            $after = $this->now();
            if ($after <= $now) throw new \LogicException('Monotonic clock did not advance during sleep.');
            $now = $after;
        }
        return !$isCancelled || !$isCancelled();
    }

    private function now(): float
    {
        $value = ($this->clock)();
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new \LogicException('Monotonic clock must return finite seconds.');
        }
        return (float) $value;
    }
}
