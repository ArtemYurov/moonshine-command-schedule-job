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
    | Default Should Be Unique Job Expires After
    |--------------------------------------------------------------------------
    |
    | How old an active job may be before the dispatch-time uniqueness check ignores
    | it: seconds from queued_at. Per-service: $shouldBeUniqueJobExpiresAfter.
    |
    | Same kind of value as the overlap key below, measured from a different point:
    | this one bounds how long a job may sit queued, that one how long it may run.
    |
    */

    'default_should_be_unique_job_expires_after' => 3 * 60 * 60, // 3 hours

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
    | Default Without Overlapping Job Expires After
    |--------------------------------------------------------------------------
    |
    | How old a run may be before the overlap middleware writes it off as broken
    | rather than busy: seconds from started_at. For the JobLog middleware this is a
    | staleness cap on PROCESSING rows, for the native fallback it IS the lock TTL.
    | Either way a job whose own timeout would outlast this value gets it raised to
    | timeout + 60s automatically, with a warning — so the value only has to cover
    | jobs that declare no timeout of their own.
    | Per-service: $withoutOverlappingJobExpiresAfter.
    |
    */

    'default_without_overlapping_job_expires_after' => 60 * 60, // 1 hour

];
