<?php

use Livewire\Livewire;

test('it groups all valid words by length and marks which were found', function () {
    Livewire::test('boggle-reveal', [
        'board' => ['size' => 4, 'seed' => 1, 'cells' => [], 'neighbors' => []],
        'foundWords' => ['cat' => 1],
        'allValidWords' => ['cat', 'cats', 'act'],
        'score' => 1,
    ])
        ->assertSet('wordsByLength', [
            3 => ['cat', 'act'],
            4 => ['cats'],
        ])
        ->assertSee('Final score: 1')
        ->assertSee('You found 1 of 3 possible words.')
        ->assertSee('CAT')
        ->assertSee('CATS')
        ->assertSee('ACT');
});
