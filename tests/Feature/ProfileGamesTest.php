<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function anyGameParticipantFor(User $user, GameType $type = GameType::Boggle, ?GameResult $result = null): GameParticipant
{
    $session = GameSession::factory()->create(['type' => $type, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'result' => $result,
    ]);
}

test('guests are redirected to login', function () {
    $this->get('/profile/games')->assertRedirect('/login');
});

test('the page renders for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile/games')
        ->assertSeeLivewire('pages::profile.games.index');
});

test('an empty state is shown when the user has no games', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile.games.index')
        ->assertSee('No games recorded yet.');
});

test('games from every type are listed, newest first', function () {
    $user = User::factory()->create();

    $boggle = anyGameParticipantFor($user, GameType::Boggle);
    $yahtzee = anyGameParticipantFor($user, GameType::Yahtzee);
    $feud = anyGameParticipantFor($user, GameType::FamilyFeud);
    $farkle = anyGameParticipantFor($user, GameType::Farkle);

    $component = Livewire::actingAs($user)->test('pages::profile.games.index');

    expect($component->get('games')->pluck('id')->all())->toBe([$farkle->id, $feud->id, $yahtzee->id, $boggle->id]);
});

test('only the acting user\'s own games are listed', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    anyGameParticipantFor($user);
    anyGameParticipantFor($otherUser);

    $component = Livewire::actingAs($user)->test('pages::profile.games.index');

    expect($component->get('games'))->toHaveCount(1);
});

test('the list is paginated', function () {
    $user = User::factory()->create();

    foreach (range(1, 25) as $i) {
        anyGameParticipantFor($user);
    }

    $component = Livewire::actingAs($user)->test('pages::profile.games.index');

    expect($component->get('games'))->toHaveCount(20)
        ->and($component->get('games')->total())->toBe(25);

    $component->call('nextPage');

    expect($component->get('games'))->toHaveCount(5);
});

test('each game links to its own type\'s show page', function () {
    $user = User::factory()->create();

    $boggle = anyGameParticipantFor($user, GameType::Boggle);
    $yahtzee = anyGameParticipantFor($user, GameType::Yahtzee);
    $feud = anyGameParticipantFor($user, GameType::FamilyFeud);
    $farkle = anyGameParticipantFor($user, GameType::Farkle);

    Livewire::actingAs($user)
        ->test('pages::profile.games.index')
        ->assertSeeHtml(route('profile.boggle.show', $boggle))
        ->assertSeeHtml(route('profile.yahtzee.show', $yahtzee))
        ->assertSeeHtml(route('profile.family-feud.show', $feud))
        ->assertSeeHtml(route('profile.farkle.show', $farkle));
});

test('a win is shown distinctly from a loss or no result', function () {
    $user = User::factory()->create();
    anyGameParticipantFor($user, GameType::Boggle, GameResult::Win);

    Livewire::actingAs($user)
        ->test('pages::profile.games.index')
        ->assertSee('Win');
});

test('the dashboard links to the all-games page', function () {
    $user = User::factory()->create();

    // The link now lives in the sidebar (see resources/views/components/
    // app-sidebar.blade.php), which is part of the layout, not the
    // Livewire component's own template -- Livewire::test() only renders
    // the latter, so this needs a real full-page request to see it.
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.games'), escape: false);
});
