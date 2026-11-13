<?php

declare(strict_types=1);

namespace Cmi\Ksefv\Dto;

/**
 * An order handed to KSeFV to be invoiced: `system` + `orderId` identify it there for good.
 *
 * The shape mirrors KSeFV's order contract (`POST /api/integrations/orders`) and is checked here
 * before anything is sent, so a contract error is a local exception, not a round trip. KSeFV
 * remains the judge of the invoice itself (NIP, dates, rates, totals) when it is issued.
 *
 * The same system and order id must always carry the same data: KSeFV answers a different
 * payload under an existing id with a conflict, and an identical one with the existing receipt.
 */
final readonly class OrderInvoice
{
    public const int MAX_LINES = 1000;
    public const int MAX_PAYMENTS = 100;

    /**
     * @param list<InvoiceLine> $lines
     * @param list<PaymentFact>|null $payments null = not declared; [] = declared, none
     */
    public function __construct(
        public string $system,
        public string $orderId,
        public string $buyerName,
        public array $lines,
        public string $currency = 'PLN',
        public ?string $buyerNip = null,
        public ?string $buyerAddress = null,
        public ?string $buyerZip = null,
        public ?string $buyerCity = null,
        public ?string $buyerCountry = null,
        public ?\DateTimeImmutable $issueDate = null,
        public ?\DateTimeImmutable $saleDate = null,
        public ?\DateTimeImmutable $dueDate = null,
        public ?string $paymentMethodCode = null,
        public ?string $bankAccount = null,
        public ?string $bankName = null,
        public ?string $receiverEmail = null,
        public ?array $payments = null,
    ) {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $system)) {
            throw new \InvalidArgumentException('system: 1–64 znaki a-z, 0-9, „_”, „-”, zaczynając od litery lub cyfry.');
        }
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $orderId)) {
            throw new \InvalidArgumentException('orderId: 1–128 znaków ASCII, pierwszy alfanumeryczny, dalej także . _ : / -.');
        }
        if (!array_is_list($lines) || [] === $lines || count($lines) > self::MAX_LINES) {
            throw new \InvalidArgumentException(sprintf('Zamówienie musi mieć od 1 do %d pozycji.', self::MAX_LINES));
        }
        foreach ($lines as $line) {
            if (!$line instanceof InvoiceLine) {
                throw new \InvalidArgumentException('Pozycje muszą być typu InvoiceLine.');
            }
        }
        if (null !== $payments) {
            if (!array_is_list($payments) || count($payments) > self::MAX_PAYMENTS) {
                throw new \InvalidArgumentException(sprintf('Najwyżej %d wpłat.', self::MAX_PAYMENTS));
            }
            foreach ($payments as $payment) {
                if (!$payment instanceof PaymentFact) {
                    throw new \InvalidArgumentException('Wpłaty muszą być typu PaymentFact.');
                }
            }
        }
        foreach ($this->texts() as $field => $value) {
            if (null !== $value && ('' === trim($value) || strlen($value) > 4096 || 1 === preg_match('/[\x00-\x1F\x7F]/', $value))) {
                throw new \InvalidArgumentException(sprintf('Pole %s jest puste, za długie albo zawiera znaki sterujące.', $field));
            }
        }
        foreach ($lines as $index => $line) {
            foreach ($line->toArray() as $field => $value) {
                if ('' === trim($value) || strlen($value) > 4096 || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
                    throw new \InvalidArgumentException(sprintf('Pozycja %d: pole %s jest puste, za długie albo zawiera znaki sterujące.', $index + 1, $field));
                }
            }
        }
    }

    /** The exact request body: strings only, absent facts left out rather than guessed. */
    public function toJson(): string
    {
        $invoice = array_filter($this->texts(), static fn (?string $value): bool => null !== $value);
        $invoice['items'] = array_map(static fn (InvoiceLine $line): array => $line->toArray(), $this->lines);
        if (null !== $this->payments) {
            $invoice['payments'] = array_map(static fn (PaymentFact $payment): array => $payment->toArray(), $this->payments);
        }

        $json = json_encode(
            ['system' => $this->system, 'orderId' => $this->orderId, 'invoice' => $invoice],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (strlen($json) > 262144) {
            throw new \InvalidArgumentException('Zamówienie przekracza limit 256 KiB.');
        }

        return $json;
    }

    /** @return array<string, ?string> */
    private function texts(): array
    {
        return [
            'buyerName' => $this->buyerName,
            'buyerNip' => $this->buyerNip,
            'buyerAddress' => $this->buyerAddress,
            'buyerZip' => $this->buyerZip,
            'buyerCity' => $this->buyerCity,
            'buyerCountry' => $this->buyerCountry,
            'currency' => $this->currency,
            'issueDate' => $this->issueDate?->format('Y-m-d'),
            'saleDate' => $this->saleDate?->format('Y-m-d'),
            'dueDate' => $this->dueDate?->format('Y-m-d'),
            'paymentMethodCode' => $this->paymentMethodCode,
            'bankAccountSnapshot' => $this->bankAccount,
            'bankNameSnapshot' => $this->bankName,
            'receiverEmailSnapshot' => $this->receiverEmail,
        ];
    }

    /** Never dump the buyer's data into logs or error reports. */
    public function __debugInfo(): array
    {
        return ['system' => $this->system, 'orderId' => $this->orderId, 'lines' => count($this->lines)];
    }
}
