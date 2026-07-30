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

    public function deletePasskey(int $passkeyId): void
    {
        $this->user()->passkeys()->whereKey($passkeyId)->delete();
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

<div class="flex flex-col items-center gap-6 w-full">
    @teleport('#game-actions')
        <a href="{{ route('dashboard') }}"><button type="button">Dashboard</button></a>
    @endteleport

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
                    <button type="button" wire:click="deletePasskey({{ $passkey->id }})"
                        wire:confirm="Remove this passkey?"
                        class="text-warn hover:underline">
                        Remove
                    </button>
                </li>
            @empty
                <li class="text-sm text-text/65 text-center">No passkeys registered yet.</li>
            @endforelse
        </ul>

        <button type="button" data-passkey-register class="w-full">Add a Passkey</button>
    </x-panel>
</div>
