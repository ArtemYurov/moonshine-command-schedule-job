<?php

namespace ArtemYurov\CommandScheduleJob\Enums;

enum JobStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case INTERRUPTED = 'interrupted';
}
