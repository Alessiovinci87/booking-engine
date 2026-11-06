# ADR-0010 — Precisazioni dell'API dopo M2

- **Stato**: Accettata (06/11/2026, verifica di M2)
- **Data**: 2026-11-06
- **Autori**: Claude Code (stesura durante M2), decisioni di Alessio alla verifica di M2
- **Documenti di specifica toccati**: 07-API-CONTRACT, 10-TRACEABILITY, 11-MILESTONES

## Contesto

Con M2 sono nati gli endpoint di strutture, unità, foto, tariffe, pubblicazione e la prima parte dell'API pubblica. 07 descrive questi endpoint a grandi linee: alcuni codici d'errore, alcune rotte di servizio e la forma degli avvisi non ci sono. C'è poi un caso che il modello dati non permette di rappresentare. In ordine:

1. **Codici d'errore non elencati in 07.** I12 (cambio a `INSTANT` con calendari esterni attivi) è descritto come "`409`" senza codice. Le guardie di pubblicazione "≥ 1 unità attiva" e "traduzione `it`" non hanno un codice. Solo `CANCELLATION_POLICY_MISSING` e `PAYMENTS_NOT_ENABLED` sono nella tabella.
2. **Rotte di servizio del pannello.** 07 elenca `GET/PATCH /properties/{id}` e `POST/DELETE /units/{id}/photos`. Il pannello ha bisogno anche di elencare e creare strutture, di elencare le foto e di cambiarne l'ordine.
3. **Avvisi sulle stagioni.** 11 (attività 2.3) chiede un "avviso di sovrapposizione a parità di priorità", ma 07 non dice come arriva al client.
4. **Idempotency-Key sulla pubblicazione.** 07 chiede `Idempotency-Key` su tutte le `POST` che cambiano stato. Il meccanismo generico (I9, `Feature/IdempotencyKeyTest`) è previsto con i pagamenti.
5. **Guardia sui pagamenti.** 07 ammette la pubblicazione con "Stripe `charges_enabled` o solo unità con prenotazione manuale". In 02, però, l'unità ha `booking_mode` `REQUEST` | `INSTANT` e non esiste un'unità "solo manuale": la prenotazione manuale (`channel = MANUAL`) è un canale della prenotazione, non una proprietà dell'unità.
6. **CSRF sull'API pubblica.** Con `statefulApi()` una `POST` dalla pagina ospite sullo stesso dominio richiederebbe il token CSRF, ma l'API pubblica non usa la sessione.
7. **Unità non prenotabile.** Con le vendite sospese o l'unità non attiva, `quote-price` restituiva comunque il prezzo; 07 non ha un codice per questo caso.
8. **Ultima unità attiva di una struttura pubblicata.** 07 non dice se disattivarla sia consentito.

## Decisione

Si registrano come **aggiunte compatibili** a 07 (restano in `v1`) i punti 1–4. Per il punto 5 resta la lettura restrittiva e 07 viene corretto. I punti 6–8 sono decisi alla verifica di M2 e si implementano in M3.

1. **Nuovi codici d'errore**:
   - `409 EXTERNAL_CALENDARS_ACTIVE` (I12), con `details.active_calendars`;
   - `422 NO_ACTIVE_UNITS` e `422 TRANSLATION_IT_MISSING` (guardie di pubblicazione).

   La pubblicazione risponde con il codice della prima guardia non soddisfatta, nell'ordine di 07. In `details.missing` mette l'elenco di tutte, così il pannello le mostra in una volta. Pubblicare una struttura già pubblicata risponde `200` senza modifiche; da `ARCHIVED` risponde `409 ILLEGAL_TRANSITION`.
2. **Rotte aggiunte**:
   - `GET/POST /admin/properties`;
   - `GET /admin/units/{id}/photos`;
   - `PUT /admin/units/{id}/photos/order` (corpo `photo_ids`, la prima foto è la copertina);
   - la rimozione è `DELETE /admin/units/{id}/photos/{photoId}`.
3. **Avvisi**: le risposte di `POST`/`PATCH` delle stagioni e di `GET /units/{id}/seasons` contengono `warnings`, un elenco di `{ code: "SEASON_OVERLAP_SAME_PRIORITY", priority, seasons: [{ id, name, first_night, last_night }] }`. La richiesta va comunque a buon fine.
4. **Pubblicazione senza Idempotency-Key** fino all'introduzione del meccanismo generico. L'operazione è idempotente per natura: la riga è bloccata e una seconda richiesta non cambia nulla e non scrive un secondo audit. Quando arriverà il middleware di I9, anche questa rotta lo userà.
5. **Guardia sui pagamenti**: per pubblicare serve un `StripeAccount` del tenant con `charges_enabled = true` (`422 PAYMENTS_NOT_ENABLED`). L'alternativa "o solo unità con prenotazione manuale" è tolta da 07. Per le demo il seeder crea un account Stripe finto (`acct_demo_…`, `livemode = false`), mai in produzione.
6. **API pubblica senza cookie di sessione**: niente CSRF. Protezione con il rate limit, l'`Idempotency-Key` sulle `POST` e, per le chiamate dalla vista incorporata, il controllo dell'header `Origin` rispetto agli `embed_allowed_origins` del tenant (attività 3.7).
7. **`409 UNIT_NOT_BOOKABLE`**: `quote-price` e `availability` su un'unità con vendite sospese o non attiva (attività 3.2).
8. **Disattivare l'ultima unità attiva di una struttura pubblicata è consentito**: il pannello mostra un avviso; la pagina ospite mostra "Nessuna disponibilità al momento" (chiave di traduzione IT/EN, attività 3.7).

API pubblica (07, già coerente; qui solo le precisazioni):
- il tenant si ricava dallo slug con `app_tenant_by_slug`;
- una struttura non `PUBLISHED`, un tenant non `ACTIVE` o un'unità non `ACTIVE` risultano inesistenti (`404`), come chiede la "Disponibilità effettiva" di 02;
- in `quote-price` l'unità arriva nel corpo (`unit_id`);
- `DATES_UNAVAILABLE` arriva con `AvailabilityService` (attività 3.2).

## Alternative considerate

| Alternativa | Perché scartata (o rinviata) |
|---|---|
| Rispondere `400 VALIDATION_FAILED` per le guardie senza codice | Le guardie non riguardano un campo del corpo della richiesta: il client non saprebbe a quale campo associarle |
| Punto 5: aggiungere a `units` un campo che marca l'unità come "solo prenotazioni manuali" (non vendibile online, nascosta o solo vetrina nella pagina ospite) | Cambia il modello di 02 e la pagina ospite. Scartata |
| Punto 5: permettere la pubblicazione senza Stripe, con le unità visibili ma non prenotabili online fino al collegamento | Una pagina pubblicata non prenotabile può confondere l'ospite. Scartata |
| Punto 5: mantenere la guardia restrittiva | **Scelta.** Nessun rischio per gli ospiti; le demo usano l'account Stripe finto del seeder |
| Punto 6: token CSRF anche sull'API pubblica (`/sanctum/csrf-cookie`) | Lega l'API pubblica alla sessione e non funziona nella vista incorporata su un altro dominio. Scartata |
| Punto 8: impedire la disattivazione dell'ultima unità | Blocca il gestore in casi legittimi (lavori, chiusura stagionale). Scartata |

## Conseguenze

- I client si basano sui codici: i nuovi codici hanno messaggi in `lang/<locale>/api.php` e nel pannello.
- Nessuna migration.
- Nessuna struttura reale si pubblica prima dell'onboarding Stripe (M4). La demo pubblica grazie all'account Stripe finto del seeder demo.
- M3: `UNIT_NOT_BOOKABLE` (3.2), esclusione delle rotte `public/*` dal CSRF con controllo `Origin` e messaggio "Nessuna disponibilità al momento" (3.7), avviso nel pannello sull'ultima unità.

## Impatto sui test

- Test aggiunti: `Feature/PropertyPublishTest`, `Feature/InstantWithCalendarRejectedTest`, `Feature/Admin/RatePlanApiTest` (avvisi), `Feature/Admin/UnitPhotoApiTest`, `Feature/Public/PublicPropertyApiTest`.
- Test aggiunti alla verifica di M2: `Feature/DemoSeederTest` (la struttura demo si pubblica; in produzione il seeder si ferma), `tests/E2E/m2-panel.spec.ts` (in locale; in CI da M6).
- Test previsti in M3: `UNIT_NOT_BOOKABLE` su `quote-price` e `availability`; `Origin` non ammesso sulle `POST` pubbliche; pagina ospite senza unità.
- Invarianti toccati: I12.

## Aggiornamento della specifica

- [x] 07: codici nella tabella degli errori (`EXTERNAL_CALENDARS_ACTIVE`, `NO_ACTIVE_UNITS`, `TRANSLATION_IT_MISSING`, `UNIT_NOT_BOOKABLE`), rotte aggiunte, forma di `warnings`, nota sulla Idempotency-Key della pubblicazione
- [x] 07: guardia dei pagamenti senza l'alternativa "o solo unità con prenotazione manuale"
- [x] 07: API pubblica senza CSRF, struttura senza unità attive
- [x] 11: attività 3.2 e 3.7 aggiornate
- [x] Versione della specifica incrementata (07 → 1.3)
