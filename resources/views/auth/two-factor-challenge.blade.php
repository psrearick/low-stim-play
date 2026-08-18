@extends('layouts.app')

@section('content')
    <x-panel heading="Two-factor authentication">
        <p class="text-sm text-text/80 text-center" id="two-factor-instructions">
            Enter the six-digit code from your authenticator app.
        </p>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="w-full flex flex-col gap-4" id="two-factor-code-form">
            @csrf

            <div class="flex flex-col gap-1">
                <label for="code" class="text-sm text-text/80">Authentication code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('code')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" id="two-factor-code-submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Verify
            </button>
        </form>

        <button type="button" id="two-factor-toggle-recovery" class="w-full">Use a recovery code instead</button>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="w-full flex-col gap-4 hidden" id="two-factor-recovery-form">
            @csrf

            <div class="flex flex-col gap-1">
                <label for="recovery_code" class="text-sm text-text/80">Recovery code</label>
                <input id="recovery_code" name="recovery_code" type="text" autocomplete="off"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('recovery_code')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" id="two-factor-recovery-submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Verify
            </button>
        </form>
    </x-panel>
@endsection
