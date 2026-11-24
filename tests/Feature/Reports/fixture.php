<?php

use App\Domain\Enums\PartyStatus;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\Seating;

/**
 * 營業日 2026-11-20 的固定資料（手算結果見 ReportBuilderTest）：
 *
 * | 號碼 | 人數 | 取號  | 入座  | 離開  | 桌  | 取號時預估 | 跳過 | 結果     |
 * | A001 | 2    | 18:00 | 18:10 | 18:25 | I01 | 5–15       | 0    | 入座     |
 * | A002 | 2    | 18:05 | 18:35 | —     | I01 | 10–20      | 1    | 入座     |
 * | B001 | 4    | 18:00 | 18:20 | —     | I05 | 20–30      | 0    | 入座     |
 * | B002 | 3    | 18:10 | —     | —     | —   | —          | 2    | 取消     |
 * | D001 | 8    | 18:15 | —     | —     | —   | —          | 3    | 過號     |
 */
function seedReportFixture(): void
{
    $i01 = DiningTable::factory()->create(['code' => 'I01', 'capacity' => 2]);
    $i05 = DiningTable::factory()->create(['code' => 'I05', 'capacity' => 4]);
    DiningTable::factory()->create(['code' => 'T01', 'capacity' => 4]);

    $party = fn (string $code, int $size, string $issued, array $extra = []) => Party::factory()->size($size)->create([
        'business_date' => '2026-11-20',
        'ticket_code' => $code,
        'issued_at' => "2026-11-20 {$issued}",
    ] + $extra);

    $seat = function (Party $p, DiningTable $table, string $seated, ?string $left = null) {
        $seating = Seating::create([
            'party_id' => $p->id,
            'business_date' => '2026-11-20',
            'seated_at' => "2026-11-20 {$seated}",
            'left_at' => $left ? "2026-11-20 {$left}" : null,
        ]);
        $seating->tables()->attach($table->id, ['released_at' => $left ? "2026-11-20 {$left}" : null]);
    };

    $a1 = $party('A001', 2, '18:00', ['status' => PartyStatus::Seated, 'seated_at' => '2026-11-20 18:10', 'est_low_at_issue' => 5, 'est_high_at_issue' => 15]);
    $a2 = $party('A002', 2, '18:05', ['status' => PartyStatus::Seated, 'seated_at' => '2026-11-20 18:35', 'est_low_at_issue' => 10, 'est_high_at_issue' => 20, 'skip_count' => 1]);
    $b1 = $party('B001', 4, '18:00', ['status' => PartyStatus::Seated, 'seated_at' => '2026-11-20 18:20', 'est_low_at_issue' => 20, 'est_high_at_issue' => 30]);
    $party('B002', 3, '18:10', ['status' => PartyStatus::Cancelled, 'cancelled_at' => '2026-11-20 18:30', 'skip_count' => 2]);
    $party('D001', 8, '18:15', ['status' => PartyStatus::NoShow, 'no_show_count' => 1, 'skip_count' => 3]);

    $seat($a1, $i01, '18:10', '18:25');
    $seat($a2, $i01, '18:35');
    $seat($b1, $i05, '18:20');

    // 別的營業日的資料不應該被算進來
    Party::factory()->create(['business_date' => '2026-11-21', 'ticket_code' => 'A001']);
}
