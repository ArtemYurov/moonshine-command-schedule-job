<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Item first, extra constructor args after it — the shape dispatchBatch() builds. */
class DummyBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var string[] items whose job actually ran */
    public static array $handled = [];

    public function __construct(public string $item, public bool $dryRun = false)
    {
        $this->queue = 'batch-fixture';
    }

    public function handle(): void
    {
        static::$handled[] = $this->item;
    }

    public function tags(): array
    {
        return ['dummy-batch', 'item:' . $this->item];
    }
}
