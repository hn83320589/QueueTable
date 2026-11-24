<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueTicketRequest;
use App\Services\TicketService;
use App\Support\ActorContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TakeTicketController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('customer/Take', [
            'maxPartySize' => (int) config('queuetable.max_party_size'),
        ]);
    }

    public function store(IssueTicketRequest $request, TicketService $tickets): RedirectResponse
    {
        $party = $tickets->issue(
            $request->integer('size'),
            $request->string('name')->toString(),
            $request->string('phone')->toString(),
            Channel::Remote,
            ActorContext::customer(),
        );

        return redirect("/q/{$party->token}");
    }
}
