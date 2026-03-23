<?php

return [

    // Console output
    'console' => [
        'starting_service' => 'Starting service :description...',
        'service_completed' => 'Service call completed',
        'job_already_in_queue' => 'Job already in queue, tags:',
        'terminate_confirm' => 'Terminate active jobs?',
        'force_terminating' => 'Force terminating active jobs',
        'process_killed' => 'Process PID :pid killed (:uuid)',
        'removed_from_queue' => 'Removed from queue: :uuid',
        'marked_interrupted' => 'Marked as interrupted: :uuid',
        'error_terminating' => 'Error terminating job :uuid: :error',
        'horizon_terminate_confirm' => 'horizon:terminate will be called to stop processing jobs (affects all workers). Continue?',
        'horizon_restart_cancelled' => 'Horizon restart cancelled, some jobs may still be running',
        'restarting_horizon' => 'Restarting Horizon...',
        'job_not_found' => 'Job with UUID :uuid not found in queue :queue',
    ],

    // Table headers (dumpJobs)
    'table' => [
        'status' => 'Status',
        'uuid' => 'UUID',
        'queue' => 'Queue',
        'connection' => 'Connection',
        'queued' => 'Queued',
        'started' => 'Started',
    ],

    // MoonShine resource
    'resource' => [
        'title' => 'Manage Command Schedule Job',
        'service' => 'Service',
        'frequency' => 'Frequency',
        'last_run' => 'Last Run',
        'next_run' => 'Next Run',
        'scheduler' => 'Scheduler',
        'general' => 'General',
        'service_class' => 'Service Class',
        'description' => 'Description',
        'arguments' => 'Arguments',
        'scheduler_enabled' => 'Scheduler Enabled',
        'without_overlapping_job' => 'Without Overlapping Job',
        'console_args' => 'Console Args',
        'console_args_hint' => 'e.g. --force --param=value',
        'scheduler_enabled_filter' => 'Scheduler enabled',
        'command' => 'Command',
    ],

    // Frequency descriptions
    'frequency' => [
        'everySecond' => 'Every second',
        'everyMinute' => 'Every minute',
        'everyTwoMinutes' => 'Every 2 minutes',
        'everyThreeMinutes' => 'Every 3 minutes',
        'everyFourMinutes' => 'Every 4 minutes',
        'everyFiveMinutes' => 'Every 5 minutes',
        'everyTenMinutes' => 'Every 10 minutes',
        'everyFifteenMinutes' => 'Every 15 minutes',
        'everyThirtyMinutes' => 'Every 30 minutes',
        'hourly' => 'Hourly',
        'everyTwoHours' => 'Every 2 hours',
        'everyThreeHours' => 'Every 3 hours',
        'everyFourHours' => 'Every 4 hours',
        'everySixHours' => 'Every 6 hours',
        'daily' => 'Daily',
        'dailyAt' => 'Daily at specified time',
        'twiceDaily' => 'Twice daily',
        'twiceDailyAt' => 'Twice daily at specified times',
        'weekly' => 'Weekly',
        'weeklyOn' => 'Weekly on specified day',
        'monthly' => 'Monthly',
        'monthlyOn' => 'Monthly on specified date',
        'lastDayOfMonth' => 'Last day of month',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
        'yearlyOn' => 'Yearly on specified date',
    ],

    // Validation
    'validation' => [
        'unknown_frequency' => 'Unknown frequency: :frequency',
        'invalid_args_count' => 'Required arguments: :expected, given: :actual',
    ],

];
