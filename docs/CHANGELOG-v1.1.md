# Changelog v1.0 → v1.1

Data: 06/11/2026 · Origine: revisione tecnica della specifica v1.0 (10 punti: 6 bloccanti, 4 ad alta priorità).

Esito: tutti e 10 i punti accolti. Perimetro MVP invariato; stack invariato (Laravel 13, PHP 8.4, React, PostgreSQL con RLS, Stripe Connect, iCal).

| # | Tema | Priorità | ADR |
|---|---|---|---|
| C1 | Propagazione iCal tra OTA | Bloccante | ADR-0003 |
| C2 | `PAYMENT_FAILED`, hold e garanzia unica dell'inventario | Bloccante | ADR-0002, ADR-0004 |
| C3 | Scadenze di checkout e occupazione | Bloccante | ADR-0004 |
| C4 | Cancellazione e rimborso | Bloccante | ADR-0004 |
| C5 | Uscita del cliente e feed iCal | Bloccante | ADR-0003 |
| C6 | Prenotazioni manuali senza pagamento | Bloccante | ADR-0004 |
| C7 | Vincoli tra tenant | Alta | ADR-0005 |
| C8 | Duplicati nell'export iCal | Alta | ADR-0002, ADR-0003 |
| C9 | Retention e anonimizzazione | Alta | nessuna (precisazione) |
| C10 | Concorrenza e prezzo dei preventivi | Alta | nessuna (precisazione) |

---

## C1 — Propagazione iCal tra OTA

**Prima.** Un solo feed di export per unità; gli eventi importati dalle OTA non venivano riesportati, assumendo che le OTA si sincronizzassero tra loro. Una prenotazione presa su Booking non bloccava Airbnb passando dal nostro sistema.

**Ora.**
- Topologia a stella: il nostro sistema è il centro, ogni OTA importa solo il feed a lei dedicato; i collegamenti iCal diretti tra OTA vanno rimossi (checklist di attivazione).
- Un `IcsFeed` per unità e destinazione (`BOOKING`, `AIRBNB`, `OTHER`), accoppiato al calendario importato dallo stesso canale.
- Matrice dei flussi esplicita: il feed per X contiene tutto tranne gli eventi importati da X e gli echi.
- Classificazione degli echi (`CONFIRMED_ECHO` da UID nostro, `SUSPECTED_ECHO` per intervallo identico con UID estraneo): bloccano le date ma non vengono mai riesportati, così i cicli si interrompono.
- Monitoraggio delle letture dei feed da parte delle OTA.
- Nessuna ipotesi sul comportamento delle OTA: da verificare in fase 0 su account reali.

**File.** 06, 02 (`IcsFeed`, `echo_kind`), 07 (`/ics-feeds`, checklist canali), 01 (criterio 13), 08 (invariato).
**Invarianti.** Nuova I18; I6 confermata.
**Test.** Contenuto di ogni feed per ogni origine; propagazione Booking → Airbnb e Airbnb → Booking; ciclo eco interrotto; riclassificazione manuale di un'eco sospetta.

## C2 — `PAYMENT_FAILED`, hold e garanzia unica dell'inventario

**Prima.** Dopo una capture fallita la prenotazione andava in `PAYMENT_FAILED` (terminale, "non occupa") mentre l'hold restava attivo, e il gestore poteva creare un nuovo tentativo come prenotazione separata. Le sovrapposizioni erano vietate da due vincoli su due tabelle diverse (prenotazioni, hold), con i controlli incrociati affidati al codice.

**Ora.**
- Nuovo stato non terminale `CAPTURE_FAILED` che occupa le date, con scadenza di decisione 24 h (T13, T15, T16).
- Recupero sulla **stessa** prenotazione e sulla stessa occupazione, con `Payment` `purpose = RECOVERY` (Flusso R).
- `InventoryHold` e `InventoryBlock` sostituiti da **`InventoryOccupancy`**: una sola tabella, un solo vincolo GiST per tutte le occupazioni interne, una sola occupazione per prenotazione per tutta la vita.
- Unico punto di scrittura: `InventoryService` (acquire, promoteToFirm, setTemporaryReason, move, release), con lock sull'unità; controllo statico in CI.
- Tabella esplicita stato prenotazione ↔ occupazione.

**File.** 02, 03, 05, 07 (`/recovery-link`, `/release-dates`), 08, CLAUDE.md, 01 (criterio 14).
**Invarianti.** I1 riformulata (garanzia del database); nuova I15.
**Test.** Coerenza stato/occupazione dopo ogni transizione; capture fallita → recupero pagato / scaduto / nessuna decisione; 20 acquisizioni parallele; scrittura diretta della tabella bloccata dall'analisi statica.

## C3 — Scadenze di checkout e occupazione

**Prima.** Hold di 30 minuti e sessione Stripe di 30 minuti creati in momenti diversi: un ritardo poteva lasciare una sessione pagabile dopo il rilascio delle date. Il rilascio non verificava lo stato della sessione.

**Ora.**
- Sequenza in tre passi: occupazione con scadenza provvisoria (45 min) → sessione Stripe con idempotency key → scadenza dell'occupazione = scadenza **restituita da Stripe** + 5 minuti.
- Un'occupazione con sessione si rilascia solo dopo `checkout.sessions.expire` riuscito o dopo aver verificato che la sessione è chiusa; se risulta completata, si procede con il pagamento (T6).
- Recupero della sessione tramite idempotency key se il sistema si interrompe tra i passi.
- Si assume esplicitamente che il webhook possa arrivare dopo la scadenza (T7).

**File.** 05, 03, 02 (`checkout_expires_at`, regola sulle occupazioni scadute), CLAUDE.md, 01 (criterio 15).
**Invarianti.** Nuova I16; I4 confermata.
**Test.** Sessione creata in ritardo; errore Stripe; interruzione tra passo 2 e 3; sessione completata scoperta dal job; webhook dopo il rilascio.

## C4 — Cancellazione e rimborso

**Prima.** `CONFIRMED → CANCELLED` liberava le date e "rimborsava" nello stesso passaggio, senza distinguere l'esito asincrono del rimborso.

**Ora.**
- Esiti separati: prenotazione `CANCELLED` e date libere subito; `Refund` con propria macchina a stati (`PENDING`, `SUCCEEDED`, `FAILED`, `CANCELLED`); `refund_status` derivato e visibile nel pannello.
- Email "rimborso avviato" alla cancellazione, "rimborso completato" solo a `SUCCEEDED`.
- Errori: retry automatici (1 h, 6 h, 24 h), poi allerta e azioni "Riprova" / "Segna come rimborsato fuori piattaforma".
- Un rimborso fallito non riapre la prenotazione.

**File.** 03 (macchina del rimborso), 05, 02 (`Refund.method`, `refund_status`), 07 (`/refunds`), 01 (criteri 9 e 19).
**Invarianti.** Nuova I20; I7 confermata.
**Test.** Cancellazione con rimborso riuscito, fallito, segnato offline; nessuna email di completamento prima dell'esito.

## C5 — Uscita del cliente e feed iCal

**Prima.** All'uscita del cliente l'export ICS veniva disattivato subito: le OTA avrebbero potuto continuare a leggere un feed sparito o vuoto.

**Ora.**
- Stati del feed `ACTIVE` → `FROZEN` → `RETIRED`. Un feed non serve mai un calendario vuoto; `RETIRED` risponde `410`.
- Procedura guidata: export con prenotazioni future, istruzioni per OTA, ultima lettura visibile, conferma del gestore per ogni OTA.
- Ritiro solo dopo conferma, oppure dopo 30 giorni se l'OTA non legge il feed da 7 giorni; mai in silenzio se l'OTA lo legge ancora.
- La rotazione di un token segue la stessa logica (vecchio token `FROZEN` per 7 giorni).

**File.** 06, 09, 02 (campi di offboarding del tenant), 07 (`/tenant/offboarding`), 01 (criterio 18).
**Invarianti.** Nuova I19.
**Test.** Feed `FROZEN` con prenotazioni esistenti; nessun feed vuoto in nessuna fase; `410` dopo il ritiro; ritiro bloccato se l'OTA legge ancora.

## C6 — Prenotazioni manuali senza pagamento

**Prima.** I11 richiedeva un pagamento offline registrato per confermare una prenotazione manuale, mentre `createManual` lo rendeva facoltativo.

**Ora.** Consentita, come proposto: la prenotazione manuale è `CONFIRMED` con `confirmation_kind = ADMINISTRATIVE` e stato economico `UNPAID`, evidenziato nel pannello. I11 distingue la conferma amministrativa dalla conferma per pagamento online.

**File.** 02 (`confirmation_kind`, I11), 03 (T2), 05 (Flusso D), 07, 01 (criterio 17).
**Invarianti.** I11 riformulata.
**Test.** Manuale senza pagamento → `CONFIRMED`/`UNPAID`; pagamento offline → `PAID`; prenotazione online mai confermata senza incasso.

## C7 — Vincoli tra tenant

**Prima.** `tenant_id` e RLS su ogni tabella, ma FK semplici: PostgreSQL verifica le FK senza RLS, quindi un ID di un altro tenant inserito per errore sarebbe stato accettato.

**Ora.** `UNIQUE (tenant_id, id)` su ogni tabella tenant-scoped e FK composite `(tenant_id, parent_id) → (tenant_id, id)` per ogni relazione tra tabelle tenant-scoped; indici con `tenant_id` in testa; test su `pg_constraint`.

**File.** 02, 08, CLAUDE.md.
**Invarianti.** Nuova I17; I5 rafforzata.
**Test.** Catalogo dei vincoli; inserimento trasversale rifiutato dal database.

## C8 — Duplicati nell'export iCal

**Prima.** Prenotazioni in attesa e relativi hold esportati come due eventi distinti sullo stesso intervallo; UID diverso tra checkout e conferma.

**Ora.** Un'occupazione logica per prenotazione con UID stabile `occ-{id}@{dominio}` per tutta la vita; modifica di date con lo stesso UID e `SEQUENCE` incrementato; scomparsa dal feed al rilascio; accorpamento di intervalli identici con regola di precedenza.

**File.** 06, 02.
**Invarianti.** Rafforza I15 e I18.
**Test.** UID stabile dal checkout alla conferma; nessun doppione per intervalli identici; comportamento a modifica e rilascio.

## C9 — Retention e anonimizzazione

**Prima.** Durate (10 anni, 24 mesi, 90 giorni) presentate come regole, senza distinguere categorie di dati e copie.

**Ora.**
- Durate dichiarate come ipotesi, configurabili in `RetentionPolicy`, con base giuridica da validare e traccia della validazione.
- Sette categorie separate (amministrativi, identificativi, riferimenti ai pagamenti, tentativi, eventi iCal, log, comunicazioni).
- Anonimizzazione estesa a tutte le copie: audit log senza valori personali per progetto, outbox svuotato, export a scadenza, provider email, backup (ripristino seguito da nuova anonimizzazione).

**File.** 09, 02 (`RetentionPolicy`, `AuditLog.changes`, `Guest.anonymized_at`), CLAUDE.md.
**Invarianti.** Nuova I22.
**Test.** Anonimizzazione su tutte le copie; audit log privo di valori personali; job di retention per categoria.

## C10 — Concorrenza e prezzo dei preventivi

**Prima.** Il preventivo passava ad `ACCEPTED` prima del pagamento e tornava `SENT` in caso di fallimento; non erano definiti accettazioni contemporanee, idempotenza, prezzo e limite dei tentativi.

**Ora.**
- Nuovi stati `IN_CHECKOUT` ed `EXHAUSTED`; `ACCEPTED` solo quando l'ospite ha pagato o autorizzato.
- Lock della riga del preventivo; un solo tentativo attivo (`active_reservation_id`); un secondo `accept` restituisce lo stesso checkout.
- Prezzo congelato alla creazione, nessun ricalcolo.
- Massimo 5 tentativi di checkout, poi `EXHAUSTED`.
- Scadenza verificata all'avvio del checkout.

**File.** 03, 02, 04, 07, 01 (criterio 16).
**Invarianti.** Nuova I21; I8 e I14 riformulate.
**Test.** Due `accept` paralleli; doppio clic; tentativi esauriti; tariffe modificate dopo l'emissione.

---

## Punti aperti che restano dipendenze esterne

- Comportamento reale dei feed di export di Booking e Airbnb (contenuto, UID, propagazione): fase 0.
- `checkout.sessions.expire` e manual capture su account connessi: test in sandbox.
- Basi giuridiche e durate di conservazione: professionista.
