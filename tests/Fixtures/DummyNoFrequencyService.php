<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;

class DummyNoFrequencyService extends CommandScheduleJobService
{
    protected string $commandSignature = 'test:no-frequency';
    protected string $commandDescription = 'Service without frequency';
    protected ?string $scheduleFrequency = null;

    protected function handle(array $params = []): void
    {
        // no-op
    }
}
