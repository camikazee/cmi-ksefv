# Zgłoszenia do KSeFV — dla agenta pracującego w `Ksefv/`

**Stan na 13 listopada 2026 r.** · źródło: odczyt kodu `ksefv-symfony` @ `2340f2e` podczas pisania
klienta `cmi/ksefv` 0.1.0 · ścieżki względem `Ksefv/ksefv-symfony`

Ten plik jest kolejką prac po stronie KSeFV, które są potrzebne bibliotece `cmi/ksefv` i jej
pierwszemu użytkownikowi (Todoly, faktury dla firm za plany — ADR-080 w Todoly). Agent w KSeFV
pracuje według reguł repozytorium KSeFV (`Ksefv/AGENTS.md`, protokół dokumentacji, testy,
changelog); ten plik niczego tam nie nadpisuje i nie jest zgodą na deploy ani push.

Zasady dla obu stron:

- Po zmianie w KSeFV zaktualizuj status punktu niżej (data, commit KSeFV) i
  [`ksefv-contract.md`](ksefv-contract.md); zmiana kontraktu = zmiana testów `tests/KsefvClientTest.php`.
- Nowe potrzeby integratora dopisuj tutaj jako kolejny punkt `KSV-REQ-NN`, nie w rozmowie.
- Zmiana zachowania widocznego dla integratora (kody, pola) musi być wstecznie zgodna albo mieć
  wersję/flagę — inne integracje mogą już polegać na obecnym kontrakcie.

Statusy: **open** · **in progress** · **done (data, commit)** · **rejected (powód)**.

---

## A. Usterki kontraktu znalezione przy pisaniu klienta

### KSV-REQ-01 — `Retry-After` ginie w odpowiedziach problem+json — **open**

- **Gdzie:** `src/Shared/EventSubscriber/ApiExceptionSubscriber.php` (rejestracja
  `config/services.yaml:324`) buduje odpowiedź z kodu i treści wyjątku, ale ignoruje
  `$exception->getHeaders()`.
- **Skutek:** `HttpException(503, …, null, ['Retry-After' => '3'])` z
  `src/Integration/Order/IntegrationOrderInbox.php:43-44` i
  `src/Integration/Order/IntegrationOrderIssuer.php:109` dociera do klienta bez nagłówka. Klient
  zgaduje przerwę (stałe 3 s w `KsefvClient::DEFAULT_RETRY_AFTER`).
- **Oczekiwane:** subscriber przenosi nagłówki `HttpExceptionInterface::getHeaders()` do
  odpowiedzi (co najmniej `Retry-After`, `Allow`, `WWW-Authenticate`), bez zmiany treści.
- **Odbiór:** test funkcjonalny: wymuszony 503 z inboxu → odpowiedź ma `Retry-After: 3`
  i `Content-Type: application/problem+json`.
- **Po stronie `cmi/ksefv`:** bez zmian w kodzie (już czyta nagłówek); usunąć adnotację o
  zgubionym nagłówku z README i `ksefv-contract.md`.

### KSV-REQ-02 — „już wysłana do KSeF” nieodróżnialna od „nieobsługiwana” — **open**

- **Gdzie:** `src/Ksef/Controller/KsefController.php:44` (faktura ma `ksefNumber` /
  `ksefReferenceNumber` / `ksefSessionReference` albo status `processing|sent`) i `:58`
  (`assertSupportedForKsef` nie przechodzi) — oba zwracają **400** `{"error": …}`.
- **Skutek:** po niepewnej wysyłce (timeout) integrator nie wie, czy ponowienie zostało
  odrzucone, bo faktura już jest w drodze (sukces), czy dlatego, że nie da się jej wysłać (błąd).
- **Oczekiwane:** faktura już przekazana / wysłana → **409** z problem+json i stabilnym
  `type` (np. `…/errors/ksef-already-submitted`) oraz stanem (`processing|sent|accepted`);
  faktura nieobsługiwana przez KSeF → **422** z listą pól, które blokują (bez wartości).
  Alternatywa w pełni zgodna wstecz: 400 zostaje, ale z polem `code`
  (`already_submitted` / `unsupported`).
- **Odbiór:** testy API dla obu przypadków i dla ponownego `send` po sukcesie.
- **Po stronie `cmi/ksefv`:** `sendToKsef()` zwraca sukces (lub osobny wynik „już wysłana”)
  dla 409/`already_submitted` zamiast odmowy; test w `KsefvClientTest`.

### KSV-REQ-03 — status KSeF faktury nigdy niewysłanej to 500 — **open**

- **Gdzie:** `src/Ksef/Controller/KsefController.php:98` + `src/Ksef/Service/KsefUpoService.php`
  — brak referencji sesji kończy się wyjątkiem i `500 {"error": "Nie udało się sprawdzić statusu."}`.
- **Skutek:** klient traktuje 5xx jako wynik niepewny i ponawia; nie odróżni „nie wysłano” od
  awarii KSeF/KSeFV.
- **Oczekiwane:** faktura bez wysyłki → **409** (albo 200 z `outcome: "not_submitted"`);
  prawdziwy błąd odczytu z KSeF → **502/503** z `Retry-After`. 500 tylko dla błędu programu.
- **Odbiór:** test API: świeżo wystawiona faktura → status nie jest 500.
- **Po stronie `cmi/ksefv`:** nowa wartość w `KsefStatus` albo osobny wyjątek; test.

### KSV-REQ-04 — zdarzenia `invoice.issued.v1` bez dostawy — **open**

- **Gdzie:** `src/Integration/Order/IntegrationOrderIssuer.php:99-104` zapisuje zdarzenie
  `pending` w `integration_order_events`; `src/Entity/IntegrationOrderEvent.php:8` i
  `docs/architecture.md` potwierdzają brak konsumenta. Nie ma też zdarzeń o wyniku KSeF.
- **Skutek:** integrator musi odpytywać status każdej faktury (limit 120 żądań/min na klucz).
- **Oczekiwane (etap 5 z `Ksefv/docs/order-invoice-automation.md`):** webhook z zatwierdzonej
  konfiguracji integracji (HTTPS, ochrona SSRF, limit przekierowań/rozmiaru/czasu), podpis HMAC
  z `timestamp` i `eventId`, ponowienia z DLQ, zdarzenia co najmniej `invoice.issued.v1`,
  `invoice.ksef_accepted.v1`, `invoice.ksef_rejected.v1`. Do czasu webhooków — endpoint listy
  zmian od kursora (np. `GET /api/integrations/events?after=<eventId>`), żeby nie odpytywać
  każdej faktury osobno.
- **Odbiór:** testy podpisu, ponowień i deduplikacji po `eventId`.
- **Po stronie `cmi/ksefv`:** weryfikacja podpisu webhooka i parser zdarzeń (albo odczyt listy
  zmian); do tego czasu Todoly odpytuje jobem.

### KSV-REQ-05 — brak profilu B2C (nabywca bez NIP) — **open, niski priorytet**

- **Gdzie:** `src/Integration/Order/OrderInvoiceMapper.php:21-28` (NIP 10 cyfr, `PL`,
  `regular`) i `src/Invoice/Service/KsefInvoiceService.php:132-135` (wysyłka KSeF wymaga NIP).
- **Skutek:** faktury dla osób prywatnych zostają w systemie źródłowym (Todoly wystawia je samo —
  ADR-080). Nie blokuje Todoly.
- **Oczekiwane, jeśli KSeFV ma obsłużyć sklepy B2C:** osobny profil (np. `consumer-pln-v1`)
  z jawną decyzją, czy faktura B2C idzie do KSeF; bez zmiany profilu `regular-pln-v1`.

---

## B. Potrzeby integratora (Todoly) — brakujące elementy

### KSV-REQ-06 — sprawdzenie klucza i zakresów bez skutków — **open**

- **Potrzeba:** przy konfiguracji integracji (panel Todoly, readiness przed wydaniem) trzeba
  sprawdzić, czy adres i klucz działają oraz czy klucz ma `invoices.write`, `invoices.read`,
  `invoices.issue`, `ksef.send`, `ksef.download`, `invoices.download` i plan z API.
- **Dziś:** nie ma endpointu, który to mówi; jedyna droga to próbne wywołania z efektami albo
  zgadywanie z 403.
- **Oczekiwane:** `GET /api/integrations/whoami` (albo `/api/api-keys/self`) → `200`
  `{"company": {"nip", "name"}, "scopes": [...], "apiPlanActive": bool, "integrationOrders": {"enabled", "issueEnabled"}}`
  — bez sekretów, bez danych osobowych poza firmą klucza.
- **Po stronie `cmi/ksefv`:** `KsefvClient::testConnection()` zwracające brakujące zakresy.

### KSV-REQ-07 — słownik metod płatności dla klucza API — **open (do potwierdzenia)**

- **Potrzeba:** `paymentMethodCode` to id z `DicPaymentMethod` firmy (`IntegrationOrderIssuer.php:83`),
  a nie kod KSeF. Todoly musi znać id metody „PayU” (lub „płatność elektroniczna”) w firmie
  sprzedawcy i wiedzieć, czy wymaga rachunku bankowego.
- **Do sprawdzenia przez agenta:** czy istnieje endpoint słownika dostępny dla klucza API
  (jaki zakres, jakie pola). Jeśli nie — dodać odczyt `GET /api/dictionaries/payment-methods`
  (`id`, `name`, `ksefCode`, `requiresBankAccount`) za zakresem `invoices.read`.
- **Po stronie `cmi/ksefv`:** `KsefvClient::paymentMethods()`; Todoly zapisuje wybrane id
  w konfiguracji, nie zgaduje.

### KSV-REQ-08 — firma testowa i klucz do odbioru — **open**

- **Potrzeba:** odbiór `cmi/ksefv` i integracji Todoly na prawdziwym KSeFV: firma testowa
  z kompletnym profilem sprzedawcy, serią numeracji `regular`, metodą płatności, planem z API,
  kluczem ze wszystkimi zakresami z KSV-REQ-06, włączonymi flagami
  `INTEGRATION_ORDERS_ENABLED=1` i `INTEGRATION_ORDER_ISSUE_ENABLED=1` oraz KSeF w środowisku
  testowym MF.
- **Oczekiwane:** instrukcja (gdzie, jak założyć, jak włączyć flagi na stagingu KSeFV) zapisana
  w dokumentacji KSeFV; klucz przekazuje właściciel poza repozytorium.
- **Odbiór po stronie `cmi/ksefv`:** przyjęcie → wystawienie → wysyłka → status `accepted` →
  PDF i UPO, powtórka przyjęcia i wystawienia (`replayed`), konflikt danych (409); wynik
  dopisany do `CHANGELOG.md` biblioteki jako dowód odbioru.

### KSV-REQ-09 — wysyłka KSeF z kluczem idempotencji — **open, opcjonalne**

- **Potrzeba:** bezpieczne ponowienie `POST /api/ksef/invoice/{id}/send` po timeoucie bez
  odpytywania statusu.
- **Oczekiwane:** nagłówek `Idempotency-Key` — to samo żądanie z tym samym kluczem zwraca
  pierwotny wynik (202) zamiast 400/409. Rozwiązuje też większość KSV-REQ-02.

### KSV-REQ-10 — faktura korygująca dla zamówienia z integracji — **open**

- **Potrzeba:** zwrot płatności za plan (ADR-079) wymaga korekty faktury wystawionej przez KSeFV.
- **Dziś:** `Ksefv/docs/order-invoice-automation.md`, „Kolejne etapy” pkt 1 i 4 — korekty poza
  zakresem; edycja wystawionej faktury zablokowana (409).
- **Oczekiwane:** `POST /api/integrations/orders/{id}/corrections` z jawną referencją do
  oryginału i pozycjami korekty (idempotentne po identyfikatorze korekty integratora), wystawienie
  i wysyłka jak dla faktury. Do czasu wdrożenia Todoly zgłasza zwrot człowiekowi, który koryguje
  fakturę w panelu KSeFV.

---

## Historia

- 2026-11-13 — utworzono (KSV-REQ-01…10) przy `cmi/ksefv` 0.1.0.
