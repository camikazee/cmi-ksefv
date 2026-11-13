# Kontrakt KSeFV, na którym stoi klient

Źródło: kod `ksefv-symfony` @ `2340f2e` (2026-11-13), nie tylko dokumentacja. Ścieżki względem
`Ksefv/ksefv-symfony`. Zmiana po stronie KSeFV = przegląd tego pliku i testów `tests/KsefvClientTest.php`.

## Uwierzytelnienie

- Nagłówek `X-API-Key: ksv_live_<12 znaków id><40 znaków sekretu>` (`src/ApiKey/Security/ApiKeyAuthenticator.php`).
- 401 `{"error"}` — zły lub odwołany klucz; 403 `{"error"}` — brak planu API, brak zakresu,
  endpoint niedostępny dla kluczy; 429 `{"error"}` + `Retry-After` — 120 żądań/min na klucz.

## Endpointy

| Operacja | Endpoint | Sukces | Błędy |
| --- | --- | --- | --- |
| Przyjęcie zamówienia | `POST /api/integrations/orders` | 201 nowe / 200 powtórka: `id, system, orderId, payloadSha256, state, invoiceId, createdAt, replayed` | 404 wyłączone, 409 inne dane, 413, 415, 422 kontrakt, 503 |
| Potwierdzenie | `GET /api/integrations/orders/{id}` | 200, te same pola bez `replayed` | 404 |
| Wystawienie | `POST /api/integrations/orders/{id}/issue`, body puste albo `{}` | 201 / 200: `id, invoiceId, number, state: issued, replayed` | 404, 409 (niespójność, zamknięty okres), 422 (dane, profil sprzedawcy, seria, FA(3)), 503 |
| Wysyłka KSeF | `POST /api/ksef/invoice/{id}/send` | 202 `{"message"}` — kolejka workera | 400 `{"error"}`: już wysłana / w trakcie / nieobsługiwana; 403; 404 |
| Status KSeF | `GET /api/ksef/invoice/{id}/status` | 200: `processingStatus` (200 przyjęta, 100/150 w toku, ≥400 odrzucona, 440 duplikat → w toku + `requiresReview`), `processingDescription`, `ksefNumber`, `acquisitionDate`, `outcome: accepted\|rejected\|processing` | 500 `{"error"}` także dla faktury nigdy niewysłanej |
| UPO | `GET /api/ksef/invoice/{id}/upo` | 200 `application/xml` (podpis UPO niezweryfikowany) | 500 |
| Faktura | `GET /api/invoices/{id}` z `Accept: application/json` | `id, number, status (draft\|issued\|processing\|sent\|paid\|rejected), currency, totalNet, totalVat, totalGross, ksefNumber, …` | 403, 404 |
| PDF | `GET /api/invoices/{id}/pdf` | 200 `application/pdf` | 503 `{"error"}` + `Retry-After: 30`, 403, 404 |

## Profil zamówienia `regular-pln-v1`

Koperta: dokładnie `system` (`^[a-z0-9][a-z0-9_-]{0,63}$`), `orderId`
(`^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$`), `invoice`. Wszystkie wartości to niepuste stringi
(JSON-owe liczby są odrzucane), ≤ 4096 bajtów, bez znaków sterujących; całość ≤ 256 KiB.

Do przyjęcia wymagane `buyerName`, `currency`, `items`. Do **wystawienia** dodatkowo `buyerNip`
(10 cyfr), `buyerAddress`, `buyerCountry = PL`, `issueDate`, `saleDate`, `dueDate ≥ issueDate`,
`paymentMethodCode` (id słownika metod płatności firmy, `^[1-9]\d{0,8}$`; rachunek bankowy, jeśli
metoda go wymaga) i klucz `payments` (także `[]`). `currency = PLN`, bez `exemptionBasis`.
Pozycje: `name`, `quantity` (`^\d{1,7}(\.\d{1,3})?$`, > 0), `netPrice` (`^\d{1,10}(\.\d{1,2})?$`),
`vatRateCode` ∈ 23/8/5/0, `unit` (≤ 10 znaków); `discount` tylko zero. Wpłaty: `amount` > 0,
`date` `Y-m-d`, `method` tekst; suma ≤ brutto — to deklaracja źródła, nie dowód banku.

Klient sprawdza lokalnie kopertę (identyfikatory, typy, limity, znaki), a reguły profilu
pozostawia KSeFV, żeby nie utrzymywać ich w dwóch miejscach.

## Rzeczy do poprawy po stronie KSeFV (znalezione przy pisaniu klienta)

1. `ApiExceptionSubscriber` gubi nagłówki `HttpException` — `Retry-After: 3` z inboxu/issuera
   nie dociera do klienta (klient przyjmuje wtedy domyślne 3 s).
2. Wysyłka KSeF odpowiada 400 zarówno dla faktury już wysłanej, jak i nieobsługiwanej — klient
   nie odróżni bezpiecznego powtórzenia od odmowy (zalecany 409 dla „już wysłana”).
3. Status KSeF zwraca 500 dla faktury nigdy niewysłanej — nie da się odróżnić od awarii.
4. Brak dostawy zdarzeń `invoice.issued.v1` (tabela `integration_order_events` bez konsumenta) —
   integrator musi odpytywać.
5. Brak profilu B2C (nabywca bez NIP) — faktury dla konsumentów zostają w systemie źródłowym.
