<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Payments\OfflinePaymentService;
use App\Domain\Pricing\Calculator\PriceQuote;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\Enums\PriceLineKind;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\NewReservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Auth\PanelSession;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Http\WithTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\DryRunRollback;
use App\Http\Api\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManualReservationRequest;
use App\Http\Requests\Admin\ModifyReservationRequest;
use App\Http\Resources\Admin\ReservationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Prenotazioni del pannello (07): elenco, dettaglio, prenotazione manuale (T2), approvazione e rifiuto
 * (T9, T10), cancellazione con rimborso (T17), modifica (T18), pagamenti offline, link di recupero
 * (T15) e rilascio delle date (T16). Ogni cambio di stato passa dalla ReservationStateMachine.
 * Risorse di altri tenant: 404 (RLS).
 */
final class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationStateMachine $machine,
        private readonly PriceQuoteService $prices,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(ReservationStatus::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after:from'],
            'unit_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);

        $query = Reservation::query()->with(['unit', 'guest', 'payments.refunds'])
            ->orderByDesc('created_at')->orderByDesc('id');

        if (isset($validated['status'])) {
            $query->whereIn('status', $validated['status']);
        }

        if (isset($validated['unit_id'])) {
            $query->where('unit_id', $validated['unit_id']);
        }

        if (isset($validated['from']) || isset($validated['to'])) {
            $range = StayRange::of($validated['from'] ?? '2000-01-01', $validated['to'] ?? '2100-01-01');
            $query->whereRaw('stay && ?::daterange', [$range->toPostgres()]);
        }

        if (isset($validated['q'])) {
            $term = mb_strtoupper(trim((string) $validated['q']));
            $query->where('code', $term);
        }

        $page = $query->cursorPaginate((int) ($validated['limit'] ?? 50));

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (Reservation $r): array => ReservationResource::summary($r))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(string $reservation): JsonResponse
    {
        return self::respond(Reservation::query()->findOrFail($reservation));
    }

    /** POST /admin/reservations: T2, confermata con conferma amministrativa, anche senza pagamento. */
    public function store(ManualReservationRequest $request): JsonResponse
    {
        $unit = Unit::query()->findOrFail($request->string('unit_id')->toString());
        $stay = $request->stay();
        $adults = $request->integer('adults');
        $children = $request->integer('children');
        $price = $this->prices->quote($unit, new PriceRequest($stay, $adults, $children, $request->integer('discount_cents'), false));

        $created = $this->machine->createManual(
            new NewReservation($unit, $stay, $adults, $children, $request->guest(), ReservationChannel::MANUAL, $price),
            $request->offlineAmount(),
            $request->offlineMethod(),
        );

        return self::respond($created->reservation, 201);
    }

    /** PATCH /admin/reservations/{id}: T18 con If-Match; prezzo ricalcolato dal server con lo stesso sconto. */
    public function update(ModifyReservationRequest $request, string $reservation): JsonResponse
    {
        $model = self::lock($reservation, $request);
        $expected = $request->expectedTotalCents();

        if ($expected !== null) {
            $price = $this->priceFor($request, $model);

            if ($price->totalCents !== $expected) {
                throw BusinessRuleViolation::make(409, 'PRICE_CHANGED', ['contract_total' => Money::json($price->totalCents, $model->currency)]);
            }
        }

        return self::respond($this->modify($request, $model));
    }

    /**
     * POST /admin/reservations/{id}/modify-preview (ADR-0011 punto 28): esegue la modifica in un
     * savepoint annullato e restituisce soggiorno e prezzo prima e dopo, senza salvare nulla. Stessi
     * errori del PATCH (date non disponibili, transizione non ammessa, notti minime). Il pannello
     * mostra il confronto e chiede conferma; il PATCH successivo porta `expected_total_cents`.
     */
    public function previewModify(ModifyReservationRequest $request, string $reservation): JsonResponse
    {
        $model = Reservation::query()->lockForUpdate()->findOrFail($reservation);
        $current = ReservationResource::priceSnapshot($model);
        $proposed = null;
        $currentCents = $model->contract_total_cents;
        $proposedCents = 0;

        try {
            DB::transaction(function () use ($request, $model, &$proposed, &$proposedCents): void {
                $modified = $this->modify($request, $model)->refresh();
                $proposed = ReservationResource::priceSnapshot($modified);
                $proposedCents = $modified->contract_total_cents;

                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            // Annullata di proposito: l'anteprima non salva niente.
        }

        $model->refresh();

        return new JsonResponse([
            'version' => $model->version,
            'current' => $current,
            'proposed' => $proposed,
            'difference' => Money::json($proposedCents - $currentCents, $model->currency),
        ]);
    }

    private function priceFor(ModifyReservationRequest $request, Reservation $model): PriceQuote
    {
        $discount = -1 * (int) $model->priceLines()->where('kind', PriceLineKind::DISCOUNT->value)->sum('amount_cents');

        return $this->prices->quote($model->unit, new PriceRequest($request->stayFor($model), $request->adultsFor($model),
            $request->childrenFor($model), $discount, $model->channel !== ReservationChannel::MANUAL));
    }

    private function modify(ModifyReservationRequest $request, Reservation $model): Reservation
    {
        return $this->machine->modify($model, $request->stayFor($model), $request->adultsFor($model), $request->childrenFor($model),
            $this->priceFor($request, $model), $request->note(), self::userId($request));
    }

    public function approve(string $reservation): JsonResponse
    {
        return self::respond($this->machine->approve(Reservation::query()->findOrFail($reservation)));
    }

    public function reject(Request $request, string $reservation): JsonResponse
    {
        $validated = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return self::respond($this->machine->reject(Reservation::query()->findOrFail($reservation), self::text($validated['reason'] ?? null)));
    }

    /**
     * POST /admin/reservations/{id}/cancel: T17. `refund_cents` facoltativo (default proposto dalla
     * politica, massimo l'incassato online). La risposta riporta refund_status PENDING o NONE.
     */
    public function cancel(Request $request, string $reservation): JsonResponse
    {
        $validated = $request->validate([
            'refund_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $model = Reservation::query()->findOrFail($reservation);
        $refund = CancellationRefund::for($model, isset($validated['refund_cents']) ? (int) $validated['refund_cents'] : null);

        // Rimborso > 0: ri-autenticazione (08; decisione dopo la verifica di M4, ADR-0012 punto 24).
        if ($refund->amountCents > 0 && ! (new PanelSession($request->session()))->passwordConfirmedWithin((int) config('auth.password_timeout'))) {
            throw BusinessRuleViolation::make(403, 'REAUTH_REQUIRED');
        }

        return self::respond($this->machine->cancel($model, $refund, self::text($validated['reason'] ?? null), self::userId($request)));
    }

    public function releaseDates(string $reservation): JsonResponse
    {
        return self::respond($this->machine->releaseDates(Reservation::query()->findOrFail($reservation)));
    }

    /** POST /admin/reservations/{id}/offline-payments (AC17). */
    public function offlinePayment(Request $request, string $reservation, OfflinePaymentService $offline): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(OfflineMethod::class)],
        ]);
        $model = Reservation::query()->findOrFail($reservation);
        $offline->record($model, (int) $validated['amount_cents'], OfflineMethod::from((string) $validated['method']));

        return self::respond($model->refresh(), 201);
    }

    /**
     * POST /admin/reservations/{id}/recovery-link (T15): richiesta senza transazione unica
     * (`tenant:panel,steps`), perché la sessione di pagamento si apre fuori transazione (05).
     */
    public function recoveryLink(Request $request, string $reservation, CheckoutService $checkout, TenantContext $tenants): JsonResponse
    {
        $validated = $request->validate(['deadline_hours' => ['required', 'integer', 'between:1,24']]);
        $tenantId = (string) $request->attributes->get(WithTenantContext::TENANT_ATTRIBUTE);
        $session = $checkout->sendRecoveryLink($tenantId, $reservation, (int) $validated['deadline_hours']);

        return $tenants->run($tenantId, function () use ($reservation, $session): JsonResponse {
            $response = self::respond(Reservation::query()->findOrFail($reservation));
            $response->setData([
                ...(array) $response->getData(true),
                'recovery' => ['checkout_url' => $session->url, 'expires_at' => $session->expiresAt->toIso8601ZuluString()],
            ]);

            return $response;
        });
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function lock(string $id, Request $request): Reservation
    {
        $model = Reservation::query()->lockForUpdate()->findOrFail($id);
        IfMatch::assert($request, $model->version);

        return $model;
    }

    private static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    public static function respond(Reservation $reservation, int $status = 200): JsonResponse
    {
        $reservation->refresh()->loadMissing(['unit', 'guest']);

        return (new JsonResponse(ReservationResource::detail($reservation), $status))
            ->header('ETag', IfMatch::etag($reservation->version));
    }
}
