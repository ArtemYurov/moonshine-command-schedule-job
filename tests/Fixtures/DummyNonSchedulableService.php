<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;

class DummyNonSchedulableService extends CommandScheduleJobService
{
    protected string $commandSignature = 'test:non-schedulable';
    protected string $commandDescription = 'Service that must never enter the scheduler';
    protected ?string $scheduleFrequency = 'daily';

    // Code-only capability flag: never registers a cron entry.
    protected bool $schedulable = false;

    protected function handle(array $params = []): void
    {
        // no-op
    }
}
