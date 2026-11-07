# Guida rapida per i gestori

> **BOZZA (6.8): da rivedere con Giuseppe**, soprattutto il linguaggio e gli esempi, prima di consegnarla al primo pilota. Scritta da Claude Code il 07/11/2026 sul pannello di `m6-rilascio`. Le parole tra virgolette sono quelle che vedi sui pulsanti e nei menu.

Il pannello è all'indirizzo che ti abbiamo comunicato, seguito da `/app`. Funziona dal computer e dal telefono.

## 1. Primo accesso e sicurezza

1. Accedi con la tua email e la password che ti abbiamo dato per telefono.
2. Configura la verifica in due passaggi: inquadra il QR con un'app di autenticazione (Google Authenticator, Microsoft Authenticator, 1Password…) e inserisci il codice a 6 cifre.
3. **Salva i 10 codici di recupero** (stampali o tienili in un gestore di password). Ti servono se perdi il telefono. Ogni codice vale una volta.
4. Da quel momento, a ogni accesso, ti chiediamo password e codice dell'app. Dopo 2 ore di inattività, o 12 ore in totale, si rientra.
5. Per le operazioni delicate (rimborsi, collegamento a Stripe, download dei dati degli ospiti, anonimizzazione) il pannello ti chiede di nuovo la password. La conferma vale 15 minuti.

## 2. La struttura

Menu "Strutture" → la tua struttura.

- **Dati della struttura**: indirizzo, codice CIN, email e telefono per gli ospiti, orari di check-in e check-out.
- **Testi** in italiano (obbligatori) e in inglese: nome, descrizione, regole della casa.
- **Informativa privacy** (obbligatoria) e condizioni di prenotazione: sono documenti tuoi. Inserisci il link https. Se cambi il documento, cambia anche il link (per esempio aggiungendo la data): così sappiamo quale versione ha accettato ogni ospite.
- **Politica di cancellazione predefinita**: "Flessibile (7 giorni)" (rimborso totale fino a 7 giorni prima dell'arrivo) oppure "Non rimborsabile".
- **Tempo per rispondere alle richieste** (predefinito 12 ore).
- **Commissione OTA abituale**: serve solo alle metriche, per stimare quanto risparmi con le prenotazioni dirette.

## 3. Unità, prezzi e foto

"Nuova unità" dalla pagina della struttura, poi apri l'unità.

- **Ospiti inclusi** nel prezzo e **ospiti massimi**; oltre gli inclusi si applica il supplemento per ospite (bambini compresi).
- **Notti minime**, **pulizie finali**.
- **Modalità**: "Immediata" (l'ospite paga e la prenotazione è confermata) o "Su richiesta" (la carta viene solo autorizzata e decidi tu). Se colleghi i calendari di Booking o Airbnb, l'unità deve essere "Su richiesta".
- **Pagamento online**: caparra del 30% (il saldo lo incassi tu, come preferisci) oppure intero importo.
- **Tariffe**: prezzo base a notte. **Stagioni**: prezzo diverso in un periodo (prima e ultima notte comprese). Se due stagioni si sovrappongono vince quella con priorità più alta.
- **Anteprima prezzo**: prova sempre due o tre soggiorni e controlla che il totale sia quello che ti aspetti.
- **Foto**: fino a 10 MB, la prima è la copertina. Posizione e dati della fotocamera vengono rimossi.

## 4. Stripe e pubblicazione

- Menu "Pagamenti" → "Collega Stripe". Si apre il sito di Stripe, dove inserisci i dati dell'attività e l'IBAN. Gli incassi arrivano sul **tuo** conto Stripe: noi non tocchiamo i soldi degli ospiti.
- Quando lo stato è "Collegato: pagamenti attivi" puoi pubblicare.
- "Pubblica" ti dice cosa manca, se manca qualcosa. Dopo la pubblicazione la pagina per gli ospiti è attiva: condividi il link sul sito, su WhatsApp, su Instagram.

## 5. Collegare Booking e Airbnb (menu "Calendari OTA")

Per ogni unità servono **due collegamenti per ogni OTA**:

1. **Calendario importato**: copia dall'OTA l'indirizzo del suo calendario (iCal, "esporta calendario") e incollalo qui. Lo leggiamo ogni 10 minuti circa: le date occupate su Booking o Airbnb non si possono prenotare qui.
2. **Feed esportato**: "Crea il feed per Airbnb", poi "Copia indirizzo" e incollalo **su Airbnb** ("importa calendario"). Fai lo stesso con il feed per Booking, **su Booking**. Ogni OTA ha il suo feed: non usare lo stesso feed su due OTA.
3. Togli i collegamenti diretti tra Booking e Airbnb, se li avevi.
4. Segui la **checklist di collegamento** finché non risulta completa.

Da sapere:

- Booking e Airbnb leggono i calendari quando vogliono (spesso ogni ora o più). Per qualche decina di minuti una data appena prenotata qui può risultare ancora libera sulle OTA. È un limite dei calendari iCal, non un errore.
- Se un calendario non si legge da oltre un'ora il pannello lo segnala; dopo 3 ore ricevi un'email. Le date già importate restano comunque bloccate.
- Se un'OTA non legge il tuo feed da oltre 24 ore, il pannello te lo segnala: controlla che sia collegato.

## 6. Ogni giorno

**Richieste da approvare** (unità su richiesta). Ricevi un'email per ogni richiesta. Apri "Prenotazioni", poi la richiesta:

- prima di approvare **controlla Booking e Airbnb**: la data potrebbe essere stata appena venduta lì;
- "Approva": incassiamo l'importo autorizzato e la prenotazione diventa confermata;
- "Rifiuta": l'autorizzazione sulla carta viene annullata, le date si liberano e l'ospite riceve un'email;
- se non rispondi entro il tempo previsto la richiesta scade da sola, come un rifiuto.

**Conflitti** (menu "Conflitti"): quando una data arriva occupata da due fonti diverse (per esempio una prenotazione diretta e una di Booking sulle stesse notti). Le date restano bloccate e **nessuna prenotazione viene cancellata in automatico**. Che cosa fare:

1. "Prendi in carico";
2. decidi con gli ospiti: annulli una delle due prenotazioni nel suo canale (qui o sull'OTA) oppure sposti l'ospite su un altro alloggio;
3. "Risolvi", scegli l'esito, "Chiudi come risolto". Nelle note non scrivere dati degli ospiti.

Se il pannello indica "possibile eco", la data che vedi è probabilmente una nostra data rimandata indietro dall'OTA. Se invece è una prenotazione vera, premi "Non è un'eco".

## 7. Prenotazioni, pagamenti e cancellazioni

- **Prenotazione manuale** (telefono, WhatsApp): "Prenotazioni" → "Nuova prenotazione manuale". È confermata subito, anche senza pagamento.
- **Pagamenti fuori piattaforma** (bonifico, contanti, POS): nella prenotazione, "Registra pagamento offline".
- **Modifica di date e ospiti**: "Modifica date e ospiti". Il prezzo si ricalcola con le tariffe attuali e vedi il confronto prima di confermare.
- **Cancellazione**: "Cancella la prenotazione". Il pannello propone il rimborso previsto dalla politica e puoi cambiarlo. Il rimborso parte subito, ma risulta "in corso" finché Stripe non lo completa; l'ospite riceve l'email "rimborso completato" solo a rimborso eseguito.
- **Rimborso non riuscito**: dopo tre tentativi ti avvisiamo per email. Puoi riprovare o, se hai rimborsato in altro modo, usare "Segna come rimborsato".
- **Incasso non riuscito** (una richiesta approvata ma la carta rifiuta l'addebito): le date restano bloccate. Usa "Invia link di pagamento" per mandare all'ospite un nuovo link, oppure "Libera le date". Se non decidi, le date si liberano da sole dopo 24 ore.

## 8. Preventivi

Menu "Preventivi" → "Nuovo preventivo": unità, date, ospiti ed eventuale sconto. Il prezzo resta quello del preventivo anche se poi cambi le tariffe.

- "Copia link" o "Apri WhatsApp" per mandarlo all'ospite; l'ospite trova i dati già compilati e paga online.
- Scade dopo 48 ore se non indichi un'altra scadenza. Un preventivo scaduto o ritirato non si può più pagare.

## 9. Richieste degli ospiti sui loro dati

Per i dati dei tuoi ospiti il titolare del trattamento sei tu: le richieste arrivano a te. Dalla prenotazione, sezione "Privacy dell'ospite":

- "Scarica i dati (JSON)" o "Versione stampabile (PDF)" per una richiesta di accesso;
- "Anonimizza l'ospite" per una richiesta di cancellazione. È irreversibile: nomi, email, telefono e note vengono sostituiti; importi e date restano per gli obblighi contabili. Non si può fare se l'ospite ha un soggiorno in corso o futuro.

Il "CSV prenotazioni" delle metriche contiene i dati degli ospiti: conservalo con cura. Non è il file per Alloggiati Web.

## 10. Metriche

Menu "Metriche": fatturato diretto del periodo, commissioni OTA che avresti pagato con la tua percentuale abituale e **beneficio diretto stimato** (commissioni evitate meno commissioni di pagamento e sconti). È una stima: presuppone che ogni prenotazione diretta sarebbe altrimenti arrivata da un'OTA.

## Domande frequenti

- **Ho perso il telefono con l'app di autenticazione.** Accedi con uno dei codici di recupero e configura il nuovo telefono. Se non hai più i codici, contattaci.
- **Un ospite dice di aver pagato ma la prenotazione non è confermata.** La conferma arriva da Stripe, a volte con qualche minuto di ritardo. Se dopo 15 minuti non è cambiato nulla, contattaci con il codice della prenotazione (8 caratteri), senza altri dati dell'ospite.
- **Voglio fermare le vendite di un alloggio per qualche giorno.** Blocca le date dal "Calendario" oppure, dall'unità, "Sospendi le vendite".
