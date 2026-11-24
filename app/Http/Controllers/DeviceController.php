<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Support\ActorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 帶位人員選擇手上的平板，存在長效 cookie，之後的操作紀錄都會帶上平板識別。
 */
class DeviceController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('device/Select', [
            'devices' => Device::query()->orderBy('name')->get(['id', 'name']),
            'current' => $request->cookie(ActorContext::DEVICE_COOKIE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['device_id' => ['required', 'integer', 'exists:devices,id']], [], ['device_id' => '平板']);

        Cookie::queue(Cookie::forever(ActorContext::DEVICE_COOKIE, (string) $data['device_id']));

        return redirect()->intended('/host');
    }
}
