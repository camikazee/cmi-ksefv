<?php

declare(strict_types=1);

namespace Cmi\Ksefv;

/** Decimal strings for KSeFV: amounts travel as text so no float ever rounds them. */
final class Decimal
{
    /** 12345 grosze → "123.45"; negative amounts are not part of the order contract. */
    public static function fromMinor(int $minor): string
    {
        if ($minor < 0) {
            throw new \InvalidArgumentException('Kwota nie może być ujemna.');
        }

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    public static function assert(string $value, string $field): void
    {
        if (1 !== preg_match('/^\d{1,15}(\.\d{1,6})?$/D', $value)) {
            throw new \InvalidArgumentException(sprintf('Pole %s musi być liczbą dziesiętną z kropką, np. "19.00".', $field));
        }
    }
}
