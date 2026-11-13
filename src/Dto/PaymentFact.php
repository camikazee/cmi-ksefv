<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

use Cmi\Ksefv\Decimal;

/**
 * A payment the source system declares (KSeFV records it as a declaration, not a bank proof).
 *
 * `method` is the KSeFV payment-method dictionary value the integration was configured with.
 */
final readonly class PaymentFact
{
    public function __construct(public string $amount, public \DateTimeImmutable $date, public string $method)
    {
        Decimal::assert($amount, 'payments.amount');
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'date' => $this->date->format('Y-m-d'), 'method' => $this->method];
    }
}
