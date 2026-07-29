<?php

namespace App\Services\Boggle;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

class BoardGenerator
{
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
