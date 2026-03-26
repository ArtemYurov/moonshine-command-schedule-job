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
    | Default Without Overlapping Job Expires At
    |--------------------------------------------------------------------------
    |
    | How far back (in minutes) to search for active jobs with matching tags.
    | Jobs older than this are considered stale and ignored during overlap check.
    | Can be overridden per-service via $withoutOverlappingJobExpiresAt.
    |
    */

    'default_without_overlapping_job_expires_at' => 180,

];
