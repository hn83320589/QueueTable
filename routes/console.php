<?php

use App\Services\SeatingService;
use App\Support\ActorContext;
use Illuminate\Support\Facades\Schedule;

// 叫號滿 3 分鐘未報到 → 過號。Laravel 只支援固定的秒級間隔，這裡用每 10 秒，
// 所以過號最晚在 3 分 10 秒內發生；平板與顧客頁讀取前也會先檢查一次（ExpireNoShows middleware）。
Schedule::call(fn () => app(SeatingService::class)->expireNoShows(ActorContext::system()))
    ->everyTenSeconds()
    ->name('expire-no-shows')
    ->withoutOverlapping();
