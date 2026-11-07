# ADR-0018 — Precisazioni di M7 "Completamento"

- **Stato**: Proposta
- **Data**: 2026-11-07
- **Autori**: Claude Code
- **Documenti di specifica toccati**: 06-CALENDAR-SYNC ("Ciclo di vita dei feed e uscita del cliente"), 07-API-CONTRACT ("Privacy", "Formato errori"), 08-SECURITY-OPERATIONS ("Livello database (RLS)"), 09-PRIVACY-GDPR ("Uscita del cliente", "Conservazione"), 10-TRACEABILITY, 11-MILESTONES (M7)

Il numero 0017 è riservato all'ADR della milestone M8 "Qualità del frontend", chiesta insieme a M7.

## Contesto

M7 completa le parti di 06 e 09 rimaste fuori dalla 1.0 del pilota: uscita del cliente (7.1), conservazione dei dati (7.2), analisi statica più severa (7.4) e accesso di supporto (7.5). La specifica descrive cosa deve succedere, ma lascia aperti alcuni dettagli tecnici: con quale ruolo PostgreSQL si cancella, come si cancellano righe legate da riferimenti circolari, cosa resta dopo la cancellazione, il formato dell'export e cosa può fare il gestore mentre l'uscita è in corso. Questa ADR raccoglie le scelte fatte durante la milestone. Resta **Proposta** finché la verifica di M7 non la accetta.

## Decisione

### 7.1 Uscita del cliente

1. **Ruolo `app_retention`** (deciso dopo M1, ADR-0009): ruolo PostgreSQL con LOGIN, senza BYPASSRLS, con solo `SELECT` e `DELETE` sulle tabelle con `tenant_id` (`audit_log` e `tenant_exports` comprese) e sulle tabelle globali degli utenti (`users`, `user_recovery_codes`, `user_password_resets`). Nessun `INSERT`, `UPDATE` o `TRUNCATE`; nessun accesso a `tenants`, `webhook_events`, `retention_policies`. La migration `2027_04_01_000100` fallisce se il ruolo non esiste. Lo creano `docker/postgres/init/02-roles.sh` (password `APP_RETENTION_PASSWORD`, obbligatoria) e lo stesso script in CI.
2. **Come si usa `app_retention`** (`App\Domain\Privacy\RetentionDatabase`, `config/retention.php` `db_mode`):
   - in produzione (`connection`) sulla connessione `pgsql_retention`, con il proprio `app.tenant_id`;
   - nei test e in locale (`set_role`) dentro la transazione corrente con `SET LOCAL ROLE app_retention`, poi `RESET ROLE`: i test con `RefreshDatabase` vedono così i propri dati. `app_runtime` riceve la membership `WITH INHERIT FALSE, SET TRUE` **solo** se `APP_RETENTION_SET_ROLE_FOR_TESTS=1` (docker-compose e CI); in produzione lo script la revoca. Senza INHERIT `app_runtime` non acquisisce mai i privilegi di `app_retention` per conto suo.
3. **Riferimenti circolari**: i due cicli di FK (preventivo ↔ prenotazione del tentativo attivo, struttura ↔ politica di cancellazione predefinita) diventano `DEFERRABLE INITIALLY IMMEDIATE`. Il comportamento normale non cambia; la cancellazione li rinvia alla fine della transazione con `SET CONSTRAINTS … DEFERRED` e non ha bisogno di `UPDATE`.
4. **Avvio** (`OffboardingService::start`, pannello e `tenant:offboarding {slug} start`; ri-autenticazione): da `ACTIVE` o `SUSPENDED` a `OFFBOARDING`, `offboarding_started_at`, feed `ACTIVE` → `FROZEN` con motivo `offboarding`. La pagina ospite e i preventivi rispondono `404 NOT_FOUND` per ogni tenant non `ACTIVE`; i preventivi prima non lo controllavano.
5. **Pannello in sola lettura** durante l'uscita (09 punto 1): il middleware `tenant.writable` risponde `409 TENANT_OFFBOARDING` a ogni richiesta che modifica dati, con il tenant `OFFBOARDING` o `CLOSED`. Restano ammesse: cancellazione e rifiuto delle prenotazioni, rimborsi (nuovo tentativo e rimborso fuori piattaforma), anonimizzazione dell'ospite, export completo e conferme della rimozione dei feed.
6. **Export completo** (`POST /admin/tenant/export`, ri-autenticazione, `202`):
   - tabella `tenant_exports` (stati `PENDING`, `READY`, `FAILED`, `EXPIRED`, macchina `TenantExportStateMachine`);
   - job asincrono `GenerateTenantExportJob` che produce uno ZIP sul disco `offboarding.disk`;
   - contenuto dello ZIP: `LEGGIMI.txt`, un CSV per tabella (separatore `;`, BOM, protezione dalle formule come l'export di 6.2), `export.json` con gli stessi dati, `prenotazioni_future.csv`;
   - colonna `futura` nelle prenotazioni, per le prenotazioni che occupano ancora e hanno check-out dopo oggi;
   - mai token, hash o valori cifrati, né `tenant_id`;
   - email `tenant.export_ready` ai proprietari, senza link di download: rimanda alla pagina del pannello.
7. **Download** (`GET /admin/tenant/exports/{id}/download`):
   - link firmato (`signed:relative`) valido fino alla scadenza dell'export, 30 giorni dal completamento;
   - richiede comunque la password confermata negli ultimi 15 minuti (`reauth`, `ProtectedDownload`);
   - `410 EXPORT_EXPIRED` se l'export è scaduto;
   - nome del file neutro `export-AAAA-MM-GG.zip`, `Cache-Control: no-store`, audit `tenant.export_downloaded`.

   Alla scadenza il job notturno cancella il file e porta l'export a `EXPIRED`. L'anonimizzazione di un ospite cancella subito gli export pronti completati dopo la sua creazione (I22).
8. **Feed** (06 punti 3–5):
   - **Conferma**: `POST /admin/tenant/offboarding/channels/{feedId}/confirm-removed` (ri-autenticazione) registra `ics_feeds.removal_confirmed_at` e ritira il feed (`410`).
   - **Ritiro automatico**: il job notturno `offboarding:checks` (dispatcher con `app_admin_ops`, un job per tenant) ritira dopo 30 giorni dall'avvio i feed `FROZEN` non letti da almeno 7 giorni o mai letti.
   - **Feed ancora letto**: resta `FROZEN` e i proprietari ricevono una sola email `offboarding.feed_still_read` per feed.
   - **Rotazioni**: i feed `FROZEN` da rotazione seguono la propria regola dei 7 giorni (5.5).
   - **Chiusura dei calendari**: quando nessun feed è più servito, i calendari importati si disattivano (`feeds_disabled_at`). `channels_disconnected_confirmed_at` si valorizza solo se il gestore ha confermato ogni feed.
9. **Cancellazione secondo il DPA** (`tenant:offboarding {slug} purge [--instruction=]`, solo comando di piattaforma):
   - **Condizioni**: tenant `OFFBOARDING` e tutti i feed ritirati. Inoltre serve un export completato da almeno 30 giorni, oppure un'istruzione scritta del cliente (riassunto di 10–200 caratteri senza email né telefoni, registrato nell'audit). Anche con l'istruzione i feed devono essere ritirati (I19). Se manca qualcosa: `409 OFFBOARDING_NOT_READY` con `missing` = `feeds_retired` o `export_wait`.
   - **Cosa si cancella**: le righe di tutte le tabelle del tenant, nell'ordine di `TenantDataPurger::PURGE_ORDER`, con `app_retention`. Un test confronta l'elenco con il catalogo. Si cancellano anche gli utenti che appartenevano solo a questo tenant, con i codici di recupero e i link di reimpostazione, poi i file delle foto (dal disco di ogni foto) e degli export.
   - **Cosa resta**: la riga `tenants` (nome, slug, piano, DPA: dati della piattaforma per la fatturazione SaaS, 09 punto 6) con stato `CLOSED` e `data_purged_at`, e l'`audit_log`, che non contiene valori personali e segue la propria categoria di conservazione (7.2).
   - **Fuori dal codice**: la revoca dell'accesso della piattaforma all'account Stripe è un passo manuale della guida `docs/guide/uscita-cliente.md`, perché l'account resta della struttura. I backup scadono con la rotazione naturale.
10. **Codici di errore nuovi**:

    | Stato | Codice |
    |---|---|
    | 409 | `TENANT_OFFBOARDING` |
    | 409 | `TENANT_NOT_OFFBOARDING` |
    | 409 | `TENANT_CLOSED` |
    | 409 | `OFFBOARDING_NOT_READY` |
    | 410 | `EXPORT_EXPIRED` |

    Testi in `lang/{it,en}/api.php` e nel pannello.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Cancellare con `app_runtime` | Avrebbe richiesto di lasciare `DELETE` libero a ogni richiesta HTTP sulle tabelle protette; la decisione dopo M1 è un ruolo dedicato. |
| Connessione separata `pgsql_retention` anche nei test | La connessione non vede la transazione di `RefreshDatabase`: ogni test di cancellazione avrebbe dovuto confermare i dati e ripulirli a mano. |
| Spezzare i cicli di FK con `UPDATE … SET NULL` prima della cancellazione | Serve `UPDATE` ad `app_retention`, che deve poter solo leggere e cancellare. |
| Cancellare anche la riga `tenants` | 09 prevede di conservare i dati necessari alla fatturazione della piattaforma; la riga non contiene dati degli ospiti. |
| Link di download dell'export nell'email | Un link valido 30 giorni in una casella di posta è un rischio; il pannello chiede la password. |
| Feed ancora letto ritirato comunque dopo 30 giorni | Contraddice 06 punto 5 e I19: un'OTA vedrebbe date libere già vendute. |

## Conseguenze

- Nuova tabella `tenant_exports` con RLS forzata, nuove colonne `ics_feeds.removal_confirmed_at`, `tenants.data_purged_at`. I quattro vincoli dei due cicli sono ora `DEFERRABLE`.
- In produzione serve la variabile `DB_RETENTION_PASSWORD` (Railway) e il ruolo creato prima della migration.
- Il pannello mostra un banner e la pagina `/app/uscita` (collegata da "Account").
- Rischio: la cancellazione è irreversibile. È disponibile solo da comando di piattaforma, con conferma esplicita e condizioni verificate nel servizio.

## Impatto sui test

- Test aggiunti:
  - `Feature/OffboardingFeedsTest` (AC18);
  - `Feature/Tenancy/{TenantExportTest, TenantPurgeTest}`;
  - in `Feature/Database/DatabaseRolesTest` i privilegi di `app_retention`.
- Test modificati:
  - `Feature/Database/SchemaTest` (`tenant_exports`);
  - `Feature/Frontend/SpaRoutesTest` (`/app/uscita`).
- Invarianti toccati:
  - I19 (feed mai vuoto: `Feature/Calendar/{FrozenFeedNeverEmptyTest, RetiredFeed410Test}` invariati, più `OffboardingFeedsTest`);
  - I22 (export completi cancellati all'anonimizzazione).

## Aggiornamento della specifica

- [x] 07 "Privacy" e "Formato errori", 09 "Uscita del cliente", 10 con riferimento a questa ADR
- [ ] Versione della specifica incrementata alla verifica di M7
