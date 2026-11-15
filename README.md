# cmi/ksefv

Klient API integracji **KSeFV** (nasz system faktur i KSeF): przekazanie zamówienia, wystawienie
z niego faktury, wysyłka do KSeF, status KSeF, PDF i UPO. Dla aplikacji, które mają własne
fakturowanie, a faktury dla firm chcą wystawiać i wysyłać do KSeF przez KSeFV.

Biblioteka jest bezstanowa: adres i klucz API firmy (`KsefvConnection`) idą z każdym wywołaniem,
więc jeden klient obsługuje wiele firm. Nie przechowuje sekretów, nie zna zamówień aplikacji.

Testy: `bin/test` lokalnie albo `docker build --target test -t cmi-ksefv-test . && docker run --rm cmi-ksefv-test`.

Lokalne uzupełnienia2026-11-14 (bez publikacji): `testConnection()` zwraca
firmę, zakresy, plan i flagi; `missingScopes()` wskazuje braki. `isReady()`
ocenia tylko tę konfigurację API, nie numerację, fakturę ani dostęp do MF.
`paymentMethods()` zwraca słownik z rzeczywistymi ID; wybrane przez operatora
ID zapisz w konfiguracji jako string `paymentMethodCode`, nie używaj `ksefCode`.

`sendToKsef()` zwraca `KsefSendResult`: `queued` dla202 lub jawne
`alreadySubmitted:true` ze stanem accepted/processing dla400codealready_submitted.
Ogólne400 i unsupported nadal są odmową; unknown nie staje się sukcesem.
`KsefStatus::processingStatus` jest teraz nullable; obsłuż `isNotSubmitted()`
i processing/null. Brak kodu nie jest potwierdzeniem pustej kolejki ani zgodą
na ponowną wysyłkę po timeoucie. Decyzje biznesowe pozostają u integratora.

Odbiór klienta po rozszerzeniu pollingu:70testów/197asercji naPHP8.3/8.5,
z atrapami oraz lokalnym HTTPloopback. Backend osobno przeszedł pełny gate;
nie wykonano wspólnego odbioru klient→staging→MF TEST. B2C, webhooki,
HTTP Idempotency-Key oraz korekty zamówień nie są dodane w tym etapie.

## Przebieg

### Logowanie integracji (opcjonalne)

Przekaż logger PSR-3: `new KsefvClient($http, logger: $integrationLogger)`.
Bez niego zachowanie pozostaje dotychczasowe. Zdarzenia `ksefv.operation.started`,
`ksefv.http.request_started`, `ksefv.operation.completed`/`ksefv.operation.failed` zawierają tylko
metadane: operację, szablon trasy, metodę, HTTPstatus/czas, wynik, requestId,
serverRequestId, wybrane kody błędu i flagi replay/review. Nie zawierają treści
dokumentów, kluczy, URL/query ani surowych wyjątków. ID korelują logi, nie dają
autoryzacji/idempotencji. Completed wymaga poprawnego parsowania odpowiedzi;
queued202 nie oznacza przyjęcia przez MF. Wyjątek loggera nie zmienia wyniku
operacji ani nie powoduje ponowienia żądania. Logger jest best-effort.

Retencję i procesory loggera ustala aplikacja integratora. Backend KSeFV ma
oddzielną historię `integration_api.request`, lokalnie z retencją30dni; nie
usuwa to logów aplikacji klienta. Instrukcja produktu: w workspace KSeFV
`docs/integration-api-logging.md`. Bez publikacji/wspólnego odbioru staging/MF.
Pełny lokalny zestaw po dodaniu loggera:78testów/262asercje PASS naPHP8.3/8.5.

### Ograniczone oczekiwanie na status

W workerze można jawnie użyć helpera wykonującego wyłącznie GET statusu:

```php
use Cmi\Ksefv\KsefStatusPoller;

$result = (new KsefStatusPoller($ksefv))->waitForResult(
    $connection,
    $invoiceId,
    timeoutSeconds: 30,
    intervalSeconds: 3,
    maxAttempts: 10,
);
if ($result->isAccepted()) {
    $ksefNumber = $result->status->ksefNumber;
}
// Pozostałe reason wymagają decyzji aplikacji; helper nigdy nie zleca send.
```

Powody zakończenia: accepted, rejected, not_submitted, requires_review, timeout,
cancelled, attempt_limit, retry_later. Ostatni udany status może być null/stary;
wynik pętli nie zmienia sam w sobie stanu biznesowego faktury. GET statusu może
natomiast odświeżyć zapisany stan faktury na serwerze KSeFV.

Retry-After z429/503 jest honorowane. Gdy przerwa nie mieści się w budżecie,
wynik retry_later zawiera wskazówkę dla kolejki, zamiast wysyłać żądanie za wcześnie.
Inne błędy, timeout transportu i nieczytelna odpowiedź propagują KsefvException;
nie uruchamiają automatycznych ponowień. Sam timeout pętli nie uprawnia do send.

Opcjonalny callback `isCancelled` (zwracający bool) sprawdzany jest między HTTP i podczas
przerw dzielonych na maksymalnie1s. Nie przerywa trwającego żądania; jego timeout
i max_duration są ograniczone pozostałym budżetem. Limit czasu musi być dodatni,
skończony i<=300s, maxAttempts1..1000, intervalSeconds dodatni/skończony.
Domyślnie30s/3s/10odczytów; brak globalnego limitera współdzielonego między jobami.

Błędy mają nullable `serverCode` oraz `isRetryLater()` obok istniejących kind,
httpStatus i retryAfterSeconds. Nie analizuj polskiego komunikatu, by zgadnąć
brakujący scope: odczytaj testConnection()->missingScopes(). serverCode pochodzi
wyłącznie ze stabilnego pola code; brak/niewłaściwy format daje null.

Backend nie dostał nowej idempotencji wysyłki. Submit/issue mogą powtórzyć tę samą
tożsamość i dane; nie wolno rozszerzać tej zasady na send po niepewnym wyniku.
Pakiet nie dodaje zależności od Symfony Framework/Messenger; uruchamianie przez
job, pobranie sekretu i utrwalenie wyniku należą do aplikacji integratora.
Odbiór2026-11-14:70testów/197asercji naPHP8.3.36 i8.5.12, z jednym prawdziwym
lokalnym testem powolnego HTTP. Nadal brak wspólnego odbioru staging/MF TEST.

### Zamówienie i faktura

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
    issueDate: $today, saleDate: $today, dueDate: $today, paymentMethodCode: $paymentMethodId, // wybrane ID ze słownika
    lines: [new InvoiceLine('Plan Zespół, 1 miesiąc', '1', Decimal::fromMinor(1545), '23', 'usł')],
    payments: [new PaymentFact(Decimal::fromMinor(1900), $paidAt, 'PayU')],
));
$invoice = $ksefv->issue($connection, $receipt->id);        // numer z serii KSeFV, XML FA(3) zamrożony
$send = $ksefv->sendToKsef($connection, $invoice->invoiceId); // queued albo jawna istniejąca próba
$status = $ksefv->ksefStatus($connection, $invoice->invoiceId); // accepted|rejected|processing|not_submitted
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
