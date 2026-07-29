<?php

namespace App\Enums;

enum GameResult: string
{
    case Win = 'win';
    case Loss = 'loss';
    case Draw = 'draw';
}
