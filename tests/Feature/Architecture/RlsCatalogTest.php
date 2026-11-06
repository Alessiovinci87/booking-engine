<?php

use Illuminate\Support\Facades\DB;

/*
 * I5 / AC11 (08, "Test di isolamento"): ogni tabella dello schema public con una colonna tenant_id
 * deve avere Row Level Security abilitata (relrowsecurity) e forzata (relforcerowsecurity).
 * Il controllo legge il catalogo, quindi copre anche le tabelle create in futuro.
 */

it('ogni tabella con tenant_id ha RLS abilitata e forzata', function () {
    $violations = DB::select(<<<'SQL'
        SELECT c.relname AS table_name, c.relrowsecurity AS rls_enabled, c.relforcerowsecurity AS rls_forced
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
        WHERE n.nspname = 'public'
          AND c.relkind IN ('r', 'p')
          AND NOT (c.relrowsecurity AND c.relforcerowsecurity)
        ORDER BY c.relname
        SQL);

    $report = array_map(
        fn (object $row): string => sprintf(
            '%s (RLS abilitata: %s, forzata: %s)',
            $row->table_name,
            $row->rls_enabled ? 'sì' : 'no',
            $row->rls_forced ? 'sì' : 'no',
        ),
        $violations,
    );

    expect($report)->toBeEmpty(
        "Tabelle con tenant_id senza RLS abilitata e forzata:\n- ".implode("\n- ", $report)
    );
});
