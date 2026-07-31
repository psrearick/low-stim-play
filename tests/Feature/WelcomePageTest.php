<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests see sign in and create account links in the top bar', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Sign In')
        ->assertSee('Create an Account')
        ->assertDontSee('Dashboard')
        ->assertDontSee('Log Out');
});

test('authenticated users see dashboard and log out links in the top bar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Log Out')
        ->assertDontSee('Sign In')
        ->assertDontSee('Create an Account');
});

test('logging out from the homepage redirects back to a guest view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});
