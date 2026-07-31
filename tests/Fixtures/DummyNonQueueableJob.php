<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A job that does NOT implement ShouldQueue — Bus\Dispatcher runs it through
 * dispatchNow(), where job middleware is never applied.
 */
class DummyNonQueueableJob
{
    use Dispatchable;

    public function handle(): void
    {
        //
    }

    public function tags(): array
    {
        return ['dummy-non-queueable-job'];
    }
}
