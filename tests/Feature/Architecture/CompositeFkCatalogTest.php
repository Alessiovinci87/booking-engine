<?php

use Illuminate\Support\Facades\DB;

/*
 * I17 (02 "Integrità tra tenant", ADR-0005): ogni FK tra due tabelle dello schema public che hanno
 * entrambe la colonna tenant_id deve includere la coppia tenant_id -> tenant_id, cioè essere una FK
 * composita (tenant_id, parent_id) -> parent (tenant_id, id). I controlli FK di PostgreSQL ignorano
 * RLS: una FK semplice accetterebbe un riferimento a una riga di un altro tenant.
 */

it('ogni FK tra tabelle con tenant_id include tenant_id', function () {
    $violations = DB::select(<<<'SQL'
        SELECT con.conname AS constraint_name, child.relname AS child_table, parent.relname AS parent_table
        FROM pg_constraint con
        JOIN pg_class child ON child.oid = con.conrelid
        JOIN pg_namespace n ON n.oid = child.relnamespace
        JOIN pg_class parent ON parent.oid = con.confrelid
        JOIN pg_attribute child_tenant
          ON child_tenant.attrelid = con.conrelid AND child_tenant.attname = 'tenant_id' AND NOT child_tenant.attisdropped
        JOIN pg_attribute parent_tenant
          ON parent_tenant.attrelid = con.confrelid AND parent_tenant.attname = 'tenant_id' AND NOT parent_tenant.attisdropped
        WHERE con.contype = 'f'
          AND n.nspname = 'public'
          AND NOT EXISTS (
            SELECT 1
            FROM unnest(con.conkey, con.confkey) AS pair(child_col, parent_col)
            WHERE pair.child_col = child_tenant.attnum AND pair.parent_col = parent_tenant.attnum
          )
        ORDER BY child.relname, con.conname
        SQL);

    $report = array_map(
        fn (object $row): string => sprintf('%s: %s -> %s', $row->constraint_name, $row->child_table, $row->parent_table),
        $violations,
    );

    expect($report)->toBeEmpty(
        "FK tra tabelle tenant-scoped senza tenant_id (serve una FK composita, ADR-0005):\n- ".implode("\n- ", $report)
    );
});
