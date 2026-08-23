<?php

namespace App\Models;

use Database\Factories\PunchlinePromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['text'])]
class PunchlinePrompt extends Model
{
    /** @use HasFactory<PunchlinePromptFactory> */
    use HasFactory;
}
