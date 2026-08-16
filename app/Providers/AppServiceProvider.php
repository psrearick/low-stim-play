<?php

namespace App\Providers;

use App\Analytics\Contracts\EventWriter;
use App\Analytics\DatabaseEventWriter;
use App\Analytics\Geo\CountryResolver;
use App\Http\Requests\RememberedPasskeyVerificationRequest;
use App\Models\User;
use App\Services\Boggle\Solver;
use App\Services\FamilyFeud\JudgeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

        // Same reasoning: the mmdb reader should only open its database
        // file once per worker, not once per request.
        $this->app->singleton(CountryResolver::class);

        $this->app->bind(EventWriter::class, DatabaseEventWriter::class);

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
        // No is_admin column or admin concept exists anywhere else in
        // this app, so config('analytics.dashboard.gate') is satisfied
        // by a plain comma-separated allow-list rather than new schema --
        // see docs/DEVLOG.md's Analytics section.
        Gate::define('view-analytics', function (User $user) {
            $allowed = array_filter(array_map(
                trim(...),
                explode(',', (string) config('analytics.admin_emails')),
            ));

            return in_array(Str::lower($user->email), array_map(Str::lower(...), $allowed), true);
        });
    }
}
