# Istruzioni per Claude Code

Questo repository implementa la specifica in `docs/`. La specifica è la fonte di verità.

## Regole

1. Prima di implementare una funzionalità, leggi i documenti di `docs/` che la riguardano. Se il codice e la specifica divergono, vince la specifica.
2. Non cambiare un comportamento concordato senza una ADR in `docs/adr/` (usa `0000-template.md`). Se una richiesta contraddice la specifica, fermati e segnalalo.
3. Ordine di lavoro: migration (02) → RLS (08) → macchine a stati (03) → pricing (04) → pagamenti (05) → sync (06) → API (07) → frontend.
4. Ogni invariante I1–I22 (02) e ogni criterio di accettazione (01) deve avere almeno un test automatico. La matrice in `docs/10-TRACEABILITY.md` indica quale; aggiornala quando aggiungi servizi, tabelle o endpoint.
5. Stati: solo tramite i metodi di servizio delle macchine a stati. Mai `update(['status' => ...])` diretti.
6. Denaro in centesimi interi, date di soggiorno `[check_in, check_out)`, timestamp UTC.
7. Mai accettare `tenant_id` dal client. Ogni transazione imposta `SET LOCAL app.tenant_id` tramite `TenantContext`.
8. Ogni nuova tabella con `tenant_id` deve avere RLS abilitata e forzata: c'è un test che fallisce altrimenti.
9. Chiamate Stripe con idempotency key; conferme solo da webhook verificato o lettura API.
10. URL forniti dagli utenti solo tramite `SafeHttpFetcher`.
11. Nessun dato personale dell'ospite in log, URL, ICS esportati o metadata Stripe.
12. Testi utente tramite chiavi di traduzione (ospite `it`/`en`, pannello `it`).
13. Le occupazioni si scrivono **solo** tramite `InventoryService`. Mai insert/update diretti su `inventory_occupancies`.
14. Ogni relazione tra tabelle tenant-scoped è una FK composita `(tenant_id, parent_id)`.
15. Prima di chiamare Stripe si persiste lo stato intermedio; un'occupazione con sessione di pagamento si rilascia solo dopo aver chiuso o verificato la sessione.
16. I feed iCal sono per destinazione e non servono mai un calendario vuoto per "spegnersi".
17. Nessun valore personale nell'audit log; durate di conservazione solo da `RetentionPolicy`.

## Comandi (da completare al bootstrap)

- Test: `php artisan test` / `npx playwright test`
- Analisi statica: `./vendor/bin/phpstan analyse`
- Code: `php artisan horizon`
