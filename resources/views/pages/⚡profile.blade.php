<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public ?string $username = null;

    public bool $is_public = true;

    public bool $saved = false;

    public bool $statsCleared = false;

    public function mount(): void
    {
        $user = $this->user();

        $this->name = $user->name;
        $this->username = $user->username;
        $this->is_public = $user->is_public;
    }

    public function save(): void
    {
        $user = $this->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'nullable', 'string', 'alpha_dash', 'max:32',
                Rule::unique('users')->ignore($user->id),
            ],
            'is_public' => ['boolean'],
        ]);

        $user->update($validated);

        $this->saved = true;
    }

    public function clearStats(): void
    {
        $this->user()->gameParticipants()->delete();

        $this->statsCleared = true;
    }

    /**
     * Hard delete, matching what the schema already anticipates: passkeys/
     * room_seats cascade-delete, hosted rooms cascade-delete, but
     * game_participants.user_id merely nulls out (so other players' own
     * shared-session history survives). A subsequent registration with the
     * same email therefore just works -- the row is gone, so there's
     * nothing to collide with or accidentally reactivate.
     */
    public function deleteAccount(): void
    {
        $user = $this->user();

        Auth::guard(config('fortify.guard'))->logout();
        session()->invalidate();
        session()->regenerateToken();

        $user->delete();

        $this->redirect('/');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Passkeys\Passkey>
     */
    public function getPasskeysProperty()
    {
        return $this->user()->passkeys()->latest()->get();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
?>

<x-slot:title>Profile — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full">
    <x-panel heading="Profile">
        @if ($saved)
            <p class="text-sm text-text/80 text-center">Profile saved.</p>
        @endif

        <form wire:submit="save" class="w-full flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <label for="name" class="text-sm text-text/80">Name</label>
                <input id="name" type="text" wire:model="name" required
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('name')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="username" class="text-sm text-text/80">Username</label>
                <input id="username" type="text" wire:model="username" placeholder="Not set yet"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @error('username')
                    <p class="text-sm text-warn">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-text/80">
                <input type="checkbox" wire:model="is_public">
                Public profile (visible to other players)
            </label>

            <button type="submit">Save</button>

            <p wire:loading.class.remove="hidden" class="hidden text-sm text-text/65 text-center">Saving...</p>
        </form>
    </x-panel>

    <x-panel heading="Passkeys">
        <ul class="w-full flex flex-col gap-3">
            @forelse ($this->passkeys as $passkey)
                <li wire:key="passkey-{{ $passkey->id }}" class="flex items-center justify-between gap-3 text-sm text-text/80">
                    <span>{{ $passkey->name }}</span>
                    <button type="button" data-passkey-remove-trigger data-passkey-id="{{ $passkey->id }}"
                        data-passkey-name="{{ $passkey->name }}" class="text-warn hover:underline">
                        Remove
                    </button>
                </li>
            @empty
                <li class="text-sm text-text/65 text-center">No passkeys registered yet.</li>
            @endforelse
        </ul>

        <button type="button" data-passkey-add class="w-full">Add a Passkey</button>
    </x-panel>

    <x-panel heading="Danger Zone">
        @if ($statsCleared)
            <p class="text-sm text-text/80 text-center">Your stats have been cleared.</p>
        @endif

        <p class="text-sm text-text/80 text-center">
            Permanently delete your game history and stats. This can't be undone.
        </p>

        <button type="button" id="clear-stats-trigger" data-dialog-open="clear-stats-confirm-dialog" class="w-full">
            Clear All Stats
        </button>

        <x-confirm-dialog id="clear-stats-confirm-dialog" title="Clear all stats?"
            message="This permanently deletes your game history and stats. This can't be undone.">
            <x-slot:confirm>
                <button type="button" id="clear-stats-confirm" data-dialog-close wire:click="clearStats"
                    class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                    Clear All Stats
                </button>
            </x-slot:confirm>
        </x-confirm-dialog>

        <p class="text-sm text-text/80 text-center">
            Permanently delete your account and all of your game history. This can't be undone.
        </p>

        <button type="button" id="delete-account-trigger" data-dialog-open="delete-account-confirm-dialog" class="w-full">
            Delete Account
        </button>

        <x-confirm-dialog id="delete-account-confirm-dialog" title="Delete your account?"
            message="This permanently deletes your account and all of your game history. This can't be undone.">
            <x-slot:confirm>
                <button type="button" id="delete-account-confirm" data-dialog-close wire:click="deleteAccount"
                    class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                    Delete Account
                </button>
            </x-slot:confirm>
        </x-confirm-dialog>
    </x-panel>

    {{--
        These three <dialog>s must stay inside this component's single root
        element -- Livewire requires exactly one root per template, so
        placing them as siblings after the closing </div> would break its
        diffing (the trailing <script> pages like boggle/play get away with
        is a documented, script-only exception).
    --}}
    <dialog class="passkey-dialog m-auto w-[min(94vw,420px)] rounded-lg border-2 border-surface-border bg-surface p-6 text-text backdrop:bg-black/50 focus:outline-none"
        id="passkey-name-dialog" aria-labelledby="passkey-name-dialog-title">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div class="text-bar font-bold tracking-wide text-lg" id="passkey-name-dialog-title">Name this passkey</div>
            <button type="button" class="rounded px-2 py-0.5 text-lg font-bold leading-none hover:bg-surface-border/40"
                data-passkey-dialog-close aria-label="Close">&times;</button>
        </div>

        <form data-passkey-name-form class="w-full flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <label for="passkey-name-input" class="text-sm text-text/80">Name</label>
                <input id="passkey-name-input" type="text" maxlength="255" placeholder="e.g. MacBook Pro" required
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
            </div>

            <p data-passkey-name-error class="text-sm text-warn hidden"></p>

            <button type="submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Add Passkey
            </button>
        </form>
    </dialog>

    <dialog class="passkey-dialog m-auto w-[min(94vw,420px)] rounded-lg border-2 border-surface-border bg-surface p-6 text-text backdrop:bg-black/50 focus:outline-none"
        id="passkey-password-dialog" aria-labelledby="passkey-password-dialog-title">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div class="text-bar font-bold tracking-wide text-lg" id="passkey-password-dialog-title">Confirm your password</div>
            <button type="button" class="rounded px-2 py-0.5 text-lg font-bold leading-none hover:bg-surface-border/40"
                data-passkey-dialog-close aria-label="Close">&times;</button>
        </div>

        <p class="text-sm text-text/80 text-center mb-4">This is a protected action. Please confirm your password to continue.</p>

        <button type="button" data-passkey-password-confirm-with-passkey class="w-full">Confirm with a passkey</button>

        <div class="flex items-center gap-3 w-full text-text/50 text-xs uppercase tracking-widest my-4">
            <span class="h-px flex-1 bg-surface-border"></span>
            or
            <span class="h-px flex-1 bg-surface-border"></span>
        </div>

        <form data-passkey-password-form class="w-full flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <label for="passkey-password-input" class="text-sm text-text/80">Password</label>
                <input id="passkey-password-input" type="password" autocomplete="current-password"
                    class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
            </div>

            <p data-passkey-password-error class="text-sm text-warn hidden"></p>

            <button type="submit" class="rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Confirm
            </button>
        </form>
    </dialog>

    <dialog class="passkey-dialog m-auto w-[min(94vw,420px)] rounded-lg border-2 border-surface-border bg-surface p-6 text-text backdrop:bg-black/50 focus:outline-none"
        id="passkey-remove-dialog" aria-labelledby="passkey-remove-dialog-title">
        <div class="text-bar font-bold tracking-wide text-lg mb-4" id="passkey-remove-dialog-title">Remove passkey</div>

        <p class="text-sm text-text/80 text-center mb-4">
            Remove <strong data-passkey-remove-name></strong>? You'll need another way to sign in if this was your only passkey.
        </p>

        <p data-passkey-remove-error class="text-sm text-warn hidden mb-4"></p>

        <div class="flex gap-3 w-full">
            <button type="button" data-passkey-remove-cancel class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Cancel
            </button>
            <button type="button" data-passkey-remove-confirm class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                Remove
            </button>
        </div>
    </dialog>
</div>
