# ADR-0013 — Precisazioni di calendari iCal e preventivi dopo M5

- **Stato**: Accettata (verifica di M5)
- **Data**: 2026-11-07
- **Autori**: Claude Code (stesura), verificata da Claude (chat) per Alessio
- **Documenti di specifica toccati**: 06-CALENDAR-SYNC, 07-API-CONTRACT, 08-SECURITY-OPERATIONS

## Contesto

L'implementazione di M5 (import ed export iCal, conflitti, preventivi) ha incontrato punti che 06, 03 e 08 lasciano aperti. Questa ADR li fissa man mano. Nessun punto cambia un comportamento concordato: le scelte riempiono vuoti e restano coerenti con gli invarianti I6, I8, I13, I18, I19 e I21.

## Decisione

### SafeHttpFetcher (5.1)

1. **Solo la porta 443.** Oltre a `https`, l'URL di un calendario deve usare la porta standard: i feed delle OTA non usano porte diverse, e una porta arbitraria su un IP pubblico è un vettore SSRF verso servizi non web. Errore `PORT_NOT_ALLOWED`.
2. **Nomi riservati.** `localhost`, `*.localhost`, `*.local` e `*.internal` sono rifiutati prima della risoluzione DNS (`BLOCKED_ADDRESS`).
3. **Tutti gli indirizzi risolti devono essere pubblici.** Se il DNS restituisce anche un solo indirizzo bloccato, il download è rifiutato; la connessione va al primo indirizzo validato tramite `CURLOPT_RESOLVE`, senza una seconda risoluzione (DNS rebinding).
4. **Reti bloccate** oltre a quelle elencate nel prompt di M5: indirizzi riservati e di documentazione IPv4 (`192.0.0.0/24`, `192.0.2.0/24`, `198.18.0.0/15`, `198.51.100.0/24`, `203.0.113.0/24`, `240.0.0.0/4`, broadcast) e IPv6 (`::`, NAT64 `64:ff9b::/96`, `2001::/23`, `2001:db8::/32`, 6to4 `2002::/16`, `fec0::/10`, `ff00::/8`).
5. **Codici d'errore** del download (`FetchError`), salvati in `SyncRun.error_code`: `INVALID_URL`, `SCHEME_NOT_ALLOWED`, `PORT_NOT_ALLOWED`, `DNS_FAILED`, `BLOCKED_ADDRESS`, `TOO_MANY_REDIRECTS`, `TOO_LARGE`, `TIMEOUT`, `CONNECTION_FAILED`, `TLS_ERROR`. L'URL non compare mai in eccezioni e log: può contenere il token privato dell'OTA.
6. **Trasporto separato.** Le funzioni cURL stanno in `App\Domain\Calendar\Http\CurlTransport`, usato solo da `SafeHttpFetcher` e aggiunto all'elenco di `Arch/ExternalHttpTest`; DNS e trasporto sono interfacce sostituibili nei test.

### Import iCal (5.2)

7. **Conversione in notti.** Un evento a giornata intera (`VALUE=DATE`) si usa così com'è. Un evento con orario si porta nel fuso della struttura: il check-in è il giorno dell'inizio, il check-out il giorno della fine, più un giorno se la fine non cade esattamente a mezzanotte. Senza `DTEND` si usa `DURATION`, altrimenti un giorno. Un intervallo vuoto diventa una notte.
8. **Eventi scartati.** Ricorrenti (`RRULE`, `RDATE`, `RECURRENCE-ID`), annullati (`STATUS:CANCELLED`), già conclusi e senza `DTSTART` valido non entrano; ricorrenti e invalidi sono contati in un avviso di log (`calendar.events_skipped`, solo ID). Più di 5000 `VEVENT` → errore `TOO_MANY_EVENTS`; ICS illeggibile → `INVALID_ICS`; HTTP diverso da 200/304 → `HTTP_{codice}`.
9. **SUMMARY.** Si conservano in `summary_redacted` solo le etichette neutre note dei feed di export ("CLOSED - Not available", "Reserved", "Airbnb (Not available)", "Not available", "Blocked", ...); ogni altro testo può contenere un nome e non si salva. `DESCRIPTION` non si legge mai.
10. **Salvaguardia anti-svuotamento.** Si confrontano gli eventi futuri non rimossi prima del giro con quelli della risposta. La regola del 50% vale da 2 eventi futuri in su: con un solo evento, la sua scomparsa è una cancellazione normale (altrimenti non si libererebbe mai). Una risposta `SUSPICIOUS` applica gli eventi nuovi e modificati (bloccano solo date in più), non rimuove nulla, azzera ETag e Last-Modified (il giro successivo riscarica tutto) e **non** aggiorna `last_success_at`: se resta sospetta, dopo 3 ore il calendario diventa `UNHEALTHY` e il gestore riceve l'email (06, "Salute"). Allerta di log `calendar.sync_suspicious`.
11. **Backoff.** Dopo n errori consecutivi: min(10 · 2^(n−1), 60) minuti, con jitter ±90 s; un `Retry-After` più lungo prevale, fino a 6 ore. 304 conta come successo. Un errore non tocca gli eventi né conferma un'assenza.
12. **Scheduler.** `DispatchCalendarSyncJob` ogni minuto (app_admin_ops, solo ID) accoda `SyncExternalCalendarJob` per i calendari non `DISABLED` con `next_poll_at` scaduto o nullo; il job è unico in coda per calendario e prende un lock di cache (Redis) `calendar-sync:{id}`.
13. **Macchine a stati.** Gli stati di eventi e calendari importati cambiano solo in `ExternalEventStateMachine` e `ExternalCalendarStateMachine` (CLAUDE.md regola 5); l'audit registra date e stati, mai il SUMMARY.
14. **Libreria.** `sabre/vobject` 4.5.9 (ADR-0006): la 4.5.0 genera avvisi di deprecazione con PHP 8.4.

### Echi e conflitti (5.3)

15. **Contenuto dei feed in un solo punto.** La matrice dei flussi di 06 è implementata da `Calendar\Export\FeedContents`, usata sia dal classificatore degli echi sia (5.4) dal builder del feed: "intervallo identico a un evento che esportiamo nel feed di quella OTA" significa esattamente un elemento di `FeedContents` per quella destinazione. Il feed per Booking o Airbnb esclude gli eventi di qualsiasi calendario della stessa OTA sull'unità, oltre al calendario accoppiato.
16. **"Comparso dopo che l'OTA ha letto il nostro feed".** L'elemento esportato deve esistere da prima dell'ultima lettura (`last_fetched_at`) del feed accoppiato al calendario (o, senza accoppiamento, del feed dell'unità con la stessa destinazione, `ACTIVE` o `FROZEN`). Mai letto → nessuna eco sospetta. L'eco sospetta non salva l'elemento di cui sembra l'eco (`echo_of_ics_uid` è solo per le confermate, vincolo del database): lo si ricalcola quando serve.
17. **Conflitti.** Notti del conflitto = intersezione dei due intervalli. Una coppia (evento, occupazione) o (evento, evento) con un conflitto già `OPEN` o `INVESTIGATING` non ne apre un secondo. Il rilevamento gira per eventi nuovi, con date cambiate o ricomparsi; un'eco sospetta genera conflitti (`suspected_echo = true`) solo con elementi diversi da quello di cui sembra l'eco. Gli eventi `PENDING_REMOVAL` non generano conflitti (06 parla di eventi `ACTIVE`) ma restano esportati, perché bloccano ancora le date.
18. **Risoluzione automatica** (06 "Rimozione" punto 4, 03 sezione 5): solo dai conflitti `OPEN` (un conflitto preso in carico resta al gestore), con `resolution = CANCELLED_EXTERNAL` e nota automatica; notifica `calendar.conflict_auto_resolved`.
19. **Notifiche al gestore** tramite outbox: `calendar.conflict_opened`, `calendar.conflict_auto_resolved` (e `calendar.sync_unhealthy`, 5.6), consegnate da `SendCalendarEmail` all'email di contatto della struttura, senza dati dell'ospite.
20. **API aggiunta**: `POST /admin/external-events/{id}/not-echo` per la riclassificazione "non è un'eco, è una prenotazione vera" prevista da 06; `409 ILLEGAL_TRANSITION` se l'evento non è un'eco sospetta. `GET /admin/conflicts` accetta `status[]` (predefinito `OPEN` e `INVESTIGATING`) e `unit_id`; ogni conflitto riporta `kind` (`RESERVATION`, `MANUAL_BLOCK`, `EXTERNAL`), la prenotazione coinvolta e gli eventi esterni con origine.

### Feed esportati (5.4)

21. **Colonne per `SEQUENCE` e congelamento** (migration `2027_01_18_000100_add_ics_feed_columns`):
    - `inventory_occupancies.ics_sequence` e `external_calendar_events.ics_sequence` (intero ≥ 0, predefinito 0): `SEQUENCE` dell'evento `occ-`/`ext-` in tutti i feed. Cresce di 1 a ogni cambio di date: `InventoryService::move` (T18) per le occupazioni, `ExternalEventStateMachine::seen` per gli eventi importati. Gli altri cambi (fase, stato economico) non cambiano il contenuto dell'evento. I conflitti non cambiano date: `SEQUENCE` 0. Con la sequenza sulla fonte la lettura del feed non scrive nulla e lo stesso UID ha la stessa `SEQUENCE` in ogni feed.
    - `ics_feeds.frozen_at`: istante del congelamento (rotazione del token o offboarding), obbligatorio per un feed `FROZEN` (vincolo `ics_feeds_frozen_has_date`).
22. **Documento.** `PRODID:-//BookingEngine//Calendar Export 1.0//IT`, `METHOD:PUBLISH`; per evento `UID`, `DTSTAMP` = ultima modifica della fonte (documento ed ETag stabili tra due letture senza cambi), `DTSTART;VALUE=DATE`, `DTEND;VALUE=DATE`, `SUMMARY:Non disponibile`, `SEQUENCE`, `TRANSP:OPAQUE`. Nessuna `DESCRIPTION`, nessun nome di unità o struttura. Orizzonte: dagli intervalli in corso fino a 760 giorni.
23. **Feed `FROZEN`.** Contiene solo gli elementi nati entro `frozen_at`, con le modifiche e le cancellazioni successive (06). Se non ne resta nessuno, il feed **non** si serve vuoto (I19): `GET /ical/{token}.ics` risponde `503` con `Retry-After`, e le OTA mantengono l'ultimo calendario letto (nel dubbio, si blocca).

### Ciclo di vita dei feed (5.5)

24. **Token e ricerca.** Token di 32 byte casuali in base64url (43 caratteri), cifrato a riposo (`token_encrypted`, serve per mostrare l'URL) e cercato per hash SHA-256 (`token_hash`) con la funzione SECURITY DEFINER `app_tenant_by_ics_feed(text)` (migration `2027_01_18_000200`, stesso schema delle altre funzioni di ricerca; policy `definer_lookup` su `ics_feeds` solo per app_migrator). Il percorso accetta solo `[A-Za-z0-9_-]{43}`; il token non compare in log e audit.
25. **Risposte di `GET /ical/{token}.ics`**: 200 `text/calendar; charset=utf-8` con `Cache-Control: no-store` ed `ETag`; 304 con `If-None-Match` uguale; 404 `NOT_FOUND` per token sconosciuto; 410 `FEED_RETIRED`; 503 `FEED_UNAVAILABLE` con `Retry-After: 3600` per un feed `FROZEN` senza elementi (punto 23); 429 `RATE_LIMITED` oltre 60 richieste all'ora per token (`ICAL_FEED_RATE_LIMIT_PER_HOUR`). Errori nel formato JSON di 07. Nessuna sessione né cookie.
26. **Letture registrate** al massimo ogni 5 minuti (`last_fetched_at`, `last_fetch_user_agent` ripulito dai caratteri di controllo, 255 caratteri): una scrittura per lettura sarebbe inutile con 60 letture/ora.
27. **Creazione e accoppiamento.** `POST /admin/units/{id}/ics-feeds` crea il feed `ACTIVE` della destinazione (409 `ICS_FEED_EXISTS` se c'è già) e lo accoppia al primo calendario importato non disattivato dello stesso canale; un calendario aggiunto dopo si accoppia ai feed della stessa OTA che ne sono privi.
28. **Rotazione** (`POST /admin/ics-feeds/{id}/rotate`, solo da `ACTIVE`): il feed attuale diventa `FROZEN` con `frozen_at` e `rotated_at`, il nuovo nasce `ACTIVE` con lo stesso accoppiamento. Un dispatcher orario (app_admin_ops, solo ID) ritira i feed `FROZEN` da rotazione oltre i 7 giorni (`RetireRotatedFeedsJob` per tenant). `GET` riporta `retires_at` e `not_read_warning` (feed `ACTIVE` non letto da oltre 24 ore, o mai letto 24 ore dopo la creazione; 08).
29. **Stati dei feed** solo in `IcsFeedStateMachine` (creazione `ACTIVE`, `freeze`, `retire`); `RETIRED` è definitivo. Il congelamento per offboarding e il ritiro su conferma del gestore usano gli stessi metodi; le API di offboarding restano a M7 (11).

### Salute, calendari importati e checklist (5.6)

30. **Salute.** Riferimento = `last_success_at`, o la creazione per un calendario mai sincronizzato; minuti interi trascorsi: < 60 `ACTIVE`, 60–180 `DEGRADED`, oltre 180 `UNHEALTHY`. Valutazione ogni 5 minuti (dispatcher con app_admin_ops, `EvaluateSyncHealthJob` per tenant) e dopo ogni sincronizzazione riuscita. Al passaggio a `UNHEALTHY` una sola email per episodio (`calendar.sync_unhealthy`, chiave con l'ultimo successo). L'unità prende lo stato peggiore dei calendari non disattivati (`UNHEALTHY`, `WARNING`, `HEALTHY`, `NONE`), scritto da `UnitService::setSyncHealth` senza cambiare la versione dell'unità.
31. **Creazione di un calendario** (`POST /admin/units/{id}/external-calendars`, a passi): URL controllato con `SafeHttpFetcher::assertAllowed` (422 `INVALID_ICAL_URL`, `details.reason` = codice di `FetchError`); prima sincronizzazione nella richiesta: se finisce in errore, il calendario e la sua `SyncRun` si cancellano e la risposta è 422 `INVALID_ICAL_URL` con il codice dell'errore. Su un'unità `INSTANT` serve `switch_to_request: true`, altrimenti **409 `UNIT_IS_INSTANT`** (nuovo codice): il passaggio a `REQUEST` cambia il flusso dell'ospite e va confermato (I12).
32. **Cambio di URL** (`PATCH` con `url`): nuova colonna `external_calendars.url_changed_at` (migration `2027_01_18_000300`); ETag e Last-Modified azzerati, sincronizzazione subito. Gli eventi visti l'ultima volta prima del cambio restano esclusi da rimozione in due passi e salvaguardia: restano finché il nuovo URL non li riporta o il gestore li rimuove con `POST /admin/external-events/{id}/remove` (06 "Rimozione").
33. **Disattivazione e rimozione.** `PATCH` con `status` `DISABLED`/`ACTIVE` (la riattivazione su un'unità `INSTANT` → 409 `UNIT_IS_INSTANT`); un calendario disattivato non si sincronizza ma i suoi eventi restano bloccanti. `DELETE` è la rimozione esplicita del gestore: eventi `REMOVED` (date libere, conflitti aperti risolti in automatico), calendario `DISABLED` conservato per storico e conflitti.
34. **Sincronizza ora**: 429 `RATE_LIMITED` se ripetuto entro 2 minuti per lo stesso calendario; stesso lock del job. Dell'URL l'API restituisce solo l'host (`url_host`).
35. **Checklist di collegamento** (06): passi `{canale}.feed_created` e `{canale}.calendar_imported` (non per "altro") calcolati, `{canale}.feed_linked` e, con almeno due OTA, `direct_links_removed` confermati dal gestore (`POST .../calendar-checklist/confirm`, 422 `CHECKLIST_STEP_INVALID` per un passo non manuale o assente). Le conferme stanno in `units.calendar_checklist` (jsonb: passo → istante e utente), con audit `unit.calendar_checklist_confirmed`. Un indizio per `feed_linked` è l'ultima lettura del feed da parte dell'OTA.

### Preventivi (5.7)

36. **Link del preventivo** (rivisto dopo la verifica di M5): come i feed iCal (punto 24), token casuale di 32 byte in base64url (43 caratteri), cifrato a riposo in `quotes.public_token_encrypted` per mostrare di nuovo lo stesso link nel pannello ("Copia link", WhatsApp, email) e cercato per hash SHA-256 in `public_token_hash` (02). Il link non dipende da `APP_KEY`: ruotando la chiave (con la precedente in `APP_PREVIOUS_KEYS`) l'ospite apre ancora lo stesso link, perché la ricerca usa solo l'hash; la chiave serve solo a mostrare il link nel pannello. Migration `2027_01_18_000500`: i preventivi già presenti ricevono un token nuovo. Il link non si invalida cambiando token ma con lo stato (`REVOKED`, `EXPIRED`, `EXHAUSTED`). Pagina ospite `/q/{token}`, API `/public/quotes/{token}`; il tenant si ricava dall'hash con la funzione SECURITY DEFINER `app_tenant_by_quote` (stesso schema dei feed). La prima versione (HMAC dell'ID con una chiave derivata da `APP_KEY`) è stata scartata: vedi Alternative.
37. **Invio**: `POST /quotes/{id}/send` con `channel` = `email` (outbox `quote.sent`, payload solo ID; 422 `QUOTE_EMAIL_MISSING` senza email) o `link` (copia del link e WhatsApp). Entrambi portano DRAFT → SENT e restituiscono `link`, `whatsapp_text` (nella lingua dell'ospite, senza dati personali) e `whatsapp_url` (`https://wa.me/?text=…`, senza numero di telefono). Un DRAFT non è leggibile dall'ospite (404).
38. **Valuta congelata**: `quotes.currency` (nuova colonna, copiata dal piano alla creazione), così l'accettazione non rilegge le tariffe.
39. **Accettazione**: in una transazione con la riga del preventivo bloccata si verificano scadenza (se superata il preventivo passa subito a EXPIRED e la risposta è 410), tentativi, capienza attuale (409 `OCCUPANCY_EXCEEDED`), unità prenotabile e disponibilità, poi T1 con prezzo, righe, caparra e modalità del preventivo (`NewReservation` porta le modalità congelate; con un calendario esterno attivo prevale REQUEST, I12). La sessione di pagamento si apre fuori dalla transazione (05). Il prezzo congelato non viene mai ricalcolato.
40. **Secondo accept (I21)**: con il preventivo IN_CHECKOUT e il tentativo ancora valido (prenotazione AWAITING_PAYMENT, pagamento PENDING non scaduto) la risposta è `200` con lo stesso `reservation_code` e lo stesso `checkout_url` (la sessione si recupera con la stessa idempotency key); `access_token` è `null` perché il token di accesso si mostra una sola volta. Con il tentativo scaduto ma non ancora rilasciato dal job: 409 `QUOTE_CHECKOUT_IN_PROGRESS`. Un accept su un preventivo ACCEPTED: 409 `QUOTE_ALREADY_ACCEPTED`.
41. **Esito del tentativo**: la `ReservationStateMachine`, in ogni transizione di una prenotazione con `quote_id`, chiama `QuoteStateMachine::reservationChanged` nella stessa transazione: AWAITING_APPROVAL, CAPTURING o CONFIRMED → ACCEPTED; EXPIRED o PAYMENT_FAILED → SENT, EXPIRED se il preventivo è scaduto, EXHAUSTED se i tentativi sono finiti. Un preventivo ritirato durante il checkout resta REVOKED qualunque sia l'esito del tentativo.
42. **Scadenza**: oltre al controllo all'accettazione, un job ogni 5 minuti (dispatcher con app_admin_ops, un job per tenant) porta a EXPIRED i DRAFT e i SENT scaduti; il pannello non invia un preventivo scaduto (410). Scadenza personalizzabile alla creazione, al massimo 90 giorni.
43. **Disponibilità alla creazione**: il pannello non crea preventivi su date già non vendibili (409 `DATES_UNAVAILABLE`); la disponibilità si verifica comunque di nuovo all'accettazione.

### Decisioni dopo la verifica di M5

44. **Calendari importati dimostrativi.** Nuova colonna `external_calendars.demo` (boolean, predefinita `false`; migration `2027_01_18_000600`). Un calendario `demo` non si scarica mai: è escluso da `DispatchCalendarSyncJob` e da `DispatchSyncHealthJob`, `IcalImportService::sync` non lo scarica (anche con "Sincronizza ora" o un cambio di URL) e la sua salute è sempre `ACTIVE`. I suoi eventi restano occupazioni a tutti gli effetti (disponibilità, conflitti, feed). Li crea solo il seeder demo tramite `Calendar\DemoCalendars`, con le stesse macchine a stati, la stessa rilevazione dei conflitti e lo stesso accoppiamento con i feed dell'import vero; l'URL è su un dominio `.invalid`. Il pannello li segnala come dimostrativi (`demo` nella risposta dell'API).
45. **Seeder demo di M5**: sul Trilocale (su richiesta) i calendari "Booking demo" e "Airbnb demo" con due eventi ciascuno, uno dei quali in conflitto aperto con la prenotazione manuale confermata; feed per Booking e Airbnb di entrambe le unità; due preventivi, uno inviato (Trilocale) e uno accettato e pagato sul gateway finto (Bilocale).
46. **Piano di M6** (11): nuova attività 6.9 (verifica dei calendari reali con i risultati della Fase 0, adeguamento di `EchoClassifier`, `IcsParser` e dei file di prova; issue #72) e 6.6 estesa agli E2E di `/app/calendari` e `/app/conflitti` (issue #59).

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Client HTTP di Laravel (Guzzle) con un controllo dell'URL prima della richiesta | Guzzle risolve di nuovo il nome al momento della connessione: non protegge dal DNS rebinding senza lo stesso `CURLOPT_RESOLVE` |
| Accettare il primo indirizzo pubblico ignorando quelli privati | Un nome che restituisce anche indirizzi interni è sospetto; nel dubbio si blocca (06) |
| Link del preventivo come HMAC dell'ID con una chiave derivata da `APP_KEY` (prima versione del punto 36) | Ruotare `APP_KEY` avrebbe invalidato tutti i link aperti; scartata nella verifica di M5 a favore del token casuale cifrato, come i feed |
| Calendari demo `DISABLED` per escluderli dal polling | Nel pannello comparirebbero come disattivati e la checklist non li conterebbe; il flag `demo` li tiene attivi ma mai scaricati |

## Conseguenze

- Un calendario su porta non standard non si può importare; finora nessuna OTA nota lo richiede.
- I link dei preventivi creati prima della migration `2027_01_18_000500` cambiano (nessun dato di produzione prima del pilota).
- Il database demo locale va rigenerato (`php artisan migrate:fresh --database=pgsql_migrator --seed`) per vedere i dati di M5.

## Impatto sui test

- Test aggiunti: `Quotes/QuoteAcceptTest` (link casuale indipendente da `APP_KEY`, punto 36), `DemoSeederTest` (dati di M5, punti 44–45), `Unit/Calendar/SafeHttpFetcherTest`, `Unit/Calendar/IcsParserTest`, `Feature/Calendar/IcalImportTest`, `SyncErrorKeepsDatesTest`, `AntiWipeSafeguardTest`, `ConflictOnConfirmedTest`, `ConflictCloseKeepsDatesTest`, `EchoLoopTest`, `FeedMatrixTest`, `IcsUidStabilityTest`, `IcsFeedEndpointTest`, `FrozenFeedNeverEmptyTest`, `RetiredFeed410Test`, `SyncUnhealthyTest`, `ExternalCalendarApiTest`, `CalendarChecklistTest`, `Feature/Quotes/QuoteAcceptTest`, `QuoteExpiredTest`, `QuoteConcurrencyTest` (processi paralleli), `QuoteFrozenPriceTest`.
- Test modificati: `Arch/ExternalHttpTest` (eccezione per `CurlTransport`), `Database/IcsFeedUniquenessTest` (`frozen_at` obbligatorio per i feed FROZEN), `Architecture/RlsCatalogTest` (policy `definer_lookup` su `ics_feeds` e `quotes`).

## Aggiornamento della specifica

- [x] 06-CALENDAR-SYNC, 07-API-CONTRACT e 08-SECURITY-OPERATIONS aggiornati con riferimento a questa ADR (alla chiusura di M5)
