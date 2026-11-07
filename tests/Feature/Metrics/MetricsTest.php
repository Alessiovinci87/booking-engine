<?php

use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Payments\Models\Payment;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Metrics\ReservationCsvExport;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * 6.2 — Metriche del pannello (01, 07 GET /admin/metrics) ed export CSV (07 GET
 * /admin/exports/reservations.csv). Importi in centesimi; percentuale OTA della struttura (default
 * 1500 bps); commissioni di pagamento registrate o stimate (ADR-0014).
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    $this->direct = reservationIn($this->fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');

    $quote = quoteFor($this->fx, ['stay' => '[2027-07-20,2027-07-24)', 'discount' => 4000]);
    $code = acceptQuote($this, quoteToken($quote))->assertCreated()->json('reservation_code');
    $this->quoted = simulateCustomer($this->fx, quoteReservation($this->fx, $code), FakeCustomerAction::PAY);

    // Fuori periodo e cancellata: non contano nelle metriche.
    reservationIn($this->fx, ReservationStatus::CONFIRMED, '[2027-09-10,2027-09-14)');
    $cancelled = reservationIn($this->fx, ReservationStatus::CONFIRMED, '[2027-07-15,2027-07-19)');
    inTenant($this->fx['tenant'], fn () => machine()->cancel($cancelled, CancellationRefund::for($cancelled, 0), null, null));
    // Tentativo non concluso: escluso anche dal CSV.
    reservationIn($this->fx, ReservationStatus::EXPIRED, '[2027-07-25,2027-07-29)');

    $this->owner = panelUser($this->fx['tenant']);
    // L'export CSV contiene dati personali: password confermata negli ultimi 15 minuti (08, 6.7).
    actingAsPanelUser($this, $this->owner['user'], passwordConfirmed: true);
});
afterEach(fn () => Carbon::setTestNow());

function estimatedFee(int $captured): int
{
    return intdiv($captured * 150 + 5000, 10000) + 25;
}

it('fatturato diretto, commissioni OTA evitate e beneficio diretto stimato del periodo', function () {
    $direct = reloadReservation($this->fx, $this->direct->id);
    $quoted = reloadReservation($this->fx, $this->quoted->id);
    $captured = inTenant($this->fx['tenant'], fn () => Payment::query()->whereIn('reservation_id', [$direct->id, $quoted->id])->pluck('captured_cents')->all());

    $revenue = $direct->contract_total_cents + $quoted->contract_total_cents;
    $avoided = intdiv($direct->contract_total_cents * 1500 + 5000, 10000) + intdiv($quoted->contract_total_cents * 1500 + 5000, 10000);
    $fees = array_sum(array_map(estimatedFee(...), $captured));

    $data = panelGet($this, '/api/v1/admin/metrics?from=2027-07-01&to=2027-07-31')->assertOk()->json('data');

    expect($data['reservations'])->toBe(2)
        ->and($data['nights'])->toBe(8)
        ->and($data['direct_revenue_cents'])->toBe($revenue)
        ->and($data['ota_commission_avoided_cents'])->toBe($avoided)
        ->and($data['payment_fees_cents'])->toBe($fees)
        ->and($data['payment_fees_estimated'])->toBeTrue()
        ->and($data['direct_discounts_cents'])->toBe(4000)
        ->and($data['estimated_direct_benefit_cents'])->toBe($avoided - $fees - 4000)
        ->and($data['properties'][0]['ota_commission_bps'])->toBe(1500)
        ->and($data['properties'][0]['direct_revenue_cents'])->toBe($revenue)
        ->and($data)->not->toHaveKey('net_savings_cents');
});

it('usa le commissioni registrate quando ci sono e la percentuale della struttura', function () {
    inTenant($this->fx['tenant'], function () {
        Payment::query()->whereIn('reservation_id', [$this->direct->id, $this->quoted->id])->update(['fee_cents' => 300]);
        DB::table('properties')->update(['ota_commission_bps' => 1800]);
    });
    $direct = reloadReservation($this->fx, $this->direct->id);
    $quoted = reloadReservation($this->fx, $this->quoted->id);
    $payments = inTenant($this->fx['tenant'], fn () => Payment::query()->whereIn('reservation_id', [$direct->id, $quoted->id])->where('captured_cents', '>', 0)->count());

    $data = panelGet($this, '/api/v1/admin/metrics?from=2027-07-01&to=2027-07-31')->assertOk()->json('data');

    expect($data['payment_fees_cents'])->toBe(300 * $payments)
        ->and($data['payment_fees_estimated'])->toBeFalse()
        ->and($data['ota_commission_avoided_cents'])->toBe(intdiv($direct->contract_total_cents * 1800 + 5000, 10000) + intdiv($quoted->contract_total_cents * 1800 + 5000, 10000))
        ->and($data['properties'][0]['ota_commission_bps'])->toBe(1800);
});

it('periodo predefinito e validazione', function () {
    panelGet($this, '/api/v1/admin/metrics')->assertOk()->assertJsonPath('data.from', '2027-06-01')->assertJsonPath('data.to', '2027-06-30')->assertJsonPath('data.reservations', 0);
    panelGet($this, '/api/v1/admin/metrics?from=2027-07-31&to=2027-07-01')->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    panelGet($this, '/api/v1/admin/metrics?from=2027-01-01&to=2028-01-02')->assertStatus(400);
    panelGet($this, '/api/v1/admin/exports/reservations.csv?from=bad')->assertStatus(400);
});

it('export CSV senza password confermata: 403 REAUTH_REQUIRED; le metriche no (6.7)', function () {
    $this->withSession(['panel.password_confirmed_at' => 0]);
    panelGet($this, '/api/v1/admin/metrics?from=2027-07-01&to=2027-07-31')->assertOk();
    panelGet($this, '/api/v1/admin/exports/reservations.csv?from=2027-07-01&to=2027-07-31')->assertForbidden()->assertJsonPath('error.code', 'REAUTH_REQUIRED');
});

it('un altro tenant non vede metriche né righe del CSV', function () {
    $other = bookableTenant('INSTANT');
    actingAsPanelUser($this, panelUser($other['tenant'])['user'], passwordConfirmed: true);
    panelGet($this, '/api/v1/admin/metrics?from=2027-07-01&to=2027-07-31')->assertOk()->assertJsonPath('data.reservations', 0)->assertJsonPath('data.direct_revenue_cents', 0);
    expect(panelGet($this, '/api/v1/admin/exports/reservations.csv?from=2027-07-01&to=2027-07-31')->getContent())->not->toContain($this->direct->code);
});

it('CSV: prenotazioni e anagrafica del periodo, senza tentativi non conclusi, protetto dalle formule, in audit senza dati personali', function () {
    inTenant($this->fx['tenant'], fn () => DB::table('guests')->where('id', reloadReservation($this->fx, $this->direct->id)->guest_id)->update(['notes' => '=HYPERLINK("http://x")']));

    $response = panelGet($this, '/api/v1/admin/exports/reservations.csv?from=2027-07-01&to=2027-07-31')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="prenotazioni-2027-07-01-2027-07-31.csv"');

    $csv = (string) $response->getContent();
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();

    $rows = array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
    expect($rows[0])->toBe(ReservationCsvExport::COLUMNS)
        ->and(count($rows))->toBe(4); // intestazione + diretta, preventivo, cancellata

    $byCode = collect(array_slice($rows, 1))->keyBy(0);
    $direct = $byCode[$this->direct->code];
    expect($direct[1])->toBe('CONFIRMED')
        ->and($direct[5])->toBe('2027-07-10')
        ->and($direct[13])->toBe('Ada')
        ->and($direct[15])->toBe('ada@example.test')
        ->and($direct[19])->toBe('\'=HYPERLINK("http://x")')
        ->and($direct[10])->toMatch('/^\d+,\d{2}$/')
        ->and($byCode->pluck(1)->all())->toContain('CANCELLED')->not->toContain('EXPIRED');

    $audit = inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('action', 'export.reservations_csv')->sole());
    expect($audit->changes)->toEqualCanonicalizing(['from' => '2027-07-01', 'to' => '2027-07-31', 'rows' => 3])
        ->and(json_encode($audit->getAttributes()))->not->toContain('ada@example.test');
});

it('prenotazioni non confermate non entrano nelle metriche', function () {
    $count = inTenant($this->fx['tenant'], fn () => Reservation::query()->count());
    expect($count)->toBe(5);

    $data = panelGet($this, '/api/v1/admin/metrics?from=2027-07-01&to=2027-09-30')->assertOk()->json('data');
    expect($data['reservations'])->toBe(3);
});
