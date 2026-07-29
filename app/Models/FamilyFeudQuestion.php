<?php

namespace App\Models;

use Database\Factories\FamilyFeudQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array{text: string, points: int}> $answers
 */
#[Fillable(['external_id', 'prompt', 'answers'])]
class FamilyFeudQuestion extends Model
{
    /** @use HasFactory<FamilyFeudQuestionFactory> */
    use HasFactory;

    /**
     * The client-safe shape: prompt and how many answers exist, never the
     * answers themselves. Mirrors the legacy Netlify function's `list`
     * action response.
     *
     * @return array{id: int, prompt: string, answerCount: int}
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'answerCount' => count($this->answers),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
        ];
    }
}
