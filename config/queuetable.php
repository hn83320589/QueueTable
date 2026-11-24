<?php

return [
    // 營業日切換時間：台灣時間 06:00 以前算前一個營業日
    'business_day_cutoff_hour' => 6,

    // 取號人數上限
    'max_party_size' => 12,

    // 一張桌（或併桌）最多可以浪費幾個座位
    'max_waste' => 2,

    // 同一組最多被跳過幾次，之後進入保護
    'max_skips' => 3,

    // 叫號後幾分鐘未報到視為過號
    'no_show_minutes' => 3,

    // 每組最多可以重新排入幾次
    'requeue_limit' => 1,

    // 清桌所需分鐘（預估用）
    'cleaning_minutes' => 5,

    // 前面剩幾組時產生「即將輪到」通知
    'coming_soon_ahead' => 2,

    // 等候預估取樣的歷史營業日數
    'estimate_history_days' => 14,

    // 每個分組至少幾筆樣本才使用，否則退回較粗的分組
    'estimate_min_samples' => 5,

    // 完全沒有歷史資料時的預設用餐分鐘（P50），P25/P75 = ±spread
    'default_dining_minutes' => ['A' => 50, 'B' => 65, 'C' => 80, 'D' => 95],
    'default_dining_spread' => 15,

    // 排程檢查過號的間隔秒數
    'expiry_check_seconds' => 10,
];
