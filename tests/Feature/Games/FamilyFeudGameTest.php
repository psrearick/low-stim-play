<?php

use App\Enums\FamilyFeudPhase;
use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\FamilyFeudQuestion;
use App\Models\GameParticipant;
use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fakeJudge(?int $matchIndex): void
{
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['content' => json_encode(['matchIndex' => $matchIndex === null ? null : $matchIndex + 1])]],
            ],
        ]),
    ]);
}

/**
 * A single question with 4 answers, used across most tests so match
 * indices are predictable.
 */
function makeQuestion(): FamilyFeudQuestion
{
    return FamilyFeudQuestion::factory()->create([
        'prompt' => 'Name something round.',
        'answers' => [
            ['text' => 'Ball', 'points' => 40],
            ['text' => 'Wheel', 'points' => 30],
            ['text' => 'Coin', 'points' => 20],
            ['text' => 'Plate', 'points' => 10],
        ],
    ]);
}

test('the setup screen renders', function () {
    $this->get('/family-feud')->assertSeeLivewire('pages::family-feud.play');
});

test('starting the game with no questions in the bank shows an error instead of crashing', function () {
    // Regression test: on a fresh/misconfigured deployment where the
    // family_feud_questions table hasn't been seeded, startGame() used to
    // silently set $questions to an empty array, and the template's very
    // next render (currentQuestion()) crashed with an "Undefined array
    // key 0" fatal instead of a readable error.
    Livewire::test('pages::family-feud.play')
        ->call('startGame')
        ->assertSet('started', false)
        ->assertSet('messageIsError', true)
        ->assertSee('question bank needs to be seeded');
});

test('rounds are bounded by half the question bank size', function () {
    FamilyFeudQuestion::factory()->count(6)->create();

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 10)
        ->assertSet('rounds', 3);
});

test('starting the game only ever exposes the safe question shape, never the hidden answers', function () {
    FamilyFeudQuestion::factory()->count(4)->create();

    $component = Livewire::test('pages::family-feud.play')
        ->set('teamNames.0', 'Reds')
        ->set('teamNames.1', 'Blues')
        ->call('setRounds', 2)
        ->call('startGame')
        ->assertSet('started', true)
        ->assertSet('teams.0.name', 'Reds')
        ->assertSet('teams.1.name', 'Blues')
        ->assertCount('questions', 4);

    foreach ($component->get('questions') as $question) {
        expect($question)->toHaveKeys(['id', 'prompt', 'answerCount'])
            ->not->toHaveKey('answers');
    }
});

test('a matching guess reveals the tile and adds its points to the pot', function () {
    $question = makeQuestion();
    fakeJudge(matchIndex: 1); // "Wheel"

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray())
        ->set('guessInput', 'a tire')
        ->call('submitGuess')
        ->assertSet('revealed.1.text', 'Wheel')
        ->assertSet('pot', 30)
        ->assertSet('strikes', 0);
});

test('a non-matching guess adds a strike', function () {
    $question = makeQuestion();
    fakeJudge(matchIndex: null);

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray())
        ->set('guessInput', 'a spaceship')
        ->call('submitGuess')
        ->assertSet('strikes', 1)
        ->assertSet('pot', 0);
});

test('guessing an already-revealed answer again is not a strike', function () {
    $question = makeQuestion();
    fakeJudge(matchIndex: 0);

    $component = Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray())
        ->set('guessInput', 'ball')
        ->call('submitGuess')
        ->assertSet('pot', 40);

    $component->set('guessInput', 'a round ball')
        ->call('submitGuess')
        ->assertSet('strikes', 0)
        ->assertSet('pot', 40) // unchanged
        ->assertSet('message', 'Already up there.');
});

test('the third strike moves the turn to Steal', function () {
    $question = makeQuestion();
    fakeJudge(matchIndex: null);

    $component = Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray());

    $component->set('guessInput', 'a')->call('submitGuess');
    $component->set('guessInput', 'b')->call('submitGuess');
    $component->set('guessInput', 'c')->call('submitGuess')
        ->assertSet('strikes', 3)
        ->assertSet('phase', FamilyFeudPhase::Steal);
});

test('a successful steal awards the whole pot to the other team and ends the round', function () {
    $question = makeQuestion();

    $component = Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray())
        ->set('controllingTeam', 0)
        ->set('pot', 40)
        ->set('phase', FamilyFeudPhase::Steal)
        ->set('strikes', 3);

    fakeJudge(matchIndex: 1); // Team 2 (the non-controlling team) steals "Wheel"

    $component->set('guessInput', 'a tire')
        ->call('submitGuess')
        ->assertSet('phase', FamilyFeudPhase::RoundEnd)
        ->assertSet('teams.1.score', 40)
        ->assertSet('teams.0.score', 0);
});

test('a failed steal lets the controlling team keep the pot', function () {
    $question = makeQuestion();

    $component = Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray())
        ->set('controllingTeam', 0)
        ->set('pot', 40)
        ->set('phase', FamilyFeudPhase::Steal)
        ->set('strikes', 3);

    fakeJudge(matchIndex: null);

    $component->set('guessInput', 'nonsense')
        ->call('submitGuess')
        ->assertSet('phase', FamilyFeudPhase::RoundEnd)
        ->assertSet('teams.0.score', 40)
        ->assertSet('teams.1.score', 0);
});

test('revealing all answers ends the round for the controlling team', function () {
    $question = FamilyFeudQuestion::factory()->create([
        'answers' => [['text' => 'Only answer', 'points' => 50]],
    ]);

    fakeJudge(matchIndex: 0);

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray())
        ->set('guessInput', 'the only answer')
        ->call('submitGuess')
        ->assertSet('phase', FamilyFeudPhase::RoundEnd)
        ->assertSet('teams.0.score', 50);
});

test('a judge failure keeps the guess pending for retry, without scoring a strike', function () {
    $question = makeQuestion();

    // Http::fake() calls don't replace earlier registrations for the same
    // URL pattern -- the first-registered stub keeps matching first -- so
    // a fail-then-succeed retry has to be one sequence, not two separate
    // fake() calls.
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('', 500)
            ->push(['choices' => [['message' => ['content' => '{"matchIndex": 1}']]]]),
    ]);

    $component = Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray())
        ->set('guessInput', 'ball')
        ->call('submitGuess')
        ->assertSet('strikes', 0)
        ->assertSet('pendingGuess', 'ball')
        ->assertSet('messageIsError', true);

    $component->call('retryGuess')
        ->assertSet('pot', 40)
        ->assertSet('pendingGuess', null);
});

test('skipping a question swaps in an unused one and resets the turn', function () {
    $q1 = makeQuestion();
    $q2 = FamilyFeudQuestion::factory()->create();

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions', [$q1->toSafeArray()])
        ->set('strikes', 2)
        ->set('pot', 15)
        ->call('skipQuestion')
        ->assertSet('questions.0.id', $q2->id)
        ->assertSet('strikes', 0)
        ->assertSet('pot', 0);
});

test('skipping with no spare questions in the bank shows an error instead of crashing', function () {
    $question = makeQuestion();

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions', [$question->toSafeArray()])
        ->call('skipQuestion')
        ->assertSet('messageIsError', true)
        ->assertSet('questions.0.id', $question->id);
});

test('manual reveal shows every answer and lets the host award either team', function () {
    $question = makeQuestion();

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('questions.0', $question->toSafeArray())
        ->set('pot', 25)
        ->call('revealManually')
        ->assertSet('phase', FamilyFeudPhase::Manual)
        ->assertCount('revealed', 4)
        ->call('awardManual', 1)
        ->assertSet('phase', FamilyFeudPhase::RoundEnd)
        ->assertSet('teams.1.score', 25)
        ->assertSet('pot', 0);
});

test('ending the game records a session and ranked participants for both teams', function () {
    FamilyFeudQuestion::factory()->count(2)->create();

    Livewire::test('pages::family-feud.play')
        ->set('teamNames.0', 'Reds')
        ->set('teamNames.1', 'Blues')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('teams.0.score', 60)
        ->set('teams.1.score', 30)
        ->call('endGame')
        ->assertSet('phase', FamilyFeudPhase::GameEnd);

    $session = GameSession::sole();

    expect($session->type)->toBe(GameType::FamilyFeud)
        ->and($session->mode)->toBe(GameMode::Local);

    $reds = GameParticipant::where('guest_name', 'Reds')->sole();
    $blues = GameParticipant::where('guest_name', 'Blues')->sole();

    expect($reds->score)->toBe(60)
        ->and($reds->result)->toBe(GameResult::Win)
        ->and($blues->score)->toBe(30)
        ->and($blues->result)->toBe(GameResult::Loss);
});

test('a tied game records a draw for both teams', function () {
    FamilyFeudQuestion::factory()->count(2)->create();

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->set('teams.0.score', 40)
        ->set('teams.1.score', 40)
        ->call('endGame');

    expect(GameParticipant::pluck('result')->unique()->all())->toBe([GameResult::Draw]);
});

test('ending the game twice does not record a second session', function () {
    FamilyFeudQuestion::factory()->count(2)->create();

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->call('endGame')
        ->call('endGame');

    expect(GameSession::count())->toBe(1);
});

test('starting a new game returns to the setup screen', function () {
    FamilyFeudQuestion::factory()->count(2)->create();

    Livewire::test('pages::family-feud.play')
        ->call('setRounds', 1)
        ->call('startGame')
        ->call('endGame')
        ->call('newGame')
        ->assertSet('started', false)
        ->assertSet('teams', [])
        ->assertSet('questions', []);
});
