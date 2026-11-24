<?php

use App\Support\PhoneNumber;

it('normalizes common phone formats', function (string $raw) {
    expect(PhoneNumber::normalize($raw))->toBe('0912345678');
})->with(['0912345678', '0912-345-678', '0912 345 678', '+886912345678', '+886 912 345 678', '886912345678']);

it('rejects numbers that are not Taiwan mobile numbers', function (string $raw) {
    PhoneNumber::normalize($raw);
})->with(['', '02-2345-6789', '091234567', '09123456789', 'abc'])->throws(InvalidArgumentException::class);
