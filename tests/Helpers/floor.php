<?php

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\TableStatus;
use App\Domain\QueueLetter;
use App\Domain\Seating\AllocationResult;
use App\Domain\Seating\FloorSnapshot;
use App\Domain\Seating\TableSnapshot;
use App\Domain\Seating\WaitingParty;

/**
 * 以桌號建立測試用桌況。id 依陣列順序從 1 開始。
 *
 * @param  list<array{0: string, 1: int, 2?: string, 3?: int|null, 4?: string|null}>  $tables  [code, capacity, status, heldFor, holdReason]
 * @param  list<array{0: string, 1: string}>  $pairs  相鄰桌號
 */
function makeFloor(array $tables, array $pairs = []): FloorSnapshot
{
    $snapshots = [];
    $ids = [];
    foreach (array_values($tables) as $i => $t) {
        $id = $i + 1;
        $ids[$t[0]] = $id;
        $snapshots[$id] = new TableSnapshot(
            id: $id,
            code: $t[0],
            capacity: $t[1],
            status: TableStatus::from($t[2] ?? 'available'),
            heldForPartyId: $t[3] ?? null,
            holdReason: isset($t[4]) ? HoldReason::from($t[4]) : null,
        );
    }

    $adjacency = array_map(fn (array $p) => [$ids[$p[0]], $ids[$p[1]]], $pairs);

    return new FloorSnapshot($snapshots, $adjacency);
}

/** 用桌號找 id。 */
function tid(FloorSnapshot $floor, string $code): int
{
    foreach ($floor->tables as $t) {
        if ($t->code === $code) {
            return $t->id;
        }
    }
    throw new InvalidArgumentException("找不到桌號 {$code}");
}

/** 把 id 清單轉成桌號清單，方便斷言。 @param list<int> $ids @return list<string> */
function codes(FloorSnapshot $floor, array $ids): array
{
    return array_map(fn (int $id) => $floor->table($id)->code, $ids);
}

/** 測試用等候組。 */
function wp(int $id, int $size, float $key, int $skip = 0): WaitingParty
{
    return new WaitingParty($id, QueueLetter::fromPartySize($size), $size, $key, $skip);
}

/**
 * 把建議轉成 [partyId => '桌號+桌號'] 方便斷言。
 *
 * @return array<int, string>
 */
function suggestionMap(AllocationResult $result, FloorSnapshot $floor): array
{
    $map = [];
    foreach ($result->suggestions as $s) {
        $map[$s->partyId] = implode('+', codes($floor, $s->option->tableIds));
    }

    return $map;
}
