<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/** queued is not acceptance by MF; an existing processing submission may need review. */
final readonly class KsefSendResult
{
    public function __construct(
        public string $outcome,
        public bool $alreadySubmitted,
        public bool $requiresReview,
        public ?string $submissionId = null,
        public bool $replayed = false,
    ) {
    }
}
