# Rapporto M5 — Calendari iCal e preventivi

Data: 07/11/2026 · Branch: `m5-calendari-preventivi` (da `m4-pagamenti-stripe`) · PR verso `m4-pagamenti-stripe` senza merge

## Esito
Tutte le attività 5.1–5.8 sono fatte, con un commit per attività che riporta `closes #46`–`#53`; le issue si chiuderanno al merge su `main`. I criteri di uscita (AC6, AC7, AC8, AC13, AC16; I6, I8, I13, I18, I19, I21) hanno ciascuno almeno un test automatico verde.

Gli scostamenti dalla specifica sono precisazioni di casi che 06, 03 e 08 lasciano aperti, raccolte nell'**ADR-0013 (punti 1–43)**. Nessun comportamento concordato è cambiato. Come da istruzioni, nessuna ADR sullo scambio M4/M5.

**Prerequisito non soddisfatto (ADR-0007):** i risultati della verifica di Fase 0 sui feed reali di Booking e Airbnb non sono disponibili. Ho proceduto con la specifica, come previsto da 11: i formati dei feed (etichette di `SUMMARY`, eventi con orario, `Retry-After`, frequenza di lettura) sono **ipotesi** da verificare. Va aggiunta un'attività di verifica in M6.

## Attività
| # | Stato | Issue | Commit | Note |
|---|---|---|---|---|
| 5.1 | Fatta | #46 | `6a10af2` | `SafeHttpFetcher` con trasporto cURL separato (`CurlTransport`): solo `https` sulla porta 443, nomi riservati rifiutati, tutti gli indirizzi risolti devono essere pubblici, connessione all'indirizzo validato con `CURLOPT_RESOLVE` (niente DNS rebinding), redirect ricontrollati, limiti di dimensione e tempo. |
| 5.2 | Fatta | #47 | `c21f8a1` | `IcalImportService` con `sabre/vobject` 4.5.9: polling con jitter, `ETag`/`If-Modified-Since`, backoff esponenziale con `Retry-After`, rimozione in due passi, salvaguardia anti-svuotamento (risposta sospetta → date mantenute). Dispatcher ogni minuto con `app_admin_ops`. |
| 5.3 | Fatta | #48 | `4a84434` | `EchoClassifier`, `ConflictDetector`, `ConflictService` e `ConflictStateMachine`. API dei conflitti più `POST /admin/external-events/{id}/not-echo`. Email al gestore tramite outbox, senza dati dell'ospite. |
| 5.4 | Fatta | #49 | `87c8ceb` | `IcsFeedBuilder` per destinazione con la matrice dei flussi in un solo punto (`FeedContents`), UID stabili `occ-`/`ext-`, `SEQUENCE` (nuove colonne `ics_sequence`), accorpamento degli intervalli contigui. |
| 5.5 | Fatta | #50 | `d6388fc` | `IcsFeedLifecycle` e `IcsFeedStateMachine`: rotazione (vecchio feed `FROZEN` 7 giorni), ritiro, letture registrate al massimo ogni 5 minuti. `GET /ical/{token}.ics` con `ETag`/304; un feed congelato senza elementi risponde `503` e mai vuoto (I19); un feed ritirato risponde `410`. |
| 5.6 | Fatta | #51 | `fa49391` | `SyncHealthService` (`ACTIVE`/`DEGRADED`/`UNHEALTHY` a 60 e 180 minuti, `units.sync_health`, email `calendar.sync_unhealthy`), API dei calendari importati, checklist di collegamento a stella. |
| 5.7 | Fatta | #52 | `102ff25` | `QuoteService` e `QuoteStateMachine`: prezzo e valuta congelati, link HMAC ripetibile, invio per email o link/WhatsApp, accettazione con riga bloccata e un solo tentativo attivo (I21), scadenza a ogni accesso e con un job ogni 5 minuti. Pagina ospite `/q/{token}`, pannello `/app/preventivi`. |
| 5.8 | Fatta | #53 | `2466c34` | Pannello `/app/conflitti` e `/app/calendari`: calendari importati (aggiunta, URL, sincronizza ora, disattiva, rimuovi, storico), feed esportati (crea, copia, rigenera), checklist. |
| — | — | — | `287dd52` | E2E: date libere nel seed demo, locator dell'email nella pagina del preventivo. |

Documentazione:
- ADR-0013, punti 1–43, con la casella dell'aggiornamento della specifica spuntata;
- `06-CALENDAR-SYNC.md` 1.3, `07-API-CONTRACT.md` 1.4, `08-SECURITY-OPERATIONS.md` 1.3: riferimenti all'ADR-0013, percorsi e codici d'errore reali;
- `10-TRACEABILITY.md`: percorsi dei test `Feature/Calendar/…` e `Feature/Quotes/…`, servizi nuovi, righe 5.1–5.8 nello stato dell'implementazione.

## Test
- `composer test`: **767 test verdi** (4586 asserzioni, dopo il riallineamento a M4 aggiornato) (Unit, Feature su PostgreSQL reale come `app_runtime`, Arch).
- `composer lint` e `composer analyse` (Larastan 6): verdi.
- `npm run typecheck` e `npm run build`: verdi.
- E2E `npx playwright test m2-panel m5-quote`: 2 verdi (creazione del preventivo nel pannello, copia del link, pagina ospite, accettazione).

**Criteri di uscita di M5**

| Criterio | Test |
|---|---|
| AC6 | `Feature/Calendar/ConflictOnConfirmedTest` |
| AC7 | `Feature/Calendar/SyncUnhealthyTest` |
| AC8 | `Feature/Quotes/QuoteAcceptTest`, `Feature/Quotes/QuoteExpiredTest`, E2E `m5-quote` |
| AC13 | `Feature/Calendar/FeedMatrixTest`, `Feature/Calendar/EchoLoopTest` |
| AC16 | `Feature/Quotes/QuoteConcurrencyTest`, `Feature/Quotes/QuoteFrozenPriceTest` |
| I6 | `Feature/Calendar/SyncErrorKeepsDatesTest`, `Feature/Calendar/AntiWipeSafeguardTest` |
| I8 | `Feature/Quotes/QuoteExpiredTest` |
| I13 | `Feature/Calendar/ConflictCloseKeepsDatesTest` |
| I18 | `Feature/Calendar/FeedMatrixTest`, `Feature/Calendar/EchoLoopTest` |
| I19 | `Feature/Calendar/FrozenFeedNeverEmptyTest`, `Feature/Calendar/RetiredFeed410Test` |
| I21 | `Feature/Quotes/QuoteConcurrencyTest` (10 processi paralleli: 1 tentativo nuovo, 9 ripetizioni con lo stesso `checkout_url`) |

Altri test: `Unit/Calendar/{SafeHttpFetcherTest, IcsParserTest}`, `Feature/Calendar/{IcalImportTest, IcsUidStabilityTest, IcsFeedEndpointTest, ExternalCalendarApiTest, CalendarChecklistTest}`. `Architecture/RlsCatalogTest` copre le policy `definer_lookup` su `ics_feeds` e `quotes`.

## Scostamenti dalla specifica
Tutti nell'ADR-0013. I più visibili:
1. **Link del preventivo deterministico** (punto 36): HMAC dell'ID con una chiave derivata da `APP_KEY`, così il pannello può mostrare di nuovo lo stesso link. Nel database resta solo l'hash. Conseguenza: ruotare `APP_KEY` invalida i link dei preventivi aperti.
2. **Secondo accept** (punto 40): `200` con lo stesso `reservation_code` e `checkout_url`, ma `access_token: null`, perché il token della prenotazione è salvato solo come hash. L'ospite usa il link già ricevuto.
3. **Checklist** (punto 35): il percorso reale è `/units/{id}/calendar-checklist[/confirm]` con `step` nel body, non `channel-checklist/{step}/confirm`. 07 è aggiornato.
4. **Porta 443 obbligatoria** per i calendari importati (punto 1).
5. **Feed congelato senza elementi** → `503` con `Retry-After` invece di un calendario vuoto (punto 23).

## Decisioni dopo la verifica
Verifica di M5 completata: approvata con queste decisioni, applicate su questo branch.
1. **Link del preventivo** (ADR-0013 punto 36): non più derivato da `APP_KEY`. Token casuale di 32 byte, cifrato a riposo in `quotes.public_token_encrypted` e cercato per hash, come i feed iCal (punto 24). Migration `2027_01_18_000500` (i preventivi esistenti ricevono un token nuovo). Test: `Quotes/QuoteAcceptTest` (token casuale, hash, link ancora valido dopo la rotazione di `APP_KEY`). Il punto aperto 4 (rotazione di `APP_KEY`) è chiuso.
2. **Seeder demo con i dati di M5** (punti 44–45), senza chiamate esterne: calendari "Booking demo" e "Airbnb demo" sul Trilocale con eventi inseriti come da una sincronizzazione, marcati `external_calendars.demo` (esclusi da polling, "Sincronizza ora" e salute; segnalati nel pannello); feed per Booking e Airbnb di entrambe le unità; un conflitto aperto con la prenotazione manuale confermata; due preventivi, uno inviato e uno accettato e pagato. Test: `DemoSeederTest`, `Calendar/IcalImportTest` (dispatcher). Il punto aperto 2 è chiuso. Per vederli in locale: `php artisan migrate:fresh --database=pgsql_migrator --seed`.
3. **Piano di M6** (`docs/11-MILESTONES.md`): nuova attività 6.9, verifica dei calendari reali con i risultati della Fase 0 e adeguamento di `EchoClassifier` e dei file di prova (issue #72); 6.6 estesa agli E2E di `/app/calendari` e `/app/conflitti` (issue #59). Chiude il punto aperto 3 rimandandolo a M6.
4. **ADR-0013 "Accettata"**, autori "Claude Code (stesura), verificata da Claude (chat) per Alessio".

## Dopo la verifica di M3
- Branch riallineato a `m4-pagamenti-stripe` aggiornato (che contiene `main` con M3 verificata e la finestra di approvazione per struttura) con un merge, senza riscrivere la storia.
- ADR-0013 in stato "Proposta" fino alla verifica di M5 (nuova regola 2 di `CLAUDE.md`).

## Punti aperti e rischi
1. **Formati reali delle OTA non verificati** (Fase 0, ADR-0007). Da verificare in M6: etichette neutre di `SUMMARY`, eventi con orario, frequenza reale di lettura del nostro feed, comportamento di Booking e Airbnb con un `503`.
2. **Seed demo senza dati di M5.** Il `DemoSeeder` non crea calendari importati, feed o preventivi: un calendario importato richiederebbe un URL `https` pubblico raggiungibile. Per la demo si crea tutto dal pannello. Proposta: un preventivo `SENT` e un feed esportato nel seed, senza calendario importato.
3. **Pannello calendari e conflitti non provato con un E2E.** Verificati typecheck, build, `SpaRoutesTest` e le API; nessuno scenario Playwright per `/app/calendari` e `/app/conflitti` (serve un server iCal finto raggiungibile in https).
4. **Rotazione di `APP_KEY`**: va documentata nel runbook di 08 insieme all'effetto sui link dei preventivi (punto 1 degli scostamenti).
5. **Versione della specifica** da incrementare in modo definitivo dopo il merge.

## Da fare domattina
Tutti i comandi si eseguono in `C:\dev\booking-engine`. Le chiavi restano solo in `.env`.

1. **PR aperte, da verificare in ordine** (nessuna è stata unita):
   - M3 → #69;
   - M4 → #70 (base `m3-inventario-prenotazioni`), con le verifiche Stripe descritte in `docs/reports/M4-rapporto.md`;
   - M5 → PR verso `m4-pagamenti-stripe`.
2. **Prova manuale del pannello** (`php artisan serve`, `npm run dev`, `php artisan queue:work`):
   - http://127.0.0.1:8000/app/preventivi: crea un preventivo con sconto, "Copia link", "Apri WhatsApp", apri il link in una finestra anonima e accetta;
   - http://127.0.0.1:8000/app/calendari?unit=…: aggiungi il link di export reale di un calendario Airbnb o Booking di prova, guarda il primo giro, crea il feed per l'altra OTA e aprilo nel browser;
   - http://127.0.0.1:8000/app/conflitti: con un blocco manuale sulle stesse date di un evento importato.
3. **Fase 0**: se hai i link di export reali, salvane una copia (senza dati personali) per i test di `IcsParserTest` e controlla le etichette di `SUMMARY` riconosciute (ADR-0013 punto 9).
4. **Decisioni**: i punti aperti 2 e 4 qui sopra; i punti aperti di M4 (ri-autenticazione sulla cancellazione con rimborso, carta `refundFail`).
