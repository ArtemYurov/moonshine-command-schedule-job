<?php

namespace ArtemYurov\CommandScheduleJob\Traits;

use ArtemYurov\CommandScheduleJob\DTO\JobInfo;
use ArtemYurov\CommandScheduleJob\Enums\JobStatus;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use LogicException;

trait DispatchesJobs
{
    /** Seconds added on top of a job's timeout when the configured overlap expiry is too short. */
    protected const OVERLAP_EXPIRY_MARGIN = 60;


    protected function dispatchJob(...$args): void
    {
        $job = new $this->jobClass(...$args);

        if ($this->isShouldBeUniqueJob()) {
            $tags = $this->resolveJobTags($job);
            $activeJobs = $this->getActiveJobsByTags($tags);

            if ($activeJobs->isNotEmpty()) {
                $this->command?->warn(__('command-schedule-job::messages.console.job_already_in_queue') . PHP_EOL . stripslashes(json_encode($tags, JSON_PRETTY_PRINT)));
                $this->dumpJobs($activeJobs);

                if (!$this->command) {
                    return;
                }

                if (!$this->forceRun && !$this->command->confirm(__('command-schedule-job::messages.console.terminate_confirm'))) {
                    return;
                }

                if (!$this->terminateActiveJobs($activeJobs)) {
                    return;
                }
            }
        }

        if ($this->isWithoutOverlappingJob()) {
            $this->attachOverlapMiddleware($job);
        }

        // This exact instance; static $job::dispatch(...$args) would rebuild a second job.
        $this->dispatchSync ? dispatch_sync($job) : dispatch($job);
    }

    /**
     * Short path from outside: a fresh service straight from the container.
     *
     * $dispatchSync has no default because this resolves a NEW instance — naming it is what
     * keeps a service's own handle() from silently dropping the flag the command set. Inside
     * a service use $this->newBatch() instead.
     */
    public static function batch(iterable $items, bool $dispatchSync, array $args = []): ?PendingBatch
    {
        return static::make()->setDispatchSync($dispatchSync)->newBatch($items, $args);
    }

    /** The service itself, for when setters are needed before batching. */
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Batch version of dispatchJob(): one job per item, same service flags.
     *
     * Items, not jobs: the class is always $this->jobClass, the item its first constructor
     * argument. What comes back is a native PendingBatch, used exactly like Bus::batch().
     *
     * @param  array  $args  extra constructor arguments, after the item
     * @return PendingBatch|null  nothing left to do: sync already ran the jobs inline, the
     *                            input was empty, or the guard dropped every item
     */
    public function newBatch(iterable $items, array $args = []): ?PendingBatch
    {
        if ($this->jobClass === null) {
            throw new LogicException(static::class . ' has no $jobClass, so there is nothing to batch.');
        }

        // Up front, so the refusal does not depend on the mode: sync never reaches Bus::batch.
        if (!in_array(Batchable::class, class_uses_recursive($this->jobClass), true)) {
            throw new LogicException($this->jobClass . ' must use the Batchable trait to be batched.');
        }

        $jobs = [];
        $skipped = 0;

        foreach ($items as $item) {
            $job = new $this->jobClass($item, ...$args);

            // Unlike dispatchJob(): drops this item, not the whole set, and never terminates.
            if ($this->isShouldBeUniqueJob()) {
                $tags = $this->resolveJobTags($job);

                if ($this->getActiveJobsByTags($tags)->isNotEmpty()) {
                    Log::info('Skipping one ' . $this->jobClass . ' from ' . static::class
                        . ': an active job already holds these tags.', ['tags' => $tags]);
                    $skipped++;

                    continue;
                }
            }

            if ($this->isWithoutOverlappingJob()) {
                $this->attachOverlapMiddleware($job);
            }

            $jobs[] = $job;
        }

        // An empty batch never finishes: nothing decrements pendingJobs to zero.
        if ($jobs === []) {
            Log::debug('Nothing to batch from ' . static::class . ($skipped > 0
                ? ": all {$skipped} item(s) were dropped by the dispatch guard."
                : ': no items given.'));

            return null;
        }

        // Not through the batch: Laravel 11 swallows a failing sync job in Batch::add() and
        // leaves the batch unfinished, 12/13 propagate. This loop is the same on all three.
        if ($this->dispatchSync) {
            Log::debug('Dispatching ' . count($jobs) . ' job(s) inline from ' . static::class . ': sync mode.');

            foreach ($jobs as $job) {
                dispatch_sync($job);
            }

            return null;
        }

        // The bulk path ignores $job->queue and uses the batch option, so it must be set.
        return Bus::batch($jobs)->onQueue($jobs[0]->queue ?? null);
    }

    /**
     * Overlap expiry, never shorter than the job it protects.
     *
     * A job cannot outlive its own `timeout` by more than signal delivery — the worker
     * arms SIGALRM and kills it — so the configured value only has to clear that plus a
     * small margin. Below it the native lock would expire mid-run and let a second
     * execution in, which is what the middleware exists to prevent.
     */
    protected function resolveOverlapExpiresAfter(object $job): int
    {
        $configured = $this->getWithoutOverlappingJobExpiresAfter();
        $timeout = $job->timeout ?? 0;

        if ($timeout <= 0 || $timeout + self::OVERLAP_EXPIRY_MARGIN <= $configured) {
            return $configured;
        }

        $raised = $timeout + self::OVERLAP_EXPIRY_MARGIN;

        Log::warning('Overlap expiry of ' . $configured . 's is shorter than the timeout of '
            . get_class($job) . ' (' . $timeout . 's); using ' . $raised . 's instead. '
            . 'Raise $withoutOverlappingJobExpiresAfter or the package default to silence this.');

        return $raised;
    }

    /** Attach the overlap middleware. No-op if it could never run, or the job has its own. */
    protected function attachOverlapMiddleware(object $job): void
    {
        // Middleware runs only in CallQueuedHandler; dispatchNow() ignores it, and the
        // write would add a dynamic property (deprecated in PHP 8.2+) without Queueable.
        if (!$job instanceof ShouldQueue) {
            Log::warning('Overlap prevention skipped for ' . static::class . ': ' . get_class($job)
                . ' does not implement ShouldQueue, so job middleware is never applied.');

            return;
        }

        // Stacking ours on the job's own means two locks under different keys.
        if ($this->hasOverlapMiddleware($job)) {
            Log::debug('Overlap middleware already declared by ' . get_class($job)
                . ', skipping the one from ' . static::class . '.');

            return;
        }

        // Append (through() would replace it, dropping Loggable's middleware);
        // CallQueuedHandler merges this property with middleware() at run time.
        $job->middleware = array_merge($job->middleware ?? [], [$this->resolveOverlapMiddleware($job)]);
    }

    /** Whether the job already carries an overlap middleware — property or middleware(). */
    protected function hasOverlapMiddleware(object $job): bool
    {
        $overlapClasses = array_filter([
            \Illuminate\Queue\Middleware\WithoutOverlapping::class,
            \ArtemYurov\JobLog\Middleware\JobLogWithoutOverlapping::class,
        ], 'class_exists');

        // Same pair CallQueuedHandler pipes the job through.
        $middleware = array_merge(
            $job->middleware ?? [],
            method_exists($job, 'middleware') ? $job->middleware() : [],
        );

        foreach ($middleware as $item) {
            // Laravel accepts middleware as objects, class names or "Class@method" strings.
            $class = match (true) {
                is_object($item) => $item::class,
                is_string($item) => strtok($item, '@'),
                default => null,
            };

            if ($class === null) {
                continue;
            }

            foreach ($overlapClasses as $overlapClass) {
                // $allow_string also matches subclasses.
                if (is_a($class, $overlapClass, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Overlap middleware: JobLog's when db-joblog is present and the job is Loggable, else native.
     *
     * The branch decides only the class and the key; both share the native fluent
     * contract (JobLogWithoutOverlapping extends WithoutOverlapping), so the settings
     * are applied once, after it. Keeping expireAfter() inside the native branch used
     * to be correct — the JobLog variant had no TTL at all — but now it would silently
     * ignore $withoutOverlappingJobExpiresAfter for every Loggable job.
     */
    protected function resolveOverlapMiddleware(object $job): object
    {
        if (class_exists(\ArtemYurov\JobLog\Middleware\JobLogWithoutOverlapping::class)
            && in_array(\ArtemYurov\JobLog\Traits\Loggable::class, class_uses_recursive($job), true)) {
            // Key comes from the job's JobLog tags, built inside the middleware.
            $mw = new \ArtemYurov\JobLog\Middleware\JobLogWithoutOverlapping();
        } else {
            // Key = class + sorted tags (order-independent); no tags → class alone, never shared.
            $tags = $this->resolveJobTags($job);
            sort($tags);
            $key = 'csj-overlap:' . get_class($job) . ($tags ? ':' . implode('|', $tags) : '');

            $mw = new \Illuminate\Queue\Middleware\WithoutOverlapping($key);
        }

        // Per-service override first, package config as the fallback — see the getters.
        $mw->releaseAfter($this->getWithoutOverlappingJobReleaseAfter())
            ->expireAfter($this->resolveOverlapExpiresAfter($job));

        // dontRelease() = drop instead of serialize (null release delay). Strictly after
        // releaseAfter(), which it overwrites.
        if ($this->isWithoutOverlappingJobDontRelease()) {
            $mw->dontRelease();
        }

        return $mw;
    }

    /**
     * Resolve tags for a job — job's tags() method, then db-joblog resolver, then Horizon.
     */
    protected function resolveJobTags(object $job): array
    {
        if (method_exists($job, 'tags')) {
            return $job->tags();
        }

        if ($this->hasMoonshineDbJobLogTrait() && class_exists(\ArtemYurov\JobLog\Tags\TagResolver::class)) {
            return app(\ArtemYurov\JobLog\Tags\TagResolver::class)->resolve($job);
        }

        if (class_exists(\Laravel\Horizon\Tags::class)) {
            return \Laravel\Horizon\Tags::for($job);
        }

        return [];
    }

    /**
     * @return Collection<int, JobInfo>
     */
    protected function getActiveJobsByTags(array $tags): Collection
    {
        if ($this->hasMoonshineDbJobLogTrait() && class_exists(\ArtemYurov\JobLog\Models\JobLog::class)) {
            return $this->getActiveJobsViaMoonshineDbJobLog($tags);
        }

        if (interface_exists(\Laravel\Horizon\Contracts\JobRepository::class)) {
            return $this->getActiveJobsViaHorizon($tags);
        }

        return collect();
    }

    /**
     * Find active jobs via moonshine-db-joblog (has PID for precise kill).
     *
     * @return Collection<int, JobInfo>
     */
    protected function getActiveJobsViaMoonshineDbJobLog(array $tags): Collection
    {
        $query = \ArtemYurov\JobLog\Models\JobLog::where('job_class', $this->jobClass)
            ->whereIn('status', [
                \ArtemYurov\JobLog\Enums\JobLogStatus::QUEUED,
                \ArtemYurov\JobLog\Enums\JobLogStatus::PROCESSING,
            ])
            ->where('queued_at', '>=', now()->subSeconds($this->getShouldBeUniqueJobExpiresAfter()));

        foreach ($tags as $tag) {
            $query->whereJsonContains('tags', $tag);
        }

        // A killed worker writes no final status, so a PROCESSING row can outlive the
        // process that made the claim. JobLog::isActive() is where that rule lives.
        $rows = $query->get();
        $alive = $rows->filter(fn ($jobLog) => $jobLog->isActive());

        if ($alive->count() !== $rows->count()) {
            Log::debug('Ignoring ' . ($rows->count() - $alive->count())
                . ' job log(s) whose worker is gone.', ['tags' => $tags]);
        }

        return $alive->map(fn ($jobLog) => new JobInfo(
            jobUuid: $jobLog->job_uuid,
            status: JobStatus::tryFrom($jobLog->status->value) ?? JobStatus::PROCESSING,
            connection: $jobLog->connection,
            queue: $jobLog->queue,
            pid: $jobLog->pid,
            queuedAt: $jobLog->queued_at,
            startedAt: $jobLog->started_at,
        ));
    }

    /**
     * Find active jobs via Horizon API (no PID, fallback to Horizon restart).
     *
     * @return Collection<int, JobInfo>
     */
    protected function getActiveJobsViaHorizon(array $tags): Collection
    {
        $expiresAtThreshold = now()->subSeconds($this->getShouldBeUniqueJobExpiresAfter());

        return app(\Laravel\Horizon\Contracts\JobRepository::class)->getPending()
            ->where('name', $this->jobClass)
            ->filter(function ($job) use ($tags, $expiresAtThreshold) {
                $payload = json_decode($job->payload, true);
                $pushedAt = isset($payload['pushedAt']) ? Carbon::createFromTimestamp($payload['pushedAt']) : null;

                if (!$pushedAt || $pushedAt->lt($expiresAtThreshold)) {
                    return false;
                }

                $jobTags = $payload['tags'] ?? [];
                return empty(array_diff($tags, $jobTags));
            })
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);

                return new JobInfo(
                    jobUuid: $job->id,
                    status: match ($job->status) {
                        'pending' => JobStatus::QUEUED,
                        'reserved' => JobStatus::PROCESSING,
                        'completed' => JobStatus::COMPLETED,
                        'failed' => JobStatus::FAILED,
                        default => JobStatus::PROCESSING,
                    },
                    connection: $job->connection,
                    queue: $job->queue,
                    queuedAt: isset($payload['pushedAt']) ? Carbon::createFromTimestamp($payload['pushedAt']) : null,
                    startedAt: $job->reserved_at ? Carbon::createFromTimestamp($job->reserved_at) : null,
                );
            });
    }

    /**
     * @param Collection<int, JobInfo> $activeJobs
     */
    protected function dumpJobs(Collection $activeJobs): void
    {
        $this->command?->table(
            [
                __('command-schedule-job::messages.table.status'),
                __('command-schedule-job::messages.table.uuid'),
                __('command-schedule-job::messages.table.queue'),
                __('command-schedule-job::messages.table.connection'),
                __('command-schedule-job::messages.table.queued'),
                __('command-schedule-job::messages.table.started'),
            ],
            $activeJobs->map(fn (JobInfo $job) => [
                $job->status->value,
                $job->jobUuid,
                $job->queue ?: '-',
                $job->connection,
                $job->queuedAt?->format('Y-m-d H:i:s') ?? '-',
                $job->startedAt?->format('Y-m-d H:i:s') ?? '-',
            ])->all(),
        );
    }

    protected function hasMoonshineDbJobLogTrait(): bool
    {
        if (!$this->jobClass || !trait_exists(\ArtemYurov\JobLog\Traits\Loggable::class)) {
            return false;
        }

        return in_array(\ArtemYurov\JobLog\Traits\Loggable::class, class_uses_recursive($this->jobClass));
    }
}
