<?php

use App\Services\Words\CommonWordFilter;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'word-frequency-');
    file_put_contents($this->path, implode("\n", [
        "the\t1000",
        "crane\t500",
        "adieu\t400",
        "rebus\t10",
        "xylyl\t1",
        "slave\t50",
    ]));

    $this->excludedPath = tempnam(sys_get_temp_dir(), 'excluded-words-');
    file_put_contents($this->excludedPath, implode("\n", [
        '# a comment, and a blank line below',
        '',
        'slave',
    ]));

    $this->filter = new CommonWordFilter($this->path, $this->excludedPath);
});

afterEach(function () {
    unlink($this->path);
    unlink($this->excludedPath);
});

test('mostCommon drops words with no frequency data at all', function () {
    $result = $this->filter->mostCommon(['crane', 'zzzzz'], limit: 10);

    expect($result)->toBe(['crane']);
});

test('mostCommon ranks by descending frequency', function () {
    $result = $this->filter->mostCommon(['rebus', 'crane', 'adieu'], limit: 10);

    expect($result)->toBe(['crane', 'adieu', 'rebus']);
});

test('mostCommon respects the limit, keeping only the most frequent', function () {
    $result = $this->filter->mostCommon(['rebus', 'crane', 'adieu'], limit: 2);

    expect($result)->toBe(['crane', 'adieu']);
});

test('mostCommon matches case-insensitively against the (lowercase) corpus', function () {
    $result = $this->filter->mostCommon(['CRANE'], limit: 10);

    expect($result)->toBe(['CRANE']);
});

test('mostCommon returns an empty list when nothing in the candidate list has frequency data', function () {
    $result = $this->filter->mostCommon(['zzzzz', 'wwwww'], limit: 10);

    expect($result)->toBe([]);
});

test('mostCommon drops words on the excluded-words list even when they have frequency data', function () {
    $result = $this->filter->mostCommon(['slave', 'crane'], limit: 10);

    expect($result)->toBe(['crane']);
});

test('mostCommon excludes case-insensitively', function () {
    $result = $this->filter->mostCommon(['SLAVE', 'crane'], limit: 10);

    expect($result)->toBe(['crane']);
});

test('mostCommon does not let an excluded word take up a limit slot', function () {
    $result = $this->filter->mostCommon(['slave', 'crane', 'adieu'], limit: 2);

    expect($result)->toBe(['crane', 'adieu']);
});
