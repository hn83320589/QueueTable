<?php

use App\Domain\QueueLetter;

it('classifies party size into a queue', function (int $size, QueueLetter $expected) {
    expect(QueueLetter::fromPartySize($size))->toBe($expected);
})->with([
    [1, QueueLetter::A], [2, QueueLetter::A],
    [3, QueueLetter::B], [4, QueueLetter::B],
    [5, QueueLetter::C], [6, QueueLetter::C],
    [7, QueueLetter::D], [12, QueueLetter::D],
]);

it('rejects party sizes out of range', function (int $size) {
    QueueLetter::fromPartySize($size);
})->with([0, 13])->throws(InvalidArgumentException::class);
