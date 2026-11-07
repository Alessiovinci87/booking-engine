# 07 — Contratto API

Versione: 1.3 · Data: 06/11/2026 · Modifiche: [CHANGELOG-v1.1](CHANGELOG-v1.1.md); 1.2: ADR-0008, ADR-0009; 1.3: ADR-0010 (codici, rotte, avvisi e guardia dei pagamenti dopo M2; API pubblica senza CSRF, `UNIT_NOT_BOOKABLE`); 1.4: ADR-0013 (calendari, feed, conflitti e preventivi dopo M5)

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
| 403 | `FORBIDDEN` (anche per l'API pubblica chiamata da un'origine non ammessa, ADR-0011), `REAUTH_REQUIRED` |
| 404 | `NOT_FOUND` (anche per risorse di altri tenant: mai `403`) |
| 409 | `IDEMPOTENCY_KEY_IN_USE` (ADR-0011), `DATES_UNAVAILABLE`, `CHECKOUT_EXPIRED`, `CONFLICT_OPEN`, `ILLEGAL_TRANSITION`, `PRICE_CHANGED`, `MFA_ALREADY_ENABLED`, `EXTERNAL_CALENDARS_ACTIVE`, `UNIT_NOT_BOOKABLE`, `ICS_FEED_EXISTS`, `UNIT_IS_INSTANT`, `QUOTE_ALREADY_ACCEPTED`, `QUOTE_CHECKOUT_IN_PROGRESS` (ADR-0013), `GUEST_HAS_ACTIVE_RESERVATION` (ADR-0014) |
| 419 | `CSRF_TOKEN_EXPIRED` (la SPA richiede un nuovo cookie CSRF e ripete una volta) |
| 410 | `QUOTE_EXPIRED`, `QUOTE_REVOKED`, `QUOTE_EXHAUSTED`, `FEED_RETIRED` |
| 412 | `VERSION_MISMATCH` |
| 422 | `MIN_NIGHTS_NOT_MET`, `OCCUPANCY_EXCEEDED`, `AMOUNT_BELOW_MINIMUM`, `PAYMENTS_NOT_ENABLED`, `IDEMPOTENCY_KEY_REUSED`, `INVALID_ICAL_URL`, `CANCELLATION_POLICY_MISSING`, `PRIVACY_POLICY_MISSING`, `DPA_NOT_SIGNED` (ADR-0014), `NO_ACTIVE_UNITS`, `TRANSLATION_IT_MISSING`, `QUOTE_EMAIL_MISSING`, `CHECKLIST_STEP_INVALID` (ADR-0013) |
| 429 | `RATE_LIMITED` |
| 502 | `PAYMENT_PROVIDER_REJECTED` (richiesta rifiutata da Stripe; codice di Stripe in `details.provider_code`, ADR-0012) |
| 503 | `PAYMENT_PROVIDER_UNAVAILABLE` (Stripe non risponde) |

I messaggi `message` sono localizzati; il client si basa sempre su `code`.

---

## API pubblica (ospite)

Senza cookie né CSRF. Ogni richiesta con intestazione `Origin` deve provenire dall'origine dell'app o da una delle `embed_allowed_origins` del tenant, altrimenti `403 FORBIDDEN` (ADR-0011).

### `GET /public/{t}/{p}`
Struttura pubblicata: nomi e descrizioni nella lingua richiesta (con `translation_fallback: true` se manca), foto, orari, politiche, unità attive con capienza e foto, `privacy_policy_url`, `terms_url` e le rispettive versioni `privacy_version`, `terms_version` (primi 12 caratteri esadecimali dello SHA-256 dell'URL, ADR-0011). Una struttura pubblicata senza unità attive risponde con `units: []`: la pagina ospite mostra "Nessuna disponibilità al momento" (ADR-0010).

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

`guest.notes` è facoltativo. `accepted_privacy_version` è obbligatorio e deve coincidere con `privacy_version` della struttura; `accepted_terms_version` deve coincidere con `terms_version`, oppure essere `null` se la struttura non ha condizioni (altrimenti `400 VALIDATION_FAILED`, ADR-0011).

Il server ricalcola il prezzo; se diverso da `expected_total_cents` → `409 PRICE_CHANGED` con il nuovo prezzo in `details.price` (formato di `quote-price`). Risposta `201`:

```json
{ "reservation_code": "K7Q2MZ4A", "status": "AWAITING_PAYMENT", "checkout_url": "https://checkout.stripe.com/…", "checkout_expires_at": "…", "access_token": "…" }
```

`access_token` compare solo qui (nel database resta l'hash) e serve a `GET /public/reservations/{code}` (ADR-0011). Limite: 10 richieste ogni 10 minuti per IP e per email.

Il client apre `checkout_url` **in top-level** (`window.top.location` o nuova scheda se l'iframe non lo consente).

### `GET /public/reservations/{code}?token=`
Stato della prenotazione per l'ospite (riepilogo, stato leggibile, importi pagati e dovuti, politica). Usato dalla pagina di ritorno da Stripe con polling ogni 2 s fino a 60 s. Il token arriva nella risposta di `POST /reservations` e nell'email; viene confrontato con l'hash. Codice o token errati → `404 NOT_FOUND`, senza distinguere i due casi. La risposta non contiene dati personali dell'ospite né `checkout_url`; include `status`, `booking_mode`, struttura e unità, date, ospiti, `contract_total`, `deposit_due`, `balance_due`, `paid`, `outstanding`, `economic_status`, `refund_status`, `approval_deadline`, `confirmed_at`, `cancelled_at` e la politica di cancellazione congelata (ADR-0011).

### `GET /public/quotes/{token}`
Preventivo: unità, date, ospiti, righe, sconto, totale, scadenza, `booking_mode`. Scaduto → `410 QUOTE_EXPIRED`.

### `POST /public/quotes/{token}/accept` · Idempotency-Key
Body: dati ospite (precompilati), versioni di termini e privacy accettati. Blocca la riga del preventivo, controlla scadenza, tentativi e disponibilità; **non** ricalcola il prezzo (congelato). Risposta come `POST /reservations`.

Se esiste già un tentativo attivo (`IN_CHECKOUT`) ancora valido, restituisce `200` con **lo stesso** `reservation_code` e `checkout_url`, senza creare nulla (I21). Tentativi esauriti → `410 QUOTE_EXHAUSTED`. Il secondo accept risponde con `access_token: null`; un tentativo in corso non più recuperabile → `409 QUOTE_CHECKOUT_IN_PROGRESS`; preventivo già accettato → `409 QUOTE_ALREADY_ACCEPTED` (ADR-0013 punti 39–41).

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
| GET/PATCH | `/properties/{id}` | incluse traduzioni; `approval_window_hours` 1–48 (ADR-0012 punto 22) |
| POST | `/properties/{id}/publish` | richiede, nell'ordine: ≥ 1 unità attiva (`422 NO_ACTIVE_UNITS`), traduzione `it` (`422 TRANSLATION_IT_MISSING`), politica di cancellazione predefinita della struttura (`422 CANCELLATION_POLICY_MISSING`, ADR-0009), link all'informativa privacy (`422 PRIVACY_POLICY_MISSING`, ADR-0011), DPA firmato (`422 DPA_NOT_SIGNED`, ADR-0014), account Stripe del tenant con `charges_enabled` (`422 PAYMENTS_NOT_ENABLED`). Risponde con il codice della prima guardia non soddisfatta e l'elenco di tutte in `details.missing`; già pubblicata → `200` senza modifiche; archiviata → `409 ILLEGAL_TRANSITION`. Senza `Idempotency-Key` finché non c'è il meccanismo generico di I9: l'operazione è idempotente per natura (ADR-0010) |
| GET/POST | `/properties/{id}/units` | |
| GET/PATCH | `/units/{id}` | cambio a `INSTANT` rifiutato se ci sono calendari esterni attivi (`409 EXTERNAL_CALENDARS_ACTIVE`, `details.active_calendars`); disattivare l'ultima unità attiva di una struttura pubblicata è consentito (il pannello mostra un avviso, ADR-0010); `cancellation_policy_id` facoltativa, solo tra le politiche della stessa struttura |
| GET/POST | `/units/{id}/photos` | elenco e caricamento; lettura con URL firmato |
| PUT | `/units/{id}/photos/order` | corpo `photo_ids`; la prima foto è la copertina (ADR-0010) |
| DELETE | `/units/{id}/photos/{photoId}` | (ADR-0010) |
| GET/POST | `/units/{id}/ics-feeds` | un feed per destinazione (`BOOKING`, `AIRBNB`, `OTHER`), accoppiato al calendario importato dallo stesso canale; restituisce URL e ultima lettura |
| POST | `/ics-feeds/{id}/rotate` | nuovo token; il vecchio resta `FROZEN` 7 giorni. Creazione di un feed già esistente per la destinazione → `409 ICS_FEED_EXISTS` (ADR-0013 punti 24–29) |
| GET | `/units/{id}/calendar-checklist` | stato dei passi di collegamento a stella (06; ADR-0013 punto 35) |
| POST | `/units/{id}/calendar-checklist/confirm` | conferma del gestore di un passo manuale (`step` nel body; `422 CHECKLIST_STEP_INVALID`) |
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
| GET/POST | `/units/{id}/external-calendars` | validazione URL (06): `422 INVALID_ICAL_URL` con `details.reason`; unità `INSTANT` → `409 UNIT_IS_INSTANT` salvo conferma del passaggio a `REQUEST` (ADR-0013 punti 31–34) |
| PATCH/DELETE | `/external-calendars/{id}` | disattiva/rimuove |
| POST | `/external-calendars/{id}/sync-now` | max 1 ogni 2 min |
| GET | `/external-calendars/{id}/runs` | storico SyncRun |

### Prenotazioni
| Metodo | Percorso | Note |
|---|---|---|
| GET | `/reservations?status=&from=&to=&unit_id=` | |
| GET | `/reservations/{id}` | dettaglio, righe, pagamenti, rimborsi, storico, audit; `actions` (azioni ammesse nello stato attuale, dalla tabella delle transizioni) e, se `CONFIRMED`, `cancellation_refund` con rimborso proposto e massimo (ADR-0011) |
| POST | `/reservations` | manuale, confermata anche senza pagamento (stato economico `UNPAID`) · Idempotency-Key |
| POST | `/reservations/{id}/approve` | Idempotency-Key; `409 CONFLICT_OPEN` se ci sono conflitti sulle date |
| POST | `/reservations/{id}/reject` | Idempotency-Key, `reason` opzionale |
| POST | `/reservations/{id}/cancel` | Idempotency-Key; body `refund_cents` (default = proposto dalla policy, max = incassato online); rimborso > 0 → ri-autenticazione (08, `403 REAUTH_REQUIRED`, ADR-0012 punto 24). Risposta: prenotazione `CANCELLED` e `refund_status` (`PENDING`/`NONE`), mai "rimborsato" |
| POST | `/reservations/{id}/modify-preview` | anteprima della modifica: stesso body del `PATCH`, risponde `current`, `proposed` (righe e totale), `difference`, `version`; non salva nulla (ADR-0011 punto 28) |
| PATCH | `/reservations/{id}` | modifica date/ospiti/note · If-Match; `expected_total_cents` facoltativo dall'anteprima → `409 PRICE_CHANGED` se il prezzo è cambiato (ADR-0011 punto 28) |
| POST | `/reservations/{id}/offline-payments` | registra pagamento fuori piattaforma |
| POST | `/reservations/{id}/balance-link` | link di pagamento del saldo (nessuna occupazione nuova) · Idempotency-Key |
| POST | `/reservations/{id}/recovery-link` | solo da `CAPTURE_FAILED` (T15); body `deadline_hours` 1–24 · Idempotency-Key |
| POST | `/reservations/{id}/release-dates` | solo da `CAPTURE_FAILED` (T16) · Idempotency-Key |
| GET | `/refunds?status[]=FAILED&status[]=PENDING` | rimborsi da gestire, con prenotazione, tentativi e prossimo tentativo (ADR-0012) |
| POST | `/refunds/{id}/retry` | Idempotency-Key · ri-autenticazione (08, `403 REAUTH_REQUIRED`) |
| POST | `/refunds/{id}/mark-offline` | Idempotency-Key · ri-autenticazione (08); `note` obbligatoria (3–500 caratteri, dato personale: in `refunds.reason`, mai nell'audit); il rimborso diventa `SUCCEEDED` con metodo `OFFLINE` e parte l'email di completamento (ADR-0012) |
| GET | `/disputes` | contestazioni aperte (Stripe `charge.dispute.*`), con prenotazione e importo (ADR-0012) |

### Preventivi
| Metodo | Percorso |
|---|---|
| GET/POST | `/quotes` (POST con Idempotency-Key) |
| GET | `/quotes/{id}` |
| POST | `/quotes/{id}/send` (`channel` = `email` o `link`; restituisce `link`, `whatsapp_text`, `whatsapp_url`; `422 QUOTE_EMAIL_MISSING`, `410` se scaduto; ADR-0013 punti 36–37) |
| POST | `/quotes/{id}/revoke` |

### Conflitti
| Metodo | Percorso |
|---|---|
| GET | `/conflicts?status[]=OPEN` (ADR-0013 punto 20) |
| POST | `/conflicts/{id}/investigate` |
| POST | `/conflicts/{id}/resolve` (`resolution`, `notes`) |
| POST | `/conflicts/{id}/ignore` (`notes` obbligatorie) |
| POST | `/external-events/{id}/not-echo` (riclassifica un'eco sospetta come prenotazione vera; `409 ILLEGAL_TRANSITION` altrimenti; ADR-0013 punto 20) |

### Pagamenti e account
| Metodo | Percorso |
|---|---|
| GET | `/stripe/account` (stato onboarding: `NOT_CONNECTED`, `ONBOARDING_INCOMPLETE`, `IN_REVIEW`, `ENABLED`; `?refresh=1` rilegge da Stripe al rientro dall'onboarding, ADR-0012) |
| POST | `/stripe/onboarding-link` (ri-autenticazione, 08: collegamento o cambio dell'account Stripe) |
| GET | `/metrics?from=&to=` (fatturato diretto, commissioni evitate, beneficio stimato, con percentuale usata; check-in nel periodo, predefinito il mese corrente, massimo 366 giorni; ADR-0014 punti 7–10) |
| GET | `/exports/reservations.csv?from=&to=` (separatore `;`, BOM UTF-8, protezione dalle formule; registrato in audit; ADR-0014 punto 11) |

### Privacy (vedi 09)
| Metodo | Percorso |
|---|---|
| GET | `/guests/{id}/export` (JSON scaricabile; versione stampabile nel pannello; ADR-0014 punto 13) |
| POST | `/guests/{id}/erase` (anonimizzazione, se non soggetta a conservazione obbligatoria; ri-autenticazione; `409 GUEST_HAS_ACTIVE_RESERVATION`; ADR-0014 punti 14–16) |
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
