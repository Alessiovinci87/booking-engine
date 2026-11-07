<?php

namespace App\Domain\Privacy;

use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationChange;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Offboarding\OffboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Diritti degli interessati (09 "Diritti degli interessati", 07 "Privacy", 6.3):
 *
 * - export: dati personali, prenotazioni, pagamenti e rimborsi dell'ospite (accesso, portabilità);
 * - erase: anonimizzazione immediata su tutte le copie che controlliamo (09 "Anonimizzazione
 *   effettiva", I22). Bloccata se la prenotazione è attiva e non ancora conclusa. Gli importi restano
 *   (categoria A e C), i dati identificativi no.
 *
 * Un record `guests` per prenotazione (02): l'export elenca anche gli altri record dello stesso
 * indirizzo email nel tenant, così il gestore può trattare tutte le prenotazioni della persona.
 * Esegue nel contesto del tenant (RLS); il registro di audit non riceve valori personali.
 */
final class GuestPrivacyService
{
    public const REMOVED = '[rimosso]';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
        private readonly OffboardingService $offboarding,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(string $guestId): array
    {
        $guest = Guest::query()->findOrFail($guestId);
        $reservations = Reservation::query()->where('guest_id', $guest->id)->orderBy('created_at')->get();
        $payments = Payment::query()->whereIn('reservation_id', $reservations->pluck('id'))->orderBy('created_at')->get();
        $refunds = Refund::query()->whereIn('payment_id', $payments->pluck('id'))->orderBy('created_at')->get();
        $quotes = Quote::query()->whereIn('id', $reservations->pluck('quote_id')->filter())->get()->keyBy('id');
        $units = Unit::query()->whereIn('id', $reservations->pluck('unit_id'))->get(['id', 'name', 'property_id'])->keyBy('id');
        $properties = Property::query()->whereIn('id', $units->pluck('property_id'))->pluck('name', 'id');

        $related = $guest->anonymized_at !== null ? collect() : Guest::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($guest->email)])
            ->whereKeyNot($guest->id)
            ->whereNull('anonymized_at')
            ->pluck('id');

        $this->audit->record('guest.exported', 'guest', $guest->id, ['reservations' => $reservations->count()]);

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601ZuluString(),
            'guest' => [
                'id' => $guest->id,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'email' => $guest->email,
                'phone' => $guest->phone,
                'country_code' => $guest->country_code,
                'locale' => $guest->locale->value,
                'notes' => $guest->notes,
                'anonymized_at' => $guest->anonymized_at?->toIso8601ZuluString(),
            ],
            'reservations' => $reservations->map(function (Reservation $r) use ($units, $properties, $payments, $refunds, $quotes): array {
                $unit = $units->get($r->unit_id);
                $own = $payments->where('reservation_id', $r->id);

                return [
                    'code' => $r->code,
                    'status' => $r->status->value,
                    'channel' => $r->channel->value,
                    'property' => $unit === null ? null : $properties->get($unit->property_id),
                    'unit' => $unit?->name,
                    'check_in' => $r->stay->checkIn->toDateString(),
                    'check_out' => $r->stay->checkOut->toDateString(),
                    'adults' => $r->adults,
                    'children' => $r->children,
                    'contract_total_cents' => $r->contract_total_cents,
                    'currency' => $r->currency,
                    'locale' => $r->guest_locale->value,
                    'created_at' => $r->created_at?->toIso8601ZuluString(),
                    'confirmed_at' => $r->confirmed_at?->toIso8601ZuluString(),
                    'cancelled_at' => $r->cancelled_at?->toIso8601ZuluString(),
                    'cancel_reason' => $r->cancel_reason,
                    'quote' => $r->quote_id === null ? null : (function () use ($quotes, $r): ?array {
                        $quote = $quotes->get($r->quote_id);

                        return $quote === null ? null : [
                            'guest_name' => $quote->guest_name,
                            'guest_email' => $quote->guest_email,
                            'discount_cents' => $quote->discount_cents,
                            'final_total_cents' => $quote->final_total_cents,
                        ];
                    })(),
                    'payments' => $own->map(fn (Payment $p): array => [
                        'purpose' => $p->purpose->value,
                        'status' => $p->status->value,
                        'amount_cents' => $p->amount_cents,
                        'captured_cents' => $p->captured_cents,
                        'method_offline' => $p->method_offline?->value,
                        'created_at' => $p->created_at?->toIso8601ZuluString(),
                    ])->values()->all(),
                    'refunds' => $refunds->whereIn('payment_id', $own->pluck('id'))->map(fn (Refund $f): array => [
                        'amount_cents' => $f->amount_cents,
                        'method' => $f->method->value,
                        'status' => $f->status->value,
                        'reason' => $f->reason,
                        'completed_at' => $f->completed_at?->toIso8601ZuluString(),
                    ])->values()->all(),
                ];
            })->values()->all(),
            'other_guest_records' => $related->values()->all(),
            'notes' => [
                'payments' => 'I dati della carta sono solo presso Stripe, nel conto della struttura (09).',
            ],
        ];
    }

    /**
     * Anonimizzazione di tutte le copie (09, I22). Idempotente: un ospite già anonimizzato resta così.
     */
    public function erase(string $guestId): Guest
    {
        $guest = Guest::query()->lockForUpdate()->findOrFail($guestId);

        if ($guest->anonymized_at !== null) {
            return $guest;
        }

        $reservations = Reservation::query()->where('guest_id', $guest->id)->lockForUpdate()->get();
        $today = CarbonImmutable::now()->startOfDay();
        $active = $reservations->first(fn (Reservation $r): bool => $r->status->occupies() && $r->stay->checkOut->greaterThan($today));

        if ($active !== null) {
            throw BusinessRuleViolation::make(409, 'GUEST_HAS_ACTIVE_RESERVATION', ['reservation_code' => $active->code]);
        }

        $reservationIds = $reservations->pluck('id');
        $paymentIds = Payment::query()->whereIn('reservation_id', $reservationIds)->pluck('id');
        $refundIds = Refund::query()->whereIn('payment_id', $paymentIds)->pluck('id');
        $quoteIds = $reservations->pluck('quote_id')->filter()->values();
        $occupancyIds = $this->inventory->occupancyIdsFor(array_values($reservationIds->map(fn ($id): string => (string) $id)->all()));
        $conflicts = InventoryConflict::query()->whereIn('occupancy_id', $occupancyIds)->get(['id', 'external_event_id', 'other_external_event_id']);
        $eventIds = $conflicts->pluck('external_event_id')->merge($conflicts->pluck('other_external_event_id'))->filter()->unique()->values();

        // Note delle occupazioni: solo i blocchi manuali ne hanno una (vincolo
        // occupancies_kind_consistent), mai l'occupazione di una prenotazione: nulla da togliere qui.

        // guests: valori neutri, paese mantenuto (09).
        $guest->first_name = self::REMOVED;
        $guest->last_name = self::REMOVED;
        $guest->email = 'rimosso-'.$guest->id.'@anonimo.invalid';
        $guest->phone = self::REMOVED;
        $guest->notes = null;
        $guest->anonymized_at = CarbonImmutable::now();
        $guest->saveQuietly();

        $counts = [
            'reservations' => $reservations->count(),
            'cancel_reasons' => $this->redact(Reservation::query()->whereIn('id', $reservationIds), 'cancel_reason'),
            'change_notes' => $this->redact(ReservationChange::query()->whereIn('reservation_id', $reservationIds), 'note'),
            'conflict_notes' => $this->redact(InventoryConflict::query()->whereIn('id', $conflicts->pluck('id')), 'resolution_notes'),
            'external_summaries' => $this->redact(ExternalCalendarEvent::query()->whereIn('id', $eventIds), 'summary_redacted'),
            'refund_reasons' => $this->redact(Refund::query()->whereIn('id', $refundIds), 'reason'),
            'quotes' => Quote::query()->whereIn('id', $quoteIds)->update(['guest_name' => self::REMOVED, 'guest_email' => null]),
            'outbox_payloads' => $this->purgeOutbox($reservationIds, $refundIds, $quoteIds),
            // Export completi ancora scaricabili creati dopo l'ospite: lo contengono (09, I22; 7.1).
            'tenant_exports' => $this->offboarding->expireExportsContaining(CarbonImmutable::parse($guest->created_at)),
        ];

        $this->audit->record('guest.erased', 'guest', $guest->id, $counts);

        return $guest;
    }

    /**
     * Dati dell'ospite dei preventivi non accettati (categoria D di 09, job di conservazione 7.2): nome
     * sostituito ed email tolta, come nell'anonimizzazione; payload dell'outbox svuotati.
     *
     * @param  list<string>  $quoteIds
     */
    public function anonymizeQuotes(array $quoteIds): int
    {
        if ($quoteIds === []) {
            return 0;
        }

        $updated = Quote::query()->whereIn('id', $quoteIds)->update(['guest_name' => self::REMOVED, 'guest_email' => null]);
        $this->purgeOutbox(collect(), collect(), collect($quoteIds));

        return $updated;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function redact(Builder $query, string $column): int
    {
        return $query->whereNotNull($column)->update([$column => self::REMOVED]);
    }

    /**
     * Payload dell'outbox che citano prenotazioni, rimborsi o preventivi dell'ospite: svuotati subito
     * (09). Contengono solo identificativi, ma così nessuna email parte più per questo ospite.
     *
     * @param  Collection<int, string>  $reservationIds
     * @param  Collection<int, string>  $refundIds
     * @param  Collection<int, string>  $quoteIds
     */
    private function purgeOutbox(Collection $reservationIds, Collection $refundIds, Collection $quoteIds): int
    {
        return OutboxMessage::query()
            ->whereNull('payload_purged_at')
            ->where(function (Builder $q) use ($reservationIds, $refundIds, $quoteIds): void {
                $q->whereIn('payload->reservation_id', $reservationIds->all())
                    ->orWhereIn('payload->refund_id', $refundIds->all())
                    ->orWhereIn('payload->quote_id', $quoteIds->all());
            })
            ->update(['payload' => null, 'payload_purged_at' => CarbonImmutable::now()]);
    }
}
