<?php

use App\Enums\FamilyFeudPhase;
use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\FamilyFeudQuestion;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Services\FamilyFeud\JudgeService;
use Livewire\Component;

new class extends Component
{
    public const MIN_ROUNDS = 1;

    public const DEFAULT_ROUNDS = 5;

    /**
     * @var array{0: string, 1: string}
     */
    public array $teamNames = ['Team 1', 'Team 2'];

    public int $rounds = self::DEFAULT_ROUNDS;

    public bool $started = false;

    /**
     * @var list<array{name: string, score: int}>
     */
    public array $teams = [];

    /**
     * The client-safe shape only (id/prompt/answerCount) -- never the
     * hidden answers. See the class-level note below on why answers are
     * never held as a public property at all.
     *
     * @var list<array{id: int, prompt: string, answerCount: int}>
     */
    public array $questions = [];

    public int $currentQuestionIndex = 0;

    /**
     * Revealed answers for the *current* question only, keyed by index.
     * Legitimately safe to expose once revealed.
     *
     * @var array<int, array{text: string, points: int}>
     */
    public array $revealed = [];

    public int $strikes = 0;

    public int $pot = 0;

    public int $controllingTeam = 0;

    public FamilyFeudPhase $phase = FamilyFeudPhase::Play;

    /**
     * Points each team earned per round, e.g. roundHistory[0] = [0, 27].
     *
     * @var list<array{0: int, 1: int}>
     */
    public array $roundHistory = [];

    public string $guessInput = '';

    /**
     * The last guess that failed to reach the judge, kept only so Retry
     * can resubmit it without the host retyping it.
     */
    public ?string $pendingGuess = null;

    public ?string $message = null;

    public bool $messageIsError = false;

    public bool $turnOptionsOpen = false;

    public string $roundEndTitle = '';

    /**
     * setPlayerCount-style stepper for the setup screen, bounded by how
     * many questions are actually in the bank (each round consumes 2).
     */
    public function setRounds(int $rounds): void
    {
        $this->rounds = max(self::MIN_ROUNDS, min($this->maxRounds(), $rounds));
    }

    public function maxRounds(): int
    {
        return max(self::MIN_ROUNDS, intdiv(FamilyFeudQuestion::count(), 2));
    }

    public function startGame(): void
    {
        $chosen = FamilyFeudQuestion::inRandomOrder()
            ->limit($this->rounds * 2)
            ->get()
            ->map(fn (FamilyFeudQuestion $question) => $question->toSafeArray())
            ->all();

        $this->teams = [
            ['name' => $this->resolvedTeamName(0), 'score' => 0],
            ['name' => $this->resolvedTeamName(1), 'score' => 0],
        ];
        $this->questions = $chosen;
        $this->currentQuestionIndex = 0;
        $this->revealed = [];
        $this->strikes = 0;
        $this->pot = 0;
        $this->controllingTeam = 0;
        $this->phase = FamilyFeudPhase::Play;
        $this->roundHistory = array_fill(0, $this->rounds, [0, 0]);
        $this->started = true;
        $this->message = null;
        $this->pendingGuess = null;
    }

    private function resolvedTeamName(int $index): string
    {
        $typed = trim($this->teamNames[$index] ?? '');

        return $typed !== '' ? $typed : 'Team '.($index + 1);
    }

    public function submitGuess(JudgeService $judge): void
    {
        $guess = trim($this->guessInput);

        if ($guess === '') {
            return;
        }

        $this->guessInput = '';
        $this->judgeGuess($judge, $guess);
    }

    public function retryGuess(JudgeService $judge): void
    {
        if ($this->pendingGuess === null) {
            return;
        }

        $this->judgeGuess($judge, $this->pendingGuess);
    }

    private function judgeGuess(JudgeService $judge, string $guess): void
    {
        $question = FamilyFeudQuestion::find($this->currentQuestion()['id']);
        $result = $judge->judge($question->answers, $guess);

        if ($result === null) {
            $this->pendingGuess = $guess;
            $this->message = "Couldn't reach the judge.";
            $this->messageIsError = true;

            return;
        }

        $this->pendingGuess = null;
        $this->handleJudgeResult($result);
    }

    /**
     * @param  array{matchIndex: int|null, text?: string, points?: int}  $result
     */
    private function handleJudgeResult(array $result): void
    {
        $matchIndex = $result['matchIndex'];
        $isNewMatch = $matchIndex !== null && ! isset($this->revealed[$matchIndex]);

        if ($this->phase === FamilyFeudPhase::Play) {
            if ($isNewMatch) {
                $this->revealed[$matchIndex] = ['text' => $result['text'], 'points' => $result['points']];
                $this->pot += $result['points'];
                $this->message = "Match! {$result['text']} ({$result['points']} pts)";
                $this->messageIsError = false;

                if (count($this->revealed) === $this->currentQuestion()['answerCount']) {
                    $this->roundEnd($this->controllingTeam);
                }
            } elseif ($matchIndex !== null) {
                $this->message = 'Already up there.';
                $this->messageIsError = false;
            } else {
                $this->strikes++;
                $this->message = "No match. Strike {$this->strikes}.";
                $this->messageIsError = false;

                if ($this->strikes >= 3) {
                    $this->phase = FamilyFeudPhase::Steal;
                }
            }
        } elseif ($this->phase === FamilyFeudPhase::Steal) {
            $otherTeam = 1 - $this->controllingTeam;

            if ($isNewMatch) {
                $this->revealed[$matchIndex] = ['text' => $result['text'], 'points' => $result['points']];
                $this->message = "Steal! {$result['text']} ({$result['points']} pts)";
                $this->messageIsError = false;
                $this->roundEnd($otherTeam);
            } else {
                $this->message = 'No steal.';
                $this->messageIsError = false;
                $this->roundEnd($this->controllingTeam);
            }
        }
    }

    private function roundEnd(int $winningTeam): void
    {
        $this->teams[$winningTeam]['score'] += $this->pot;
        $this->recordRoundPoints($winningTeam, $this->pot);
        $this->phase = FamilyFeudPhase::RoundEnd;
        $this->roundEndTitle = "{$this->teams[$winningTeam]['name']} win the pot! (+{$this->pot})";

        // No network round-trip needed to reveal the rest (unlike the
        // legacy version's Netlify function) -- we already have direct,
        // synchronous access to the same database.
        $question = FamilyFeudQuestion::find($this->currentQuestion()['id']);

        foreach ($question->answers as $i => $answer) {
            $this->revealed[$i] ??= ['text' => $answer['text'], 'points' => $answer['points']];
        }
    }

    private function recordRoundPoints(int $winningTeam, int $points): void
    {
        $roundIndex = intdiv($this->currentQuestionIndex, 2);
        $this->roundHistory[$roundIndex][$winningTeam] += $points;
    }

    public function nextRound(): void
    {
        if ($this->currentQuestionIndex % 2 === 0) {
            $this->advanceQuestion();
        } else {
            $this->phase = FamilyFeudPhase::RoundSummary;
        }
    }

    public function nextRoundSummary(): void
    {
        $this->advanceQuestion();
    }

    private function advanceQuestion(): void
    {
        $this->turnOptionsOpen = false;
        $this->currentQuestionIndex++;

        if ($this->currentQuestionIndex >= count($this->questions)) {
            $this->endGame();

            return;
        }

        $this->revealed = [];
        $this->strikes = 0;
        $this->pot = 0;
        $this->controllingTeam = $this->currentQuestionIndex % 2;
        $this->phase = FamilyFeudPhase::Play;
        $this->message = null;
    }

    public function skipQuestion(): void
    {
        $usedIds = collect($this->questions)->pluck('id');
        $replacement = FamilyFeudQuestion::whereNotIn('id', $usedIds)->inRandomOrder()->first();

        if (! $replacement) {
            $this->message = 'No spare questions left in the bank to skip to.';
            $this->messageIsError = true;

            return;
        }

        $this->questions[$this->currentQuestionIndex] = $replacement->toSafeArray();
        $this->revealed = [];
        $this->strikes = 0;
        $this->pot = 0;
        $this->phase = FamilyFeudPhase::Play;
        $this->turnOptionsOpen = false;
        $this->message = null;
    }

    public function swapControl(): void
    {
        $this->controllingTeam = 1 - $this->controllingTeam;
        $this->turnOptionsOpen = false;
    }

    public function toggleTurnOptions(): void
    {
        $this->turnOptionsOpen = ! $this->turnOptionsOpen;
    }

    public function revealManually(): void
    {
        $question = FamilyFeudQuestion::find($this->currentQuestion()['id']);

        foreach ($question->answers as $i => $answer) {
            $this->revealed[$i] = ['text' => $answer['text'], 'points' => $answer['points']];
        }

        $this->phase = FamilyFeudPhase::Manual;
        $this->message = null;
    }

    public function awardManual(int $teamIndex): void
    {
        $this->teams[$teamIndex]['score'] += $this->pot;
        $this->recordRoundPoints($teamIndex, $this->pot);
        $this->pot = 0;
        $this->phase = FamilyFeudPhase::RoundEnd;
        $this->roundEndTitle = "{$this->teams[$teamIndex]['name']} take the round.";
    }

    public function endGame(): void
    {
        if ($this->phase === FamilyFeudPhase::GameEnd) {
            return;
        }

        $this->phase = FamilyFeudPhase::GameEnd;

        $session = GameSession::create([
            'type' => GameType::FamilyFeud,
            'mode' => GameMode::Local,
            'settings' => ['rounds' => count($this->roundHistory)],
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        $topScore = max($this->teams[0]['score'], $this->teams[1]['score']);
        $tie = $this->teams[0]['score'] === $this->teams[1]['score'];

        foreach ($this->teams as $team) {
            $result = match (true) {
                $tie => GameResult::Draw,
                $team['score'] === $topScore => GameResult::Win,
                default => GameResult::Loss,
            };

            GameParticipant::create([
                'game_session_id' => $session->id,
                'guest_name' => $team['name'],
                'score' => $team['score'],
                'result' => $result,
            ]);
        }
    }

    public function newGame(): void
    {
        $this->started = false;
        $this->teams = [];
        $this->questions = [];
        $this->message = null;
        $this->pendingGuess = null;
    }

    /**
     * @return array{id: int, prompt: string, answerCount: int}
     */
    public function currentQuestion(): array
    {
        return $this->questions[$this->currentQuestionIndex];
    }

    public function turnTeam(): int
    {
        return $this->phase === FamilyFeudPhase::Steal ? 1 - $this->controllingTeam : $this->controllingTeam;
    }

    public function roundNumber(): int
    {
        return intdiv($this->currentQuestionIndex, 2) + 1;
    }

    public function totalRounds(): int
    {
        return intdiv(count($this->questions), 2);
    }

    public function winnerText(): string
    {
        if ($this->teams[0]['score'] === $this->teams[1]['score']) {
            return "It's a tie!";
        }

        $winner = $this->teams[0]['score'] > $this->teams[1]['score'] ? $this->teams[0] : $this->teams[1];

        return "{$winner['name']} wins!";
    }
};
?>

<div class="flex flex-col items-center gap-6 w-full max-w-3xl">
    @if (! $started)
        <div class="flex flex-col items-center gap-5 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-8">
            <h1 class="text-accent font-bold tracking-wide text-2xl">Family Feud</h1>

            <div class="flex flex-col gap-3 w-full">
                <div class="flex flex-col gap-1">
                    <label for="team0" class="text-sm text-text/80">Team 1 name</label>
                    <input id="team0" type="text" wire:model="teamNames.0" maxlength="24"
                        class="w-full rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                </div>
                <div class="flex flex-col gap-1">
                    <label for="team1" class="text-sm text-text/80">Team 2 name</label>
                    <input id="team1" type="text" wire:model="teamNames.1" maxlength="24"
                        class="w-full rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                </div>
            </div>

            <div class="flex items-center justify-between w-full">
                <p class="text-sm text-text/80 font-bold">Rounds</p>
                <div class="flex items-center gap-3">
                    <button type="button" wire:click="setRounds({{ $rounds - 1 }})" @disabled($rounds <= self::MIN_ROUNDS)
                        class="rounded w-9 h-9 font-bold bg-surface border-2 border-surface-border text-text disabled:opacity-40">
                        &minus;
                    </button>
                    <span class="min-w-[2ch] text-center font-bold text-text">{{ $rounds }}</span>
                    <button type="button" wire:click="setRounds({{ $rounds + 1 }})" @disabled($rounds >= $this->maxRounds())
                        class="rounded w-9 h-9 font-bold bg-surface border-2 border-surface-border text-text disabled:opacity-40">
                        +
                    </button>
                </div>
            </div>
            <p class="text-xs text-text/50 text-center">Each round, both teams get a turn — Team 1 goes first.</p>

            <button type="button" wire:click="startGame" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                Start game
            </button>
        </div>
    @else
        <div class="flex items-center justify-between w-full text-sm text-text/80">
            <a href="{{ route('family-feud.play') }}" class="hover:text-text">&larr; Home</a>

            @unless ($phase === FamilyFeudPhase::GameEnd)
                <div class="flex gap-4">
                    @if ($phase === FamilyFeudPhase::Play || $phase === FamilyFeudPhase::Steal)
                        <button type="button" wire:click="revealManually" wire:confirm="This shows every hidden answer and ends secrecy for this question. Continue?"
                            class="hover:text-text">
                            Reveal
                        </button>
                    @endif
                    <button type="button" wire:click="endGame" wire:confirm="End the game now and show final scores?" class="hover:text-text">
                        End game
                    </button>
                </div>
            @endif
        </div>

        @unless ($phase === FamilyFeudPhase::GameEnd || $phase === FamilyFeudPhase::RoundSummary)
            <div class="flex items-center justify-between w-full max-w-md">
                <div class="flex flex-col items-center gap-1 flex-1 {{ $this->turnTeam() === 0 && ($phase === FamilyFeudPhase::Play || $phase === FamilyFeudPhase::Steal) ? 'text-accent' : 'text-text' }}">
                    <span class="font-bold">{{ $teams[0]['name'] }}</span>
                    <span class="text-2xl font-bold">{{ $teams[0]['score'] }}</span>
                </div>
                <div class="flex flex-col items-center gap-1">
                    <span class="text-xs text-text/65">Round {{ $this->roundNumber() }} of {{ $this->totalRounds() }}</span>
                    <span class="text-sm text-text/80">Pot: {{ $pot }}</span>
                </div>
                <div class="flex flex-col items-center gap-1 flex-1 {{ $this->turnTeam() === 1 && ($phase === FamilyFeudPhase::Play || $phase === FamilyFeudPhase::Steal) ? 'text-accent' : 'text-text' }}">
                    <span class="font-bold">{{ $teams[1]['name'] }}</span>
                    <span class="text-2xl font-bold">{{ $teams[1]['score'] }}</span>
                </div>
            </div>

            <p class="text-lg font-bold text-text text-center">{{ $this->currentQuestion()['prompt'] }}</p>

            <div class="grid grid-cols-2 gap-2 w-full">
                @for ($i = 0; $i < $this->currentQuestion()['answerCount']; $i++)
                    <div wire:key="tile-{{ $i }}" class="flex items-center gap-3 rounded border-2 border-surface-border bg-surface px-3 py-2">
                        <span class="text-text/50 font-bold">{{ $i + 1 }}</span>
                        @if (isset($revealed[$i]))
                            <span class="flex-1 text-text font-bold">{{ strtoupper($revealed[$i]['text']) }}</span>
                            <span class="text-accent font-bold">{{ $revealed[$i]['points'] }}</span>
                        @endif
                    </div>
                @endfor
            </div>

            <div class="flex gap-3">
                @for ($i = 0; $i < 3; $i++)
                    <div class="w-10 h-10 rounded border-2 flex items-center justify-center font-bold text-xl {{ $i < $strikes ? 'bg-warn/20 border-warn text-warn' : 'bg-surface border-surface-border text-text/30' }}">
                        &times;
                    </div>
                @endfor
            </div>

            @if ($message)
                <p class="text-sm {{ $messageIsError ? 'text-warn' : 'text-accent' }}">{{ $message }}</p>
            @endif
        @endunless

        @if ($phase === FamilyFeudPhase::Play || $phase === FamilyFeudPhase::Steal)
            @if ($pendingGuess !== null)
                <button type="button" wire:click="retryGuess" class="rounded px-6 py-2 font-bold bg-accent text-bg">
                    Retry
                </button>
            @else
                <form wire:submit="submitGuess" class="w-full max-w-md flex gap-2">
                    <input type="text" wire:model="guessInput" placeholder="Type the answer..." autocomplete="off"
                        wire:loading.attr="disabled" wire:target="submitGuess"
                        class="flex-1 rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="submitGuess"
                        class="rounded px-6 py-2 font-bold bg-accent text-bg disabled:opacity-50">
                        <span wire:loading.remove wire:target="submitGuess">Submit</span>
                        <span wire:loading wire:target="submitGuess">Checking...</span>
                    </button>
                </form>
            @endif

            <button type="button" wire:click="toggleTurnOptions" class="text-sm text-text/65 hover:text-text">
                Options {{ $turnOptionsOpen ? '▲' : '▼' }}
            </button>

            @if ($turnOptionsOpen)
                <div class="flex gap-3">
                    <button type="button" wire:click="skipQuestion" wire:confirm="Skip this question and try a different one? Any points or strikes so far on it will be lost."
                        class="rounded px-4 py-2 font-bold bg-surface border-2 border-surface-border text-text">
                        Skip question
                    </button>
                    @if ($phase === FamilyFeudPhase::Play)
                        <button type="button" wire:click="swapControl" class="rounded px-4 py-2 font-bold bg-surface border-2 border-surface-border text-text">
                            Swap team
                        </button>
                    @endif
                </div>
            @endif
        @endif

        @if ($phase === FamilyFeudPhase::Manual)
            <div class="flex flex-col items-center gap-3 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-6">
                <p class="text-sm text-text/80 text-center">The hidden answers are now shown above. Talk it through, then award the pot to whichever team you agree got it.</p>
                <div class="flex gap-3 w-full">
                    <button type="button" wire:click="awardManual(0)" class="flex-1 rounded px-4 py-2 font-bold bg-accent text-bg">
                        Award {{ $teams[0]['name'] }}
                    </button>
                    <button type="button" wire:click="awardManual(1)" class="flex-1 rounded px-4 py-2 font-bold bg-accent text-bg">
                        Award {{ $teams[1]['name'] }}
                    </button>
                </div>
            </div>
        @endif

        @if ($phase === FamilyFeudPhase::RoundEnd)
            <div class="flex flex-col items-center gap-3 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-6">
                <p class="text-text font-bold text-center">{{ $roundEndTitle }}</p>
                <button type="button" wire:click="nextRound" class="w-full rounded px-6 py-2 font-bold bg-accent text-bg">
                    {{ $currentQuestionIndex % 2 === 0 ? 'Next question' : 'See round summary' }}
                </button>
            </div>
        @endif

        @if ($phase === FamilyFeudPhase::RoundSummary)
            @php $justFinished = intdiv($currentQuestionIndex, 2); @endphp
            <div class="flex flex-col items-center gap-4 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-6">
                <p class="text-text font-bold text-center">Round {{ $justFinished + 1 }} complete</p>
                <div class="flex justify-between w-full">
                    <div class="flex flex-col items-center gap-1">
                        <span class="text-sm text-text/80">{{ $teams[0]['name'] }}</span>
                        <span class="text-xl font-bold text-text">+{{ $roundHistory[$justFinished][0] }}</span>
                    </div>
                    <div class="flex flex-col items-center gap-1">
                        <span class="text-sm text-text/80">{{ $teams[1]['name'] }}</span>
                        <span class="text-xl font-bold text-text">+{{ $roundHistory[$justFinished][1] }}</span>
                    </div>
                </div>
                <button type="button" wire:click="nextRoundSummary" class="w-full rounded px-6 py-2 font-bold bg-accent text-bg">
                    {{ $currentQuestionIndex + 1 >= count($questions) ? 'See final score' : 'Next round' }}
                </button>
            </div>
        @endif

        @if ($phase === FamilyFeudPhase::GameEnd)
            <div class="flex flex-col items-center gap-4 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-6">
                <p class="text-accent font-bold text-xl">{{ $this->winnerText() }}</p>

                <table class="w-full text-sm text-text">
                    <thead>
                        <tr>
                            <th class="text-left p-1"></th>
                            <th class="p-1">{{ $teams[0]['name'] }}</th>
                            <th class="p-1">{{ $teams[1]['name'] }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roundHistory as $i => $result)
                            <tr wire:key="round-{{ $i }}">
                                <td class="p-1">Round {{ $i + 1 }}</td>
                                <td class="p-1 text-center">{{ $result[0] }}</td>
                                <td class="p-1 text-center">{{ $result[1] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="font-bold">
                        <tr>
                            <td class="p-1">Total</td>
                            <td class="p-1 text-center">{{ $teams[0]['score'] }}</td>
                            <td class="p-1 text-center">{{ $teams[1]['score'] }}</td>
                        </tr>
                    </tfoot>
                </table>

                <button type="button" wire:click="newGame" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                    New game
                </button>
            </div>
        @endif
    @endif
</div>
