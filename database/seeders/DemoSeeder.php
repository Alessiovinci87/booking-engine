<?php

namespace Database\Seeders;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\PropertyTranslation;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\Models\UnitTranslation;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Models\SeasonRule;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioning;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dati demo (11, attività 1.8 e 2.8): tenant "demo-alghero" con una struttura finta e due unità,
 * ciascuna con tariffa base e due stagioni (alta stagione e Ferragosto) per l'anteprima prezzo.
 * Tutti i dati sono inventati; l'email usa il dominio riservato example.test. Mai in produzione.
 *
 * Il tenant ha un account Stripe finto (acct_demo_…, livemode false, incassi e bonifici abilitati)
 * perché la guardia dei pagamenti di 07 (ADR-0010) permetta di pubblicare le strutture demo. Non
 * corrisponde a nessun account reale e nessuna chiamata a Stripe lo usa.
 *
 * La password del proprietario è casuale e si mostra una sola volta; la MFA si configura al primo
 * accesso come per ogni altro proprietario.
 */
final class DemoSeeder extends Seeder
{
    public const TENANT_SLUG = 'demo-alghero';

    public const OWNER_EMAIL = 'proprietario.demo@example.test';

    public const STRIPE_ACCOUNT_PREFIX = 'acct_demo_';

    public function run(TenantProvisioning $provisioning, TenantContext $tenantContext): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Il seeder demo non gira in produzione.');
        }

        if ($provisioning->slugIsTaken(self::TENANT_SLUG)) {
            $this->command?->info(__('demo.already_present', ['slug' => self::TENANT_SLUG]));

            return;
        }

        $password = Str::password(24);
        $created = $provisioning->create(self::TENANT_SLUG, 'Demo Alghero', self::OWNER_EMAIL, $password);

        $tenantContext->run($created['tenant']->id, function (): void {
            $this->stripeAccount();
            $property = $this->property();

            $this->unit($property, base: 2, max: 3, nightly: 9500, cleaning: 4000, names: [
                Locale::IT->value => ['Bilocale Vista Mare', 'Bilocale luminoso con terrazzo sul mare, a due passi dal centro storico.'],
                Locale::EN->value => ['Sea View Apartment', 'Bright one-bedroom apartment with a sea-view terrace, close to the old town.'],
            ]);

            $this->unit($property, base: 4, max: 6, nightly: 14500, cleaning: 6000, names: [
                Locale::IT->value => ['Trilocale Giardino', 'Trilocale al piano terra con giardino privato, ideale per famiglie.'],
                Locale::EN->value => ['Garden Apartment', 'Ground-floor two-bedroom apartment with a private garden, ideal for families.'],
            ]);
        });

        $this->command?->warn(__('demo.created', ['slug' => self::TENANT_SLUG, 'email' => self::OWNER_EMAIL]));
        $this->command?->line(__('demo.password_once', ['password' => $password]));
    }

    private function stripeAccount(): void
    {
        (new StripeAccount)->forceFill([
            'stripe_account_id' => self::STRIPE_ACCOUNT_PREFIX.Str::lower(Str::random(16)),
            'livemode' => false,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
        ])->save();
    }

    private function property(): Property
    {
        $property = new Property;
        $property->forceFill([
            'name' => 'Casa Demo Alghero',
            'slug' => 'casa-demo-alghero',
            'address_line1' => 'Via Inventata 1',
            'postal_code' => '07041',
            'city' => 'Alghero',
            'province' => 'SS',
            'country_code' => 'IT',
            'timezone' => 'Europe/Rome',
            'contact_email' => 'info.demo@example.test',
            'privacy_policy_url' => 'https://demo.example.test/privacy',
            'terms_url' => 'https://demo.example.test/condizioni',
            'checkin_time' => '15:00',
            'checkout_time' => '10:00',
        ])->save();

        $translations = [
            Locale::IT->value => ['Casa Demo Alghero', 'Struttura dimostrativa con dati inventati, vicino al mare di Alghero.', 'Niente feste. Silenzio dalle 22 alle 8.'],
            Locale::EN->value => ['Casa Demo Alghero', 'Demo property with made-up data, close to the sea in Alghero.', 'No parties. Quiet hours from 10 pm to 8 am.'],
        ];

        foreach ($translations as $locale => [$name, $description, $rules]) {
            (new PropertyTranslation)->forceFill([
                'property_id' => $property->id,
                'locale' => $locale,
                'name' => $name,
                'description' => $description,
                'house_rules' => $rules,
            ])->save();
        }

        // Politiche standard (testi nei file di lingua); predefinita della struttura: flessibile (ADR-0009).
        $policies = [];

        foreach (CancellationPolicyCode::cases() as $code) {
            $policy = new CancellationPolicy;
            $policy->forceFill(['property_id' => $property->id, 'code' => $code])->save();
            $policies[$code->value] = $policy;
        }

        $property->forceFill(['default_cancellation_policy_id' => $policies[CancellationPolicyCode::FLEXIBLE_7D->value]->id])->save();

        return $property;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $names
     */
    private function unit(Property $property, int $base, int $max, int $nightly, int $cleaning, array $names): void
    {
        $unit = new Unit;
        $unit->forceFill([
            'property_id' => $property->id,
            'name' => $names[Locale::IT->value][0],
            'base_occupancy' => $base,
            'max_occupancy' => $max,
            'min_nights' => 2,
            'cleaning_fee_cents' => $cleaning,
            'extra_guest_fee_cents' => 1500,
        ])->save();

        foreach ($names as $locale => [$name, $description]) {
            (new UnitTranslation)->forceFill([
                'unit_id' => $unit->id,
                'locale' => $locale,
                'name' => $name,
                'description' => $description,
            ])->save();
        }

        $plan = new RatePlan;
        $plan->forceFill([
            'unit_id' => $unit->id,
            'name' => 'Tariffa base',
            'base_nightly_cents' => $nightly,
            'currency' => 'EUR',
        ])->save();

        // Alta stagione +40% (luglio-agosto, minimo 5 notti) e Ferragosto +80% con priorità maggiore.
        foreach ([
            ['Alta stagione', '2027-07-01', '2027-09-01', intdiv($nightly * 140, 100), 10, 5],
            ['Ferragosto', '2027-08-13', '2027-08-18', intdiv($nightly * 180, 100), 20, null],
        ] as [$name, $from, $to, $cents, $priority, $minNights]) {
            (new SeasonRule)->forceFill([
                'rate_plan_id' => $plan->id,
                'name' => $name,
                'date_range' => StayRange::of($from, $to),
                'nightly_cents' => $cents,
                'priority' => $priority,
                'min_nights' => $minNights,
            ])->save();
        }
    }
}
