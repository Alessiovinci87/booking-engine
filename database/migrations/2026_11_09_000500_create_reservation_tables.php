<?php

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Pricing\Enums\PriceLineKind;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Reservations\Enums\ConfirmationKind;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Database\TenantTable;
use App\Domain\Tenancy\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Guest, Quote, Reservation, ReservationPriceLine, ReservationChange (02, 03, 04).
 * quotes.active_reservation_id e reservations.quote_id si referenziano a vicenda: la FK del preventivo
 * si aggiunge dopo la creazione delle prenotazioni.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantTable::create('guests', function (Blueprint $table): void {
            $table->text('first_name');
            $table->text('last_name');
            $table->text('email');
            $table->text('phone');
            $table->char('country_code', 2);
            $table->text('locale')->default(Locale::IT->value);
            $table->text('notes')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
        });
        TenantTable::enumCheck('guests', 'locale', Locale::class);

        TenantTable::create('quotes', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units');
            $table->integer('adults');
            $table->integer('children')->default(0);
            $table->text('guest_name')->nullable();
            $table->text('guest_email')->nullable();
            $table->text('guest_locale')->default(Locale::IT->value);
            $table->bigInteger('computed_total_cents');
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('final_total_cents');
            $table->jsonb('price_lines');
            $table->text('booking_mode_snapshot');
            $table->text('payment_mode_snapshot');
            $table->timestampTz('expires_at');
            $table->text('public_token_hash')->unique('quotes_public_token_hash_uniq');
            $table->text('status');
            $table->uuid('active_reservation_id')->nullable()->unique('quotes_active_reservation_uniq');
            $table->integer('checkout_attempts')->default(0);
            $table->integer('max_checkout_attempts')->default(5);
            $table->integer('version')->default(1);
            $table->index(['tenant_id', 'status', 'expires_at'], 'quotes_status_expires_idx');
        });
        TenantTable::stayColumn('quotes');
        TenantTable::enumCheck('quotes', 'status', QuoteStatus::class);
        TenantTable::enumCheck('quotes', 'guest_locale', Locale::class);
        TenantTable::enumCheck('quotes', 'booking_mode_snapshot', BookingMode::class);
        TenantTable::enumCheck('quotes', 'payment_mode_snapshot', PaymentMode::class);
        TenantTable::check('quotes', 'guests_valid', 'adults >= 1 AND children >= 0');
        TenantTable::check(
            'quotes',
            'amounts_valid',
            'computed_total_cents >= 0 AND discount_cents >= 0 AND discount_cents <= computed_total_cents '
            .'AND final_total_cents = computed_total_cents - discount_cents'
        );
        TenantTable::check(
            'quotes',
            'attempts_valid',
            'checkout_attempts >= 0 AND max_checkout_attempts >= 1 AND checkout_attempts <= max_checkout_attempts'
        );
        TenantTable::check('quotes', 'version_valid', 'version >= 1');

        TenantTable::create('reservations', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units');
            TenantTable::belongsTo($table, 'guest_id', 'guests');
            TenantTable::belongsTo($table, 'quote_id', 'quotes', nullable: true);
            // Codice pubblico di 8 caratteri, univoco (02).
            $table->char('code', 8)->unique('reservations_code_uniq');
            $table->text('channel');
            $table->integer('adults');
            $table->integer('children')->default(0);
            $table->text('status');
            $table->text('confirmation_kind')->nullable();
            $table->text('booking_mode_snapshot');
            $table->text('payment_mode_snapshot');
            $table->jsonb('cancellation_policy_snapshot');
            $table->bigInteger('contract_total_cents');
            $table->bigInteger('deposit_due_cents');
            $table->bigInteger('balance_due_cents');
            $table->char('currency', 3)->default('EUR');
            $table->text('guest_locale')->default(Locale::IT->value);
            $table->timestampTz('approval_deadline')->nullable();
            $table->timestampTz('decision_deadline')->nullable();
            $table->timestampTz('recovery_deadline')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('public_access_token_hash')->nullable()->unique('reservations_public_token_hash_uniq');
            $table->integer('version')->default(1);
            $table->index(['tenant_id', 'status'], 'reservations_status_idx');
        });
        TenantTable::stayColumn('reservations');
        TenantTable::enumCheck('reservations', 'status', ReservationStatus::class);
        TenantTable::enumCheck('reservations', 'channel', ReservationChannel::class);
        TenantTable::enumCheck('reservations', 'booking_mode_snapshot', BookingMode::class);
        TenantTable::enumCheck('reservations', 'payment_mode_snapshot', PaymentMode::class);
        TenantTable::enumCheck('reservations', 'guest_locale', Locale::class);
        DB::statement(
            'ALTER TABLE reservations ADD CONSTRAINT reservations_confirmation_kind_check '
            ."CHECK (confirmation_kind IS NULL OR confirmation_kind IN ('"
            .implode("', '", array_map(fn (ConfirmationKind $k): string => $k->value, ConfirmationKind::cases()))
            ."'))"
        );
        TenantTable::check('reservations', 'code_format', "code ~ '^[A-Z0-9]{8}$'");
        TenantTable::check('reservations', 'guests_valid', 'adults >= 1 AND children >= 0');
        TenantTable::check(
            'reservations',
            'amounts_valid',
            'contract_total_cents >= 0 AND deposit_due_cents >= 0 AND balance_due_cents >= 0 '
            .'AND deposit_due_cents + balance_due_cents = contract_total_cents'
        );
        TenantTable::check('reservations', 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        TenantTable::check('reservations', 'version_valid', 'version >= 1');
        // Una prenotazione confermata ha sempre il tipo di conferma (02, I11).
        TenantTable::check(
            'reservations',
            'confirmed_has_kind',
            "status NOT IN ('CONFIRMED', 'CANCELLED') OR confirmation_kind IS NOT NULL"
        );
        TenantTable::check('reservations', 'quote_channel', "quote_id IS NULL OR channel = 'QUOTE'");
        DB::statement('CREATE INDEX reservations_stay_idx ON reservations USING gist (tenant_id, unit_id, stay)');

        TenantTable::addForeign('quotes', 'active_reservation_id', 'reservations');

        TenantTable::create('reservation_price_lines', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'reservation_id', 'reservations');
            $table->text('kind');
            $table->date('night_date')->nullable();
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount_cents');
            $table->bigInteger('amount_cents');
            $table->integer('position');
            $table->unique(['tenant_id', 'reservation_id', 'position'], 'reservation_price_lines_position_uniq');
        });
        TenantTable::enumCheck('reservation_price_lines', 'kind', PriceLineKind::class);
        TenantTable::check('reservation_price_lines', 'quantity_valid', 'quantity >= 1');
        TenantTable::check(
            'reservation_price_lines',
            'night_date_consistent',
            "(kind IN ('NIGHT', 'EXTRA_GUEST')) = (night_date IS NOT NULL)"
        );
        TenantTable::check(
            'reservation_price_lines',
            'sign_valid',
            "(kind = 'DISCOUNT' AND amount_cents <= 0) OR (kind <> 'DISCOUNT' AND amount_cents >= 0)"
        );

        TenantTable::create('reservation_changes', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'reservation_id', 'reservations');
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users');
            $table->integer('previous_adults');
            $table->integer('new_adults');
            $table->integer('previous_children');
            $table->integer('new_children');
            $table->bigInteger('previous_contract_total_cents');
            $table->bigInteger('new_contract_total_cents');
            $table->text('note')->nullable();
            $table->timestampTz('occurred_at');
        });
        TenantTable::stayColumn('reservation_changes', 'previous_stay');
        TenantTable::stayColumn('reservation_changes', 'new_stay');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS quotes DROP CONSTRAINT IF EXISTS quotes_active_reservation_id_fk');
        foreach (['reservation_changes', 'reservation_price_lines', 'reservations', 'quotes', 'guests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
