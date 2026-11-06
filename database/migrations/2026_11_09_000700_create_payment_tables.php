<?php

use App\Domain\Payments\Enums\CaptureMethod;
use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundMethod;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Enums\WebhookEventStatus;
use App\Domain\Tenancy\Database\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * StripeAccount, Payment, PaymentEvent, Refund (02, 05) e WebhookEvent (tabella globale, 02).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantTable::create('stripe_accounts', function (Blueprint $table): void {
            // Un account per tenant; stripe_account_id è la chiave globale di ricerca dei webhook (02).
            $table->unique('tenant_id', 'stripe_accounts_tenant_uniq');
            $table->text('stripe_account_id')->unique('stripe_accounts_stripe_id_uniq');
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->boolean('livemode')->default(false);
            $table->timestampTz('last_synced_at')->nullable();
        });

        TenantTable::create('payments', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'reservation_id', 'reservations');
            $table->text('purpose');
            $table->bigInteger('amount_cents');
            $table->text('capture_method');
            $table->text('status');
            $table->text('stripe_account_id')->nullable();
            $table->text('checkout_session_id')->nullable()->unique('payments_checkout_session_uniq');
            $table->timestampTz('checkout_expires_at')->nullable();
            $table->text('payment_intent_id')->nullable()->unique('payments_payment_intent_uniq');
            $table->timestampTz('capture_before')->nullable();
            $table->bigInteger('captured_cents')->default(0);
            $table->bigInteger('fee_cents')->default(0);
            $table->text('method_offline')->nullable();
            $table->text('failure_code')->nullable();
            $table->index(['tenant_id', 'status'], 'payments_status_idx');
        });
        TenantTable::enumCheck('payments', 'purpose', PaymentPurpose::class);
        TenantTable::enumCheck('payments', 'capture_method', CaptureMethod::class);
        TenantTable::enumCheck('payments', 'status', PaymentStatus::class);
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_method_offline_check '
            ."CHECK (method_offline IS NULL OR method_offline IN ('"
            .implode("', '", array_map(fn (OfflineMethod $m): string => $m->value, OfflineMethod::cases()))
            ."'))"
        );
        TenantTable::check('payments', 'amounts_valid', 'amount_cents > 0 AND captured_cents >= 0 AND fee_cents >= 0');
        // Il metodo offline c'è se e solo se il pagamento è OFFLINE (05, flusso D).
        TenantTable::check('payments', 'offline_consistent', "(purpose = 'OFFLINE') = (method_offline IS NOT NULL)");

        TenantTable::create('payment_events', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'payment_id', 'payments');
            $table->text('type');
            $table->bigInteger('amount_cents')->nullable();
            $table->text('stripe_object_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->text('payload_hash')->nullable();
        });

        TenantTable::create('refunds', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'payment_id', 'payments');
            $table->bigInteger('amount_cents');
            $table->text('method');
            $table->text('reason')->nullable();
            $table->text('stripe_refund_id')->nullable()->unique('refunds_stripe_refund_uniq');
            $table->text('status');
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->timestampTz('completed_at')->nullable();
            $table->index(['tenant_id', 'status', 'next_attempt_at'], 'refunds_retry_idx');
        });
        TenantTable::enumCheck('refunds', 'method', RefundMethod::class);
        TenantTable::enumCheck('refunds', 'status', RefundStatus::class);
        TenantTable::check('refunds', 'amounts_valid', 'amount_cents > 0 AND attempts >= 0');

        // Tabella globale: il tenant si ricava da event.account dopo la verifica (05).
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('provider');
            $table->text('event_id');
            $table->text('type');
            $table->text('account_id')->nullable();
            $table->boolean('livemode')->default(false);
            $table->text('payload_hash');
            $table->text('status')->default(WebhookEventStatus::RECEIVED->value);
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'event_id'], 'webhook_events_provider_event_uniq');
        });
        TenantTable::enumCheck('webhook_events', 'status', WebhookEventStatus::class);
    }

    public function down(): void
    {
        foreach (['webhook_events', 'refunds', 'payment_events', 'payments', 'stripe_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
