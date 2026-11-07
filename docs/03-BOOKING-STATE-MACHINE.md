# 03 — Macchine a stati

Versione: 1.1 · Data: 06/11/2026 · Modifiche: ADR-0002, ADR-0004, [CHANGELOG-v1.1](CHANGELOG-v1.1.md); 1.2: ADR-0012 (finestra di approvazione per struttura, rimborso `SUCCEEDED → FAILED`, ri-autenticazione dei rimborsi)

Cinque macchine distinte, ciascuna con il proprio campo `status`. Non vanno fuse in un unico enum.

| Macchina | Entità | Domanda |
|---|---|---|
| Prenotazione | `Reservation` | La prenotazione esiste e occupa le date? |
| Pagamento | `Payment` | Cosa è successo ai soldi di questo tentativo? |
| Rimborso | `Refund` | Il rimborso è arrivato all'ospite? |
| Preventivo | `Quote` | Il link è utilizzabile? |
| Conflitto | `InventoryConflict` | C'è una collisione da risolvere? |

L'occupazione (`InventoryOccupancy`) non ha una macchina propria: segue la prenotazione secondo la tabella "Occupazione per stato" e viene modificata solo da `InventoryService` (02).

Regole generali:
- Ogni transizione è un metodo di servizio esplicito, mai un `update(['status' => …])` libero.
- Ogni transizione scrive `AuditLog` e, se serve, `OutboxMessage` nella stessa transazione.
- Le transizioni causate da Stripe partono solo da un webhook verificato o da una lettura API, mai dal redirect del browser.
- Una transizione non prevista solleva `IllegalTransition` ed è un bug.
- Le chiamate a Stripe avvengono **fuori** dalle transazioni del database; lo stato intermedio è sempre persistito prima della chiamata.

---

## 1. Prenotazione (`Reservation.status`)

### Stati e occupazione

| Stato | Occupa? | `phase` / `temp_reason` | `expires_at` dell'occupazione | Terminale |
|---|---|---|---|---|
| `AWAITING_PAYMENT` | sì | `TEMPORARY` / `CHECKOUT` oppure `PAYMENT_RECOVERY` | scadenza sessione Stripe + 5 min | no |
| `AWAITING_APPROVAL` | sì | `TEMPORARY` / `AWAITING_APPROVAL` | `approval_deadline` | no |
| `CAPTURING` | sì | `TEMPORARY` / `CAPTURING` | null (non scade per tempo) | no |
| `CAPTURE_FAILED` | sì | `TEMPORARY` / `CAPTURE_FAILED` | `decision_deadline` = ingresso + 24 h | no |
| `CONFIRMED` | sì | `FIRM` | null | no |
| `REJECTED` | no | rilasciata | — | sì |
| `EXPIRED` | no | rilasciata | — | sì |
| `PAYMENT_FAILED` | no | rilasciata | — | sì |
| `CANCELLED` | no | rilasciata | — | sì |

**Ogni stato terminale ha l'occupazione rilasciata; ogni stato non terminale ha esattamente un'occupazione attiva** (invariante I15). Le due cose cambiano nella stessa transazione.

### Transizioni

| # | Da | Evento | Guardie | A | Effetti |
|---|---|---|---|---|---|
| T1 | — | `startCheckout` (ospite o preventivo) | date vendibili, prezzo dal server | `AWAITING_PAYMENT` | `acquire` occupazione `CHECKOUT` con scadenza provvisoria now + 45 min; `Payment PENDING`; poi creazione sessione Stripe e allineamento scadenze (05) |
| T2 | — | `createManual` (gestore) | date vendibili | `CONFIRMED` | `acquire` `FIRM`; `confirmation_kind = ADMINISTRATIVE`; `Payment OFFLINE` facoltativo; economico `UNPAID` ammesso |
| T3 | `AWAITING_PAYMENT` | pagamento riuscito, e (`INSTANT` oppure `purpose = RECOVERY`) | occupazione `ACTIVE` | `CONFIRMED` | `promoteToFirm`; `confirmation_kind = ONLINE_PAYMENT`; email conferma |
| T4 | `AWAITING_PAYMENT` | autorizzazione riuscita (`requires_capture`), `REQUEST` | occupazione `ACTIVE` | `AWAITING_APPROVAL` | `setTemporaryReason(AWAITING_APPROVAL, approval_deadline)`; email a gestore e ospite |
| T5 | `AWAITING_PAYMENT` | job di rilascio: occupazione scaduta | sessione Stripe chiusa con `expire` o già scaduta | `EXPIRED` | `release`; email ospite se aveva lasciato email |
| T6 | `AWAITING_PAYMENT` | job di rilascio: la sessione risulta **già completata** | — | come T3 o T4 | il job tratta l'esito come un webhook (I16) |
| T7 | `AWAITING_PAYMENT` | pagamento/autorizzazione arrivati **dopo** il rilascio | occupazione già `RELEASED` | resta `EXPIRED` | rimborso automatico o annullo autorizzazione; `AuditLog late_payment`; email di scuse (I4) |
| T8 | `AWAITING_PAYMENT` | pagamento fallito definitivo | — | `PAYMENT_FAILED` | `release` (dopo `expire` della sessione) |
| T9 | `AWAITING_APPROVAL` | `approve` | `now < approval_deadline`, nessun conflitto aperto sulle date | `CAPTURING` | `setTemporaryReason(CAPTURING, null)`; job capture con idempotency key |
| T10 | `AWAITING_APPROVAL` | `reject` | — | `REJECTED` | `release`; annullo autorizzazione (retry idempotente); email ospite |
| T11 | `AWAITING_APPROVAL` | job: `approval_deadline` superata | stato invariato | `EXPIRED` | `release`; annullo autorizzazione; email ospite e gestore |
| T12 | `CAPTURING` | capture riuscita | — | `CONFIRMED` | `promoteToFirm`; `confirmation_kind = ONLINE_PAYMENT`; email conferma |
| T13 | `CAPTURING` | capture fallita definitiva | — | `CAPTURE_FAILED` | `setTemporaryReason(CAPTURE_FAILED, now + 24 h)`; notifica urgente al gestore |
| T14 | `CAPTURING` | esito sconosciuto | — | `CAPTURING` | nessun rilascio; riconciliazione ogni 5 min; alert dopo 1 h |
| T15 | `CAPTURE_FAILED` | `sendRecoveryLink` (gestore) | `now < decision_deadline` | `AWAITING_PAYMENT` | nuovo `Payment` `purpose = RECOVERY`, capture automatica; `setTemporaryReason(PAYMENT_RECOVERY, recovery_deadline + 5 min)`; email all'ospite con il link |
| T16 | `CAPTURE_FAILED` | `releaseDates` (gestore) o job a `decision_deadline` | — | `PAYMENT_FAILED` | `release`; email ospite |
| T17 | `CONFIRMED` | `cancel` (gestore) | — | `CANCELLED` | `release` subito; crea `Refund` se dovuto (05); email "cancellazione e rimborso **avviato**" |
| T18 | `CONFIRMED` | `modify` (gestore) | nuove date vendibili escludendo sé stessa | `CONFIRMED` | `InventoryService::move` sulla stessa occupazione; `ReservationChange`; differenze economiche gestite a parte |

Note:
- **Recupero dopo capture fallita** (T15): resta la **stessa prenotazione** e la stessa occupazione. Il link ha scadenza `recovery_deadline` (da 1 a 24 h, default 24 h: 24 h è la durata massima di una sessione Stripe Checkout; sempre entro il giorno prima del check-in). Se l'ospite paga → T3. Se il link scade → T5 → `EXPIRED`. Un solo recupero per prenotazione.
- Se `CAPTURE_FAILED` non riceve decisioni, alla `decision_deadline` il job applica T16. Il gestore riceve una notifica all'ingresso e un promemoria a 12 h.
- Il gestore **non può** cancellare in `CAPTURING`: deve attendere esito o riconciliazione.
- Un chargeback non cambia lo stato della prenotazione: allerta + `PaymentEvent`.
- Un conflitto aperto su una prenotazione `CONFIRMED` non ne cambia lo stato.
- `CANCELLED` non dice nulla sul rimborso: lo stato del rimborso è `refund_status` derivato dai `Refund` (02).

### Timeout

| Timer | Default | Configurabile | Note |
|---|---|---|---|
| Occupazione di checkout | scadenza sessione Stripe + 5 min | no | sessione Stripe 30 min (minimo Stripe); vedi 05 per l'allineamento |
| Scadenza provvisoria prima della sessione | creazione + 45 min | no | se la sessione non viene creata, il job rilascia |
| Finestra di approvazione | 12 h | 1–48 h per struttura (`properties.approval_window_hours`, ADR-0012 punto 22) | sempre ≤ `capture_before − 6 h` |
| Decisione dopo capture fallita | 24 h | no | promemoria a 12 h |
| Link di recupero | 24 h | 1–24 h, entro il giorno prima del check-in | limite massimo di una sessione Stripe |
| Job di rilascio | ogni minuto | — | |
| Riconciliazione `CAPTURING` | ogni 5 min | — | alert dopo 1 h |

---

## 2. Pagamento (`Payment.status`)

| Stato | Significato |
|---|---|
| `PENDING` | sessione da creare o creata, nessun esito |
| `AUTHORIZED` | `requires_capture` (solo `MANUAL`) |
| `CAPTURE_PENDING` | capture richiesta |
| `SUCCEEDED` | incassato |
| `CAPTURE_FAILED` | capture rifiutata |
| `AUTHORIZATION_CANCELLED` | autorizzazione annullata o scaduta |
| `FAILED` | pagamento non riuscito |
| `EXPIRED` | sessione chiusa senza pagamento |
| `PARTIALLY_REFUNDED`, `REFUNDED` | dopo rimborsi riusciti |

| Da | Evento | A |
|---|---|---|
| `PENDING` | sessione completata + PI `requires_capture` | `AUTHORIZED` |
| `PENDING` | sessione completata + PI `succeeded` | `SUCCEEDED` |
| `PENDING` | sessione scaduta o chiusa con `expire` | `EXPIRED` |
| `PENDING` | fallimento definitivo | `FAILED` |
| `AUTHORIZED` | capture richiesta | `CAPTURE_PENDING` |
| `AUTHORIZED` | annullo confermato / `payment_intent.canceled` | `AUTHORIZATION_CANCELLED` |
| `CAPTURE_PENDING` | capture riuscita | `SUCCEEDED` |
| `CAPTURE_PENDING` | errore definitivo | `CAPTURE_FAILED` |
| `SUCCEEDED` | rimborso parziale riuscito | `PARTIALLY_REFUNDED` |
| `SUCCEEDED`, `PARTIALLY_REFUNDED` | rimborsi a saldo zero | `REFUNDED` |
| `PARTIALLY_REFUNDED`, `REFUNDED` | un rimborso riuscito dichiarato fallito da Stripe (ADR-0012 punto 23) | `SUCCEEDED`, `PARTIALLY_REFUNDED` o `REFUNDED` secondo il rimborsato rimasto |

Un `Payment` `OFFLINE` nasce `SUCCEEDED`.

---

## 3. Rimborso (`Refund.status`)

| Da | Evento | A | Effetti |
|---|---|---|---|
| — | creazione (cancellazione o rimborso manuale) | `PENDING` | chiamata Stripe con idempotency key `refund:{id}` |
| `PENDING` | `refund.updated` succeeded / lettura API | `SUCCEEDED` | email "rimborso completato"; aggiorna `Payment` |
| `PENDING` | errore definitivo | `FAILED` | retry automatico fino a 3 volte (1 h, 6 h, 24 h), poi allerta al gestore |
| `FAILED` | `retry` (gestore) | `PENDING` | nuova chiamata, stessa idempotency key se Stripe non ha creato il rimborso, nuova altrimenti |
| `FAILED` | `markOffline` (gestore) | `SUCCEEDED` | `method = OFFLINE`, nota obbligatoria (es. bonifico) |
| `PENDING` | `refund.updated` canceled | `CANCELLED` | allerta al gestore |
| `SUCCEEDED` | evento di Stripe sul rimborso (`refund.updated`, `refund.failed`) riletto via API con esito `failed` (ADR-0012 punto 23) | `FAILED` | ammesso **solo** su comunicazione di Stripe, mai per decisione locale; rimborsato del `Payment` e `refund_status` ricalcolati; allerta urgente ed email al gestore ("il rimborso a X non è andato a buon fine: contatta l'ospite"); **nessuna** email automatica all'ospite e nessun tentativo automatico; il gestore può usare `retry` o `markOffline` |

Ogni rimborso dal pannello (cancellazione con rimborso > 0, `retry`, `markOffline`) richiede la ri-autenticazione (08, ADR-0012 punto 24).

---

## 4. Preventivo (`Quote.status`)

| Stato | Significato |
|---|---|
| `DRAFT` | creato, non inviato |
| `SENT` | link attivo |
| `IN_CHECKOUT` | un tentativo di prenotazione è attivo (`active_reservation_id`) |
| `ACCEPTED` | l'ospite ha pagato o autorizzato: la prenotazione è `AWAITING_APPROVAL`, `CAPTURING` o `CONFIRMED` |
| `EXPIRED` | scaduto senza accettazione |
| `REVOKED` | ritirato dal gestore |
| `EXHAUSTED` | raggiunto il numero massimo di tentativi |

| Da | Evento | Guardie | A |
|---|---|---|---|
| — | creazione | — | `DRAFT` |
| `DRAFT` | invio / copia link | — | `SENT` |
| `SENT` | `accept` | lock della riga, `now < expires_at`, `checkout_attempts < max`, date vendibili | `IN_CHECKOUT` (crea prenotazione T1, `checkout_attempts + 1`) |
| `IN_CHECKOUT` | `accept` di nuovo (doppio clic, seconda scheda) | tentativo attivo ancora valido | `IN_CHECKOUT` — restituisce **lo stesso** checkout, nessuna nuova prenotazione |
| `IN_CHECKOUT` | prenotazione → `AWAITING_APPROVAL` o `CONFIRMED` | — | `ACCEPTED` |
| `IN_CHECKOUT` | prenotazione → `EXPIRED` / `PAYMENT_FAILED` prima del pagamento | tentativi < max | `SENT` (o `EXPIRED` se scaduto) |
| `IN_CHECKOUT` | come sopra | tentativi = max | `EXHAUSTED` |
| `DRAFT`, `SENT` | `expires_at` superato | — | `EXPIRED` |
| `DRAFT`, `SENT`, `IN_CHECKOUT` | `revoke` | se `IN_CHECKOUT`, il tentativo attivo prosegue fino al suo esito | `REVOKED` |

Regole:
- **Prezzo congelato**: il checkout usa `final_total_cents` e `price_lines` del preventivo, senza ricalcolo. Si verificano solo disponibilità, scadenza e capienza.
- La scadenza del preventivo si verifica **all'avvio** del checkout; un checkout avviato prima della scadenza può completarsi.
- `booking_mode` e `payment_mode` sono quelli congelati nel preventivo; se nel frattempo l'unità ha aggiunto un calendario esterno, prevale `REQUEST` (I12).
- Se la prenotazione nata dal preventivo viene poi rifiutata o la sua approvazione scade, il preventivo resta `ACCEPTED`: per riproporre le date il gestore emette un nuovo preventivo.

---

## 5. Conflitto (`InventoryConflict.status`)

| Da | Evento | A | Note |
|---|---|---|---|
| — | rilevamento (06) | `OPEN` | notifica immediata |
| `OPEN` | "prendo in carico" | `INVESTIGATING` | |
| `OPEN`, `INVESTIGATING` | risolve | `RESOLVED` | `resolution` obbligatoria |
| `OPEN`, `INVESTIGATING` | ignora | `IGNORED` | note obbligatorie |
| `OPEN` | evento esterno rimosso con conferma (06) | `RESOLVED` | nota automatica, notifica |

La chiusura rimuove solo il blocco del conflitto (I13).

---

## Diagramma prenotazione

```
                    startCheckout                 createManual
                         │                             │
                         ▼                             │
     ┌──────────── AWAITING_PAYMENT ◀──recovery link── CAPTURE_FAILED ──release/24h──▶ PAYMENT_FAILED
     │  scaduta /      │       │                            ▲
     │  fallita        │       │ REQUEST: autorizzata        │ capture fallita
     ▼                 │       ▼                             │
 EXPIRED /             │  AWAITING_APPROVAL ──reject──▶ REJECTED
 PAYMENT_FAILED        │       │      └──deadline──▶ EXPIRED
                       │       │ approve
                       │       ▼
                       │   CAPTURING ───────────────────────┘
       INSTANT o       │       │ capture OK
       recovery pagato ▼       ▼
                     CONFIRMED ◀────────────────────────────┘(manuale)
                       │  ▲
                cancel │  └─ modify
                       ▼
                   CANCELLED   (+ Refund: PENDING → SUCCEEDED / FAILED)
```

## Test minimi derivati

- Tabella transizioni T1–T18: un test per riga; test property-based su tutte le coppie (stato, evento) non ammesse.
- I15: dopo ogni transizione, verifica che stato e occupazione siano coerenti.
- Concorrenza: 20 checkout paralleli sulle stesse notti → una sola occupazione.
- Webhook duplicato, fuori ordine, in ritardo dopo il rilascio (T7); sessione completata scoperta dal job di rilascio (T6).
- Capture fallita → `CAPTURE_FAILED` occupa le date → recupero pagato → `CONFIRMED`; recupero scaduto → `EXPIRED`; nessuna decisione → `PAYMENT_FAILED` a 24 h.
- Cancellazione con rimborso che fallisce: date libere, `refund_status = FAILED`, nessuna email "rimborsato".
- Preventivo: due `accept` paralleli → un solo tentativo; superamento dei tentativi → `EXHAUSTED`; tariffe cambiate → prezzo invariato.
