# ADR-0015 — Hosting su Railway, regione EU West

- **Stato**: Proposta (in attesa della conferma del professionista sui log conservati negli USA e delle verifiche tecniche elencate sotto)
- **Data**: 2026-11-07
- **Autori**: Claude Code, su indicazione di Alessio
- **Documenti di specifica toccati**: 08-SECURITY-OPERATIONS ("Backup e ripristino", "Ambienti"), 09-PRIVACY-GDPR (sub-responsabili), 11-MILESTONES (6.4)

> Solo proposta: nessun account, progetto o servizio è stato creato.

## Contesto

L'attività 6.4 di 11 prevede staging e produzione con Forge in UE, Horizon, backup cifrati, Sentry e monitoraggio di disponibilità. Alessio propone Railway, regione EU West, per ridurre il lavoro di gestione dei server: niente sistema operativo da aggiornare, deploy da Git e servizi gestiti.

Requisiti da rispettare, da 08 e 09:
- PostgreSQL con `btree_gist`, i ruoli `app_migrator`, `app_runtime` (senza `BYPASSRLS`) e `app_admin_ops`, funzioni `SECURITY DEFINER` e RLS forzata;
- backup con ripristino a un istante (7 giorni), 30 giornalieri e 12 mensili, cifrati, con copia presso un altro fornitore o un account separato; ripristino di prova mensile (I10, AC12);
- RPO ≤ 15 minuti, RTO ≤ 4 ore;
- dati in UE; log senza dati personali; staging e produzione completamente separati.

## Decisione (proposta)

**Staging e produzione su Railway, regione EU West, in due progetti separati**: variabili, database, Redis e domini distinti. Lo stesso codice gira anche su Forge (vedi "Alternativa").

### Servizi per ambiente

| Servizio | Comando | Note |
|---|---|---|
| `web` | PHP-FPM con Nginx oppure `php artisan octane` (da decidere; preferibile FPM, già provato in locale) | Unico servizio con dominio pubblico |
| `worker` | `php artisan horizon` | Code su Redis; più repliche se serve |
| `scheduler` | `php artisan schedule:work` | Una sola replica: i job usano già `withoutOverlapping` |
| `postgres` | PostgreSQL 18 (immagine ufficiale o template Railway) con volume persistente | `btree_gist`; ruoli creati con gli stessi script di `docker/postgres/init` |
| `redis` | Redis 8 con persistenza AOF | Code, cache, sessioni, limiti di frequenza |
| `backup` (cron) | `pg_dump` cifrato ogni giorno | Vedi "Backup" |

- Migration nel passo di rilascio, con `app_migrator` (`php artisan migrate --database=pgsql_migrator --force`). L'applicazione si collega sempre come `app_runtime`, mai come superuser.
- Collegamento tra servizi sulla rete privata di Railway; il database non è esposto su Internet.
- `TRUSTED_PROXIES`: il traffico arriva solo dal proxy di Railway, che non ha indirizzi fissi documentati. Si usa `TRUSTED_PROXIES=*`, ammissibile **solo** se `web` non è raggiungibile in altro modo. Va verificato che Railway riscriva `X-Forwarded-For` e `X-Forwarded-Proto` e che il client non possa iniettarli.
- Variabili di ambiente come segreti di Railway; nessun segreto nel repository. Configurazione obbligatoria: elenco in `docs/reports/M6-revisione-sicurezza.md`.

### Backup

1. **Backup di Railway** del volume Postgres (pianificati, con conservazione) come primo livello.
2. **Ripristino a un istante (7 giorni, RPO ≤ 15 min)**: se Railway non lo offre in modo nativo, archiviazione continua del WAL (per esempio `wal-g` o `pgBackRest`) verso lo storage europeo del punto 3.
3. **Dump giornaliero cifrato** (`pg_dump -Fc`, cifrato con `age` usando una chiave pubblica; la chiave privata resta fuori da Railway). Va su uno storage a oggetti europeo di un **altro fornitore** (per esempio Scaleway, OVHcloud o Hetzner), con conservazione di 30 giornalieri e 12 mensili e versioning.
4. **Ripristino di prova mensile** automatizzato in un ambiente isolato (I10, AC12), con l'esito registrato.
5. Foto: storage a oggetti europeo con versioning di 30 giorni (08).

### Log e monitoraggio

- Log applicativi già senza dati personali (08, verificato in 6.7). I log di Railway possono essere conservati fuori dall'UE: **serve la conferma del professionista** prima di usare la produzione. In alternativa si inoltrano i log a un servizio europeo e si riduce al minimo la conservazione su Railway.
- Sentry (regione UE) per gli errori; monitoraggio di disponibilità esterno su `/up`.

## Da verificare prima di adottarla (su un progetto di prova)

1. Versione di PostgreSQL disponibile (18, o immagine ufficiale personalizzata) e presenza di `btree_gist`.
2. Accesso con un ruolo che possa creare `app_migrator`, `app_runtime` (`NOBYPASSRLS`, non proprietario) e `app_admin_ops`, ed eseguire gli script di `docker/postgres/init`.
3. Creazione delle funzioni `SECURITY DEFINER` di proprietà di `app_migrator` (lookup per slug, token dei feed e dei preventivi, accesso alla prenotazione, tenant dell'utente).
4. RLS forzata davvero efficace per `app_runtime`: eseguire `Feature/Architecture/RlsCatalogTest`, `CompositeFkCatalogTest` e `CrossTenantInsertRejectedTest` contro il database di prova.
5. Ripristino a un istante: disponibilità nativa, oppure archiviazione del WAL funzionante; un ripristino completo cronometrato (RTO).
6. Comportamento del proxy con `X-Forwarded-*`, timeout delle richieste e dimensione massima del corpo (foto fino a 10 MB).
7. IP in uscita del worker: l'import iCal passa da `SafeHttpFetcher`. 08 chiede l'egress dal solo worker di sync; verificare cosa permette Railway (IP statici o reti private).
8. DPA di Railway (art. 28, clausole contrattuali standard), luogo di conservazione di log e backup, aggiornamento dell'elenco dei sub-responsabili (09).
9. Costi mensili stimati per staging e produzione.

## Alternativa: Forge con un server europeo

È l'impostazione di 11: un server europeo (Hetzner, DigitalOcean Frankfurt o simili) gestito con Laravel Forge, con PostgreSQL 18 installato sullo stesso server o gestito, Horizon e scheduler come daemon di Forge. Lo stesso codice gira senza modifiche: cambiano solo i passi di rilascio e i backup (WAL e dump gestiti da noi). Più controllo su rete, egress e posizione dei log; più manutenzione del sistema operativo.

| Alternativa | Perché non è la prima scelta |
|---|---|
| Forge con un server europeo | Più lavoro di gestione (aggiornamenti, firewall, backup manuali); resta l'alternativa se le verifiche su Railway falliscono |
| Database gestito di un altro fornitore europeo, applicazione su Railway | Latenza e costi del traffico tra fornitori; da valutare solo se Postgres su Railway non regge le verifiche 1–5 |

## Conseguenze

- 6.4 si svolge su Railway invece che su Forge, se la proposta è accettata.
- Nuovi sub-responsabili da elencare in 09: Railway e il fornitore dello storage dei backup.
- Script di rilascio e di backup da aggiungere al repository (nessun segreto).

## Impatto sui test

- Nessun test nuovo nel codice. I test di catalogo (RLS, FK composite) si eseguono anche contro il database di staging prima della produzione.

## Aggiornamento della specifica

- [ ] 08 "Backup e ripristino" e "Ambienti" aggiornati con il fornitore, quando la proposta è accettata
- [ ] 09: sub-responsabili
- [ ] 11: attività 6.4
