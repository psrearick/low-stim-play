<?php

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\User;
use App\Services\Social\FriendService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function getSearchResultsProperty()
    {
        $term = trim($this->search);

        if ($term === '') {
            return collect();
        }

        return User::query()
            ->where('id', '!=', $this->user()->id)
            ->where('is_guest', false)
            ->whereNotNull('username')
            ->where('username', 'like', '%'.$term.'%')
            ->orderBy('username')
            ->limit(10)
            ->get();
    }

    public function getFriendsProperty()
    {
        return app(FriendService::class)->friendsOf($this->user());
    }

    public function getPendingReceivedProperty()
    {
        return app(FriendService::class)->pendingReceived($this->user());
    }

    public function getPendingSentProperty()
    {
        return app(FriendService::class)->pendingSent($this->user());
    }

    public function getActivityProperty()
    {
        return app(FriendService::class)->activityFeed($this->user());
    }

    /**
     * Null when no relationship exists yet -- the "Add" button in the
     * search results only shows in that case, so a search result can't
     * offer a duplicate request against an already pending/accepted pair.
     */
    public function relationshipLabel(User $other): ?string
    {
        $friendship = app(FriendService::class)->relationshipStatus($this->user(), $other);

        if ($friendship === null) {
            return null;
        }

        if ($friendship->status === FriendshipStatus::Accepted) {
            return 'Already friends';
        }

        return $friendship->requester_id === $this->user()->id ? 'Request sent' : 'Wants to be friends';
    }

    public function sendRequest(int $userId): void
    {
        if ($userId === $this->user()->id) {
            return;
        }

        $target = User::findOrFail($userId);

        app(FriendService::class)->sendRequest($this->user(), $target);

        $this->search = '';
    }

    public function acceptRequest(int $friendshipId): void
    {
        $friendship = Friendship::where('addressee_id', $this->user()->id)->findOrFail($friendshipId);

        app(FriendService::class)->accept($friendship);
    }

    /**
     * Handles both a Decline (on a received request) and a Cancel (on a
     * sent one) -- both are just deleting the same still-pending row
     * before it's ever accepted, so one method covers both buttons.
     */
    public function removeRequest(int $friendshipId): void
    {
        $friendship = Friendship::query()
            ->where(fn ($query) => $query->where('requester_id', $this->user()->id)->orWhere('addressee_id', $this->user()->id))
            ->findOrFail($friendshipId);

        app(FriendService::class)->decline($friendship);
    }

    public function removeFriend(int $userId): void
    {
        $friend = User::findOrFail($userId);

        app(FriendService::class)->remove($this->user(), $friend);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
};
?>

<x-slot:title>Friends — Low Stim Play</x-slot>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Friends</h1>

    <div class="flex flex-col gap-3 w-full bg-surface border-2 border-surface-border rounded-lg p-4">
        <p class="text-sm text-text/80 font-bold">Add a friend</p>

        <input type="text" id="friend-search" wire:model.live.debounce.400ms="search" placeholder="Search by username"
            class="rounded border-2 border-surface-border bg-bg text-text px-3 py-2">

        @if ($this->searchResults->isNotEmpty())
            <ul class="flex flex-col gap-1">
                @foreach ($this->searchResults as $result)
                    <li wire:key="search-result-{{ $result->id }}" class="flex items-center justify-between gap-2 px-2 py-2 rounded hover:bg-bg/60">
                        <div class="flex items-center gap-2">
                            <x-avatar :name="$result->name" :avatar-url="$result->avatarUrl()" size="w-8 h-8 text-sm" />
                            <div class="flex flex-col leading-tight">
                                <span class="text-sm text-text">{{ $result->name }}</span>
                                <span class="text-xs text-text/65">&commat;{{ $result->username }}</span>
                            </div>
                        </div>

                        @php $label = $this->relationshipLabel($result); @endphp
                        @if ($label)
                            <span class="text-xs text-text/65">{{ $label }}</span>
                        @else
                            <button type="button" id="send-request-{{ $result->id }}" wire:click="sendRequest({{ $result->id }})">Add</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @elseif (trim($search) !== '')
            <p class="text-sm text-text/65">No users found.</p>
        @endif
    </div>

    @if ($this->pendingReceived->isNotEmpty())
        <div class="flex flex-col gap-3 w-full bg-surface border-2 border-surface-border rounded-lg p-4">
            <p class="text-sm text-text/80 font-bold">Friend requests</p>
            <ul class="flex flex-col gap-1">
                @foreach ($this->pendingReceived as $friendship)
                    <li wire:key="pending-received-{{ $friendship->id }}" class="flex items-center justify-between gap-2 px-2 py-2 rounded">
                        <div class="flex items-center gap-2">
                            <x-avatar :name="$friendship->requester->name" :avatar-url="$friendship->requester->avatarUrl()" size="w-8 h-8 text-sm" />
                            <span class="text-sm text-text">{{ $friendship->requester->name }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" id="accept-request-{{ $friendship->id }}" wire:click="acceptRequest({{ $friendship->id }})">Accept</button>
                            <button type="button" id="decline-request-{{ $friendship->id }}" wire:click="removeRequest({{ $friendship->id }})">Decline</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($this->pendingSent->isNotEmpty())
        <div class="flex flex-col gap-3 w-full bg-surface border-2 border-surface-border rounded-lg p-4">
            <p class="text-sm text-text/80 font-bold">Sent requests</p>
            <ul class="flex flex-col gap-1">
                @foreach ($this->pendingSent as $friendship)
                    <li wire:key="pending-sent-{{ $friendship->id }}" class="flex items-center justify-between gap-2 px-2 py-2 rounded">
                        <div class="flex items-center gap-2">
                            <x-avatar :name="$friendship->addressee->name" :avatar-url="$friendship->addressee->avatarUrl()" size="w-8 h-8 text-sm" />
                            <span class="text-sm text-text">{{ $friendship->addressee->name }}</span>
                        </div>
                        <button type="button" id="cancel-request-{{ $friendship->id }}" wire:click="removeRequest({{ $friendship->id }})">Cancel</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-col gap-3 w-full bg-surface border-2 border-surface-border rounded-lg p-4">
        <p class="text-sm text-text/80 font-bold">Your friends</p>

        @if ($this->friends->isEmpty())
            <p class="text-sm text-text/65">No friends yet — search for a username above to send a request.</p>
        @else
            <ul class="flex flex-col gap-1">
                @foreach ($this->friends as $friend)
                    <li wire:key="friend-{{ $friend->id }}" class="flex items-center justify-between gap-2 px-2 py-2 rounded">
                        @if ($friend->username)
                            <a href="{{ route('profile.show', ['username' => $friend->username]) }}" class="flex items-center gap-2 hover:underline">
                                <x-avatar :name="$friend->name" :avatar-url="$friend->avatarUrl()" size="w-8 h-8 text-sm" />
                                <span class="text-sm text-text">{{ $friend->name }}</span>
                            </a>
                        @else
                            <div class="flex items-center gap-2">
                                <x-avatar :name="$friend->name" :avatar-url="$friend->avatarUrl()" size="w-8 h-8 text-sm" />
                                <span class="text-sm text-text">{{ $friend->name }}</span>
                            </div>
                        @endif
                        <button type="button" id="remove-friend-{{ $friend->id }}" wire:click="removeFriend({{ $friend->id }})">Remove</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="flex flex-col gap-2 w-full">
        <p class="text-sm text-text/80 font-bold">Friend activity</p>

        @if ($this->activity->isEmpty())
            <p class="text-sm text-text/65 text-center">Nothing here yet.</p>
        @else
            <div class="w-full overflow-x-auto bg-surface border-2 border-surface-border rounded-lg">
                <div class="grid min-w-[480px]" style="grid-template-columns: 1fr 1fr 1fr 0.8fr;">
                    @foreach ($this->activity as $participant)
                        <div wire:key="activity-{{ $participant->id }}" class="contents">
                            <span class="pl-6 pr-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm font-bold text-text">
                                {{ $participant->user->name }}
                            </span>
                            <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text/65">
                                {{ $participant->gameSession->type->label() }}
                            </span>
                            <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text/65">
                                {{ $participant->created_at->diffForHumans() }}
                            </span>
                            <span class="pl-3 pr-6 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text">
                                {{ $participant->score }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
