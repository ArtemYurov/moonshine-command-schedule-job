<?php

namespace ArtemYurov\CommandScheduleJob\DTO;

use ArtemYurov\CommandScheduleJob\Enums\JobStatus;
use Carbon\Carbon;

readonly class JobInfo
{
    public function __construct(
        public string $jobUuid,
        public JobStatus $status,
        public string $connection,
        public string $queue,
        public ?int $pid = null,
        public ?Carbon $queuedAt = null,
        public ?Carbon $startedAt = null,
    ) {}
}
