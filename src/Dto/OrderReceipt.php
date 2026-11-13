<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/**
 * KSeFV's acknowledgement that it holds the order (`received`) or has invoiced it (`issued`).
 *
 * `received` is not an invoice: nothing is numbered, sent to KSeF or paid yet. `replayed` says the
 * same order with the same data was already there — the normal outcome of a safe retry.
 */
final readonly class OrderReceipt
{
    public function __construct(
        public string $id,
        public string $system,
        public string $orderId,
        public string $payloadSha256,
        public string $state,
        public ?string $invoiceId,
        public \DateTimeImmutable $createdAt,
        public bool $replayed,
    ) {
    }

    public function isIssued(): bool
    {
        return 'issued' === $this->state && null !== $this->invoiceId;
    }
}
