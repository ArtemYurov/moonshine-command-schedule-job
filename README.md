# MoonShine Command Schedule Job

[![PHP](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11|12-red.svg)](https://laravel.com)
[![MoonShine](https://img.shields.io/badge/MoonShine-4.x-purple.svg)](https://moonshine-laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

**One class = artisan command + scheduler + queue job + admin UI.**

Define a single service class — the package auto-registers an artisan command and scheduler entry, with a MoonShine admin panel for managing schedules at runtime.

---

## Quick Start

```php
class SyncAccountData extends CommandScheduleJobService
{
    protected string $commandSignature = 'sync:account-data {--account-id=}';
    protected string $commandDescription = 'Sync account data from external API';
    protected ?string $scheduleFrequency = 'everyFiveMinutes';
    protected ?string $jobClass = SyncAccountDataJob::class;

    protected function handle(array $params = []): void
    {
        $account = Account::findOrFail($params['account-id']);
        $this->dispatchJob($account);
    }
}
```

```bash
php artisan sync:account-data --account-id=42
```


---

## Installation

```bash
composer require artemyurov/moonshine-command-schedule-job
php artisan migrate
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=command-schedule-job-config
```

---

## Features

- **Console + Scheduler** — every service automatically becomes an artisan command and a scheduler entry
- **Queue Jobs** — set `$jobClass` to dispatch queued jobs with built-in deduplication
- **MoonShine Admin UI** — toggle scheduler, configure frequency, view run times, copy commands
- **Artisan Generator** — `php artisan make:command-schedule-job-service MyService`
- **Auto-discovery** — scans configured directories for service classes

---

## Service Registration

Services are collected from three sources (merged, deduplicated):

### 1. Auto-discovery

Scans configured directories for non-abstract classes extending `CommandScheduleJobService`:

```php
// config/command-schedule-job.php
'discovery' => [
    'paths' => ['app/Services/'],
    'namespaces' => ['App\\Services'],
],
```

### 2. Config registration

Explicitly list service classes in config:

```php
// config/command-schedule-job.php
'services' => [
    \App\Services\MyCustomService::class,
],
```

### 3. Programmatic registration (for packages)

Register services from a package's `ServiceProvider`:

```php
use ArtemYurov\CommandScheduleJob\CommandScheduleJobServiceRegistry;

class MyPackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        app(CommandScheduleJobServiceRegistry::class)
            ->register(MyPackageSyncService::class);
    }
}
```

All three sources are merged via `CommandScheduleJobServiceRegistry`.

---

## Service Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$commandSignature` | `string` | *required* | Artisan command signature |
| `$commandDescription` | `string` | *required* | Command description |
| `$scheduleFrequency` | `?string` | `null` | Schedule method (`daily`, `hourly`, `everyFiveMinutes`, etc.) |
| `$scheduleFrequencyArgs` | `?array` | `null` | Arguments for the frequency method |
| `$scheduleConsoleArgs` | `?string` | `null` | Extra console arguments for the scheduled command |
| `$jobClass` | `?string` | `null` | Job class to dispatch (null = sync execution) |
| `$withoutOverlappingJob` | `bool` | `true` | Check for active jobs before dispatch |
| `$withoutOverlappingJobExpiresAt` | `?int` | `null` | Lookup window in minutes (null = config default) |

---

## Configuration

```php
// config/command-schedule-job.php
return [
    'services' => [],
    'discovery' => [
        'paths' => ['app/Services/'],
        'namespaces' => ['App\\Services'],
    ],
    'table' => 'command_schedule_jobs',
    'default_without_overlapping_job_expires_at' => 180,
];
```

---

## Optional Dependencies

| Package | What it enables |
|---------|----------------|
| `laravel/horizon` | Queue-based job search and management |
| `artemyurov/moonshine-db-joblog` | PID-based precise job termination |
| `ext-posix` | PID-based process termination |

Core functionality works without these. Job deduplication and termination are enhanced when installed.

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- MoonShine 4.x

## License

MIT
