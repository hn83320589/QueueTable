<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Support\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 帶位人員必須先選擇自己拿的是哪一台平板，操作紀錄才能記錄平板識別。
 */
class EnsureDeviceSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->cookie(ActorContext::DEVICE_COOKIE);

        if (! is_numeric($id) || ! Device::query()->whereKey((int) $id)->exists()) {
            return redirect()->guest(route('device.select'));
        }

        return $next($request);
    }
}
