# ADR-0005 — Chiavi esterne composite per l'integrità tra tenant

- **Stato**: Accettata
- **Data**: 2026-11-06
- **Documenti toccati**: 02, 08, CLAUDE.md
- **Origine**: revisione tecnica v1.0, punto 7 (CHANGELOG C7)

## Contesto

RLS isola le righe lette e scritte, ma PostgreSQL verifica le chiavi esterne senza applicare RLS. Con FK semplici, un errore nel codice potrebbe collegare una prenotazione del tenant A a un'unità del tenant B, e il database lo accetterebbe.

## Decisione

`UNIQUE (tenant_id, id)` su ogni tabella tenant-scoped; ogni relazione tra tabelle tenant-scoped è una FK composita `(tenant_id, parent_id) → parent(tenant_id, id)`; indici con `tenant_id` in testa; test automatico sul catalogo dei vincoli.

## Alternative considerate

| Alternativa | Perché scartata |
|---|---|
| Solo controlli applicativi | un bug basta a creare legami trasversali |
| Trigger di verifica | più lenti e più facili da dimenticare delle FK dichiarative |
| Un database per tenant | eccessivo per la scala prevista (02, 08) |

## Conseguenze

Migration leggermente più verbose; un indice unico in più per tabella.

## Impatto sui test

- Nuovi: catalogo `pg_constraint`, inserimento trasversale rifiutato.
- Invarianti: I17 nuova, I5 rafforzata.
