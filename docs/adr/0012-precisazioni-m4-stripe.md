# ADR-0012 — Precisazioni dei pagamenti Stripe dopo M4

- **Stato**: Proposta (da accettare con la verifica di M4, regola 2 di `CLAUDE.md`)
- **Data**: 2026-11-07
- **Autori**: Alessio Vinci, Claude Code
- **Documenti di specifica toccati**: 05-PAYMENTS-SPEC, 07-API-CONTRACT, 08-SECURITY-OPERATIONS

## Contesto

L'integrazione con Stripe (M4) ha incontrato punti che 05 lascia aperti o che dipendono dal comportamento reale dell'API di Stripe. Questa ADR li fissa. Nessun punto cambia un comportamento concordato: le scelte riempiono vuoti e restano coerenti con gli invarianti I2, I3, I4, I9, I16 e I20.

## Decisione

### Account connessi (4.1)

1. **Accounts v2.** stripe-php 22 (API `2026-10-28.endive`) espone Accounts v2 e Account Links v2 senza versione "preview": si usano come in 05 (`dashboard: full`, configurazione `merchant` con `card_payments`, `fees_collector` e `losses_collector` = `stripe`, valuta EUR, paese da `STRIPE_CONNECT_COUNTRY`, default `it`). Non serve il ripiego sugli account v1 Standard.
2. **Corrispondenza con `StripeAccount`.** `charges_enabled` = capability `card_payments` attiva; `payouts_enabled` = capability `stripe_balance.payouts` attiva; `details_submitted` = nessun requisito `currently_due` o `past_due`. Lo stato si rilegge sempre da Stripe (evento dell'account o rientro dall'onboarding con `GET /admin/stripe/account?refresh=1`).
3. **Idempotency key `account:{tenant_id}`** sulla creazione dell'account: un doppio clic o una richiesta ripetuta ritrovano lo stesso account. Metadata dell'account: solo `tenant_id`.
4. **Errori dell'onboarding**: Stripe irraggiungibile → `503 PAYMENT_PROVIDER_UNAVAILABLE`; richiesta rifiutata da Stripe (configurazione della piattaforma, parametri) → `502 PAYMENT_PROVIDER_REJECTED` con il codice di Stripe in `details.provider_code`.
5. **`php artisan stripe:demo-account`** sostituisce l'account finto `acct_demo_…` del seeder (ADR-0010 punto 5) con un account connesso vero in modalità test e stampa il link di onboarding. Rifiuta il gateway `fake` e le chiavi `sk_live_`.

### Checkout (4.2)

6. **Margine di 120 secondi sulla scadenza della sessione.** Stripe accetta `expires_at` solo da 30 minuti dopo la creazione della sessione, che arriva qualche istante dopo il passo 1 di 05. La scadenza resta deterministica (creazione del `Payment` + 30 min + 120 s, `payments.checkout_session_margin_seconds`), così la stessa idempotency key ripete la stessa richiesta. L'occupazione resta allineata: scadenza della sessione restituita da Stripe + 5 min.
7. **Sessione mai creata.** Se il recupero con la stessa idempotency key viene rifiutato da Stripe (per esempio perché `expires_at` è ormai passato), la sessione non è mai esistita: una sessione già creata sarebbe restituita dalla chiave. Il job di rilascio applica T5 senza `expire`.
8. **Carta rifiutata dentro Stripe Checkout.** Un rifiuto della carta lascia la sessione aperta e Stripe Checkout fa riprovare l'ospite: non è il "pagamento fallito definitivo" di T8. Con Stripe `paymentFailed` è vero solo per un PaymentIntent annullato; altrimenti la prenotazione resta `AWAITING_PAYMENT` fino al pagamento o alla scadenza (T5). Il gateway finto conserva il comportamento di M3 (rifiuto = T8) per i test della macchina a stati.

### Webhook (4.3)

9. **Oggetti non nostri.** Eventi `checkout.session.*` e `payment_intent.*` senza `metadata.tenant_id` (per esempio un link di pagamento creato dal gestore nella sua dashboard Stripe) → `IGNORED` con motivo `foreign_object`. Il controllo di `metadata.tenant_id` (passo 4 di 05) si applica quando il campo è presente; rimborsi, contestazioni e account si riconducono al tenant tramite l'account connesso e i nostri ID.
10. **Riconsegna di un evento `FAILED` o `RECEIVED`**: si accoda di nuovo il job (il job rilegge sempre via API, quindi una seconda elaborazione è innocua). Solo `PROCESSED` e `IGNORED` sono finali (I3). Gli stati del `WebhookEvent` si scrivono solo tramite `WebhookEventStateMachine`.
11. **Job del webhook**: 6 tentativi con backoff 30 s → 1 h; dal terzo tentativo fallito un'allerta (08). In `last_error` solo il nome della classe dell'errore.
12. **Contestazioni**: `PaymentEvent` `dispute_created` / `dispute_closed` (unico per contestazione), allerta, email al gestore (`reservation.dispute_opened`) e avviso nel pannello (`GET /admin/disputes`). Stati di prenotazione e pagamento invariati: la risposta si gestisce nella dashboard Stripe.

### Rimborsi (4.7)

13. **Chiavi dei tentativi.** Primo tentativo `refund:{refund_id}`; dopo un rifiuto definitivo il tentativo successivo usa `refund:{refund_id}:{n}` (n = tentativi già fatti), perché Stripe restituirebbe lo stesso rifiuto per la stessa chiave. Con esito sconosciuto (timeout, 5xx) si riusa la stessa chiave.
14. **Tentativi automatici** = primo tentativo + 3 ripetizioni (1 h, 6 h, 24 h). Dopo l'ultimo: log di errore ed email al gestore (`reservation.refund_failed`), una per serie di tentativi.
15. **"Segna come rimborsato fuori piattaforma"**: nota obbligatoria (3–500 caratteri) salvata in `refunds.reason` (campo già personale, ADR-0009), nell'audit solo `[personal]`; il rimborso diventa `SUCCEEDED` con metodo `OFFLINE` e parte l'email "rimborso completato", perché per l'ospite il rimborso è avvenuto.
16. **"Riprova"** dal pannello: `FAILED → PENDING`, azzera `stripe_refund_id` e richiama Stripe con una chiave nuova. "Riprova", "Segna come rimborsato fuori piattaforma" e il link di onboarding Stripe richiedono la password confermata negli ultimi 15 minuti (08, middleware `reauth`, `403 REAUTH_REQUIRED`); il pannello chiede la password e ripete l'azione. La cancellazione con rimborso (M3, `POST /reservations/{id}/cancel`) non è ancora protetta: punto aperto nel rapporto di M4.

### Flusso R e riconciliazione (4.6, 4.8)

17. **Promemoria di `CAPTURE_FAILED`** al gestore 12 ore prima di `decision_deadline`, tramite outbox con `available_at`; non parte se la prenotazione non è più in `CAPTURE_FAILED`.
18. **Allerte della riconciliazione**: `CAPTURING` da oltre 1 h (T14), autorizzazione di una prenotazione chiusa ancora da annullare dopo 24 h (con nuovo annullo), `capture_before` entro 6 h con richiesta da approvare. Nei log solo ID.

### Pannello e test (4.10, 4.11)

19. **Pagina Pagamenti** (`/app/pagamenti`): stato Stripe e onboarding, richieste da approvare con tempo rimasto e promemoria "verifica Booking e Airbnb prima di approvare", pagamenti da recuperare, rimborsi da gestire, contestazioni aperte.
20. **Suite `Stripe/*`**: una Checkout Session non si completa lato server; il pagamento dell'ospite è un PaymentIntent reale con carta di prova sull'account connesso e gli stessi metadata, presentato come esito della sessione. Tutte le altre chiamate sono reali. La suite è fuori dalla suite predefinita (`phpunit.stripe.xml`) e si attiva con `STRIPE_LIVE_TESTS=1`; gira ogni notte (`.github/workflows/stripe-nightly.yml`). Il Checkout vero è coperto dall'E2E `m4-stripe-checkout.spec.ts`.
21. **`stripe:demo-account --account=acct_…`** collega un account connesso già completato al tenant demo (database rigenerato, E2E).

### Decisioni dopo la verifica di M3

22. **Finestra di approvazione per struttura** (03 "Tempi"): nuova colonna `properties.approval_window_hours` (smallint, predefinita 12, vincolo 1–48; migration `2027_01_04_000200`), impostabile dal pannello nella scheda della struttura (`PATCH /admin/properties/{id}`, `400 VALIDATION_FAILED` fuori intervallo). All'autorizzazione (T4) `approval_deadline` = ora + finestra della struttura, ma mai oltre `capture_before − 6 h` (`ReservationStateMachine::approvalDeadline`). Sostituisce la configurazione globale `payments.approval_window_hours`. La finestra vale per le autorizzazioni successive al cambio: le richieste già in attesa mantengono la loro scadenza.

### Decisioni dopo la verifica di M4

23. **Rimborso `SUCCEEDED → FAILED`** (03 sezione 3): ammesso solo quando lo comunica Stripe, cioè per un evento sul singolo rimborso (`refund.updated`, `refund.failed`, `charge.refund.updated`) riletto via API con esito `failed` e lo stesso `stripe_refund_id`; mai da `charge.refunded`, dalla riconciliazione periodica o per decisione locale, e solo per rimborsi con metodo `STRIPE`. Effetti nella stessa transazione: rimborso `FAILED` senza tentativi automatici (`next_attempt_at` nullo, `completed_at` azzerato, audit con `reported_by = stripe`); rimborsato del `Payment` ricalcolato (`PaymentStateMachine::revertRefund`, evento `refund_reversed`), quindi `refund_status` derivato; log `critical` `payments.refund_reversed` e outbox `reservation.refund_reversed` → email urgente al gestore con nome dell'ospite e importo ("il rimborso a X non è andato a buon fine: contatta l'ospite"); nessuna email automatica all'ospite, che aveva già ricevuto "rimborso completato". Il gestore può usare "Riprova" o "Segna come rimborsato fuori piattaforma".
24. **Ri-autenticazione dei rimborsi** (08): `POST /admin/reservations/{id}/cancel` con rimborso effettivo > 0 (anche quello proposto dalla politica quando `refund_cents` manca) → `403 REAUTH_REQUIRED` se la password non è stata confermata negli ultimi 15 minuti, prima di qualsiasi scrittura; senza rimborso nessuna richiesta. "Riprova" e "Segna come rimborsato fuori piattaforma" la richiedevano già. Il pannello mostra la richiesta della password e ripete la cancellazione dopo la conferma; ogni tentativo usa una `Idempotency-Key` nuova, quindi il 403 memorizzato non viene rigiocato.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Account v1 Standard | Accounts v2 è disponibile senza preview in stripe-php 22; v1 resta solo come ripiego |
| Scadenza della sessione calcolata al momento della chiamata | Cambierebbe i parametri a ogni tentativo: Stripe rifiuta una idempotency key riusata con parametri diversi, e il recupero di 05 non funzionerebbe |
| Chiudere la sessione al primo rifiuto della carta | L'ospite perderebbe le date per un errore di digitazione o un 3D Secure non riuscito, mentre Stripe Checkout gli permette di riprovare |
| Stessa chiave `refund:{id}` per tutti i tentativi | Stripe restituirebbe il rifiuto memorizzato: i tentativi automatici non servirebbero |
| Rifiutare gli eventi senza metadata con `FAILED` e allerta | Un link di pagamento creato dal gestore nella sua dashboard genererebbe allerte di sicurezza inutili |
| Completare il Checkout nei test `Stripe/*` con un browser | Lento e fragile per una suite notturna; il Checkout vero resta coperto dall'E2E |

## Conseguenze

- La sessione di checkout dura 32 minuti invece di 30; l'occupazione 37 invece di 35.
- Nuovo endpoint `GET /admin/disputes`; nuovo codice di errore `502 PAYMENT_PROVIDER_REJECTED` (07).
- Nuova funzione SECURITY DEFINER `app_tenant_by_stripe_account` con policy `definer_lookup` su `stripe_accounts` (eccezione già prevista in 02 per `stripe_accounts.stripe_account_id`).

## Impatto sui test

- Test aggiunti: `Payments/ApprovalWindowTest` (punto 22), `Payments/RefundReversedTest` e `Stripe/RefundFailureTest` (punto 23), `Payments/RefundReauthTest` ed E2E `m3-guest-booking` (punto 24), `Payments/StripeConnectOnboardingTest`, `Payments/StripeCheckoutTest`, `WebhookIdempotencyTest`, `Payments/StripeFlowsTest`, `ApprovalDeadlineTest`, `Payments/StripeReleaseTest`, `CaptureFailedTimeoutTest`, `RefundRetryTest`, `RefundEmailTimingTest`, `Payments/PaymentReconciliationTest`, `Payments/GuestReturnPageTest`, `Payments/DisputeApiTest`, `Stripe/*` (dieci test), `E2E/m4-stripe-checkout.spec`.
- Test modificati: `Reservations/ReservationTransitionsTest` e `Public/PublicReservationApiTest` (scadenze con il margine).

## Aggiornamento della specifica

- [x] 05-PAYMENTS-SPEC e 07-API-CONTRACT aggiornati con riferimento a questa ADR
- [ ] Versione della specifica incrementata (alla chiusura di M4, dopo il merge)
