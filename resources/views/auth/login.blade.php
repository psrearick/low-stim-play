@extends('layouts.app')

@section('content')
    <x-panel heading="Sign in">
        @if (session('status'))
            <p class="text-sm text-text/80 text-center">{{ session('status') }}</p>
        @endif

        <button type="button" data-passkey-login class="w-full">Sign in with a passkey</button>
        <p data-passkey-login-error class="text-sm text-warn hidden"></p>

        <div class="flex items-center gap-3 w-full text-text/50 text-xs uppercase tracking-widest">
            <span class="h-px flex-1 bg-surface-border"></span>
            or
            <span class="h-px flex-1 bg-surface-border"></span>
        </div>

        <form method="POST" action="{{ route('login') }}" class="w-full flex flex-col gap-4">
            @csrf

            <div class="flex flex-col gap-1">
                <label for="email" class="text-sm text-text/80">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username webauthn"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('email')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="password" class="text-sm text-text/80">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('password')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-text/80">
                <input type="checkbox" name="remember" @checked(old('remember', true))>
                Remember me
            </label>

            <button type="submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Sign in
            </button>
        </form>

        <div class="flex justify-between w-full text-sm text-text/65">
            <a href="{{ route('password.request') }}" class="hover:text-text">Forgot password?</a>
            <a href="{{ route('register') }}" class="hover:text-text">Create account</a>
        </div>
    </x-panel>
@endsection
