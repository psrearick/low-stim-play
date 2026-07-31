<?php

namespace App\Providers;

use App\Http\Requests\RememberedPasskeyVerificationRequest;
use App\Services\Boggle\Solver;
use App\Services\FamilyFeud\JudgeService;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

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

        $this->app->bind(JudgeService::class, fn () => new JudgeService(
            apiKey: config('services.openrouter.key'),
            model: config('services.openrouter.model'),
        ));

        // See RememberedPasskeyVerificationRequest's docblock -- makes
        // every passkey login "remembered" since the JS package has no way
        // to request that itself.
        $this->app->bind(PasskeyVerificationRequest::class, RememberedPasskeyVerificationRequest::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
