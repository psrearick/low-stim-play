<?php

it('opens and closes the rules dialog from the Game Menu', function () {
    $page = visit('/yacht');

    $page->assertDontSee('How to Use This Page')
        ->click('#game-menu-toggle')
        ->click('#rules-btn')
        ->assertSee('How to Use This Page')
        ->click('#rules-dialog-close')
        ->assertDontSee('How to Use This Page');
});
