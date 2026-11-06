# 06 — Sincronizzazione calendari (iCal)

Versione: 1.1 · Data: 06/11/2026 · Modifiche: ADR-0003, [CHANGELOG-v1.1](CHANGELOG-v1.1.md)

## Principi

- iCal trasporta solo **intervalli occupati**. Un evento importato è un'occupazione, mai una prenotazione completa.
- **Pubblicato ≠ recepito.** Il nostro feed si aggiorna subito; ogni OTA lo legge con i suoi tempi (Airbnb dichiara un aggiornamento automatico ogni 3 ore). Nessun testo dice "sincronizzato in tempo reale".
- **Nel dubbio, si blocca.** Un errore, una risposta vuota anomala, un cambio di URL o la disattivazione di un feed non liberano mai date (I6, I19).
- Un'unità con calendari esterni attivi è sempre `REQUEST` (I12).
- **Non si assume** che un'OTA propaghi verso le altre i calendari che importa. Il nostro sistema fa da **centro**: ogni OTA riceve da noi tutto ciò che occupa l'unità, tranne le proprie prenotazioni.

## Topologia richiesta: a stella

```
            ┌──────── feed per Booking ────────▶  Booking.com
            │◀─────── calendario di Booking ────
   NOSTRO   │
  SISTEMA   │──────── feed per Airbnb ─────────▶  Airbnb
            │◀─────── calendario di Airbnb ─────
            │
            └──────── feed "altro" ────────────▶  altri portali (facoltativo)
```

All'attivazione il gestore deve:
1. collegare a ogni OTA **il feed dedicato a quella OTA** (non un feed generico);
2. importare da noi il calendario di export di ogni OTA;
3. **rimuovere i collegamenti iCal diretti tra le OTA** (es. Airbnb che importa Booking). Se restano, le date si bloccano lo stesso, ma aumentano echi e conflitti.

L'onboarding è una checklist nel pannello con conferma esplicita del gestore per ciascun passo. Da verificare in fase 0 su account reali: cosa contengono esattamente i feed di export di Booking e Airbnb (solo le proprie prenotazioni, anche i blocchi manuali, anche le date importate da altri calendari) e con quale UID.

## Export: un feed per destinazione

Ogni unità ha un `IcsFeed` per ciascuna destinazione usata (`BOOKING`, `AIRBNB`, `OTHER`), con token proprio. Il feed di una destinazione è **accoppiato** al calendario importato dallo stesso canale (`paired_external_calendar_id`).

### Matrice dei flussi

| Origine dell'occupazione | Feed per Booking | Feed per Airbnb | Feed "altro" |
|---|---|---|---|
| Prenotazione diretta (qualsiasi stato occupante) | sì | sì | sì |
| Blocco manuale del gestore | sì | sì | sì |
| Evento importato dal calendario Booking | **no** | sì | sì |
| Evento importato dal calendario Airbnb | sì | **no** | sì |
| Evento importato da un calendario "altro" | sì | sì | no se è il calendario accoppiato a quel feed, altrimenti sì |
| Eco confermata (`CONFIRMED_ECHO`) | no | no | no |
| Eco sospetta (`SUSPECTED_ECHO`) | no | no | no |
| Conflitto aperto senza altre occupazioni sulle date | sì, come "Non disponibile" | sì | sì |

Regola generale (I18): il feed per la destinazione X contiene tutte le occupazioni attive dell'unità **tranne** gli eventi importati dal calendario accoppiato a X e tutti gli echi. Le date di un'eco restano comunque **non vendibili** da noi (02, disponibilità).

### Un'occupazione logica, un UID

- Una prenotazione è **un solo evento** per tutta la sua vita (occupazione unica, 02): dalla fase temporanea a quella definitiva l'UID `occ-{id}@{dominio}` non cambia. Le prenotazioni in attesa sono esportate: è prudente bloccarle anche sulle OTA.
- Se la prenotazione cambia date, l'evento con lo stesso UID cambia `DTSTART`/`DTEND` e incrementa `SEQUENCE`.
- Se l'occupazione viene rilasciata, l'evento **scompare** dal feed.
- Un evento importato e riesportato ha UID `ext-{external_event_id}@{dominio}`.
- Un conflitto esportato da solo ha UID `cfl-{conflict_id}@{dominio}`; se le date sono già coperte da un'altra occupazione esportata, non viene aggiunto.
- **Accorpamento**: se due eventi del feed hanno esattamente lo stesso intervallo, si esporta solo quello con la precedenza più alta (prenotazione diretta > blocco manuale > evento importato > conflitto), così non compaiono doppioni.

### Formato

- `GET /ical/{feed_token}.ics`; token casuale di 32 byte, ruotabile per feed.
- Eventi a giornata intera: `DTSTART;VALUE=DATE` = check-in, `DTEND;VALUE=DATE` = check-out.
- `SUMMARY` neutro "Non disponibile"; nessun dato dell'ospite.
- `Content-Type: text/calendar; charset=utf-8`, `Cache-Control: no-store`, `ETag`.
- Si registrano `last_fetched_at` e user agent: il pannello mostra se l'OTA sta davvero leggendo il feed ("ultima lettura di Airbnb: 2 h fa").
- Rate limit 60 richieste/ora per token.

## Import: i calendari delle OTA

### Configurazione e sicurezza
Come 1.0: solo `https`, controllo DNS anti-SSRF anche dopo i redirect (max 3), 2 MB, 10 s, URL cifrato, prima sincronizzazione obbligatoria.

### Polling
Ogni 10 min con jitter ±90 s; un job per calendario; richieste condizionali; backoff fino a 60 min; rispetto di `Retry-After`.

### Elaborazione
1. Errore, timeout, URL bloccato, ICS non valido → `SyncRun` d'errore; **nessuna modifica**.
2. `304` → `NOT_MODIFIED`.
3. Risposta valida → per ogni `VEVENT` valido:
   - intervallo `[DTSTART, DTEND)` a giornate intere; eventi passati ignorati
   - chiave `(external_calendar_id, UID)`; senza UID → hash di `DTSTART|DTEND|SUMMARY`
   - **classificazione eco** (sotto)
   - nuovo → `ACTIVE`; date cambiate → aggiorna + `AuditLog`; noto → aggiorna `last_seen_at`
   - controllo conflitti per gli eventi non eco
4. Eventi assenti → rimozione in due passi (sotto).
5. **Salvaguardia anti-svuotamento**: zero eventi futuri quando prima erano almeno 2, oppure perdita di oltre il 50% degli eventi futuri → risposta sospetta, nessuna rimozione, allerta.

### Classificazione degli echi
Un'eco è un evento che l'OTA ci rimanda dopo averlo importato dal nostro feed.

| Caso | Classificazione | Effetto |
|---|---|---|
| UID dell'evento contiene il nostro dominio (`@{dominio}`) | `CONFIRMED_ECHO`, `echo_of_ics_uid` valorizzato | blocca le date, non genera conflitti, non viene riesportato |
| UID estraneo, ma intervallo **identico** a un evento che esportiamo nel feed di quella stessa OTA, comparso dopo che l'OTA ha letto il nostro feed | `SUSPECTED_ECHO` | blocca le date, **non viene riesportato** (questo spezza i cicli), genera un conflitto con `suspected_echo = true` solo se si sovrappone a un'occupazione diversa da quella di cui sembra l'eco |
| Altrimenti | `NONE` | evento normale |

Un'eco sospetta si può riclassificare dal pannello ("non è un'eco, è una prenotazione vera"): da quel momento viene riesportata e partecipa ai conflitti. Se in fase 0 il comportamento delle OTA risulta stabile, una ADR potrà rendere automatiche altre regole.

### Rimozione
1. Prima assenza in una risposta valida → `PENDING_REMOVAL`; le date restano bloccate.
2. Assenza confermata in una seconda risposta valida, ad almeno 10 minuti → `REMOVED`.
3. Ricompare → `ACTIVE`.
4. Se l'evento era in un conflitto aperto → il conflitto si risolve con nota automatica e notifica.

Cambio di URL di un calendario: gli eventi del vecchio URL restano finché il nuovo non li conferma o il gestore li rimuove a mano.

## Conflitti

Dopo ogni evento nuovo o modificato non eco, si cercano sovrapposizioni con:
- occupazioni attive dell'unità (prenotazioni in qualsiasi stato occupante, blocchi manuali)
- eventi `ACTIVE` non eco di **altri** calendari dell'unità

Per ogni sovrapposizione: `InventoryConflict OPEN`, date non vendibili, notifica immediata al gestore. **Nessuna** cancellazione automatica. Se il conflitto tocca una prenotazione `AWAITING_APPROVAL`, il pulsante "Approva" è disabilitato.

## Salute della sincronizzazione

| Ultimo successo di un calendario importato | Stato | Effetto |
|---|---|---|
| < 60 min | `ACTIVE` | — |
| 60 min – 3 h | `DEGRADED` | avviso |
| > 3 h | `UNHEALTHY` | email; unità `sync_health = UNHEALTHY` |

In più, per l'export: se un feed `ACTIVE` non viene letto da oltre 24 h, il pannello avvisa "Airbnb non sta leggendo il tuo calendario: controlla il collegamento".

Con `UNHEALTHY` le richieste restano accettabili con avviso evidente; il gestore può sospendere le vendite dell'unità.

## Ciclo di vita dei feed e uscita del cliente

Un feed non viene mai "spento" restituendo un calendario vuoto: per l'OTA un calendario vuoto significa "tutto libero" (I19).

| Stato feed | Cosa serve all'OTA | Quando |
|---|---|---|
| `ACTIVE` | occupazioni correnti | normale |
| `FROZEN` | ultima versione congelata, aggiornata solo con le prenotazioni già esistenti (cancellazioni e modifiche incluse), nessuna nuova vendita | durante l'offboarding |
| `RETIRED` | `410 Gone` | solo dopo conferma esplicita del gestore che l'OTA non usa più il feed, oppure dopo la rotazione del token |

Procedura di uscita (dettagli contrattuali in 09):
1. Il tenant passa a `OFFBOARDING`: pagina ospite e nuove prenotazioni disattivate; i feed passano a `FROZEN`.
2. Il gestore riceve, per ogni unità e OTA, le istruzioni per riattivare la sincronizzazione che userà dopo di noi e rimuovere il nostro feed dall'OTA, più l'elenco delle prenotazioni future da riportare.
3. Il pannello mostra per ogni feed l'ultima lettura dell'OTA. Il gestore conferma per ciascuna OTA di aver rimosso il feed (`channels_disconnected_confirmed_at`).
4. Solo dopo la conferma, oppure 30 giorni dopo l'inizio dell'offboarding se l'OTA ha smesso di leggere il feed da almeno 7 giorni, i feed passano a `RETIRED`.
5. Se un'OTA continua a leggere un feed dopo 30 giorni, il feed resta `FROZEN` e si contatta il gestore; non si ritira in silenzio.

Rotazione del token di un feed: il vecchio token resta `FROZEN` per 7 giorni (per dare tempo di aggiornare l'URL sull'OTA), poi `RETIRED`.

## Interfaccia per il channel manager (fase 3)

Invariata rispetto alla 1.0 (`ChannelManagerProvider`). Quando un'unità passerà a Channex, i suoi feed iCal verso le OTA collegate via API andranno ritirati con la stessa procedura di conferma.

## Test obbligatori

- Matrice dei flussi: prenotazione diretta, evento Booking, evento Airbnb, eco confermata, eco sospetta → contenuto esatto di ogni feed (I18).
- Propagazione: prenotazione importata da Booking compare nel feed per Airbnb e non in quello per Booking.
- Ciclo eco: evento Airbnb → feed Booking → ritorno da Booking con UID estraneo → classificato `SUSPECTED_ECHO`, non riesportato verso Airbnb.
- UID stabile dal checkout alla conferma; scomparsa al rilascio; `SEQUENCE` incrementato alla modifica.
- Accorpamento di intervalli identici.
- ICS valido, vuoto, malformato, enorme, lento, redirect verso IP privato.
- Rimozione in due passi; salvaguardia anti-svuotamento.
- Offboarding: feed `FROZEN` mai vuoto; `RETIRED` solo dopo conferma o condizioni; `410` dopo il ritiro (I19).
