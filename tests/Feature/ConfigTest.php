<?php

it('uses Taipei timezone', function () {
    expect(config('app.timezone'))->toBe('Asia/Taipei');
});

it('configures sqlite for concurrent writers', function () {
    expect(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE')
        ->and(config('database.connections.sqlite.busy_timeout'))->toBe(5000)
        ->and(config('database.connections.sqlite.journal_mode'))->toBe('wal');
});

it('exposes queuetable constants', function () {
    expect(config('queuetable.max_skips'))->toBe(3)
        ->and(config('queuetable.no_show_minutes'))->toBe(3)
        ->and(config('queuetable.max_waste'))->toBe(2)
        ->and(config('queuetable.business_day_cutoff_hour'))->toBe(6);
});

it('disables self registration', function () {
    $this->get('/register')->assertNotFound();
});
