export type QueueLetter = 'A' | 'B' | 'C' | 'D';

export type PartyStatus =
    | 'waiting'
    | 'called'
    | 'seated'
    | 'no_show'
    | 'cancelled';

export type TableStatus = 'available' | 'occupied' | 'cleaning' | 'disabled';

/** 公開快照（看板、顧客頁、WebSocket queue.updated） */
export type QueueSnapshot = {
    business_date: string;
    generated_at: string;
    queues: Record<
        QueueLetter,
        { now_calling: string | null; waiting_count: number }
    >;
    parties: {
        ticket_code: string;
        queue: QueueLetter;
        status: PartyStatus;
        ahead: number;
        est_low: number | null;
        est_high: number | null;
    }[];
};

export type HostParty = {
    id: number;
    ticket_code: string;
    queue: QueueLetter;
    size: number;
    name: string;
    phone_tail: string;
    channel: 'kiosk' | 'remote';
    status: PartyStatus;
    ahead: number;
    waited_minutes: number;
    skip_count: number;
    requeue_count: number;
    est_low: number | null;
    est_high: number | null;
    called_at: string | null;
};

export type HostTable = {
    id: number;
    code: string;
    capacity: number;
    area: 'indoor' | 'outdoor' | 'bar';
    status: TableStatus;
    held_for: string | null;
    hold_reason: 'called' | 'protected' | null;
    seated_at: string | null;
    elapsed_minutes: number | null;
    party_ticket: string | null;
    party_size: number | null;
    status_changed_at: string | null;
};

export type HostSuggestion = {
    party_id: number;
    ticket_code: string;
    size: number | null;
    table_ids: number[];
    table_codes: string[];
    waste: number;
    combined: boolean;
};

export type HostState = {
    business_date: string;
    now: string;
    parties: HostParty[];
    no_shows: {
        id: number;
        ticket_code: string;
        size: number;
        name: string;
        can_requeue: boolean;
    }[];
    tables: HostTable[];
    suggestions: HostSuggestion[];
};

export type TableOptionChoice = {
    table_ids: number[];
    codes: string[];
    capacity: number;
    waste: number;
    combined: boolean;
};

export const QUEUE_LABELS: Record<QueueLetter, string> = {
    A: '1–2 位',
    B: '3–4 位',
    C: '5–6 位',
    D: '7 位以上',
};

/** 等候區間文字；null 代表目前桌位配置無法入座 */
export function estimateText(low: number | null, high: number | null): string {
    if (low === null || high === null) {
        return '請洽現場店員';
    }

    return `約 ${low}–${high} 分鐘`;
}
