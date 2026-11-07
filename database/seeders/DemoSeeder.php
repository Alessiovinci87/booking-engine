<?php

namespace Database\Seeders;

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\PropertyTranslation;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\Models\UnitTranslation;
use App\Domain\Inventory\PropertySetupService;
use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Payments\SimulatedPaymentEvents;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Models\SeasonRule;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\GuestDetails;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\NewReservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioning;
use Carbon\CarbonImmutable;
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
 * Da M3 (attività 3.8) la struttura è pubblicata, il Bilocale è a prenotazione immediata e il
 * Trilocale su richiesta, e ci sono prenotazioni di esempio in vari stati create con i flussi veri
 * (macchina a stati, checkout con il gateway finto, pagamenti offline) e ospiti inventati. Le date
 * partono dal giorno dell'esecuzione, così il calendario del pannello le mostra subito.
 *
 * La password del proprietario è casuale e si mostra una sola volta; la MFA si configura al primo
 * accesso come per ogni altro proprietario.
 */
final class DemoSeeder extends Seeder
{
    public const TENANT_SLUG = 'demo-alghero';

    public const PROPERTY_SLUG = 'casa-demo-alghero';

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
        $tenantId = $created['tenant']->id;

        $setup = $tenantContext->run($tenantId, function (): array {
            $accountId = $this->stripeAccount();
            $property = $this->property();

            $seaView = $this->unit($property, BookingMode::INSTANT, base: 2, max: 3, nightly: 9500, cleaning: 4000, names: [
                Locale::IT->value => ['Bilocale Vista Mare', 'Bilocale luminoso con terrazzo sul mare, a due passi dal centro storico.'],
                Locale::EN->value => ['Sea View Apartment', 'Bright one-bedroom apartment with a sea-view terrace, close to the old town.'],
            ]);

            $garden = $this->unit($property, BookingMode::REQUEST, base: 4, max: 6, nightly: 14500, cleaning: 6000, names: [
                Locale::IT->value => ['Trilocale Giardino', 'Trilocale al piano terra con giardino privato, ideale per famiglie.'],
                Locale::EN->value => ['Garden Apartment', 'Ground-floor two-bedroom apartment with a private garden, ideal for families.'],
            ]);

            app(PropertySetupService::class)->publish($property->refresh());

            return ['account' => $accountId, 'sea_view' => $seaView, 'garden' => $garden];
        });

        $this->reservations($tenantId, $setup['account'], $setup['sea_view'], $setup['garden'], $tenantContext);

        $this->command?->warn(__('demo.created', ['slug' => self::TENANT_SLUG, 'email' => self::OWNER_EMAIL]));
        $this->command?->line(__('demo.password_once', ['password' => $password]));
    }

    private function stripeAccount(): string
    {
        $accountId = self::STRIPE_ACCOUNT_PREFIX.Str::lower(Str::random(16));

        (new StripeAccount)->forceFill([
            'stripe_account_id' => $accountId,
            'livemode' => false,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
        ])->save();

        return $accountId;
    }

    private function property(): Property
    {
        $property = new Property;
        $property->forceFill([
            'name' => 'Casa Demo Alghero',
            'slug' => self::PROPERTY_SLUG,
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
    private function unit(Property $property, BookingMode $mode, int $base, int $max, int $nightly, int $cleaning, array $names): string
    {
        $unit = new Unit;
        $unit->forceFill([
            'property_id' => $property->id,
            'name' => $names[Locale::IT->value][0],
            'booking_mode' => $mode,
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

        return $unit->id;
    }

    /**
     * Prenotazioni di esempio: online (pagamento simulato riuscito, rifiutato o ancora aperto),
     * richieste da approvare o rifiutate, manuali con e senza pagamento offline, una cancellata e un
     * blocco manuale. Ospiti inventati, email @example.test.
     */
    private function reservations(string $tenantId, string $accountId, string $seaView, string $garden, TenantContext $tenants): void
    {
        $day = fn (int $days): string => CarbonImmutable::today()->addDays($days)->toDateString();
        $stay = fn (int $from, int $nights): StayRange => StayRange::of($day($from), $day($from + $nights));
        $guest = fn (string $first, string $last, string $country, Locale $locale, string $phone): GuestDetails => new GuestDetails(
            $first, $last, Str::lower($first.'.'.$last).'@example.test', $phone, $country, $locale,
        );
        $machine = app(ReservationStateMachine::class);

        // Online, Bilocale (immediata): confermata, ancora in pagamento, pagamento rifiutato.
        $this->online($tenantId, $accountId, $seaView, $stay(5, 3), 2, $guest('Giulia', 'Ferri', 'IT', Locale::IT, '+39 333 000 0101'), FakeCustomerAction::PAY);
        $this->online($tenantId, $accountId, $seaView, $stay(12, 3), 2, $guest('Luca', 'Moretti', 'IT', Locale::IT, '+39 333 000 0102'), null);
        $this->online($tenantId, $accountId, $seaView, $stay(40, 3), 2, $guest('Sofia', 'Bianchi', 'IT', Locale::IT, '+39 333 000 0103'), FakeCustomerAction::DECLINE);

        // Su richiesta, Trilocale: una da approvare e una rifiutata (autorizzazione annullata).
        $this->online($tenantId, $accountId, $garden, $stay(7, 4), 4, $guest('Hans', 'Weber', 'DE', Locale::EN, '+49 151 0000 0104'), FakeCustomerAction::PAY);
        $rejected = $this->online($tenantId, $accountId, $garden, $stay(14, 3), 3, $guest('Elena', 'Pinna', 'IT', Locale::IT, '+39 333 000 0105'), FakeCustomerAction::PAY);
        $tenants->run($tenantId, fn () => $machine->reject(Reservation::query()->findOrFail($rejected), 'Date non disponibili per lavori'));

        $tenants->run($tenantId, function () use ($seaView, $garden, $stay, $guest, $machine, $day): void {
            // Manuali: non pagata, con pagamento offline parziale, cancellata.
            $this->manual($seaView, $stay(20, 4), 2, $guest('Marta', 'Sanna', 'IT', Locale::IT, '+39 333 000 0106'));
            $this->manual($seaView, $stay(28, 3), 2, $guest('Peter', 'Novak', 'SI', Locale::EN, '+386 40 000 107'), 10000, OfflineMethod::BANK_TRANSFER);
            $this->manual($garden, $stay(25, 5), 5, $guest('Marco', 'Deiana', 'IT', Locale::IT, '+39 333 000 0108'), 20000, OfflineMethod::CASH);
            $cancelled = Reservation::query()->findOrFail($this->manual($seaView, $stay(35, 3), 2, $guest('Anna', 'Rossi', 'IT', Locale::IT, '+39 333 000 0109')));
            $machine->cancel($cancelled, CancellationRefund::for($cancelled, 0), 'Annullata su richiesta', null);

            // Blocco manuale per manutenzione.
            app(InventoryService::class)->acquireBlock(Unit::query()->findOrFail($seaView), StayRange::of($day(45), $day(48)), 'Manutenzione impianto');
        });
    }

    /** Prenotazione dalla pagina ospite (T1) e, se indicata, azione sulla pagina di pagamento simulato. */
    private function online(string $tenantId, string $accountId, string $unitId, StayRange $stay, int $adults, GuestDetails $guest, ?FakeCustomerAction $action): string
    {
        $tenants = app(TenantContext::class);
        $data = $tenants->run($tenantId, function () use ($unitId, $stay, $adults, $guest): NewReservation {
            $unit = Unit::query()->findOrFail($unitId);
            $price = app(PriceQuoteService::class)->quote($unit, new PriceRequest($stay, $adults));

            return new NewReservation($unit, $stay, $adults, 0, $guest, ReservationChannel::DIRECT_WEB, $price);
        });
        $reservationId = app(CheckoutService::class)->start($tenantId, $data)['created']->reservation->id;

        if ($action !== null) {
            if (config('payments.gateway') !== 'fake') {
                throw new RuntimeException('Il seeder demo richiede PAYMENT_GATEWAY=fake.');
            }

            $gateway = app(PaymentGateway::class);
            $sessionId = (string) $tenants->run($tenantId, fn () => Payment::query()
                ->where('reservation_id', $reservationId)->orderByDesc('created_at')->value('checkout_session_id'));
            app(SimulatedPaymentEvents::class)->dispatch($accountId, $gateway->simulateCustomer($sessionId, $action));
        }

        return $reservationId;
    }

    /** Prenotazione manuale del gestore (T2), con pagamento offline facoltativo. */
    private function manual(string $unitId, StayRange $stay, int $adults, GuestDetails $guest, ?int $offlineCents = null, ?OfflineMethod $method = null): string
    {
        $unit = Unit::query()->findOrFail($unitId);
        $price = app(PriceQuoteService::class)->quote($unit, new PriceRequest($stay, $adults, 0, 0, false));

        return app(ReservationStateMachine::class)
            ->createManual(new NewReservation($unit, $stay, $adults, 0, $guest, ReservationChannel::MANUAL, $price), $offlineCents, $method)
            ->reservation->id;
    }
}
