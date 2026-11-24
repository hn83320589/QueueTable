<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\QueueSnapshotBuilder;
use App\Services\TicketService;
use App\Support\ActorContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 顧客以取號時拿到的連結（含 token）查看狀態，不需要帳號。
 */
class PartyStatusController extends Controller
{
    public function show(string $token, QueueSnapshotBuilder $snapshots): Response
    {
        $party = Party::query()->where('token', $token)->firstOrFail();

        return Inertia::render('customer/Status', [
            'party' => [
                'ticket_code' => $party->ticket_code,
                'queue' => $party->queue->value,
                'status' => $party->status->value,
                'size' => $party->size,
            ],
            'snapshot' => $snapshots->build($party->business_date),
            'cancelUrl' => "/q/{$token}/cancel",
        ]);
    }

    public function cancel(string $token, TicketService $tickets): RedirectResponse
    {
        $party = Party::query()->where('token', $token)->firstOrFail();
        $tickets->cancel($party, ActorContext::customer());

        Inertia::flash('toast', ['type' => 'success', 'message' => "已取消號碼 {$party->ticket_code}"]);

        return redirect("/q/{$token}");
    }
}
