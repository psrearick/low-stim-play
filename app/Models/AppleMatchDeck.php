<?php

namespace App\Models;

use Database\Factories\AppleMatchDeckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'owner_user_id', 'source', 'generation_prompt'])]
class AppleMatchDeck extends Model
{
    /** @use HasFactory<AppleMatchDeckFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<AppleMatchPrompt, $this>
     */
    public function prompts(): HasMany
    {
        return $this->hasMany(AppleMatchPrompt::class, 'deck_id');
    }

    /**
     * @return HasMany<AppleMatchCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(AppleMatchCard::class, 'deck_id');
    }
}
