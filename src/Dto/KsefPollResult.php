<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/** Why polling stopped; status is the last successful observation, not necessarily current/final. */
final readonly class KsefPollResult
{
    public function __construct(
        public string $reason,
        public ?KsefStatus $status,
        public int $attempts,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public function isAccepted(): bool
    {
        return 'accepted' === $this->reason && true === $this->status?->isAccepted();
    }
}
