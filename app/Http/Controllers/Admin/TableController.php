<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Enums\TableArea;
use App\Domain\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Services\ActivityLogger;
use App\Support\ActorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 店長設定桌位：編號、座位數、區域與可併桌的相鄰桌。
 */
class TableController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(): Response
    {
        return Inertia::render('admin/Tables', [
            'tables' => DiningTable::query()->orderBy('code')->get()->map(fn (DiningTable $t): array => [
                'id' => $t->id,
                'code' => $t->code,
                'capacity' => $t->capacity,
                'area' => $t->area->value,
                'status' => $t->status->value,
                'adjacent_ids' => $t->adjacentIds(),
            ]),
            'maxCapacity' => (int) config('queuetable.max_party_size'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $table = DiningTable::create($data + ['status' => TableStatus::Available, 'status_changed_at' => now()]);
        $this->logger->record(ActorContext::fromRequest($request), 'table.created', $table, null, TableStatus::Available->value, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "已新增 {$table->code}"]);

        return back();
    }

    public function update(Request $request, DiningTable $table): RedirectResponse
    {
        $data = $this->validated($request, $table);
        $before = ['code' => $table->code, 'capacity' => $table->capacity, 'area' => $table->area->value];
        $table->update($data);
        $this->logger->record(ActorContext::fromRequest($request), 'table.updated', $table, null, null, ['before' => $before, 'after' => $data]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "已更新 {$table->code}"]);

        return back();
    }

    public function adjacency(Request $request, DiningTable $table): RedirectResponse
    {
        $data = $request->validate([
            'adjacent_ids' => ['present', 'array'],
            'adjacent_ids.*' => ['integer', 'exists:dining_tables,id', Rule::notIn([$table->id])],
        ], ['adjacent_ids.*.not_in' => '桌子不能和自己併桌'], ['adjacent_ids' => '相鄰桌']);

        $ids = array_values(array_unique(array_map(intval(...), $data['adjacent_ids'])));
        $before = $table->adjacentIds();

        DB::transaction(function () use ($table, $ids) {
            DB::table('table_adjacencies')->where('table_a_id', $table->id)->orWhere('table_b_id', $table->id)->delete();
            foreach ($ids as $id) {
                DiningTable::linkAdjacent($table->id, $id);
            }
        });
        $this->logger->record(ActorContext::fromRequest($request), 'table.adjacency_changed', $table, null, null, ['before' => $before, 'after' => $ids]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "已更新 {$table->code} 的相鄰桌"]);

        return back();
    }

    /**
     * @return array{code: string, capacity: int, area: string}
     */
    private function validated(Request $request, ?DiningTable $table = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', Rule::unique('dining_tables', 'code')->ignore($table?->id)],
            'capacity' => ['required', 'integer', 'min:1', 'max:'.(int) config('queuetable.max_party_size')],
            'area' => ['required', Rule::enum(TableArea::class)],
        ], [], ['code' => '桌號', 'capacity' => '座位數', 'area' => '區域']);

        return ['code' => (string) $data['code'], 'capacity' => (int) $data['capacity'], 'area' => (string) $data['area']];
    }
}
