<?php

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Inventory\Enums\SyncHealth;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Tenancy\Database\TenantTable;
use App\Domain\Tenancy\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Property, PropertyTranslation, CancellationPolicy, Unit, UnitTranslation, UnitPhoto (02).
 *
 * Politica di cancellazione (ADR-0009): properties.default_cancellation_policy_id (obbligatoria per
 * pubblicare) e units.cancellation_policy_id (facoltativa), entrambe verso una politica della stessa
 * struttura: FK composite (tenant_id, property_id, policy_id). I testi IT/EN sono standard, nei file
 * di lingua (lang/<locale>/cancellation.php), e non si salvano nel database.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantTable::create('properties', function (Blueprint $table): void {
            $table->text('name');
            $table->text('slug');
            // Indirizzo strutturato.
            $table->text('address_line1')->nullable();
            $table->text('address_line2')->nullable();
            $table->text('postal_code')->nullable();
            $table->text('city')->nullable();
            $table->text('province')->nullable();
            $table->char('country_code', 2)->default('IT');
            $table->text('cin_code')->nullable();
            $table->text('timezone')->default('Europe/Rome');
            $table->text('contact_email')->nullable();
            $table->text('contact_phone')->nullable();
            $table->time('checkin_time')->nullable();
            $table->time('checkout_time')->nullable();
            $table->text('privacy_policy_url')->nullable();
            $table->text('terms_url')->nullable();
            $table->integer('ota_commission_bps')->default(1500);
            // FK aggiunta dopo cancellation_policies (riferimento circolare).
            $table->uuid('default_cancellation_policy_id')->nullable();
            $table->text('status')->default(PropertyStatus::DRAFT->value);
            $table->unique(['tenant_id', 'slug'], 'properties_tenant_slug_uniq');
        });
        TenantTable::enumCheck('properties', 'status', PropertyStatus::class);
        TenantTable::check('properties', 'commission_range', 'ota_commission_bps BETWEEN 0 AND 9999');
        TenantTable::check('properties', 'slug_format', "slug ~ '^[a-z0-9]([a-z0-9-]{0,98}[a-z0-9])?$'");

        TenantTable::create('property_translations', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'property_id', 'properties', index: false);
            $table->text('locale');
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('house_rules')->nullable();
            $table->unique(['tenant_id', 'property_id', 'locale'], 'property_translations_locale_uniq');
        });
        TenantTable::enumCheck('property_translations', 'locale', Locale::class);

        TenantTable::create('cancellation_policies', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'property_id', 'properties', index: false);
            $table->text('code');
            $table->unique(['tenant_id', 'property_id', 'code'], 'cancellation_policies_code_uniq');
            // Destinazione delle FK che vincolano la politica alla stessa struttura.
            $table->unique(['tenant_id', 'property_id', 'id'], 'cancellation_policies_property_scope_uniq');
        });
        TenantTable::enumCheck('cancellation_policies', 'code', CancellationPolicyCode::class);
        DB::statement(
            'ALTER TABLE properties ADD CONSTRAINT properties_default_cancellation_policy_id_fk '
            .'FOREIGN KEY (tenant_id, id, default_cancellation_policy_id) '
            .'REFERENCES cancellation_policies (tenant_id, property_id, id)'
        );
        DB::statement(
            'CREATE INDEX properties_default_cancellation_policy_id_idx ON properties (tenant_id, default_cancellation_policy_id)'
        );

        TenantTable::create('units', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'property_id', 'properties');
            $table->text('name');
            $table->integer('base_occupancy');
            $table->integer('max_occupancy');
            $table->text('booking_mode')->default(BookingMode::REQUEST->value);
            $table->text('payment_mode')->default(PaymentMode::DEPOSIT_30->value);
            $table->integer('min_nights')->default(1);
            $table->bigInteger('cleaning_fee_cents')->default(0);
            $table->bigInteger('extra_guest_fee_cents')->default(0);
            $table->text('sync_health')->default(SyncHealth::NONE->value);
            $table->boolean('sales_suspended')->default(false);
            $table->text('status')->default(UnitStatus::ACTIVE->value);
            // Facoltativa: senza, vale quella predefinita della struttura (ADR-0009).
            $table->uuid('cancellation_policy_id')->nullable();
            $table->foreign(['tenant_id', 'property_id', 'cancellation_policy_id'], 'units_cancellation_policy_id_fk')
                ->references(['tenant_id', 'property_id', 'id'])
                ->on('cancellation_policies');
            $table->index(['tenant_id', 'cancellation_policy_id'], 'units_cancellation_policy_id_idx');
        });
        TenantTable::enumCheck('units', 'booking_mode', BookingMode::class);
        TenantTable::enumCheck('units', 'payment_mode', PaymentMode::class);
        TenantTable::enumCheck('units', 'sync_health', SyncHealth::class);
        TenantTable::enumCheck('units', 'status', UnitStatus::class);
        TenantTable::check('units', 'occupancy_valid', 'base_occupancy >= 1 AND max_occupancy >= base_occupancy');
        TenantTable::check('units', 'min_nights_valid', 'min_nights >= 1');
        TenantTable::check('units', 'fees_valid', 'cleaning_fee_cents >= 0 AND extra_guest_fee_cents >= 0');

        TenantTable::create('unit_translations', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units', index: false);
            $table->text('locale');
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('house_rules')->nullable();
            $table->unique(['tenant_id', 'unit_id', 'locale'], 'unit_translations_locale_uniq');
        });
        TenantTable::enumCheck('unit_translations', 'locale', Locale::class);

        TenantTable::create('unit_photos', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units');
            $table->text('disk');
            $table->text('path');
            $table->text('mime_type');
            $table->bigInteger('size_bytes');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('position')->default(0);
            $table->unique(['tenant_id', 'path'], 'unit_photos_path_uniq');
        });
        TenantTable::check('unit_photos', 'size_valid', 'size_bytes > 0 AND position >= 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS properties DROP CONSTRAINT IF EXISTS properties_default_cancellation_policy_id_fk');

        foreach (['unit_photos', 'unit_translations', 'units', 'cancellation_policies', 'property_translations', 'properties'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
