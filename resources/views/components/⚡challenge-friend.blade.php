<?php

use App\Enums\GameType;
use App\Models\User;
use App\Services\Social\FriendService;
use App\Services\Social\GameChallengeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * A "Challenge a Friend" button + dialog, dropped into a solo game's own
 * reveal screen once it's ended -- shared across every challengeable game
 * (see GameType::isChallengeable()) rather than duplicated per game, since
 * the only thing that differs between them is the link itself (built by
 * the caller: Boggle's own shared/ranked boggle.share link, Word Blocks'
 * independent seed link).
 */
new class extends Component
{
    public GameType $gameType;

    public string $link;

    public ?int $sentFriendId = null;

    public function mount(GameType $gameType, string $link): void
    {
        $this->gameType = $gameType;
        $this->link = $link;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function getFriendsProperty()
    {
        return app(FriendService::class)->friendsOf(Auth::user())->reject->is_guest;
    }

    public function send(int $friendId, GameChallengeService $service): void
    {
        $friend = User::findOrFail($friendId);

        $service->send(Auth::user(), $friend, $this->link, $this->gameType);

        $this->sentFriendId = $friendId;
    }
};
?>

<div>
    <button type="button" id="challenge-friend-trigger-{{ $gameType->value }}"
        data-dialog-open="challenge-friend-dialog-{{ $gameType->value }}">
        Challenge a Friend
    </button>

    {{-- wire:ignore.self -- same reasoning as rooms-menu's join-room-dialog:
         send() re-renders this component to show "Sent!", which would
         otherwise morph the <dialog>'s own open attribute back to closed. --}}
    <x-dialog id="challenge-friend-dialog-{{ $gameType->value }}" title="Challenge a Friend" wire:ignore.self>
        @if ($this->friends->isEmpty())
            <p class="text-sm text-text/65">
                Add a friend from the <a href="{{ route('friends') }}">Friends page</a> first to challenge them.
            </p>
        @else
            <ul class="flex flex-col gap-1 w-full">
                @foreach ($this->friends as $friend)
                    <li wire:key="challenge-friend-{{ $friend->id }}" class="flex items-center justify-between gap-2 px-2 py-2 rounded">
                        <span class="text-sm text-text">{{ $friend->name }}</span>
                        @if ($sentFriendId === $friend->id)
                            <span class="text-sm text-text/65">Sent!</span>
                        @else
                            <button type="button" id="challenge-friend-send-{{ $friend->id }}" wire:click="send({{ $friend->id }})">
                                Challenge
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="flex gap-3 w-full" style="margin-top: 12px;">
            <button type="button" data-dialog-close
                class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Close
            </button>
        </div>
    </x-dialog>
</div>
