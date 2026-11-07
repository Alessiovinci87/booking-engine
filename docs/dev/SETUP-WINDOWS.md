# Ambiente di sviluppo su Windows

Guida passo passo per far partire `booking-engine` su Windows 11, dalla macchina pulita al primo `composer test` verde.
Scelte di riferimento: [ADR-0006](../adr/0006-scelte-di-implementazione.md).

- Repository: `C:\dev\booking-engine` (percorso consigliato, su disco **NTFS**). Un disco exFAT, come il `D:` esterno usato in M0, funziona ma è più lento e ha limiti: vedi "Note sul disco D: (exFAT)" in fondo.
- PHP, Composer, Node: **Laravel Herd** (PHP 8.4) e Node LTS
- PostgreSQL 18 e Redis 8: **Docker Desktop** con `docker-compose.yml`
- Code in locale: `php artisan queue:work`. **Horizon non si usa su Windows** (richiede l'estensione `pcntl`): solo in staging e produzione.

Ogni passo indica la cartella in cui eseguire il comando. Tutti i comandi sono per **PowerShell**.

---

## 1. Prerequisiti da installare

| Programma | Link ufficiale | Note |
|---|---|---|
| Git per Windows | https://git-scm.com/download/win | |
| GitHub CLI (facoltativa) | https://cli.github.com/ | per issue e pull request |
| Laravel Herd per Windows | https://herd.laravel.com/windows | versione gratuita |
| Node.js LTS | https://nodejs.org/ | se non lo gestisci da Herd |
| Docker Desktop | https://www.docker.com/products/docker-desktop/ | backend WSL 2 |

### 1.1 Laravel Herd
1. Scarica ed esegui l'installer da https://herd.laravel.com/windows (accetta la richiesta di amministratore).
2. Apri Herd → **Settings → PHP** → installa **PHP 8.4** e impostalo come predefinito.
3. Chiudi **tutte** le finestre di PowerShell/terminale e riaprile (il PATH si aggiorna solo nelle nuove finestre).

### 1.2 Docker Desktop
1. Installa Docker Desktop e lascia attivo il backend **WSL 2**.
2. Avvialo dal menu Start e attendi che l'icona indichi *Engine running*.

## 2. Verifica dei prerequisiti

Cartella: qualsiasi, in una **nuova** PowerShell.

```powershell
(Get-Command php).Source
```
Deve indicare un percorso sotto `C:\Users\<utente>\.config\herd\bin\`. Se indica `C:\xampp\php\php.exe` (o un altro PHP), segui il passo 2.1.

```powershell
php -v
```
Deve indicare `PHP 8.4.x`.

```powershell
php -m | Select-String "pdo_pgsql|pgsql|intl|mbstring|redis"
```
Devono comparire almeno `pdo_pgsql`, `pgsql`, `intl`, `mbstring`.

```powershell
composer --version
```
Deve indicare `PHP version 8.4.x` con il percorso di Herd.

```powershell
node -v
npm -v
docker version --format '{{.Server.Version}}'
docker compose version
```

### 2.1 Se un altro PHP (es. XAMPP) viene prima di Herd

Il PATH di sistema (*Machine*) viene letto prima di quello utente: se contiene `C:\xampp\php`, quel PHP vince su Herd. Per rimuoverlo:

1. Apri **PowerShell come amministratore** (tasto destro su PowerShell → *Esegui come amministratore*).
2. Salva una copia del PATH di sistema attuale:

```powershell
[Environment]::GetEnvironmentVariable('Path', 'Machine') | Out-File -Encoding utf8 "$env:USERPROFILE\path-backup.txt"
```

3. Controlla quali voci contengono `xampp`:

```powershell
[Environment]::GetEnvironmentVariable('Path', 'Machine') -split ';' | Select-String -SimpleMatch 'xampp'
```

4. Rimuovi `C:\xampp\php` dal PATH di sistema:

```powershell
$voci = [Environment]::GetEnvironmentVariable('Path', 'Machine') -split ';' | Where-Object { $_ -and ($_.TrimEnd('\') -ine 'C:\xampp\php') }
[Environment]::SetEnvironmentVariable('Path', ($voci -join ';'), 'Machine')
```

5. Fai lo stesso controllo sul PATH utente (non serve l'amministratore):

```powershell
[Environment]::GetEnvironmentVariable('Path', 'User') -split ';' | Select-String -SimpleMatch 'xampp'
```

6. Chiudi tutte le finestre di PowerShell, riaprine una nuova e ripeti il passo 2.

Per tornare indietro: il PATH originale è in `%USERPROFILE%\path-backup.txt`; da PowerShell amministratore:

```powershell
[Environment]::SetEnvironmentVariable('Path', (Get-Content "$env:USERPROFILE\path-backup.txt" -Raw).Trim(), 'Machine')
```

XAMPP non va disinstallato: basta che il suo PHP non sia nel PATH.

> Anche gli strumenti già aperti (VS Code, Claude Code, terminali integrati) mantengono il vecchio PATH finché non vengono riavviati.

## 3. Clonare la repository

Cartella: qualsiasi. Crea la cartella `C:\dev` (se esiste già il comando non fa nulla):

```powershell
New-Item -ItemType Directory -Force C:\dev
```

```powershell
git clone https://github.com/Alessiovinci87/booking-engine.git C:\dev\booking-engine
```

Se arrivi da una copia precedente (es. `D:\booking-engine`), copia il tuo `.env` nella nuova cartella invece di crearne uno nuovo: le password dei ruoli PostgreSQL sono già impostate nel volume Docker, che non dipende dalla cartella della repository.

```powershell
Copy-Item D:\booking-engine\.env C:\dev\booking-engine\.env
```

Il nome del progetto Docker è fisso (`booking-engine` in `docker-compose.yml`), quindi la nuova cartella usa gli stessi volumi e gli stessi dati.

Tutti i passi seguenti si eseguono in `C:\dev\booking-engine`:

```powershell
Set-Location C:\dev\booking-engine
```

## 4. File `.env`

Cartella: `C:\dev\booking-engine`

```powershell
Copy-Item .env.example .env
```

Apri `.env` e sostituisci **tutti** i valori `cambia-...` con password locali a piacere (non usarle altrove). Per generarne una:

```powershell
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

Variabili da impostare:

| Variabile | Uso |
|---|---|
| `POSTGRES_SUPERUSER_PASSWORD` | superutente `postgres`, solo per gli script di inizializzazione |
| `DB_MIGRATOR_PASSWORD` | ruolo `app_migrator` (migration, proprietario delle tabelle) |
| `DB_PASSWORD` | ruolo `app_runtime` (applicazione, soggetto a RLS) |
| `DB_ADMIN_OPS_PASSWORD` | ruolo `app_admin_ops` (solo dispatcher dei job cross-tenant) |

In locale il pannello gira su `http`: lascia `SESSION_SECURE_COOKIE=false` (già in `.env.example`), altrimenti il browser non rimanda il cookie di sessione e il login non resta attivo. Se il tuo `.env` è precedente a M1, aggiungi anche `SANCTUM_STATEFUL_DOMAINS` copiandolo da `.env.example`.

Le password devono essere impostate **prima** del primo avvio di PostgreSQL: gli script di inizializzazione creano i ruoli solo quando il volume è vuoto (vedi 5.2).

`.env` non va mai committato (è in `.gitignore`).

### Porte

Per non entrare in conflitto con altri progetti, le porte host sono configurabili:

| Variabile | Predefinita | Servizio |
|---|---|---|
| `DB_HOST_PORT` | `5434` | PostgreSQL (`DB_PORT` deve avere lo stesso valore) |
| `REDIS_HOST_PORT` | `6380` | Redis (`REDIS_PORT` deve avere lo stesso valore) |

Le porte sono esposte solo su `127.0.0.1`. Per vedere chi occupa una porta:

```powershell
Get-NetTCPConnection -LocalPort 5434 -State Listen -ErrorAction SilentlyContinue
docker ps --format "table {{.Names}}\t{{.Ports}}"
```

## 5. Servizi Docker (PostgreSQL e Redis)

### 5.1 Avvio

Cartella: `C:\dev\booking-engine`

```powershell
docker compose up -d --wait
```

```powershell
docker compose ps
```

Entrambi i servizi devono risultare `healthy`. Al primo avvio gli script in `docker\postgres\init\` creano:
- i database `booking_engine` (sviluppo), `booking_engine_test` (Pest) e `booking_engine_e2e` (Playwright), con l'estensione `btree_gist`;
- i ruoli `app_migrator`, `app_runtime`, `app_admin_ops`.

Verifica:

```powershell
docker compose exec -T postgres psql -U postgres -tAc "SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname LIKE 'app_%' ORDER BY 1"
```

### 5.2 Password cambiate o ruoli da riallineare

Lo script dei ruoli è idempotente: se hai cambiato solo le password in `.env`, ricrea il container (per leggere il nuovo `.env`) e rieseguilo, senza perdere dati:

```powershell
docker compose up -d --force-recreate --wait postgres
docker compose exec -T postgres bash /docker-entrypoint-initdb.d/02-roles.sh
```

### 5.3 Ricreare il database da zero

Serve se gli script di inizializzazione sono cambiati o vuoi ripartire da un database vuoto. **Cancella i dati locali** di PostgreSQL e Redis di questo progetto (non tocca altri progetti):

```powershell
docker compose down -v
docker compose up -d --wait
```

I volumi Docker hanno un nome (`booking-engine_pgdata`, `booking-engine_redisdata`): i dati non stanno su `D:`.

### 5.3 Fermare i servizi

```powershell
docker compose stop
```

## 6. Dipendenze PHP e JavaScript

Cartella: `C:\dev\booking-engine`

```powershell
composer install
```

```powershell
php artisan key:generate
```

```powershell
npm ci
```

## 7. Migration

Le migration girano **sempre** con il ruolo `app_migrator` (connessione `pgsql_migrator`), mai con il ruolo dell'applicazione.

Cartella: `C:\dev\booking-engine`

```powershell
php artisan migrate --database=pgsql_migrator
```

### 7.1 Dati demo

Con `APP_ENV=local` il seeder crea il tenant `demo-alghero` (struttura "Casa Demo Alghero", due unità, dati inventati, un account Stripe finto `acct_demo_…` con cui le strutture demo si possono pubblicare) e il proprietario `proprietario.demo@example.test`. Il seeder si rifiuta di girare con `APP_ENV=production`:

```powershell
php artisan db:seed
```

La password del proprietario viene mostrata **una sola volta**: annotala. Al primo accesso il pannello chiede di configurare la verifica in due passaggi con un'app di autenticazione (TOTP) e mostra 10 codici di recupero. Rieseguire il seeder non cambia nulla se il tenant esiste già.

Per creare un altro tenant (la password viene chiesta in modo nascosto):

```powershell
php artisan tenant:create mio-tenant "Nome del tenant" proprietario@example.test
```

## 8. Verifiche di qualità

Cartella: `C:\dev\booking-engine`

```powershell
composer test
```

I test usano il database `booking_engine_test`: le migration vengono applicate come `app_migrator`, i test girano come `app_runtime` (soggetto a RLS).

```powershell
composer lint
composer analyse
npm run typecheck
npm run build
```

`composer lint` controlla soltanto; per correggere lo stile automaticamente: `vendor\bin\pint`.

### 8.1 Test E2E (Playwright)

Solo in locale fino a M6. La prima volta installa il browser:

```powershell
npx playwright install chromium
```

Poi, con Docker avviato e `npm run dev` **fermo** (servono gli asset di `npm run build`):

```powershell
npm run test:e2e
```

Il comando usa un database dedicato, `booking_engine_e2e` (`DB_E2E_DATABASE` in `.env`), che rigenera a ogni esecuzione (`migrate:fresh` e seeder demo). Il database di sviluppo `booking_engine` **non viene toccato**. Avvia un proprio server PHP su http://127.0.0.1:8001, collegato al database E2E, e lo chiude alla fine: puoi lasciare aperto `php artisan serve` sulla porta 8000. Accetta solo `APP_ENV=local`. Gli screenshot di ogni passo finiscono in `storage\e2e-screenshots\` (non committati).

Se il volume Docker esisteva già prima di M3, il database E2E va creato una volta, senza perdere dati:

```powershell
docker compose up -d --force-recreate --wait postgres
docker compose exec -T postgres bash /docker-entrypoint-initdb.d/01-databases-extensions.sh
docker compose exec -T postgres bash /docker-entrypoint-initdb.d/02-roles.sh
```

## 9. Avvio dell'applicazione in sviluppo

Tre finestre di PowerShell, tutte in `C:\dev\booking-engine`.

Server PHP:

```powershell
php artisan serve
```

Vite (frontend con ricaricamento automatico):

```powershell
npm run dev
```

Code (al posto di Horizon):

```powershell
php artisan queue:work
```

Per la consegna dei messaggi in uscita serve anche lo scheduler (oppure lancia a mano `php artisan schedule:run`):

```powershell
php artisan schedule:work
```

Indirizzi:
- Pannello: http://127.0.0.1:8000/app (accesso con l'utente demo, vedi 7.1)
- Pagina ospite di prova: http://127.0.0.1:8000/p/demo/casa-demo

### 9.1 Pagamenti Stripe in modalità test (da M4)

Con `PAYMENT_GATEWAY=fake` (predefinito in locale) il pagamento è la pagina "Pagamento simulato". Per Stripe vero in modalità test:

1. In `.env`: `PAYMENT_GATEWAY=stripe`, `STRIPE_KEY=pk_test_…`, `STRIPE_SECRET=sk_test_…` (chiavi della piattaforma, dashboard Stripe → Sviluppatori → Chiavi API). Mai chiavi `sk_live_`, mai nei commit o nei log.
2. Installa la [Stripe CLI](https://docs.stripe.com/stripe-cli) e accedi una volta con `stripe login`.
3. Account connesso del tenant demo, con onboarding ospitato da Stripe (dati di prova di Stripe):

   ```powershell
   php artisan stripe:demo-account
   ```

   Apri il link stampato e completa l'onboarding. Se il link scade, rilancia il comando. Per collegare un account già completato (per esempio dopo aver rigenerato il database): `php artisan stripe:demo-account --account=acct_…`.
4. In una quarta finestra, inoltro dei webhook Connect al server locale:

   ```powershell
   stripe listen --forward-connect-to http://127.0.0.1:8000/webhooks/stripe
   ```

   Il segreto si legge con `stripe listen --print-secret`: mettilo in `STRIPE_WEBHOOK_SECRET` nel file `.env` e riavvia `php artisan serve` e `php artisan queue:work`.
5. Carte di prova: `4242 4242 4242 4242` (riuscito), `4000 0025 0000 3155` (3D Secure), `4000 0000 0000 9995` (rifiutata). Scadenza futura qualsiasi, CVC qualsiasi.

Suite `Stripe/*` (API di Stripe in modalità test, fuori da `composer test`):

```powershell
$env:STRIPE_LIVE_TESTS='1'; php vendor/bin/pest -c phpunit.stripe.xml
```

Usa `STRIPE_SECRET` di `.env` e il primo account connesso con i pagamenti attivi (oppure `STRIPE_TEST_CONNECTED_ACCOUNT`). In CI gira ogni notte (`.github/workflows/stripe-nightly.yml`, segreto `STRIPE_SECRET`).

E2E con Stripe Checkout vero (server E2E sulla porta 8001):

```powershell
stripe listen --forward-connect-to http://127.0.0.1:8001/webhooks/stripe
$env:E2E_PAYMENT_GATEWAY='stripe'; $env:E2E_STRIPE_ACCOUNT='acct_…'; npx playwright test m4-stripe-checkout
```

## 10. Problemi frequenti

| Sintomo | Causa e soluzione |
|---|---|
| `could not find driver` | si sta usando un PHP diverso da Herd: passo 2.1 |
| `port is already allocated` | porta occupata da un altro progetto: cambia `DB_HOST_PORT`/`REDIS_HOST_PORT` e `DB_PORT`/`REDIS_PORT` in `.env` |
| `password authentication failed for user "app_..."` | password cambiate dopo il primo avvio: passo 5.2 |
| `failed to connect to the docker API` | Docker Desktop non è avviato |
| `pcntl` richiesta da Horizon | in locale non si usa Horizon: `php artisan queue:work` |
| `composer install` molto lento (minuti per pochi pacchetti) | disco esterno exFAT e scansione antivirus: attendi, oppure esegui di nuovo il comando (riprende dalla cache di Composer) |
| `detected dubious ownership ... git config --global --add safe.directory D:/booking-engine/vendor/...` | exFAT non registra il proprietario dei file: Composer ha ripiegato sul clone git di un pacchetto. Esegui di nuovo `composer install --prefer-dist`; non serve aggiungere eccezioni `safe.directory` per `vendor` |
| Caricamento delle foto: "Si è verificato un errore" e nel terminale di `php artisan serve` `unable to create a temporary file` | `php artisan serve` non passava `TEMP`/`TMP` al server PHP; corretto in `AppServiceProvider` (solo `local`). Riavvia `php artisan serve` |
| `php artisan storage:link` fallisce | exFAT non supporta i collegamenti simbolici; in sviluppo non serve finché non si usano file pubblici (foto da M2, con URL firmati) |

### Note sul disco D: (exFAT)

Il disco `D:` è formattato exFAT: niente proprietario dei file né collegamenti simbolici, e operazioni su molti piccoli file più lente. Solo se git segnala `detected dubious ownership` sulla repository stessa (non su `vendor`), aggiungi una volta l'eccezione:

```powershell
git config --global --add safe.directory D:/booking-engine
```

Le differenze di filesystem con Linux (permessi, maiuscole/minuscole nei nomi) le intercetta la CI, che gira su Ubuntu.
