<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/** The invoice as KSeFV keeps it (`GET /api/invoices/{id}`): number, state, totals, KSeF number. */
final readonly class InvoiceSummary
{
    public function __construct(
        public string $id,
        public ?string $number,
        public string $status,
        public string $currency,
        public string $totalNet,
        public string $totalVat,
        public string $totalGross,
        public ?string $ksefNumber,
    ) {
    }
}
