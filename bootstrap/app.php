<?php

use App\Exceptions\DuplicateActivePhone;
use App\Exceptions\InvalidPhoneNumber;
use App\Exceptions\PartySizeOutOfRange;
use App\Exceptions\QueueTableException;
use App\Http\Middleware\EnsureDeviceSelected;
use App\Http\Middleware\EnsureManager;
use App\Http\Middleware\ExpireNoShows;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'device' => EnsureDeviceSelected::class,
            'manager' => EnsureManager::class,
            'expire' => ExpireNoShows::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 業務規則違反：網頁表單回到上一頁並顯示中文原因，JSON 回 422
        $exceptions->render(function (QueueTableException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $field = match (true) {
                $e instanceof DuplicateActivePhone, $e instanceof InvalidPhoneNumber => 'phone',
                $e instanceof PartySizeOutOfRange => 'size',
                default => 'action',
            };

            return back()->withErrors([$field => $e->getMessage()])->withInput();
        });
    })->create();
