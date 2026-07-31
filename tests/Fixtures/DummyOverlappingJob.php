<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * A job declaring its own overlap middleware — the service must not stack a
 * second one on top under a different key.
 */
class DummyOverlappingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        //
    }

    public function tags(): array
    {
        return ['dummy-overlapping-job'];
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping('own-key')];
    }
}
