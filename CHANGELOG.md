# Changelog

## Unreleased — 2026-11-14

- Opcjonalny logger PSR-3, zależność psr/log^3.0; metadane start/HTTP/wynik/błąd,
  requestId i serverRequestId, bez treści faktur, kluczy i wyjątków. Stan
  per wywołanie, bez dodatkowego retry; throwing logger nie zmienia wyniku.
- Logi klienta mają retencję ustaloną przez integratora, niezależną od30dni
  historii backendu KSeFV. Pełne78/262PASS naPHP8.3.36 i8.5.12; atrapy i
  istniejący loopback, nie odbiór produkcyjny/MF. Zmiany lokalne niewydane.

- KsefStatusPoller: jawne GET-only oczekiwanie, limity czasu/prób, Retry-After,
  kooperatywne anulowanie i KsefPollResult bez automatycznych zapisów/send.
- Opcjonalny maxDurationSeconds w ksefStatus ogranicza oba timeouty HTTP;
  KsefvException.serverCode/isRetryLater bez zmiany istniejących argumentów.
- Poprawiono nieprawdziwe zapewnienie, że każdy zapis klienta jest idempotentny.
  Backend nie został zmieniony; HTTPsend nadal nie ma Idempotency-Key.
- Pełny zestaw70/197PASS naPHP8.3.36 i8.5.12. Atrapy HTTP oraz rzeczywisty
  loopback z odpowiedzią kroplową sprawdzającą całkowity budżet HTTP; nie MF.

- Zgodność z lokalnym API KSeFV na bazie2340f2e + niecommitowane zmiany:
  whoami/testConnection, paymentMethods, stabilne400code i status not_submitted.
- sendToKsef zwraca DTO zamiast void: queued nie oznacza przyjęcia MF,
  already_submitted nie jest mylone z unsupported. Ogólne400 nadal odmowa.
- KsefStatus.processingStatus dopuszcza null dla processing/not_submitted;
  integrator musi uwzględnić nowy wariant i nie ponawiać automatycznie wysyłki.
- RED39:4errors/1failure na brakującym kontrakcie; GREEN39/103PASS, PHP8.5.12,
  PHPUnit11.5.58, atrapy HTTP. Brak odbioru rzeczywistego staging/MF TEST.
  Te same testy i zależności naPHP8.3.36:39/103PASS; minimalne PHP^8.3 bez zmian.
- Bez B2C, webhooków, idempotencji HTTPsend i korekt. Bez commit/push/release.

## 0.1.0 — 2026-11-13

- `KsefvClient`: przyjęcie zamówienia, potwierdzenie, wystawienie faktury, wysyłka do KSeF,
  status KSeF, faktura, PDF, UPO — na kontrakcie `ksefv-symfony` @ 2340f2e (`docs/ksefv-contract.md`).
- `KsefvException` z rodzajem `refused` / `retry_later` / `uncertain`; oba kształty błędów KSeFV
  (`{"error"}` i problem+json); domyślne 3 s, gdy KSeFV zgubi `Retry-After`.
- `OrderInvoice`, `InvoiceLine`, `PaymentFact`, `Decimal`: koperta zamówienia sprawdzana lokalnie,
  kwoty jako dokładne stringi z jednostek mniejszych, dane nabywcy ukryte w `__debugInfo`.
- `KsefvConnection`: tylko HTTPS (poza loopback), klucz bez znaków sterujących.
- Testy: 34 (atrapy HTTP). **Nie odebrane na prawdziwym KSeFV** — wymaga klucza API firmy testowej.
