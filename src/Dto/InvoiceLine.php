<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

use Cmi\Ksefv\Decimal;

/**
 * One position of the invoice, as KSeFV's order contract names it.
 *
 * Quantities and prices are decimal strings, never floats; build them from minor units with
 * `Decimal::fromMinor()`. `vatRateCode` is KSeFV's rate code (`23`, `8`, `5`, `0` in profile v1).
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $name,
        public string $quantity,
        public string $netPrice,
        public string $vatRateCode,
        public string $unit = 'szt',
        public ?string $pkwiu = null,
        public ?string $gtuCode = null,
    ) {
        Decimal::assert($quantity, 'quantity');
        Decimal::assert($netPrice, 'netPrice');
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'quantity' => $this->quantity,
            'netPrice' => $this->netPrice,
            'vatRateCode' => $this->vatRateCode,
            'unit' => $this->unit,
            'pkwiu' => $this->pkwiu,
            'gtuCode' => $this->gtuCode,
        ], static fn (?string $value): bool => null !== $value);
    }
}
