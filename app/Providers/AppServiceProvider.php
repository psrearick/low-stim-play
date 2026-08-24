<?php

namespace App\Providers;

use App\Analytics\Contracts\EventWriter;
use App\Analytics\DatabaseEventWriter;
use App\Analytics\Geo\CountryResolver;
use App\Events\RoomGameStateUpdated;
use App\Http\Requests\RememberedPasskeyVerificationRequest;
use App\Models\User;
use App\Services\Boggle\Solver;
use App\Services\FamilyFeud\JudgeService;
use App\Services\Quartiles\PuzzleGenerator;
use App\Services\SpellingBee\PuzzleGenerator as SpellingBeePuzzleGenerator;
use App\Services\Wordle\AnswerPicker;
use App\Services\Words\CommonWordFilter;
use Illuminate\Support\Facades\Event;
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

        // Same reasoning: filtering the ~79k-word file down to 8-16 letter
        // candidates should only happen once per worker, not once per
        // Quartiles puzzle generated.
        $this->app->singleton(PuzzleGenerator::class);

        // Same reasoning: the frequency corpus (~61k words) and the
        // filtered 5-letter answer pool built from it should only be read
        // once per worker, not once per Wordle game.
        $this->app->singleton(CommonWordFilter::class);
        $this->app->singleton(AnswerPicker::class);

        // Same reasoning: scanning the ~79k-word dictionary down to every
        // 4+-letter, excluded-words-filtered candidate (and the 7-distinct-
        // letter pangram subset of it) should only happen once per worker,
        // not once per Word Hive puzzle generated.
        $this->app->singleton(SpellingBeePuzzleGenerator::class);

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

        // Keeps a room alive for as long as it's genuinely being played --
        // see Room::extendExpiry()'s own docblock for the bug this fixes
        // (a room's fixed creation-time expiry silently 404ing every page
        // out from under still-active players on a long enough game).
        // Every room game already dispatches this event on every real
        // state change, so this one listener covers all of them.
        Event::listen(RoomGameStateUpdated::class, fn (RoomGameStateUpdated $event) => $event->room->extendExpiry());
    }
}
