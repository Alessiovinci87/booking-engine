# Rapporto M3 — Inventario, prenotazioni e pagina ospite

Data: 07/11/2026 · Branch: `m3-inventario-prenotazioni` · PR verso `main` senza merge

## Esito
Completata. Le issue #27–#34 sono referenziate con `closes #N` nei commit e si chiuderanno al merge su `main`. Gli scostamenti dalla specifica sono aggiunte compatibili o precisazioni di casi non coperti, raccolti nell'**ADR-0011 (Accettata)**: nessun comportamento concordato è cambiato.

Rispetto al piano di 11, la pagina ospite non "si ferma prima del pagamento": come chiesto nel prompt di avvio arriva fino al checkout **simulato** (gateway finto, solo `local`/`testing`), con conferma dal flusso equivalente al webhook. Stripe reale resta per M4.

## Attività
| # | Stato | Issue | Commit | Note |
|---|---|---|---|---|
| 3.1 | Fatta | #27 | `a41a74a` | `InventoryService` unico scrittore delle occupazioni: `acquireForReservation`, `acquireBlock`, `releaseBlock`, `promoteToFirm`, `setTemporaryReason`, `move`, `release`, `calendarOccupancies`. Lock sull'unità, vincolo GiST `23P01` → `409 DATES_UNAVAILABLE`. |
| 3.2 | Fatta | #28 | `53dc5f3` | `AvailabilityService` sulle tre fonti (occupazioni, eventi esterni, conflitti), `GET /public/{t}/{p}/availability` con `min_nights_by_night`; `409 UNIT_NOT_BOOKABLE` per unità sospese o non attive. |
| 3.3 | Fatta | #29 | `26847a6` | `PaymentGateway` e `FakePaymentGateway` (stato in cache, solo `local`/`testing`); pagina "Pagamento simulato" che produce gli stessi eventi del webhook. |
| I9 | Fatta | — | `b03f557` | Middleware `Idempotency-Key` per tutte le POST che cambiano stato (richiesto dal prompt di avvio prima di 3.4). |
| 3.4 | Fatta | #30 | `0edfe10` | `ReservationStateMachine` con la tabella T1–T18 come dati; test per riga e test sulle transizioni vietate; `CheckoutService` in tre passi; email nell'outbox. |
| 3.5 | Fatta | #31 | `68deab7` | API del pannello: elenco con filtri, dettaglio, prenotazione manuale (T2, `UNPAID`), pagamenti offline, approva/rifiuta, cancellazione T17 con rimborso sul gateway finto, modifica T18 con `If-Match`, link di recupero T15, rilascio T16, blocchi, calendario. |
| 3.6 | Fatta | #32 | `5a4b858`, `44fc232` | `occupancies:release` ogni minuto: dispatcher con `app_admin_ops` (solo ID dei tenant), un job per tenant con `app_runtime`. |
| 3.7 | Fatta | #33 | `e8ca968` | Pagina ospite mobile-first IT/EN (galleria, schede unità, calendario a due mesi con `react-day-picker`, ospiti, prezzo con le righe dell'API, dati dell'ospite, accettazioni, pagamento a pagina intera, pagina di ritorno con polling). `POST /public/{t}/{p}/reservations` e `GET /public/reservations/{code}?token=`. Guardia `PRIVACY_POLICY_MISSING`. CSP con nonce, `frame-ancestors`, vista embed `/e/{t}/{p}`, API pubblica senza cookie con controllo `Origin`. Avviso sull'ultima unità attiva. |
| 3.8 | Fatta | #34 | `f7e3f8a` | Pannello: calendario mensile per unità, elenco con filtri, dettaglio con azioni ammesse dal server, prenotazione manuale, blocchi. Seeder demo con prenotazioni in vari stati. E2E `tests/E2E/m3-guest-booking.spec.ts`. |

Documentazione: ADR-0011 (nuova, accettata), ADR-0006 (integrazione `react-day-picker` 9 + `date-fns` 4), `07-API-CONTRACT.md` (nuovi campi e codici), `10-TRACEABILITY.md` 1.7.

## Test
- **Criteri di uscita di M3**:
  - **I1**: `Feature/OccupancyExclusionTest`, `Feature/ConcurrentAcquireTest`;
  - **20 acquisizioni parallele → una sola occupazione** (AC5): `Feature/ConcurrentAcquireTest`, con processi separati su PostgreSQL reale;
  - **I7**: `Feature/CancelHistoryTest`;
  - **I11**: `Feature/ConfirmationKindTest`;
  - **I15**: `Feature/OccupancyConsistencyTest` (dopo ogni transizione) e controllo nel `Feature/DemoSeederTest`;
  - **AC17**: `Feature/ManualUnpaidTest`.
- **Altri test richiesti dal prompt**: `IdempotencyKeyTest`, `NoConfirmOnRedirectTest`, `PriceSnapshotTest` (prezzi congelati sulla prenotazione), `Reservations/ReservationTransitionsTest` (T1–T18), `Payments/SimulatedCheckoutTest` (rotte assenti in produzione, staging e con gateway Stripe).
- **E2E** (`npm run test:e2e`, solo in locale come in M2): `m2-panel.spec.ts` e `m3-guest-booking.spec.ts`, 3 test verdi. Screenshot in `storage/e2e-screenshots/` (non committati): pagina della struttura, date e prezzo, dati dell'ospite, pagamento simulato, annullamento e ripresa, conferma, pagina in inglese, elenco, dettaglio e calendario del pannello.
- **Risultato in locale**: tutti verdi:
  - `composer test`: 544 test, 3500 asserzioni;
  - `composer lint`, `composer analyse` (Larastan livello 6);
  - `npm run typecheck`, `npm run build`, `npm run test:e2e` (3 test);
  - `composer audit`: nessuna vulnerabilità.
- **Risultato CI**: verde su `83263f6`, run [42316620555](https://github.com/Alessiovinci87/booking-engine/actions/runs/42316620555). Il primo run della PR era rosso: `NoConfirmOnRedirectTest` apriva la pagina ospite senza `withoutVite()` e in CI i test girano prima della build; corretto in `83263f6`. Il commit di questo rapporto avvia un nuovo run.
- **Test aggiunti**:
  - `Feature/Inventory/InventoryServiceTest`, `Feature/OccupancyExclusionTest`, `Feature/ConcurrentAcquireTest`, `Arch/InventoryWritesTest` (attivo sul modello reale)
  - `Feature/Public/AvailabilityApiTest`
  - `Feature/Payments/FakePaymentGatewayTest`, `Feature/Payments/SimulatedCheckoutTest`
  - `Feature/IdempotencyKeyTest`
  - `Feature/Reservations/ReservationTransitionsTest`, `Feature/OccupancyConsistencyTest`, `Feature/ConfirmationKindTest`, `Feature/NoConfirmOnRedirectTest`
  - `Feature/ManualUnpaidTest`, `Feature/CancelHistoryTest`, `Feature/Admin/ReservationApiTest`
  - `Feature/Reservations/OccupancyReleaseJobTest`
  - `Feature/Public/PublicReservationApiTest`, `Feature/SecurityHeadersTest`
  - `tests/E2E/m3-guest-booking.spec.ts`
  - Estesi: `Feature/PriceSnapshotTest`, `Feature/PropertyPublishTest`, `Feature/DemoSeederTest`, `Feature/Frontend/SpaRoutesTest` (testi della pagina ospite), `Feature/Architecture/RlsCatalogTest`, `tests/E2E/m2-panel.spec.ts`

## Scostamenti dalla specifica
Tutti nell'**ADR-0011**, in sintesi:
1. **API pubblica**: `access_token` nella risposta di `POST /reservations`; tenant dello stato risolto da codice + token con una funzione `SECURITY DEFINER`; `privacy_version`/`terms_version` = hash dell'URL; `403 FORBIDDEN` per origini non ammesse; `409 PRICE_CHANGED` con il nuovo prezzo in `details.price`.
2. **Pubblicazione**: nuova guardia `422 PRIVACY_POLICY_MISSING` (richiesta dal prompt di avvio).
3. **Sessione e CSRF**: Sanctum stateful solo su `/admin`; pagine ospite ed embed senza sessione.
4. **Pannello**: `actions` e `cancellation_refund` nel dettaglio della prenotazione; `409 IDEMPOTENCY_KEY_IN_USE`.
5. **Macchina a stati**: T6 passa da T3/T4, T7 dagli stati terminali, guardie I11 sul canale, motivo del rifiuto in `cancel_reason`, modifica con lo sconto originale.

## Scelte minori prese
- Il token della prenotazione resta nella `sessionStorage` della scheda (`guest.reservation.{code}`) insieme a `checkout_url`: la ripresa del pagamento dopo un annullamento funziona solo nella scheda che l'ha creato.
- Stessa `Idempotency-Key` per i nuovi invii dello stesso modulo ospite; chiave nuova se il corpo cambia (per esempio dopo `PRICE_CHANGED`).
- Il calendario del pannello mostra una griglia di 6 settimane da lunedì; nelle notti libere un "+" crea un blocco da quella data.
- Elenco dei paesi in `resources/js/shared/countries.ts`, usato da pagina ospite e pannello; nomi con `Intl.DisplayNames`.
- La cronologia del dettaglio traduce le azioni note dell'audit (`history.*`) e mostra il codice grezzo per le altre.
- Il seeder demo crea le prenotazioni con date relative al giorno di esecuzione (UTC del server) e richiede `PAYMENT_GATEWAY=fake`. La prenotazione "in attesa di pagamento" scade dopo 30 minuti se gira il job di rilascio: è il comportamento atteso.
- Il commit `46921b9` (database E2E dedicato) è sul branch prima di 3.1: completa la verifica di M2 ed è necessario agli E2E di M3.

## Problemi aperti e rischi per M4
- **Stripe reale**: `StripePaymentGateway`, webhook firmati, `PaymentReconciler` e la suite `Stripe/*` (AC2, AC3, AC4, AC9, AC14, AC15, I4, I16) arrivano in M4. Il contratto `PaymentGateway` è quello che il gateway finto già rispetta.
- **Promemoria `CAPTURE_FAILED` a 12 ore** rinviato a M4 (ADR-0011, punto 12).
- **CSP e foto**: se in staging le foto arrivano da un CDN su un'altra origine, va impostato `CSP_IMG_SRC`.
- **E2E in CI**: ancora solo in locale (in CI da M6, attività 6.6). Lo spec di M3 riusa la MFA configurata da quello di M2 nello stesso processo di Playwright: se si lancia da solo, il test del pannello viene saltato.
- La pagina ospite non ha ancora foto nella demo (il seeder non carica immagini); la galleria mostra "Nessuna foto".

## Decisioni dopo la verifica
Verifica di M3 completata: approvata con correzioni, applicate su questo branch prima del merge.
1. **ADR-0011** resta "Accettata". Autori corretti in "Claude Code (stesura), verificata da Claude (chat) per Alessio"; il riferimento del punto 21 (pagina di ritorno) è I2, non I6. Nuova regola in `CLAUDE.md`: le ADR scritte durante una milestone hanno stato "Proposta" finché la verifica non le accetta.
2. **Anteprima della modifica** (ADR-0011 punto 28): prima di salvare un `PATCH` il pannello mostra vecchio e nuovo prezzo (righe e totale) calcolati dal server con `POST /admin/reservations/{id}/modify-preview`, che non salva nulla, e chiede una conferma esplicita. Il `PATCH` porta `expected_total_cents`: se il prezzo nel frattempo è cambiato → `409 PRICE_CHANGED`. Test: `Admin/ReservationModifyPreviewTest`.
3. **Link dei documenti legali** (ADR-0011 punto 16): accanto ai campi dell'informativa privacy e delle condizioni il pannello avvisa "Se aggiorni il documento, cambia anche il link (per esempio aggiungendo ?v=2): così agli ospiti verrà chiesto di accettare la nuova versione".
4. **Finestra di approvazione configurabile per struttura**: 1–48 ore, predefinita 12, e comunque non oltre `capture_before − 6 h` dell'autorizzazione. Da completare in M4 (campo su `properties`, impostabile nel pannello, con test).

## Ore impiegate (stima) rispetto al previsto
Previsto: sprint di due settimane (7–18 dicembre 2026). Impiegato: circa due giornate di lavoro di Claude Code, concentrate il 6–7 novembre 2026. Nessun superamento della stima.

## Come provare la demo

### Preparazione (PowerShell, in `C:\dev\booking-engine`)
1. Servizi locali: `docker compose up -d --wait`
2. Database di sviluppo pulito con i dati demo (**cancella i dati locali**):
   - `php artisan migrate:fresh --database=pgsql_migrator`
   - `php artisan db:seed --class=DemoSeeder` (annota la password del proprietario: si mostra una sola volta)
3. Frontend: `npm run build` (oppure `npm run dev` in una finestra separata)
4. In due finestre separate: `php artisan serve` e `php artisan queue:work` (il worker esegue incassi, annulli delle autorizzazioni e rimborsi sul gateway finto). Facoltativo, per il rilascio automatico delle date: `php artisan schedule:work`.

Il file `.env` deve avere `APP_ENV=local` e `PAYMENT_GATEWAY=fake` (valori di `.env.example`).

### Pagina ospite (dal telefono o con il browser in modalità mobile)
1. Apri `http://127.0.0.1:8000/p/demo-alghero/casa-demo-alghero`: galleria, descrizione e due alloggi.
2. **Bilocale Vista Mare** (prenotazione immediata) → "Scegli le date": le notti già occupate sono barrate; scegli arrivo e partenza (minimo 2 notti). Il prezzo compare con le righe calcolate dal server.
3. Compila i dati, accetta condizioni e informativa, poi "Prosegui al pagamento": si apre la pagina **Pagamento simulato**.
4. Prova "Abbandona": torni alla prenotazione con "Riprendi il pagamento". Poi "Pagamento riuscito": la pagina di ritorno attende la conferma e mostra "Prenotazione confermata".
5. Ripeti con il **Trilocale Giardino** (su richiesta): dopo il pagamento la prenotazione resta "Richiesta inviata", in attesa del gestore.
6. Cambia lingua in alto a destra (Italiano/English).
7. Vista incorporata: `http://127.0.0.1:8000/e/demo-alghero/casa-demo-alghero`. Si apre in un iframe solo dalle origini in `embed_allowed_origins` del tenant; il pagamento esce sempre dall'iframe.

### Pannello
1. `http://127.0.0.1:8000/app`, accedi con `proprietario.demo@example.test` e la password del seeder; al primo accesso configura la MFA.
2. **Prenotazioni**: nove prenotazioni di esempio (confermate online e manuali, in attesa di pagamento, da approvare, rifiutata, cancellata, pagamento non riuscito). Prova i filtri per stato, periodo, unità e codice.
3. Apri **Hans Weber** (da approvare): "Approva" incassa l'importo autorizzato (con il worker attivo la prenotazione diventa confermata); in alternativa "Rifiuta" con un motivo.
4. Apri **Marta Sanna** (manuale non pagata): "Registra pagamento offline", poi "Modifica date e ospiti" (prezzo ricalcolato, storico delle modifiche) e "Cancella la prenotazione" (rimborso proposto dalla politica).
5. **Nuova prenotazione manuale**: unità, date, ospiti, sconto facoltativo e pagamento offline iniziale; è confermata subito.
6. **Calendario**: scegli l'unità; le prenotazioni sono colorate per stato (tratteggiate quelle temporanee), il blocco "Manutenzione impianto" è in grigio scuro. Clic su un "+" per bloccare date libere, clic su un blocco per rimuoverlo.
7. Su una struttura pubblicata, disattivando l'ultima unità attiva compare un avviso di conferma; la pagina ospite mostra poi "Nessuna disponibilità al momento".
