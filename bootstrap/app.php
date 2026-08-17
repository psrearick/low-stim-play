<?php

use App\Analytics\Console\Commands\AnalyticsPrune;
use App\Analytics\Console\Commands\AnalyticsRollup;
use App\Analytics\Http\Middleware\RecordPageview;
use App\Console\Commands\PruneGuestAccounts;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    // Everything under app/Analytics/ (see docs/specs/analytics.md) lives
    // outside the framework's default app/Console/Commands scan path, so
    // it needs an explicit entry alongside it here.
    ->withCommands([
        app_path('Console/Commands'),
        app_path('Analytics/Console/Commands'),
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // On Laravel Cloud the app sits behind a load balancer. Without
        // this, $request->ip() returns the balancer's own address and
        // every visitor's App\Analytics\Http\Middleware\RecordPageview
        // hash collapses to one -- see analytics:doctor, the acceptance
        // gate for this.
        $middleware->trustProxies(at: '*');

        $middleware->appendToGroup('web', RecordPageview::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(PruneGuestAccounts::class)->daily();

        // Refreshes today and yesterday every hour so late-arriving rows
        // and timezone edges stay current between visits to the
        // dashboard.
        $schedule->command(AnalyticsRollup::class, ['--days=2'])->hourly();
        $schedule->command(AnalyticsPrune::class)->dailyAt('03:15');
    })
    ->create();
