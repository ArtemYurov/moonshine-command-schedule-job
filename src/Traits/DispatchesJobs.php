<?php

namespace ArtemYurov\CommandScheduleJob\Traits;

use ArtemYurov\CommandScheduleJob\DTO\JobInfo;
use ArtemYurov\CommandScheduleJob\Enums\JobStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;

trait DispatchesJobs
{
    protected function dispatchJob(...$args): void
    {
        $job = new $this->jobClass(...$args);

        if ($this->getWithoutOverlappingJob()) {
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

        $this->dispatchSync ? $job->dispatchSync(...$args) : $job->dispatch(...$args);
    }

    /**
     * Resolve tags for a job instance.
     * Uses job's tags() method if available, falls back to Horizon Tags::for().
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
            ->where('queued_at', '>=', now()->subMinutes($this->getJobExpiresAt()));

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
        $expiresAtThreshold = now()->subMinutes($this->getJobExpiresAt());

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
