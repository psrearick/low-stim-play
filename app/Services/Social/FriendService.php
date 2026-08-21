<?php

namespace App\Services\Social;

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\FriendRequestReceived;
use Illuminate\Support\Collection;

class FriendService
{
    /**
     * Accepted friends of $user, in either relationship direction,
     * resolved to the *other* user in the pair.
     *
     * @return Collection<int, User>
     */
    public function friendsOf(User $user): Collection
    {
        return Friendship::query()
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($query) => $query->where('requester_id', $user->id)->orWhere('addressee_id', $user->id))
            ->with(['requester', 'addressee'])
            ->get()
            ->map(fn (Friendship $friendship) => $friendship->requester_id === $user->id
                ? $friendship->addressee
                : $friendship->requester)
            ->values();
    }

    /**
     * Pending requests $user has received and hasn't responded to yet.
     *
     * @return Collection<int, Friendship>
     */
    public function pendingReceived(User $user): Collection
    {
        return Friendship::query()
            ->where('addressee_id', $user->id)
            ->where('status', FriendshipStatus::Pending)
            ->with('requester')
            ->latest()
            ->get();
    }

    /**
     * Pending requests $user has sent that haven't been accepted yet.
     *
     * @return Collection<int, Friendship>
     */
    public function pendingSent(User $user): Collection
    {
        return Friendship::query()
            ->where('requester_id', $user->id)
            ->where('status', FriendshipStatus::Pending)
            ->with('addressee')
            ->latest()
            ->get();
    }

    /**
     * The friendship row between two users regardless of who sent the
     * original request, or null if none exists yet.
     */
    public function relationshipStatus(User $user, User $other): ?Friendship
    {
        return Friendship::query()
            ->where(fn ($query) => $query->where('requester_id', $user->id)->where('addressee_id', $other->id))
            ->orWhere(fn ($query) => $query->where('requester_id', $other->id)->where('addressee_id', $user->id))
            ->first();
    }

    /**
     * No-ops for self-requests or a pair that's already friends/pending in
     * either direction, rather than throwing -- the only entry point is a
     * search result already filtered to exclude those cases, so this is a
     * defensive guard, not an expected user-facing failure.
     */
    public function sendRequest(User $from, User $to): void
    {
        if ($from->is($to) || $this->relationshipStatus($from, $to) !== null) {
            return;
        }

        Friendship::create([
            'requester_id' => $from->id,
            'addressee_id' => $to->id,
            'status' => FriendshipStatus::Pending,
        ]);

        if (! $to->is_guest) {
            $to->notify(new FriendRequestReceived($from));
        }
    }

    public function accept(Friendship $friendship): void
    {
        $friendship->update(['status' => FriendshipStatus::Accepted]);
    }

    /**
     * Also used to cancel a still-pending outgoing request -- both are
     * just deleting the row before it's accepted.
     */
    public function decline(Friendship $friendship): void
    {
        $friendship->delete();
    }

    public function remove(User $user, User $friend): void
    {
        $this->relationshipStatus($user, $friend)?->delete();
    }

    /**
     * $user's friends' completed games, newest first -- a GameParticipant
     * row is only ever written once a game actually ends (see e.g.
     * pages::boggle.play's endGame()), so no separate "completed" filter
     * is needed beyond the row existing at all.
     *
     * @return Collection<int, GameParticipant>
     */
    public function activityFeed(User $user, int $limit = 20): Collection
    {
        $friendIds = $this->friendsOf($user)->pluck('id');

        return GameParticipant::query()
            ->whereIn('user_id', $friendIds)
            ->with(['gameSession', 'user'])
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
