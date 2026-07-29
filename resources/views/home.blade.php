@extends('layouts.app')

@section('content')
    <x-panel heading="You're in">
        <p class="text-sm text-text/80 text-center">
            Signed in as {{ auth()->user()->email }}. Stats and game history land here in a future update.
        </p>

        <a href="{{ route('boggle.lobby') }}" class="w-full text-center rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
            Play Boggle
        </a>

        <a href="{{ route('yahtzee.play') }}" class="w-full text-center rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
            Play Yahtzee
        </a>

        <a href="{{ route('profile') }}" class="w-full text-center rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
            Edit profile
        </a>

        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <button type="submit" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Log out
            </button>
        </form>
    </x-panel>
@endsection
