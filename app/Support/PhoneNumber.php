<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 台灣手機號碼正規化：去掉空白、連字號，+886 / 886 開頭換成 0，結果必須是 09 開頭的 10 碼。
 * 同一支手機不論怎麼輸入，都會得到相同字串，「同手機只能一筆」的檢查才可靠。
 */
final class PhoneNumber
{
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/[\s\-()]/', '', $raw) ?? '';
        $digits = preg_replace('/^\+?886/', '0', $digits) ?? '';

        if (preg_match('/^09\d{8}$/', $digits) !== 1) {
            throw new InvalidArgumentException('請輸入正確的手機號碼（09 開頭共 10 碼）');
        }

        return $digits;
    }
}
