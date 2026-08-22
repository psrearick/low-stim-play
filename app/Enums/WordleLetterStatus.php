<?php

namespace App\Enums;

/**
 * One guessed letter's outcome against the secret answer -- see
 * App\Services\Wordle\GuessEvaluator::evaluate().
 */
enum WordleLetterStatus: string
{
    case Correct = 'correct';
    case Present = 'present';
    case Absent = 'absent';
}
