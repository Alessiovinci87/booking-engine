# Glossario dei testi

Riferimento per i testi della pagina ospite (italiano e inglese) e del pannello (italiano), introdotto con la revisione dei testi di M8 (attività 8.6, ADR-0017). Lo scopo: **un concetto, una parola**, ovunque. Chi aggiunge o cambia un testo in `resources/js/{guest,panel}/locales/*.json` usa le parole di questa tabella. Se un concetto manca, lo aggiunge qui nello stesso commit.

Chi legge i testi:

- il **gestore** non è un tecnico. Niente gergo informatico (feed, export, token, slug, webhook, API). Se un termine tecnico serve davvero, va spiegato una volta tra parentesi, come "Indirizzo della pagina (slug)".
- l'**ospite** può essere straniero e leggere in inglese. Frasi brevi, una sola informazione per frase, nessun riferimento al funzionamento interno.

## Parole scelte

| Concetto | Pannello (it) | Pagina ospite (it) | Pagina ospite (en) | Da non usare |
|---|---|---|---|---|
| Chi gestisce la struttura | gestore (solo nei testi interni, per esempio "Inserita dal gestore") | la struttura | the property | host, proprietario, il gestore (verso l'ospite) |
| Edificio o attività ricettiva | struttura | struttura | property | proprietà |
| Ciò che si prenota | unità | alloggio | accommodation | camera, appartamento (salvo nei nomi scelti dal gestore) |
| La pagina pubblica | pagina di prenotazione | — | — | pagina ospite (nei testi visibili) |
| Portali esterni (Booking.com, Airbnb…) | OTA (con gli esempi alla prima occorrenza della pagina) | — | — | portale, canale (salvo "Altro canale" come origine) |
| Booking.com | Booking.com | — | — | Booking |
| Calendario che arriva da un'OTA | calendario importato | — | — | calendario di export, feed esterno |
| Calendario che pubblichiamo per un'OTA | calendario esportato | — | — | feed, feed iCal, feed dedicato |
| Lettura periodica di un calendario importato | sincronizzazione, sincronizzare | — | — | sync, aggiornamento |
| Esportazione di tutti i dati del gestore | esportazione dei dati | — | — | export |
| File di prenotazioni per il gestionale | CSV prenotazioni | — | — | export CSV |
| Prenotazione su richiesta | su richiesta | su richiesta | on request | — |
| Decisione sulla richiesta | approvare / rifiutare | approvare (la struttura approva) | approve | accettare, confermare (per la richiesta) |
| Stato finale positivo della prenotazione | confermata | confermata | confirmed | — |
| Fine anticipata di una prenotazione | cancellare, cancellata | cancellata | cancelled | annullare (per le prenotazioni) |
| Interruzione di un pagamento o di un'autorizzazione | annullare, annullata | annullare | cancel | cancellare (per i pagamenti) |
| Importo bloccato sulla carta | autorizzare, importo autorizzato | autorizzare | authorise (inglese britannico) | prenotare l'importo, pre-addebito |
| Prelievo dell'importo autorizzato | incassare, incasso | addebitare | charge | catturare, capture |
| Quota iniziale | caparra (pannello e importi) | da pagare ora | to pay now | acconto, anticipo |
| Resto del prezzo | saldo | saldo | balance | — |
| Restituzione di denaro | rimborso, rimborsare | rimborso | refund | storno |
| Esito negativo di un'operazione | non riuscito / non riuscita | non riuscito | failed | fallito |
| Proposta di prezzo con link | preventivo | preventivo | quote | offerta |
| L'ospite sceglie di pagare un preventivo; presa visione delle condizioni | accettare, accettato | accettare | accept | approvare (riservato alle richieste) |
| Ritiro di un preventivo | ritirare, ritirato | ritirato | withdrawn | revocare, annullare |
| Date bloccate a mano dal gestore | blocco, blocco manuale | — | — | chiusura |
| Date occupate da due fonti | conflitto | — | — | sovrapposizione |
| Arrivo e partenza (giorni) | check-in / check-out (campi delle date), soggiorno | arrivo / partenza | arrival / departure | — |
| Orari di arrivo e partenza | check-in dalle / check-out entro le | check-in dalle / check-out entro le | check-in from / check-out by | — |
| Addebito delle pulizie | pulizia finale | pulizia finale | final cleaning | pulizie finali |
| Supplemento per ospiti oltre quelli inclusi | ospite in più, supplemento per ospite in più | ospite in più | extra guest | — |
| Documento privacy | informativa privacy | informativa privacy | privacy policy | privacy policy (in italiano) |
| Condizioni della struttura | condizioni | condizioni di prenotazione | booking terms | termini |
| Accordo con la piattaforma | accordo sul trattamento dei dati (DPA) | — | — | — |
| Seconda verifica all'accesso | verifica in due passaggi | — | — | MFA, 2FA, TOTP |
| Codici monouso di emergenza | codici di recupero | — | — | codici di backup |
| Accesso temporaneo della piattaforma | accesso di supporto | — | — | impersonificazione |
| Lasciare la piattaforma | uscita dalla piattaforma | — | — | offboarding, disdetta |
| Link inviato per email | link | link | link | collegamento (si usa solo per il collegamento tra calendari o con Stripe) |
| Servizio di pagamento | Stripe (per nome) | servizio di pagamento, Stripe (nella nota sul pagamento sicuro) | payment provider, Stripe | — |

## Regole di scrittura

- **Apostrofi diritti** (`'`) in tutti i file di traduzione: niente apostrofi tipografici misti.
- **Date e importi** non si scrivono nei testi: arrivano già formattati nei segnaposto (`{{date}}`, `{{amount}}`).
- **Messaggi di errore**: dicono cosa è successo e cosa fare ("Ricarica la pagina e riprova", "Contatta la struttura"). Mai codici, nomi di campo o parole come "richiesta HTTP". Ogni codice di 07 ha un messaggio in `lang/{it,en}/api.php`, nel pannello e, se l'ospite può riceverlo, nella pagina ospite: il test `SpaRoutesTest` lo controlla.
- **Conferme**: le azioni irreversibili chiedono conferma con una domanda ("Ritirare il preventivo?") e spiegano la conseguenza in una frase. Il pulsante di conferma ripete l'azione ("Ritira"), non un generico "OK".
- **Pulsanti**: verbo all'imperativo, al massimo quattro parole ("Crea la prenotazione", "Copia link").
- **Inglese**: britannico (authorise, cancelled), tono cortese con "Please" nei messaggi che chiedono un'azione.
