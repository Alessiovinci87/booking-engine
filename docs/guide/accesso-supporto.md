# Accesso di supporto in sola lettura

Procedura per la piattaforma (7.5; 08 "Autenticazione"; ADR-0018 punti 19–25). Serve quando un gestore chiede aiuto e bisogna vedere il suo pannello. L'operatore vede il pannello del gestore **solo in lettura**. Il server rifiuta ogni modifica e gli export con dati personali.

## Una volta: creare l'operatore

1. L'operatore ha un utente del pannello che **non appartiene a nessun tenant** (un'email della piattaforma).
2. `php artisan support:operator operatore@piattaforma.example` segna l'utente come operatore. Il comando rifiuta un utente che è già gestore di un tenant.
3. L'operatore entra nel pannello (`/app/login`) e configura la MFA. Senza MFA attiva `support:open` rifiuta.

Per togliere il ruolo: `php artisan support:operator operatore@piattaforma.example --remove`.

## A ogni richiesta di supporto

1. Concorda con il gestore il motivo dell'accesso.
2. Apri l'accesso:

   ```
   php artisan support:open slug-del-tenant --operator=operatore@piattaforma.example --reason="Verifica calendario Booking che non si aggiorna" --hours=1
   ```

   - La durata va da 1 a 2 ore.
   - La motivazione è obbligatoria: da 10 a 200 caratteri, senza email né numeri di telefono. Finisce nell'audit e il gestore la vede.
   - Una nuova autorizzazione chiude la precedente dello stesso operatore.
3. L'operatore apre `/app`. Per tutto il tempo vede il banner viola "Accesso di supporto" con la scadenza e il motivo.
4. A lavoro finito chiudi l'accesso, senza aspettare la scadenza:

   ```
   php artisan support:close slug-del-tenant
   ```

## Cosa vede il gestore

- In "Account" → "Accessi di supporto" (`/app/accessi-supporto`) trova, per ogni accesso:
  - chi l'ha aperto e perché;
  - quando è stato aperto e quando scade;
  - la prima e l'ultima lettura dell'operatore;
  - lo stato.
- Può chiudere subito un accesso attivo con "Chiudi l'accesso" (chiede la password).
- L'audit registra l'apertura, la prima lettura, la revoca e la chiusura.

## Cosa non può fare l'operatore

- Nessuna modifica: le richieste diverse da `GET` rispondono `403 SUPPORT_READ_ONLY`, anche se l'interfaccia mostra ancora i pulsanti.
- Nessun export: dati di un ospite, CSV delle prenotazioni ed export completo restano chiusi.
- Nessun altro tenant: il database (RLS) gli mostra solo il tenant dell'autorizzazione.
- Dopo la scadenza o la revoca ogni richiesta risponde `403 SUPPORT_ACCESS_INACTIVE`.
