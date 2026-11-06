# 05 — Pagamenti

Versione: 1.1 · Data: 06/11/2026 · Modifiche: ADR-0004, [CHANGELOG-v1.1](CHANGELOG-v1.1.md)

## Principi

1. I soldi degli ospiti vanno **direttamente sul conto Stripe della struttura** (direct charges). La piattaforma non li tocca.
2. Una prenotazione online si conferma solo da **webhook verificato o lettura API**, mai dal redirect del browser.
3. Ogni chiamata Stripe che modifica qualcosa ha una **idempotency key** derivata da un nostro ID.
4. Le chiamate Stripe sono **fuori** dalle transazioni del database: prima si persiste lo stato intermedio, poi si chiama Stripe, poi si registra l'esito. Ogni passo è recuperabile.
5. **Le scadenze sono persistite e condivise**: la scadenza dell'occupazione è sempre successiva alla scadenza della sessione di pagamento, e un'occupazione con sessione si rilascia solo dopo aver chiuso o verificato la sessione (I16).
6. Due relazioni Stripe separate: ospite → struttura (Connect) e struttura → piattaforma (abbonamento). Mai mescolate.

## Configurazione Stripe Connect

| Impostazione | Valore |
|---|---|
| Account connesso | merchant con dashboard Stripe completa (Accounts v2: `dashboard: full`) |
| Commissioni di pagamento | a carico della struttura (`fees_collector: stripe`) |
| Saldi negativi | `losses_collector: stripe` |
| Addebito | direct charges, header `Stripe-Account` |
| Commissione applicativa | 0% |
| Checkout | Stripe Checkout ospitato, sempre top-level |
| Metodi | solo carta |
| Valuta | EUR |

Finché `charges_enabled = false` la struttura accetta solo prenotazioni manuali.

Da verificare in sandbox (dipendenza esterna): configurazione disponibile per il nostro account piattaforma, manual capture su direct charges, 3D Secure, `checkout.sessions.expire` su sessioni del conto connesso.

## Creazione del checkout (comune a tutti i flussi online)

Sequenza obbligatoria:

| Passo | Dove | Cosa |
|---|---|---|
| 1 | transazione DB | `InventoryService::acquire` con `temp_reason = CHECKOUT` (o `PAYMENT_RECOVERY`) e **scadenza provvisoria** `now + 45 min`; crea `Reservation` (se nuova) e `Payment PENDING` |
| 2 | Stripe | `checkout.sessions.create` con idempotency key `checkout:{payment_id}` e `expires_at = now + 30 min` (minimo Stripe; per il recupero: `recovery_deadline`, massimo 24 h consentito da Stripe) |
| 3 | transazione DB | salva `checkout_session_id` e `checkout_expires_at` = valore **restituito da Stripe**; imposta `occupancy.expires_at = checkout_expires_at + 5 min` |

Garanzie:
- Dopo il passo 3: `occupancy.expires_at > checkout_expires_at` sempre.
- Se il passo 2 fallisce o è lento: il `Payment` resta senza sessione; alla scadenza provvisoria il job di rilascio **riprova la creazione con la stessa idempotency key** per scoprire se la sessione esiste. Se esiste la chiude con `expire`; se Stripe non l'ha mai creata, rilascia.
- Se il passo 3 fallisce dopo che Stripe ha creato la sessione: stesso recupero tramite idempotency key, oppure tramite il webhook che porta `metadata.payment_id`.
- La sessione porta `metadata: { reservation_id, payment_id, tenant_id }` e `payment_intent_data.metadata` uguale.

Parametri della sessione:
- `mode: payment`, `line_items` con importo = caparra o totale (04)
- `payment_intent_data.capture_method`: `manual` se la prenotazione è `REQUEST` e non è un recupero; `automatic` negli altri casi. **Deriva dalla prenotazione, mai dal frontend.**
- `locale` = lingua dell'ospite, `customer_email`
- `success_url` / `cancel_url` verso la pagina ospitata con `{CHECKOUT_SESSION_ID}`, mai segreti

## Rilascio delle occupazioni con sessione (job ogni minuto)

Per ogni occupazione `TEMPORARY` con `temp_reason ∈ {CHECKOUT, PAYMENT_RECOVERY}` e `expires_at < now`:

1. Se esiste una sessione: `checkout.sessions.expire` (idempotency key `expire:{payment_id}`).
   - Stripe conferma la chiusura, o la sessione era già scaduta → rilascio (T5): `Payment EXPIRED`, `Reservation EXPIRED`, occupazione `RELEASED`.
   - Stripe risponde che la sessione è **già completata** → **non** si rilascia: si legge il PaymentIntent e si applica T3 o T4 (T6).
   - Errore di rete → nessun rilascio, nuovo tentativo al giro successivo; alert se l'occupazione resta scaduta per oltre 30 min.
2. Se non esiste una sessione: recupero tramite idempotency key (sopra), poi come al punto 1.

Le occupazioni `AWAITING_APPROVAL` e `CAPTURE_FAILED` scadute seguono T11 e T16: il rilascio è immediato e l'annullo dell'autorizzazione avviene dopo, con retry. L'autorizzazione non ancora annullata non può più produrre incassi, perché la capture si richiede solo da `CAPTURING`.

Il webhook può arrivare dopo la scadenza: non si assume mai che arrivi prima. Se arriva dopo il rilascio, si applica T7 (rimborso o annullo automatici).

## Flusso A — Prenotazione immediata (`INSTANT`)

1. Checkout creato come sopra, `capture_method: automatic`.
2. L'ospite paga; la pagina di ritorno mostra "Stiamo confermando…" e interroga `GET /public/reservations/{code}`.
3. Webhook `checkout.session.completed` → lettura del PaymentIntent → `succeeded` e occupazione `ACTIVE` → `Payment SUCCEEDED`, T3 → `CONFIRMED`.

## Flusso B — Richiesta con pre-autorizzazione (`REQUEST`)

1. Checkout con `capture_method: manual`, importo = **solo la caparra** (o il totale se `FULL`).
2. Webhook → lettura PI:
   - `requires_capture` → `Payment AUTHORIZED`; si legge `capture_before` dalla Charge; T4 → `AWAITING_APPROVAL` con `approval_deadline = min(now + finestra struttura, capture_before − 6 h)`.
   - altro → resta `PENDING` fino a esito o rilascio.
3. Il gestore riceve la richiesta con il promemoria: **verificare Booking e Airbnb prima di approvare**.
4. **Approva** (T9) → `CAPTURING`, `Payment CAPTURE_PENDING` → job `paymentIntents.capture`, idempotency key `capture:{payment_id}`:
   - riuscita → `SUCCEEDED`, T12 → `CONFIRMED`
   - errore definitivo → `Payment CAPTURE_FAILED`, T13 → `CAPTURE_FAILED` (occupa ancora le date)
   - esito sconosciuto → T14, riconciliazione
5. **Rifiuta** (T10) o **scadenza** (T11) → rilascio, poi `paymentIntents.cancel` con idempotency key `cancel:{payment_id}`, retry con backoff; alert se dopo 24 h non è annullata.

## Flusso R — Recupero dopo capture fallita

1. Dal pannello il gestore sceglie "Invia link di pagamento" (T15) entro 24 h.
2. Nuovo `Payment` `purpose = RECOVERY`, `capture_method: automatic`, importo = caparra o totale previsto. Checkout creato con la sequenza standard e `expires_at = recovery_deadline` (default 24 h).
3. L'occupazione resta la stessa, con `temp_reason = PAYMENT_RECOVERY`.
4. Pagamento riuscito → T3 → `CONFIRMED`. Scadenza → rilascio con `expire` → `EXPIRED`.
5. In alternativa il gestore sceglie "Rilascia le date" (T16).

## Flusso C — Preventivo

Il preventivo accettato entra nel Flusso A o B in base al `booking_mode` congelato nel preventivo (salvo I12). Importo = caparra calcolata su `final_total_cents` congelato. Un secondo `accept` mentre il tentativo è attivo restituisce **lo stesso** `checkout_url` (03, macchina del preventivo).

## Flusso D — Prenotazioni manuali e saldo

- Il gestore può creare una prenotazione `MANUAL` già `CONFIRMED` (`confirmation_kind = ADMINISTRATIVE`) **anche senza pagamento**: lo stato economico è `UNPAID` e il pannello lo evidenzia. Può registrare uno o più `Payment OFFLINE` (`BANK_TRANSFER`, `CASH`, `POS`, `OTHER`).
- Il saldo di una prenotazione online si registra come `OFFLINE` oppure con un link di pagamento (`purpose: BALANCE`, capture automatica). Il link di saldo **non** crea occupazioni: le date sono già occupate dalla prenotazione.

## Cancellazioni e rimborsi

Solo il gestore cancella nella 1.0. Due esiti indipendenti:

| Aspetto | Comportamento |
|---|---|
| Prenotazione | `CANCELLED` subito (T17) |
| Disponibilità | date liberate subito, nella stessa transazione |
| Rimborso | `Refund PENDING` creato nella stessa transazione se l'importo è > 0; chiamata Stripe subito dopo |
| Comunicazione all'ospite | email "prenotazione cancellata, rimborso di X € **avviato**"; seconda email "rimborso completato" solo a `Refund SUCCEEDED` |
| Pannello | badge `refund_status`: in corso / completato / fallito |

Calcolo con lo snapshot della politica:

| Politica | Cancellazione | Rimborso proposto |
|---|---|---|
| `FLEXIBLE_7D` | ≥ 7 giorni prima del check-in (00:00 del giorno di arrivo, fuso della struttura) | 100% di quanto incassato online |
| `FLEXIBLE_7D` | < 7 giorni | 0% |
| `NON_REFUNDABLE` | sempre | 0% |

Il gestore può aumentare il rimborso fino al 100% dell'incassato online, mai oltre. I pagamenti `OFFLINE` non si rimborsano da Stripe: il gestore registra un `Refund` `OFFLINE`.

Errori: un rimborso fallito **non** riapre la prenotazione né rioccupa le date. Retry automatici (1 h, 6 h, 24 h), poi allerta al gestore con le azioni "Riprova" e "Segna come rimborsato fuori piattaforma" (03, macchina del rimborso).

Qualificazione giuridica dell'anticipo e testi delle politiche da validare legalmente prima del pilota.

## Webhook

Endpoint unico `POST /webhooks/stripe` (eventi Connect con campo `account`). Procedura obbligatoria:

1. Verifica firma. Non valida → `400`.
2. `WebhookEvent (provider, event_id)` unico; già `PROCESSED` → `200` senza effetti (I3).
3. Tenant da `event.account`; account sconosciuto → `IGNORED`.
4. `metadata.tenant_id` deve coincidere; altrimenti `FAILED` + allerta di sicurezza.
5. Accoda il job, risponde `200`.
6. Il job **rilegge l'oggetto via API** e applica solo transizioni ammesse.

| Evento | Uso |
|---|---|
| `checkout.session.completed` | esito iniziale |
| `checkout.session.expired` | conferma chiusura (il rilascio resta del job, I16) |
| `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled` | esiti |
| `charge.refunded`, `refund.updated` | rimborsi |
| `charge.dispute.created`, `charge.dispute.closed` | chargeback: allerta e `PaymentEvent` |
| `account.updated` | stato onboarding |

## Riconciliazione

Ogni 15 minuti (ogni 5 per `CAPTURING`):
- `Payment PENDING` senza sessione da oltre 5 min → recupero tramite idempotency key.
- `Payment AUTHORIZED` → PI ancora `requires_capture`, `capture_before` rispettato.
- `Payment CAPTURE_PENDING` → chiude l'esito.
- `Refund PENDING` da oltre 1 h → legge il rimborso.
- Occupazione `ACTIVE` con prenotazione terminale, o prenotazione occupante senza occupazione → allerta P1 (violazione di I15).

## Abbonamento SaaS

Pilota: fatturazione manuale. Dalla fase 2: Stripe Billing sull'account piattaforma.

## Test obbligatori (modalità test Stripe)

- Flusso A con carta standard e con 3D Secure.
- Flusso B: approva, rifiuta, scadenza; carta rifiutata in autorizzazione.
- Allineamento scadenze: sessione creata in ritardo, creazione Stripe fallita, crash tra passo 2 e 3 (recupero con idempotency key).
- Job di rilascio che trova la sessione già completata (T6).
- Webhook dopo il rilascio (T7) → rimborso o annullo automatici.
- Capture fallita → recupero pagato; recupero scaduto; nessuna decisione.
- Cancellazione con rimborso riuscito, fallito, segnato offline; nessuna email "completato" prima di `SUCCEEDED`.
- Prenotazione manuale `UNPAID`, poi pagamento offline registrato.
- Webhook duplicato, fuori ordine, firma errata, account sconosciuto, tenant non coerente.
