<?php

use App\Services\Wordle\AnswerPicker;
use App\Services\Words\CommonWordFilter;

beforeEach(function () {
    $this->wordsPath = tempnam(sys_get_temp_dir(), 'wordle-words-');
    file_put_contents($this->wordsPath, implode("\n", [
        'a', 'cat', // too short
        'crane', 'adieu', 'rebus', 'xylyl',
        'electroencephalographic', // too long
    ]));

    $this->frequencyPath = tempnam(sys_get_temp_dir(), 'wordle-freq-');
    file_put_contents($this->frequencyPath, implode("\n", [
        "crane\t500",
        "adieu\t400",
        "rebus\t10",
        // xylyl deliberately has no frequency data -- must never be picked.
    ]));

    $this->picker = new AnswerPicker(new CommonWordFilter($this->frequencyPath), $this->wordsPath);
});

afterEach(function () {
    unlink($this->wordsPath);
    unlink($this->frequencyPath);
});

test('pick only ever returns a 5-letter word with frequency data', function () {
    foreach (range(1, 20) as $seed) {
        $word = $this->picker->pick($seed)['word'];

        expect(mb_strlen($word))->toBe(5)
            ->and($word)->toBeIn(['crane', 'adieu', 'rebus']);
    }
});

test('the same seed always picks the same word', function () {
    expect($this->picker->pick(42))->toBe($this->picker->pick(42));
});

test('different seeds can pick different words', function () {
    $words = collect(range(1, 20))->map(fn (int $seed) => $this->picker->pick($seed)['word']);

    expect($words->unique()->count())->toBeGreaterThan(1);
});

test('pick returns the seed it actually used, generating one when none is given', function () {
    $result = $this->picker->pick();

    expect($result['seed'])->toBeInt()
        ->and($this->picker->pick($result['seed'])['word'])->toBe($result['word']);
});

test('a candidate pool with no common words throws', function () {
    $emptyFrequencyPath = tempnam(sys_get_temp_dir(), 'wordle-freq-empty-');
    file_put_contents($emptyFrequencyPath, '');

    try {
        expect(fn () => new AnswerPicker(new CommonWordFilter($emptyFrequencyPath), $this->wordsPath))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($emptyFrequencyPath);
    }
});
