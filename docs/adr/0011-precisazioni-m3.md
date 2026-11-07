# ADR-0011 — Precisazioni di prenotazioni, pagamenti e pagina ospite dopo M3

- **Stato**: Accettata
- **Data**: 2026-11-07
- **Autori**: Claude Code (stesura), verificata da Claude (chat) per Alessio
- **Documenti di specifica toccati**: 03-BOOKING-STATE-MACHINE, 05-PAYMENTS-SPEC, 07-API-CONTRACT, 08-SECURITY-OPERATIONS

## Contesto

L'implementazione di M3 (inventario, macchina a stati, checkout con gateway finto, pannello e pagina ospite) ha incontrato punti che la specifica lascia aperti o descrive solo a grandi linee. Questa ADR li fissa. Nessun punto cambia un comportamento concordato: le scelte riempiono vuoti e sono coerenti con gli invarianti I1–I22.

## Decisione

### Macchina a stati e pagamenti (3.4–3.6)

1. **T6 passa da T3/T4.** Un pagamento riuscito su una prenotazione `AWAITING_PAYMENT` in recupero applica la stessa transizione del primo tentativo (T3 immediata, T4 su richiesta); non esiste un percorso separato.
2. **T7 da qualsiasi stato terminale.** Un pagamento che arriva dopo il rilascio delle date (`EXPIRED`, `PAYMENT_FAILED`, `CANCELLED`, `REJECTED`) apre sempre un rimborso o l'annullo dell'autorizzazione (I4). Un Payment `EXPIRED` o `FAILED` può passare a `SUCCEEDED`/`AUTHORIZED` per registrare il fatto, mai la prenotazione.
3. **Finestra di approvazione di 12 ore** (`config/payments.php`), calcolata dal momento dell'autorizzazione.
4. **Guardie sul canale (I11).** `startCheckout` rifiuta il canale `MANUAL`; `createManual` accetta solo `MANUAL`.
5. **Stato economico e `refund_status` derivati, mai salvati.** `ReservationEconomics`: incassato = importi catturati dei Payment `SUCCEEDED`, `PARTIALLY_REFUNDED`, `REFUNDED`; rimborsato = Refund `SUCCEEDED`; autorizzato = Payment `AUTHORIZED`/`CAPTURE_PENDING`. `refund_status` riassume i rimborsi (`NONE`, `PENDING`, `SUCCEEDED`, `PARTIALLY_FAILED`, `FAILED`).
6. **Pagamenti offline** solo su prenotazioni `CONFIRMED` e per un importo tra 1 centesimo e il residuo dovuto; altrimenti `409 ILLEGAL_TRANSITION` o `400 VALIDATION_FAILED`.
7. **Motivo del rifiuto** salvato in `reservations.cancel_reason` (testo libero, può contenere dati personali), mai nell'audit log.
8. **Modifica di una prenotazione** (`PATCH`): ricalcolo con le tariffe correnti, sconto originale conservato (dalle righe `DISCOUNT`), `If-Match` obbligatorio.
9. **Idempotency-Key** su ogni POST che cambia stato, compresi `offline-payments` e `blocks`; chiave in uso → `409 IDEMPOTENCY_KEY_IN_USE`.
10. **Chiamate al servizio di pagamento fuori transazione.** `WithTenantContext` in modalità `tenant:<superficie>,steps`: niente transazione unica di richiesta, ogni passo apre la propria con `TenantContext::run`. Lo stato intermedio si salva prima della chiamata (CLAUDE.md regola 15).
11. **Rilascio delle occupazioni** (3.6): un dispatcher ogni minuto con `app_admin_ops` trova i tenant con occupazioni scadute (solo gli ID) e accoda un job per tenant che lavora con `app_runtime` e `SET LOCAL app.tenant_id`. Un rilascio in ritardo di oltre 30 minuti genera `occupancy_release.overdue` nel log.
12. **Promemoria a 12 ore per `CAPTURE_FAILED`** rinviato a M4 insieme alle email di Stripe.
13. **Blocchi manuali consentiti anche su unità con vendite sospese**: sono lo strumento per chiudere date durante lavori o chiusure.

### Pagina ospite e API pubblica (3.7)

14. **Token di accesso alla prenotazione.** `POST /public/{t}/{p}/reservations` restituisce anche `access_token` (in chiaro una sola volta; nel database solo l'hash). La pagina ospite lo conserva in `sessionStorage` (`guest.reservation.{code}`) insieme a `checkout_url` per riprendere il pagamento dopo un annullamento. L'email di recupero punta alla pagina della prenotazione con il token nel link.
15. **Risoluzione del tenant da codice e token.** `GET /public/reservations/{code}?token=` non contiene lo slug: una funzione `SECURITY DEFINER` (`app_tenant_by_reservation_access`, eseguibile solo da `app_runtime`) restituisce l'ID del tenant solo se codice e hash del token corrispondono. Senza token il codice non rivela neanche l'esistenza della prenotazione (`404`). La risposta non contiene dati personali né `checkout_url`.
16. **Versioni dei documenti legali** = primi 12 caratteri esadecimali dello SHA-256 dell'URL (`privacy_version`, `terms_version` in `GET /public/{t}/{p}`). Cambiare il link cambia la versione; una versione accettata diversa da quella corrente → `400 VALIDATION_FAILED`. Le versioni accettate si registrano nell'audit (`reservation.terms_accepted`), senza dati dell'ospite. Nel pannello, accanto ai campi dell'informativa privacy e delle condizioni, l'avviso: "Se aggiorni il documento, cambia anche il link (per esempio aggiungendo ?v=2): così agli ospiti verrà chiesto di accettare la nuova versione" (decisione dopo la verifica di M3).
17. **Informativa privacy obbligatoria per pubblicare**: nuova guardia `422 PRIVACY_POLICY_MISSING`, dopo la politica di cancellazione e prima dei pagamenti; su una struttura pubblicata non si può svuotare il link. Le condizioni di prenotazione (`terms_url`) restano facoltative: se assenti la casella non compare e `accepted_terms_version` è `null`.
18. **Niente cookie sull'API pubblica.** Il middleware stateful di Sanctum si applica solo al gruppo `/admin`; le rotte `public/*` non avviano sessioni e non usano CSRF. Al loro posto: controllo `Origin` (`public.origin`): ammessi richieste senza `Origin`, l'origine dell'app e le `embed_allowed_origins` del tenant; altre origini → `403 FORBIDDEN`. Limiti `public-read` e `public-booking` (per IP e per hash dell'email).
19. **Pagine ospite ed embed senza sessione.** `/p/{t}/{p}` e `/e/{t}/{p}` escludono sessione, cookie e CSRF. CSP con nonce per pagina (`default-src 'self'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`); `frame-ancestors 'none'` per pannello e pagina ospite, `frame-ancestors` = `embed_allowed_origins` per la vista embed (struttura pubblicata, altrimenti `404`); API con `default-src 'none'; frame-ancestors 'none'`. Solo in locale, con il server Vite attivo, si aggiungono l'origine di Vite e gli stili inline. Origini aggiuntive per le immagini da `CSP_IMG_SRC`.
20. **Pagamento sempre a pagina intera.** Fuori dall'iframe si usa `location.assign`; nella vista embed `window.top.location`, poi una nuova scheda, infine un pulsante se il browser blocca la finestra. Il ritorno dal pagamento va sempre su `/p/…/prenotazione/{code}`.
21. **Pagina di ritorno**: interroga lo stato ogni 2 s per al massimo 60 s finché la prenotazione è `AWAITING_PAYMENT` o `CAPTURING`, poi invita ad attendere l'email. Il redirect non conferma nulla (I2). Con `?cancelled=1` offre "Riprendi il pagamento" se la sessione salvata non è scaduta.
22. **Prezzo cambiato durante la compilazione**: `409 PRICE_CHANGED` con `details.price` (stesso formato di `quote-price`); la pagina aggiorna il riepilogo e chiede una nuova conferma con una nuova Idempotency-Key, perché il corpo è cambiato.
23. **Ultima unità attiva**: il pannello chiede conferma prima di disattivarla su una struttura pubblicata (ADR-0010 punto 8).

### Pannello (3.8)

24. **Azioni dal server.** Il dettaglio della prenotazione elenca in `actions` le azioni ammesse (`approve`, `reject`, `cancel`, `modify`, `recovery_link`, `release_dates`, `offline_payment`), ricavate dalla tabella delle transizioni di 03; `offline_payment` compare solo con un residuo da incassare. Il pannello mostra solo quei pulsanti e non replica le regole.
25. **Rimborso proposto visibile prima di cancellare.** Per le prenotazioni `CONFIRMED` il dettaglio include `cancellation_refund` (`proposed` dalla politica congelata, `max` = incassato online ancora rimborsabile), gli stessi valori che `POST /cancel` usa se `refund_cents` manca.
26. **Calendario mensile per unità** con una griglia di 6 settimane da lunedì; ogni notte mostra l'occupazione che la copre (colore per stato, bordo tratteggiato per le occupazioni temporanee), gli eventi esterni e un bordo rosso per i conflitti aperti. Clic su una notte libera: nuovo blocco da quella data; clic su un blocco: rimozione.
27. **Seeder demo con prenotazioni** create con i flussi veri (checkout con gateway finto, macchina a stati, pagamenti offline), date relative al giorno dell'esecuzione; struttura demo pubblicata, Bilocale immediato e Trilocale su richiesta. Richiede `PAYMENT_GATEWAY=fake`.

### Decisioni dopo la verifica di M3

28. **Anteprima della modifica.** Prima di salvare una modifica (T18) il pannello mostra vecchio e nuovo soggiorno, righe e totale e chiede una conferma esplicita. `POST /admin/reservations/{id}/modify-preview` (stesso body del `PATCH`, senza `If-Match`) esegue la modifica vera in un savepoint annullato: restituisce `current`, `proposed` (date, notti, ospiti, righe, totale, caparra, saldo), `difference` e `version`, con gli stessi errori del `PATCH` (`DATES_UNAVAILABLE`, `ILLEGAL_TRANSITION`, `MIN_NIGHTS_NOT_MET`, ...), senza scrivere nulla (nemmeno audit o storico). Il `PATCH` accetta `expected_total_cents` facoltativo: se il prezzo ricalcolato è diverso → `409 PRICE_CHANGED` con il nuovo totale in `details.contract_total`, e il pannello torna all'anteprima.
29. **Avviso sui link legali** nel pannello: vedi punto 16.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Token di accesso solo nell'email | La pagina di ritorno da Stripe non avrebbe modo di leggere lo stato prima dell'arrivo dell'email |
| `checkout_url` nella risposta di `GET /public/reservations/{code}` | Chiunque abbia il link dell'email potrebbe riaprire una sessione di pagamento; la ripresa resta legata alla scheda che l'ha creata |
| Versioni legali inserite a mano dal gestore | Un campo in più da ricordare; l'hash dell'URL cambia da solo quando cambia il documento pubblicato |
| CSRF con cookie anche sull'API pubblica | Richiede una sessione e un cookie anche dentro iframe di terze parti, dove i cookie sono spesso bloccati |
| Pagamento dentro l'iframe | Stripe Checkout lo vieta e l'ospite non vedrebbe la barra dell'indirizzo del fornitore |

## Conseguenze

- Nuova migration `2026_12_07_000100_create_reservation_access_lookup.php` (policy `definer_lookup` su `reservations` per `app_migrator`, come per `tenants` e `tenant_members`).
- Le strutture pubblicate prima di M3 senza `privacy_policy_url` restano pubblicate; non si possono ripubblicare né svuotare il link finché non lo aggiungono.
- Nessun dato personale lascia il server attraverso l'API di stato o i log.

## Impatto sui test

- Test aggiunti: `Admin/ReservationModifyPreviewTest` (punto 28), `ConfirmationKindTest`, `NoConfirmOnRedirectTest`, `ManualUnpaidTest`, `CancelHistoryTest`, `Reservations/OccupancyReleaseJobTest`, `Public/PublicReservationApiTest`, `SecurityHeadersTest`, `Payments/SimulatedCheckoutTest`, `Admin/ReservationApiTest`.
- Test modificati: `PropertyPublishTest` (guardia privacy), `Architecture/RlsCatalogTest` (`reservations.definer_lookup`), `Auth/ReauthTest` e `Tenancy/TenantRequestRollbackTest` (middleware stateful esplicito), `Frontend/SpaRoutesTest` (testi della pagina ospite).
- Invarianti toccati: I4, I6, I9, I11, I15.

## Aggiornamento della specifica

- [x] 07-API-CONTRACT aggiornato con riferimento a questa ADR
- [ ] Versione della specifica incrementata (alla chiusura di M3)
