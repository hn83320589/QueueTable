<?php

use App\Models\Party;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/simulation');
    File::ensureDirectoryExists($this->dir);
    config(['database.connections.simulation.database' => "{$this->dir}/sim.sqlite"]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function simulate(object $test, int $seed, string $json): array
{
    $test->artisan('queuetable:simulate', ['--seed' => $seed, '--date' => '2026-11-24', '--until' => '18:30', '--json' => $json])
        ->expectsOutputToContain('跳號檢查：PASS')
        ->assertExitCode(0);

    return json_decode(File::get($json), true);
}

it('runs a short evening and passes the skip check', function () {
    $result = simulate($this, 42, "{$this->dir}/a.json");

    expect($result['issued'])->toBeGreaterThan(0)
        ->and($result['max_skip_audited'])->toBeLessThanOrEqual(3)
        ->and($result['max_skip_audited'])->toBe($result['max_skip_stored'])
        ->and($result['utilization'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);
});

it('gives identical results for the same seed', function () {
    expect(simulate($this, 7, "{$this->dir}/a.json"))->toBe(simulate($this, 7, "{$this->dir}/b.json"));
});

it('gives different results for a different seed', function () {
    expect(simulate($this, 1, "{$this->dir}/a.json"))->not->toBe(simulate($this, 2, "{$this->dir}/b.json"));
});

it('leaves the main database untouched', function () {
    simulate($this, 42, "{$this->dir}/a.json");

    expect(Party::count())->toBe(0)
        ->and(config('database.default'))->toBe('sqlite');
});
