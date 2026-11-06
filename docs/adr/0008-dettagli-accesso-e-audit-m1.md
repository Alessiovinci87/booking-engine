# ADR-0008 — Dettagli di accesso, audit e outbox emersi in M1

- **Stato**: Accettata (verifica di M1, 06/11/2026), con il codice `CSRF_TOKEN_EXPIRED` per il 419
- **Data**: 2026-11-06
- **Autori**: Claude Code (stesura), Alessio (verifica e accettazione)
- **Documenti di specifica toccati**: 02-DOMAIN-MODEL (tabella `user_recovery_codes`, `audit_log.tenant_id`), 07-API-CONTRACT (rotte di accesso, codici di errore), 08-SECURITY-OPERATIONS (dettagli della sessione)

## Contesto

M1 implementa l'accesso al pannello (08: Sanctum SPA, MFA obbligatoria, codici di recupero, ri-autenticazione, limiti ai tentativi), l'audit (02, 08) e l'outbox (02, 03). La specifica descrive i requisiti ma non alcuni dettagli che il codice deve fissare:

- 07 elenca le rotte `/admin/*` di dominio, ma nessuna rotta di accesso (login, MFA, logout);
- 07 elenca i codici di errore 401 `UNAUTHENTICATED` e `MFA_REQUIRED`, ma non distingue la MFA da configurare né la ri-autenticazione richiesta;
- 02 non ha un'entità per i codici di recupero MFA;
- 02 descrive `AuditLog` senza `tenant_id`, mentre alcuni eventi di sicurezza (es. login con email sconosciuta) non appartengono a nessun tenant.

Sono **aggiunte compatibili**: nessun comportamento concordato cambia. Si registrano qui perché il contratto API e il modello dati crescono.

## Decisione

**Le aggiunte seguenti entrano nella specifica 1.x, salvo diversa indicazione in verifica.**

1. **Rotte di accesso** sotto `/api/v1/admin/auth/`:
   - `POST login`
   - `GET session`
   - `POST logout`
   - `GET mfa/setup` e `POST mfa/setup` (configurazione, solo con la MFA non ancora attiva)
   - `POST mfa/verify` (codice TOTP o di recupero)
   - `POST confirm-password` (ri-autenticazione, richiede la MFA verificata)

   Le altre rotte `/admin/*` richiedono, nell'ordine: sessione Sanctum, sessione fresca (2 h di inattività, 12 h assolute), MFA verificata nella sessione, limite di 300 richieste al minuto per utente, contesto tenant dalla membership.
2. **Nuovi codici di errore**:

   | HTTP | Codice | Significato |
   |---|---|---|
   | 401 | `MFA_SETUP_REQUIRED` | la MFA non è ancora configurata |
   | 401 | `INVALID_CREDENTIALS` | email o password errate |
   | 401 | `INVALID_MFA_CODE` | codice MFA errato |
   | 403 | `REAUTH_REQUIRED` | serve la conferma della password (15 min) |
   | 409 | `MFA_ALREADY_ENABLED` | la MFA è già configurata |
   | 419 | `CSRF_TOKEN_EXPIRED` | token CSRF mancante o scaduto: la SPA richiede un nuovo cookie CSRF e ripete la richiesta una volta |

   Gli errori di validazione hanno `details.fields` con l'elenco degli errori per campo.
3. **Tabella `user_recovery_codes`**, globale come `users`, con:
   - `user_id`
   - `code_hash` (HMAC-SHA256 con la chiave dell'applicazione)
   - `used_at`

   Contiene 10 codici monouso per utente.
4. **`audit_log.tenant_id`** è nullable. NULL solo per gli eventi di piattaforma senza tenant, ammesso dalla policy RLS solo fuori da un contesto tenant. Esempi: login con email inesistente, login di un utente senza membership.
5. **Formato di `audit_log.changes`** per le modifiche dei modelli (`AuditLogger::recordModelChange`):
   - `{ campo: { from, to } }` per i campi ordinari
   - `"[personal]"` per i campi `#[PersonalData]` (come 02)
   - `"[hidden]"` per i campi nascosti del modello (hash della password, segreto MFA)
6. **Outbox**:
   - `last_error` contiene solo la classe dell'eccezione, non il messaggio, che può contenere dati personali.
   - Massimo 8 tentativi, con backoff 1 min, 5 min, 15 min, 30 min, 1 h, 2 h, 4 h.
   - Poi lo stato è `FAILED`, con reinvio manuale tramite `OutboxMessageStateMachine::retry`.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Rotte di accesso fuori da `/api/v1` (es. `/login` di Fortify) | Fortify non è nelle scelte di ADR-0006; un solo prefisso semplifica CSRF, errori e rate limit |
| Un solo codice `MFA_REQUIRED` anche per la MFA da configurare | La SPA deve sapere quale pagina mostrare, senza una seconda chiamata |
| Codici di recupero in una colonna JSON di `users` | La riga separata permette il consumo atomico (`UPDATE … WHERE used_at IS NULL`) |
| Eventi senza tenant scartati dall'audit | 08 chiede l'audit dei fallimenti di login, anche per email inesistenti |

## Conseguenze

- Il contratto API cresce, ma resta compatibile (07, "Versionamento").
- La migration di `audit_log` e quella di `user_recovery_codes` sono già in M1; non servono migration nuove.
- Il job di conservazione (M7) dovrà cancellare le righe di `audit_log` dopo 24 mesi: oggi `app_runtime` non ha `DELETE` su `audit_log` (1.2). Servirà un ruolo o una funzione dedicata.

## Impatto sui test

- Test aggiunti:
  - `Feature/Auth/{LoginTest, LoginThrottleTest, MfaTest, SessionExpiryTest, ReauthTest}`
  - `Feature/AuditLogNoPersonalDataTest`
  - `Feature/Tenancy/OutboxTest`
- Test da modificare: nessuno.
- Invarianti toccati: I5 (contesto tenant dalla membership), I22 (audit senza valori personali).

## Aggiornamento della specifica

- [x] Documenti aggiornati con riferimento a questa ADR (02: `user_recovery_codes`, `audit_log.tenant_id`, formato di `changes`; 07: rotte e codici; 08: sessione, CSRF, outbox)
- [x] Versione della specifica incrementata (02, 07, 08 → 1.2)
