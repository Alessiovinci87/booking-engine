/**
 * API admin di strutture, unità, foto, tariffe, stagioni e anteprima prezzo (07). Importi sempre in
 * centesimi interi: il pannello non calcola prezzi, mostra quelli restituiti dal server.
 */
import { apiRequest } from './client';

export type LocaleCode = 'it' | 'en';

export interface TranslationTexts {
    name: string;
    description: string | null;
    house_rules: string | null;
}

export type Translations = Record<LocaleCode, TranslationTexts | null>;

export interface Money {
    amount_cents: number;
    currency: string;
}

export interface Property {
    id: string;
    name: string;
    slug: string;
    status: 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';
    address_line1: string | null;
    address_line2: string | null;
    postal_code: string | null;
    city: string | null;
    province: string | null;
    country_code: string;
    cin_code: string | null;
    timezone: string;
    contact_email: string | null;
    contact_phone: string | null;
    checkin_time: string | null;
    checkout_time: string | null;
    privacy_policy_url: string | null;
    terms_url: string | null;
    ota_commission_bps: number;
    default_cancellation_policy_id: string | null;
    cancellation_policies: { id: string; code: string }[];
    translations: Translations;
    version: number;
}

export type BookingMode = 'REQUEST' | 'INSTANT';
export type PaymentMode = 'DEPOSIT_30' | 'FULL';

export interface Unit {
    id: string;
    property_id: string;
    name: string;
    base_occupancy: number;
    max_occupancy: number;
    booking_mode: BookingMode;
    payment_mode: PaymentMode;
    min_nights: number;
    cleaning_fee: Money;
    extra_guest_fee: Money;
    cancellation_policy_id: string | null;
    sync_health: string;
    sales_suspended: boolean;
    status: 'ACTIVE' | 'INACTIVE';
    translations: Translations;
    version: number;
}

export interface UnitPhoto {
    id: string;
    position: number;
    is_cover: boolean;
    width: number | null;
    height: number | null;
    urls: { large: string; thumbnail: string };
}

export interface RatePlan {
    id: string;
    unit_id: string;
    name: string;
    base_nightly: Money;
    version: number;
}

export interface Season {
    id: string;
    name: string;
    first_night: string;
    last_night: string;
    nightly: Money;
    priority: number;
    min_nights: number | null;
    version: number;
}

export interface SeasonWarning {
    code: 'SEASON_OVERLAP_SAME_PRIORITY';
    priority: number;
    seasons: { id: string; name: string; first_night: string; last_night: string }[];
}

export interface PriceLine {
    kind: 'NIGHT' | 'EXTRA_GUEST' | 'CLEANING' | 'DISCOUNT';
    date: string | null;
    quantity: number;
    unit_amount: Money;
    amount: Money;
}

export interface PriceQuote {
    nights: number;
    guests: number;
    required_min_nights: number;
    lines: PriceLine[];
    subtotal: Money;
    discount: Money;
    total: Money;
    deposit: Money;
    balance: Money;
    payment_mode: PaymentMode;
    booking_mode: BookingMode;
    cancellation_policy: { code: string; title: string; text: string } | null;
}

export type PropertyInput = Partial<Omit<Property, 'id' | 'status' | 'version' | 'cancellation_policies' | 'translations'>> & {
    translations?: Partial<Record<LocaleCode, TranslationTexts | null>>;
};

export interface UnitInput {
    name?: string;
    base_occupancy?: number;
    max_occupancy?: number;
    booking_mode?: BookingMode;
    payment_mode?: PaymentMode;
    status?: Unit['status'];
    min_nights?: number;
    cleaning_fee_cents?: number;
    extra_guest_fee_cents?: number;
    cancellation_policy_id?: string | null;
    base_nightly_cents?: number;
    translations?: Partial<Record<LocaleCode, TranslationTexts | null>>;
}

export interface SeasonInput {
    name?: string;
    first_night?: string;
    last_night?: string;
    nightly_cents?: number;
    priority?: number;
    min_nights?: number | null;
}

export const adminApi = {
    properties: () => apiRequest<{ data: Property[] }>('GET', '/properties'),
    property: (id: string) => apiRequest<Property>('GET', `/properties/${id}`),
    createProperty: (input: PropertyInput) => apiRequest<Property>('POST', '/properties', input),
    updateProperty: (id: string, version: number, input: PropertyInput) =>
        apiRequest<Property>('PATCH', `/properties/${id}`, input, { version }),
    publishProperty: (id: string) => apiRequest<Property>('POST', `/properties/${id}/publish`),

    units: (propertyId: string) => apiRequest<{ data: Unit[] }>('GET', `/properties/${propertyId}/units`),
    unit: (id: string) => apiRequest<Unit>('GET', `/units/${id}`),
    createUnit: (propertyId: string, input: UnitInput) => apiRequest<Unit>('POST', `/properties/${propertyId}/units`, input),
    updateUnit: (id: string, version: number, input: UnitInput) => apiRequest<Unit>('PATCH', `/units/${id}`, input, { version }),
    setSalesSuspended: (id: string, version: number, suspended: boolean) =>
        apiRequest<Unit>('PATCH', `/units/${id}/sales`, { sales_suspended: suspended }, { version }),

    photos: (unitId: string) => apiRequest<{ data: UnitPhoto[] }>('GET', `/units/${unitId}/photos`),
    uploadPhoto: (unitId: string, file: File) => {
        const form = new FormData();
        form.append('photo', file);

        return apiRequest<UnitPhoto>('POST', `/units/${unitId}/photos`, form);
    },
    reorderPhotos: (unitId: string, ids: string[]) =>
        apiRequest<{ data: UnitPhoto[] }>('PUT', `/units/${unitId}/photos/order`, { photo_ids: ids }),
    deletePhoto: (unitId: string, photoId: string) => apiRequest<void>('DELETE', `/units/${unitId}/photos/${photoId}`),

    ratePlan: (unitId: string) => apiRequest<RatePlan>('GET', `/units/${unitId}/rate-plan`),
    updateRatePlan: (unitId: string, version: number, baseNightlyCents: number) =>
        apiRequest<RatePlan>('PATCH', `/units/${unitId}/rate-plan`, { base_nightly_cents: baseNightlyCents }, { version }),
    seasons: (unitId: string) => apiRequest<{ data: Season[]; warnings: SeasonWarning[] }>('GET', `/units/${unitId}/seasons`),
    createSeason: (unitId: string, input: SeasonInput) =>
        apiRequest<Season & { warnings: SeasonWarning[] }>('POST', `/units/${unitId}/seasons`, input),
    updateSeason: (unitId: string, season: Season, input: SeasonInput) =>
        apiRequest<Season & { warnings: SeasonWarning[] }>('PATCH', `/units/${unitId}/seasons/${season.id}`, input, {
            version: season.version,
        }),
    deleteSeason: (unitId: string, seasonId: string) => apiRequest<void>('DELETE', `/units/${unitId}/seasons/${seasonId}`),

    pricePreview: (unitId: string, input: { check_in: string; check_out: string; adults: number; children: number }) =>
        apiRequest<PriceQuote>('POST', `/units/${unitId}/price-preview`, input),
};
