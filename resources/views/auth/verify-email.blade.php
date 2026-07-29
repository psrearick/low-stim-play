@extends('layouts.app')

@section('content')
    <x-panel heading="Verify your email">
        <p class="text-sm text-text/80 text-center">
            We sent a verification link to your email address. Click it to activate your account.
        </p>

        @if (session('status') === 'verification-link-sent')
            <p class="text-sm text-accent text-center">A new verification link has been sent.</p>
        @endif

        <form method="POST" action="{{ route('verification.send') }}" class="w-full">
            @csrf
            <button type="submit" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Resend verification email
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <button type="submit" class="w-full text-sm text-text/65 hover:text-text">Log out</button>
        </form>
    </x-panel>
@endsection
