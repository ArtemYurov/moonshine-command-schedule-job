<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Fixtures;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;
use Illuminate\Support\Collection;

class DummyBatchService extends CommandScheduleJobService
{
    protected string $commandSignature = 'test:batch-service';
    protected string $commandDescription = 'Service used for batch dispatch tests';
    protected ?string $jobClass = DummyBatchJob::class;

    /** @var string[] items whose tags are pretended to be held by a live job */
    public array $busyItems = [];

    protected function handle(array $params = []): void
    {
        //
    }

    /** The real lookup is always empty without joblog/Horizon; only emptiness matters. */
    protected function getActiveJobsByTags(array $tags): Collection
    {
        foreach ($this->busyItems as $item) {
            if (in_array('item:' . $item, $tags, true)) {
                return collect(['busy']);
            }
        }

        return collect();
    }
}
