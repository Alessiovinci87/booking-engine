/**
 * API admin dei calendari (07 "Calendario", 06; 5.6–5.8): conflitti, calendari importati con storico
 * delle sincronizzazioni, feed esportati per destinazione e checklist di collegamento. Dell'URL di un
 * calendario importato il server restituisce solo l'host.
 */
import { apiRequest } from './client';

export type ChannelSource = 'BOOKING' | 'AIRBNB' | 'OTHER';
export const CHANNEL_SOURCES: ChannelSource[] = ['BOOKING', 'AIRBNB', 'OTHER'];

export type ConflictStatus = 'OPEN' | 'INVESTIGATING' | 'RESOLVED' | 'IGNORED';
export type ConflictResolution = 'CANCELLED_DIRECT' | 'CANCELLED_EXTERNAL' | 'FALSE_POSITIVE' | 'RELOCATED' | 'OTHER';
export const CONFLICT_RESOLUTIONS: ConflictResolution[] = ['CANCELLED_DIRECT', 'CANCELLED_EXTERNAL', 'FALSE_POSITIVE', 'RELOCATED', 'OTHER'];

export interface ConflictEvent {
    id: string;
    source: ChannelSource | null;
    check_in: string;
    check_out: string;
    summary: string | null;
    echo_kind: 'NONE' | 'CONFIRMED_ECHO' | 'SUSPECTED_ECHO';
    status: string;
}

export interface Conflict {
    id: string;
    unit_id: string;
    unit: { id: string; name: string | null };
    check_in: string;
    check_out: string;
    status: ConflictStatus;
    suspected_echo: boolean;
    kind: 'RESERVATION' | 'MANUAL_BLOCK' | 'EXTERNAL';
    reservation: { id: string; code: string; status: string } | null;
    external_event: ConflictEvent | null;
    other_external_event: ConflictEvent | null;
    detected_at: string;
    resolution: ConflictResolution | null;
    resolution_notes: string | null;
    resolved_at: string | null;
}

export interface ExternalCalendar {
    id: string;
    unit_id: string;
    source: ChannelSource;
    url_host: string | null;
    status: 'ACTIVE' | 'DEGRADED' | 'UNHEALTHY' | 'DISABLED';
    health: 'ACTIVE' | 'DEGRADED' | 'UNHEALTHY' | 'DISABLED';
    last_success_at: string | null;
    last_attempt_at: string | null;
    consecutive_failures: number;
    next_poll_at: string | null;
    url_changed_at: string | null;
    active_events: number | null;
    poll_minutes: number;
}

export interface SyncRun {
    id: string;
    started_at: string;
    finished_at: string | null;
    outcome: string | null;
    http_status: number | null;
    error_code: string | null;
    events_seen: number;
    events_added: number;
    events_updated: number;
    events_pending_removal: number;
    events_removed: number;
    duration_ms: number | null;
}

export interface IcsFeed {
    id: string;
    unit_id: string;
    destination: ChannelSource;
    status: 'ACTIVE' | 'FROZEN' | 'RETIRED';
    url: string | null;
    paired_external_calendar_id: string | null;
    last_fetched_at: string | null;
    last_fetch_user_agent: string | null;
    not_read_warning: boolean;
    frozen_at: string | null;
    rotated_at: string | null;
    retires_at: string | null;
}

export interface ChecklistStep {
    key: string;
    channel: ChannelSource | null;
    kind: 'auto' | 'manual';
    done: boolean;
    confirmed_at: string | null;
    evidence: { last_fetched_at?: string | null; last_success_at?: string | null };
}

export interface Checklist {
    steps: ChecklistStep[];
    complete: boolean;
}

const data = <T>(promise: Promise<{ data: T }>): Promise<T> => promise.then((r) => r.data);

export const calendarsApi = {
    conflicts: (status: ConflictStatus[]) =>
        data(apiRequest<{ data: Conflict[] }>('GET', `/conflicts?${status.map((s) => `status[]=${s}`).join('&')}`)),
    investigate: (id: string) => data(apiRequest<{ data: Conflict }>('POST', `/conflicts/${id}/investigate`)),
    resolve: (id: string, resolution: ConflictResolution, notes: string | null) =>
        data(apiRequest<{ data: Conflict }>('POST', `/conflicts/${id}/resolve`, { resolution, notes })),
    ignore: (id: string, notes: string | null) => data(apiRequest<{ data: Conflict }>('POST', `/conflicts/${id}/ignore`, { notes })),
    notAnEcho: (eventId: string) => data(apiRequest<{ data: unknown }>('POST', `/external-events/${eventId}/not-echo`)),

    calendars: (unitId: string) => data(apiRequest<{ data: ExternalCalendar[] }>('GET', `/units/${unitId}/external-calendars`)),
    addCalendar: (unitId: string, body: { source: ChannelSource; url: string; switch_to_request: boolean }) =>
        data(apiRequest<{ data: ExternalCalendar & { first_sync: SyncRun } }>('POST', `/units/${unitId}/external-calendars`, body)),
    updateCalendar: (id: string, body: { url?: string; status?: 'ACTIVE' | 'DISABLED' }) =>
        data(apiRequest<{ data: ExternalCalendar }>('PATCH', `/external-calendars/${id}`, body)),
    removeCalendar: (id: string) => data(apiRequest<{ data: ExternalCalendar & { events_removed: number } }>('DELETE', `/external-calendars/${id}`)),
    syncNow: (id: string) => data(apiRequest<{ data: ExternalCalendar & { last_run: SyncRun | null } }>('POST', `/external-calendars/${id}/sync-now`)),
    runs: (id: string) => data(apiRequest<{ data: SyncRun[] }>('GET', `/external-calendars/${id}/runs`)),

    feeds: (unitId: string) => data(apiRequest<{ data: IcsFeed[] }>('GET', `/units/${unitId}/ics-feeds`)),
    createFeed: (unitId: string, destination: ChannelSource) => data(apiRequest<{ data: IcsFeed }>('POST', `/units/${unitId}/ics-feeds`, { destination })),
    rotateFeed: (id: string) => data(apiRequest<{ data: IcsFeed }>('POST', `/ics-feeds/${id}/rotate`)),

    checklist: (unitId: string) => data(apiRequest<{ data: Checklist }>('GET', `/units/${unitId}/calendar-checklist`)),
    confirmStep: (unitId: string, step: string) => data(apiRequest<{ data: Checklist }>('POST', `/units/${unitId}/calendar-checklist/confirm`, { step })),
};
