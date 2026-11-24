<?php

namespace App\Simulation;

final readonly class SimulationResult
{
    /**
     * @param  array<string, int|null>  $perQueueMaxWait
     */
    public function __construct(
        public int $seed,
        public string $businessDate,
        public int $issued,
        public int $seated,
        public int $noShows,
        public int $cancelled,
        public ?float $mae,
        public ?float $intervalHitRate,
        public ?float $avgWait,
        public ?int $maxWait,
        public array $perQueueMaxWait,
        public int $maxSkipAudited,
        public int $maxSkipStored,
        public bool $skipCheckPassed,
        public float $utilization,
        public int $unservedAtEnd,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'business_date' => $this->businessDate,
            'issued' => $this->issued,
            'seated' => $this->seated,
            'no_shows' => $this->noShows,
            'cancelled' => $this->cancelled,
            'mae' => $this->mae,
            'interval_hit_rate' => $this->intervalHitRate,
            'avg_wait' => $this->avgWait,
            'max_wait' => $this->maxWait,
            'per_queue_max_wait' => $this->perQueueMaxWait,
            'max_skip_audited' => $this->maxSkipAudited,
            'max_skip_stored' => $this->maxSkipStored,
            'skip_check_passed' => $this->skipCheckPassed,
            'utilization' => $this->utilization,
            'unserved_at_end' => $this->unservedAtEnd,
        ];
    }
}
