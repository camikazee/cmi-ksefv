<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Exception;

/**
 * A call to KSeFV that did not succeed, classified by what the caller may do next.
 *
 * - `Refused`: KSeFV refused the call; retrying it unchanged will not help
 *   (wrong key or scope, invalid order, conflicting data, feature off).
 * - `RetryLater`: KSeFV answered that it could not do it now (503 or 429, with Retry-After);
 *   retry reads later. For writes, use the endpoint's documented recovery rules.
 * - `Uncertain`: no usable answer (network, timeout, other 5xx, unreadable body); the operation
 *   may have taken effect. Submit/issue can replay the same identity and data, but send
 *   has no HTTP idempotency guarantee. Read its status; never create a replacement order.
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
        public readonly ?string $serverCode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function refused(string $message, int $httpStatus, ?string $serverCode = null): self
    {
        return new self(self::REFUSED, $message, $httpStatus, null, null, $serverCode);
    }

    public static function retryLater(string $message, int $httpStatus, ?int $retryAfterSeconds, ?string $serverCode = null): self
    {
        return new self(self::RETRY_LATER, $message, $httpStatus, $retryAfterSeconds, null, $serverCode);
    }

    public static function uncertain(string $message, ?int $httpStatus = null, ?\Throwable $previous = null, ?string $serverCode = null): self
    {
        return new self(self::UNCERTAIN, $message, $httpStatus, null, $previous, $serverCode);
    }

    public function isRefused(): bool
    {
        return self::REFUSED === $this->kind;
    }

    public function isUncertain(): bool
    {
        return self::UNCERTAIN === $this->kind;
    }

    public function isRetryLater(): bool
    {
        return self::RETRY_LATER === $this->kind;
    }
}
