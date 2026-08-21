<?php

namespace App\Notifications;

use App\Enums\GameType;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GameChallengeReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $from, public GameType $gameType, public string $link) {}

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
            ->subject("{$this->from->name} challenged you to a game of {$this->gameType->label()}")
            ->line("{$this->from->name} thinks you can beat their score in {$this->gameType->label()}.")
            ->action('Accept the Challenge', $this->link);
    }
}
