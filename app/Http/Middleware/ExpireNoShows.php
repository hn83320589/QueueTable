<?php

namespace App\Http\Middleware;

use App\Services\SeatingService;
use App\Support\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 讀取候位狀態前先檢查過號，就算排程沒在跑，畫面上的狀態也是正確的。
 */
class ExpireNoShows
{
    public function __construct(private readonly SeatingService $seating) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->seating->expireNoShows(ActorContext::system());

        return $next($request);
    }
}
