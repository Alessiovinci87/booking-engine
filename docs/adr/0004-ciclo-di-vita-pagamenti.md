# ADR-0004 — Scadenze del checkout, recupero dopo capture fallita, rimborsi separati, prenotazioni manuali

- **Stato**: Accettata
- **Data**: 2026-11-06
- **Documenti toccati**: 02, 03, 05, 07, 01, CLAUDE.md
- **Origine**: revisione tecnica v1.0, punti 2, 3, 4 e 6 (CHANGELOG C2, C3, C4, C6)

## Contesto

Nella 1.0: scadenze di hold e sessione Stripe create in momenti diversi; rilascio senza verificare la sessione; capture fallita in uno stato terminale con hold ancora attivo; cancellazione che non distingueva l'esito asincrono del rimborso; regola contraddittoria sulle prenotazioni manuali senza pagamento.

## Decisione

1. Checkout in tre passi con scadenza provvisoria, sessione con idempotency key, scadenza dell'occupazione allineata a quella restituita da Stripe più 5 minuti.
2. Un'occupazione con sessione si rilascia solo dopo `checkout.sessions.expire` o la verifica della chiusura; se la sessione risulta pagata si prosegue con il pagamento.
3. Nuovo stato `CAPTURE_FAILED` che occupa le date fino a 24 h; recupero con nuovo `Payment RECOVERY` sulla stessa prenotazione, oppure rilascio.
4. Cancellazione e rimborso come esiti separati; macchina a stati del `Refund`; comunicazioni distinte per "avviato" e "completato".
5. Prenotazioni manuali confermabili senza pagamento (`ADMINISTRATIVE`, `UNPAID`).

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Rilasciare a tempo senza chiamare Stripe | sessione pagabile dopo il rilascio; più rimborsi automatici e ospiti scontenti |
| Nuova prenotazione per il recupero | due prenotazioni per lo stesso soggiorno, storico frammentato |
| Bloccare la cancellazione finché il rimborso non riesce | il gestore resterebbe con date bloccate per problemi di pagamento |
| Vietare le prenotazioni manuali non pagate | non corrisponde a come lavorano i piccoli gestori (bonifico dopo la conferma) |

## Conseguenze

- Il job di rilascio dipende da Stripe: se Stripe non risponde, le date restano bloccate più a lungo (prudente, con allerta).
- La durata del link di recupero è limitata a 24 h dalla durata massima di una sessione Checkout.

## Impatto sui test

- Nuovi: allineamento scadenze, sessione completata scoperta dal job, recupero, rimborsi falliti, manuale `UNPAID`.
- Invarianti: I11 riformulata; I16, I20 nuove.
