<?php

namespace App\Http\Controllers\Host;

use App\Domain\BusinessDay;
use App\Http\Controllers\Controller;
use App\Services\HostStateBuilder;
use Inertia\Inertia;
use Inertia\Response;

class HostController extends Controller
{
    public function show(HostStateBuilder $state): Response
    {
        return Inertia::render('host/Index', [
            'state' => $state->build(BusinessDay::today()),
        ]);
    }
}
