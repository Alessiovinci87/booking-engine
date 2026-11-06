<?php

/*
 * Controllo statico (10): nessuna scrittura diretta di status fuori dalle macchine a stati (03;
 * CLAUDE.md regola 5). Versione decisa dopo la verifica di M0 (docs/reports/M0-rapporto.md):
 *
 * - vieta solo le scritture: update, fill, forceFill, create (e varianti) con chiave 'status',
 *   setAttribute('status', ...) e l'assegnazione ->status = ...;
 * - consente letture, regole di validazione e API Resource ('status' => $this->status);
 * - eccezioni: app/Domain/<Area>/StateMachines e InventoryService, unico scrittore delle occupazioni
 *   (che non hanno una macchina a stati propria, 03).
 *
 * La protezione a runtime è nel trait GuardsStatusWrites dei modelli con macchina a stati.
 */

const STATUS_WRITE_PATTERNS = [
    // ->status = ... (non ==, non =>)
    '/->status\s*=(?![=>])/',
    // setAttribute('status', ...)
    '/setAttribute\(\s*[\'"]status[\'"]/',
    // metodi di scrittura con un array letterale che contiene la chiave status
    '/\b(?:update|fill|forceFill|create|forceCreate|make|insert|insertOrIgnore|insertGetId|upsert|updateOrInsert|firstOrCreate|firstOrNew|updateOrCreate|createQuietly|updateQuietly|saveQuietly)\s*\(\s*(?:\[[^\[\]]*\]\s*,\s*)?\[[^\[\]]*[\'"]status[\'"]\s*=>/s',
];

function containsStatusWrite(string $code): bool
{
    foreach (STATUS_WRITE_PATTERNS as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return true;
        }
    }

    return false;
}

it('nessuna scrittura di status fuori da app/Domain/*/StateMachines', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if ($file->getExtension() !== 'php'
            || preg_match('#^app/Domain/[^/]+/StateMachines/#', $relative)
            || $relative === 'app/Domain/Inventory/InventoryService.php') {
            continue;
        }

        if (containsStatusWrite((string) file_get_contents($file->getPathname()))) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty(
        "Scrittura diretta dello stato fuori dalle macchine a stati:\n- ".implode("\n- ", $offenders)
    );
});

it('riconosce le scritture dello stato', function (string $code) {
    expect(containsStatusWrite($code))->toBeTrue();
})->with([
    '$reservation->update([\'status\' => ReservationStatus::CONFIRMED]);',
    'Reservation::query()->where(\'id\', $id)->update(["status" => "CONFIRMED"]);',
    '$payment->fill([\'amount_cents\' => 1, \'status\' => $s]);',
    '$quote->forceFill([\'status\' => QuoteStatus::SENT])->save();',
    'Refund::create([\'payment_id\' => $p, \'status\' => RefundStatus::PENDING]);',
    "DB::table('reservations')->insert(['id' => \$id, 'status' => 'CONFIRMED']);",
    'Quote::updateOrCreate([\'id\' => $id], [\'status\' => \'SENT\']);',
    '$reservation->status = ReservationStatus::CANCELLED;',
    '$reservation?->status = ReservationStatus::CANCELLED;',
    '$conflict->setAttribute(\'status\', ConflictStatus::RESOLVED);',
]);

it('non segnala letture, validazioni e API Resource', function (string $code) {
    expect(containsStatusWrite($code))->toBeFalse();
})->with([
    "return ['status' => \$this->status->value, 'code' => \$this->code];",
    "\$rules = ['status' => ['required', Rule::enum(ReservationStatus::class)]];",
    'if ($reservation->status === ReservationStatus::CONFIRMED) {}',
    'if ($reservation->status == $other) {}',
    "Reservation::where('status', 'CONFIRMED')->get();",
    "protected function casts(): array { return ['status' => ReservationStatus::class]; }",
    "\$reservation->update(['adults' => 3]);",
    'match ($payment->status) { PaymentStatus::PENDING => 1, default => 2 };',
]);
