@extends('layouts.app')

@section('content')
    <x-panel heading="Create account">
        <form method="POST" action="{{ route('register') }}" class="w-full flex flex-col gap-4">
            @csrf

            <div class="flex flex-col gap-1">
                <label for="name" class="text-sm text-text/80">Name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('name')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="email" class="text-sm text-text/80">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('email')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="password" class="text-sm text-text/80">Password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('password')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="password_confirmation" class="text-sm text-text/80">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
            </div>

            <button type="submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Create account
            </button>
        </form>

        <p class="text-sm text-text/65">
            Already have an account?
            <a href="{{ route('login') }}" class="hover:text-text underline">Sign in</a>
        </p>
    </x-panel>
@endsection
