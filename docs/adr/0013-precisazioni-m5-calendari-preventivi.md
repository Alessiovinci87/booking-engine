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

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Client HTTP di Laravel (Guzzle) con un controllo dell'URL prima della richiesta | Guzzle risolve di nuovo il nome al momento della connessione: non protegge dal DNS rebinding senza lo stesso `CURLOPT_RESOLVE` |
| Accettare il primo indirizzo pubblico ignorando quelli privati | Un nome che restituisce anche indirizzi interni è sospetto; nel dubbio si blocca (06) |

## Conseguenze

- Un calendario su porta non standard non si può importare; finora nessuna OTA nota lo richiede.

## Impatto sui test

- Test aggiunti: `Unit/Calendar/SafeHttpFetcherTest`, `Unit/Calendar/IcsParserTest`, `Feature/Calendar/IcalImportTest`, `SyncErrorKeepsDatesTest`, `AntiWipeSafeguardTest`.
- Test modificati: `Arch/ExternalHttpTest` (eccezione per `CurlTransport`).

## Aggiornamento della specifica

- [ ] 06-CALENDAR-SYNC e 08-SECURITY-OPERATIONS aggiornati con riferimento a questa ADR (alla chiusura di M5)
