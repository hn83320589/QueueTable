<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Services\TableService;
use App\Support\ActorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TableActionController extends Controller
{
    public function __construct(private readonly TableService $tables) {}

    public function leave(Request $request, DiningTable $table): RedirectResponse
    {
        $this->tables->leave($table, ActorContext::fromRequest($request));

        return $this->done("{$table->code} 客人已離開，請清桌");
    }

    public function clean(Request $request, DiningTable $table): RedirectResponse
    {
        $this->tables->markCleaned($table, ActorContext::fromRequest($request));

        return $this->done("{$table->code} 清桌完成");
    }

    public function disable(Request $request, DiningTable $table): RedirectResponse
    {
        $this->tables->disable($table, ActorContext::fromRequest($request));

        return $this->done("{$table->code} 已停用");
    }

    public function enable(Request $request, DiningTable $table): RedirectResponse
    {
        $this->tables->enable($table, ActorContext::fromRequest($request));

        return $this->done("{$table->code} 已啟用");
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
