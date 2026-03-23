<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;

class DummyScheduleService extends CommandScheduleJobService
{
    protected string $commandSignature = 'test:dummy';
    protected string $commandDescription = 'Dummy test service';
    protected ?string $scheduleFrequency = 'daily';
    protected ?array $scheduleFrequencyArgs = null;

    public bool $handled = false;

    protected function handle(array $params = []): void
    {
        $this->handled = true;
    }
}
