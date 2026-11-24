<?php

namespace Database\Seeders;

use App\Domain\BusinessDay;
use App\Domain\DiningTimeModel;
use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Domain\Estimation\TimeSlot;
use App\Domain\QueueLetter;
use App\Domain\Seating\FloorSnapshot;
use App\Domain\Seating\OptionFinder;
use App\Services\FloorRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 產生今天以前 30 個營業日的歷史候位與用餐紀錄（給等候預估與報表使用）。
 *
 * 不跑完整模擬，而是以「各桌最早空出時間」貪婪指派：確保同一張桌子時間不重疊、人數不超過容量。
 * 固定亂數種子，每次產生的資料都一樣。
 */
class HistorySeeder extends Seeder
{
    public const SEED = 20261124;

    public const DAYS = 30;

    /** 每小時基本到達組數（17 點到隔天 1 點），每天再乘上 0.85–1.2 的倍率 → 每天約 90–125 組 */
    private const HOURLY = [17 => 8, 18 => 20, 19 => 24, 20 => 16, 21 => 12, 22 => 10, 23 => 8, 0 => 5, 1 => 2];

    /** 人數分布（權重，總和 100）：9–12 人合計 3% */
    private const SIZE_WEIGHTS = [1 => 5, 2 => 30, 3 => 15, 4 => 25, 5 => 8, 6 => 8, 7 => 3, 8 => 3, 9 => 1, 10 => 1, 11 => 0.5, 12 => 0.5];

    private const SURNAMES = ['陳', '林', '黃', '張', '李', '王', '吳', '劉', '蔡', '楊', '許', '鄭'];

    private Randomizer $rng;

    private DiningTimeModel $dining;

    private OptionFinder $finder;

    private FloorSnapshot $floor;

    public function run(): void
    {
        $this->rng = new Randomizer(new Mt19937(self::SEED));
        $this->dining = new DiningTimeModel($this->rng);
        $this->finder = new OptionFinder((int) config('queuetable.max_waste'));
        $this->floor = app(FloorRepository::class)->floor();

        $today = CarbonImmutable::parse(BusinessDay::today());

        DB::transaction(function () use ($today) {
            for ($d = self::DAYS; $d >= 1; $d--) {
                $this->seedDay($today->subDays($d)->toDateString());
            }
        });
    }

    private function seedDay(string $date): void
    {
        $open = CarbonImmutable::parse("{$date} 17:00");
        $factor = 0.85 + $this->rng->getInt(0, 35) / 100;

        $arrivals = [];
        foreach (self::HOURLY as $hour => $rate) {
            $hourStart = $hour >= 17 ? $open->setTime($hour, 0) : $open->addDay()->setTime($hour, 0);
            $count = (int) round($rate * $factor);
            for ($i = 0; $i < $count; $i++) {
                $arrivals[] = ['at' => $hourStart->addSeconds($this->rng->getInt(0, 3599)), 'size' => $this->pickSize()];
            }
        }
        usort($arrivals, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        /** @var array<int, CarbonImmutable> $freeAt */
        $freeAt = array_map(fn () => $open, $this->floor->tables);
        $counters = [];

        foreach ($arrivals as $arrival) {
            /** @var CarbonImmutable $issuedAt */
            $issuedAt = $arrival['at'];
            $size = $arrival['size'];
            $queue = QueueLetter::fromPartySize($size);
            $number = $counters[$queue->value] = ($counters[$queue->value] ?? 0) + 1;

            $row = [
                'business_date' => $date,
                'queue' => $queue->value,
                'number' => $number,
                'ticket_code' => sprintf('%s%03d', $queue->value, $number),
                'size' => $size,
                'name' => self::SURNAMES[$this->rng->getInt(0, count(self::SURNAMES) - 1)].($this->rng->getInt(0, 1) === 1 ? '先生' : '小姐'),
                'phone' => '09'.str_pad((string) $this->rng->getInt(0, 99_999_999), 8, '0', STR_PAD_LEFT),
                'channel' => $this->rng->getInt(1, 10) <= 6 ? Channel::Kiosk->value : Channel::Remote->value,
                'token' => (string) Str::uuid(),
                'position_key' => $issuedAt->getTimestamp(),
                'issued_at' => $issuedAt->toDateTimeString(),
                'created_at' => $issuedAt->toDateTimeString(),
                'updated_at' => $issuedAt->toDateTimeString(),
            ];

            $roll = $this->rng->getInt(1, 100);
            if ($roll <= 5) {
                DB::table('parties')->insert($row + [
                    'status' => PartyStatus::Cancelled->value,
                    'cancelled_at' => $issuedAt->addMinutes($this->rng->getInt(5, 40))->toDateTimeString(),
                ]);

                continue;
            }
            if ($roll <= 9) {
                DB::table('parties')->insert($row + [
                    'status' => PartyStatus::NoShow->value,
                    'no_show_count' => 1,
                    'called_at' => $issuedAt->addMinutes($this->rng->getInt(10, 40))->toDateTimeString(),
                ]);

                continue;
            }

            [$tableIds, $seatedAt] = $this->earliestSeat($size, $issuedAt->addMinute(), $freeAt);
            $leftAt = $seatedAt->addMinutes($this->dining->sample($queue, TimeSlot::of($seatedAt)));
            foreach ($tableIds as $id) {
                $freeAt[$id] = $leftAt->addMinutes($this->rng->getInt(3, 7));
            }

            $wait = intdiv($seatedAt->getTimestamp() - $issuedAt->getTimestamp(), 60);
            $mid = $wait + $this->rng->getInt(-10, 10);
            $low = max(0, (int) floor(($mid - 5) / 5) * 5);
            $high = max($low + 5, (int) ceil(($mid + 5) / 5) * 5);

            $partyId = DB::table('parties')->insertGetId($row + [
                'status' => PartyStatus::Seated->value,
                'called_at' => $seatedAt->subMinute()->toDateTimeString(),
                'seated_at' => $seatedAt->toDateTimeString(),
                'est_low_at_issue' => $low,
                'est_high_at_issue' => $high,
                'est_low' => $low,
                'est_high' => $high,
            ]);
            $seatingId = DB::table('seatings')->insertGetId([
                'party_id' => $partyId,
                'business_date' => $date,
                'seated_at' => $seatedAt->toDateTimeString(),
                'left_at' => $leftAt->toDateTimeString(),
                'created_at' => $seatedAt->toDateTimeString(),
                'updated_at' => $leftAt->toDateTimeString(),
            ]);
            foreach ($tableIds as $id) {
                DB::table('seating_tables')->insert([
                    'seating_id' => $seatingId,
                    'table_id' => $id,
                    'released_at' => $leftAt->toDateTimeString(),
                ]);
            }
        }
    }

    /**
     * 找出最早可以入座的選項與時間。
     *
     * @param  array<int, CarbonImmutable>  $freeAt
     * @return array{0: list<int>, 1: CarbonImmutable}
     */
    private function earliestSeat(int $size, CarbonImmutable $notBefore, array $freeAt): array
    {
        $best = null;
        foreach ($this->finder->allOptions($size, $this->floor) as $option) {
            $at = $notBefore;
            foreach ($option->tableIds as $id) {
                if ($freeAt[$id]->greaterThan($at)) {
                    $at = $freeAt[$id];
                }
            }
            if ($best === null || $at->lessThan($best[1])) {
                $best = [$option->tableIds, $at];
            }
        }

        return $best ?? throw new \LogicException("桌位配置無法容納 {$size} 人");
    }

    private function pickSize(): int
    {
        $roll = $this->rng->getFloat(0, 100);
        $acc = 0.0;
        foreach (self::SIZE_WEIGHTS as $size => $weight) {
            $acc += $weight;
            if ($roll < $acc) {
                return $size;
            }
        }

        return 2;
    }
}
