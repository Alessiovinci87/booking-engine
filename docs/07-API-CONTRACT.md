# 07 — Contratto API

Versione: 1.3 · Data: 06/11/2026 · Modifiche: [CHANGELOG-v1.1](CHANGELOG-v1.1.md); 1.2: ADR-0008, ADR-0009; 1.3: ADR-0010 (codici, rotte, avvisi e guardia dei pagamenti dopo M2; API pubblica senza CSRF, `UNIT_NOT_BOOKABLE`)

REST JSON, prefisso `/api/v1`. Tre superfici con autenticazione diversa:

| Superficie | Prefisso | Autenticazione | Tenant risolto da |
|---|---|---|---|
| Pubblica (ospite) | `/api/v1/public/{tenantSlug}/{propertySlug}` | nessuna; rate limit per IP | slug nell'URL |
| Accesso ospite a una prenotazione/preventivo | `/api/v1/public/reservations/{code}`, `/api/v1/public/quotes/{token}` | token segreto nel link | token |
| Pannello gestore | `/api/v1/admin` | Sanctum (cookie SPA), MFA obbligatoria | membership dell'utente |
| Webhook | `/webhooks/stripe` | firma Stripe | `event.account` |
| ICS export | `/ical/{feed_token}.ics` (un feed per unità e destinazione) | token del feed | token |

Il `tenant_id` non viene **mai** accettato dal client.

L'API pubblica non usa cookie di sessione e quindi non usa il token CSRF (ADR-0010). È protetta dal rate limit, dall'`Idempotency-Key` sulle `POST` e, per le chiamate dalla vista incorporata, dal controllo dell'header `Origin` rispetto agli `embed_allowed_origins` del tenant (02).

## Convenzioni

- `Content-Type: application/json`; date `YYYY-MM-DD`; timestamp ISO 8601 UTC; importi `{ "amount_cents": 158500, "currency": "EUR" }`.
- Intervalli di soggiorno: `{ "check_in": "2027-08-10", "check_out": "2027-08-17" }` con check-out escluso.
- Lingua: header `Accept-Language` (`it` | `en`, fallback `en`) o parametro `locale`.
- Paginazione cursor-based: `?cursor=…&limit=50` (max 100); risposta `{ "data": [...], "next_cursor": "…" | null }`.
- **Idempotenza**: tutte le `POST` che creano prenotazioni, checkout, preventivi, rimborsi o cambiano stato richiedono header `Idempotency-Key` (UUID, conservato 24 h per utente/IP). Stessa chiave + stesso body → stessa risposta; stessa chiave + body diverso → `422 IDEMPOTENCY_KEY_REUSED`.
- **Concorrenza ottimistica** sulle modifiche admin: header `If-Match: "<version>"`; versione non corrispondente → `412`.

## Formato errori

```json
{
  "error": {
    "code": "DATES_UNAVAILABLE",
    "message": "Le date selezionate non sono più disponibili.",
    "details": { "unit_id": "…", "nights": ["2027-08-12"] },
    "request_id": "01J…"
  }
}
```

| HTTP | Codici |
|---|---|
| 400 | `VALIDATION_FAILED` (`details.fields`: errori per campo) |
| 401 | `UNAUTHENTICATED`, `MFA_REQUIRED`, `MFA_SETUP_REQUIRED`, `INVALID_CREDENTIALS`, `INVALID_MFA_CODE` |
| 403 | `FORBIDDEN`, `REAUTH_REQUIRED` |
| 404 | `NOT_FOUND` (anche per risorse di altri tenant: mai `403`) |
| 409 | `DATES_UNAVAILABLE`, `CHECKOUT_EXPIRED`, `CONFLICT_OPEN`, `ILLEGAL_TRANSITION`, `PRICE_CHANGED`, `MFA_ALREADY_ENABLED`, `EXTERNAL_CALENDARS_ACTIVE`, `UNIT_NOT_BOOKABLE` |
| 419 | `CSRF_TOKEN_EXPIRED` (la SPA richiede un nuovo cookie CSRF e ripete una volta) |
| 410 | `QUOTE_EXPIRED`, `QUOTE_REVOKED`, `QUOTE_EXHAUSTED`, `FEED_RETIRED` |
| 412 | `VERSION_MISMATCH` |
| 422 | `MIN_NIGHTS_NOT_MET`, `OCCUPANCY_EXCEEDED`, `AMOUNT_BELOW_MINIMUM`, `PAYMENTS_NOT_ENABLED`, `IDEMPOTENCY_KEY_REUSED`, `INVALID_ICAL_URL`, `CANCELLATION_POLICY_MISSING`, `NO_ACTIVE_UNITS`, `TRANSLATION_IT_MISSING` |
| 429 | `RATE_LIMITED` |
| 502/503 | `PAYMENT_PROVIDER_UNAVAILABLE` |

I messaggi `message` sono localizzati; il client si basa sempre su `code`.

---

## API pubblica (ospite)

### `GET /public/{t}/{p}`
Struttura pubblicata: nomi e descrizioni nella lingua richiesta (con `translation_fallback: true` se manca), foto, orari, politiche, unità attive con capienza e foto. Una struttura pubblicata senza unità attive risponde con `units: []`: la pagina ospite mostra "Nessuna disponibilità al momento" (ADR-0010).

### `GET /public/{t}/{p}/availability?unit_id=&from=&to=`
Notti non vendibili per unità nell'intervallo (max 18 mesi). Non rivela il motivo (prenotazione, blocco, conflitto). Unità con vendite sospese o non attiva: `409 UNIT_NOT_BOOKABLE` (ADR-0010).

```json
{ "unit_id": "…", "unavailable_nights": ["2027-08-10", "…"], "min_nights_by_night": { "2027-08-01": 5 } }
```

### `POST /public/{t}/{p}/quote-price`
Calcola il prezzo senza creare nulla. Body: `unit_id`, `check_in`, `check_out`, `adults`, `children`. Risposta: righe, totale, caparra, saldo, `booking_mode`, politica di cancellazione. Errori: `MIN_NIGHTS_NOT_MET`, `OCCUPANCY_EXCEEDED`, `DATES_UNAVAILABLE`, `UNIT_NOT_BOOKABLE` (unità con vendite sospese o non attiva, ADR-0010).

### `POST /public/{t}/{p}/reservations` · Idempotency-Key
Avvia una prenotazione. Body:

```json
{
  "unit_id": "…", "check_in": "2027-08-10", "check_out": "2027-08-17",
  "adults": 4, "children": 1,
  "guest": { "first_name": "…", "last_name": "…", "email": "…", "phone": "…", "country_code": "DE" },
  "locale": "en",
  "accepted_terms_version": "2026-11-01", "accepted_privacy_version": "2026-11-01",
  "expected_total_cents": 158500
}
```

Il server ricalcola il prezzo; se diverso da `expected_total_cents` → `409 PRICE_CHANGED` con il nuovo prezzo. Risposta `201`:

```json
{ "reservation_code": "K7Q2MZ4A", "status": "AWAITING_PAYMENT", "checkout_url": "https://checkout.stripe.com/…", "checkout_expires_at": "…" }
```

Il client apre `checkout_url` **in top-level** (`window.top.location` o nuova scheda se l'iframe non lo consente).

### `GET /public/reservations/{code}?token=`
Stato della prenotazione per l'ospite (riepilogo, stato leggibile, importi pagati e dovuti, politica). Usato dalla pagina di ritorno da Stripe con polling ogni 2 s fino a 60 s. Il token arriva nell'email e nel redirect; viene confrontato con l'hash.

### `GET /public/quotes/{token}`
Preventivo: unità, date, ospiti, righe, sconto, totale, scadenza, `booking_mode`. Scaduto → `410 QUOTE_EXPIRED`.

### `POST /public/quotes/{token}/accept` · Idempotency-Key
Body: dati ospite (precompilati), versioni di termini e privacy accettati. Blocca la riga del preventivo, controlla scadenza, tentativi e disponibilità; **non** ricalcola il prezzo (congelato). Risposta come `POST /reservations`.

Se esiste già un tentativo attivo (`IN_CHECKOUT`) ancora valido, restituisce `200` con **lo stesso** `reservation_code` e `checkout_url`, senza creare nulla (I21). Tentativi esauriti → `410 QUOTE_EXHAUSTED`.

---

## API pannello (`/admin`)

### Accesso (ADR-0008)
Sotto `/admin/auth/`, con sessione Sanctum e CSRF:

| Metodo | Percorso | Note |
|---|---|---|
| POST | `/auth/login` | email e password; risponde `{ mfa_setup_required }`; limite 5 tentativi/15 min per account e per IP |
| GET | `/auth/session` | `{ mfa_enabled, mfa_verified }` |
| POST | `/auth/logout` | |
| GET/POST | `/auth/mfa/setup` | solo con la MFA non ancora attiva: QR (SVG generato in locale) e chiave; la conferma restituisce 10 codici di recupero, una sola volta |
| POST | `/auth/mfa/verify` | `code` TOTP oppure `recovery_code`; stesso limite di tentativi del login |
| POST | `/auth/confirm-password` | ri-autenticazione valida 15 minuti per le azioni sensibili (`403 REAUTH_REQUIRED` senza) |

Ogni altra rotta `/admin/*` richiede, nell'ordine: sessione valida (2 h di inattività, 12 h assolute), MFA verificata nella sessione, limite di 300 richieste/min per utente, tenant dalla membership.


Tutte richiedono utente autenticato con MFA verificata nella sessione e membership `OWNER` nel tenant attivo (nella 1.0 c'è un solo tenant per utente; header `X-Tenant` riservato per il futuro, validato contro le membership).

### Struttura e unità
| Metodo | Percorso | Note |
|---|---|---|
| GET/POST | `/properties` | elenco e creazione (ADR-0010) |
| GET/PATCH | `/properties/{id}` | incluse traduzioni |
| POST | `/properties/{id}/publish` | richiede, nell'ordine: ≥ 1 unità attiva (`422 NO_ACTIVE_UNITS`), traduzione `it` (`422 TRANSLATION_IT_MISSING`), politica di cancellazione predefinita della struttura (`422 CANCELLATION_POLICY_MISSING`, ADR-0009), account Stripe del tenant con `charges_enabled` (`422 PAYMENTS_NOT_ENABLED`). Risponde con il codice della prima guardia non soddisfatta e l'elenco di tutte in `details.missing`; già pubblicata → `200` senza modifiche; archiviata → `409 ILLEGAL_TRANSITION`. Senza `Idempotency-Key` finché non c'è il meccanismo generico di I9: l'operazione è idempotente per natura (ADR-0010) |
| GET/POST | `/properties/{id}/units` | |
| GET/PATCH | `/units/{id}` | cambio a `INSTANT` rifiutato se ci sono calendari esterni attivi (`409 EXTERNAL_CALENDARS_ACTIVE`, `details.active_calendars`); disattivare l'ultima unità attiva di una struttura pubblicata è consentito (il pannello mostra un avviso, ADR-0010); `cancellation_policy_id` facoltativa, solo tra le politiche della stessa struttura |
| GET/POST | `/units/{id}/photos` | elenco e caricamento; lettura con URL firmato |
| PUT | `/units/{id}/photos/order` | corpo `photo_ids`; la prima foto è la copertina (ADR-0010) |
| DELETE | `/units/{id}/photos/{photoId}` | (ADR-0010) |
| GET/POST | `/units/{id}/ics-feeds` | un feed per destinazione (`BOOKING`, `AIRBNB`, `OTHER`), accoppiato al calendario importato dallo stesso canale; restituisce URL e ultima lettura |
| POST | `/ics-feeds/{id}/rotate` | nuovo token; il vecchio resta `FROZEN` 7 giorni |
| GET | `/units/{id}/channel-checklist` | stato dei passi di collegamento a stella (06) |
| POST | `/units/{id}/channel-checklist/{step}/confirm` | conferma del gestore |
| PATCH | `/units/{id}/sales` | sospende o riattiva le vendite dell'unità |

### Tariffe
| Metodo | Percorso |
|---|---|
| GET/PATCH | `/units/{id}/rate-plan` |
| GET/POST/PATCH/DELETE | `/units/{id}/seasons[/{seasonId}]` (le risposte di `GET`, `POST` e `PATCH` contengono `warnings`: `{ code: "SEASON_OVERLAP_SAME_PRIORITY", priority, seasons: [{ id, name, first_night, last_night }] }`, ADR-0010) |
| POST | `/units/{id}/price-preview` (stesso calcolo della pubblica) |

### Calendario
| Metodo | Percorso | Note |
|---|---|---|
| GET | `/calendar?unit_id=&from=&to=` | occupazioni (prenotazioni in ogni stato occupante e blocchi manuali), eventi esterni con classificazione eco, conflitti, con origine |
| POST/DELETE | `/units/{id}/blocks[/{blockId}]` | blocchi manuali |
| GET/POST | `/units/{id}/external-calendars` | validazione URL (06) |
| PATCH/DELETE | `/external-calendars/{id}` | disattiva/rimuove |
| POST | `/external-calendars/{id}/sync-now` | max 1 ogni 2 min |
| GET | `/external-calendars/{id}/runs` | storico SyncRun |

### Prenotazioni
| Metodo | Percorso | Note |
|---|---|---|
| GET | `/reservations?status=&from=&to=&unit_id=` | |
| GET | `/reservations/{id}` | dettaglio, righe, pagamenti, rimborsi, storico, audit |
| POST | `/reservations` | manuale, confermata anche senza pagamento (stato economico `UNPAID`) · Idempotency-Key |
| POST | `/reservations/{id}/approve` | Idempotency-Key; `409 CONFLICT_OPEN` se ci sono conflitti sulle date |
| POST | `/reservations/{id}/reject` | Idempotency-Key, `reason` opzionale |
| POST | `/reservations/{id}/cancel` | Idempotency-Key; body `refund_cents` (default = proposto dalla policy, max = incassato online). Risposta: prenotazione `CANCELLED` e `refund_status` (`PENDING`/`NONE`), mai "rimborsato" |
| PATCH | `/reservations/{id}` | modifica date/ospiti/note · If-Match |
| POST | `/reservations/{id}/offline-payments` | registra pagamento fuori piattaforma |
| POST | `/reservations/{id}/balance-link` | link di pagamento del saldo (nessuna occupazione nuova) · Idempotency-Key |
| POST | `/reservations/{id}/recovery-link` | solo da `CAPTURE_FAILED` (T15); body `deadline_hours` 1–24 · Idempotency-Key |
| POST | `/reservations/{id}/release-dates` | solo da `CAPTURE_FAILED` (T16) · Idempotency-Key |
| GET | `/refunds?status=FAILED` | rimborsi da gestire |
| POST | `/refunds/{id}/retry` | Idempotency-Key |
| POST | `/refunds/{id}/mark-offline` | `note` obbligatoria |

### Preventivi
| Metodo | Percorso |
|---|---|
| GET/POST | `/quotes` (POST con Idempotency-Key) |
| GET | `/quotes/{id}` |
| POST | `/quotes/{id}/send` (email all'ospite; restituisce anche link e testo per WhatsApp) |
| POST | `/quotes/{id}/revoke` |

### Conflitti
| Metodo | Percorso |
|---|---|
| GET | `/conflicts?status=OPEN` |
| POST | `/conflicts/{id}/investigate` |
| POST | `/conflicts/{id}/resolve` (`resolution`, `notes`) |
| POST | `/conflicts/{id}/ignore` (`notes` obbligatorie) |

### Pagamenti e account
| Metodo | Percorso |
|---|---|
| GET | `/stripe/account` (stato onboarding) |
| POST | `/stripe/onboarding-link` |
| GET | `/metrics?from=&to=` (fatturato diretto, commissioni evitate, beneficio stimato, con percentuale usata) |
| GET | `/exports/reservations.csv?from=&to=` |

### Privacy (vedi 09)
| Metodo | Percorso |
|---|---|
| GET | `/guests/{id}/export` |
| POST | `/guests/{id}/erase` (anonimizzazione, se non soggetta a conservazione obbligatoria) |
| POST | `/tenant/export` (export completo per uscita cliente, asincrono) |
| POST | `/tenant/offboarding/start` (feed `FROZEN`, vendite chiuse; ri-autenticazione) |
| GET | `/tenant/offboarding` (stato dei feed, ultima lettura per OTA, conferme) |
| POST | `/tenant/offboarding/channels/{feedId}/confirm-removed` |

---

## Rate limit

| Superficie | Limite |
|---|---|
| Pubblica lettura | 120 req/min per IP |
| Pubblica creazione prenotazione/accettazione preventivo | 10 req/10 min per IP e per email ospite |
| Login | 5 tentativi/15 min per account e per IP |
| Admin | 300 req/min per utente |
| ICS export | 60 req/ora per token |

## Versionamento

Modifiche compatibili (campi aggiunti) restano in `v1`. Rimozioni o cambi di significato richiedono `v2` e una ADR. La specifica OpenAPI (`openapi.yaml`) viene generata dal codice e verificata in CI contro questo documento.
