<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DummyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static bool $dispatched = false;

    public function __construct()
    {
        //
    }

    public function handle(): void
    {
        static::$dispatched = true;
    }

    public function tags(): array
    {
        return ['dummy-job', 'test'];
    }
}
