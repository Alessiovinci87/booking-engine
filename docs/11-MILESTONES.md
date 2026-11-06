# 11 — Piano di sviluppo a milestone

Versione: 1.2 · Data: 06/11/2026 · Modifiche 1.2: criterio di uscita della demo di M2, attività 3.2 e 3.7 dopo la verifica di M2 (ADR-0010) · Scelte tecniche: [ADR-0006](adr/0006-scelte-di-implementazione.md) · Calendario: [ADR-0007](adr/0007-sviluppo-anticipato.md) (sviluppo anticipato)

Questo documento è **vincolante**: lo sviluppo procede una milestone alla volta, nell'ordine indicato, e si passa alla successiva solo quando i criteri di uscita sono soddisfatti. Le decisioni tecniche sono già prese (specifica 01–10 e ADR-0006). Durante lo sviluppo non si discutono scelte, salvo i casi elencati in "Quando ci si ferma".

**Sviluppo e Fase 0 (ADR-0007).** Lo sviluppo non aspetta la decisione della Fase 0. Interviste, piloti e verifica dei calendari proseguono in parallelo: il loro esito positivo è la condizione per avviare il pilota (gate prima di M6), non per sviluppare. Restano due condizioni:
- accordo preliminare tra i soci (riservatezza e titolarità del codice) entro il **27/11/2026**;
- risultati della verifica dei calendari reali **prima di M5**. Se mancano, M5 procede con la specifica e la verifica passa in M6.

## Panoramica

| M | Nome | Periodo | Contiene | Dipende da | Demo a Giuseppe |
|---|---|---|---|---|---|
| M0 | Fondazioni tecniche | completata il 6 nov 2026 | ambiente, progetto vuoto, sicurezza di base, CI | — | no |
| M1 | Modello dati, accesso e audit | 9 – 20 nov 2026 | tutte le tabelle, RLS, login con MFA | M0 | accesso al pannello |
| M2 | Strutture, tariffe e prezzi | 23 nov – 4 dic 2026 | configurazione struttura, calcolo prezzi | M1 | struttura pilota con prezzi veri |
| M3 | Inventario, prenotazioni e pagina ospite | 7 – 18 dic 2026 | anti-overbooking, macchina a stati, prenotazioni manuali, pagina ospite senza pagamento | M2 | un ospite sceglie le date e vede il prezzo |
| M4 | Pagamenti Stripe | 21 dic 2026 – 15 gen 2027 (comprese le festività) | checkout, pre-autorizzazione, approvazione, rimborsi | M3 | prenotazione pagata in modalità test |
| M5 | Calendari iCal e preventivi | 18 – 29 gen 2027 | import, export per destinazione, conflitti, preventivi | M4 | data presa su Airbnb che si blocca da noi |
| — | **Gate pilota: esito Fase 0 positivo** | prima di M6 | M6 (staging, produzione, attivazione dei piloti) parte solo con la Fase 0 positiva (ADR-0007) | | |
| M6 | Comunicazioni, privacy e rilascio | 1 – 19 feb 2027 | email, metriche, privacy, staging e produzione, collaudo | M5 | collaudo completo |
| — | **Gate: pilota online** | aprile 2027 (invariato) | margine tra la fine di M6 e il pilota per Fase 0, collaudo e correzioni | | |
| M7 | Completamento durante il pilota | apr – 31 mag 2027 | uscita del cliente, conservazione automatica, correzioni | M6 | — |

Le milestone dopo il pilota (abbonamenti, channel manager, adempimenti) si pianificano a giugno 2027 con lo stesso formato.

Ogni milestone è una **fetta verticale**: backend, test e la parte di interfaccia necessaria alla demo. Così ogni due settimane c'è qualcosa di provabile.

---

## Regole del piano

### Come si lavora su una milestone
1. Si apre il branch `mN-nome` da `main`.
2. Su GitHub esiste una issue per ogni attività della milestone (codice `N.x` nel titolo).
3. Claude Code esegue le attività nell'ordine elencato; ogni commit indica la issue che chiude.
4. Ogni attività termina con i suoi test verdi. Non si inizia l'attività successiva con test rossi.
5. A fine milestone: pull request verso `main`, CI verde, checklist "Definition of Done" compilata nella descrizione della PR, aggiornamento di `docs/10-TRACEABILITY.md`.
6. Claude Code scrive il **rapporto di fine milestone** (modello in fondo) e Alessio lo porta a Claude (chat) per la verifica. Solo dopo si fa il merge e si apre la milestone successiva.

### Definition of Done (vale per ogni milestone)
- [ ] Tutte le issue della milestone chiuse
- [ ] `composer test`, `composer lint`, `composer analyse` verdi in locale e in CI
- [ ] Test per ogni invariante e criterio di accettazione indicati nella milestone, con il nome previsto in `10-TRACEABILITY.md`
- [ ] Nessuna violazione dei controlli architetturali (scritture inventario, stati, URL esterni, RLS, FK composite)
- [ ] Nessun testo utente scritto direttamente nel codice (solo chiavi di traduzione)
- [ ] `10-TRACEABILITY.md` aggiornato con servizi, tabelle, endpoint e test reali
- [ ] Eventuali scostamenti dalla specifica registrati come ADR
- [ ] Demo preparata con dati di prova (seeder)

### Quando ci si ferma
Claude Code si ferma e segnala, senza procedere con una soluzione propria, solo se:
1. la specifica è contraddittoria o non copre un caso necessario;
2. un servizio esterno (Stripe, OTA, provider) si comporta diversamente da quanto scritto;
3. un invariante non si riesce a garantire come descritto;
4. serve una scelta con effetti su sicurezza, privacy, soldi degli ospiti o costi ricorrenti;
5. una milestone supera la stima di oltre il 50%;
6. servono credenziali, account o pagamenti.

In tutti gli altri casi si procede con la specifica e con ADR-0006. Una scelta minore non prevista (nome di una classe, struttura di un componente) si prende e si annota nel rapporto di fine milestone.

### Avvio di una milestone
Per ogni milestone Claude (chat) prepara un prompt di avvio per Claude Code. Il modello è:

```
Avvia la milestone MN "<nome>" secondo docs/11-MILESTONES.md.
Prima leggi: CLAUDE.md, docs/11-MILESTONES.md (sezione MN) e i documenti indicati nella sezione.
Crea il branch mN-<nome>. Esegui le attività N.1 … N.x nell'ordine, una per volta, chiudendo la issue corrispondente.
Rispetta la Definition of Done e la regola "Quando ci si ferma".
Alla fine apri la pull request e scrivi il rapporto di fine milestone.
```

---

## M0 — Fondazioni tecniche

**Periodo:** previsto 16 novembre – 18 dicembre 2026, completata il 6 novembre 2026, in parallelo alla Fase 0. **Nessuna logica di business.**
**Documenti:** ADR-0006, 08, 10 (controlli statici).
**Obiettivo:** un progetto Laravel vuoto ma con l'ambiente, la sicurezza di base e la CI che impediranno errori strutturali nelle milestone successive.

| # | Attività |
|---|---|
| 0.1 | Ambiente locale secondo ADR-0006: Laravel Herd (PHP 8.4, Composer, Node LTS), Docker Desktop, `docker-compose.yml` con PostgreSQL ed estensione `btree_gist`, Redis. Guida passo passo in `docs/dev/SETUP-WINDOWS.md`, con percorsi e comandi PowerShell esatti |
| 0.2 | Bootstrap Laravel 13 nella root senza toccare `docs/`; `.env.example` completo; `APP_TIMEZONE=UTC`, lingua `it` |
| 0.3 | Qualità: Pest, Larastan livello 6, Pint; script Composer `test`, `lint`, `analyse` |
| 0.4 | Frontend: Vite, React, TypeScript, Tailwind, React Router, TanStack Query, react-i18next; due applicazioni (`/app` pannello, `/p/...` ospite) con una pagina di prova ciascuna |
| 0.5 | Ruoli PostgreSQL `app_migrator`, `app_runtime`, `app_admin_ops` e due connessioni Laravel (migration e runtime) |
| 0.6 | `TenantContext` con `SET LOCAL app.tenant_id`, funzione `app_current_tenant()`, middleware che apre la transazione, gestione nei job in coda |
| 0.7 | Test di catalogo: tabelle con `tenant_id` senza RLS forzata e FK tra tabelle tenant-scoped senza `tenant_id` fanno fallire i test. Verificati con una tabella di prova, poi rimossa |
| 0.8 | Controlli architetturali con Pest `arch()`: nessuna scrittura di `inventory_occupancies` fuori da `InventoryService`, nessun `update` diretto degli stati, nessun client HTTP verso URL utente fuori da `SafeHttpFetcher` (classi vuote segnaposto) |
| 0.9 | CI GitHub Actions su Linux con PostgreSQL e Redis: lint, analisi, test, build frontend |
| 0.10 | Struttura `app/Domain/...` come da ADR-0006 e aggiornamento dei comandi in `CLAUDE.md` |

**Criteri di uscita**
- `composer test` verde in locale (Windows) e in CI (Linux).
- Una tabella di prova senza RLS fa fallire la CI: provato e documentato nel rapporto.
- Un nuovo sviluppatore riesce a far partire il progetto seguendo solo `SETUP-WINDOWS.md`.

**Requisiti coperti:** base per I5, I17, controlli statici di 10.

---

## M1 — Modello dati, accesso e audit

**Periodo:** 9 – 20 novembre 2026 (sprint 1, scadenza 2026-11-20). **Documenti:** 02, 08, 09 (campi personali).
**Obiettivo:** tutte le tabelle della specifica, protette, e l'accesso sicuro al pannello.

| # | Attività |
|---|---|
| 1.1 | Migration di tutte le entità di 02, con `UNIQUE (tenant_id, id)`, FK composite, vincolo GiST su `inventory_occupancies`, vincoli `CHECK`, indici |
| 1.2 | Policy RLS forzate su tutte le tabelle tenant-scoped; permessi dei ruoli (`audit_log` senza `UPDATE`/`DELETE` per `app_runtime`) |
| 1.3 | Modelli Eloquent, enum PHP per tutti gli stati di 03, attributo `#[PersonalData]` sui campi personali |
| 1.4 | Autenticazione Sanctum SPA, MFA TOTP obbligatoria, codici di recupero, ri-autenticazione per azioni sensibili, limiti ai tentativi |
| 1.5 | Comando interno `tenant:create` per creare tenant e proprietario (nessuna registrazione pubblica) |
| 1.6 | `AuditLogger` senza valori personali; `OutboxMessage` con dispatcher e retry |
| 1.7 | Pannello: login, configurazione MFA, layout base in italiano |
| 1.8 | Seeder demo: un tenant con una struttura finta di Alghero e due unità |

**Criteri di uscita:** test I5, I17, `RlsCatalogTest`, `CompositeFkCatalogTest`, `AuditLogNoPersonalDataTest` verdi; login con MFA funzionante in demo.
**Requisiti coperti:** I5, I17, parte di AC11 e I22.

---

## M2 — Strutture, tariffe e prezzi

**Periodo:** 23 novembre – 4 dicembre 2026 (sprint 2, scadenza 2026-12-04). **Documenti:** 01, 04, 07.
**Obiettivo:** il gestore configura la struttura e il sistema calcola i prezzi esatti.

| # | Attività |
|---|---|
| 2.1 | API admin strutture, unità e traduzioni IT/EN |
| 2.2 | Upload foto con URL firmati (disco locale in sviluppo) |
| 2.3 | API tariffe e stagioni, con avviso di sovrapposizione a parità di priorità |
| 2.4 | `PriceCalculator` puro: test golden di 04 (1.585,00 €) e tutti i casi limite elencati |
| 2.5 | `UnitService`: modalità di prenotazione con vincolo I12 (applicazione + trigger), sospensione vendite. Gli stati di tenant, struttura e unità (non sono macchine a stati di 03) si cambiano solo tramite i rispettivi servizi (`UnitService`, `PropertySetupService`, servizio del tenant); `Arch/StateTransitionsTest` ammette quelle classi come eccezione esplicita (ADR-0009) |
| 2.6 | Pubblicazione della struttura con le guardie di 07, compresa la politica di cancellazione predefinita (`422 CANCELLATION_POLICY_MISSING`, ADR-0009); lo stato della struttura cambia solo tramite `PropertySetupService` |
| 2.7 | API pubblica: `GET` struttura e `POST quote-price` |
| 2.8 | Pannello: struttura, unità, foto, tariffe, stagioni, anteprima prezzo |

**Criteri di uscita:** test golden verde; I12 e I14 (parte prezzi) verdi; demo con la struttura di un pilota configurata con i suoi prezzi veri. *Alla verifica di M2 la demo del pilota è sostituita dalla demo con i dati del test golden (`tests/E2E/m2-panel.spec.ts`), perché nessun pilota è ancora disponibile; i prezzi reali si inseriranno dal pannello quando la Fase 0 porterà il primo gestore.*
**Requisiti coperti:** AC1 (parte pannello), I12, I14.

---

## M3 — Inventario, prenotazioni e pagina ospite

**Periodo:** 7 – 18 dicembre 2026 (sprint 3, scadenza 2026-12-18). **Documenti:** 02, 03, 07.
**Obiettivo:** il cuore anti-overbooking e la macchina a stati, ancora con un gateway di pagamento finto.

| # | Attività |
|---|---|
| 3.1 | `InventoryService`: `acquire`, `promoteToFirm`, `setTemporaryReason`, `move`, `release`, con lock sull'unità |
| 3.2 | `AvailabilityService` sulle tre fonti e `GET availability`. `quote-price` e `availability` su un'unità con vendite sospese o non attiva: `409 UNIT_NOT_BOOKABLE` (ADR-0010) |
| 3.3 | Interfaccia `PaymentGateway` e `FakePaymentGateway` |
| 3.4 | `ReservationStateMachine` con tutte le transizioni T1–T18 di 03, test per riga e test property-based sulle transizioni vietate |
| 3.5 | Prenotazioni manuali (T2, `UNPAID`), pagamenti offline, blocchi manuali, modifica (T18), cancellazione (T17) con rimborso sul gateway finto |
| 3.6 | Job di rilascio delle occupazioni (con gateway finto) |
| 3.7 | Pagina ospite IT/EN per telefono: struttura, foto, calendario, prezzo, dati dell'ospite; si ferma prima del pagamento. CSP di base per pannello e pagina ospite (08, "Header e frontend"): `default-src 'self'`, `frame-ancestors 'none'` per il pannello e solo i domini registrati dal gestore per la vista embed (ADR-0009). API pubblica senza cookie di sessione: niente CSRF sulle rotte `public/*`; protezione con rate limit, `Idempotency-Key` sulle `POST` e controllo dell'header `Origin` rispetto agli `embed_allowed_origins` del tenant per le chiamate dalla vista incorporata. Struttura pubblicata senza unità attive: la pagina ospite mostra "Nessuna disponibilità al momento" (chiave IT/EN) e il pannello avvisa quando si disattiva l'ultima unità attiva, senza impedirlo (ADR-0010) |
| 3.8 | Pannello: calendario, elenco e dettaglio prenotazioni, prenotazione manuale, blocchi |

**Criteri di uscita:** I1, I7, I11, I15 verdi; 20 acquisizioni parallele → una sola occupazione; AC5 e AC17 verdi.
**Requisiti coperti:** AC5, AC17, I1, I7, I11, I15.

---

## M4 — Pagamenti Stripe

**Periodo:** 21 dicembre 2026 – 15 gennaio 2027 (sprint 4 con le festività, scadenza 2027-01-15). **Documenti:** 03, 05.
**Prerequisiti esterni:** account Stripe piattaforma in modalità test con Connect attivo; Stripe CLI installata (Claude Code si ferma e chiede se mancano).
**Obiettivo:** soldi veri, in modalità test, con tutte le garanzie di 05.

| # | Attività |
|---|---|
| 4.1 | Onboarding Stripe Connect del gestore e gestione `account.updated` |
| 4.2 | `StripePaymentGateway` e `CheckoutService` con la sequenza in tre passi e il recupero tramite idempotency key |
| 4.3 | `StripeWebhookHandler` con `WebhookEvent`, verifica tenant, rilettura via API |
| 4.4 | Flusso A (immediata) e Flusso B (pre-autorizzazione, approvazione, rifiuto, scadenza, capture) |
| 4.5 | `OccupancyReleaseJob` con `checkout.sessions.expire` e gestione della sessione già completata (T6) e del pagamento tardivo (T7) |
| 4.6 | Flusso R: `CAPTURE_FAILED`, link di recupero, rilascio a 24 h |
| 4.7 | `RefundService`: macchina del rimborso, retry, segna offline |
| 4.8 | `PaymentReconciler` |
| 4.9 | Pagina ospite: checkout top-level, pagina di ritorno con attesa della conferma |
| 4.10 | Pannello: collegamento Stripe, richieste da approvare, recupero, rimborsi |
| 4.11 | Suite `Stripe/*` in modalità test, eseguita in CI notturna |

**Criteri di uscita:** AC2 (senza feed), AC3, AC4, AC9, AC10, AC14, AC15, AC19 verdi; I2, I3, I4, I9, I16, I20 verdi.

---

## M5 — Calendari iCal e preventivi

**Periodo:** 18 – 29 gennaio 2027 (sprint 5, scadenza 2027-01-29). **Documenti:** 03, 06.
**Prerequisito (ADR-0007):** risultati della verifica di Fase 0 sui feed reali di Booking e Airbnb, attesi prima dell'avvio di M5. Se non disponibili, si procede con la specifica e si aggiunge un'attività di verifica in M6.
**Obiettivo:** il calendario allineato con le OTA in topologia a stella, e i preventivi.

| # | Attività |
|---|---|
| 5.1 | `SafeHttpFetcher` con tutti i controlli anti-SSRF e relativi test |
| 5.2 | `IcalImportService`: polling con jitter, richieste condizionali, backoff, rimozione in due passi, salvaguardia anti-svuotamento |
| 5.3 | `EchoClassifier` e `ConflictDetector`; `ConflictService` |
| 5.4 | `IcsFeedBuilder` per destinazione: matrice dei flussi, UID stabili, `SEQUENCE`, accorpamento |
| 5.5 | `IcsFeedLifecycle`: stati, rotazione del token, registrazione delle letture |
| 5.6 | Salute della sincronizzazione e checklist di collegamento nel pannello |
| 5.7 | `QuoteService`, API, pannello preventivi con link WhatsApp, pagina ospite del preventivo |
| 5.8 | Pannello: conflitti, calendari importati, feed esportati |

**Criteri di uscita:** AC6, AC7, AC8, AC13, AC16 verdi; I6, I8, I13, I18, I19, I21 verdi.

---

## M6 — Comunicazioni, privacy e rilascio del pilota

**Periodo:** 1 – 19 febbraio 2027 (sprint 6, scadenza 2027-02-19).
**Gate pilota (ADR-0007):** M6 parte solo con l'esito positivo della Fase 0 (interviste, piloti, verifica dei calendari).
**Documenti:** 01, 08, 09.
**Prerequisiti esterni:** account Resend, Sentry, hosting e dominio (Claude Code si ferma e chiede).
**Obiettivo:** il sistema online, sicuro e collaudato, pronto per i gestori pilota.

| # | Attività |
|---|---|
| 6.1 | Email transazionali IT/EN tramite outbox: conferme, richieste, rimborsi avviati e completati, promemoria al gestore |
| 6.2 | `MetricsService` e schermata metriche; export CSV |
| 6.3 | Privacy: export e anonimizzazione dell'ospite su tutte le copie (I22), campi DPA del tenant, link alle informative nel checkout |
| 6.4 | Staging e produzione con Forge in UE, Horizon, backup cifrati, Sentry, monitoraggio di disponibilità |
| 6.5 | Allerte operative di 08 |
| 6.6 | Test Playwright E2E per AC1–AC17 e AC19 su staging |
| 6.7 | Revisione di sicurezza con la checklist di 08; test di ripristino del backup (AC12, I10) |
| 6.8 | Procedura di attivazione dei piloti e guida rapida per i gestori, insieme a Giuseppe |

**Criteri di uscita:** tutti i criteri di accettazione tranne AC18 verdi su staging; backup ripristinato; prima struttura pilota configurata in produzione.
**Gate:** pilota online ad aprile 2027 (invariato); tra la fine di M6 e il pilota c'è un margine di circa sei settimane.

---

## M7 — Completamento durante il pilota

**Periodo:** aprile – maggio 2027 (scadenza 2027-05-31).
**Perché dopo il pilota:** nessun cliente esce nei primi due mesi, e le prime scadenze di conservazione arrivano dopo 90 giorni. Restano comunque nel perimetro della 1.0.

| # | Attività |
|---|---|
| 7.1 | `OffboardingService` e procedura di uscita completa (06, 09) |
| 7.2 | `RetentionPolicy` configurabile e job di conservazione, con le durate validate dal professionista. Ruolo PostgreSQL dedicato `app_retention`, con `DELETE` solo sulle tabelle soggette a conservazione (compresi `audit_log` e `payment_events`, append-only per `app_runtime`), usato solo dal job di conservazione (ADR-0009) |
| 7.3 | Correzioni e miglioramenti emersi dal pilota, in ordine di gravità |
| 7.4 | Larastan a livello 8 |

**Criteri di uscita:** AC18 verde; job di conservazione attivo con durate validate; nessun problema grave aperto dal pilota.

---

## Modello del rapporto di fine milestone

```
# Rapporto M<N> — <nome>

## Esito
Completata / completata con eccezioni / non completata

## Attività
| # | Stato | Issue | Note |

## Test
- Invarianti e criteri coperti: …
- Risultato CI: link
- Test aggiunti: elenco

## Scostamenti dalla specifica
- (nessuno) oppure: descrizione + ADR

## Scelte minori prese
- …

## Problemi aperti e rischi per la milestone successiva
- …

## Ore impiegate (stima) rispetto al previsto
```
