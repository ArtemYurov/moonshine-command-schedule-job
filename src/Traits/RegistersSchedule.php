<?php

namespace ArtemYurov\CommandScheduleJob\Traits;

use ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob;
use ArtemYurov\CommandScheduleJob\Support\FrequencyHelper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

trait RegistersSchedule
{
    private ?CommandScheduleJob $scheduleConfig = null;

    protected function registerSchedule(): void
    {
        $scheduleConfig = $this->getScheduleConfig();

        if (!$scheduleConfig || !$scheduleConfig->schedule_enabled)
            return;

        $frequency = $this->getScheduleFrequency();

        if ($frequency === null)
            return;

        $frequencyArgs = $this->getScheduleFrequencyArgs() ?? [];

        $validationError = FrequencyHelper::validateFrequencyArgs($frequency, $frequencyArgs);
        if ($validationError) {
            Log::warning("Schedule validation failed for " . static::class . ": " . $validationError);
            return;
        }

        $command = strtok($this->commandSignature, ' ');

        $consoleArgs = $this->getScheduleConsoleArgs();
        if ($consoleArgs) {
            $command .= ' ' . $consoleArgs;
        }

        app(Schedule::class)
            ->command($command)
            ->{$frequency}(...$frequencyArgs);
    }

    protected function getScheduleConfig(): ?CommandScheduleJob
    {
        if ($this->scheduleConfig) {
            return $this->scheduleConfig;
        }

        try {
            $this->scheduleConfig = CommandScheduleJob::findOrCreateForService(static::class);
        } catch (\Exception $e) {
            // table not yet migrated or other DB issue
        }

        return $this->scheduleConfig;
    }

    protected function updateScheduleRunTimes(): void
    {
        $config = $this->getScheduleConfig();

        if (!$config)
            return;

        $config->updateLastRunAt();
    }
}
