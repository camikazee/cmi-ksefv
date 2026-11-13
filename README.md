# cmi/ksefv

Klient API integracji **KSeFV** (nasz system faktur i KSeF): przekazanie zamówienia, wystawienie
z niego faktury, wysyłka do KSeF, status KSeF, PDF i UPO. Dla aplikacji, które mają własne
fakturowanie, a faktury dla firm chcą wystawiać i wysyłać do KSeF przez KSeFV.

Biblioteka jest bezstanowa: adres i klucz API firmy (`KsefvConnection`) idą z każdym wywołaniem,
więc jeden klient obsługuje wiele firm. Nie przechowuje sekretów, nie zna zamówień aplikacji.

Testy: `bin/test` lokalnie albo `docker build --target test -t cmi-ksefv-test . && docker run --rm cmi-ksefv-test`.

## Przebieg

```php
use Cmi\Ksefv\Decimal;
use Cmi\Ksefv\Dto\{InvoiceLine, OrderInvoice, PaymentFact};
use Cmi\Ksefv\{KsefvClient, KsefvConnection};

$ksefv = new KsefvClient($httpClient);                       // Symfony HttpClientInterface
$connection = new KsefvConnection('https://api.ksefv.pl', $decryptedApiKey);

$receipt = $ksefv->submitOrder($connection, new OrderInvoice(
    system: 'todoly',                                        // stały identyfikator systemu źródłowego
    orderId: 'TD-0190f1c2-…',                                // nasze trwałe id zamówienia
    buyerName: 'Firma Sp. z o.o.', buyerNip: '5260250274', buyerAddress: 'ul. Przykładowa 1',
    buyerZip: '00-001', buyerCity: 'Warszawa', buyerCountry: 'PL',
    issueDate: $today, saleDate: $today, dueDate: $today, paymentMethodCode: '7',
    lines: [new InvoiceLine('Plan Zespół, 1 miesiąc', '1', Decimal::fromMinor(1545), '23', 'usł')],
    payments: [new PaymentFact(Decimal::fromMinor(1900), $paidAt, 'PayU')],
));
$invoice = $ksefv->issue($connection, $receipt->id);        // numer z serii KSeFV, XML FA(3) zamrożony
$ksefv->sendToKsef($connection, $invoice->invoiceId);       // 202: przekazane workerowi, nie przyjęte
$status = $ksefv->ksefStatus($connection, $invoice->invoiceId); // odpytywanie: accepted|rejected|processing
$pdf = $ksefv->invoicePdf($connection, $invoice->invoiceId);
```

KSeFV nie ma webhooków: stan sprawdza się odpytywaniem (`receipt`, `invoice`, `ksefStatus`).

## Błędy i ponawianie

Każda porażka to `KsefvException` z rodzajem:

| Rodzaj | Kiedy | Co robić |
| --- | --- | --- |
| `refused` | 4xx: zły klucz/uprawnienie/plan (401/403), błędne dane (422), inne dane pod tym samym id (409), funkcja wyłączona albo brak zasobu (404), faktura już wysłana lub nieobsługiwana przez KSeF (400) | Nie ponawiać tego samego; poprawić dane/konfigurację albo przekazać człowiekowi. |
| `retry_later` | 429, 503 | Nic się nie stało; ponowić **to samo** wywołanie po `retryAfterSeconds` (domyślnie 3 s, bo KSeFV gubi `Retry-After` w odpowiedziach problem+json). |
| `uncertain` | brak odpowiedzi, timeout, inne 5xx, nieczytelna odpowiedź, przekierowanie | Mogło się wykonać. `submitOrder` i `issue` są idempotentne po stronie KSeFV — ponowić to samo. `sendToKsef` nie: najpierw `ksefStatus()`. |

Zamówienie identyfikuje para `system` + `orderId` na zawsze: te same dane → `replayed`, inne
dane → 409. Nie twórz nowego `orderId` po błędzie — ponawiaj ten sam.

## Uprawnienia klucza API (KSeFV)

`invoices.write` (przyjęcie), `invoices.read` (potwierdzenie, faktura), `invoices.issue`
(wystawienie — razem z `invoices.write`), `ksef.send`, `ksef.download` (status, UPO),
`invoices.download` (PDF). Klucz działa tylko w swojej firmie; wymaga planu z API.

## Ograniczenia profilu KSeFV (stan 2026-11-13)

Szczegóły: [`docs/ksefv-contract.md`](docs/ksefv-contract.md). Usterki i brakujące elementy po stronie
KSeFV — kolejka dla agenta w KSeFV: [`docs/ksefv-requests.md`](docs/ksefv-requests.md). Najważniejsze: tylko faktura
`regular` w PLN dla nabywcy z polskim NIP (bez B2C), stawki 23/8/5/0, bez rabatów i korekt;
`paymentMethodCode` to id ze słownika metod płatności firmy w KSeFV.
