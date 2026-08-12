<?php

namespace App\Models;

use Database\Factories\AppleMatchPromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deck_id', 'text'])]
class AppleMatchPrompt extends Model
{
    /** @use HasFactory<AppleMatchPromptFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AppleMatchDeck, $this>
     */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(AppleMatchDeck::class, 'deck_id');
    }
}
