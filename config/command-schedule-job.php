<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registered Services
    |--------------------------------------------------------------------------
    |
    | Explicitly registered service classes. These are merged with
    | auto-discovered services and programmatically registered ones.
    |
    | Example: \App\Services\MyCustomService::class,
    |
    */

    'services' => [],

    /*
    |--------------------------------------------------------------------------
    | Service Discovery
    |--------------------------------------------------------------------------
    |
    | Paths and namespaces for auto-discovery of CommandScheduleJobService classes.
    | The package scans these directories recursively for non-abstract classes
    | that extend CommandScheduleJobService.
    |
    */

    'discovery' => [
        'paths' => [
            'app/Services/',
        ],
        'namespaces' => [
            'App\\Services',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    |
    | The database table used for storing service schedule configurations.
    |
    */

    'table' => 'command_schedule_jobs',

    /*
    |--------------------------------------------------------------------------
    | Default Should Be Unique Job Expires At
    |--------------------------------------------------------------------------
    |
    | Look-back window (seconds) for the dispatch-time uniqueness check; older
    | active jobs are treated as stale. Per-service: $shouldBeUniqueJobExpiresAt.
    |
    */

    'default_should_be_unique_job_expires_at' => 3 * 60 * 60, // 3 hours

    /*
    |--------------------------------------------------------------------------
    | Default Without Overlapping Job Release After
    |--------------------------------------------------------------------------
    |
    | Release delay (seconds) for the overlap middleware: a job that hits an active
    | peer is released and retried after this. Per-service: $withoutOverlappingJobReleaseAfter.
    |
    */

    'default_without_overlapping_job_release_after' => 10, // 10 seconds

    /*
    |--------------------------------------------------------------------------
    | Default Without Overlapping Job Expires At
    |--------------------------------------------------------------------------
    |
    | Lock TTL (seconds) for the NATIVE fallback middleware only (unused by the
    | JobLog middleware); should exceed the job's max run time. Per-service:
    | $withoutOverlappingJobExpiresAt.
    |
    */

    'default_without_overlapping_job_expires_at' => 60 * 60, // 1 hour

];
