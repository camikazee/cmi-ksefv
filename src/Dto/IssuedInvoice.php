<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/**
 * The invoice KSeFV issued for a received order: its own id and the number from its series.
 *
 * Issued means numbered, validated against FA(3) and frozen — not sent to KSeF. Sending is a
 * separate call (`KsefvClient::sendToKsef`).
 */
final readonly class IssuedInvoice
{
    public function __construct(
        public string $receiptId,
        public string $invoiceId,
        public string $number,
        public bool $replayed,
    ) {
    }
}
