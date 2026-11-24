<?php

use App\Http\Controllers\Admin\TableController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Board\BoardController;
use App\Http\Controllers\Customer\PartyStatusController;
use App\Http\Controllers\Customer\TakeTicketController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\Host\HostController;
use App\Http\Controllers\Host\PartyActionController;
use App\Http\Controllers\Host\TableActionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// 公開：門口看板、顧客取號與狀態頁（讀取前先檢查過號）
Route::middleware('expire')->group(function () {
    Route::get('/board', [BoardController::class, 'show'])->name('board');
    Route::get('/take', [TakeTicketController::class, 'create'])->name('take');
    Route::post('/take', [TakeTicketController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/q/{token}', [PartyStatusController::class, 'show'])->name('party.status');
    Route::post('/q/{token}/cancel', [PartyStatusController::class, 'cancel'])->name('party.cancel');
});

Route::middleware('auth')->group(function () {
    Route::get('/device', [DeviceController::class, 'show'])->name('device.select');
    Route::post('/device', [DeviceController::class, 'store']);

    // 帶位平板：必須先選擇平板
    Route::middleware(['device', 'expire'])->prefix('host')->group(function () {
        Route::get('/', [HostController::class, 'show'])->name('host');
        Route::post('/parties', [PartyActionController::class, 'store']);
        Route::get('/parties/{party}/options', [PartyActionController::class, 'options']);
        Route::post('/parties/{party}/call', [PartyActionController::class, 'call']);
        Route::post('/parties/{party}/check-in', [PartyActionController::class, 'checkIn']);
        Route::post('/parties/{party}/cancel', [PartyActionController::class, 'cancel']);
        Route::post('/parties/{party}/requeue', [PartyActionController::class, 'requeue']);
        Route::post('/tables/{table}/leave', [TableActionController::class, 'leave']);
        Route::post('/tables/{table}/clean', [TableActionController::class, 'clean']);
        Route::post('/tables/{table}/disable', [TableActionController::class, 'disable']);
        Route::post('/tables/{table}/enable', [TableActionController::class, 'enable']);
    });

    // 店長：桌位設定、帳號、營業日報表
    Route::middleware('manager')->prefix('admin')->group(function () {
        Route::get('/tables', [TableController::class, 'index'])->name('admin.tables');
        Route::post('/tables', [TableController::class, 'store']);
        Route::put('/tables/{table}', [TableController::class, 'update']);
        Route::put('/tables/{table}/adjacency', [TableController::class, 'adjacency']);
        Route::get('/users', [UserController::class, 'index'])->name('admin.users');
        Route::post('/users', [UserController::class, 'store']);
    });
});
