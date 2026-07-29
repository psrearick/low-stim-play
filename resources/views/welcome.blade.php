@extends('layouts.app')

@section('content')
    <div class="flex flex-col items-center gap-8 w-full max-w-4xl">
        <div class="flex flex-col items-center gap-3 text-center">
            <h1 class="text-accent font-bold tracking-wide text-3xl">Low Stim Play</h1>
            <p class="text-text/80 max-w-md">
                A small collection of quiet games. Low contrast, no motion, built to be gentle on sensitive eyes and heads.
            </p>
        </div>

        <div class="flex flex-wrap justify-center gap-6 w-full">
            <div class="flex flex-col items-center gap-3 bg-surface border-2 border-surface-border rounded-lg p-6 flex-1 basis-64 max-w-sm">
                <h2 class="text-accent font-bold tracking-wide text-lg">Boggle</h2>
                <p class="text-sm text-text/80 text-center">
                    A shuffled 4&times;4 or 5&times;5 letter grid. Play solo, host an online room, or challenge a friend.
                </p>
                <a href="{{ route('boggle.lobby') }}" class="w-full text-center rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                    Play Boggle
                </a>
            </div>

            <div class="flex flex-col items-center gap-3 bg-surface border-2 border-surface-border rounded-lg p-6 flex-1 basis-64 max-w-sm">
                <h2 class="text-accent font-bold tracking-wide text-lg">Yahtzee</h2>
                <p class="text-sm text-text/80 text-center">
                    A full scorecard for 1&ndash;8 players, pass-and-play on one device.
                </p>
                <a href="{{ route('yahtzee.play') }}" class="w-full text-center rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                    Play Yahtzee
                </a>
            </div>
        </div>

        @guest
            <p class="text-sm text-text/65">
                <a href="{{ route('register') }}" class="underline hover:text-text">Create an account</a> to track your
                games and stats, or <a href="{{ route('login') }}" class="underline hover:text-text">sign in</a>.
            </p>
        @endguest
    </div>
@endsection
