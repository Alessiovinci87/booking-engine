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

/*
 * Ogni tabella con tenant_id ha la policy tenant_isolation di 08, identica su tutte (creata da
 * TenantTable), per tutti i comandi e per tutti i ruoli. Unica eccezione documentata: audit_log, che
 * accetta anche gli eventi di piattaforma senza tenant (tenant_id NULL fuori da un contesto tenant).
 */
it('ogni tabella con tenant_id ha la policy tenant_isolation fail-closed di 08', function () {
    $tables = DB::select(<<<'SQL'
        SELECT c.relname AS table_name, p.polcmd, p.polpermissive, p.polroles,
               pg_get_expr(p.polqual, p.polrelid) AS using_expr,
               pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
        LEFT JOIN pg_policy p ON p.polrelid = c.oid AND p.polname = 'tenant_isolation'
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
        ORDER BY c.relname
        SQL);

    $expected = '(tenant_id = app_current_tenant())';
    $violations = [];

    foreach ($tables as $row) {
        $checkExpected = $row->table_name === 'audit_log'
            ? '((tenant_id = app_current_tenant()) OR ((tenant_id IS NULL) AND (app_current_tenant() IS NULL)))'
            : $expected;

        if ($row->using_expr !== $expected || $row->check_expr !== $checkExpected
            || $row->polcmd !== '*' || ! $row->polpermissive || $row->polroles !== '{0}') {
            $violations[] = $row->table_name;
        }
    }

    expect($tables)->not->toBeEmpty()
        ->and($violations)->toBe([], "Policy tenant_isolation mancante o diversa da 08:\n- ".implode("\n- ", $violations));
});

it('nessuna policy permissiva aggiuntiva apre le tabelle tenant-scoped ad app_runtime', function () {
    // Le policy permissive si sommano (OR): una policy in più per PUBLIC o app_runtime annullerebbe
    // l'isolamento. Ammesse solo tenant_isolation e definer_lookup (riservata ad app_migrator, cioè
    // alle funzioni SECURITY DEFINER: risoluzione del tenant per slug, membership e, da M3, per
    // codice + token della prenotazione, da M4, per account Stripe del webhook e, da M5, per token
    // del feed iCal). Da M7 (ADR-0018 punto 15) le due policy di app_retention sulle righe di piattaforma
    // dell'audit log (tenant_id NULL, senza contesto tenant), mai per app_runtime.
    $extra = DB::select(<<<'SQL'
        SELECT c.relname AS table_name, p.polname,
               ARRAY(SELECT CASE WHEN r = 0 THEN 'public' ELSE pg_get_userbyid(r) END FROM unnest(p.polroles) r) AS roles
        FROM pg_policy p
        JOIN pg_class c ON c.oid = p.polrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND p.polname <> 'tenant_isolation'
        SQL);

    $violations = array_values(array_filter(
        array_map(fn ($row) => "{$row->table_name}.{$row->polname} {$row->roles}", $extra),
        fn (string $line) => ! preg_match('/^(tenants|tenant_members|reservations|stripe_accounts|ics_feeds|quotes)\.definer_lookup \{app_migrator\}$/', $line)
            && ! preg_match('/^audit_log\.retention_platform_(select|delete) \{app_retention\}$/', $line),
    ));

    expect($violations)->toBe([]);
});

it('tenants ha RLS forzata con la policy id = app_current_tenant()', function () {
    $row = DB::selectOne(<<<'SQL'
        SELECT c.relrowsecurity, c.relforcerowsecurity,
               pg_get_expr(p.polqual, p.polrelid) AS using_expr,
               pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
        FROM pg_class c
        JOIN pg_policy p ON p.polrelid = c.oid AND p.polname = 'tenant_isolation'
        WHERE c.relname = 'tenants'
        SQL);

    expect($row->relrowsecurity)->toBeTrue()
        ->and($row->relforcerowsecurity)->toBeTrue()
        ->and($row->using_expr)->toBe('(id = app_current_tenant())')
        ->and($row->check_expr)->toBe('(id = app_current_tenant())');
});
