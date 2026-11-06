# 04 — Pricing

Versione: 1.1 · Data: 06/11/2026 · Modifiche: [CHANGELOG-v1.1](CHANGELOG-v1.1.md)

Il prezzo si calcola **solo lato server**, in un servizio puro (`PriceCalculator`) senza accesso a rete o orologio: riceve tariffe e richiesta, restituisce righe e totale. Il frontend mostra, non calcola.

## Input

| Campo | Tipo | Note |
|---|---|---|
| `unit` | Unit | `base_occupancy`, `max_occupancy`, `cleaning_fee_cents`, `extra_guest_fee_cents`, `min_nights` |
| `rate_plan` | RatePlan + SeasonRule[] | uno attivo per unità |
| `stay` | `[check_in, check_out)` | almeno 1 notte |
| `adults`, `children` | int | i bambini contano come ospiti nella 1.0 (nessuna tariffa per età) |
| `payment_mode` | `DEPOSIT_30` \| `FULL` | dall'unità |
| `discount_cents` | int ≥ 0 | solo da preventivo o prenotazione manuale |

## Notti

Le notti sono le date `d` con `check_in ≤ d < check_out`. Un soggiorno dal 10 al 17 agosto ha 7 notti: dal 10 al 16. La data di check-out non è una notte e non è occupata.

## Prezzo per notte

Per ogni notte `d`:

1. Si raccolgono le `SeasonRule` con `d ∈ date_range`.
2. Se nessuna: si usa `base_nightly_cents`.
3. Se una o più: vince quella con `priority` più alta; a parità, quella con `date_range` più corto; a parità, la più recente (`created_at`).

Le stagioni si possono sovrapporre (es. "Estate" e sopra "Ferragosto"). Il pannello avvisa quando due regole con la stessa priorità si sovrappongono.

## Minimo notti

`min_nights_richiesto = max( unit.min_nights, max(min_nights delle stagioni vincenti su ciascuna notte del soggiorno) )`

Le stagioni con `min_nights` nullo non contribuiscono. Se `notti < min_nights_richiesto` → errore `MIN_NIGHTS_NOT_MET` con il valore richiesto.

## Ospiti

- `ospiti = adults + children`
- `ospiti > max_occupancy` → errore `OCCUPANCY_EXCEEDED`
- `extra = max(0, ospiti − base_occupancy)`
- supplemento per notte = `extra × extra_guest_fee_cents`

## Righe e totale

| Riga | Calcolo |
|---|---|
| `NIGHT` (una per notte) | prezzo della notte |
| `EXTRA_GUEST` (una per notte, se `extra > 0`) | `extra × extra_guest_fee_cents`, `quantity = extra` |
| `CLEANING` (una) | `cleaning_fee_cents`, se > 0 |
| `DISCOUNT` (una, negativa) | `−discount_cents`, se > 0 |

```
subtotale = Σ NIGHT + Σ EXTRA_GUEST + CLEANING
totale    = subtotale − discount_cents
```

Vincoli: `0 ≤ discount_cents ≤ subtotale`; `totale ≥ 50` centesimi se si paga online (minimo Stripe in EUR).

Tutti gli importi sono interi in centesimi; nessun numero decimale in nessun passaggio.

## Caparra e saldo

- `FULL`: `deposit_due_cents = totale`, `balance_due_cents = 0`
- `DEPOSIT_30`: `deposit_due_cents = ceil(totale × 30 / 100)` calcolato in interi come `(totale × 30 + 99) div 100`; `balance_due_cents = totale − deposit_due_cents`

L'arrotondamento per eccesso al centesimo cade sulla caparra, così `deposit + balance = totale` sempre.

Il saldo non viene addebitato in automatico: il gestore lo incassa fuori piattaforma e lo registra come `Payment OFFLINE`, oppure invia un link di pagamento esplicito.

## Snapshot

Alla creazione della prenotazione le righe vengono copiate in `ReservationPriceLine` e i totali in `Reservation`. Cambiare tariffe dopo non modifica prenotazioni esistenti (invariante I14).

## Preventivi

Il prezzo di un preventivo si calcola una volta, alla creazione, e si **congela** in `Quote.price_lines` e `final_total_cents`. All'accettazione non si ricalcola: si controllano solo disponibilità, scadenza e capienza (03). Il gestore che vuole un prezzo diverso emette un nuovo preventivo.

## Tassa di soggiorno

Fuori dalla 1.0. Non viene calcolata né mostrata come riga. La pagina ospite mostra la frase configurabile per struttura, es. "Imposta di soggiorno non inclusa, da versare in struttura secondo regolamento comunale".

Nella fase 4 il calcolo arriverà da un motore di regole per Comune, separato dal `PriceCalculator` (es. Alghero: tariffa per persona/notte con tetto di notti ed esenzioni, regole in dati, non in codice).

## Simulazione tariffe per canale

Solo informativa nel pannello: dato il prezzo diretto e `ota_commission_bps`, mostra il prezzo suggerito per incassare lo stesso netto su ogni OTA:

```
prezzo_ota_suggerito = ceil( prezzo_diretto × 10000 / (10000 − commissione_bps) )
```

Esempio: 100,00 € diretti con commissione 15% → 117,65 € su Booking. Non viene pubblicato da nessuna parte nella 1.0.

## Esempio di riferimento (test golden)

Unità: base 4 ospiti, max 6, pulizie 60 €, extra 15 €/notte, min 3 notti. Base 120 €/notte. Stagione "Agosto" 1–31/08 a 180 € (priorità 10, min 5). Stagione "Ferragosto" 13–17/08 a 220 € (priorità 20). Richiesta: 10→17 agosto, 5 ospiti, caparra 30%.

| Notte | Prezzo |
|---|---|
| 10, 11, 12 | 180 € × 3 = 540 € |
| 13, 14, 15, 16 | 220 € × 4 = 880 € |
| Extra ospite | 1 × 15 € × 7 = 105 € |
| Pulizie | 60 € |
| **Totale** | **1.585,00 €** |
| Caparra 30% | 475,50 € |
| Saldo | 1.109,50 € |

Minimo notti richiesto: max(3, 5) = 5; il soggiorno ha 7 notti → valido.

Questo esempio diventa un test unitario del `PriceCalculator`, insieme ai casi limite: soggiorno a cavallo di due stagioni, stagioni con stessa priorità, sconto pari al subtotale, totale sotto il minimo Stripe, ospiti oltre il massimo.
