<?php

use App\Models\FamilyFeudQuestion;
use Database\Seeders\FamilyFeudQuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the seeder loads the bundled question bank', function () {
    (new FamilyFeudQuestionSeeder)->run();

    expect(FamilyFeudQuestion::count())->toBeGreaterThanOrEqual(12);

    $question = FamilyFeudQuestion::first();

    expect($question->external_id)->not->toBeEmpty()
        ->and($question->prompt)->not->toBeEmpty()
        ->and($question->answers)->toBeArray()
        ->and($question->answers)->not->toBeEmpty()
        ->and($question->answers[0])->toHaveKeys(['text', 'points']);
});

test('running the seeder twice does not duplicate questions', function () {
    (new FamilyFeudQuestionSeeder)->run();
    $countAfterFirstRun = FamilyFeudQuestion::count();

    (new FamilyFeudQuestionSeeder)->run();

    expect(FamilyFeudQuestion::count())->toBe($countAfterFirstRun);
});
