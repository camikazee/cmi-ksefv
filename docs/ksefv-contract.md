# Kontrakt KSeFV, na którym stoi klient

Źródło: kod `ksefv-symfony` @ `2340f2e` (2026-11-13), nie tylko dokumentacja. Ścieżki względem
`Ksefv/ksefv-symfony`. Zmiana po stronie KSeFV = przegląd tego pliku i testów `tests/KsefvClientTest.php`.

Uzupełnienie2026-11-14: backend2340f2e + lokalne niecommitowane zmiany API,
nie potwierdzenie produkcji. Pełny lokalny backend gate1668/18596 i671criticalPASS,
audit0 po phpseclib3.0.58. Biblioteka po dodaniu pollingu70/197PASS naPHP8.3/8.5,
z atrapami i lokalnym HTTPloopback; brak wspólnego
odbioru staging/MF TEST. Kanoniczny opis produktu i runbook: w workspace KSeFV
`docs/integration-client-contract.md` oraz `docs/audits/2026-11-14-client-release-unblock.md`.

## Uwierzytelnienie

- Nagłówek `X-API-Key: ksv_live_<12 znaków id><40 znaków sekretu>` (`src/ApiKey/Security/ApiKeyAuthenticator.php`).
- 401 `{"error"}` — zły lub odwołany klucz; 403 `{"error"}` — brak planu API, brak zakresu,
  endpoint niedostępny dla kluczy; 429 `{"error"}` + `Retry-After` — 120 żądań/min na klucz.
- Wyjątek od planu: ważny klucz może odczytać GET/HEAD dokładnego whoami z
  apiPlanActive:false. Nie odblokowuje żadnej operacji biznesowej.
- Wyjątki pod /api/integrations/ mają problem+json i zachowują Retry-After/Allow/
  WWW-Authenticate; legacy JSON poza prefiksem zachowano, także z nagłówkami.

## Endpointy

| Operacja | Endpoint | Sukces | Błędy |
| --- | --- | --- | --- |
| Przyjęcie zamówienia | `POST /api/integrations/orders` | 201 nowe / 200 powtórka: `id, system, orderId, payloadSha256, state, invoiceId, createdAt, replayed` | 404 wyłączone, 409 inne dane, 413, 415, 422 kontrakt, 503 |
| Potwierdzenie | `GET /api/integrations/orders/{id}` | 200, te same pola bez `replayed` | 404 |
| Wystawienie | `POST /api/integrations/orders/{id}/issue`, body puste albo `{}` | 201 / 200: `id, invoiceId, number, state: issued, replayed` | 404, 409 (niespójność, zamknięty okres), 422 (dane, profil sprzedawcy, seria, FA(3)), 503 |
| Wysyłka KSeF | `POST /api/ksef/invoice/{id}/send` | 202 `{"message"}` — kolejka workera | 400error + code:already_submitted (outcome accepted/processing, requiresReview) lub unsupported; bez code nadal odmowa; 403/404 |
| Status KSeF | `GET /api/ksef/invoice/{id}/status` | 200: outcome accepted/rejected/processing/not_submitted; processingStatus int lub null tylko dla processing/not_submitted. Accepted wymaga numeru KSeF | typowane awarie MF/transportu503 + Retry-After3; pozostałe500 |
| Diagnostyka klucza | `GET /api/integrations/whoami` | company{nip,name} nullable, scopes[], apiPlanActive bool, integrationOrders{enabled,issueEnabled} bool; no-store | 401/403/429; JWT panelu nie zastępuje klucza |
| Metody płatności | `GET /api/dictionaries/payment-methods`, invoices.read i plan API | items[{id int,name,ksefCode nullable,requiresBankAccount bool}], no-store | 401/403/429 |
| UPO | `GET /api/ksef/invoice/{id}/upo` | 200 `application/xml` (podpis UPO niezweryfikowany) | 500 |
| Faktura | `GET /api/invoices/{id}` z `Accept: application/json` | `id, number, status (draft\|issued\|processing\|sent\|paid\|rejected), currency, totalNet, totalVat, totalGross, ksefNumber, …` | 403, 404 |
| PDF | `GET /api/invoices/{id}/pdf` | 200 `application/pdf` | 503 `{"error"}` + `Retry-After: 30`, 403, 404 |

## Oczekiwanie i błędy po stronie klienta (2026-11-14)

KsefStatusPoller nie jest nowym endpointem ani webhookiem. Wywołuje tylko
istniejący GETstatus, maksymalnie do limitu czasu/prób. Status serwerowy oraz
powód zatrzymania pętli są odrębne. Retry_later odsyła do kolejki, gdy wskazana
przerwa nie zmieści się w pozostałym budżecie; nie skraca Retry-After.
Transport/nieczytelna odpowiedź/odmowa propagują wyjątek. Anulowanie kooperatywne,
nie natychmiastowe w czasie HTTP. Żądanie otrzymuje timeout/max_duration nie
większe niż pozostały czas; lokalne planowanie procesu nie jest gwarancją SLA.

KsefvException.serverCode: null albo string `[a-z][a-z0-9_]{0,63}` odczytany
z pola code. Klasyfikacja refused/retry_later/uncertain pozostaje zgodna;
isRetryLater jest helperem, nie pozwoleniem na ponawianie dowolnego zapisu.
Nowy opcjonalny argument ksefStatus maxDurationSeconds nie zmienia domyślnych
15s inactivity/30s max_duration pozostałych wywołań.
Testy70/197PASS naPHP8.3/8.5 z realnym HTTPloopback; nie zastępują REQ08.

## Profil zamówienia `regular-pln-v1` — pola

Koperta: dokładnie `system` (`^[a-z0-9][a-z0-9_-]{0,63}$`), `orderId`
(`^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$`), `invoice`. Wszystkie wartości to niepuste stringi
(JSON-owe liczby są odrzucane), ≤ 4096 bajtów, bez znaków sterujących; całość ≤ 256 KiB.

Do przyjęcia wymagane `buyerName`, `currency`, `items`. Do **wystawienia** dodatkowo `buyerNip`
(10 cyfr), `buyerAddress`, `buyerCountry = PL`, `issueDate`, `saleDate`, `dueDate ≥ issueDate`,
`paymentMethodCode` (id wspólnego słownika metod płatności, nie ksefCode, `^[1-9]\d{0,8}$`; rachunek bankowy, jeśli
metoda go wymaga) i klucz `payments` (także `[]`). `currency = PLN`, bez `exemptionBasis`.
Pozycje: `name`, `quantity` (`^\d{1,7}(\.\d{1,3})?$`, > 0), `netPrice` (`^\d{1,10}(\.\d{1,2})?$`),
`vatRateCode` ∈ 23/8/5/0, `unit` (≤ 10 znaków); `discount` tylko zero. Wpłaty: `amount` > 0,
`date` `Y-m-d`, `method` tekst; suma ≤ brutto — to deklaracja źródła, nie dowód banku.

Klient sprawdza lokalnie kopertę (identyfikatory, typy, limity, znaki), a reguły profilu
pozostawia KSeFV, żeby nie utrzymywać ich w dwóch miejscach.

## Usterki i potrzeby po stronie KSeFV

Kolejka prac dla agenta w KSeFV, z miejscami w kodzie, oczekiwanym zachowaniem i odbiorem:
[`ksefv-requests.md`](ksefv-requests.md) (KSV-REQ-01…10). Po każdej zmianie w KSeFV aktualizuj
oba pliki i testy klienta.
