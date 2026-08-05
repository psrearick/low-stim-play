<?php

namespace App\Enums;

enum YahtzeeCategory: string
{
    case Ones = 'ones';
    case Twos = 'twos';
    case Threes = 'threes';
    case Fours = 'fours';
    case Fives = 'fives';
    case Sixes = 'sixes';
    case ThreeOfAKind = 'three_of_a_kind';
    case FourOfAKind = 'four_of_a_kind';
    case FullHouse = 'full_house';
    case SmallStraight = 'small_straight';
    case LargeStraight = 'large_straight';
    case Yahtzee = 'yahtzee';
    case Chance = 'chance';

    /**
     * @return list<self>
     */
    public static function upper(): array
    {
        return [self::Ones, self::Twos, self::Threes, self::Fours, self::Fives, self::Sixes];
    }

    /**
     * @return list<self>
     */
    public static function lower(): array
    {
        return [
            self::ThreeOfAKind, self::FourOfAKind, self::FullHouse,
            self::SmallStraight, self::LargeStraight, self::Yahtzee, self::Chance,
        ];
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [...self::upper(), ...self::lower()];
    }

    /**
     * The die face an Upper Section category counts, e.g. Fours -> 4.
     */
    public function face(): ?int
    {
        return match ($this) {
            self::Ones => 1,
            self::Twos => 2,
            self::Threes => 3,
            self::Fours => 4,
            self::Fives => 5,
            self::Sixes => 6,
            default => null,
        };
    }

    /**
     * The Upper Section category matching a given die face, e.g. 4 -> Fours.
     * Used by the Joker rule to find which upper box a Yahtzee roll matches.
     */
    public static function forFace(int $face): ?self
    {
        return match ($face) {
            1 => self::Ones,
            2 => self::Twos,
            3 => self::Threes,
            4 => self::Fours,
            5 => self::Fives,
            6 => self::Sixes,
            default => null,
        };
    }

    /**
     * The `Yahtzee` case's own label is `'Yacht'`, not `'Yahtzee'` -- see
     * `App\Enums\GameType::label()`'s own docblock for why the game's
     * user-visible name changed without touching any case names/values.
     * The five-of-a-kind category is the same trademarked term, so it
     * changed too, matching the real historical "Yacht" game's own
     * identically-named top category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ones => 'Ones',
            self::Twos => 'Twos',
            self::Threes => 'Threes',
            self::Fours => 'Fours',
            self::Fives => 'Fives',
            self::Sixes => 'Sixes',
            self::ThreeOfAKind => '3 of a Kind',
            self::FourOfAKind => '4 of a Kind',
            self::FullHouse => 'Full House',
            self::SmallStraight => 'Sm. Straight',
            self::LargeStraight => 'Lg. Straight',
            self::Yahtzee => 'Yacht',
            self::Chance => 'Chance',
        };
    }
}
