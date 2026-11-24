<?php

use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Events\FloorUpdated;
use App\Events\QueueUpdated;
use App\Models\DiningTable;
use App\Models\Party;
use App\Services\QueueSnapshotBuilder;
use App\Services\SeatingService;
use App\Services\TableService;
use App\Services\TicketService;
use App\Support\ActorContext;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->system = ActorContext::system();
    $this->table = DiningTable::factory()->create(['code' => 'T1', 'capacity' => 4]);
});

it('broadcasts queue and floor updates after seat, leave and cancel', function () {
    Event::fake([QueueUpdated::class, FloorUpdated::class]);
    $seating = app(SeatingService::class);
    $party = Party::factory()->size(4)->create();
    $other = Party::factory()->size(2)->create();
    $seating->call($party, [$this->table->id], $this->system);

    foreach ([
        fn () => $seating->checkIn($party, $this->system),
        fn () => app(TableService::class)->leave($this->table, $this->system),
        fn () => app(TicketService::class)->cancel($other, ActorContext::customer()),
    ] as $action) {
        Event::fake([QueueUpdated::class, FloorUpdated::class]);
        $action();
        Event::assertDispatchedTimes(QueueUpdated::class, 1);
        Event::assertDispatchedTimes(FloorUpdated::class, 1);
    }
});

it('reports now calling and waiting counts per queue', function () {
    $called = Party::factory()->size(4)->create(['ticket_code' => 'B007']);
    Party::factory()->size(3)->create(['ticket_code' => 'B008']);
    Party::factory()->size(2)->create(['ticket_code' => 'A001']);
    app(SeatingService::class)->call($called, [$this->table->id], $this->system);

    $snapshot = app(QueueSnapshotBuilder::class)->build('2026-11-24');

    expect($snapshot['queues']['B'])->toBe(['now_calling' => 'B007', 'waiting_count' => 1])
        ->and($snapshot['queues']['A'])->toBe(['now_calling' => null, 'waiting_count' => 1])
        ->and($snapshot['queues']['D'])->toBe(['now_calling' => null, 'waiting_count' => 0])
        ->and(collect($snapshot['parties'])->pluck('ticket_code')->sort()->values()->all())->toBe(['A001', 'B007', 'B008']);
});

it('reports how many parties are ahead in the same queue', function () {
    Party::factory()->size(2)->create(['ticket_code' => 'A001', 'position_key' => 1]);
    Party::factory()->size(2)->create(['ticket_code' => 'A002', 'position_key' => 2]);

    $parties = collect(app(QueueSnapshotBuilder::class)->build('2026-11-24')['parties'])->keyBy('ticket_code');

    expect($parties['A001']['ahead'])->toBe(0)->and($parties['A002']['ahead'])->toBe(1);
});

it('never puts personal data on the public channel', function () {
    $party = Party::factory()->size(2)->create(['name' => '測試王先生', 'phone' => '0987654321']);

    $json = json_encode((new QueueUpdated(app(QueueSnapshotBuilder::class)->build('2026-11-24')))->broadcastWith(), JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('測試王先生')
        ->not->toContain('0987654321')
        ->not->toContain($party->token);
});

it('does not broadcast when the transaction rolls back', function () {
    Event::fake([QueueUpdated::class, FloorUpdated::class]);

    try {
        DB::transaction(function () {
            app(TicketService::class)->issue(2, '陳先生', '0912000001', Channel::Remote, ActorContext::customer());
            throw new RuntimeException('之後的步驟失敗');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(QueueUpdated::class);
    expect(Party::count())->toBe(0);
});

it('logs a warning and keeps the business change when broadcasting fails', function () {
    Broadcast::extend('failing', fn () => new class extends Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new BroadcastException('Reverb 沒有啟動');
        }
    });
    config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);
    Log::spy();

    $party = app(TicketService::class)->issue(2, '陳先生', '0912000001', Channel::Remote, ActorContext::customer());

    expect($party->fresh()->status)->toBe(PartyStatus::Waiting);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, '推播失敗'))->atLeast()->once();
});
