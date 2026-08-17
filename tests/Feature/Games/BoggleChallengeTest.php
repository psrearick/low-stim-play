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

test('a completed solo game gets a session id for building a challenge link', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 0])
        ->call('endGame')
        ->assertSet('ended', true)
        ->tap(function ($component) {
            expect($component->get('gameSessionId'))->toBe(GameSession::sole()->id);
        });
});

test('the share link redirects to the play page with the session referenced', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Solo,
        'settings' => ['size' => 5, 'seed' => 42, 'minutes' => 2],
    ]);

    $this->get(route('boggle.share', ['gameSession' => $session->id]))
        ->assertRedirect(route('boggle.play', ['size' => 5, 'minutes' => 2, 'challenge' => $session->id]));
});

test('the share link 404s for a non-Boggle session', function () {
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee]);

    $this->get(route('boggle.share', ['gameSession' => $session->id]))->assertNotFound();
});

test('opening a challenge link reproduces the exact same board', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Solo,
        'settings' => ['size' => 4, 'seed' => 99, 'minutes' => 3],
    ]);

    $component = Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id]);

    expect($component->get('board')['seed'])->toBe(99)
        ->and($component->get('size'))->toBe(4)
        ->and($component->get('minutes'))->toBe(3)
        ->and($component->get('isSharedSession'))->toBeTrue();
});

test('finishing a challenge joins the original session instead of creating a new one', function () {
    $originalPlayer = User::factory()->create();
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Solo,
        'settings' => ['size' => 4, 'seed' => 7, 'minutes' => 3],
    ]);
    GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $originalPlayer->id,
        'score' => 8,
        'stats' => ['found_words' => ['cat' => 3, 'dog' => 5]],
    ]);

    $friend = User::factory()->create();

    Livewire::actingAs($friend)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id])
        ->set('foundWords', ['owl' => 5, 'fox' => 7])
        ->set('score', 12)
        ->call('endGame');

    expect(GameSession::count())->toBe(1)
        ->and(GameParticipant::count())->toBe(2);

    $friendParticipant = GameParticipant::where('user_id', $friend->id)->sole();
    $originalParticipant = GameParticipant::where('user_id', $originalPlayer->id)->sole();

    expect($friendParticipant->result)->toBe(GameResult::Win)
        ->and($originalParticipant->result)->toBe(GameResult::Loss);
});

test('the reveal screen shows the other player once they have also finished', function () {
    $originalPlayer = User::factory()->create(['name' => 'Alex']);
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Solo,
        'settings' => ['size' => 4, 'seed' => 7, 'minutes' => 3],
    ]);
    GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $originalPlayer->id,
        'score' => 8,
        'stats' => ['found_words' => ['cat' => 3, 'dog' => 5]],
    ]);

    $friend = User::factory()->create();

    Livewire::actingAs($friend)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id])
        ->call('endGame')
        ->assertSee('Alex: 8');
});

/**
 * Regression coverage for a real duplicate-row bug found by inspecting
 * production data: mount() never checked whether the acting user already
 * had a GameParticipant row for this shared session, so reloading the
 * page right after finishing (e.g. a page refresh) started a fresh,
 * replayable attempt and finishGame() created a second row for the same
 * session/user on completion -- corrupting SharedSessionFinalizer's
 * per-word finder counts along the way. See docs/DEVLOG.md's Boggle
 * section for the full story.
 */
test('remounting a finished shared session restores the ended state instead of allowing a replay', function () {
    $player = User::factory()->create();
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Solo,
        'settings' => ['size' => 4, 'seed' => 7, 'minutes' => 3],
    ]);

    Livewire::actingAs($player)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id])
        ->set('foundWords', ['owl' => 5])
        ->set('score', 5)
        ->call('endGame');

    expect(GameParticipant::count())->toBe(1);

    // A fresh mount -- standing in for a page refresh -- must not let the
    // same account start a brand new, replayable attempt at this session.
    Livewire::actingAs($player)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id])
        ->assertSet('ended', true)
        ->assertSet('score', 5)
        ->assertSet('foundWords', ['owl' => 5]);

    expect(GameParticipant::count())->toBe(1);
});

test('finishing the same shared session twice for the same account updates one row instead of creating a duplicate', function () {
    $player = User::factory()->create();
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Solo,
        'settings' => ['size' => 4, 'seed' => 7, 'minutes' => 3],
    ]);

    // Simulates the race the bug allowed: two separate component instances
    // (e.g. two tabs, or a reload racing the "already ended" check) both
    // reach finishGame() for the same account before either sees the
    // other's row -- the persistence layer itself must still de-duplicate.
    Livewire::actingAs($player)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id])
        ->set('foundWords', ['owl' => 5])
        ->set('score', 5)
        ->call('endGame');

    Livewire::actingAs($player)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 7, 'challenge' => $session->id])
        ->set('ended', false)
        ->set('foundWords', ['owl' => 5, 'fox' => 7])
        ->set('score', 12)
        ->call('endGame');

    expect(GameParticipant::where('user_id', $player->id)->count())->toBe(1);
    expect(GameParticipant::where('user_id', $player->id)->sole()->score)->toBe(12);
});

test('guest players are unaffected by the authenticated-user duplicate guard', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Solo,
        'settings' => ['size' => 4, 'seed' => 7, 'minutes' => 3],
    ]);

    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id])
        ->set('foundWords', ['owl' => 5])
        ->set('score', 5)
        ->call('endGame');

    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id])
        ->assertSet('ended', false);

    expect(GameParticipant::whereNull('user_id')->count())->toBe(1);
});

test('resetting is a no-op for a challenge game', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Solo,
        'settings' => ['size' => 4, 'seed' => 7, 'minutes' => 3],
    ]);

    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'challenge' => $session->id])
        ->call('endGame')
        ->call('resetGame')
        ->assertSet('ended', true);
});
