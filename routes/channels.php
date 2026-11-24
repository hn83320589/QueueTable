<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// 帶位平板：已登入的員工才能訂閱（payload 只有變動訊號，資料再經 HTTP 取得）
Broadcast::channel('staff', fn (User $user): bool => true);
