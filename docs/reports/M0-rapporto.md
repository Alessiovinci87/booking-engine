# Rapporto M0 — Fondazioni tecniche

Data: 06/11/2026 · Branch: `m0-fondazioni` · Ultimo commit verificato: `ab0d9ab` (più questo rapporto)

## Esito
Completata.

Le issue #1–#10 sono referenziate con `closes #N` nei commit e si chiuderanno automaticamente al merge su `main`.

## Attività
| # | Stato | Issue | Note |
|---|---|---|---|
| 0.1 | Fatta | #1 | `docker-compose.yml`: `postgres:18` (18.6) e `redis:8` (8.10.3), tag verificati, volumi con nome (`booking-engine_pgdata`, `booking-engine_redisdata`), porte su `127.0.0.1` e configurabili (5434 e 6380). `docker/postgres/init/01-databases-extensions.sh`: `booking_engine_test` e `btree_gist` 1.8 su entrambi i database. `docs/dev/SETUP-WINDOWS.md` con i comandi PowerShell passo per passo, compresa la rimozione di XAMPP dal PATH di sistema con backup. |
| 0.2 | Fatta | #2 | Scheletro Laravel 13 (framework 13.40.0) nella root; `docs/`, `README.md` e `CLAUDE.md` non toccati. `.env.example` completo, `APP_TIMEZONE=UTC`, lingua `it`. Tolti tabella, modello e factory `User` (sono entità di M1) e la pagina `welcome`, che conteneva testi non tradotti. |
| 0.3 | Fatta | #3 | Pest 5.3 con plugin Laravel, Larastan 3.14 a livello 6, Pint (preset laravel). Script `test`, `lint`, `analyse`. |
| 0.4 | Fatta | #4 | `resources/js/panel/main.tsx` su `/app/{any?}` (solo it) e `resources/js/guest/main.tsx` su `/p/{tenantSlug}/{propertySlug}/{any?}` (it/en con selettore). Vite 8, React 19, React Router 8, TanStack Query 5, react-i18next 17, Tailwind 4, TypeScript 7 strict. Script npm `build`, `dev`, `typecheck`. |
| 0.5 | Fatta | #5 | `docker/postgres/init/02-roles.sh`, idempotente, con password lette solo dall'ambiente: `app_migrator` proprietario, `app_runtime` NOSUPERUSER/NOBYPASSRLS con solo DML tramite `ALTER DEFAULT PRIVILEGES`, `app_admin_ops`. Connessioni `pgsql` (predefinita, `app_runtime`), `pgsql_migrator`, `pgsql_admin_ops`. I test fanno le migration come `app_migrator` e girano come `app_runtime`. |
| 0.6 | Fatta | #6 | `App\Domain\Tenancy\TenantContext::run()` usa `set_config('app.tenant_id', ?, true)` con parametro legato. Migration di `app_current_tenant()` identica a 08. Middleware `WithTenantContext` (alias `tenant`). Per i job: `TenantAwareJob` + `InteractsWithTenant` (tenant_id serializzato) + `SetTenantContextForJob`. |
| 0.7 | Fatta | #7 | `tests/Feature/Architecture/RlsCatalogTest` e `CompositeFkCatalogTest`. Provati in negativo e in positivo con una migration di prova, poi rimossa (vedi "Test"). |
| 0.8 | Fatta | #8 | Segnaposto `InventoryService` e `SafeHttpFetcher`; tre regole in `tests/Arch/`. Ogni regola provata con violazioni temporanee in `app/` e con le eccezioni ammesse. |
| 0.9 | Fatta | #9 | `.github/workflows/ci.yml`: ubuntu-latest, PHP 8.4, servizi `postgres:18` e `redis:8`, stessi script di init, audit, lint, analisi, migration come `app_migrator`, test come `app_runtime`, `npm ci`, audit, typecheck, build. |
| 0.10 | Fatta | #10 | Aree `app/Domain/<Area>` e `app/Http/Controllers/Api/V1/{Admin,Public}` come da ADR-0006. Comandi reali in `CLAUDE.md`; `10-TRACEABILITY.md` aggiornato alla versione 1.2. |

## Test
- **Invarianti e criteri coperti**: base di I5/AC11 (`Feature/Architecture/RlsCatalogTest` e isolamento su tabella sonda in `Feature/Tenancy/TenantContextTest`), base di I17 (`Feature/Architecture/CompositeFkCatalogTest`), controlli statici di 10 (`Arch/InventoryWritesTest`, `Arch/StateTransitionsTest`, `Arch/ExternalHttpTest`). I test completi di I5/I17 sulle tabelle reali (`TenantIsolationApiTest`, `CrossTenantInsertRejectedTest`) arrivano con M1.
- **Risultato CI**: verde sull'ultimo commit di codice, run [42217264041](https://github.com/Alessiovinci87/booking-engine/actions/runs/42217264041), 56 test e 108 asserzioni, 0 vulnerabilità da `composer audit` e `npm audit`. In locale su Windows `composer test`, `composer lint`, `composer analyse`, `npm run typecheck` e `npm run build` sono tutti verdi.
- **Criterio di uscita "una tabella di prova senza RLS fa fallire la CI"**: provato sul branch temporaneo `m0-prova-rls` (commit `00438f0`, mai unito; il branch è stato cancellato dopo la prova). La migration creava `ci_probe_parents` e `ci_probe_children` con `tenant_id`, senza RLS e con una FK semplice. La CI è fallita, run [42216858650](https://github.com/Alessiovinci87/booking-engine/actions/runs/42216858650), con 2 test falliti e 42 passati:
  ```
  FAILED  Tests\Feature\Architecture\CompositeFkCatalogTest
  - ci_probe_children_parent_id_fkey: ci_probe_children -> ci_probe_parents
  FAILED  Tests\Feature\Architecture\RlsCatalogTest
  - ci_probe_children (RLS abilitata: no, forzata: no)
  - ci_probe_parents (RLS abilitata: no, forzata: no)
  ```
- **Prova locale dei test di catalogo (0.7)**, con migration temporanea in `database/migrations`:
  - versione errata: `catalog_probe_parents` con RLS abilitata ma **non forzata**, `catalog_probe_children` senza RLS e con FK semplice. Entrambi i test falliscono e indicano `catalog_probe_children_parent_fk`, `catalog_probe_children (abilitata: no, forzata: no)` e `catalog_probe_parents (abilitata: sì, forzata: no)`;
  - versione corretta (RLS abilitata e forzata, FK composita `(tenant_id, parent_id)`): entrambi passano, quindi niente falsi positivi;
  - migration rimossa; la suite completa torna verde e nel database di test non restano tabelle di prova.
- **Prova dei controlli architetturali (0.8)**: con tre classi temporanee in `app/ArchProbe/`, che usavano la facade `Http` più `file_get_contents`, il modello e la tabella delle occupazioni, e `update(['status' => ...])`, tutte le regole hanno fallito. La regola `arch()` sul modello scatta solo quando la classe esiste: verificato con un modello temporaneo, e coperto prima di allora dalla scansione testuale (vedi "Scelte minori"). Con le eccezioni ammesse (`SafeHttpFetcher` e `App\Domain\Payments\Stripe` che usano `Http`) i controlli passano.
- **Prova di `SETUP-WINDOWS.md`**: copia pulita clonata da GitHub, poi passi 6–8 della guida (`composer install`, `npm ci`, migration, `composer test`, lint, analisi, typecheck, build), tutti verdi. I passi 1–2 (Herd, Docker, PATH) li hai eseguiti tu oggi seguendo le stesse istruzioni.
- **Test aggiunti**:
  - `Unit/MoneyConventionTest`
  - `Feature/ApplicationTest` (UTC, lingua it, redirect, `/up`)
  - `Feature/Frontend/SpaRoutesTest` (rotte delle due SPA, parità delle chiavi it/en)
  - `Feature/Database/DatabaseRolesTest` (attributi dei ruoli, proprietà delle tabelle, solo DML, nessun DDL a runtime, `app_admin_ops` in sola lettura)
  - `Feature/Tenancy/TenantContextTest` (esecuzione come `app_runtime`, SET LOCAL al primo livello e nei savepoint, UUID non valido, run annidati, 0 righe senza contesto, solo righe di A, insert con B sotto A rifiutato, rollback)
  - `Feature/Tenancy/TenantMiddlewareTest` (404 senza tenant, transazione con tenant, tenant_id del client ignorato)
  - `Feature/Tenancy/TenantJobTest` (serializzazione, contesto nel job)
  - `Feature/Architecture/RlsCatalogTest`
  - `Feature/Architecture/CompositeFkCatalogTest`
  - `Arch/InventoryWritesTest`
  - `Arch/ExternalHttpTest`
  - `Arch/StateTransitionsTest`
  - `Arch/DomainStructureTest`

## Scostamenti dalla specifica
- Nessuno. Non serve nessuna ADR: le scelte qui sotto rientrano in ADR-0006 o nei dettagli già decisi nel prompt di avvio.

## Scelte minori prese
- **Porte host configurabili** (decise da te durante la 0.1): PostgreSQL `"${DB_HOST_PORT:-5434}:5432"` e Redis `"${REDIS_HOST_PORT:-6380}:6379"`, in `docker-compose.yml`, `.env.example`, `.env` locale e `SETUP-WINDOWS.md`. La 5433 era occupata dal container `awareness-db`; la 6380 evita conflitti con Redis di altri progetti (es. casa-wallet). La CI usa le porte standard 5432/6379 nei propri container.
- Porte Docker esposte solo su `127.0.0.1`, non sulla rete locale.
- `app_admin_ops`: `BYPASSRLS` e **sola lettura** (SELECT). Basta ai dispatcher, che selezionano gli ID mentre le scritture avvengono nei job per singolo tenant con `app_runtime`. Se M1 o il supporto richiederanno scritture, andranno concesse esplicitamente.
- `app_runtime`: solo SELECT/INSERT/UPDATE/DELETE, niente TRUNCATE, REFERENCES o TRIGGER; EXECUTE sulle funzioni create da `app_migrator`. Revocati `CONNECT` e `CREATE` a PUBLIC.
- Script di init in bash: la CI li esegue identici con `psql` sul servizio, perché i service container di GitHub non possono montare la repository.
- `TenantContext`: istanza `scoped` (una per richiesta o job). Un run annidato con lo stesso tenant riusa la transazione; con un tenant diverso solleva `TenantContextConflict`. Il messaggio di `InvalidTenantId` non riporta il valore ricevuto.
- `TenantResolver` è un'interfaccia; il risolutore predefinito `UnresolvedTenantResolver` non risolve nessun tenant, quindi le rotte con il middleware `tenant` rispondono 404 finché M1/M2 non introducono membership e slug.
- I test di isolamento usano una tabella sonda `tenancy_probe` creata da `app_migrator` e rimossa dopo ogni test. Non è una tabella di dominio.
- `phpunit.xml`: database `booking_engine_test` e driver di test forzati (`force="true"`), così le variabili d'ambiente della CI non possono puntare i test al database principale.
- Sessioni, cache e code su Redis; tolta la migration `users`, restano quelle di `cache` e `jobs`/`failed_jobs` dello scheletro.
- Posizione prevista del modello delle occupazioni: `App\Domain\Inventory\Models\InventoryOccupancy` (la regola `arch()` accetta anche `App\Models\InventoryOccupancy`). La scansione testuale cerca sia `inventory_occupancies` sia `InventoryOccupancy` in `app/`.
- Regola 2: oltre a `Http`, Guzzle e `file_get_contents` sono vietati anche `Illuminate\Http\Client`, `Psr\Http\Client`, `fopen`, `curl_*`, `fsockopen`, `stream_socket_client` e `get_headers`. Le letture di file locali in `app/` dovranno usare `Storage`/`File`.
- Regola 3: cerca `'status' =>`, `"status" =>` e le varianti senza spazio.
- Larastan analizza `app`, `bootstrap/app.php`, `database` e `routes`. Ignora solo l'avviso "trait non usato" per `InteractsWithTenant`, che oggi è usato solo dai test.
- `/` reindirizza a `/app`. Il titolo delle pagine usa `config('app.name')`. La lingua dell'ospite si sceglie con il selettore, viene ricordata in `localStorage` (con try/catch) e altrimenti segue la lingua del browser, con l'italiano come predefinito.
- Font di sistema al posto di Instrument Sans: nessuna risorsa esterna, in vista della CSP di 08.
- Toolchain frontend alle versioni correnti: Vite 8 e laravel-vite-plugin 3, perché lo scheletro Laravel 13.0.0 era ancora su Vite 7, incompatibile con `@vitejs/plugin-react` 6. Rimosso axios, che non serviva.
- Script `composer dev` senza Pail e con `queue:work`: Pail e Horizon richiedono `pcntl`, assente su Windows. Lo script `setup` lancia le migration con `--database=pgsql_migrator`.
- `composer.json`: nome `alessiovinci87/booking-engine` e licenza `proprietary`, al posto di MIT dello scheletro.
- CI: si attiva su `main`, sui branch `m[0-9]*` e sulle PR; azioni alle ultime major (checkout v7, cache v6, setup-node v7); include `composer audit` e `npm audit --audit-level=high` come chiede 08.

## Problemi aperti e rischi per la milestone successiva
- **Regola 3 troppo larga per M1+**: la scansione di `'status' =>` in tutto `app/` colpirà anche usi legittimi, come le regole di validazione nelle Form Request (`'status' => ['in:...']`) e l'output delle API Resource (`'status' => $this->status`). Quando arrivano i modelli con macchina a stati (M1.3/M3.4) va sostituita con un controllo mirato alle scritture (`update`/`fill`/`forceFill`/`create` sui modelli con stato). È il modo concordato per il segnaposto, quindi cambiarlo richiede una tua conferma (o una ADR se cambia il comportamento concordato).
- **Disco D: in exFAT**: il primo `composer install` ha impiegato oltre 15 minuti ed è fallito una volta (Composer è ripiegato su un clone git di `doctrine/inflector`, rifiutato per "dubious ownership"). Il secondo tentativo è riuscito. Lo stesso install sulla copia di verifica su C: è stato molto più rapido. exFAT inoltre non supporta i collegamenti simbolici (`storage:link`). Tutto documentato in `SETUP-WINDOWS.md`. Se la lentezza pesa, valuta un disco NTFS.
- `config/auth.php` fa ancora riferimento a `App\Models\User` dello scheletro, che non esiste più. Va riallineato in M1.4, quando si crea il modello `User` di 02.
- Le issue #1–#10 restano aperte fino al merge.
- GitHub segnala che `ubuntu-latest` passerà a Ubuntu 26 da ottobre 2026: possibili differenze di client `psql` o pacchetti nella CI.
- `composer audit` e `npm audit` in CI possono fallire per nuove vulnerabilità pubblicate, anche senza modifiche al codice. È voluto, ma può bloccare una PR.
- Le sessioni di Claude Code avviate prima della modifica del PATH vedono ancora XAMPP: nei comandi ho ricaricato il PATH dal registro. Dopo un riavvio di Claude Code il problema non si presenta.

## Ore impiegate (stima) rispetto al previsto
Circa 3 ore di lavoro effettivo in una sessione il 06/11/2026, di cui quasi un'ora di attesa per le installazioni sul disco exFAT. Il previsto è il periodo 16 novembre – 18 dicembre 2026, in parallelo alla Fase 0: nessun rischio di superare la stima (regola "Quando ci si ferma", punto 5).

## Decisioni dopo la verifica
Verifica di M0 completata e approvata da Alessio il 06/11/2026.

- **Regola 3 (stati)**: approvata la sostituzione in M1.3 con:
  - (a) **protezione a runtime**: i modelli con macchina a stati sollevano un'eccezione se `status` viene scritto fuori da una classe in `app/Domain/*/StateMachines`;
  - (b) **controllo statico limitato alle scritture** fuori dalle StateMachines: `update`, `fill`, `forceFill`, `create`, `setAttribute('status')` e l'assegnazione `->status =`. Validazioni e API Resource restano consentite.

  Nessuna ADR: la regola resta la stessa, cambia solo la precisione della verifica. Quando la si implementa va aggiornata la riga corrispondente in `docs/10-TRACEABILITY.md` (sezione "Controlli statici in CI").
- **CI con versioni fisse**: `runs-on: ubuntu-24.04` e `node-version: '24'` al posto di `ubuntu-latest` e `lts/*`. Commit "CI: versioni fisse di runner e Node", CI verde: run [42221298612](https://github.com/Alessiovinci87/booking-engine/actions/runs/42221298612). Supera il rischio della migrazione di `ubuntu-latest` a Ubuntu 26.
- **Disco D: in exFAT**: consigliato lo spostamento su NTFS prima di M1 (decisione di Alessio).
