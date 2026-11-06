<?php

namespace App\Domain\Tenancy\Database;

use BackedEnum;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Unico modo di creare una tabella tenant-scoped nelle migration (02 "Integrità tra tenant", 08 RLS,
 * ADR-0005). create() applica sempre:
 *
 * - id UUID (v7, generato dall'applicazione) e tenant_id UUID NOT NULL con FK verso tenants;
 * - UNIQUE (tenant_id, id), richiesto dalle FK composite dei figli;
 * - ENABLE e FORCE ROW LEVEL SECURITY con la policy tenant_isolation di 08 (fail-closed).
 *
 * belongsTo() aggiunge una FK composita (tenant_id, colonna) -> genitore (tenant_id, id) con l'indice
 * che ha tenant_id in testa. I test di catalogo (RlsCatalogTest, CompositeFkCatalogTest) verificano
 * il risultato su tutte le tabelle reali.
 *
 * Le migration girano come app_migrator: le chiamate usano la connessione della migration in corso.
 */
final class TenantTable
{
    /**
     * @param  Closure(Blueprint): void  $columns
     */
    public static function create(string $table, Closure $columns, bool $timestamps = true): void
    {
        self::assertIdentifier($table);

        Schema::create($table, function (Blueprint $blueprint) use ($table, $columns, $timestamps): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('tenant_id');
            $blueprint->foreign('tenant_id', "{$table}_tenant_fk")->references('id')->on('tenants');

            $columns($blueprint);

            if ($timestamps) {
                $blueprint->timestampsTz();
            }

            $blueprint->unique(['tenant_id', 'id'], "{$table}_tenant_id_uniq");
        });

        self::enableRowLevelSecurity($table);
    }

    /**
     * RLS abilitata e forzata (vale anche per il proprietario app_migrator) con la policy di 08.
     */
    public static function enableRowLevelSecurity(string $table): void
    {
        self::assertIdentifier($table);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$table} "
            .'USING (tenant_id = app_current_tenant()) WITH CHECK (tenant_id = app_current_tenant())'
        );
    }

    /**
     * Colonna UUID con FK composita (tenant_id, $column) -> $parent (tenant_id, id) e indice
     * (tenant_id, $column).
     */
    public static function belongsTo(
        Blueprint $blueprint,
        string $column,
        string $parent,
        bool $nullable = false,
        bool $index = true,
    ): void {
        self::assertIdentifier($column);
        self::assertIdentifier($parent);

        $table = $blueprint->getTable();
        $definition = $blueprint->uuid($column);

        if ($nullable) {
            $definition->nullable();
        }

        $blueprint->foreign(['tenant_id', $column], "{$table}_{$column}_fk")
            ->references(['tenant_id', 'id'])
            ->on($parent);

        if ($index) {
            $blueprint->index(['tenant_id', $column], "{$table}_{$column}_idx");
        }
    }

    /**
     * FK composita aggiunta dopo la creazione (riferimenti circolari, es. quotes <-> reservations).
     */
    public static function addForeign(string $table, string $column, string $parent): void
    {
        foreach ([$table, $column, $parent] as $identifier) {
            self::assertIdentifier($identifier);
        }

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_fk "
            ."FOREIGN KEY (tenant_id, {$column}) REFERENCES {$parent} (tenant_id, id)"
        );
        DB::statement("CREATE INDEX {$table}_{$column}_idx ON {$table} (tenant_id, {$column})");
    }

    /**
     * Vincolo CHECK con i valori dell'enum PHP (stati e categorie come colonne text).
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enumCheck(string $table, string $column, string $enum): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);

        $values = array_map(
            static fn (BackedEnum $case): string => "'".str_replace("'", "''", (string) $case->value)."'",
            $enum::cases(),
        );

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check "
            ."CHECK ({$column} IN (".implode(', ', $values).'))'
        );
    }

    public static function check(string $table, string $name, string $expression): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($name);

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$expression})");
    }

    /**
     * Colonna DATERANGE di un soggiorno: intervallo [check_in, check_out) non vuoto (02).
     * PostgreSQL normalizza i daterange in forma [): il vincolo rifiuta comunque limiti infiniti.
     */
    public static function stayColumn(string $table, string $column = 'stay', bool $nullable = false): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);

        DB::statement(
            "ALTER TABLE {$table} ADD COLUMN {$column} daterange".($nullable ? '' : ' NOT NULL')
        );

        self::check(
            $table,
            "{$column}_valid",
            "{$column} IS NULL OR (NOT isempty({$column}) AND lower_inc({$column}) AND NOT upper_inc({$column}) "
            ."AND NOT lower_inf({$column}) AND NOT upper_inf({$column}))"
        );
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Identificatore SQL non valido.');
        }
    }
}
