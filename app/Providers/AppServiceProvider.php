<?php

namespace App\Providers;

use App\Services\Boggle\Solver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Building the word trie means reading ~79k words; a singleton
        // means that only happens once per worker instead of per request.
        $this->app->singleton(Solver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
