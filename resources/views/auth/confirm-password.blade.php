@extends('layouts.app')

@section('content')
    <x-panel heading="Confirm password">
        <p class="text-sm text-text/80 text-center">
            This is a protected area. Please confirm your password before continuing.
        </p>

        <button type="button" data-passkey-confirm class="w-full">Confirm with a passkey</button>

        <div class="flex items-center gap-3 w-full text-text/50 text-xs uppercase tracking-widest">
            <span class="h-px flex-1 bg-surface-border"></span>
            or
            <span class="h-px flex-1 bg-surface-border"></span>
        </div>

        <form method="POST" action="{{ route('password.confirm') }}" class="w-full flex flex-col gap-4">
            @csrf

            <div class="flex flex-col gap-1">
                <label for="password" class="text-sm text-text/80">Password</label>
                <input id="password" name="password" type="password" required autofocus autocomplete="current-password"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('password')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Confirm
            </button>
        </form>
    </x-panel>
@endsection
