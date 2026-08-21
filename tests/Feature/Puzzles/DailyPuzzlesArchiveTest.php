<?php

test('the archive page renders for a guest, no auth required', function () {
    $this->get('/daily-puzzles')->assertSeeLivewire('pages::daily-puzzles');
});

test('the archive lists 30 days, newest first, with a link into each game for each day', function () {
    $today = now()->startOfDay();

    $response = $this->get('/daily-puzzles')->assertOk();

    $response->assertSee('Today');
    $response->assertSee($today->subDays(29)->format('M j, Y'));

    $response->assertSee(route('boggle.play', ['dailyDate' => now()->startOfDay()->toDateString()]), false);
    $response->assertSee(route('quartiles.play', ['dailyDate' => now()->startOfDay()->toDateString()]), false);
});
