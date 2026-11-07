# Rapporto M6 â€” Comunicazioni, privacy e rilascio del pilota (parziale)

Data: 07/11/2026 Â· Ramo: `m6-rilascio` (da `m5-calendari-preventivi`) Â· PR verso `m5-calendari-preventivi`, senza merge Â· Autore: Claude Code

## Esito

**Non completata (parziale, come concordato).** Sono state svolte solo le attivitÃ  che non richiedono account esterni: 6.1, 6.2, 6.3, 6.5, 6.7, 6.10 e 6.11 sono chiuse; 6.6 e 6.8 sono fatte per la parte possibile senza staging e senza Giuseppe e restano aperte; 6.4 e 6.9 non sono iniziate.

I criteri di uscita di M6 non sono raggiungibili senza 6.4: criteri di accettazione verdi su staging, backup ripristinato, prima struttura pilota in produzione.

Nota sul gate: dopo la verifica parziale, ADR-0007 Ã¨ aggiornata. L'esito positivo della Fase 0 serve per **attivare i piloti** (procedura di 6.8), non per proseguire lo sviluppo; 11-MILESTONES Ã¨ alla versione 1.3.

### Decisioni della verifica parziale (07/11/2026)

Nessun merge: M4, M5 e M6 restano in attesa delle prove con Stripe reale.

1. **6.10 "Strumenti di piattaforma per il pilota"** (#74): svolta e chiusa (`tenant:embed-origins`, `user:reset-mfa`, procedura `docs/guide/azzeramento-mfa.md`).
2. **7.5 "Accesso di supporto al tenant in sola lettura"** (#75) aggiunta a M7: motivazione obbligatoria, massimo 2 ore, audit visibile al gestore.
3. **ADR-0014 accettata**; i punti 19 (allerte via email) e 22â€“24 (E2E solo in locale) restano provvisori fino a 6.4.
4. **ADR-0007 aggiornata**: il gate della Fase 0 vale per l'attivazione dei piloti; 11-MILESTONES 1.3 aggiornato di conseguenza.
5. **ADR-0015 "Hosting su Railway, regione EU West"** in stato Proposta. Aggiornata con il ripristino a un istante (PITR) di Railway, attivato alla creazione del database di produzione, piÃ¹ il dump giornaliero cifrato presso un altro fornitore europeo; resta da verificare il piano Railway necessario per il PITR. Contiene servizi, ambienti separati, backup con ripristino a un istante e dump cifrato presso un altro fornitore europeo, `TRUSTED_PROXIES` e log, piÃ¹ l'elenco delle verifiche da fare prima. Forge resta l'alternativa. Ãˆ in attesa della conferma del professionista sui log conservati negli USA. Nessun account creato.

### Verifica di 6.11 (07/11/2026)

Approvata con modifiche, nessun merge:

1. **ADR-0016 accettata.**
2. **Conservazione dei link di reimpostazione** usati o scaduti: categoria H di `RetentionPolicy`, 30 giorni, cancellazione (09 alla versione 1.4). La cancellazione la esegue il job di 7.2, ora indicata nella issue #63 e in 11.
3. **`sessions_revoked_at` al microsecondo**: la colonna era `timestamptz(0)` e arrotondava al secondo. Ora Ã¨ `timestamptz(6)`, e la sessione registra l'inizio in microsecondi. Una sessione aperta subito dopo una revoca, anche nello stesso secondo, resta aperta; una aperta subito prima si chiude. Tolta la nota sui casi limite dall'ADR; test aggiunti.
4. **E2E `tests/E2E/m6-password.spec.ts`**, senza accesso diretto al database:
   - password dimenticata â†’ link letto dalla casella `/dev/mail` â†’ nuova password â†’ il link non vale una seconda volta â†’ la vecchia password non vale â†’ il login con la nuova chiede ancora la verifica in due passaggi â†’ email di avviso;
   - cambio password da `/app/account` con due sessioni aperte: la corrente resta, l'altra torna all'accesso;
   - alla fine la password demo torna quella del global setup. Il global setup azzera i limiti di "password dimenticata" per l'email demo e 127.0.0.1, cosÃ¬ lo spec si puÃ² rilanciare.

## AttivitÃ 

| # | Stato | Issue | Note |
|---|---|---|---|
| 6.1 | Fatta | #54 (chiusa) | Email transazionali IT/EN tramite outbox con mailer astratto (`TransactionalMailer`). Driver `log` in locale e nei test con casella di anteprima `/dev/mail` (solo `APP_ENV=local`). Adattatore Resend senza chiave, attivo da configurazione e testato con client simulato. `reply_to` all'email della struttura; nuova email al gestore a ogni conferma online. ADR-0014 punti 1â€“6 |
| 6.2 | Fatta | #55 (chiusa) | `MetricsService` (fatturato diretto, commissioni OTA evitate, beneficio diretto stimato, dettaglio per struttura) e pagina `/app/metriche`. Export CSV con `;`, BOM e protezione dalle formule, registrato in audit. Commissioni di pagamento stimate finchÃ© `fee_cents` non viene popolato. ADR-0014 punti 7â€“12 |
| 6.3 | Fatta | #56 (chiusa) | Export JSON e versione stampabile dell'ospite. Anonimizzazione su tutte le copie (I22): ospite, testi liberi, preventivi, outbox, eventi esterni, rimborsi; con ri-autenticazione e blocco se il soggiorno Ã¨ attivo. DPA del tenant (`tenant:dpa`, guardia `DPA_NOT_SIGNED` alla pubblicazione); link all'informativa della piattaforma e ai sub-responsabili. ADR-0014 punti 13â€“18 |
| 6.4 | Non iniziata | #57 (aperta) | Staging e produzione, backup cifrati, Sentry, monitoraggio. Servono account e pagamenti (hosting, dominio, Sentry, Resend): regola "Quando ci si ferma" n. 6. Hosting proposto in ADR-0015 (Railway EU West, Proposta; alternativa Forge) |
| 6.5 | Fatta | #58 (chiusa) | Allerte operative di 08 nel log al loro livello, piÃ¹ email agli indirizzi della piattaforma (`ALERT_EMAILS`) con limite di una ogni 60 minuti. Nuovi controlli: coda in ritardo ogni 5 minuti, riepilogo giornaliero dei calendari `UNHEALTHY`. Push e Sentry restano per 6.4. ADR-0014 punti 19â€“21 |
| 6.6 | Parziale | #59 (aperta) | E2E in locale con il gateway finto: `tests/E2E/m6-acceptance.spec.ts` copre AC2â€“AC6, AC9, AC13, AC17 e I22 dal pannello, compresi `/app/calendari` e `/app/conflitti`. AC1 e AC8 erano giÃ  coperti da `m2-panel` e `m5-quote`. Il resto Ã¨ da ripetere su staging (tabella sotto). ADR-0014 punti 22â€“24 |
| 6.7 | Fatta (parte sul codice) | #60 (chiusa) | Revisione con la checklist di 08: [M6-revisione-sicurezza](M6-revisione-sicurezza.md). Cinque lacune corrette, ciascuna con test: ri-autenticazione sugli export, scadenza a 90 giorni del link dell'ospite, HSTS, `TRUSTED_PROXIES`, Dependabot. Il test di ripristino del backup (AC12, I10) Ã¨ passato a 6.4, come concordato. ADR-0014 punti 25â€“29 |
| 6.8 | Bozza | #61 (aperta) | [Procedura di attivazione dei piloti](../guide/attivazione-pilota.md) e [guida rapida per i gestori](../guide/guida-rapida-gestori.md), da rivedere con Giuseppe. Scrivendole Ã¨ emerso un campo mancante, "Commissione OTA abituale" nel modulo della struttura, che ho aggiunto |
| 6.10 | Fatta | #74 (chiusa) | `php artisan tenant:embed-origins {slug} --add/--remove/--list`: solo https con un dominio, senza percorso; audit. `php artisan user:reset-mfa {email} --reason=â€¦`: promemoria e conferma, cancella segreto e codici di recupero, chiude le sessioni (`users.sessions_revoked_at`), MFA da riconfigurare; audit con ID e motivazione. Procedura in `docs/guide/azzeramento-mfa.md`. Test: `Feature/Tenancy/PlatformToolsTest`. ADR-0014 punto 30 |
| 6.11 | Fatta | #76 (chiusa) | Cambio password nel pannello (`/app/account`: password attuale, MFA verificata, chiusura delle altre sessioni, la corrente resta aperta) e "password dimenticata" (risposta sempre `202`, link monouso di 60 minuti salvato come hash con il token nel frammento dell'indirizzo, 3 richieste/ora per email e 10 per IP, la reimpostazione non apre la sessione e la MFA resta obbligatoria). Audit ed email di avviso via outbox. Revoca delle sessioni al microsecondo (`timestamptz(6)`); link usati o scaduti in categoria H di conservazione (30 giorni, cancellati dal job di 7.2). Test: `Feature/Auth/PasswordManagementTest`, `Feature/Privacy/RetentionPolicyTest`, E2E `m6-password.spec.ts`. ADR-0016 (accettata) |
| 6.9 | Non iniziata | #72 (aperta) | Verifica dei calendari reali di Booking e Airbnb. Servono i risultati della Fase 0 (feed reali, etichette `SUMMARY`, frequenza di lettura, risposta a `503`), che non sono ancora arrivati. In 11 Ã¨ da fare prima di 6.6 su staging |

### PerchÃ© 6.4 e 6.9 restano aperte

- **6.4**: tutto ciÃ² che contiene richiede account esterni e costi ricorrenti: hosting in UE, dominio, Forge, Sentry, Resend con dominio verificato, storage dei backup in una seconda regione. 11 lo indica tra i prerequisiti esterni ("Claude Code si ferma e chiede"). Per renderla meccanica Ã¨ pronto il codice che non dipende dagli account:
  - adattatore Resend;
  - allerte via email;
  - `TRUSTED_PROXIES` e HSTS;
  - elenco della configurazione obbligatoria (in fondo al rapporto di sicurezza).
- **6.9**: dipende dai risultati della Fase 0 sui feed reali. Senza quelli si rischia di adattare `EchoClassifier` e `IcsParser` a ipotesi invece che ai dati. Va fatta prima della serie E2E su staging.

### Passi E2E "da ripetere su staging"

| Criterio | In locale (`m6-acceptance` e spec precedenti) | Da ripetere su staging |
|---|---|---|
| AC1 | `m2-panel` (configurazione, anteprima golden, pubblicazione) | Stesso flusso sul dominio vero |
| AC2 | Feed con UID `occ-â€¦@` stabili tra due letture; pagamento con il gateway finto (`m3-guest-booking`) | Checkout Stripe in modalitÃ  test, webhook firmato, UID presente nei feed **dall'inizio del checkout** (`m4-stripe-checkout`) |
| AC3 | Approvazione dal pannello â†’ Confermata (gateway finto) | Pre-autorizzazione e capture reali su Stripe in modalitÃ  test |
| AC4 | Rifiuto â†’ date libere, email all'ospite in `/dev/mail` | Annullamento reale dell'autorizzazione su Stripe; email reale via Resend; scadenza a 12 ore |
| AC5 | Due `POST` simultanei â†’ 201 + 409 `DATES_UNAVAILABLE` | Stesso test contro staging (PostgreSQL e PHP-FPM reali, piÃ¹ worker) |
| AC6 | Conflitto dei calendari dimostrativi: presa in carico e chiusura in `/app/conflitti` | Import da un server iCal finto in **https**: `SafeHttpFetcher` rifiuta giustamente gli indirizzi locali, quindi l'import reale non Ã¨ provabile in locale. Email al gestore |
| AC7 | Solo test Feature (`Feature/Calendar/SyncUnhealthyTest`) | Calendario che non risponde per 3 ore: serve il server iCal finto e il passare del tempo |
| AC8 | `m5-quote` (sconto, accettazione, pagamento simulato) | Pagamento Stripe reale; preventivo scaduto non pagabile |
| AC9 | Cancellazione con rimborso, password, email in `/dev/mail` (gateway finto) | Rimborso reale "in corso" â†’ "completato" solo all'esito Stripe, con l'email in quel momento |
| AC10 | Solo test Feature (`WebhookIdempotencyTest`) | Webhook ripetuto da `stripe events resend` |
| AC11 | Test Feature e catalogo RLS | Prova con due tenant sul pannello di staging |
| AC12 | â€” | Ripristino del backup in ambiente isolato (6.4) |
| AC13 | Data importata da Booking presente nel feed per Airbnb e assente in quello per Booking; nessuna eco | Con il server iCal finto in https e le etichette reali della Fase 0 (6.9) |
| AC14 | Solo test Feature | Capture rifiutata con carta di test Stripe, link di recupero, rilascio a 24 ore |
| AC15 | Solo test Feature e `Stripe/*` | Sessione completata scoperta dal job prima del webhook (webhook ritardato) |
| AC16 | Solo test Feature (`QuoteConcurrencyTest`) | Due aperture contemporanee del preventivo nel browser |
| AC17 | Manuale senza pagamento ("Non pagata"), poi bonifico â†’ "Pagata" | Stesso flusso su staging |
| AC19 | Solo test Feature (`RefundRetryTest`) | Rimborso fallito tre volte con Stripe in modalitÃ  test, allerta, "Segna come rimborsato" |

## Test

- **Invarianti e criteri coperti**:
  - I22 Ã¨ ora coperto da `Feature/AnonymizationCoverageTest` e dall'E2E.
  - AC2â€“AC6, AC9, AC13 e AC17 sono coperti anche in E2E locale.
  - Tutti gli invarianti I1â€“I22 e i criteri AC1â€“AC19 restano coperti dai test indicati in `10-TRACEABILITY.md`; AC12 aspetta 6.4.
- **Risultati in locale** (07/11/2026):
  - `composer test`: 846 test verdi (5202 asserzioni), anche senza `public/build`, come in CI;
  - `composer lint`: verde;
  - `composer analyse`: nessun errore;
  - `npm run typecheck`: verde;
  - `npx playwright test m2-panel m3-guest m5-quote m6-acceptance m6-password`: 10 verdi, anche rilanciati di seguito;
  - `composer audit` e `npm audit`: nessuna vulnerabilitÃ .
- **CI**: le esecuzioni dei commit 6.3â€“6.8 erano rosse per un solo test (`PlatformPrivacyLinksTest`). In locale passava perchÃ© c'era la build di Vite, che in CI non esiste prima dei test. Ãˆ corretto nel commit `473ff8a`, e da lÃ¬ la CI Ã¨ verde (run 42367434173).
- **Test aggiunti**:
  - `Feature/Communications/TransactionalMailTest` e `Feature/Communications/OperationalAlertsTest`;
  - `Feature/Metrics/MetricsTest`;
  - `Feature/AnonymizationCoverageTest` e `Feature/Public/PlatformPrivacyLinksTest`;
  - casi DPA in `Feature/PropertyPublishTest`;
  - casi HSTS e proxy fidati in `Feature/SecurityHeadersTest`;
  - scadenza del link in `Feature/Public/PublicReservationApiTest`;
  - `REAUTH_REQUIRED` sugli export;
  - E2E `tests/E2E/m6-acceptance.spec.ts` e `tests/E2E/m6-password.spec.ts` (6.11);
  - `Feature/Tenancy/PlatformToolsTest` (6.10);
  - `Feature/Auth/PasswordManagementTest` e `Feature/Privacy/RetentionPolicyTest` (6.11).
- **Test modificati**:
  - `Feature/Reservations/ReservationTransitionsTest`: T3 genera anche `reservation.confirmed_manager`;
  - `Feature/Frontend/SpaRoutesTest`: rotte nuove e attributi `data-*` della pagina ospite;
  - `Arch/DomainStructureTest`: nuova area `Communications`;
  - `Arch/ExternalHttpTest`: `ResendTransport` nell'allowlist.

## Scostamenti dalla specifica

Tutti in [ADR-0014](../adr/0014-precisazioni-m6-comunicazioni-privacy.md), **accettata** il 07/11/2026; i punti 19 e 22â€“24 sono provvisori fino a 6.4. I principali:

- versione "PDF" dei dati dell'ospite prodotta dalla stampa del browser invece che dal server, per non aggiungere librerie (punto 13);
- ri-autenticazione anche per l'anonimizzazione (punto 16) e per gli export con dati personali (punto 25);
- DPA come guardia della pubblicazione (punto 17);
- allerte "push" sostituite da email alla piattaforma fino a 6.4 (punto 19);
- E2E di 6.6 in locale con il gateway finto invece che su staging, con la tabella dei passi da ripetere (punti 22â€“23);
- `TRUSTED_PROXIES` e HSTS dall'applicazione (punti 27â€“28).

Le specifiche toccate sono 03, 07, 08 e 09 (portata a v1.3), piÃ¹ la tracciabilitÃ .

## Scelte minori prese

- Nuova area di dominio `app/Domain/Communications`:
  - `TransactionalMailer`, `RecipientMask`, `MailPreviewBox` (file JSON in `storage`, al massimo 200 file e 30 giorni);
  - trasporti `PreviewLogTransport` e `ResendTransport`;
  - `Alerts/{AlertSignal, OperationalAlerts}`.
- Export CSV con un codificatore di righe proprio invece di `fopen('php://temp')`: il controllo architetturale sulle chiamate di rete e di file resta senza eccezioni.
- `ProtectedDownload` nel pannello: download con fetch e richiesta della password su `REAUTH_REQUIRED`.
- Scadenza del link dell'ospite controllata nel controller (`GUEST_LINK_DAYS_AFTER_CHECK_OUT = 90`) e non nella funzione SQL di lookup, cosÃ¬ segue l'orologio dell'applicazione anche nei test.
- E2E:
  - `tests/E2E/support/artisan.ts` esegue un giro dell'outbox (`schedule:test --name=outbox:dispatch`) per leggere le email in `/dev/mail`;
  - il setup globale azzera nella cache E2E il contatore del limite di frequenza per 127.0.0.1 e quelli di "password dimenticata" per l'email demo e 127.0.0.1.
- Campo "Commissione OTA abituale (%)" nel modulo della struttura, convertito in basis point.

## Problemi aperti e rischi per la milestone successiva

1. **6.4 bloccata dagli account esterni.** Prima si decide l'hosting: ADR-0015 propone Railway EU West, da verificare su un progetto di prova (Postgres 18, ruoli e funzioni `SECURITY DEFINER`, RLS forzata, ripristino a un istante, proxy, egress, DPA e log negli USA). Poi servono dominio, Sentry, Resend con dominio verificato e lo storage europeo dei backup. Senza questi passi M6 non chiude, e il margine verso il pilota di aprile si riduce.
2. **Fase 0 e 6.9.** Senza i feed reali non sappiamo se `EchoClassifier` e `IcsParser` reggono le etichette vere di Booking e Airbnb. Ãˆ il rischio tecnico maggiore per AC6, AC7 e AC13.
3. **Commissioni di pagamento stimate** (1,5% + 0,25 â‚¬) finchÃ© non si legge la commissione reale dalla balance transaction di Stripe. Da fare con i test Stripe reali (in sospeso anche per M4).
4. **Accesso di supporto al tenant** (08): pianificato come 7.5 in M7 (#75).
5. **Pulizia dei link di reimpostazione** (categoria H, 30 giorni): la durata Ã¨ configurata, ma la cancellazione arriva solo con il job di conservazione di 7.2 (#63). Fino ad allora le righe usate o scadute restano, giÃ  senza la copia cifrata del token.
6. **Testi legali** (informativa della piattaforma, sub-responsabili, DPA, condizioni di servizio): da redigere con il professionista. La pagina ospite mostra i link solo se configurati, e la checklist di rilascio li richiede.
7. **M4 ancora aperta su Stripe reale.** Restano i test `tests/Stripe/*` con `STRIPE_LIVE_TESTS=1`, l'E2E `m4-stripe-checkout`, l'accettazione di ADR-0012 e i merge di M4 e M5 su `main`, in attesa della conferma dell'onboarding Stripe (account `acct_1UZ4fZDvJleujh4m`).

## Ore impiegate (stima) rispetto al previsto

Circa 30 ore di lavoro equivalente per le nove attivitÃ  svolte:

| AttivitÃ  | Ore circa |
|---|---|
| 6.1 | 5 |
| 6.2 | 3 |
| 6.3 | 5 |
| 6.5 | 3 |
| 6.6 | 3 |
| 6.7 | 3 |
| 6.8 | 2 |
| 6.10 | 2 |
| 6.11 | 4 (compresa la verifica) |

PiÃ¹ circa 1 ora per ADR-0015 e gli aggiornamenti di ADR-0007, ADR-0014 e 11. Il previsto per M6 intera Ã¨ lo sprint del 1â€“19 febbraio 2027. Le attivitÃ  rimaste (6.4, la parte di 6.6 su staging, 6.9, la revisione di 6.8) dipendono da account e risultati esterni piÃ¹ che da ore di sviluppo. Nessuno sforamento della stima.
