# Procedura di attivazione di un gestore pilota

> **BOZZA (6.8): da rivedere con Giuseppe** prima del primo pilota. Scritta da Claude Code il 07/11/2026 sul codice di `m6-rilascio`. I passi con ☐ sono da spuntare per ogni pilota. I punti marcati **[6.4]** dipendono dagli ambienti di staging e produzione, che non esistono ancora.

Chi la usa: la piattaforma (Alessio, con Giuseppe per il rapporto con il gestore). Il gestore segue la [guida rapida](guida-rapida-gestori.md).

## 0. Prima di iniziare (una volta per ambiente)

- ☐ **Fase 0 con esito positivo** (gate pilota, ADR-0007): senza, questa procedura non si esegue.
- ☐ Produzione attiva e verificata **[6.4]**: configurazione obbligatoria del rapporto [M6-revisione-sicurezza](../reports/M6-revisione-sicurezza.md) (in particolare `APP_DEBUG=false`, `TRUSTED_PROXIES`, chiavi Stripe live, `MAIL_MAILER=resend`, `ALERT_EMAILS`); backup giornaliero attivo e un ripristino di prova riuscito (AC12).
- ☐ Testi legali pubblicati e collegati: informativa della piattaforma (`PLATFORM_PRIVACY_NOTICE_URL`), elenco dei sub-responsabili (`PLATFORM_SUBPROCESSORS_URL`), modello di DPA e condizioni di servizio, con le fasce di supporto (08 "Gestione incidenti", 09).
- ☐ Criteri di accettazione verdi su staging (6.6) e verifica dei calendari reali di Booking e Airbnb (6.9).

## 1. Raccolta dei dati dal gestore (con Giuseppe)

Da raccogliere prima dell'attivazione, in un documento condiviso col gestore. Niente dati degli ospiti.

- ☐ Ragione sociale o nome, email del proprietario dell'account (sarà il suo accesso al pannello), telefono.
- ☐ Per ogni struttura: indirizzo, codice CIN, email e telefono di contatto per gli ospiti, orari di check-in e check-out.
- ☐ Unità: nome, ospiti inclusi e massimi, notti minime, pulizie finali, supplemento per ospite in più, foto (JPEG, PNG o WebP fino a 10 MB).
- ☐ Prezzi: prezzo base a notte e stagioni (date, prezzo, notti minime).
- ☐ Politica di cancellazione (Flessibile 7 giorni o Non rimborsabile), pagamento online (caparra del 30% o intero importo), modalità (immediata o su richiesta).
- ☐ Indirizzi https dell'**informativa privacy della struttura** e, se ci sono, delle condizioni di prenotazione: sono documenti del gestore, che è titolare del trattamento (09).
- ☐ Percentuale di commissione OTA abituale della struttura (per le metriche; predefinita 15%).
- ☐ Calendari: per ogni unità presente su Booking o Airbnb, l'indirizzo iCal di export dell'OTA.
- ☐ Siti del gestore dove incorporerà la pagina (vista embed), se ne ha.

## 2. Contratto e DPA

- ☐ Condizioni di servizio firmate.
- ☐ **DPA firmato** (09 requisito 1, "prima dell'attivazione"). Senza DPA registrato il pannello mostra un avviso e la pubblicazione è bloccata (`DPA_NOT_SIGNED`).

## 3. Creazione dell'account (piattaforma, sul server di produzione)

```
php artisan tenant:create <slug> "<Nome del gestore>" <email-del-proprietario>
php artisan tenant:dpa <slug> <versione-del-dpa> --signed-at=<AAAA-MM-GG>
```

- Lo slug compare negli indirizzi pubblici (`/p/<slug>/<struttura>`): minuscole, cifre e trattini, non si cambia.
- `tenant:create` chiede due volte la password iniziale, senza mostrarla. Si comunica al gestore per telefono, mai per email o chat insieme all'indirizzo di accesso.
- La versione del DPA è quella del documento firmato (per esempio `2027-01`). La data non può essere futura.
- ☐ Registrare nel documento del pilota la data di creazione e la versione del DPA.
- ☐ Domini della vista embed, se il gestore la usa (solo https, senza percorso; ogni modifica va in audit):

  ```
  php artisan tenant:embed-origins <slug> --add=https://www.sito-del-gestore.it
  php artisan tenant:embed-origins <slug> --list
  php artisan tenant:embed-origins <slug> --remove=https://vecchio-sito.it
  ```

## 4. Primo accesso del gestore (in videochiamata, consigliato)

- ☐ Il gestore apre `/app`, accede con email e password, configura la MFA con un'app di autenticazione (Google Authenticator, 1Password e simili) e **salva i 10 codici di recupero** in un posto sicuro.
- ☐ Verifica che il pannello mostri il nome del gestore e nessun avviso DPA.
- ☐ Il gestore **cambia la password iniziale** ("Account" → "Cambia password") e prova "Password dimenticata?" solo se vuole verificare la ricezione delle email.

## 5. Configurazione (il gestore, con l'aiuto della piattaforma)

Segue la [guida rapida](guida-rapida-gestori.md), sezioni 2–4:

- ☐ Struttura: dati, testi in italiano (obbligatori) e in inglese, politica predefinita, informativa privacy.
- ☐ Unità, tariffe, stagioni, foto; controllo dell'anteprima del prezzo su due o tre soggiorni noti.
- ☐ **Stripe**: "Collega Stripe" dal pannello (password richiesta) e onboarding completato dal gestore sul sito di Stripe. Lo stato deve diventare "Collegato: pagamenti attivi". Se il link scade se ne apre uno nuovo dal pannello.
- ☐ Pubblicazione: il pulsante "Pubblica" elenca cosa manca (unità attive, testi italiani, politica, informativa, DPA, pagamenti).

## 6. Calendari OTA (sezione "Calendari OTA")

Per ogni unità presente sulle OTA:

- ☐ Le unità con calendari esterni devono essere **su richiesta**: il pannello lo chiede alla prima importazione (I12).
- ☐ Importare il calendario di export di ogni OTA; attendere "Ultima sincronizzazione riuscita" e controllare che le date occupate coincidano con quelle dell'OTA.
- ☐ Creare il **feed dedicato** per ogni OTA e incollarne l'indirizzo nell'OTA giusta: il feed per Airbnb va su Airbnb, quello per Booking su Booking. Mai un feed su due OTA.
- ☐ Rimuovere i collegamenti iCal diretti tra Booking e Airbnb, se c'erano.
- ☐ La checklist di collegamento deve risultare completa. Entro 24 ore ogni feed deve mostrare "ultima lettura dell'OTA"; altrimenti il pannello avvisa.
- ☐ Aprire la pagina "Conflitti": eventuali sovrapposizioni preesistenti vanno risolte con il gestore prima di aprire le vendite.

## 7. Collaudo prima di aprire le vendite

Su una data lontana, con una carta reale del gestore o della piattaforma e importo minimo:

- ☐ Prenotazione dalla pagina ospite (IT ed EN), pagamento, email di conferma all'ospite e al gestore.
- ☐ La data compare nel feed per l'altra OTA entro il ciclo di lettura dell'OTA.
- ☐ Cancellazione dal pannello con rimborso: rimborso "in corso", poi "completato", email all'ospite.
- ☐ Per le unità su richiesta: richiesta, approvazione dal pannello, incasso e conferma.
- ☐ Ripulire: prenotazioni di collaudo cancellate e rimborsate.

## 8. Apertura e prime due settimane

- ☐ Condividere col gestore il link della pagina (`/p/<slug>/<struttura>`) e, se serve, il codice di incorporamento.
- ☐ Primo giorno: controllare le allerte operative (email `ALERT_EMAILS`) e lo stato dei calendari.
- ☐ Ogni giorno, per due settimane: richieste in attesa (scadenza 12 ore), conflitti aperti, calendari non sincronizzati, email non partite (allerta "coda in ritardo").
- ☐ Dopo due settimane: chiamata di verifica con il gestore e note per 7.3 (correzioni dal pilota).

## Se qualcosa va storto

- **Doppia prenotazione reale, pagamento senza prenotazione, accesso tra tenant**: incidente P1 (08). Sospendere le vendite dell'unità dal pannello ("Sospendi le vendite"), avvisare il gestore, poi l'analisi.
- **Calendario non sincronizzato da oltre 3 ore**: le date già importate restano bloccate; controllare l'indirizzo con il gestore.
- **Il gestore perde la MFA**: usa un codice di recupero. Senza codici: [azzeramento della MFA](azzeramento-mfa.md), con verifica dell'identità per telefono prima di `php artisan user:reset-mfa`.

## Punti aperti di questa procedura

1. Accesso di supporto al tenant (08): attività 7.5 di M7. Fino ad allora si assiste il gestore in condivisione dello schermo.
2. Testi legali, fasce di supporto e prezzi dell'abbonamento: da definire prima della firma.
3. Hosting: proposta in [ADR-0015](../adr/0015-hosting-railway-eu.md) (Railway, regione EU West), in attesa delle verifiche.
