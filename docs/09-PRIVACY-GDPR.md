# 09 — Privacy e GDPR

Versione: 1.3 · Data: 07/11/2026 · Modifiche: ADR-0003, [CHANGELOG-v1.1](CHANGELOG-v1.1.md); 1.2: ADR-0009; 1.3: ADR-0014 (DPA prima della pubblicazione, export stampabile, ri-autenticazione dell'anonimizzazione, link della piattaforma)

> Questo documento è una specifica tecnica e organizzativa, **non** un parere legale. DPA, informative, registro e condizioni di prenotazione vanno redatti o verificati da un professionista prima del pilota con ospiti reali.

## Ruoli per finalità

| Finalità | Dati | Titolare | Responsabile |
|---|---|---|---|
| Gestione prenotazioni degli ospiti tramite il software | ospiti, prenotazioni, pagamenti (riferimenti) | Struttura | Piattaforma (art. 28) |
| Pagamenti degli ospiti | dati di carta | Struttura e Stripe (secondo i termini Stripe) | — la piattaforma non tratta dati di carta |
| Rapporto commerciale con il gestore (contratto, fatturazione SaaS, assistenza) | dati del gestore e dei suoi utenti | Piattaforma | — |
| Sicurezza del servizio (log, audit, prevenzione abusi) | IP, user agent, log | Piattaforma per i propri sistemi; per conto della struttura per i log relativi ai suoi dati | — |

La piattaforma **non** usa i dati degli ospiti per finalità proprie (marketing, profilazione, statistiche commerciali), salvo dati aggregati e anonimi sull'utilizzo del servizio.

## Requisiti MVP

| # | Requisito | Dove si implementa |
|---|---|---|
| 1 | DPA art. 28 firmato con ogni struttura prima dell'attivazione | onboarding; campo `Tenant.dpa_signed_at`, `dpa_version` (`php artisan tenant:dpa`; senza DPA la struttura non si pubblica, `422 DPA_NOT_SIGNED`, ADR-0014) |
| 2 | Informativa della piattaforma (come responsabile) accessibile dal checkout | link fisso nel footer della pagina ospite (`PLATFORM_PRIVACY_NOTICE_URL`, ADR-0014) |
| 3 | Informativa della struttura (come titolare) collegata nel checkout | `Property.privacy_policy_url` obbligatorio per pubblicare |
| 4 | Registro dei trattamenti del responsabile | documento interno, revisionato a ogni nuovo sub-responsabile |
| 5 | Elenco sub-responsabili pubblicato e comunicato | pagina pubblica + preavviso ai clienti per modifiche |
| 6 | Retention documentata per categoria, configurabile e validata | `RetentionPolicy` + job di retention |
| 7 | Export e cancellazione dove applicabili | endpoint di 07 |
| 8 | Gestione delle richieste degli interessati | procedura sotto |
| 9 | Procedura data breach | procedura sotto |
| 10 | Divieto di marketing con i dati degli ospiti senza autonoma base giuridica | nessuna funzione marketing nella 1.0; nessun opt-in pre-spuntato |

## Dati raccolti dagli ospiti (minimizzazione)

| Dato | Obbligatorio | Motivo |
|---|---|---|
| Nome, cognome | sì | identificare la prenotazione |
| Email | sì | conferme e comunicazioni del soggiorno |
| Telefono | sì | contatto della struttura per l'arrivo |
| Paese | sì | lingua, eventuali obblighi |
| Numero di adulti e bambini | sì | prezzo e capienza |
| Note per la struttura | no | testo libero, con avviso di non inserire dati sensibili |

**Non** raccolti nella 1.0: documenti d'identità, data e luogo di nascita, dati per Alloggiati Web o ROSS1000. Questi arriveranno solo con il modulo adempimenti (fase 4), con la sequenza: raccolta con informativa → comunicazione autorizzata → conservazione della ricevuta → cancellazione delle copie dei documenti dopo l'invio.

Al checkout l'ospite accetta termini e informativa (versione registrata in prenotazione). Nessuna casella di consenso marketing nella 1.0.

## Conservazione

Le durate seguenti sono **ipotesi di lavoro**, non obblighi generali validi per qualunque dato. Ogni durata vive in `RetentionPolicy` (02), è configurabile senza modificare il codice e va validata dal professionista con la base giuridica indicata (`validated_by`, `validated_at`). Finché una categoria non è validata, il pannello di amministrazione interno la segnala.

Le categorie sono separate perché hanno basi giuridiche e durate diverse:

| Categoria | Contenuto | Base giuridica da verificare | Ipotesi di durata | Azione |
|---|---|---|---|---|
| A — Dati amministrativi della prenotazione | codice, unità, date, importi, righe di prezzo, stato | obblighi contabili e fiscali **della struttura** | 10 anni dal soggiorno | conservazione, senza dati identificativi dopo la categoria B |
| B — Dati identificativi e di contatto dell'ospite | nome, cognome, email, telefono, note | esecuzione del contratto di soggiorno; eventuale obbligo di conservazione legato ai documenti contabili della struttura | 24 mesi dal check-out | anonimizzazione |
| C — Riferimenti ai pagamenti | ID Stripe (sessione, PaymentIntent, rimborso), importi, esiti | obblighi contabili; i dati di carta restano solo in Stripe | come categoria A | conservazione; gli ID Stripe sono pseudonimi, i dati personali restano presso Stripe nel conto della struttura |
| D — Tentativi non conclusi | prenotazioni `EXPIRED`, `PAYMENT_FAILED`, `REJECTED`; preventivi non accettati | interesse legittimo alla gestione delle richieste | 90 giorni | cancellazione dei dati personali |
| E — Eventi iCal importati | intervalli, UID, sommario ridotto | esecuzione del servizio | 30 giorni dopo il check-out | cancellazione |
| F — Log di sicurezza e audit | azioni, attori, IP | interesse legittimo alla sicurezza | 24 mesi (audit), 30 giorni (log applicativi) | cancellazione |
| G — Comunicazioni | payload outbox, log del provider email | esecuzione del servizio | 30 giorni dopo l'invio | svuotamento / cancellazione presso il provider |

Il job di retention gira ogni notte, per tenant, e registra in `AuditLog` quante righe ha trattato per categoria.

### Anonimizzazione effettiva

Anonimizzare un ospite significa togliere i suoi dati da **tutte** le copie che controlliamo, non solo dalla tabella `guests`:

| Copia | Trattamento |
|---|---|
| `guests` | nome, cognome, email, telefono, note sostituiti con valori neutri; `anonymized_at` valorizzato; paese mantenuto |
| `reservations`, `quotes` | campi ospite copiati (es. `guest_name`, `guest_email` del preventivo) sostituiti |
| Testi liberi (`reservations.cancel_reason`, `reservation_changes.note`, `inventory_occupancies.note`, `inventory_conflicts.resolution_notes`, `refunds.reason`, `external_calendar_events.summary_redacted`) | sostituiti con `"[rimosso]"` (ADR-0009): possono contenere dati dell'ospite scritti dal gestore o arrivati dalle OTA |
| `audit_log` | non contiene mai valori personali (02): nessun intervento necessario, verificato da test |
| `outbox_messages` | payload già svuotati dopo 30 giorni; quelli più recenti dell'ospite vengono svuotati subito |
| Export generati (CSV, JSON, PDF) | scadono e vengono cancellati dopo 30 giorni; quelli ancora attivi che contengono l'ospite vengono cancellati |
| Provider email | richiesta di cancellazione o scadenza dei log secondo la retention del provider, documentata nell'elenco dei sub-responsabili |
| Stripe | fuori dal nostro controllo: i dati sono nel conto Stripe della struttura, che li gestisce come titolare |
| Backup | non modificati; si cancellano con la rotazione naturale (massimo 12 mesi). In caso di ripristino, il job di anonimizzazione viene rieseguito prima di riaprire il servizio |

Test (I22): dopo `erase`, nessuna colonna marcata `#[PersonalData]`, nessun payload outbox e nessun export attivo contiene i valori originali.

## Diritti degli interessati

Gli ospiti esercitano i diritti presso la struttura (titolare). La piattaforma assiste la struttura (art. 28):

- **Accesso / portabilità**: `GET /admin/guests/{id}/export` → JSON e PDF con dati personali, prenotazioni e pagamenti dell'ospite (il PDF dalla versione stampabile del pannello, ADR-0014 punto 13).
- **Cancellazione**: `POST /admin/guests/{id}/erase` → anonimizzazione immediata, salvo dati soggetti a conservazione obbligatoria (gli importi restano, i dati identificativi dove possibile no). Bloccata su prenotazioni future attive. Con ri-autenticazione (ADR-0014 punti 14–16).
- **Rettifica**: modifica dal pannello, tracciata in `AuditLog`.
- Se un ospite scrive direttamente alla piattaforma: inoltro alla struttura entro 2 giorni lavorativi, senza rispondere nel merito.

Tempi di supporto della piattaforma alla struttura: entro 10 giorni, per consentire alla struttura di rispettare il termine di un mese.

## Sub-responsabili (iniziali)

| Fornitore | Servizio | Dati | Ubicazione |
|---|---|---|---|
| Provider di hosting UE | server, database, backup | tutti | UE |
| Provider storage S3-compatibile | foto, export, backup | foto, export | UE |
| Resend (o equivalente) | email transazionali | email, nome, contenuto email | da verificare: se extra-UE, clausole contrattuali standard e valutazione del trasferimento |
| Sentry (o equivalente) | monitoraggio errori | ID tecnici, nessun dato ospite (scrubbing attivo) | preferire regione UE |
| Stripe | pagamenti | gestito come rapporto diretto Stripe–struttura | secondo termini Stripe |

Fase 3: Channex (dati di prenotazione dalle OTA). Fase 4: Chekin o fornitore adempimenti. Ogni nuovo sub-responsabile richiede aggiornamento dell'elenco, del registro e preavviso ai clienti.

## Data breach

1. Rilevamento (allerta, segnalazione, audit) → apertura incidente P1 (08).
2. Contenimento entro le prime ore; preservazione delle evidenze.
3. Valutazione: dati coinvolti, tenant e ospiti interessati, rischio.
4. **Notifica a ogni struttura interessata senza ingiustificato ritardo**, con le informazioni necessarie perché la struttura (titolare) valuti la notifica al Garante entro 72 ore e la comunicazione agli ospiti.
5. Per i dati di cui la piattaforma è titolare (gestori, utenti): valutazione e notifica al Garante entro 72 ore quando dovuta.
6. Registro interno delle violazioni, anche quelle non notificate.
7. Post-mortem con azioni correttive e test.

## Uscita del cliente

La disattivazione dei calendari esportati è la parte delicata: se un feed sparisce o diventa vuoto, un'OTA può mostrare libere date già vendute. La procedura tecnica dei feed è in 06; qui gli aspetti contrattuali e di dati.

1. **Avvio** (`Tenant.status = OFFBOARDING`): pagina ospite disattivata, nessuna nuova prenotazione; le prenotazioni esistenti restano gestibili dal pannello in sola lettura, salvo cancellazioni e rimborsi; i feed iCal passano a `FROZEN` (continuano a servire le occupazioni esistenti, mai un calendario vuoto).
2. **Export completo** (`POST /admin/tenant/export`): prenotazioni, ospiti, pagamenti, rimborsi, preventivi, tariffe, con l'elenco delle **prenotazioni future** da riportare nel nuovo sistema; scaricabile per 30 giorni.
3. **Disconnessione guidata dei canali**: per ogni unità e OTA il pannello indica quale feed rimuovere e mostra l'ultima lettura dell'OTA. Il gestore conferma la rimozione per ciascuna OTA.
4. **Ritiro dei feed** (`RETIRED`, risposta `410`): solo dopo la conferma del gestore, oppure dopo 30 giorni se l'OTA non legge il feed da almeno 7 giorni. Se un'OTA continua a leggerlo, il feed resta `FROZEN` e si contatta il gestore.
5. **Revoca degli altri collegamenti**: calendari importati disattivati; l'account Stripe resta della struttura, la piattaforma revoca solo il proprio accesso.
6. **Cancellazione o restituzione dei dati secondo il DPA**: dopo il ritiro dei feed e almeno 30 giorni dall'export, o su istruzione scritta; restano solo i dati che la piattaforma deve conservare per obblighi propri (fatturazione SaaS). I backup scadono con la rotazione naturale.
7. **Chiusura**: conferma scritta al cliente; `Tenant.status = CLOSED`.

## Privacy by design nel codice

- Nessun dato personale dell'ospite in URL, log, nomi di file, export ICS o metadata Stripe (solo ID interni).
- Campi personali raggruppati e marcati nel modello (es. attributo `#[PersonalData]`) per alimentare export, anonimizzazione e test.
- Test: vedi I22 e "Anonimizzazione effettiva".
- Ambiente di staging senza dati reali.
- Cookie della pagina ospite: solo tecnici, nessun analytics nella 1.0, quindi nessun banner di consenso necessario per l'analytics (da confermare legalmente).
