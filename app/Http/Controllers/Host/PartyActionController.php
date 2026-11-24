<?php

namespace App\Http\Controllers\Host;

use App\Domain\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueTicketRequest;
use App\Models\Party;
use App\Services\HostStateBuilder;
use App\Services\SeatingService;
use App\Services\TicketService;
use App\Support\ActorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PartyActionController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly SeatingService $seating,
    ) {}

    /** 門口平板代客取號 */
    public function store(IssueTicketRequest $request): RedirectResponse
    {
        $party = $this->tickets->issue(
            $request->integer('size'),
            $request->string('name')->toString(),
            $request->string('phone')->toString(),
            Channel::Kiosk,
            ActorContext::fromRequest($request),
        );

        return $this->done("已發號 {$party->ticket_code}（{$party->size} 人）");
    }

    public function options(Party $party, HostStateBuilder $state): JsonResponse
    {
        return response()->json(['options' => $state->optionsFor($party)]);
    }

    public function call(Request $request, Party $party): RedirectResponse
    {
        $data = $request->validate([
            'table_ids' => ['required', 'array', 'min:1', 'max:2'],
            'table_ids.*' => ['integer'],
        ], [], ['table_ids' => '桌子']);

        $ids = array_values(array_map(intval(...), $data['table_ids']));
        $this->seating->call($party, $ids, ActorContext::fromRequest($request));

        return $this->done("請 {$party->ticket_code} 到門口報到");
    }

    public function checkIn(Request $request, Party $party): RedirectResponse
    {
        $this->seating->checkIn($party, ActorContext::fromRequest($request));

        return $this->done("{$party->ticket_code} 已入座");
    }

    public function cancel(Request $request, Party $party): RedirectResponse
    {
        $this->tickets->cancel($party, ActorContext::fromRequest($request));

        return $this->done("已取消 {$party->ticket_code}");
    }

    public function requeue(Request $request, Party $party): RedirectResponse
    {
        $this->seating->requeue($party, ActorContext::fromRequest($request));

        return $this->done("{$party->ticket_code} 已重新排入第 3 位");
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
