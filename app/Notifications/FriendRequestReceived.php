<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FriendRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $requester) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->requester->name} sent you a friend request")
            ->line("{$this->requester->name} wants to add you as a friend.")
            ->action('View Friend Requests', route('friends'));
    }
}
