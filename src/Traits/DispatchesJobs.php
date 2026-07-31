<?php

namespace ArtemYurov\CommandScheduleJob\Traits;

use ArtemYurov\CommandScheduleJob\DTO\JobInfo;
use ArtemYurov\CommandScheduleJob\Enums\JobStatus;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

trait DispatchesJobs
{
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

    /** Overlap middleware: JobLog's when db-joblog is present and the job is Loggable, else native. */
    protected function resolveOverlapMiddleware(object $job): object
    {
        $delay = $this->getWithoutOverlappingJobReleaseAfter();

        if (class_exists(\ArtemYurov\JobLog\Middleware\JobLogWithoutOverlapping::class)
            && in_array(\ArtemYurov\JobLog\Traits\Loggable::class, class_uses_recursive($job), true)) {
            $mw = new \ArtemYurov\JobLog\Middleware\JobLogWithoutOverlapping($delay);
        } else {
            // Key = class + sorted tags (order-independent); no tags → class alone, never shared.
            $tags = $this->resolveJobTags($job);
            sort($tags);
            $key = 'csj-overlap:' . get_class($job) . ($tags ? ':' . implode('|', $tags) : '');

            $mw = (new \Illuminate\Queue\Middleware\WithoutOverlapping($key))
                ->releaseAfter($delay)
                ->expireAfter($this->getWithoutOverlappingJobExpiresAt());
        }

        // dontRelease() = drop instead of serialize (null release delay).
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
            ->where('queued_at', '>=', now()->subSeconds($this->getShouldBeUniqueJobExpiresAt()));

        foreach ($tags as $tag) {
            $query->whereJsonContains('tags', $tag);
        }

        return $query->get()->map(fn ($jobLog) => new JobInfo(
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
        $expiresAtThreshold = now()->subSeconds($this->getShouldBeUniqueJobExpiresAt());

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
