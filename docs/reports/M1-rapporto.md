# Rapporto M1 — Modello dati, accesso e audit

Data: 06/11/2026 · Branch: `m1-modello-dati` · PR verso `main` senza merge

## Esito
Completata, con un punto da decidere prima di M5 e alcune domande aperte per M2 (vedi "Problemi aperti").

Le issue #11–#18 sono referenziate con `closes #N` nei commit e si chiuderanno al merge su `main`. Lo scostamento dalla specifica (solo aggiunte compatibili) è nell'ADR-0008, in stato **Proposta**: va verificato.

## Attività
| # | Stato | Issue | Commit | Note |
|---|---|---|---|---|
| 1.1 | Fatta | #11 | `f5634ae` | Migration di tutte le entità di 02, eseguite come `app_migrator`. UUID v7 generati dall'applicazione (`HasUuids` di Laravel 13 usa `Str::uuid7`); `timestamptz`; importi `BIGINT` in centesimi; `DATERANGE` con SQL esplicito, value object `StayRange` e cast. Stati come `text` con `CHECK` generati dagli enum PHP. Ogni tabella tenant-scoped nasce da `TenantTable::create`, che applica `UNIQUE (tenant_id, id)`, FK verso `tenants`, RLS abilitata e forzata e policy `tenant_isolation`. FK composite e indici con `tenant_id` in testa; vincolo GiST `occupancies_no_overlap`. Tabelle globali: `users`, `user_recovery_codes`, `webhook_events`, `retention_policies` e le tabelle di sistema di Laravel. `tenants` ha la policy `id = app_current_tenant()`. Funzioni `SECURITY DEFINER` di `app_migrator`, con `search_path` fisso, che restituiscono solo ID: `app_user_tenants(uuid)`, `app_tenant_by_slug(text)`. |
| 1.2 | Fatta | #12 | `dffd155` | `REVOKE UPDATE, DELETE, TRUNCATE` su `audit_log` per `app_runtime` (e scritture revocate ad `app_admin_ops`). `RlsCatalogTest` verifica che ogni policy usi la stessa espressione, con eccezioni esplicite e motivate (`audit_log`, `tenants`, policy `definer_lookup`). |
| 1.3 | Fatta | #13 | `c605804` | Modelli in `app/Domain/<Area>/Models`, enum per tutti gli stati di 03, `#[PersonalData]` sui campi personali di 09 (`Guest`, `Quote`, `User`). Regola sugli stati come deciso: (a) a runtime il trait `GuardsStatusWrites` e il builder `StatusGuardedBuilder` lanciano `IllegalStatusWrite` se `status` si scrive fuori da `app/Domain/*/StateMachines`; (b) controllo statico limitato alle scritture. Riga di `10-TRACEABILITY` aggiornata. |
| 1.4 | Fatta | #14 | `8c11ae9` | Sanctum SPA con cookie e CSRF. Password Argon2id, minimo 12 caratteri, controllo delle password compromesse. MFA TOTP obbligatoria (pragmarx/google2fa), QR generato in locale (bacon/bacon-qr-code), protezione dal riuso del codice nello stesso intervallo. 10 codici di recupero monouso salvati come hash. Nessuna API admin senza MFA verificata nella sessione. Sessioni Redis: 2 h di inattività, 12 h assolute. Ri-autenticazione con password valida 15 min (middleware `reauth`). Login: 5 tentativi ogni 15 min per account e per IP; stesso limite per i codici MFA. `config/auth.php` punta al nuovo modello `User`. |
| 1.5 | Fatta | #15 | `2fcd992` | `php artisan tenant:create {slug} {name} {owner-email}`: password chiesta due volte con input nascosto, mai come argomento; slug, slug già usato ed email già usata controllati prima della password; il proprietario nasce senza MFA e la configura al primo accesso. |
| 1.6 | Fatta | #16 | `322ebfa` | `AuditLogger::recordModelChange`: in `changes` i campi `#[PersonalData]` compaiono solo con `"[personal]"`. `Outbox::enqueue` con `dedupe_key` unico per tenant (`ON CONFLICT DO NOTHING`, la transazione non si interrompe). Dispatcher in coda ogni minuto: ID cross-tenant letti con `app_admin_ops`, poi un job per messaggio con `app_runtime` e contesto tenant. Retry con backoff, poi `FAILED`. |
| 1.7 | Fatta | #17 | `c7f27b5` | Pannello in italiano: login, configurazione MFA (QR e chiave manuale, poi 10 codici di recupero mostrati una volta), verifica MFA (TOTP o codice di recupero), layout base con nome del tenant e uscita. Solo chiavi di traduzione. |
| 1.8 | Fatta | #18 | `edb8860` | `DemoSeeder` con dati inventati: tenant `demo-alghero`, struttura "Casa Demo Alghero" con traduzioni it/en, due unità con traduzioni, una tariffa base per unità. Idempotente, rifiutato in produzione. |

Documentazione: `10-TRACEABILITY.md` 1.3, ADR-0008, `SETUP-WINDOWS.md` (cookie di sessione in locale, dati demo, `tenant:create`, scheduler).

## Test
- **Invarianti e criteri coperti**:
  - **I5 e parte di AC11**:
    - `Feature/TenantIsolationApiTest`: `GET /api/v1/admin/me` e `GET /api/v1/admin/properties/{id}`; una risorsa di un altro tenant risponde 404; `tenant_id` del client ignorato; utente senza membership → 404.
    - `Feature/Architecture/RlsCatalogTest` e `Feature/Database/TenantRowIsolationTest` sulle tabelle reali.
  - **I17**: `Feature/Architecture/CompositeFkCatalogTest` sulle tabelle reali, `Feature/CrossTenantInsertRejectedTest`.
  - **I22, parte audit**: `Feature/AuditLogNoPersonalDataTest` su tutti i modelli con `#[PersonalData]`, modifiche reali di `Guest` e campi segreti di `User`.
  - **I1, livello database**: `Feature/OccupancyExclusionTest` sul vincolo GiST. Il test di concorrenza arriva con 3.1.
- **Criteri di uscita di M1**:
  - Test I5, I17, `RlsCatalogTest`, `CompositeFkCatalogTest` e `AuditLogNoPersonalDataTest` verdi.
  - Login con MFA provato su server reale, sessioni Redis e database di sviluppo, con il tenant demo:
    - credenziali errate → 401 `INVALID_CREDENTIALS`;
    - login → `mfa_setup_required`;
    - `/me` → 401 `MFA_SETUP_REQUIRED`;
    - configurazione con QR → 10 codici;
    - `/me` → 200;
    - logout, poi `/me` → 401;
    - secondo accesso → 401 `MFA_REQUIRED`;
    - verifica con codice di recupero → 200;
    - `POST` senza token CSRF → 419.

  Dopo la prova la MFA del proprietario demo è stata azzerata, così puoi configurarla con il tuo telefono.
- **Risultato in locale**: `composer test` 253 test e 757 asserzioni, tutti verdi. Verdi anche `composer lint`, `composer analyse` (Larastan livello 6), `npm run typecheck`, `npm run build` e `composer audit` (nessuna vulnerabilità).
- **Risultato CI**: verde sull'ultimo commit di codice e documentazione (`71751e0`, run [42238475350](https://github.com/Alessiovinci87/booking-engine/actions/runs/42238475350)); il commit di questo rapporto avvia un nuovo run sulla PR.
- **Test aggiunti**:
  - `Unit/StayRangeTest`
  - `Feature/Database/{SchemaTest, TenantRowIsolationTest, AuditLogPrivilegesTest, TenantLookupFunctionsTest}`
  - `Feature/OccupancyExclusionTest`
  - `Feature/CrossTenantInsertRejectedTest`
  - `Feature/Domain/ModelsTest`
  - `Feature/TenantIsolationApiTest`
  - `Feature/Auth/{LoginTest, LoginThrottleTest, MfaTest, SessionExpiryTest, ReauthTest}`
  - `Feature/Tenancy/{CreateTenantCommandTest, OutboxTest}`
  - `Feature/AuditLogNoPersonalDataTest`
  - `Feature/DemoSeederTest`
  - Estesi:
    - `Feature/Architecture/RlsCatalogTest`
    - `Feature/Frontend/SpaRoutesTest`: pagine di accesso, cookie CSRF, ogni codice di errore tradotto, nessun testo scritto direttamente nel JSX.
    - `Arch/StateTransitionsTest`: riscritto, con esempi positivi e negativi.

## Scostamenti dalla specifica
Solo aggiunte compatibili, raccolte nell'**ADR-0008 (Proposta)**:
- rotte di accesso sotto `/api/v1/admin/auth/*`, assenti in 07;
- codici di errore nuovi: `MFA_SETUP_REQUIRED`, `INVALID_CREDENTIALS`, `INVALID_MFA_CODE`, `REAUTH_REQUIRED`, `MFA_ALREADY_ENABLED`, e 419 per il CSRF scaduto;
- tabella `user_recovery_codes`;
- `audit_log.tenant_id` nullable per gli eventi di piattaforma;
- formato di `changes` (`from`/`to`, `[personal]`, `[hidden]`);
- parametri di retry dell'outbox.

I documenti 02 e 07 non sono ancora stati modificati: si aggiornano dopo la tua verifica dell'ADR.

## Scelte minori prese
- **Entità "invariate 1.0"**: 02 rimanda alla 1.0 per `RatePlan`, `SeasonRule`, `PriceLine`, `ReservationChange`, `SyncRun`, `WebhookEvent` e `OutboxMessage`, ma la specifica 1.0 non è nella repository. I campi sono ricavati da 04, 05 e 06 (vedi problemi aperti).
- **`retention_policies` globale**: è configurazione della piattaforma per categoria (09), nessuna durata nel codice.
- **`AuditLogger` e outbox nell'area Tenancy**: sono trasversali, l'area più vicina in ADR-0006. La macchina a stati dell'outbox è in `app/Domain/Tenancy/StateMachines`.
- **Policy `definer_lookup`**: `FOR SELECT TO app_migrator` su `tenants` e `tenant_members`. Con RLS forzata anche il proprietario è soggetto alle policy, quindi le funzioni `SECURITY DEFINER` ne hanno bisogno. Le funzioni restituiscono solo ID, `EXECUTE` è concesso solo ad `app_runtime` e revocato a `PUBLIC` e ad `app_admin_ops`.
- **Tenant della richiesta**: arriva da `app_user_tenants()` tramite `MembershipTenantResolver`. Con più membership si usa la prima: nella 1.0 c'è un solo OWNER per tenant e un utente per tenant. La scelta del tenant attivo servirà quando un utente potrà averne più di uno.
- **`UnresolvedTenantResolver`** (M0) non è più collegato: il pannello usa `MembershipTenantResolver`. Si toglie o si riusa con M3, che ha il risolutore della pagina pubblica.
- **`GET /api/v1/admin/properties/{id}`**: in M1 solo lettura di base (nome, slug, stato, fuso orario), per il test di isolamento; M2.1 la completa. Il modello si carica dentro il contesto tenant e non con il binding implicito, che girerebbe prima del middleware `tenant`.
- **Codici di recupero**: HMAC-SHA256 con `APP_KEY`, perché 50 bit casuali non richiedono un hash lento e così il consumo è un `UPDATE … WHERE used_at IS NULL` atomico. **Effetto**: se `APP_KEY` cambia, i codici esistenti non valgono più.
- **Segreto TOTP**: è cifrato con il cast `encrypted` di Laravel, quindi dipende anch'esso da `APP_KEY`.
- **Login**:
  - Con un'email sconosciuta si calcola comunque un hash, per non rivelare l'esistenza dell'account con i tempi di risposta.
  - Le chiavi del limite di tentativi contengono l'hash dell'email, non l'email.
  - Un accesso riuscito azzera il contatore dell'account, non quello dell'IP.
- **Outbox `last_error`**: contiene solo la classe dell'eccezione, perché i messaggi dei provider possono contenere email.
- **Test del dispatcher**: la query con `app_admin_ops` è verificata per ruolo e sintassi, non sulle righe. I dati dei test sono nella transazione di `RefreshDatabase`, invisibile ad altre connessioni. La logica di accodamento è verificata con una sorgente finta.
- **Pannello**:
  - il QR si mostra come immagine `data:` e mai come HTML;
  - all'uscita la cache di TanStack Query viene svuotata;
  - la navigazione segue lo stato di `GET /admin/auth/session`, ma il controllo vero resta sul server.
- **`lang/it/validation.php`**: contiene solo le regole usate finora. Senza questo file i messaggi dei comandi e delle API mostravano le chiavi invece del testo.
- **`.env` locale**: aggiunti `SESSION_SECURE_COOKIE=false` e `SANCTUM_STATEFUL_DOMAINS`, anche in `.env.example`. Senza, il login in locale su `http` non resta attivo. In produzione il cookie resta `secure` (valore predefinito in `config/session.php`).
- **Seeder demo**: la password del proprietario è casuale e mostrata una sola volta in console. La MFA si configura al primo accesso.

## Problemi aperti e rischi per la milestone successiva
1. **Contraddizione nella specifica, da decidere prima di M5** (caso 1 di "Quando ci si ferma"; non blocca M1 né M2):
   - 06 prevede un feed per unità e destinazione: in M1 c'è l'indice unico `ics_feeds_one_per_destination` su `(tenant_id, unit_id, destination) WHERE status <> 'RETIRED'`.
   - La rotazione del token, però, tiene il vecchio feed `FROZEN` per 7 giorni accanto al nuovo `ACTIVE`, cioè due feed non ritirati per la stessa destinazione.
   - Due soluzioni:
     - l'indice esclude anche `FROZEN`;
     - la rotazione cambia il token sulla stessa riga e conserva il vecchio hash in una colonna dedicata.
   - Serve una scelta (ADR) prima di 5.x.
2. **Quale `CancellationPolicy` vale per un'unità** (M2): 02 lega le politiche alla struttura (`property_id`, `code`) ma nessun campo dice quale si applica a un'unità o a una prenotazione. Oggi c'è solo lo snapshot in `reservations.cancellation_policy_snapshot`. Da chiarire prima di 2.x.
3. **Campi di testo libero non marcati `#[PersonalData]`** (privacy, caso 4):
   - `reservations.cancel_reason`, `reservation_changes.note`, `inventory_occupancies.note`, `inventory_conflicts.resolution_notes` e `refunds.reason` possono contenere dati dell'ospite scritti dal gestore.
   - Marcarli significa anonimizzarli con l'ospite (I22) e non registrarne mai il valore nell'audit. La decisione è tua.
4. **`payment_events` append-only solo a livello applicativo**: si può revocare `UPDATE`/`DELETE` ad `app_runtime` come per `audit_log`. Proposta per M4.
5. **Conservazione dell'audit (M7)**: con il `REVOKE` della 1.2 il job di retention non può cancellare le righe di `audit_log` dopo 24 mesi. Servirà una funzione `SECURITY DEFINER` dedicata o un ruolo apposito.
6. **Controllo statico sugli stati (M2)**: la regola (b) segnalerà le scritture di `status` su `Property`, `Unit` e `Tenant` fuori da `StateMachines`. In M2 serviranno piccole macchine a stati, ad esempio per la pubblicazione della struttura, oppure eccezioni motivate nel test.
7. **Trigger di I12** (unità con calendari esterni mai `INSTANT`): rinviato a 2.5 con `UnitService`, come da piano.
8. **Controllo delle password compromesse**:
   - È una chiamata esterna verso Have I Been Pwned: si invia solo il prefisso di 5 caratteri dello SHA-1, mai la password.
   - Se il servizio non risponde, Laravel lo considera superato (fail-open).
   - Lo usano solo `tenant:create` e, in futuro, il cambio password. Il login non lo usa.
9. **CSP**: non è ancora configurata (08 la prevede). Il QR usa un'immagine `data:`: la CSP dovrà ammettere `img-src 'self' data:`, oppure il QR andrà servito da una rotta.
10. **Tempi dei test in locale**:
    - Sul PC di sviluppo PostgreSQL in Docker ha una latenza di circa 7 ms per query.
    - La suite completa ha impiegato tra 37 s e 5 min, a seconda dei momenti.
    - Non è un problema del codice, ma rallenta il ciclo; in CI i tempi sono regolari.
11. **Avviso PSR-4 per `ProbeTenantJob`**: Composer lo segnala, ma esisteva già prima di M1.
12. **E2E del pannello**: Playwright arriva in M6, come da piano. Fino ad allora il flusso è coperto dai test Feature delle API e dalla prova manuale descritta sopra.
13. **Entità "invariate 1.0"**: se la specifica 1.0 esiste altrove, conviene confrontarla con i campi ricavati (vedi "Scelte minori").

## Ore impiegate (stima) rispetto al previsto
Previsto: sprint 1, 9–20 novembre 2026 (due settimane). Svolta in anticipo, in un'unica sessione di lavoro di Claude Code il 06/11/2026; non ho una misura affidabile delle ore. La parte che ha richiesto più iterazioni sono stati RLS e permessi (policy `definer_lookup`, eccezioni del catalogo). Seguono i controlli sugli stati: il builder dedicato serviva per l'analisi statica.
