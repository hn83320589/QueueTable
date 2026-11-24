<?php

namespace App\Http\Controllers\Board;

use App\Domain\BusinessDay;
use App\Http\Controllers\Controller;
use App\Services\QueueSnapshotBuilder;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function show(QueueSnapshotBuilder $snapshots): Response
    {
        return Inertia::render('board/Index', [
            'snapshot' => $snapshots->build(BusinessDay::today()),
            'takeUrl' => url('/take'),
        ]);
    }
}
