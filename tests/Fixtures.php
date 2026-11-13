<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Tests;

use Cmi\Ksefv\Dto\InvoiceLine;
use Cmi\Ksefv\Dto\OrderInvoice;
use Cmi\Ksefv\Dto\PaymentFact;
use Cmi\Ksefv\KsefvConnection;

final class Fixtures
{
    public const string KEY = 'ksv_live_abcdefghijkl0123456789012345678901234567890123456789';
    public const string RECEIPT = '0190f1c2-3b4a-7d5e-8f60-123456789abc';
    public const string INVOICE = '0190f1c2-3b4a-7d5e-8f60-cba987654321';

    public static function connection(): KsefvConnection
    {
        return new KsefvConnection('https://api.ksefv.example/', self::KEY);
    }

    public static function order(): OrderInvoice
    {
        return new OrderInvoice(
            system: 'todoly',
            orderId: 'TD-0190f1c2',
            buyerName: 'Firma Demonstracyjna Sp. z o.o.',
            lines: [new InvoiceLine('Todoly — plan Zespół, 1 miesiąc', '1', '15.45', '23', 'usł')],
            buyerNip: '5260250274',
            buyerAddress: 'ul. Przykładowa 1',
            buyerZip: '00-001',
            buyerCity: 'Warszawa',
            buyerCountry: 'PL',
            issueDate: new \DateTimeImmutable('2026-11-13'),
            saleDate: new \DateTimeImmutable('2026-11-13'),
            dueDate: new \DateTimeImmutable('2026-11-13'),
            paymentMethodCode: '7',
            payments: [new PaymentFact('19.00', new \DateTimeImmutable('2026-11-13'), 'PayU')],
        );
    }
}
