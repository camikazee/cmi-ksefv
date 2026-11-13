<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/**
 * What KSeF said about an invoice sent through KSeFV (`GET /api/ksef/invoice/{id}/status`).
 *
 * Only `accepted` with a KSeF number means the Ministry's system took the invoice. `processing`
 * includes a duplicate under review (`requiresReview`). Reading the status also updates the
 * invoice in KSeFV, so it is the way to settle a send whose answer was lost.
 */
final readonly class KsefStatus
{
    public const string ACCEPTED = 'accepted';
    public const string REJECTED = 'rejected';
    public const string PROCESSING = 'processing';

    public function __construct(
        public string $outcome,
        public int $processingStatus,
        public ?string $description,
        public ?string $ksefNumber,
        public ?string $acquisitionDate,
        public bool $requiresReview,
    ) {
    }

    public function isAccepted(): bool
    {
        return self::ACCEPTED === $this->outcome && null !== $this->ksefNumber;
    }

    public function isRejected(): bool
    {
        return self::REJECTED === $this->outcome;
    }
}
