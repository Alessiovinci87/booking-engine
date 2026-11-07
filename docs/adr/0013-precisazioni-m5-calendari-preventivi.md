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

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Client HTTP di Laravel (Guzzle) con un controllo dell'URL prima della richiesta | Guzzle risolve di nuovo il nome al momento della connessione: non protegge dal DNS rebinding senza lo stesso `CURLOPT_RESOLVE` |
| Accettare il primo indirizzo pubblico ignorando quelli privati | Un nome che restituisce anche indirizzi interni è sospetto; nel dubbio si blocca (06) |

## Conseguenze

- Un calendario su porta non standard non si può importare; finora nessuna OTA nota lo richiede.

## Impatto sui test

- Test aggiunti: `Unit/Calendar/SafeHttpFetcherTest`.
- Test modificati: `Arch/ExternalHttpTest` (eccezione per `CurlTransport`).

## Aggiornamento della specifica

- [ ] 06-CALENDAR-SYNC e 08-SECURITY-OPERATIONS aggiornati con riferimento a questa ADR (alla chiusura di M5)
