<?php

namespace App\Models;

use Database\Factories\HoaxQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['text', 'answer'])]
class HoaxQuestion extends Model
{
    /** @use HasFactory<HoaxQuestionFactory> */
    use HasFactory;
}
