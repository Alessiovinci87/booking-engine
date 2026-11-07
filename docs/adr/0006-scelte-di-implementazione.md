# ADR-0006 — Scelte di implementazione predefinite

- **Stato**: Accettata
- **Data**: 2026-11-06
- **Documenti toccati**: 11-MILESTONES, CLAUDE.md
- **Origine**: piano a milestone; obiettivo: nessuna scelta tecnica lasciata all'improvvisazione durante lo sviluppo

## Contesto

La specifica fissa lo stack (Laravel 13, PHP 8.4, PostgreSQL con RLS, Redis, React + TypeScript, Stripe Connect, iCal) ma non librerie, struttura del codice e ambiente di sviluppo. Lo sviluppo avviene su Windows, con la repository su un disco esterno (`D:\booking-engine`), tramite Claude Code.

## Decisione

### Ambiente di sviluppo locale (Windows)
| Elemento | Scelta | Motivo |
|---|---|---|
| PHP, Composer, Node | **Laravel Herd** per Windows (versione gratuita), PHP 8.4, Node LTS | PHP nativo su Windows, più veloce di Docker con cartelle su disco esterno |
| PostgreSQL e Redis | **Docker Desktop** con `docker-compose.yml` nella repository (immagini ufficiali `postgres` versione stabile più recente e `redis`) | stessi servizi della produzione, senza installarli su Windows |
| Code in locale | `php artisan queue:work` | **Horizon non funziona su Windows** (richiede l'estensione `pcntl`): si usa solo in staging e produzione |
| Webhook Stripe in locale | **Stripe CLI** (`stripe listen --forward-to`) | |
| File in locale | disco `local` di Laravel; S3 compatibile solo in staging e produzione | |

### Struttura del codice
- Un'unica applicazione Laravel nella root della repository; la cartella `docs/` resta intoccata dal codice.
- Logica di dominio in `app/Domain/<Area>/` (Inventory, Reservations, Pricing, Payments, Calendar, Quotes, Privacy, Tenancy), con servizi, azioni, enum degli stati e macchine a stati.
- Controller sottili in `app/Http/Controllers/Api/V1/{Admin,Public}`, validazione con Form Request, risposte con API Resource.
- Integrazioni esterne dietro interfacce: `PaymentGateway` (implementazioni `StripePaymentGateway` e `FakePaymentGateway` per i test), `ChannelManagerProvider`, `ComplianceProvider`, `Mailer` tramite outbox.

### Librerie
| Area | Scelta |
|---|---|
| Test | Pest (con `arch()` per i controlli architetturali), Playwright per E2E; `@axe-core/playwright` per l'accessibilità e Lighthouse in locale per le prestazioni della pagina ospite (da M8, ADR-0017) |
| Analisi statica e stile | Larastan (livello 6 all'inizio, 8 da M7 senza baseline: ADR-0018 punto 18), Laravel Pint |
| Stripe | `stripe/stripe-php` ufficiale |
| iCal | `sabre/vobject` |
| MFA | `pragmarx/google2fa` (TOTP) |
| Frontend | React + TypeScript con Vite, **SPA** servita da Laravel (non Inertia: le schermate usano l'API REST `/api/v1` della specifica) |
| Routing e dati frontend | React Router, TanStack Query |
| Stile | Tailwind CSS |
| Traduzioni | `react-i18next` (frontend), file di lingua Laravel (email e messaggi API) |
| Errori | Sentry, regione UE |
| Email | Resend (verificare in M6 la regione di invio e il trasferimento dati, 09) |
| Immagini | `intervention/image` v3 con driver GD (integrazione di M2, vedi sotto) |

### Frontend
- Due applicazioni React nella stessa build Vite: **pannello** su `/app` (solo italiano) e **pagina ospite** su `/p/{tenantSlug}/{propertySlug}` (italiano e inglese).
- La pagina ospite è progettata prima per il telefono.
- Nessun codice di calcolo di prezzi o disponibilità nel frontend.

### Hosting (deciso in M6, criteri già fissati)
Server in UE gestiti con Laravel Forge; staging e produzione separati; PostgreSQL e Redis sullo stesso server nel pilota, con backup cifrati esterni (08). Il fornitore si sceglie in M6 confrontando prezzo e disponibilità del momento: prima scelta un provider europeo (es. Hetzner, OVH, Scaleway).

### Flusso di lavoro con Git
- Un branch per milestone (`m1-modello-dati`, …), una issue GitHub per attività, commit che chiudono le issue (`closes #N`).
- Merge su `main` con pull request solo quando la CI è verde e la "Definition of Done" della milestone è soddisfatta.
- Messaggi di commit in italiano.

### Integrazioni successive
| Data | Milestone | Libreria | Motivo |
|---|---|---|---|
| 2026-11-23 | M2 (attività 2.2) | `intervention/image` v3, driver GD (estensioni PHP `gd` ed `exif`) | **Privacy delle foto**: ogni immagine caricata dal gestore si decodifica e si ricodifica in WebP (1600 px e miniatura 400 px), eliminando tutti i metadati EXIF, compresa la posizione GPS che spesso rivela l'indirizzo di casa o le abitudini di chi scatta (09). L'originale non si conserva. GD è già disponibile in Herd e nella CI, senza dipendenze di sistema come Imagick. |
| 2026-11-07 | M3 (attività 3.7) | `react-day-picker` 9 con `date-fns` 4 (solo frontend) | **Calendario della pagina ospite**: selezione di un intervallo su due mesi, giorni disabilitati da una funzione (notti non disponibili, partenza al massimo fino alla prima notte occupata), localizzazione IT/EN, tastiera e lettori di schermo già gestiti. `date-fns` è la dipendenza di localizzazione richiesta dalla libreria; le date di soggiorno restano stringhe `YYYY-MM-DD` e nessun calcolo di prezzo o disponibilità avviene nel frontend. |

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Laravel Sail / tutto in Docker su Windows | lento con la repository su un disco esterno; richiede di spostare il progetto dentro WSL |
| Inertia.js | la specifica prevede un'API REST versionata usata da entrambe le interfacce |
| Due repository separate (backend e frontend) | complessità inutile per una persona sola |

## Conseguenze

Horizon e alcune differenze di filesystem si vedono solo in staging: per questo la CI gira su Linux e lo staging arriva in M6, prima del pilota.

## Impatto sui test

Nessun invariante toccato. I controlli architetturali (10, "Controlli statici in CI") si implementano con Pest `arch()` in M0.
