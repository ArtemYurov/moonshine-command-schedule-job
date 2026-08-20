<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;

/** No $jobClass — batching has nothing to build. */
class DummyNoJobClassService extends CommandScheduleJobService
{
    protected string $commandSignature = 'test:no-job-class-service';
    protected string $commandDescription = 'Service without a job class';

    protected function handle(array $params = []): void
    {
        //
    }
}
