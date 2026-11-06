# 01 — Scope MVP 1.0

Versione: 1.1 · Data: 06/11/2026 · Perimetro invariato; criteri di accettazione aggiornati ([CHANGELOG-v1.1](CHANGELOG-v1.1.md))

## Obiettivo

Un prodotto focalizzato sulla **prenotazione diretta**: pagina di prenotazione ospitata, calendario affidabile, preventivi e pagamenti semplici, per proprietari e piccoli gestori (1–20 unità) che oggi dipendono da Booking.com e Airbnb.

Non è un mini-PMS generico, né un concorrente completo di VikBooking.

**Posizionamento commerciale**: sfruttare Booking e Airbnb senza dipenderne. Non "abbandonare le OTA".

## Perimetro incluso

| Area | Specifica 1.0 |
|---|---|
| Tenant | Creato da procedura amministrativa interna. Nessun self-service pubblico |
| Utenti | Un solo utente amministratore operativo per tenant. Il modello dati supporta membri e ruoli, senza UI di gestione |
| Strutture | Crea/modifica: nome, indirizzo, descrizione IT/EN, CIN, fuso orario, contatti, politiche |
| Unità | 1–20 per tenant. Capienza base e massima, foto, descrizione IT/EN, modalità di prenotazione |
| Tariffe | Prezzo base per notte, stagioni per intervallo di date, supplemento ospite extra, pulizie, minimo notti (vedi 04) |
| Calendario | Disponibilità per unità, prenotazioni, blocchi manuali, conflitti |
| iCal | Import da Booking.com e Airbnb; export con un feed per destinazione, in topologia a stella (vedi 06) |
| Pagina ospite | Pagina ospitata IT/EN: struttura, unità, foto, calendario, prezzo, condizioni, checkout |
| Embed | Pulsante/link "Prenota" e iframe opzionale per ricerca date. Il pagamento si apre sempre top-level |
| Prenotazione | Modalità `REQUEST` (conferma manuale, obbligatoria per unità con iCal) e `INSTANT` (solo unità senza calendari esterni) |
| Pagamenti | Stripe Connect direct charges, caparra 30% o saldo totale, EUR. Pre-autorizzazione in `REQUEST` (vedi 05) |
| Preventivi | Risorsa `Quote` con link pubblico, scadenza (48 h di default), sconto esplicito, stesso flusso di prenotazione |
| Prenotazioni manuali | Inserimento dal pannello (telefono/WhatsApp), confermate anche senza pagamento (stato `UNPAID`); pagamenti fuori piattaforma registrabili |
| Cancellazione | Politiche `FLEXIBLE_7D` e `NON_REFUNDABLE`. Cancellazione dal gestore; rimborso Stripe gestito e mostrato separatamente, con retry e chiusura manuale |
| Email | Transazionali IT/EN a ospite e gestore |
| Pannello | Solo italiano: calendario, elenco/dettaglio prenotazioni, preventivi, conflitti, stato sync, impostazioni, pagamenti |
| Metriche | Fatturato diretto, commissioni OTA teoricamente evitate, beneficio diretto stimato (vedi sotto) |
| Export | CSV prenotazioni e anagrafica per uso gestionale (non è un file ufficiale Alloggiati Web) |
| Sicurezza | Multi-tenancy con RLS, RBAC di base, MFA admin, audit log, backup (vedi 08) |
| Privacy | DPA, informative, retention, export/cancellazione (vedi 09) |

### Metriche del pannello

```
fatturato_diretto            = somma importi contrattuali prenotazioni dirette confermate
commissioni_ota_evitate      = fatturato_diretto × percentuale_ota_configurata_dal_gestore
beneficio_diretto_stimato    = commissioni_ota_evitate − commissioni_pagamento − sconti_diretti
```

La terza metrica si chiama **"beneficio diretto stimato"**, mai "risparmio netto". Il pannello mostra la percentuale usata e una nota di attendibilità: la stima assume che la prenotazione sarebbe altrimenti arrivata da OTA.

## Esclusioni esplicite dalla 1.0

- Channel manager via API (Channex): solo l'interfaccia `ChannelManagerProvider`, nessuna implementazione attiva
- Pubblicazione automatica di prezzi sulle OTA
- Alloggiati Web, ROSS1000, tassa di soggiorno (interfaccia `ComplianceProvider` prevista, non implementata)
- Pre-check-in e gestione documenti d'identità
- CRM, marketing, codici sconto, campagne
- WhatsApp automatico (sono ammessi link `wa.me` precompilati nel pannello)
- Gestione pulizie, analytics avanzati, multiutente configurabile
- Fatturazione elettronica
- Tariffe dinamiche, sconti automatici per durata, tariffe distinte per canale (solo simulazione informativa)
- Inventario alberghiero aggregato, camere fungibili, allotment, unità vendute sia intere sia a camere
- Multi-valuta, addebito automatico del saldo, modifica autonoma della prenotazione da parte dell'ospite
- Widget JavaScript montato nel DOM del sito cliente, Stripe Embedded Checkout
- Abbonamenti SaaS automatici (nel pilota fatturati manualmente; Stripe Billing in fase successiva)

## Roadmap

| Fase | Contenuto | Uscita |
|---|---|---|
| 0 — Validazione | 8–10 interviste, 3–5 strutture pilota, listino, richiesta condizioni Channex e Chekin, sandbox Stripe | Requisiti confermati, piloti firmati |
| 1 — MVP 1.0 | Tutto il perimetro incluso | Prima prenotazione diretta reale completata e verificata |
| 2 — Affidabilità | Monitoraggio, rimborsi completi, collaudi, Stripe Billing, onboarding piloti a pagamento | Utilizzo continuativo e disponibilità a pagare |
| 3 — Pro | Adapter Channex, certificazione, attivazione con almeno 8 clienti Pro impegnati (o canone che copre il fisso) | Prenotazione immediata su unità connesse via API |
| 4 — Adempimenti e crescita | `ComplianceProvider` (Chekin o interno), tassa di soggiorno con regole per Comune, CRM, WhatsApp, pre-check-in | Prodotto completo per gestori 3–10 unità |

### Criteri per abilitare `INSTANT` su un'unità con canali esterni (fase 3)

Tutti obbligatori:

1. Integrazione Channex in produzione, certificata e autorizzata per l'unità
2. Riconciliazione iniziale completa tra prenotazioni interne e OTA
3. Test automatici superati per prenotazione, modifica, cancellazione, webhook duplicati
4. Nessun conflitto irrisolto per almeno 30 giorni e almeno 100 eventi di sync (soglie progettuali, non standard)
5. Alert operativi e procedura di blocco vendite attivi
6. Accettazione scritta del rischio residuo da parte del gestore

Il sistema torna automaticamente a `REQUEST` se la connessione si perde o l'inventario non è riconciliato.

## Listino sperimentale (da validare nella fase 0)

| Piano | Prezzo | Include |
|---|---|---|
| Starter | 29 €/mese | 1 unità |
| Plus | 49 €/mese | Fino a 3 unità |
| Pro | 79–99 €/mese | Fino a 5 unità + channel manager (dalla fase 3) |
| Unità extra | +5–10 €/mese | Oltre il limite del piano |

Formula annuale con pagamento mensile, per evitare disdette stagionali.

## Criteri di accettazione MVP 1.0

L'MVP è completo quando tutti questi scenari passano come test end-to-end in staging con Stripe in modalità test:

1. Un gestore configura struttura, unità, stagioni e politiche, e pubblica la pagina ospite IT/EN.
2. Un ospite prenota un'unità `INSTANT`: paga la caparra, riceve conferma, la prenotazione è `CONFIRMED`, tutti i feed esportati contengono le date con lo stesso UID dall'inizio del checkout.
3. Un ospite richiede un'unità `REQUEST`: la carta viene pre-autorizzata; il gestore approva entro 12 h; la caparra viene incassata; la prenotazione è `CONFIRMED`.
4. Stessa richiesta, il gestore rifiuta o lascia scadere: l'autorizzazione viene annullata, le date tornano libere, l'ospite riceve email.
5. Due ospiti tentano le stesse notti in contemporanea: uno solo ottiene l'occupazione, l'altro riceve errore `409`.
6. Un iCal esterno riporta un'occupazione sovrapposta a una prenotazione confermata: nasce un `InventoryConflict`, le date sono bloccate alla vendita, il gestore riceve notifica, la prenotazione resta `CONFIRMED`.
7. Un calendario esterno non risponde per 3 h: l'unità entra in `SYNC_UNHEALTHY` e nessuna data occupata viene liberata.
8. Il gestore crea un preventivo con sconto; l'ospite lo accetta entro la scadenza e prenota; un preventivo scaduto non è pagabile.
9. Il gestore cancella una prenotazione `FLEXIBLE_7D` a più di 7 giorni: date libere subito, rimborso avviato e mostrato "in corso", email "rimborso completato" solo all'esito Stripe; storico conservato.
10. Un webhook Stripe consegnato due volte non produce doppi pagamenti né doppie conferme.
11. Un utente del tenant A non può leggere né modificare nulla del tenant B, né via API né via query diretta con RLS.
12. Un backup viene ripristinato in un ambiente separato e i dati verificati.
13. Una prenotazione importata dal calendario di Booking compare nel feed per Airbnb e non in quello per Booking; un'eco rimandata da un'OTA non viene riesportata.
14. Una capture fallisce: le date restano occupate; il gestore invia un link di recupero; l'ospite paga e la prenotazione è `CONFIRMED`. In assenza di decisioni, dopo 24 h le date si liberano.
15. Una sessione Stripe completata viene scoperta dal job di rilascio prima del webhook: le date non vengono liberate e la prenotazione prosegue.
16. Due aperture contemporanee dello stesso preventivo producono un solo tentativo di checkout; il prezzo resta quello del preventivo anche se le tariffe cambiano.
17. Il gestore crea una prenotazione manuale senza pagamento: è `CONFIRMED` con stato economico `UNPAID`; registra poi un bonifico e risulta pagata.
18. Durante l'uscita di un cliente i feed non diventano mai vuoti e vengono ritirati solo dopo conferma del gestore o alle condizioni previste.
19. Un rimborso fallisce tre volte: il gestore riceve l'allerta e lo segna come rimborsato fuori piattaforma.
