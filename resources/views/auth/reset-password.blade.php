@extends('layouts.app')

@section('content')
    <x-panel heading="Reset password">
        <form method="POST" action="{{ route('password.update') }}" class="w-full flex flex-col gap-4">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="flex flex-col gap-1">
                <label for="email" class="text-sm text-text/80">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $request->query('email')) }}" required autofocus
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('email')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="password" class="text-sm text-text/80">New password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('password')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="password_confirmation" class="text-sm text-text/80">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
            </div>

            <button type="submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Reset password
            </button>
        </form>
    </x-panel>
@endsection
