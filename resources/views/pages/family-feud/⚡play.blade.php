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

    public function getRoundIndicatorProperty(): string
    {
        if ($this->phase === FamilyFeudPhase::GameEnd) {
            return 'Game over';
        }

        $turnTeam = $this->turnTeam();

        $suffix = match ($this->phase) {
            FamilyFeudPhase::Steal => ' — Steal: '.$this->teams[$turnTeam]['name'],
            FamilyFeudPhase::Play => ' — '.$this->teams[$turnTeam]['name']."'s turn",
            default => '',
        };

        return 'Round '.$this->roundNumber().' of '.$this->totalRounds().$suffix;
    }

    public function getInPlayProperty(): bool
    {
        return $this->phase === FamilyFeudPhase::Play || $this->phase === FamilyFeudPhase::Steal;
    }
};
?>

<x-slot:title>Family Feud — Low Stim Play</x-slot>
<x-slot:description>A single-screen Family Feud style game for family game night. An LLM judges guesses server-side, so the whole family plays from one shared, calm, low-contrast screen.</x-slot>
<x-slot:bodyClass>family-feud-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Family Feud Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>One person is the host and types on this screen; everyone else just calls out answers out loud (in person or over a video call, with this screen shared).</li>
        <li>The answers for each question are hidden from everyone, including the host — an AI judge checks each guess against the hidden list and reveals a tile only when it finds a match.</li>
        <li>Type whatever a player calls out and press Submit (or Enter). "Checking..." means the judge is thinking; the input is disabled until it answers.</li>
        <li>Tap <strong>Options</strong> below the guess box to reveal Skip Question and Swap Team -- they're tucked away since you'd only need them once, right at the start of a turn.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Two teams. Every round, both teams get a turn on their own question — Team 1 goes first, then Team 2 — so an odd number of rounds never gives either team an extra turn. No face-off buzzer round.</li>
        <li>On the controlling team's turn, every correct new answer reveals its tile and adds its points to the pot. A wrong guess adds a strike.</li>
        <li>Guessing something already revealed doesn't count as a strike — it's just shown as "already up there."</li>
        <li>Three strikes hands one steal guess to the other team. A match on the steal wins the whole pot for the stealing team; a miss lets the controlling team keep it.</li>
        <li>Once every tile is revealed (or the steal is resolved), that team's turn ends, the pot is awarded, and any remaining answers are shown before moving on to the other team's turn.</li>
        <li>Once both teams have taken their turn, a round summary shows how many points each team earned that round before moving to the next one.</li>
        <li>Don't like the current question, or already played it before? <strong>Skip Question</strong> (under Options) swaps in a different question for the same turn — no scoring change.</li>
        <li>If the judge is unreachable for a while, <strong>Reveal</strong> in the top bar shows the full hidden list so the host can finish scoring that turn by ear. It intentionally ends secrecy for that question, so it's meant as a last resort.</li>
    </ul>
</x-slot:rules>
<div>
    {{--
        Teleported (see layouts/app.blade.php's #game-actions anchor) rather
        than a layout $barRight slot -- these buttons depend on $started/
        $phase, which change after the page's first render, and a layout
        slot only ever captures its content once, at that first render.
    --}}
    @if ($started)
        @teleport('#game-actions')
            <div style="display: contents;">
                @if ($this->inPlay)
                    <button type="button" wire:click="revealManually" wire:confirm="This shows every hidden answer and ends secrecy for this question. Continue?">
                        Reveal
                    </button>
                @endif
                @unless ($phase === FamilyFeudPhase::GameEnd)
                    <button type="button" wire:click="endGame" wire:confirm="End the game now and show final scores?">
                        End Game
                    </button>
                @else
                    <button type="button" wire:click="newGame">New Game</button>
                @endunless
            </div>
        @endteleport
    @endif

    @unless ($started)
        <div class="setup" id="setup">
            <div class="heading">Family Feud</div>

            <div class="team-inputs">
                <div class="team-input-row">
                    <label for="team0">Team 1</label>
                    <input type="text" id="team0" wire:model="teamNames.0" maxlength="24" placeholder="Team 1">
                </div>
                <div class="team-input-row">
                    <label for="team1">Team 2</label>
                    <input type="text" id="team1" wire:model="teamNames.1" maxlength="24" placeholder="Team 2">
                </div>
            </div>

            <div class="setup-row">
                <label>Rounds</label>
                <div class="stepper">
                    <button type="button" wire:click="setRounds({{ $rounds - 1 }})" @disabled($rounds <= self::MIN_ROUNDS) aria-label="Fewer rounds">&minus;</button>
                    <div class="stepper-value">{{ $rounds }}</div>
                    <button type="button" wire:click="setRounds({{ $rounds + 1 }})" @disabled($rounds >= $this->maxRounds()) aria-label="More rounds">+</button>
                </div>
            </div>
            <div class="setup-note">Each round, Team 1 and Team 2 both get a turn — Team 1 goes first.</div>

            <button type="button" wire:click="startGame">Start Game</button>
        </div>
    @else
        <div id="family-feud-game">
            <div class="scoreboard">
                <div class="team-card {{ $this->inPlay && $this->turnTeam() === 0 ? 'controlling' : '' }}">
                    <div class="team-name">{{ $teams[0]['name'] }}</div>
                    <div class="team-score">{{ $teams[0]['score'] }}</div>
                </div>
                <div class="round-column">
                    <div class="round-indicator">{{ $this->roundIndicator }}</div>
                    <div class="pot {{ $phase === FamilyFeudPhase::GameEnd || $phase === FamilyFeudPhase::RoundSummary ? 'hidden' : '' }}">Pot: {{ $pot }}</div>
                </div>
                <div class="team-card {{ $this->inPlay && $this->turnTeam() === 1 ? 'controlling' : '' }}">
                    <div class="team-name">{{ $teams[1]['name'] }}</div>
                    <div class="team-score">{{ $teams[1]['score'] }}</div>
                </div>
            </div>

            @unless ($phase === FamilyFeudPhase::GameEnd || $phase === FamilyFeudPhase::RoundSummary)
                <div class="board-panel">
                    <div class="prompt">{{ $this->currentQuestion()['prompt'] }}</div>
                    <div class="board">
                        @for ($i = 0; $i < $this->currentQuestion()['answerCount']; $i++)
                            <div wire:key="tile-{{ $i }}" class="tile {{ isset($revealed[$i]) ? 'revealed' : '' }}">
                                <div class="tile-index">{{ $i + 1 }}</div>
                                <div class="tile-content">
                                    <div class="tile-text">{{ isset($revealed[$i]) ? strtoupper($revealed[$i]['text']) : '' }}</div>
                                    <div class="tile-points">{{ $revealed[$i]['points'] ?? '' }}</div>
                                </div>
                            </div>
                        @endfor
                    </div>
                </div>

                <div class="strikes">
                    <span class="strikes-label">Strikes</span>
                    @for ($i = 0; $i < 3; $i++)
                        <span class="strike-mark {{ $i < $strikes ? 'active' : '' }}">&times;</span>
                    @endfor
                </div>

                <div class="message {{ $messageIsError ? 'error' : '' }}">{{ $message }}</div>
            @endunless

            @if ($this->inPlay)
                @if ($pendingGuess !== null)
                    <button type="button" wire:click="retryGuess">Retry</button>
                @else
                    <form class="guess-form" wire:submit="submitGuess">
                        <input type="text" wire:model="guessInput" placeholder="Type the answer..." autocomplete="off"
                            wire:loading.attr="disabled" wire:target="submitGuess">
                        <button type="submit" wire:loading.attr="disabled" wire:target="submitGuess">
                            <span wire:loading.remove wire:target="submitGuess">Submit</span>
                            <span wire:loading wire:target="submitGuess">Checking...</span>
                        </button>
                    </form>
                @endif

                <button type="button" class="link-toggle" wire:click="toggleTurnOptions">
                    Options {!! $turnOptionsOpen ? '&#9652;' : '&#9662;' !!}
                </button>

                @if ($turnOptionsOpen)
                    <div class="controls">
                        <button type="button" wire:click="skipQuestion" wire:confirm="Skip this question and try a different one? Any points or strikes so far on it will be lost.">
                            Skip Question
                        </button>
                        @if ($phase === FamilyFeudPhase::Play)
                            <button type="button" wire:click="swapControl">Swap Team</button>
                        @endif
                    </div>
                @endif
            @endif

            @if ($phase === FamilyFeudPhase::Manual)
                <div class="panel">
                    <div class="panel-title">Finish this round by eye</div>
                    <p>The hidden answers are now shown above. Talk it through, then award the pot to whichever team you agree got it.</p>
                    <div class="controls">
                        <button type="button" wire:click="awardManual(0)">Award pot to {{ $teams[0]['name'] }}</button>
                        <button type="button" wire:click="awardManual(1)">Award pot to {{ $teams[1]['name'] }}</button>
                    </div>
                </div>
            @endif

            @if ($phase === FamilyFeudPhase::RoundEnd)
                <div class="panel">
                    <div class="panel-title">{{ $roundEndTitle }}</div>
                    <div class="controls">
                        <button type="button" wire:click="nextRound">
                            {{ $currentQuestionIndex % 2 === 0 ? 'Next Question' : 'See Round Summary' }}
                        </button>
                    </div>
                </div>
            @endif

            @if ($phase === FamilyFeudPhase::RoundSummary)
                @php $justFinished = intdiv($currentQuestionIndex, 2); @endphp
                <div class="panel">
                    <div class="panel-title">Round {{ $justFinished + 1 }} Complete</div>
                    <div class="round-summary-scores">
                        <div class="team-card">
                            <div class="team-name">{{ $teams[0]['name'] }}</div>
                            <div class="team-score">{{ $roundHistory[$justFinished][0] }}</div>
                        </div>
                        <div class="team-card">
                            <div class="team-name">{{ $teams[1]['name'] }}</div>
                            <div class="team-score">{{ $roundHistory[$justFinished][1] }}</div>
                        </div>
                    </div>
                    <div class="controls">
                        <button type="button" wire:click="nextRoundSummary">
                            {{ $currentQuestionIndex + 1 >= count($questions) ? 'See Final Score' : 'Next Round' }}
                        </button>
                    </div>
                </div>
            @endif

            @if ($phase === FamilyFeudPhase::GameEnd)
                <div class="panel">
                    <div class="panel-title">Final Score</div>
                    <div class="panel-title">{{ $this->winnerText() }}</div>
                    <table class="round-table">
                        <thead>
                            <tr>
                                <th>Round</th>
                                <th>{{ $teams[0]['name'] }}</th>
                                <th>{{ $teams[1]['name'] }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($roundHistory as $i => $result)
                                <tr wire:key="round-{{ $i }}">
                                    <td>Round {{ $i + 1 }}</td>
                                    <td>{{ $result[0] }}</td>
                                    <td>{{ $result[1] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td>{{ $teams[0]['score'] }}</td>
                                <td>{{ $teams[1]['score'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    @endunless
</div>
