<?php

namespace App\Services\Boggle;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

class BoardGenerator
{
    /**
     * The fewest findable words a freshly dealt board may have -- see
     * generatePlayable(). Measured over 500 random boards: a 4x4 board's
     * median is ~56 words, but about 1 in 4 falls under 40 and the worst
     * had just 2; 5x5 boards almost never get near this (median ~137).
     */
    public const int MIN_WORDS = 40;

    /**
     * A safety cap, not an expected count -- with ~27% of 4x4 boards under
     * MIN_WORDS, needing even 10 rerolls is a ~1-in-500,000 event.
     */
    private const int MAX_PLAYABLE_ATTEMPTS = 100;

    /**
     * Standard 4x4 Boggle -- 16 dice (New Boggle set, post-1990).
     *
     * @var list<list<string>>
     */
    private const DICE_4 = [
        ['A', 'A', 'E', 'E', 'G', 'N'],
        ['E', 'L', 'R', 'T', 'T', 'Y'],
        ['A', 'O', 'O', 'T', 'T', 'W'],
        ['A', 'B', 'B', 'J', 'O', 'O'],
        ['E', 'H', 'R', 'T', 'V', 'W'],
        ['C', 'I', 'M', 'O', 'T', 'U'],
        ['D', 'I', 'S', 'T', 'T', 'Y'],
        ['E', 'I', 'O', 'S', 'S', 'T'],
        ['D', 'E', 'L', 'R', 'V', 'Y'],
        ['A', 'C', 'H', 'O', 'P', 'S'],
        ['H', 'I', 'M', 'N', 'Qu', 'U'],
        ['E', 'E', 'F', 'H', 'I', 'Y'],
        ['E', 'E', 'G', 'H', 'N', 'W'],
        ['A', 'F', 'F', 'K', 'P', 'S'],
        ['H', 'L', 'N', 'N', 'R', 'Z'],
        ['D', 'E', 'I', 'L', 'R', 'X'],
    ];

    /**
     * Big Boggle 5x5 -- 25 dice.
     *
     * @var list<list<string>>
     */
    private const DICE_5 = [
        ['A', 'A', 'A', 'F', 'R', 'S'],
        ['A', 'A', 'E', 'E', 'G', 'N'],
        ['A', 'A', 'E', 'E', 'G', 'N'],
        ['A', 'A', 'F', 'I', 'R', 'S'],
        ['A', 'D', 'E', 'N', 'N', 'N'],
        ['A', 'E', 'E', 'E', 'E', 'M'],
        ['A', 'E', 'G', 'M', 'U', 'U'],
        ['A', 'E', 'G', 'M', 'N', 'N'],
        ['A', 'F', 'I', 'R', 'S', 'Y'],
        ['B', 'J', 'K', 'Qu', 'X', 'Z'],
        ['C', 'C', 'N', 'S', 'T', 'W'],
        ['C', 'E', 'I', 'I', 'L', 'T'],
        ['C', 'E', 'I', 'L', 'P', 'T'],
        ['D', 'D', 'H', 'N', 'O', 'T'],
        ['D', 'H', 'H', 'L', 'O', 'R'],
        ['D', 'H', 'L', 'N', 'O', 'R'],
        ['D', 'H', 'L', 'N', 'O', 'R'],
        ['E', 'E', 'I', 'I', 'I', 'T'],
        ['E', 'N', 'S', 'S', 'S', 'U'],
        ['E', 'E', 'T', 'T', 'I', 'I'],
        ['F', 'I', 'P', 'R', 'S', 'Y'],
        ['G', 'O', 'R', 'R', 'V', 'W'],
        ['H', 'I', 'P', 'R', 'R', 'Y'],
        ['N', 'O', 'O', 'T', 'U', 'W'],
        ['O', 'O', 'O', 'T', 'T', 'U'],
    ];

    /**
     * Generate a board of the given size (4 or 5).
     *
     * Passing the same `$seed` always produces the same board, which is how
     * online multiplayer rooms and async challenges share an identical
     * board across players without transmitting the whole layout.
     *
     * @return array{
     *     size: int,
     *     seed: int,
     *     cells: list<array{index: int, row: int, col: int, display: string, match: string}>,
     *     neighbors: list<list<int>>,
     * }
     */
    public function generate(int $size, ?int $seed = null): array
    {
        if (! in_array($size, [4, 5], true)) {
            throw new InvalidArgumentException("Boggle board size must be 4 or 5, got {$size}.");
        }

        $seed ??= random_int(0, PHP_INT_MAX);
        $randomizer = new Randomizer(new Mt19937($seed));

        $dice = $size === 5 ? self::DICE_5 : self::DICE_4;
        $shuffled = $randomizer->shuffleArray($dice);

        $cells = [];

        foreach ($shuffled as $index => $faces) {
            $face = $faces[$randomizer->getInt(0, count($faces) - 1)];

            $cells[] = [
                'index' => $index,
                'row' => intdiv($index, $size),
                'col' => $index % $size,
                'display' => $face,
                'match' => strtolower($face),
            ];
        }

        return [
            'size' => $size,
            'seed' => $seed,
            'cells' => $cells,
            'neighbors' => $this->buildAdjacency($size),
        ];
    }

    /**
     * A new board with at least MIN_WORDS findable words, for dealing a
     * *fresh* game (solo New Game, a room starting, group play, a daily).
     * generate() itself stays an exact seed -> board mapping, since that's
     * what replays an already-dealt board (a room's stored seed, a share
     * link, a challenge) and must never change underneath it.
     *
     * Rerolls deterministically from $seed (candidate seeds derived from
     * it, not fresh randomness), so the same starting seed -- e.g. a daily
     * puzzle's date seed -- always settles on the same board. The returned
     * board's 'seed' is the candidate that was actually accepted, so
     * storing it and later calling generate() with it reproduces this exact
     * board with no reroll. If the cap is ever hit, the richest board seen
     * is returned rather than failing the game.
     *
     * @return array{
     *     size: int,
     *     seed: int,
     *     cells: list<array{index: int, row: int, col: int, display: string, match: string}>,
     *     neighbors: list<list<int>>,
     * }
     */
    public function generatePlayable(int $size, Solver $solver, ?int $seed = null): array
    {
        $seed ??= random_int(0, PHP_INT_MAX);
        $richestBoard = null;
        $richestCount = -1;

        for ($attempt = 0; $attempt < self::MAX_PLAYABLE_ATTEMPTS; $attempt++) {
            $board = $this->generate($size, $attempt === 0 ? $seed : crc32("{$seed}-{$attempt}"));
            $wordCount = count($solver->solve($board));

            if ($wordCount >= self::MIN_WORDS) {
                return $board;
            }

            if ($wordCount > $richestCount) {
                $richestBoard = $board;
                $richestCount = $wordCount;
            }
        }

        return $richestBoard;
    }

    /**
     * @return list<list<int>>
     */
    private function buildAdjacency(int $size): array
    {
        $neighbors = [];

        for ($index = 0; $index < $size * $size; $index++) {
            $row = intdiv($index, $size);
            $col = $index % $size;
            $cellNeighbors = [];

            for ($dr = -1; $dr <= 1; $dr++) {
                for ($dc = -1; $dc <= 1; $dc++) {
                    if ($dr === 0 && $dc === 0) {
                        continue;
                    }

                    $r = $row + $dr;
                    $c = $col + $dc;

                    if ($r >= 0 && $r < $size && $c >= 0 && $c < $size) {
                        $cellNeighbors[] = $r * $size + $c;
                    }
                }
            }

            $neighbors[] = $cellNeighbors;
        }

        return $neighbors;
    }
}
