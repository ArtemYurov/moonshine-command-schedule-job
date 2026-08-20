<?php

namespace ArtemYurov\CommandScheduleJob;

use ArtemYurov\CommandScheduleJob\Traits\DispatchesJobs;
use ArtemYurov\CommandScheduleJob\Traits\RegistersCommands;
use ArtemYurov\CommandScheduleJob\Traits\RegistersSchedule;
use ArtemYurov\CommandScheduleJob\Traits\TerminatesJobs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

abstract class CommandScheduleJobService
{
    use RegistersCommands;
    use RegistersSchedule;
    use DispatchesJobs;
    use TerminatesJobs;

    protected string $commandSignature;

    protected string $commandDescription;

    protected ?string $scheduleFrequency = null;
    protected ?array $scheduleFrequencyArgs = null;

    protected ?string $scheduleConsoleArgs = null;

    /** @var class-string|null Job class to dispatch. If null, handle() runs synchronously. */
    protected ?string $jobClass = null;

    protected bool $dispatchSync = false;
    protected bool $forceRun = false;

    /** Whether this service may enter the scheduler. Code-only (no DB column); a non-schedulable service never gets a cron entry, but can still be run manually. */
    protected bool $schedulable = true;

    /** Job deduplication — drop/takeover an already-active job before dispatch (ShouldBeUnique semantics). Opt-in. */
    protected bool $shouldBeUniqueJob = false;

    /** How old an active job may be before the dispatch guard ignores it: seconds from queued_at. null = from config default_should_be_unique_job_expires_after */
    protected ?int $shouldBeUniqueJobExpiresAfter = null;

    /** Execution-level overlap prevention — attach middleware on dispatch (serialize via release/retry, not drop) */
    protected bool $withoutOverlappingJob = false;

    /** Overlap middleware release delay (seconds). null = from config default_without_overlapping_job_release_after */
    protected ?int $withoutOverlappingJobReleaseAfter = null;

    /** Overlap behaviour — serialize (release/retry, default) vs drop (dontRelease). Code-only (no DB column); drop can silently discard work. */
    protected bool $withoutOverlappingJobDontRelease = false;

    /** How old a run may be before the overlap middleware writes it off: seconds from started_at. Staleness cap for the JobLog variant, lock TTL for the native one. null = from config default_without_overlapping_job_expires_after */
    protected ?int $withoutOverlappingJobExpiresAfter = null;

    /** Console command instance for styled output and interactive prompts. Null when called outside CLI. */
    protected ?Command $command = null;

    // ──────────────────────────────────────────────
    // Registration
    // ──────────────────────────────────────────────

    public function registerCommands(): void
    {
        try {
            $this->registerCommand();
            $this->registerSchedule();
        } catch (\Exception $e) {
            Log::warning("Failed to register commands for " . static::class . ": " . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    // Execution
    // ──────────────────────────────────────────────

    public function execute(array $params = []): void
    {
        $this->updateScheduleRunTimes();
        $this->handle($params);
    }

    /**
     * Handle the service execution.
     *
     * Override this method to implement custom logic.
     * Default implementation dispatches $jobClass if configured.
     */
    protected function handle(array $params = []): void
    {
        if ($this->jobClass) {
            $this->dispatchJob();
        }
    }

    // ──────────────────────────────────────────────
    // Setters & State
    // ──────────────────────────────────────────────

    public function setCommand(?Command $command): self
    {
        $this->command = $command;
        return $this;
    }

    public function isVerbose(): bool
    {
        return $this->command?->getOutput()->isVerbose() ?? false;
    }

public function setDispatchSync(?bool $dispatchSync): self
    {
        $this->dispatchSync = $dispatchSync ?? false;
        return $this;
    }

    public function setForceRun(?bool $forceRun): self
    {
        $this->forceRun = $forceRun ?? false;
        return $this;
    }

    public function setWithoutOverlappingJob(?bool $withoutOverlappingJob): self
    {
        $this->withoutOverlappingJob = $withoutOverlappingJob ?? false;
        return $this;
    }

    public function setWithoutOverlappingJobReleaseAfter(?int $withoutOverlappingJobReleaseAfter): self
    {
        $this->withoutOverlappingJobReleaseAfter = $withoutOverlappingJobReleaseAfter;
        return $this;
    }

    public function getWithoutOverlappingJobReleaseAfter(): int
    {
        return $this->withoutOverlappingJobReleaseAfter ?? config('command-schedule-job.default_without_overlapping_job_release_after', 10);
    }

    public function setWithoutOverlappingJobDontRelease(?bool $withoutOverlappingJobDontRelease): self
    {
        $this->withoutOverlappingJobDontRelease = $withoutOverlappingJobDontRelease ?? false;
        return $this;
    }

    public function isWithoutOverlappingJobDontRelease(): bool
    {
        return $this->withoutOverlappingJobDontRelease;
    }

    public function setWithoutOverlappingJobExpiresAfter(?int $withoutOverlappingJobExpiresAfter): self
    {
        $this->withoutOverlappingJobExpiresAfter = $withoutOverlappingJobExpiresAfter;
        return $this;
    }

    public function getWithoutOverlappingJobExpiresAfter(): int
    {
        return $this->withoutOverlappingJobExpiresAfter ?? config('command-schedule-job.default_without_overlapping_job_expires_after', 3600);
    }

    public function getShouldBeUniqueJobExpiresAfter(): int
    {
        return $this->shouldBeUniqueJobExpiresAfter ?? config('command-schedule-job.default_should_be_unique_job_expires_after', 3 * 60 * 60);
    }

    public function getJobClass(): ?string
    {
        return $this->jobClass;
    }

    public function getCommandSignature(): string
    {
        return $this->commandSignature;
    }

    public function getCommandDescription(): string
    {
        return $this->commandDescription;
    }

    // ──────────────────────────────────────────────
    // Resolved getters (DB value → property fallback, for runtime)
    // ──────────────────────────────────────────────

    /**
     * Default property values for DB population.
     * Uses raw property values to avoid circular DB lookups.
     */
    public function getDefaults(): array
    {
        return [
            'frequency' => $this->scheduleFrequency,
            'frequency_args' => $this->scheduleFrequencyArgs,
            'description' => $this->commandDescription ?: null,
            'should_be_unique_job' => $this->shouldBeUniqueJob,
        ];
    }

    public function isSchedulable(): bool
    {
        return $this->schedulable;
    }

    public function getScheduleFrequency(): ?string
    {
        return $this->getScheduleConfig()?->frequency ?: $this->scheduleFrequency;
    }

    public function getScheduleFrequencyArgs(): ?array
    {
        return $this->getScheduleConfig()?->frequency_args ?? $this->scheduleFrequencyArgs;
    }

    public function getScheduleConsoleArgs(): ?string
    {
        return $this->getScheduleConfig()?->schedule_console_args ?? $this->scheduleConsoleArgs;
    }

    public function isShouldBeUniqueJob(): bool
    {
        return $this->getScheduleConfig()?->should_be_unique_job ?? $this->shouldBeUniqueJob;
    }

    /** Code-only: whether a task tolerates a concurrent run is a property of the task. */
    public function isWithoutOverlappingJob(): bool
    {
        return $this->withoutOverlappingJob;
    }

}
