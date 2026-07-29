<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/home', 'home')
    ->middleware(['auth', 'verified'])
    ->name('home');

Route::livewire('/profile', 'pages::profile')
    ->middleware(['auth', 'verified'])
    ->name('profile');
