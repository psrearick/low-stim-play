<?php

namespace App\Analytics\Contracts;

use App\Analytics\PageviewData;

/**
 * The seam docs/specs/analytics.md section 6.7 asks for: if per-request
 * inserts ever become a bottleneck, a buffered writer can push onto a
 * Redis list and flush in batches from a scheduled command, without
 * App\Analytics\Jobs\RecordPageview or the middleware needing to change.
 * Not built until that's actually needed -- see App\Analytics\
 * DatabaseEventWriter, the only implementation that exists today.
 */
interface EventWriter
{
    public function write(PageviewData $data): void;
}
