# Rapporto M7 — Completamento

Data: 07/11/2026 · Branch: `m7-completamento` (da `m6-rilascio`) · PR verso `m6-rilascio` senza merge

## Esito
**Completata con eccezioni.** Fatte 7.1, 7.2, 7.4 e 7.5, con un commit per attività che riporta `Closes #N`. Resta fuori **7.3** (correzioni dal pilota, #64), come previsto: dipende dal pilota, che non è ancora partito.

Criteri di uscita di M7 (11):
- **AC18 verde**: `Feature/OffboardingFeedsTest` più `Feature/Calendar/{FrozenFeedNeverEmptyTest, RetiredFeed410Test}`.
- **Job di conservazione con durate validate: non ancora.** Il job c'è e gira ogni notte, ma nessuna categoria è validata: per tutte gira in **simulazione**, come chiesto. Diventa effettivo categoria per categoria, quando il professionista convalida le durate con `php artisan retention validate`.
- **Nessun problema grave aperto dal pilota**: non applicabile finché il pilota non parte.

Tutte le decisioni sono nell'**ADR-0018 (Proposta, punti 1–25)**. Nessun comportamento concordato è cambiato. C'è **una deviazione dalla lettera di 08**, sul ruolo dell'accesso di supporto (punto 21, vedi "Scostamenti").

## Attività
| # | Stato | Issue | Commit | Note |
|---|---|---|---|---|
| 7.1 | Fatta | #62 (chiusa) | `97e2d66` | Uscita del cliente, comando `tenant:offboarding`, pagina `/app/uscita` con banner. Dettagli sotto la tabella. |
| 7.2 | Fatta | #63 (chiusa) | `f2f5996` | Conservazione dei dati con `RetentionService` e job notturno `retention:nightly` (02:30). Dettagli sotto la tabella. |
| 7.3 | Non iniziata | #64 (aperta) | — | Dipende dal pilota. |
| 7.4 | Fatta | #65 (chiusa) | `513b2f4` | Larastan a livello 8 su `app`, `bootstrap`, `database` e `routes`, senza baseline e senza errori ignorati. Dettagli sotto la tabella. |
| 7.5 | Fatta | #75 | `COMMIT75` | Accesso di supporto in sola lettura. Dettagli sotto la tabella. |

**7.1 — uscita del cliente** (`OffboardingService`)
- **Avvio**:
  - il tenant passa a `OFFBOARDING` e le vendite si chiudono (pagina ospite e preventivi rispondono 404);
  - i feed passano a `FROZEN` e restano mai vuoti;
  - il pannello resta in sola lettura (`409 TENANT_OFFBOARDING`), salvo cancellazioni, rimborsi, diritti degli ospiti ed export.
- **Export completo asincrono**:
  - ZIP con un CSV per tabella, `export.json` e `prenotazioni_future.csv`;
  - si scarica con un link firmato valido 30 giorni e chiede la password confermata;
  - un'email avvisa che l'export è pronto, senza link.
- **Conferma per feed** della rimozione dall'OTA, con l'ultima lettura visibile.
- **Ritiro automatico** dei feed dopo 30 giorni, solo se non letti da 7 giorni. Un feed ancora letto non si ritira mai da solo: il gestore riceve un'email.
- **Cancellazione secondo il DPA**:
  - con il ruolo `app_retention`, poi stato `CLOSED`;
  - restano la riga del tenant e l'audit.

**7.2 — conservazione dei dati** (`RetentionService`)
- Categorie A–H di 09 con le durate attuali come valori provvisori.
- Le categorie **senza `validated_at` girano in simulazione**: registrano nell'audit cosa farebbero, senza modificare nulla.
- Si convalida una categoria con `php artisan retention validate {categoria} --by="nome" --date=AAAA-MM-GG`.
- Il nome di chi convalida resta in `retention_policies` e non nell'audit.
- Cancellazioni con il ruolo `app_retention` (solo `SELECT` e `DELETE`); anonimizzazione (B, D) con `GuestPrivacyService`, su tutte le copie (I22).

**7.4 — analisi statica**
- 139 errori corretti nel codice, senza cambi di comportamento (ADR-0018 punto 18).
- `OffboardingFeedsTest` aveva un test instabile, legato al cambio di secondo: corretto fermando l'orologio.

**7.5 — accesso di supporto**
- **Operatore di piattaforma**: un utente con flag e MFA obbligatoria, senza membership.
- **Comandi**:
  - `support:operator` segna l'utente come operatore;
  - `support:open {tenant} --operator= --reason= --hours=` crea un'autorizzazione da 1 a 2 ore, con il limite controllato anche dal database;
  - `support:close` chiude subito gli accessi aperti.
- **Durante l'accesso**:
  - il pannello è in sola lettura e il server rifiuta ogni richiesta diversa da GET (`403 SUPPORT_READ_ONLY`);
  - restano chiusi anche gli export con dati personali;
  - il pannello mostra il banner "Accesso di supporto".
- **Per il gestore**:
  - la pagina "Accessi di supporto" (`/app/accessi-supporto`) mostra operatore, motivo, durata, prima e ultima lettura;
  - può revocare l'accesso, con la password confermata;
  - l'audit è visibile nel tenant.
- Guida: `docs/guide/accesso-supporto.md`.

Documentazione:
- ADR-0018 (punti 1–25, Proposta);
- 07 ("Privacy", "Formato errori", "Accessi di supporto"), 08 ("Autenticazione"), 09 ("Uscita del cliente", "Conservazione");
- 10 (servizi e righe 7.1, 7.2, 7.5);
- `CLAUDE.md` e ADR-0006 (Larastan 8);
- guide `docs/guide/uscita-cliente.md` e `docs/guide/accesso-supporto.md`.

## Test
- `composer test`: **899 test verdi** (5565 asserzioni) (Unit, Feature su PostgreSQL reale come `app_runtime`, Arch).
- `composer lint` (Pint) e `composer analyse` (Larastan **livello 8**, senza baseline): verdi.
- `npm run typecheck` e `npm run build`: verdi.
- E2E `npx playwright test m2-panel m3-guest m5-quote m6-acceptance m6-password`: **10 verdi**.
- CI: verde su `97e2d66`, `f2f5996` e `513b2f4`; CI75.

| Criterio | Test |
|---|---|
| AC18 | `Feature/OffboardingFeedsTest` (10 test), `Feature/Tenancy/{TenantExportTest, TenantPurgeTest}` |
| I19 | `Feature/Calendar/{FrozenFeedNeverEmptyTest, RetiredFeed410Test}`, `Feature/OffboardingFeedsTest` |
| I22 | `Feature/Privacy/RetentionServiceTest` (categoria B su tutte le copie), `Feature/Tenancy/TenantExportTest` (export completi cancellati all'anonimizzazione) |
| Conservazione in simulazione ed esecuzione | `Feature/Privacy/RetentionServiceTest` (13 test: ogni categoria nelle due modalità, privilegi di `app_retention`, dispatcher, comandi) |
| Accesso di supporto | `Feature/Tenancy/SupportAccessTest` (15 test: lettura, rifiuto lato server, export chiusi, scadenza, revoca, isolamento tra tenant, comandi, vincolo delle 2 ore, audit) |

Test aggiunti:
- `Feature/OffboardingFeedsTest`;
- `Feature/Tenancy/{TenantExportTest, TenantPurgeTest, SupportAccessTest}`;
- `Feature/Privacy/RetentionServiceTest`.

Test modificati:
- `Feature/Database/{DatabaseRolesTest, SchemaTest}`;
- `Feature/Architecture/RlsCatalogTest`;
- `Feature/Frontend/SpaRoutesTest`.

## Scostamenti dalla specifica
Tutti nell'ADR-0018. Quelli da guardare:
1. **Accesso di supporto con `app_runtime`, non con `app_admin_ops`** (punto 21).
   - 08 dice "solo con ruolo `app_admin_ops`", ma quel ruolo ha `BYPASSRLS`.
   - L'operatore usa invece `app_runtime` con il tenant dell'autorizzazione: è RLS a impedirgli di vedere altri tenant, non solo il codice.
   - 08 è aggiornato con il riferimento all'ADR. **Da confermare in verifica.**
2. **Riga `tenants` e audit conservati dopo la cancellazione** (punti 8–9). Servono alla fatturazione della piattaforma e alla prova delle operazioni; non contengono dati degli ospiti.
3. **Conservazione in simulazione** finché il professionista non convalida (punti 11–17). Il criterio di uscita "durate validate" resta quindi aperto.
4. **Export completo senza link nell'email** (punto 7). Il link è solo nel pannello, con la password confermata.

## Scelte minori prese
- **Cicli di FK**: i quattro vincoli dei due cicli (preventivi ↔ prenotazioni, strutture ↔ politiche) sono `DEFERRABLE INITIALLY IMMEDIATE`. Il comportamento non cambia e `app_retention` cancella senza `UPDATE`.
- **Test con `app_retention`**: nei test e in CI il ruolo si usa con `SET LOCAL ROLE` dentro la transazione del test. In produzione si usa la connessione `pgsql_retention` (variabile `DB_RETENTION_PASSWORD`).
- **Supporto, varie**:
  - una nuova autorizzazione dello stesso operatore chiude la precedente;
  - il gestore può revocare anche durante l'uscita;
  - l'istante di confronto arriva dall'applicazione (stesso orologio dei controlli in PHP).
- **Revoca dell'accesso**: ri-autenticazione come le altre azioni sensibili.

## Punto aperto del cambio password: chiuso
- Era il punto aperto 8 del rapporto M1: il controllo delle password compromesse era usato solo da `tenant:create` e "in futuro dal cambio password".
- Con 6.11 (ADR-0016, accettata) il cambio password e la reimpostazione usano la stessa `PasswordPolicy`:
  - minimo 12 caratteri;
  - controllo Have I Been Pwned con il solo prefisso di 5 caratteri dello SHA-1.
- Le sessioni si revocano al microsecondo e un'email avvisa del cambio.
- Copertura: `Feature/Auth/PasswordManagementTest` ed E2E `m6-password.spec.ts`, verdi anche su questo branch.
- In M7 non è servito altro: **il punto è chiuso**.

## Problemi aperti e rischi
1. **Durate di conservazione da validare** con il professionista. Fino ad allora il job registra solo cosa farebbe.
2. **7.3** si apre con il pilota. Prove con Stripe reale e merge di M4, M5 e M6 restano rinviati, come da istruzioni.
3. **Produzione**:
   - creare il ruolo `app_retention` prima della migration `2027_04_01_000100`;
   - impostare `DB_RETENTION_PASSWORD` su Railway.
4. **Operatori di supporto**: nessuno è ancora creato. Va scelto chi lo sarà e con quale email della piattaforma.
5. **Versione della specifica** da incrementare dopo la verifica di M7.

## Ore impiegate (stima) rispetto al previsto
11 non stima le ore di M7: indica solo il periodo aprile – maggio 2027, 7.3 compresa. Impiegate senza 7.3: circa 2 giornate di sviluppo assistito.
