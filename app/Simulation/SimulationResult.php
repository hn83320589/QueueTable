<?php

namespace App\Simulation;

final readonly class SimulationResult
{
    /**
     * @param  array<string, int|null>  $perQueueMaxWait
     * @param  array<string, int>  $rejectedSuggestions  系統建議被叫號服務拒絕的次數（依例外類別）
     * @param  array<string, int>  $rejectedRequeues  重新排入被拒絕的次數（依例外類別）
     * @param  array<string, array{issued: int, seated: int, cancelled: int, cancel_rate: float|null, avg_wait_seated: float|null, max_wait_including_cancelled: int|null}>  $bySize  依人數分組
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
        public array $rejectedSuggestions = [],
        public array $rejectedRequeues = [],
        public array $bySize = [],
    ) {}

    /** 系統給的每個建議都被叫號服務接受（建議演算法與叫號檢查一致） */
    public function suggestionsConsistent(): bool
    {
        return $this->rejectedSuggestions === [];
    }

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
            'rejected_suggestions' => $this->rejectedSuggestions,
            'rejected_requeues' => $this->rejectedRequeues,
            'by_size' => $this->bySize,
        ];
    }
}
