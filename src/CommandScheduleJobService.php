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

    /** Job deduplication — check for active jobs before dispatch */
    protected bool $withoutOverlappingJob = true;

    /** Active job lookup window (minutes). null = from config default_without_overlapping_job_expires_at */
    protected ?int $withoutOverlappingJobExpiresAt = null;

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

    public function getJobExpiresAt(): int
    {
        return $this->withoutOverlappingJobExpiresAt ?? config('command-schedule-job.default_without_overlapping_job_expires_at', 180);
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
            'without_overlapping_job' => $this->withoutOverlappingJob,
        ];
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

    public function getWithoutOverlappingJob(): bool
    {
        return $this->getScheduleConfig()?->without_overlapping_job ?? $this->withoutOverlappingJob;
    }

}
