# ADR-0012 — Precisazioni dei pagamenti Stripe dopo M4

- **Stato**: Accettata
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

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Account v1 Standard | Accounts v2 è disponibile senza preview in stripe-php 22; v1 resta solo come ripiego |
| Scadenza della sessione calcolata al momento della chiamata | Cambierebbe i parametri a ogni tentativo: Stripe rifiuta una idempotency key riusata con parametri diversi, e il recupero di 05 non funzionerebbe |
| Chiudere la sessione al primo rifiuto della carta | L'ospite perderebbe le date per un errore di digitazione o un 3D Secure non riuscito, mentre Stripe Checkout gli permette di riprovare |

## Conseguenze

- La sessione di checkout dura 32 minuti invece di 30; l'occupazione 37 invece di 35.

## Impatto sui test

- Test aggiunti: `Payments/StripeConnectOnboardingTest`, `Payments/StripeCheckoutTest`.
- Test modificati: `Reservations/ReservationTransitionsTest` e `Public/PublicReservationApiTest` (scadenze con il margine).

## Aggiornamento della specifica

- [x] 05-PAYMENTS-SPEC e 07-API-CONTRACT aggiornati con riferimento a questa ADR
- [ ] Versione della specifica incrementata (alla chiusura di M4)
