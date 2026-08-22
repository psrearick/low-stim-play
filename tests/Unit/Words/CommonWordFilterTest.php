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
    ]));

    $this->filter = new CommonWordFilter($this->path);
});

afterEach(function () {
    unlink($this->path);
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
