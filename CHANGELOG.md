# Changelog

## 0.1.0 — 2026-11-13

- `KsefvClient`: przyjęcie zamówienia, potwierdzenie, wystawienie faktury, wysyłka do KSeF,
  status KSeF, faktura, PDF, UPO — na kontrakcie `ksefv-symfony` @ 2340f2e (`docs/ksefv-contract.md`).
- `KsefvException` z rodzajem `refused` / `retry_later` / `uncertain`; oba kształty błędów KSeFV
  (`{"error"}` i problem+json); domyślne 3 s, gdy KSeFV zgubi `Retry-After`.
- `OrderInvoice`, `InvoiceLine`, `PaymentFact`, `Decimal`: koperta zamówienia sprawdzana lokalnie,
  kwoty jako dokładne stringi z jednostek mniejszych, dane nabywcy ukryte w `__debugInfo`.
- `KsefvConnection`: tylko HTTPS (poza loopback), klucz bez znaków sterujących.
- Testy: 34 (atrapy HTTP). **Nie odebrane na prawdziwym KSeFV** — wymaga klucza API firmy testowej.
