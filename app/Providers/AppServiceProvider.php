<?php

namespace App\Providers;

use App\Domain\Estimation\WaitEstimator;
use App\Domain\Seating\OptionFinder;
use App\Domain\Seating\SeatingAllocator;
use App\Domain\Seating\SkipRule;
use App\Services\QueueBroadcaster;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Domain 物件的參數取自 config；用 bind（非 singleton），模擬器與測試改 config 時才會生效
        // 同一個請求內共用，才能把同一交易內的多次推播合併成一次
        $this->app->scoped(QueueBroadcaster::class);

        $this->app->bind(OptionFinder::class, fn () => new OptionFinder(
            (int) config('queuetable.max_waste'),
            (int) config('queuetable.relaxed_max_waste'),
            (int) config('queuetable.relax_after_minutes'),
        ));
        $this->app->bind(SeatingAllocator::class, fn ($app) => new SeatingAllocator($app->make(OptionFinder::class), (int) config('queuetable.max_skips')));
        $this->app->bind(SkipRule::class, fn ($app) => new SkipRule($app->make(OptionFinder::class), (int) config('queuetable.max_skips')));
        $this->app->bind(WaitEstimator::class, fn ($app) => new WaitEstimator(
            $app->make(SeatingAllocator::class),
            $app->make(SkipRule::class),
            (int) config('queuetable.cleaning_minutes'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDevCommands();
    }

    /**
     * `composer run dev` 要同時啟動的程序：網站、Reverb、排程（過號檢查）、Vite。
     * 推播使用 ShouldBroadcastNow，不需要 queue worker。
     */
    protected function configureDevCommands(): void
    {
        if (! class_exists(DevCommands::class)) {
            return;
        }

        DevCommands::except('queue');
        DevCommands::artisan('schedule:work', 'schedule');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
