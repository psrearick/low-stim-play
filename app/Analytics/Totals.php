<?php

namespace App\Analytics;

final readonly class Totals
{
    public function __construct(
        public int $pageviews,
        public int $visitors,
        public float $viewsPerVisitor,
    ) {}
}
