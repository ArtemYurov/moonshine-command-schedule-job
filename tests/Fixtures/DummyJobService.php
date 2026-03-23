<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;

class DummyJobService extends CommandScheduleJobService
{
    protected string $commandSignature = 'test:job-service';
    protected string $commandDescription = 'Service with job class';
    protected ?string $scheduleFrequency = 'daily';
    protected ?string $jobClass = DummyJob::class;

    public bool $handled = false;

    protected function handle(array $params = []): void
    {
        $this->handled = true;
    }
}
