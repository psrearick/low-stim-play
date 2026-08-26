<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\Friendship;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Notifications\GameChallengeReceived;
use App\Services\Social\GameChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setWordleChallengeAnswer($component, string $answer): void
{
    $session = GameSession::find($component->get('gameSessionId'));
    $session->update(['settings' => array_merge($session->settings, ['answer' => $answer])]);
}

test('a completed solo game gets a session id for building a challenge link', function () {
    Livewire::test('pages::wordle.play')
        ->call('startGame')
        ->set('currentGuess', 'zzzzz') // rejected as not a word -- just proves gameSessionId exists pre-finish
        ->call('submitGuess')
        ->tap(function ($component) {
            expect($component->get('gameSessionId'))->toBe(GameSession::sole()->id);
        });
});

test('the share link redirects to the play page referencing the session', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);

    $this->get(route('wordle.share', ['gameSession' => $session->id]))
        ->assertRedirect(route('wordle.play', ['challenge' => $session->id]));
});

test('the share link 404s for a non-Wordle session', function () {
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);

    $this->get(route('wordle.share', ['gameSession' => $session->id]))->assertNotFound();
});

test('opening a challenge link plays against the exact same secret answer, not a regenerated one', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);

    $component = Livewire::test('pages::wordle.play', ['challenge' => $session->id]);
    expect($component->get('isSharedSession'))->toBeTrue();

    $component->set('currentGuess', 'crane')->call('submitGuess');

    expect($component->get('won'))->toBeTrue();
});

test('finishing a challenge joins the original session instead of creating a new one', function () {
    $originalPlayer = User::factory()->create();
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);
    GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $originalPlayer->id,
        'score' => 500,
        'stats' => ['won' => true, 'guess_count' => 2, 'hard_mode' => false, 'guesses' => []],
    ]);

    $friend = User::factory()->create();

    Livewire::actingAs($friend)
        ->test('pages::wordle.play', ['challenge' => $session->id])
        ->set('currentGuess', 'crane')
        ->call('submitGuess');

    expect(GameSession::count())->toBe(1)
        ->and(GameParticipant::count())->toBe(2);
});

test('the reveal screen shows the other player once they have also finished', function () {
    $originalPlayer = User::factory()->create(['name' => 'Alex']);
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);
    GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $originalPlayer->id,
        'score' => 500,
        'stats' => ['won' => true, 'guess_count' => 2, 'hard_mode' => false, 'guesses' => []],
    ]);

    $friend = User::factory()->create();

    Livewire::actingAs($friend)
        ->test('pages::wordle.play', ['challenge' => $session->id])
        ->set('currentGuess', 'crane')
        ->call('submitGuess')
        ->assertSee('Alex')
        ->assertSee('2 guesses');
});

test('remounting a finished shared session restores the ended state instead of allowing a replay', function () {
    $player = User::factory()->create();
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);

    Livewire::actingAs($player)
        ->test('pages::wordle.play', ['challenge' => $session->id])
        ->set('currentGuess', 'crane')
        ->call('submitGuess');

    expect(GameParticipant::count())->toBe(1);

    Livewire::actingAs($player)
        ->test('pages::wordle.play', ['challenge' => $session->id])
        ->assertSet('ended', true)
        ->assertSet('won', true);

    expect(GameParticipant::count())->toBe(1);
});

test('a shared session\'s state is never overwritten by an in-progress guess, avoiding cross-player clobbering', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
        'state' => null,
    ]);

    Livewire::test('pages::wordle.play', ['challenge' => $session->id])
        ->set('currentGuess', 'stone') // wrong, doesn't end the game
        ->call('submitGuess');

    expect(GameSession::find($session->id)->state)->toBeNull();
});

test('resetting is a no-op for a challenge game', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);

    Livewire::test('pages::wordle.play', ['challenge' => $session->id])
        ->set('currentGuess', 'crane')
        ->call('submitGuess')
        ->call('resetGame')
        ->assertSet('ended', true);
});

test('the reveal screen offers a Challenge a Friend button for a signed-in player finishing an ordinary game, but not a daily or a shared session', function () {
    $user = User::factory()->create();

    $ordinary = Livewire::actingAs($user)->test('pages::wordle.play')->call('startGame');
    setWordleChallengeAnswer($ordinary, 'crane');
    $ordinary->set('currentGuess', 'crane')
        ->call('submitGuess')
        ->assertSee('Challenge a Friend');

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::wordle.play', ['dailyDate' => $date])
        ->assertDontSee('Challenge a Friend');

    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);

    Livewire::actingAs($user)
        ->test('pages::wordle.play', ['challenge' => $session->id])
        ->set('currentGuess', 'crane')
        ->call('submitGuess')
        ->assertDontSee('Challenge a Friend');
});

test('the button is absent for a guest', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleChallengeAnswer($component, 'crane');

    $component->set('currentGuess', 'crane')
        ->call('submitGuess')
        ->assertDontSee('Challenge a Friend');
});

test('sending a challenge notifies the selected friend with the shared session link', function () {
    Notification::fake();

    $user = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $friend->id]);

    $session = GameSession::factory()->create(['type' => GameType::Wordle]);
    $link = route('wordle.share', ['gameSession' => $session->id]);

    Livewire::actingAs($user)
        ->test('challenge-friend', ['gameType' => GameType::Wordle, 'link' => $link])
        ->call('send', $friend->id);

    Notification::assertSentTo($friend, GameChallengeReceived::class, function (GameChallengeReceived $notification) use ($user, $link) {
        return $notification->from->is($user)
            && $notification->gameType === GameType::Wordle
            && $notification->link === $link;
    });
});

test('the challenge link resolves via GameChallengeService to the shared-session share link', function () {
    $session = GameSession::factory()->create(['type' => GameType::Wordle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id]);

    $link = app(GameChallengeService::class)->linkFor($participant);

    expect($link)->toBe(route('wordle.share', ['gameSession' => $session->id]));
});
