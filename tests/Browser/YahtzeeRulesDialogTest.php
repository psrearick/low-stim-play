<?php

it('opens and closes the rules dialog from the button bar', function () {
    $page = visit('/yacht');

    $page->assertDontSee('How to Use This Page')
        ->click('#rules-btn')
        ->assertSee('How to Use This Page')
        ->click('#rules-dialog-close')
        ->assertDontSee('How to Use This Page');
});
