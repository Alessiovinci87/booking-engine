# Rapporto M4 — Pagamenti Stripe

Data: 07/11/2026 · Branch: `m4-pagamenti-stripe` (da `m3-inventario-prenotazioni`) · PR verso `m3-inventario-prenotazioni` senza merge

## Esito
Il codice è completo e verificato con Stripe simulato a livello HTTP; le verifiche contro Stripe vero sono rimandate a domattina. Tutte le attività 4.1–4.11 sono state fatte, con un commit per attività che riporta `closes #35`–`#45`; le issue si chiuderanno al merge su `main`. Tutti i flussi sono verificati con il client HTTP di stripe-php sostituito da un simulatore. Restano da eseguire contro Stripe in modalità test:
- la suite `tests/Stripe/*`;
- l'E2E `m4-stripe-checkout.spec.ts`.

Entrambe sono scritte e pronte. Come da istruzioni non le ho lanciate stanotte: vedi **Da fare domattina**.

Gli scostamenti dalla specifica sono precisazioni di casi che 05 lascia aperti, raccolte nell'**ADR-0012 (punti 1–21)**. Nessun comportamento concordato è cambiato. Ci sono due punti aperti, descritti più sotto: la ri-autenticazione sulla cancellazione con rimborso e la carta `refundFail`.

## Attività
| # | Stato | Issue | Commit | Note |
|---|---|---|---|---|
| 4.1 | Fatta | #35 | `ea98a5b` | Accounts v2 (`dashboard: full`, `merchant.card_payments`, fees/losses collector `stripe`) e Account Links v2 ospitati, senza versione preview. Comando `stripe:demo-account` (ora anche con `--account`). Stato dell'account riletto da Stripe. |
| 4.2 | Fatta | #36 | `6cd719f` | `StripePaymentGateway` e `CheckoutService` in tre passi con idempotency key `checkout:`/`capture:`/`cancel:`/`expire:`/`refund:` + ID. Scadenza della sessione con un margine di 120 s (ADR-0012 #6). |
| 4.3 | Fatta | #37 | `95cabcf` | `POST /webhooks/stripe`: verifica della firma, poi `WebhookEvent` unico (I3). Il tenant si ricava con la funzione SECURITY DEFINER `app_tenant_by_stripe_account` e si controlla `metadata.tenant_id`. Il job rilegge sempre l'oggetto via API. `WebhookEventStateMachine`. Le contestazioni producono un `PaymentEvent`, un'allerta e un'email al gestore. |
| 4.4 | Fatta | #38 | `4a2e0be` | Flusso A e Flusso B: approvazione con capture, rifiuto con annullo, scadenza dell'approvazione. |
| 4.5 | Fatta | #39 | `63a8cb2` | Rilascio con `checkout.sessions.expire`. Due casi gestiti: sessione già completata (T6) e pagamento tardivo con rimborso (T7). |
| 4.6 | Fatta | #40 | `8e5df80` | Flusso R: `CAPTURE_FAILED` con le date ancora occupate, link di recupero, rilascio a 24 h. Promemoria al gestore 12 h prima della scadenza. |
| 4.7 | Fatta | #41 | `1f3b0d5` | `RefundService`: tentativi a 1 h, 6 h e 24 h, ognuno con una chiave nuova; poi allerta ed email al gestore. "Riprova" e "Segna come rimborsato fuori piattaforma" (nota obbligatoria, mai nell'audit). |
| 4.8 | Fatta | #42 | `eb049cf` | `PaymentReconciler`: giro completo ogni 15 minuti, `CAPTURING` ogni 5. Il dispatcher usa `app_admin_ops`, ogni tenant ha il suo job. Allerta P1 per le violazioni di I15. |
| 4.9 | Fatta | #43 | `3eeb20f` | Pagina ospite già pronta da M3 (checkout nella finestra principale, polling ogni 2 s). Aggiunti i test con Stripe. |
| 4.10 | Fatta | #44 | `067d9b3`, `0e280bb` | Pannello `/app/pagamenti` con: stato Stripe e pulsante di onboarding; richieste da approvare con tempo rimasto e promemoria "verifica Booking e Airbnb prima di approvare"; pagamenti da recuperare; rimborsi con "Riprova" e "Segna come rimborsato fuori piattaforma"; avvisi di contestazione (`GET /admin/disputes`). Ri-autenticazione (08) sulle azioni sensibili, con richiesta della password nel pannello. |
| 4.11 | Fatta (da eseguire) | #45 | `83f871d` | Dieci test `tests/Stripe/*` (vedi Test). Workflow notturno `.github/workflows/stripe-nightly.yml`. E2E `tests/E2E/m4-stripe-checkout.spec.ts` con le carte 4242, 3155 (3D Secure) e 9995. |

Documentazione:
- ADR-0012, completata con i punti 9–21;
- `05-PAYMENTS-SPEC.md`: webhook, chiavi dei rimborsi, riconciliazione, `stripe listen`;
- `07-API-CONTRACT.md`: rimborsi, contestazioni, onboarding, `502 PAYMENT_PROVIDER_REJECTED`, ri-autenticazione;
- `10-TRACEABILITY.md`, aggiornata a fine M4;
- `docs/dev/SETUP-WINDOWS.md`, nuova sezione 9.1 "Pagamenti Stripe in modalità test".

## Test
**Criteri di uscita di M4**

| Criterio | Test con Stripe simulato (verdi) | Test con Stripe in modalità test (da eseguire) |
|---|---|---|
| AC2 (senza feed) | `Payments/StripeCheckoutTest`, `Payments/StripeFlowsTest` | `Stripe/InstantBookingFlowTest`, E2E `m4-stripe-checkout` |
| AC3 | `Payments/StripeFlowsTest` | `Stripe/RequestApproveCaptureTest` |
| AC4 | `ApprovalDeadlineTest`, `Payments/StripeFlowsTest` | `Stripe/RequestRejectTest` |
| AC9 | `RefundEmailTimingTest` | `Stripe/CancelRefundTest` |
| AC10 | `WebhookIdempotencyTest` (duplicati, fuori ordine, firma errata, account sconosciuto, tenant incoerente) | — |
| AC14 | `CaptureFailedTimeoutTest` | `Stripe/CaptureFailureRecoveryTest` |
| AC15 | `Payments/StripeReleaseTest` | `Stripe/ReleaseJobCompletedSessionTest` |
| AC19 | `RefundRetryTest` | `Stripe/RefundFailureTest` |
| I2 | `NoConfirmOnRedirectTest`, `Payments/GuestReturnPageTest` | — |
| I3 | `WebhookIdempotencyTest` | — |
| I4 | `Payments/StripeReleaseTest` | `Stripe/LatePaymentRefundTest` |
| I9 | `IdempotencyKeyTest`, chiavi verificate nei test con il simulatore | `Stripe/IdempotentRetryTest` |
| I16 | `Payments/StripeReleaseTest`, `Payments/PaymentReconciliationTest` | `Stripe/ReleaseJobCompletedSessionTest`, `Stripe/CheckoutCrashRecoveryTest` |
| I20 | `RefundEmailTimingTest` | — |

**Come funziona `tests/Stripe/*`.** Una Checkout Session non si può completare lato server. Per questo il pagamento dell'ospite è un PaymentIntent vero, confermato con una carta di prova sull'account connesso e con gli stessi metadata. Tutto il resto è reale: sessioni, expire, capture, annullo, rimborsi e riletture. Il Checkout vero è coperto dall'E2E. La suite:
- sta fuori da `composer test` (`phpunit.stripe.xml`);
- si attiva solo con `STRIPE_LIVE_TESTS=1`;
- senza questa variabile i 10 test risultano "skipped" (verificato).

**Simulatore.** `tests/Support/stripe_simulator.php` sostituisce il client HTTP di stripe-php con uno stato in memoria: sessioni, PaymentIntent, rimborsi, idempotency replay ed eventi firmati.

**Risultato in locale.** Tutti i test indicati nella tabella sono verdi. Il numero di test della suite completa è da aggiornare con l'ultimo giro.
- `composer test`: 611 test verdi all'ultimo giro completo;
- `composer lint`, `composer analyse` (Larastan livello 6);
- `npm run typecheck`, `npm run build`.

**Risultato CI.** Verde su tutti i push del branch fino a `83f871d` (run [42324003345](https://github.com/Alessiovinci87/booking-engine/actions/runs/42324003345): 611 test). Il workflow notturno non è ancora partito: vedi i punti aperti.

**Test aggiunti**
- Feature:
  - `Payments/StripeConnectOnboardingTest`, `Payments/StripeCheckoutTest`, `WebhookIdempotencyTest`;
  - `Payments/StripeFlowsTest`, `ApprovalDeadlineTest`, `Payments/StripeReleaseTest`;
  - `CaptureFailedTimeoutTest`, `RefundRetryTest`, `RefundEmailTimingTest`;
  - `Payments/PaymentReconciliationTest`, `Payments/GuestReturnPageTest`, `Payments/DisputeApiTest`.
- `tests/Stripe/*` (10 test) e `tests/E2E/m4-stripe-checkout.spec.ts`.
- Estesi: `Architecture/RlsCatalogTest` (`definer_lookup` su `stripe_accounts`), `Frontend/SpaRoutesTest`, `Reservations/ReservationTransitionsTest` e `Public/PublicReservationApiTest` (margine della sessione).

## Scostamenti dalla specifica
Tutti nell'**ADR-0012**, in sintesi:
1. **Account connessi.** Accounts v2 disponibili senza preview, quindi nessun ripiego su v1. Lo stato dell'account si ricava dalle capability. Onboarding con chiave `account:{tenant_id}`. Errori: `503` se Stripe non risponde, `502 PAYMENT_PROVIDER_REJECTED` se rifiuta.
2. **Checkout.**
   - Scadenza della sessione con un margine di 120 s: Stripe accetta `expires_at` solo da 30 minuti dopo la creazione, quindi la sessione dura 32 minuti.
   - Sessione mai creata → rilascio senza `expire`.
   - Una carta rifiutata dentro Stripe Checkout non è un fallimento definitivo (T8): Checkout fa riprovare l'ospite.
3. **Webhook.**
   - Gli oggetti senza i nostri metadata sono `IGNORED`.
   - Gli eventi `FAILED` si rielaborano alla consegna successiva.
   - Le contestazioni producono un `PaymentEvent`, un'email e un avviso nel pannello; gli stati non cambiano.
4. **Rimborsi.**
   - Chiave `refund:{id}` al primo tentativo, `refund:{id}:{n}` dopo un rifiuto definitivo.
   - Tentativi automatici: primo tentativo più 3 ripetizioni.
   - "Segna come rimborsato fuori piattaforma" salva la nota in `refunds.reason` e invia l'email di completamento.
5. **Flusso R e riconciliazione.**
   - Promemoria al gestore 12 h prima di `decision_deadline`.
   - Allerte: `CAPTURING` da oltre 1 h, autorizzazione non annullata dopo 24 h, `capture_before` vicina.
6. **Ri-autenticazione (08).** Applicata a "Riprova", "Segna come rimborsato" e al link di onboarding Stripe.

## Decisioni dopo la verifica di M3
- **Finestra di approvazione per struttura** (ADR-0012 punto 22): `properties.approval_window_hours` 1–48 h (predefinita 12), impostabile nella scheda della struttura nel pannello; la scadenza resta limitata a `capture_before − 6 h`. Test: `Payments/ApprovalWindowTest`.
- Branch riallineato a `main` dopo il merge di M3 (merge di `main`, nessuna riscrittura della storia); PR #70 ora verso `main`.
- ADR-0012 in stato "Proposta" fino alla verifica di M4 (nuova regola 2 di `CLAUDE.md`).

## Punti aperti e rischi
1. **Ri-autenticazione sulla cancellazione con rimborso.**
   - 08 chiede la password confermata per i rimborsi, ma `POST /reservations/{id}/cancel` (M3) non ha il middleware `reauth`.
   - Non l'ho aggiunto stanotte: cambierebbe il comportamento di un endpoint di M3 e del suo E2E.
   - Proposta: aggiungerlo quando l'importo da rimborsare è > 0, con la stessa richiesta della password già pronta nel pannello (`components/Reauth.tsx`). Decisione tua.
2. **Carta `refundFail` (4000 0000 0000 5126).**
   - Stripe documenta che con questa carta un rimborso può risultare prima `succeeded` e poi `failed`.
   - In quel caso il `RefundService` lo chiuderebbe come `SUCCEEDED` con l'email di completamento, e il passaggio successivo a `FAILED` non è previsto dalla macchina del rimborso (03).
   - `Stripe/RefundFailureTest` lo rivelerà al primo giro. Se succede serve una decisione (ADR) su `SUCCEEDED → FAILED`.
3. **Workflow notturno.**
   - GitHub esegue `schedule` e `workflow_dispatch` solo per i workflow presenti sul branch predefinito.
   - `stripe-nightly.yml` si attiverà dopo il merge su `main`; fino ad allora la suite si lancia in locale.
   - Variabile facoltativa del repository: `STRIPE_TEST_CONNECTED_ACCOUNT`.
4. **Pannello Pagamenti non provato nel browser.** Verificati typecheck, build e API. Da guardare domattina insieme all'E2E con Stripe.
5. **Versione della specifica** da incrementare alla chiusura di M4, dopo il merge (ADR-0012).

## Da fare domattina
Tutti i comandi si eseguono in `C:\dev\booking-engine`. Le chiavi restano solo in `.env`: non vanno incollate in chat, nei commit o nei log.

1. **Onboarding dell'account demo** (`.env` con `PAYMENT_GATEWAY=stripe` e chiavi `sk_test_`/`pk_test_`):
   ```powershell
   php artisan stripe:demo-account
   ```
   Apri il link stampato e completa l'onboarding con i dati di prova di Stripe. Annota l'ID `acct_…` stampato.
2. **Webhook in locale** (finestra separata):
   ```powershell
   stripe listen --forward-connect-to http://127.0.0.1:8000/webhooks/stripe
   stripe listen --print-secret
   ```
   Metti il segreto in `STRIPE_WEBHOOK_SECRET` nel file `.env`, poi riavvia `php artisan serve` e `php artisan queue:work`.
3. **Suite Stripe**:
   ```powershell
   $env:STRIPE_LIVE_TESTS='1'; php vendor/bin/pest -c phpunit.stripe.xml
   ```
4. **E2E con Stripe Checkout** (`npm run dev` fermo):
   ```powershell
   npm run build
   stripe listen --forward-connect-to http://127.0.0.1:8001/webhooks/stripe   # finestra separata, stesso segreto
   $env:E2E_PAYMENT_GATEWAY='stripe'; $env:E2E_STRIPE_ACCOUNT='acct_…'; npx playwright test m4-stripe-checkout
   ```
5. **Prova manuale del pannello** su http://127.0.0.1:8000/app/pagamenti:
   - stato dell'account;
   - una richiesta da approvare;
   - un rimborso fallito (con "Riprova" ti chiede la password).
6. **Decisioni**: punti aperti 1 e 2.
