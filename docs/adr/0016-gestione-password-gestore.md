# ADR-0016 — Gestione della password del gestore (6.11)

- **Stato**: Proposta (attività 6.11 di M6; la verifica della milestone la accetta)
- **Data**: 2026-11-07
- **Autori**: Claude Code, su indicazione di Alessio
- **Documenti di specifica toccati**: 07-API-CONTRACT ("Accesso", "Formato errori"), 08-SECURITY-OPERATIONS ("Autenticazione e accesso"), 11-MILESTONES (6.11)

## Contesto

Scrivendo la procedura di azzeramento della MFA (6.10) è emerso che il gestore non può cambiare la password dal pannello e non può recuperarla se la dimentica. La password iniziale viene comunicata per telefono alla creazione dell'account (`tenant:create`): oggi il gestore la tiene per sempre. Alessio ha chiesto il 07/11/2026 la nuova attività 6.11, con queste regole:

- cambio password nel pannello: password attuale, MFA già verificata nella sessione, stesse regole di robustezza di M1, chiusura delle altre sessioni, audit, email di avviso;
- "password dimenticata": risposta sempre identica, link monouso valido 60 minuti salvato come hash, limite per email e per IP, email tramite outbox. La reimpostazione non salta la MFA; chiude le sessioni, scrive l'audit e invia l'email di avviso.

## Decisione

### Rotte (sotto `/api/v1/admin/auth/`, con CSRF come il login)

| Metodo | Percorso | Accesso | Esito |
|---|---|---|---|
| POST | `/auth/password/forgot` | senza sessione | `{ email }` → sempre `202 { "status": "accepted" }`; `429 RATE_LIMITED` oltre il limite |
| POST | `/auth/password/reset` | senza sessione | `{ token, password, password_confirmation }` → `204`; `422 PASSWORD_RESET_INVALID` se il link non esiste, è scaduto, è già stato usato o è stato sostituito; `400 VALIDATION_FAILED` per la password |
| POST | `/auth/password` | sessione e MFA verificata (`panel.fresh`, `mfa`) | `{ current_password, password, password_confirmation }` → `204`; `401 INVALID_CREDENTIALS` con la password attuale errata; `429 RATE_LIMITED` |

### Regole

1. **Robustezza**: la stessa `PasswordPolicy` di M1 (almeno 12 caratteri, al massimo 200, controllo su Have I Been Pwned con k-anonymity) più la conferma. Nel cambio password la nuova password deve essere diversa da quella attuale.
2. **Richiesta del link**:
   - risposta identica che l'email esista o no;
   - limite di 3 richieste all'ora per email (chiave con l'hash dell'email, mai in chiaro) e di 10 all'ora per IP;
   - il limite si conta su ogni richiesta, anche per email inesistenti, quindi neanche il 429 rivela l'esistenza dell'account;
   - un nuovo link annulla quelli precedenti ancora validi.
3. **Link**:
   - token casuale di 256 bit; nella tabella globale `user_password_resets` (senza `tenant_id`, come `user_recovery_codes`) c'è solo lo SHA-256 del token;
   - una copia cifrata con la chiave dell'applicazione (`token_encrypted`) serve all'handler dell'outbox per comporre l'email senza mettere il token nel payload. Si azzera all'uso, alla scadenza rilevata e quando un nuovo link sostituisce il vecchio;
   - validità 60 minuti, un solo uso;
   - indirizzo `/app/reimposta-password#token=…`: il token sta nel frammento, che il browser non invia al server. Non finisce quindi nei log di accesso né nell'intestazione Referer. Dopo l'uso la pagina lo toglie dalla barra degli indirizzi.
4. **Email** tramite outbox nel tenant principale dell'utente, solo ID nel payload:
   - `account.password_reset`: il link. Non parte se nel frattempo il link è stato usato, è scaduto o è stato sostituito;
   - `account.password_changed`: l'avviso "la tua password è stata cambiata", dopo il cambio e dopo la reimpostazione.

   Testi solo in italiano, come il pannello. Un utente senza tenant (account di sola piattaforma) non riceve email, perché l'outbox è per tenant.
5. **La reimpostazione non salta la MFA**: non apre una sessione e non tocca segreto TOTP e codici di recupero. Il gestore torna al login e, dopo la nuova password, deve inserire il codice o un codice di recupero.
6. **Sessioni**: cambio e reimpostazione impostano `users.sessions_revoked_at` (colonna di 6.10, precisione al secondo). Tutte le sessioni iniziate fino a quell'istante si chiudono alla richiesta successiva.
   - Nel cambio password la sessione che ha fatto il cambio resta aperta, con la MFA verificata: prende atto della revoca (`panel.revocation_ack` nella sessione, uguale a `sessions_revoked_at`), e il suo identificativo viene rigenerato.
   - Una revoca successiva (un altro cambio, `user:reset-mfa`) chiude anche questa sessione.
   - Le sessioni aperte nello stesso secondo della revoca si chiudono: meglio un accesso in più che una sessione sopravvissuta alla revoca.
7. **Tentativi sulla password attuale**: stesso limite del login (5 ogni 15 minuti per account e per IP), condiviso con il login e con la conferma della password.
8. **Audit**, solo ID e nessun valore personale:
   - `auth.password_reset.requested`: con l'utente se esiste, altrimenti senza soggetto;
   - `auth.password_reset.failed`, con `reason` uguale a `used` o `expired` quando il link è riconosciuto;
   - `auth.password_reset.completed`;
   - `auth.password_changed`;
   - `auth.password_change.failed`.
9. **Pannello**:
   - link "Password dimenticata?" nella pagina di accesso;
   - pagine `/app/password-dimenticata` e `/app/reimposta-password`;
   - pagina `/app/account` con il cambio password, raggiungibile dall'email in alto a destra (su schermi piccoli dalla voce "Account").

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Aggiungere i punti ad ADR-0014 | ADR-0014 è già accettata; una decisione nuova resta "Proposta" finché la verifica di M6 non la accetta (CLAUDE.md, regola 2) |
| Broker delle password di Laravel (`Password::sendResetLink`, tabella `password_reset_tokens`) | Invia l'email in modo sincrono, fuori dall'outbox (niente ritentativi né idempotenza); il token è legato all'email e non all'ID; il limite di frequenza è solo per email. Il servizio dedicato è piccolo e segue le regole del progetto |
| Token nella query string (`?token=`) | Finirebbe nei log di accesso del server e del proxy e nel Referer |
| Accesso automatico dopo la reimpostazione | Salterebbe almeno in parte la MFA, contro la richiesta e contro 08 |
| Reimpostazione anche della MFA via email | Chi controlla la casella email otterrebbe l'account intero; l'azzeramento della MFA resta una procedura di piattaforma con verifica dell'identità (6.10) |
| 404 o messaggio diverso per un'email inesistente | Rivela quali email hanno un account |

## Conseguenze

- Nuova tabella globale `user_password_resets` (migration `2027_02_08_000100`); nessun dato esistente toccato.
- Nuovi tipi di outbox `account.password_reset` e `account.password_changed` (handler `SendAccountEmail`).
- Nuovo codice di errore `PASSWORD_RESET_INVALID` (422).
- Rischio ridotto: la password iniziale comunicata per telefono si può cambiare al primo accesso, e una password dimenticata non richiede più l'intervento della piattaforma.
- Rischio residuo: chi controlla la casella email del gestore può cambiare la password, ma senza il secondo fattore non entra; il gestore riceve comunque l'avviso.
- Il tempo di risposta della richiesta del link può essere un po' più lungo per un'email esistente (scrittura del link e dell'outbox). Il contenuto della risposta e il limite di frequenza sono identici; un attaccante dovrebbe misurare differenze di pochi millisecondi sotto il limite di 10 richieste all'ora per IP.
- Conservazione: i link usati o scaduti restano nella tabella senza la copia cifrata. La pulizia periodica, con una durata da `RetentionPolicy`, va aggiunta con le altre conservazioni (vedi "Aggiornamento della specifica").

## Impatto sui test

- Test aggiunti: `Feature/Auth/PasswordManagementTest`:
  - email inesistente con risposta identica e nessun link;
  - link scaduto, già usato, inesistente e sostituito;
  - limite per email e per IP;
  - MFA ancora richiesta dopo la reimpostazione, con il codice TOTP e con un codice di recupero;
  - politica di robustezza;
  - cambio password: password attuale errata con limite, MFA obbligatoria, sessione corrente aperta e altre chiuse, audit, email in outbox.
- Test modificati:
  - `Feature/Database/SchemaTest`: nuova tabella globale;
  - `Feature/Frontend/SpaRoutesTest`: nuove pagine e nuovo codice di errore tradotto.
- Invarianti toccati: nessuno di I1–I22.

## Aggiornamento della specifica

- [x] 07: rotte di accesso e codice `PASSWORD_RESET_INVALID`
- [x] 11: attività 6.11
- [x] Guida rapida per i gestori e procedura di azzeramento della MFA
- [ ] 08 "Autenticazione e accesso": riferimento a questa ADR, quando è accettata
- [ ] `RetentionPolicy`: durata di conservazione dei link usati o scaduti (proposta: 30 giorni), da decidere alla verifica
