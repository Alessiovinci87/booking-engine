# ADR-0013 — Precisazioni di calendari iCal e preventivi dopo M5

- **Stato**: Accettata
- **Data**: 2026-11-07
- **Autori**: Alessio Vinci, Claude Code
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

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Client HTTP di Laravel (Guzzle) con un controllo dell'URL prima della richiesta | Guzzle risolve di nuovo il nome al momento della connessione: non protegge dal DNS rebinding senza lo stesso `CURLOPT_RESOLVE` |
| Accettare il primo indirizzo pubblico ignorando quelli privati | Un nome che restituisce anche indirizzi interni è sospetto; nel dubbio si blocca (06) |

## Conseguenze

- Un calendario su porta non standard non si può importare; finora nessuna OTA nota lo richiede.

## Impatto sui test

- Test aggiunti: `Unit/Calendar/SafeHttpFetcherTest`, `Unit/Calendar/IcsParserTest`, `Feature/Calendar/IcalImportTest`, `SyncErrorKeepsDatesTest`, `AntiWipeSafeguardTest`, `ConflictOnConfirmedTest`, `ConflictCloseKeepsDatesTest`, `EchoLoopTest`, `FeedMatrixTest`, `IcsUidStabilityTest`, `IcsFeedEndpointTest`, `FrozenFeedNeverEmptyTest`, `RetiredFeed410Test`.
- Test modificati: `Arch/ExternalHttpTest` (eccezione per `CurlTransport`), `Database/IcsFeedUniquenessTest` (`frozen_at` obbligatorio per i feed FROZEN), `Architecture/RlsCatalogTest` (policy `definer_lookup` su `ics_feeds`).

## Aggiornamento della specifica

- [ ] 06-CALENDAR-SYNC e 08-SECURITY-OPERATIONS aggiornati con riferimento a questa ADR (alla chiusura di M5)
