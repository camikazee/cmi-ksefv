<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/** Use id (as a string) for paymentMethodCode, never ksefCode. */
final readonly class PaymentMethod
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $ksefCode,
        public bool $requiresBankAccount,
    ) {
    }
}
