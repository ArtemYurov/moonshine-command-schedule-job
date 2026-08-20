# MoonShine Command Schedule Job

[![PHP](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11|12|13-red.svg)](https://laravel.com)
[![MoonShine](https://img.shields.io/badge/MoonShine-4.x-purple.svg)](https://moonshine-laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)

**[Русская версия (README.ru.md)](README.ru.md)**

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
| `$schedulable` | `bool` | `true` | Code-only capability flag. When `false`, the service never enters the scheduler (no cron entry, hidden schedule fields in the admin UI) — but `php artisan <command>` still works |
| `$shouldBeUniqueJob` | `bool` | `false` | Dispatch-guard: drop/takeover an already-active job with matching tags before dispatch |
| `$shouldBeUniqueJobExpiresAfter` | `?int` | `null` | How old an active job may be before the dispatch guard ignores it — seconds from `queued_at` (null = config default) |
| `$withoutOverlappingJob` | `bool` | `false` | Execution-level overlap middleware: serialize a run that hits an active peer (release/retry) |
| `$withoutOverlappingJobReleaseAfter` | `?int` | `null` | Overlap middleware release delay in seconds (null = config default) |
| `$withoutOverlappingJobDontRelease` | `bool` | `false` | Code-only overlap behaviour: `false` = serialize (release/retry), `true` = drop the overlapping run instead of waiting |
| `$withoutOverlappingJobExpiresAfter` | `?int` | `null` | How old a run may be before the overlap middleware writes it off — seconds from `started_at`; staleness cap for the JobLog variant, lock TTL for the native one. Raised to `timeout + 60s` when the job would outlast it (null = config default) |

### Storage principle

```
DB = on/off + when          code = how + how-long
```

- **DB (admin-editable):** schedule (`schedule_enabled`, `frequency`, `frequency_args`, `schedule_console_args`) plus the two protection toggles (`should_be_unique_job`, `without_overlapping_job`).
- **Code (developer, structural):** timings, serialize-vs-drop (`$withoutOverlappingJobDontRelease`), own/native middleware choice, and `$schedulable`. These never get DB columns.

### Overlap prevention contract

In serialize mode a job that hits an active peer is **released back to the queue** and retried, which consumes an attempt. So give it a retry budget — `public function retryUntil(): \DateTime` (preferred; also caps the wait) or `public int $tries > 1` — otherwise the first release exhausts its single attempt and the run is lost.

Both settings — the release delay and the expiry — reach whichever middleware is chosen: the JobLog-backed one when `moonshine-db-joblog` is installed and the job is `Loggable`, the native one otherwise. What the expiry *means* differs:

- **JobLog variant** — busy means a live process, so the expiry is only a staleness cap on `PROCESSING` rows: it stops a lost bookkeeping write from wedging a tag forever.
- **Native variant** — the expiry IS the lock TTL, so a job outliving it would lose its lock mid-run.

Either way a job whose own `timeout` would outlast the configured value gets the expiry raised to `timeout + 60s` automatically, with a warning naming the job — so the configured value only has to cover jobs that declare no timeout.

The dispatch guard ignores `PROCESSING` records whose worker is gone — otherwise a crash would keep it skipping ticks for the whole look-back window.

---

## Dispatching a set

`dispatchJob(...$args)` dispatches one job; `batch()` builds a set as a native `PendingBatch` with the same flags on every job. You pass **items, not jobs** — the class is always `$jobClass` (which must use `Batchable`), the item is its first constructor argument, `$args` follow.

```php
$batch = LoadPaymentsService::batch($accounts, $dispatchSync, [$dryRun])   // from outside
    ?->name('load payments')
    ->allowFailures()
    ->dispatch();

Service::make()->setSomething(...)->newBatch($items, $args);   // when setters come first
$this->newBatch($items, $args);                                // inside the service's own handle()
```

`null` means nothing is left to do: sync already ran the jobs inline, the input was empty, or the guard dropped every item. Sync builds no batch, so those jobs carry no batch id and chained callbacks never fire.

`$dispatchSync` has no default because the static resolves a *fresh* service. Three things then differ from a hand-rolled `Bus::batch`: a busy tag drops **that one item** rather than the whole set (and never terminates the job holding it); the queue is preset from the first job's `$queue`, since batches bypass the dispatcher; and waiting is yours, because the native batch cannot wait either.

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
    // How old an active job may be before the dispatch guard ignores it (from queued_at).
    'default_should_be_unique_job_expires_after' => 3 * 60 * 60,   // 3 hours
    // Overlap middleware release delay (seconds) in serialize mode.
    'default_without_overlapping_job_release_after' => 10,      // 10 seconds
    // How old a run may be before it is written off as broken (from started_at):
    // staleness cap for the JobLog variant, lock TTL for the native one. Raised
    // automatically when a job's own timeout would outlast it.
    'default_without_overlapping_job_expires_after' => 60 * 60,  // 1 hour
];
```

> **Renamed in 2.0.1.** `default_should_be_unique_job_expires_at` and
> `default_without_overlapping_job_expires_at` became `…_expires_after`, along with the matching
> `$…ExpiresAt` service properties, getters and setters. Both always held a duration in seconds,
> so the `_at` suffix was a misnomer. Rename the keys if you had published the config — an
> unrenamed one silently falls back to the package defaults.

---

## Optional Dependencies

| Package | What it enables |
|---------|----------------|
| `laravel/horizon` | Queue-based job search and management |
| `artemyurov/moonshine-db-joblog` | PID-based precise job termination, and the JobLog-backed overlap middleware |
| `ext-posix` | PID-based process termination, and skipping stale `PROCESSING` records in the dispatch guard |

Core functionality works without these. Job deduplication and termination are enhanced when installed.

---

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- MoonShine 4.x

## License

MIT
