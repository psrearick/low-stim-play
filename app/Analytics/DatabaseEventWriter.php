<?php

namespace App\Analytics;

use App\Analytics\Contracts\EventWriter;
use App\Analytics\Models\AnalyticsEvent;

class DatabaseEventWriter implements EventWriter
{
    public function write(PageviewData $data): void
    {
        AnalyticsEvent::create($data->toArray());
    }
}
