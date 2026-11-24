<?php

namespace App\Simulation;

use App\Domain\Enums\Channel;
use Carbon\CarbonImmutable;
use Random\Randomizer;

/**
 * 依各時段到達率產生一晚的客人（Poisson 到達：相鄰兩組的間隔服從指數分布）。
 */
final class ArrivalGenerator
{
    private const SURNAMES = ['陳', '林', '黃', '張', '李', '王', '吳', '劉', '蔡', '楊', '許', '鄭'];

    /**
     * @param  array<string, mixed>  $config  config('simulation')
     * @return list<SimArrival>
     */
    public function generate(Randomizer $rng, string $date, array $config): array
    {
        $open = CarbonImmutable::parse("{$date} {$config['open']}");
        $lastTicket = SimClock::at($date, (string) $config['last_ticket']);

        $arrivals = [];
        $index = 0;
        /** @var array<int, int|float> $rates */
        $rates = $config['arrivals_per_hour'];
        foreach ($rates as $hour => $rate) {
            $hourStart = $hour >= 12 ? $open->setTime($hour, 0) : $open->addDay()->setTime($hour, 0);
            $t = $hourStart;
            while ($rate > 0) {
                $gapSeconds = (int) round(-log(1.0 - $rng->nextFloat()) / $rate * 3600);
                $t = $t->addSeconds(max(1, $gapSeconds));
                if ($t->greaterThanOrEqualTo($hourStart->addHour()) || $t->greaterThan($lastTicket)) {
                    break;
                }
                $arrivals[] = $this->party($rng, $t, ++$index, $config);
            }
        }

        usort($arrivals, fn (SimArrival $a, SimArrival $b): int => $a->at <=> $b->at);

        return $arrivals;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function party(Randomizer $rng, CarbonImmutable $at, int $index, array $config): SimArrival
    {
        /** @var array{0: int, 1: int} $delay */
        $delay = $config['check_in_delay_seconds'];
        /** @var array{0: int, 1: int} $patience */
        $patience = $config['patience_minutes'];
        /** @var array{0: int, 1: int} $requeueDelay */
        $requeueDelay = $config['requeue_delay_minutes'];

        $willNoShow = $rng->nextFloat() < (float) $config['no_show_rate'];

        return new SimArrival(
            at: $at,
            size: $this->size($rng, $config['party_size_weights']),
            name: self::SURNAMES[$rng->getInt(0, count(self::SURNAMES) - 1)].($rng->getInt(0, 1) === 1 ? '先生' : '小姐'),
            phone: sprintf('09%08d', $index),
            channel: $rng->nextFloat() < (float) $config['kiosk_rate'] ? Channel::Kiosk : Channel::Remote,
            patienceMinutes: $rng->getInt($patience[0], $patience[1]),
            checkInDelaySeconds: $rng->getInt($delay[0], $delay[1]),
            willNoShow: $willNoShow,
            willRequeue: $willNoShow && $rng->nextFloat() < (float) $config['requeue_after_no_show_rate'],
            requeueDelayMinutes: $rng->getInt($requeueDelay[0], $requeueDelay[1]),
            randomCancelAt: $rng->nextFloat() < (float) $config['random_cancel_rate'] ? $at->addMinutes($rng->getInt(5, 60)) : null,
        );
    }

    private function size(Randomizer $rng, mixed $weights): int
    {
        /** @var array<int, int|float> $weights */
        $roll = $rng->getFloat(0, array_sum($weights));
        $acc = 0.0;
        foreach ($weights as $size => $weight) {
            $acc += $weight;
            if ($roll < $acc) {
                return $size;
            }
        }

        return 2;
    }
}
