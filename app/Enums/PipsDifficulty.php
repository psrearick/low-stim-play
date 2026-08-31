<?php

namespace App\Enums;

/**
 * The three board/domino-set tiers Pips ships in v1 -- matching the real
 * game's own Easy/Medium/Hard split rather than the single fixed size an
 * earlier draft of docs/specs/pips.md section 3 considered. Each tier's
 * board is sized to exactly fit its own full double-N domino set (N =
 * maxPip()), the same "sized to exactly fit a full set" logic the deferred
 * single-size draft already used for what is now the Hard tier. Every
 * generation-time Pips service (BoardFiller, RegionGrower, ConstraintDeriver,
 * SolutionSolver, PuzzleGenerator) takes rows()/cols()/maxPip() from here
 * rather than hardcoding any one tier's numbers.
 */
enum PipsDifficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    public function label(): string
    {
        return match ($this) {
            self::Easy => 'Easy',
            self::Medium => 'Medium',
            self::Hard => 'Hard',
        };
    }

    /**
     * The highest pip value in this tier's domino set -- a full double-N
     * set has every unordered pair 0-0 through N-N.
     */
    public function maxPip(): int
    {
        return match ($this) {
            self::Easy => 4,
            self::Medium => 5,
            self::Hard => 6,
        };
    }

    public function rows(): int
    {
        return match ($this) {
            self::Easy => 5,
            self::Medium => 6,
            self::Hard => 7,
        };
    }

    public function cols(): int
    {
        return match ($this) {
            self::Easy => 6,
            self::Medium => 7,
            self::Hard => 8,
        };
    }

    /**
     * The count of distinct dominoes in a full double-N set: C(N+1, 2) + (N+1).
     */
    public function dominoCount(): int
    {
        $n = $this->maxPip() + 1;

        return intdiv($n * ($n + 1), 2);
    }

    public function cellCount(): int
    {
        return $this->rows() * $this->cols();
    }
}
