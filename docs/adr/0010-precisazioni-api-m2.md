# ADR-0010 — Precisazioni dell'API dopo M2

- **Stato**: Proposta
- **Data**: 2026-11-06
- **Autori**: Claude Code (stesura durante M2), da verificare con Alessio
- **Documenti di specifica toccati**: 07-API-CONTRACT, 10-TRACEABILITY

## Contesto

Con M2 sono nati gli endpoint di strutture, unità, foto, tariffe, pubblicazione e la prima parte dell'API pubblica. 07 descrive questi endpoint a grandi linee: alcuni codici d'errore, alcune rotte di servizio e la forma degli avvisi non ci sono. C'è poi un caso che il modello dati non permette di rappresentare. In ordine:

1. **Codici d'errore non elencati in 07.** I12 (cambio a `INSTANT` con calendari esterni attivi) è descritto come "`409`" senza codice. Le guardie di pubblicazione "≥ 1 unità attiva" e "traduzione `it`" non hanno un codice. Solo `CANCELLATION_POLICY_MISSING` e `PAYMENTS_NOT_ENABLED` sono nella tabella.
2. **Rotte di servizio del pannello.** 07 elenca `GET/PATCH /properties/{id}` e `POST/DELETE /units/{id}/photos`. Il pannello ha bisogno anche di elencare e creare strutture, di elencare le foto e di cambiarne l'ordine.
3. **Avvisi sulle stagioni.** 11 (attività 2.3) chiede un "avviso di sovrapposizione a parità di priorità", ma 07 non dice come arriva al client.
4. **Idempotency-Key sulla pubblicazione.** 07 chiede `Idempotency-Key` su tutte le `POST` che cambiano stato. Il meccanismo generico (I9, `Feature/IdempotencyKeyTest`) è previsto con i pagamenti.
5. **Guardia sui pagamenti.** 07 ammette la pubblicazione con "Stripe `charges_enabled` o solo unità con prenotazione manuale". In 02, però, l'unità ha `booking_mode` `REQUEST` | `INSTANT` e non esiste un'unità "solo manuale": la prenotazione manuale (`channel = MANUAL`) è un canale della prenotazione, non una proprietà dell'unità.

## Decisione

Si registrano come **aggiunte compatibili** a 07 (restano in `v1`) i punti 1–4. Per il punto 5 si applica per ora la lettura più restrittiva, in attesa di decisione.

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
5. **Guardia sui pagamenti**: per pubblicare serve un `StripeAccount` del tenant con `charges_enabled = true` (`422 PAYMENTS_NOT_ENABLED`). L'alternativa "solo unità con prenotazione manuale" non è implementata, perché nel modello non esiste. **Da decidere** (vedi "Alternative").

API pubblica (07, già coerente; qui solo le precisazioni):
- il tenant si ricava dallo slug con `app_tenant_by_slug`;
- una struttura non `PUBLISHED`, un tenant non `ACTIVE` o un'unità non `ACTIVE` risultano inesistenti (`404`), come chiede la "Disponibilità effettiva" di 02;
- in `quote-price` l'unità arriva nel corpo (`unit_id`);
- `DATES_UNAVAILABLE` arriva con `AvailabilityService` (attività 3.2).

## Alternative considerate

| Alternativa | Perché scartata (o rinviata) |
|---|---|
| Rispondere `400 VALIDATION_FAILED` per le guardie senza codice | Le guardie non riguardano un campo del corpo della richiesta: il client non saprebbe a quale campo associarle |
| Punto 5: aggiungere a `units` un campo che marca l'unità come "solo prenotazioni manuali" (non vendibile online, nascosta o solo vetrina nella pagina ospite) | Cambia il modello di 02 e la pagina ospite: richiede una decisione di prodotto. **Da decidere** |
| Punto 5: permettere la pubblicazione senza Stripe, con le unità visibili ma non prenotabili online fino al collegamento | Sposta il controllo dal momento della pubblicazione al checkout (M4). È più semplice per i piloti, ma una pagina pubblicata non prenotabile può confondere l'ospite. **Da decidere** |
| Punto 5: mantenere la guardia restrittiva (scelta attuale) | Nessun rischio per gli ospiti. Però fino a M4 (onboarding Stripe) nessuna struttura reale si può pubblicare e la pagina pubblica è dimostrabile solo nei test |

## Conseguenze

- I client si basano sui codici: i nuovi codici hanno messaggi in `lang/<locale>/api.php` e nel pannello.
- Nessuna migration.
- Fino alla decisione sul punto 5, la demo di M2 mostra la pubblicazione bloccata da `PAYMENTS_NOT_ENABLED`.

## Impatto sui test

- Test aggiunti: `Feature/PropertyPublishTest`, `Feature/InstantWithCalendarRejectedTest`, `Feature/Admin/RatePlanApiTest` (avvisi), `Feature/Admin/UnitPhotoApiTest`, `Feature/Public/PublicPropertyApiTest`.
- Test da modificare: se si sceglie un'alternativa al punto 5, `PropertyPublishTest` ("richiede un account Stripe abilitato agli incassi").
- Invarianti toccati: I12.

## Aggiornamento della specifica

- [ ] 07: codici nella tabella degli errori, rotte aggiunte, forma di `warnings`, nota sulla Idempotency-Key della pubblicazione (dopo l'accettazione)
- [ ] 07: guardia dei pagamenti secondo la decisione sul punto 5
- [ ] Versione della specifica incrementata
