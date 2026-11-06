# ADR-0002 — Occupazione unica come garanzia anti-overbooking

- **Stato**: Accettata
- **Data**: 2026-11-06
- **Documenti toccati**: 02, 03, 05, 06, 08, CLAUDE.md
- **Origine**: revisione tecnica v1.0, punti 2 e 8 (CHANGELOG C2, C8)

## Contesto

Nella 1.0 le occupazioni interne vivevano in tre posti (prenotazioni, hold, blocchi), con due vincoli di esclusione su tabelle diverse. Le collisioni tra tabelle erano affidate al codice. Inoltre una capture fallita portava la prenotazione in uno stato terminale mentre l'hold restava attivo, e l'export iCal produceva due eventi per la stessa prenotazione.

## Decisione

Una sola tabella `inventory_occupancies` per tutte le occupazioni interne (`RESERVATION`, `MANUAL_BLOCK`), con un solo vincolo GiST `EXCLUDE (unit_id WITH =, stay WITH &&) WHERE status = 'ACTIVE'`. Una prenotazione ha una sola occupazione per tutta la vita (fase `TEMPORARY` → `FIRM` sulla stessa riga). Tutte le scritture passano da `InventoryService`, con lock dell'unità. Eventi esterni e conflitti restano tabelle separate perché sono fatti e segnalazioni che possono sovrapporsi.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Mantenere tabelle separate e una funzione di controllo | la garanzia resta nel codice, non nel database |
| Trigger che controllano le altre tabelle | difficili da testare, problemi di concorrenza senza lock espliciti |
| Inserire anche gli eventi esterni nel vincolo | gli eventi esterni possono legittimamente sovrapporsi (sono il motivo dei conflitti) |

## Conseguenze

- Un'occupazione scaduta ma non rilasciata continua a bloccare: alcune date possono restare non vendibili qualche minuto in più. Scelta prudente.
- La disponibilità si calcola su tre fonti invece di cinque.
- L'UID iCal di una prenotazione è stabile.

## Impatto sui test

- Nuovi: coerenza stato/occupazione (I15), 20 acquisizioni parallele, controllo statico sulle scritture.
- Invarianti: I1 riformulata, I15 nuova.
