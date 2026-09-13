<?php

namespace App\Enums;

/**
 * The three board/domino-set tiers Pips ships in v1 -- matching the real
 * game's own Easy/Medium/Hard split rather than the single fixed size an
 * earlier draft of docs/specs/pips.md section 3 considered. Every
 * generation-time Pips service (BoardFiller, RegionGrower, ConstraintDeriver,
 * SolutionSolver, PuzzleGenerator) takes rows()/cols()/maxPip() from here
 * rather than hardcoding any one tier's numbers.
 *
 * rows()/cols() are a *bounding box*, not the exact board size: the real
 * board is an irregular, connected shape grown to exactly cellCount() cells
 * within that box (App\Services\Pips\ShapeGrower), matching the actual NYT
 * game's own non-rectangular layouts (docs/DEVLOG.md's Pips entry) rather
 * than a filled rectangle -- the box is deliberately oversized relative to
 * cellCount() (roughly 1.4-1.6x its area) so growth has room to be organic
 * instead of being forced back into a rectangle by tight bounds.
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

    /**
     * Bounding-box row count a board is grown within -- see this enum's own
     * docblock. Deliberately larger than a tight rows-that-exactly-fit
     * cellCount() rectangle would need.
     */
    public function rows(): int
    {
        return match ($this) {
            self::Easy => 4,
            self::Medium => 4,
            self::Hard => 5,
        };
    }

    /**
     * Bounding-box column count -- see rows()'s own docblock.
     */
    public function cols(): int
    {
        return match ($this) {
            self::Easy => 4,
            self::Medium => 5,
            self::Hard => 8,
        };
    }

    /**
     * How many dominoes this tier's tray actually holds -- a small subset of
     * the full double-`maxPip()` set (BoardFiller draws exactly this many
     * from a shuffled full set), matching the real NYT game's own tray
     * sizes rather than a full double-N set (docs/concerns.md: a full set
     * was roughly double every tier's intended count -- Hard's 28-domino
     * full double-6 set in particular made for a much bigger, busier board
     * than the real game ever ships).
     */
    public function dominoCount(): int
    {
        return match ($this) {
            self::Easy => 5,
            self::Medium => 7,
            self::Hard => 13,
        };
    }

    /**
     * The board's real, active cell count -- every domino covers exactly two
     * cells, so this is always twice dominoCount(), regardless of how much
     * larger the rows()/cols() bounding box is.
     */
    public function cellCount(): int
    {
        return 2 * $this->dominoCount();
    }
}
