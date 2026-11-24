<?php

namespace App\Services;

use App\Domain\BusinessDay;
use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Domain\QueueLetter;
use App\Exceptions\DuplicateActivePhone;
use App\Exceptions\InvalidPhoneNumber;
use App\Exceptions\InvalidTransition;
use App\Exceptions\PartySizeOutOfRange;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\TicketCounter;
use App\Support\ActorContext;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 取號與取消。
 */
class TicketService
{
    public function __construct(
        private readonly QueueStateRefresher $refresher,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * 發號在單一交易內完成：任何一步失敗整筆 rollback，號碼不會被吃掉（不跳號）。
     */
    public function issue(int $size, string $name, string $phone, Channel $channel, ActorContext $actor): Party
    {
        try {
            $queue = QueueLetter::fromPartySize($size);
        } catch (InvalidArgumentException $e) {
            throw new PartySizeOutOfRange($e->getMessage(), previous: $e);
        }
        try {
            $phone = PhoneNumber::normalize($phone);
        } catch (InvalidArgumentException $e) {
            throw new InvalidPhoneNumber($e->getMessage(), previous: $e);
        }

        try {
            return DB::transaction(fn (): Party => $this->createParty($queue, $size, trim($name), $phone, $channel, $actor));
        } catch (UniqueConstraintViolationException $e) {
            // 兩台裝置同時用同一支手機取號：應用層檢查都通過，由資料庫唯一索引擋下
            throw new DuplicateActivePhone('此手機今天已有等候中的號碼', previous: $e);
        }
    }

    public function cancel(Party $party, ActorContext $actor): Party
    {
        return DB::transaction(function () use ($party, $actor): Party {
            $party->refresh();
            if (! $party->status->isActive()) {
                throw new InvalidTransition("號碼 {$party->ticket_code} 目前為「{$party->status->label()}」，無法取消");
            }

            $from = $party->status;
            DiningTable::query()->where('held_for_party_id', $party->id)->update(['held_for_party_id' => null, 'hold_reason' => null]);
            $party->update(['status' => PartyStatus::Cancelled, 'cancelled_at' => now()]);

            $this->logger->record($actor, 'party.cancelled', $party, $from->value, PartyStatus::Cancelled->value);
            $this->refresher->refresh($party->business_date, $actor);

            return $party;
        });
    }

    private function createParty(QueueLetter $queue, int $size, string $name, string $phone, Channel $channel, ActorContext $actor): Party
    {
        $date = BusinessDay::today();

        $existing = Party::query()
            ->where('business_date', $date)
            ->where('phone', $phone)
            ->whereIn('status', [PartyStatus::Waiting, PartyStatus::Called])
            ->first();
        if ($existing !== null) {
            throw new DuplicateActivePhone("此手機今天已有等候中的號碼 {$existing->ticket_code}");
        }

        $number = $this->nextNumber($date, $queue);
        $party = Party::create([
            'business_date' => $date,
            'queue' => $queue,
            'number' => $number,
            'ticket_code' => sprintf('%s%03d', $queue->value, $number),
            'size' => $size,
            'name' => $name,
            'phone' => $phone,
            'channel' => $channel,
            'token' => (string) Str::uuid(),
            'status' => PartyStatus::Waiting,
            'position_key' => $this->nextPositionKey($date),
            'issued_at' => now(),
        ]);

        $this->logger->record($actor, 'party.issued', $party, null, PartyStatus::Waiting->value, ['channel' => $channel->value, 'size' => $size]);
        $this->refresher->refresh($date, $actor);

        $party->refresh();
        $party->update(['est_low_at_issue' => $party->est_low, 'est_high_at_issue' => $party->est_high]);

        return $party;
    }

    /**
     * 取號時間（毫秒精度）作為排序鍵，並保證同一營業日內嚴格遞增：
     * 同一毫秒取號（或測試、模擬器凍結時間）時往後推 1 毫秒，
     * 重新排入用兩個鍵的中點時才一定能排進兩者之間。
     */
    private function nextPositionKey(string $date): float
    {
        $now = now()->getPreciseTimestamp(3) / 1000;
        $last = Party::query()->where('business_date', $date)->max('position_key');

        return is_numeric($last) && (float) $last >= $now ? (float) $last + 0.001 : $now;
    }

    private function nextNumber(string $date, QueueLetter $queue): int
    {
        TicketCounter::query()->insertOrIgnore(['business_date' => $date, 'queue' => $queue->value, 'last_number' => 0]);
        TicketCounter::query()->where('business_date', $date)->where('queue', $queue->value)->increment('last_number');

        $number = (int) TicketCounter::query()->where('business_date', $date)->where('queue', $queue->value)->value('last_number');
        if ($number > 999) {
            throw new InvalidTransition("佇列 {$queue->value} 今天的號碼已用完");
        }

        return $number;
    }
}
