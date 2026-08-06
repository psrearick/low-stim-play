<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomMember>
 */
class RoomMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'user_id' => User::factory(),
        ];
    }
}
