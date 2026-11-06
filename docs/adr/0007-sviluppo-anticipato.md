# ADR-0007 — Sviluppo anticipato rispetto alla decisione della Fase 0

- **Stato**: Accettata
- **Data**: 2026-11-06
- **Autori**: Alessio (decisione), Claude Code (stesura)
- **Documenti di specifica toccati**: 11-MILESTONES, README (indice). Nessun documento di comportamento (01–10).

## Contesto

Il piano a milestone (11, versione 1.0) faceva partire M1 solo dopo il gate "decisione Fase 0" del 18/12/2026, cioè dopo l'esito di interviste, piloti e verifica dei calendari reali.

M0 (fondazioni tecniche) è stata completata e unita su `main` il 06/11/2026, con largo anticipo sul periodo previsto (16/11–18/12/2026).

Le milestone M1–M3 (modello dati, accesso, strutture, prezzi, inventario, prenotazioni, pagina ospite senza pagamento) implementano parti della specifica che non dipendono dagli esiti delle interviste. Il rischio di prodotto, cioè costruire qualcosa che i gestori non useranno o che le OTA non supportano come previsto, cresce da M4 in poi: pagamenti, calendari reali, comunicazioni e rilascio.

## Decisione

**Lo sviluppo prosegue senza attendere la decisione della Fase 0 del 18/12/2026. La Fase 0 continua in parallelo ed è condizione per l'avvio del pilota, non per lo sviluppo.**

Dettagli:

- La Fase 0 (interviste, piloti, verifica dei calendari) prosegue in parallelo. Il suo esito positivo diventa il **gate pilota**, da superare prima di M6 (staging, produzione e attivazione dei piloti).
- Condizioni che restano:
  - **accordo preliminare tra i soci** (riservatezza e titolarità del codice) entro il **27/11/2026**;
  - **risultati della verifica dei calendari reali** (Booking, Airbnb) **prima di M5**. Se mancano, M5 procede con la specifica (06) e la verifica diventa un'attività di M6, come già previsto in 11.
- Nuove scadenze:

| Milestone | Scadenza |
|---|---|
| M0 Fondazioni tecniche | completata il 2026-11-06 |
| M1 Modello dati, accesso e audit | 2026-11-20 |
| M2 Strutture, tariffe e prezzi | 2026-12-04 |
| M3 Inventario, prenotazioni e pagina ospite | 2026-12-18 |
| M4 Pagamenti Stripe | 2027-01-15 |
| M5 Calendari iCal e preventivi | 2027-01-29 |
| M6 Comunicazioni, privacy e rilascio | 2027-02-19 |
| M7 Completamento durante il pilota | 2027-05-31 |

- Il **pilota resta ad aprile 2027**, come prima.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Attendere il 18/12/2026 come da piano 1.0 | sei settimane senza sviluppo su parti (M1–M3) indipendenti dagli esiti delle interviste |
| Sviluppare tutto, M4–M6 compresi, senza alcun gate | il rischio di prodotto è concentrato da M4 in poi: il rilascio ai piloti deve restare legato all'esito della Fase 0 |
| Anticipare anche il pilota | i piloti dipendono dalla Fase 0 (selezione dei gestori, verifica dei calendari) e dall'accordo tra i soci, non dalla velocità dello sviluppo |

## Conseguenze

- Nessun effetto sul comportamento del sistema né sui dati: cambia solo il calendario del piano.
- Rischio introdotto: lavoro su M4–M5 che potrebbe dover essere adattato se la Fase 0 cambia requisiti (es. comportamento reale dei feed OTA). Mitigazioni: la verifica dei calendari prima di M5 e il gate pilota prima di M6.
- Rischio introdotto: sviluppare codice prima dell'accordo sulla titolarità. Mitigazione: scadenza dell'accordo preliminare tra i soci al 27/11/2026, durante M1.
- Tra la fine di M6 (19/02/2027) e il pilota (aprile 2027) resta un margine di circa sei settimane, utile per gli esiti della Fase 0, il collaudo e le correzioni.

## Impatto sui test

- Test da aggiungere: nessuno.
- Test da modificare: nessuno.
- Invarianti toccati (I1–I22): nessuno.

## Aggiornamento della specifica

- [x] Documenti aggiornati con riferimento a questa ADR: 11-MILESTONES (panoramica, periodi, gate), README (indice)
- [x] Versione del piano incrementata: 11-MILESTONES 1.1
