<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Tests;

use Cmi\Ksefv\Decimal;
use Cmi\Ksefv\Dto\InvoiceLine;
use Cmi\Ksefv\Dto\OrderInvoice;
use Cmi\Ksefv\KsefvConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The order contract of KSeFV, enforced before anything leaves the application. */
final class OrderInvoiceTest extends TestCase
{
    public function testTheBodyIsExactlyTheContractWithStringsOnly(): void
    {
        $body = Fixtures::order()->toJson();

        self::assertSame(
            '{"system":"todoly","orderId":"TD-0190f1c2","invoice":{"buyerName":"Firma Demonstracyjna Sp. z o.o.",'
            .'"buyerNip":"5260250274","buyerAddress":"ul. Przykładowa 1","buyerZip":"00-001","buyerCity":"Warszawa",'
            .'"buyerCountry":"PL","currency":"PLN","issueDate":"2026-11-13","saleDate":"2026-11-13","dueDate":"2026-11-13",'
            .'"paymentMethodCode":"7","items":[{"name":"Todoly — plan Zespół, 1 miesiąc","quantity":"1","netPrice":"15.45",'
            .'"vatRateCode":"23","unit":"usł"}],"payments":[{"amount":"19.00","date":"2026-11-13","method":"PayU"}]}}',
            $body,
        );
    }

    public function testDeclaredNoPaymentsIsAnEmptyListAndUndeclaredIsAbsent(): void
    {
        $none = new OrderInvoice('todoly', 'A1', 'Firma', [new InvoiceLine('X', '1', '1.00', '23')], payments: []);
        $absent = new OrderInvoice('todoly', 'A1', 'Firma', [new InvoiceLine('X', '1', '1.00', '23')]);

        self::assertStringContainsString('"payments":[]', $none->toJson());
        self::assertStringNotContainsString('payments', $absent->toJson());
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function brokenOrders(): iterable
    {
        $line = new InvoiceLine('X', '1', '1.00', '23');
        yield 'system with capitals' => [static fn () => new OrderInvoice('Todoly', 'A1', 'Firma', [$line])];
        yield 'order id starting with a dash' => [static fn () => new OrderInvoice('todoly', '-A1', 'Firma', [$line])];
        yield 'order id with a space' => [static fn () => new OrderInvoice('todoly', 'A 1', 'Firma', [$line])];
        yield 'no lines' => [static fn () => new OrderInvoice('todoly', 'A1', 'Firma', [])];
        yield 'blank buyer' => [static fn () => new OrderInvoice('todoly', 'A1', '  ', [$line])];
        yield 'control character' => [static fn () => new OrderInvoice('todoly', 'A1', "Firma\n", [$line])];
        yield 'float-like price with comma' => [static fn () => new InvoiceLine('X', '1', '1,00', '23')];
        yield 'negative quantity' => [static fn () => new InvoiceLine('X', '-1', '1.00', '23')];
        yield 'too many lines' => [static fn () => new OrderInvoice('todoly', 'A1', 'Firma', array_fill(0, 1001, $line))];
    }

    #[DataProvider('brokenOrders')]
    public function testABrokenOrderIsRefusedLocally(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }

    public function testMinorUnitsBecomeExactDecimalStrings(): void
    {
        self::assertSame('19.00', Decimal::fromMinor(1900));
        self::assertSame('0.05', Decimal::fromMinor(5));
        self::assertSame('182.40', Decimal::fromMinor(18240));
    }

    public function testTheKeyTravelsOnlyOverHttps(): void
    {
        self::assertSame('http://localhost:8080', (new KsefvConnection('http://localhost:8080/', Fixtures::KEY))->baseUrl);
        foreach (['http://api.ksefv.example', 'https://user:pass@api.ksefv.example', 'https://api.ksefv.example/?x=1', 'ftp://api.ksefv.example'] as $url) {
            try {
                new KsefvConnection($url, Fixtures::KEY);
                self::fail("Accepted $url");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new KsefvConnection('https://api.ksefv.example', "key\nwith-newline");
    }

    public function testDebugOutputHidesTheBuyer(): void
    {
        self::assertSame(['system' => 'todoly', 'orderId' => 'TD-0190f1c2', 'lines' => 1], Fixtures::order()->__debugInfo());
    }
}
