<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomSeat>
 */
class RoomSeatFactory extends Factory
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
            'seat_index' => 0,
            'user_id' => User::factory(),
        ];
    }
}
