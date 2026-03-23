<?php

namespace ArtemYurov\CommandScheduleJob\Traits;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

trait RegistersCommands
{
    protected function registerCommand(): void
    {
        if ($this->jobClass) {
            $this->ensureDispatchSyncOptionExists();
            $this->ensureForceOptionExists();
        }

        $serviceClass = static::class;
        $description = $this->getCommandDescription();

        Artisan::command($this->commandSignature, function () use ($serviceClass, $description) {
            /** @var $this Command */
            $this->info(__('command-schedule-job::messages.console.starting_service', ['description' => $description]));

            $params = array_merge($this->arguments(), $this->options());

            /** @var CommandScheduleJobService $service */
            $service = app($serviceClass);
            $service->setCommand($this);

            if ($this->hasOption('dispatch-sync')) {
                $service->setDispatchSync($this->option('dispatch-sync'));
            }

            if ($this->hasOption('force')) {
                $service->setForceRun($this->option('force'));
            }

            $service->execute($params);

            $this->info(__('command-schedule-job::messages.console.service_completed'));
        })->describe($this->getCommandDescription());
    }

    protected function ensureDispatchSyncOptionExists(): void
    {
        if (strpos($this->commandSignature, '--dispatch-sync') === false) {
            $this->commandSignature .= ' {--dispatch-sync : Dispatch job synchronously}';
        }
    }

    protected function ensureForceOptionExists(): void
    {
        if (strpos($this->commandSignature, '--force') === false) {
            $this->commandSignature .= ' {--force : Terminate active jobs with matching tags and restart}';
        }
    }

}
