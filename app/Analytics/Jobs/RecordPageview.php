<?php

namespace App\Analytics\Jobs;

use App\Analytics\Contracts\EventWriter;
use App\Analytics\PageviewData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordPageview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly PageviewData $data) {}

    public function handle(EventWriter $writer): void
    {
        $writer->write($this->data);
    }
}
