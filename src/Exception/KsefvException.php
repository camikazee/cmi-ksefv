<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Exception;

/**
 * A call to KSeFV that did not succeed, classified by what the caller may do next.
 *
 * - `Refused`: KSeFV answered and did nothing; retrying the same call will not help
 *   (wrong key or scope, invalid order, conflicting data, feature off).
 * - `RetryLater`: KSeFV answered that it could not do it now (503 or 429, with Retry-After);
 *   nothing was done, repeat the same call later.
 * - `Uncertain`: no usable answer (network, timeout, other 5xx, unreadable body); the operation
 *   may have taken effect. Every write of this client is idempotent on KSeFV's side, so the
 *   safe recovery is to repeat the very same call — never to build a new order.
 */
final class KsefvException extends \RuntimeException
{
    public const string REFUSED = 'refused';
    public const string RETRY_LATER = 'retry_later';
    public const string UNCERTAIN = 'uncertain';

    private function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function refused(string $message, int $httpStatus): self
    {
        return new self(self::REFUSED, $message, $httpStatus);
    }

    public static function retryLater(string $message, int $httpStatus, ?int $retryAfterSeconds): self
    {
        return new self(self::RETRY_LATER, $message, $httpStatus, $retryAfterSeconds);
    }

    public static function uncertain(string $message, ?int $httpStatus = null, ?\Throwable $previous = null): self
    {
        return new self(self::UNCERTAIN, $message, $httpStatus, null, $previous);
    }

    public function isRefused(): bool
    {
        return self::REFUSED === $this->kind;
    }

    public function isUncertain(): bool
    {
        return self::UNCERTAIN === $this->kind;
    }
}
