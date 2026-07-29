@extends('layouts.app')

@section('content')
    <x-panel heading="Forgot password">
        <p class="text-sm text-text/80 text-center">
            Enter your email and we'll send you a password reset link.
        </p>

        @if (session('status'))
            <p class="text-sm text-accent text-center">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="w-full flex flex-col gap-4">
            @csrf

            <div class="flex flex-col gap-1">
                <label for="email" class="text-sm text-text/80">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('email')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Email password reset link
            </button>
        </form>

        <a href="{{ route('login') }}" class="text-sm text-text/65 hover:text-text">Back to sign in</a>
    </x-panel>
@endsection
