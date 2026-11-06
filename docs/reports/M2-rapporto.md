# Rapporto M2 — Strutture, tariffe e prezzi

Data: 06/11/2026 · Branch: `m2-strutture-tariffe` · PR verso `main` senza merge

## Esito
Completata con eccezioni (alla stesura; dopo la verifica vedi "Decisioni dopo la verifica" in fondo):
- **Guardia dei pagamenti alla pubblicazione** (ADR-0010, punto 5): va decisa. Finché resta restrittiva, nessuna struttura si pubblica senza Stripe, cioè prima di M4.
- **Demo con la struttura di un pilota e i suoi prezzi veri**: non l'ho preparata, perché non ho i dati del pilota. Il pannello per inserirli è pronto (vedi "Problemi aperti").

Le issue #19–#26 sono referenziate con `closes #N` nei commit e si chiuderanno al merge su `main`. Gli scostamenti dalla specifica sono solo aggiunte compatibili e un punto da decidere, raccolti nell'ADR-0010 in stato **Proposta**.

## Attività
| # | Stato | Issue | Commit | Note |
|---|---|---|---|---|
| 2.1 | Fatta | #19 | `af231fe` | `GET/POST /admin/properties`, `GET/PATCH /admin/properties/{id}`, `GET/POST /admin/properties/{id}/units`, `GET/PATCH /admin/units/{id}`. Traduzioni nel corpo come `translations: { it, en }` (una lingua a `null` si elimina). Colonna `version` e `If-Match` (412 anche senza header). Politiche di cancellazione standard create insieme alla struttura. UUID nei percorsi come vincolo di rotta. |
| 2.2 | Fatta | #20 | `baf1fd6` | Foto JPEG/PNG/WebP fino a 10 MB, massimo 20 per unità. Ricodifica in WebP (1600 px e miniatura 400 px) con `intervention/image` (GD): EXIF e GPS eliminati, originale non conservato. Lettura solo con URL firmati da 1 h. Nuova libreria registrata in ADR-0006. |
| 2.3 | Fatta | #21 | `c84c313` | Prezzo base e stagioni. Le stagioni si indicano con prima e ultima notte comprese (nel database `[prima, ultima + 1)`). Sovrapposizioni con la stessa priorità ammesse, con `warnings` nella risposta. |
| 2.4 | Fatta | #22 | `063f21b` | `PriceCalculator` puro, protetto da `Arch/PriceCalculatorPurityTest`. Test golden di 04 esatto: 1.585,00 €, caparra 475,50 €, saldo 1.109,50 €. Casi limite di 04 coperti. `POST /admin/units/{id}/price-preview`. |
| 2.5 | Fatta | #23 | `bc484b6` | I12 in applicazione (`409 EXTERNAL_CALENDARS_ACTIVE`) e con trigger (SQLSTATE `BE012`). `PATCH /units/{id}/sales`. Stati di tenant, struttura e unità solo tramite servizi (eccezioni esplicite di `Arch/StateTransitionsTest`). Correzione di `WithTenantContext`: se il controller fallisce, la transazione si annulla. |
| 2.6 | Fatta | #24 | `b5eb0db` | `POST /admin/properties/{id}/publish` con riga bloccata. Guardie nell'ordine di 07: unità attiva, traduzione `it`, politica predefinita (`CANCELLATION_POLICY_MISSING`), Stripe `charges_enabled`. Risponde `422` con il codice della prima guardia e `details.missing` con tutte. Già pubblicata: `200` senza modifiche. Archiviata: `409 ILLEGAL_TRANSITION`. Audit `property.published`. |
| 2.7 | Fatta | #25 | `80fe9d6` | Il middleware `tenant:public` ricava il tenant dallo slug (`app_tenant_by_slug`). `PublicCatalog` mostra solo strutture `PUBLISHED` di tenant `ACTIVE` e unità `ACTIVE`; il resto è 404. `GET /public/{t}/{p}` nella lingua da `locale` o `Accept-Language`, fallback `en`, con `translation_fallback`. `POST quote-price` usa lo stesso calcolo dell'anteprima e non scrive nulla. Limite di 120 richieste/min per IP. |
| 2.8 | Fatta | #26 | `ba61ed9` | Pannello: elenco e creazione delle strutture; dati, testi IT/EN, politica predefinita e pubblicazione con l'elenco di ciò che manca; unità (importi in euro, modalità, politica, vendite, attiva/disattiva); foto (caricamento, ordine, rimozione); tariffa base e stagioni con avvisi; anteprima prezzo con le righe del server. Seeder demo con due stagioni per unità. |

Documentazione: `10-TRACEABILITY.md` 1.5, ADR-0010 (proposta), ADR-0006 (integrazione `intervention/image`).

## Test
- **Invarianti e criteri coperti**:
  - **I12**: `Feature/InstantWithCalendarRejectedTest`, sia tramite servizio sia con insert e update diretti (trigger).
  - **I14, parte prezzi**: `Feature/PriceSnapshotTest`. Golden e casi limite in `Unit/Pricing/PriceCalculatorTest`.
  - **AC1, parte pannello**: `Feature/PropertyPublishTest`, `Feature/Admin/{PropertyApiTest, UnitApiTest, UnitPhotoApiTest, RatePlanApiTest, PricePreviewApiTest, UnitSalesTest}`, `Feature/Public/PublicPropertyApiTest`. `E2E/setup-property.spec` arriva in M6.
  - **I5, estensione**: ogni nuovo endpoint admin ha il test "risorsa di un altro tenant → 404". L'API pubblica non raggiunge strutture di altri tenant né con lo slug giusto né con un `unit_id` estraneo.
- **Criteri di uscita di M2**: test golden verde; I12 e I14 (parte prezzi) verdi. Demo con la struttura di un pilota: vedi "Problemi aperti".
- **Risultato in locale**: tutti verdi:
  - `composer test`: 400 test, 1605 asserzioni;
  - `composer lint`, `composer analyse` (Larastan livello 6);
  - `npm run typecheck`, `npm run build`;
  - `composer audit`: nessuna vulnerabilità.
- **Risultato CI**: verde su `81a7a59`, run [42282237025](https://github.com/Alessiovinci87/booking-engine/actions/runs/42282237025). Il commit di questo rapporto avvia un nuovo run sulla PR.
- **Test aggiunti**:
  - `Unit/Pricing/PriceCalculatorTest`
  - `Arch/PriceCalculatorPurityTest`
  - `Feature/Admin/{PropertyApiTest, UnitApiTest, UnitPhotoApiTest, RatePlanApiTest, PricePreviewApiTest, UnitSalesTest}`
  - `Feature/PropertyPublishTest`
  - `Feature/Public/PublicPropertyApiTest`
  - `Feature/InstantWithCalendarRejectedTest`
  - `Feature/PriceSnapshotTest`
  - `Feature/Tenancy/TenantRequestRollbackTest`
  - Estesi: `Arch/StateTransitionsTest`, `Feature/DemoSeederTest`

## Scostamenti dalla specifica
Tutti nell'**ADR-0010 (Proposta)**:
1. Nuovi codici: `409 EXTERNAL_CALENDARS_ACTIVE`, `422 NO_ACTIVE_UNITS`, `422 TRANSLATION_IT_MISSING`; `details.missing` sulla pubblicazione.
2. Rotte aggiunte: `GET/POST /admin/properties`, `GET /admin/units/{id}/photos`, `PUT /admin/units/{id}/photos/order`, `DELETE /admin/units/{id}/photos/{photoId}`.
3. `warnings` (`SEASON_OVERLAP_SAME_PRIORITY`) nelle risposte delle stagioni.
4. `POST /publish` senza `Idempotency-Key` finché non c'è il meccanismo generico di I9. L'operazione è idempotente per natura.
5. **Da decidere**: 07 ammette la pubblicazione con "solo unità con prenotazione manuale", ma il modello di 02 non ha un'unità di questo tipo. Per ora serve sempre Stripe `charges_enabled`. Le alternative sono nell'ADR.

## Scelte minori prese
- Le guardie di pubblicazione rispondono con il codice della prima non soddisfatta, nell'ordine di 07, e con l'elenco completo in `details.missing`.
- Il controllo Stripe usa l'unico `StripeAccount` del tenant (uno per tenant in 02), letto sotto RLS.
- API pubblica:
  - tenant non `ACTIVE` → 404, coerente con la "Disponibilità effettiva" di 02;
  - il limite `public-read` vale anche per `quote-price`, che non crea nulla;
  - nella risposta non compaiono stato, versione, commissioni OTA, `sync_health`, `sales_suspended` né identificativi del tenant;
  - compare `cin_code`, che la struttura deve esporre.
- `WithTenantContext` accetta il parametro `public` invece di un secondo middleware. Il risolutore del pannello resta il binding predefinito di `TenantResolver`.
- Lingua dell'API pubblica: middleware `guest.locale`. Imposta anche la lingua dei messaggi d'errore.
- Pannello:
  - importi inseriti in euro (`120`, `120,50`, `1.585,00`) e convertiti in centesimi senza virgola mobile (`resources/js/panel/money.ts`);
  - i totali dell'anteprima sono quelli del server;
  - dopo un `412` il modulo si ricarica con i dati più recenti e lo segnala.
- Seeder demo: per ogni unità "Alta stagione" (luglio-agosto 2027, +40%, minimo 5 notti) e "Ferragosto" (13–17/08, +80%, priorità 20). Dati inventati.

## Problemi aperti e rischi per la milestone successiva
1. **Decisione sulla guardia dei pagamenti** (ADR-0010, punto 5). Con la scelta attuale la struttura del pilota resta in bozza fino a M4, e la pagina ospite di 3.7 si potrà provare solo con dati di test (stato `PUBLISHED` impostato a mano) o dopo la decisione.
2. **Demo con i prezzi veri di un pilota**: servono nome, unità, capienze, prezzo base, stagioni, pulizie e supplementi del pilota. Dal pannello (`/app`) si inseriscono in pochi minuti; poi l'anteprima prezzo mostra il calcolo. Puoi inserirli tu, oppure me li passi e preparo un seeder dedicato, senza dati personali.
3. **Il pannello di 2.8 non è stato provato in un browser.** È verificato da `typecheck`, `build`, `SpaRoutesTest` (anche il controllo che i testi passino dalle chiavi di traduzione) e dai test delle API che usa. Gli E2E Playwright arrivano in M6. Prima del merge consiglio un giro manuale: crea una struttura, aggiungi un'unità, carica una foto, aggiungi una stagione, fai un'anteprima, prova a pubblicare.
4. **CSRF sulle `POST` pubbliche** (per 3.7): con `statefulApi()` una `POST` dalla pagina ospite sullo stesso dominio richiede il token CSRF. In 3.7 la pagina ospite dovrà chiedere `/sanctum/csrf-cookie` oppure le rotte `public/*` andranno escluse dalla verifica CSRF (non usano la sessione). È una scelta di sicurezza da fare in 3.7.
5. **Unità con vendite sospese**: oggi `quote-price` restituisce il prezzo. `DATES_UNAVAILABLE` (e quindi il blocco) arriva con `AvailabilityService` in 3.2.
6. **Ultima unità attiva di una struttura pubblicata**: oggi si può disattivare. La struttura resta pubblicata e mostra zero unità. 07 non dice se vada impedito: da chiarire, eventualmente in 3.2 con la disponibilità.

## Ore impiegate (stima) rispetto al previsto
Previsto: sprint 2, dal 23 novembre al 4 dicembre 2026 (10 giorni lavorativi). Svolta in anticipo, entro il 6 novembre 2026, in più sessioni. Le ore non sono state misurate con precisione, ma sono ben sotto la stima: nessun rischio per la regola del +50%.

## Decisioni dopo la verifica
Verifica del 06/11/2026: **M2 approvata**, con le decisioni seguenti, applicate sul branch prima del merge.

1. **ADR-0010 accettata.** I punti 1–4 sono accettati come scritti. Punto 5: resta la guardia restrittiva, cioè si pubblica solo con un `StripeAccount` che ha `charges_enabled = true`. Le modifiche a 07 (versione 1.3):
   - tolta l'alternativa "o solo unità con prenotazione manuale";
   - aggiunti i codici, le rotte, la forma di `warnings` e la nota sull'`Idempotency-Key` della pubblicazione.

   La checklist dell'ADR è completa.
2. **Account Stripe finto nel seeder demo.** Per `demo-alghero` il seeder crea un account con:
   - `stripe_account_id` `acct_demo_…`;
   - `livemode = false`;
   - `charges_enabled` e `payouts_enabled` a `true`.

   Con `APP_ENV=production` si ferma, come già faceva. I test in `Feature/DemoSeederTest`:
   - "crea un account Stripe finto con cui la struttura demo si pubblica";
   - "in produzione si ferma senza scrivere nulla".
3. **Prova nel browser con Playwright** (`tests/E2E/m2-panel.spec.ts`, script `npm run test:e2e`, solo in locale; in CI da M6). Il global setup rigenera il database di sviluppo (`migrate:fresh` e seeder demo) e legge la password demo dall'output del seeder. Il test esegue nell'ordine:
   - accesso con password, configurazione della MFA con il codice TOTP calcolato dal segreto mostrato (`tests/E2E/support/totp.ts`, solo per i test);
   - creazione di una struttura con testi IT/EN e politica predefinita;
   - creazione di un'unità con i valori del test golden e caricamento di una foto di prova;
   - stagioni Agosto e Ferragosto;
   - anteprima dal 10 al 17 agosto con 5 ospiti;
   - pubblicazione e lettura di `GET /api/v1/public/demo-alghero/casa-golden-e2e`.

   **Esito: verde** (1 test, circa 14 s, Chromium headless):
   - anteprima: 1.585,00 €, caparra 475,50 €, saldo 1.109,50 €;
   - struttura "Pubblicata";
   - l'API pubblica restituisce la struttura in IT ed EN con l'unità e la sua foto.

   Screenshot in `storage/e2e-screenshots/`, esclusi da git:
   - `01-login.png`
   - `02-mfa-configurazione.png`
   - `03-struttura-salvata.png`
   - `04-unita-con-foto.png`
   - `05-stagioni.png`
   - `06-anteprima-golden.png`
   - `07-struttura-pubblicata.png`
   - `08-api-pubblica.png`

   La prova nel browser ha trovato due difetti, ora corretti:
   - **Importi sotto 10.000 € senza separatore delle migliaia.** Il pannello mostrava "1585,00 €": in `it-IT` `Intl.NumberFormat` usa per default il raggruppamento "auto". Ora `formatMoney` usa `useGrouping: true` e mostra "1.585,00 €". Il valore era già corretto, cambia solo come viene mostrato.
   - **Foto non caricabili con `php artisan serve` su Windows.** Il comando passa al server PHP un elenco chiuso di variabili d'ambiente, senza `TEMP` e `TMP`, così PHP non riusciva a creare il file temporaneo dell'upload. Ora `AppServiceProvider` aggiunge `TEMP` e `TMP` all'elenco, solo con `APP_ENV=local`. Riguarda solo lo sviluppo: staging e produzione non usano `artisan serve`. L'ho annotato in `docs/dev/SETUP-WINDOWS.md`.
4. **11-MILESTONES (1.2) e issue di M3 aggiornate** (#28, #33; decisioni registrate anche nell'ADR-0010, punti 6–8):
   - API pubblica senza cookie di sessione: niente CSRF. La protezione è affidata a tre misure:
     - rate limit;
     - `Idempotency-Key` sulle `POST`;
     - controllo dell'header `Origin` rispetto agli `embed_allowed_origins` del tenant, per la vista incorporata (3.7).
   - `quote-price` e `availability` su un'unità con vendite sospese o non attiva rispondono `409 UNIT_NOT_BOOKABLE` (3.2). Il codice è già in 07.
   - Disattivare l'ultima unità di una struttura pubblicata è consentito. Il pannello mostra un avviso; la pagina ospite mostra "Nessuna disponibilità al momento", con chiave di traduzione IT/EN (3.7).
5. **Criterio di uscita "demo con i prezzi veri di un pilota"**: sostituito dalla demo con i dati del test golden (punto 3), perché nessun pilota è ancora disponibile. I prezzi reali si inseriranno dal pannello quando la Fase 0 porterà il primo gestore. Annotato anche in `11-MILESTONES.md`.

Altre modifiche:
- `10-TRACEABILITY.md` aggiornato alla 1.6.
- Nuove dipendenze di sviluppo `@playwright/test` (prevista da ADR-0006) e `@types/node` (solo per controllare i tipi dei test E2E).
- `npm run typecheck` controlla anche `tests/E2E`.
- `.gitignore` esclude gli screenshot, `playwright-report/` e `test-results/`.

Verifiche in locale dopo le modifiche, tutte verdi:
- `composer test`: 401 test, 1612 asserzioni;
- `composer lint` e `composer analyse`;
- `npm run typecheck` e `npm run build`;
- `npm run test:e2e`.
