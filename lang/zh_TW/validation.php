<?php

// 只收錄本專案用到的驗證規則
return [
    'required' => ':attribute 為必填',
    'string' => ':attribute 必須是文字',
    'integer' => ':attribute 必須是整數',
    'array' => ':attribute 格式不正確',
    'email' => ':attribute 必須是有效的 Email',
    'exists' => '選擇的 :attribute 不存在',
    'unique' => ':attribute 已經被使用',
    'in' => '選擇的 :attribute 無效',
    'date_format' => ':attribute 格式必須是 :format',
    'confirmed' => ':attribute 確認不一致',
    'different' => ':attribute 與 :other 必須不同',
    'min' => [
        'numeric' => ':attribute 不能小於 :min',
        'string' => ':attribute 至少需要 :min 個字',
        'array' => ':attribute 至少需要 :min 項',
    ],
    'max' => [
        'numeric' => ':attribute 不能大於 :max',
        'string' => ':attribute 不能超過 :max 個字',
        'array' => ':attribute 不能超過 :max 項',
    ],
    'attributes' => [],
];
