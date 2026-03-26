<?php

namespace ArtemYurov\CommandScheduleJob\Providers;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobServiceRegistry;
use ArtemYurov\CommandScheduleJob\Console\MakeServiceCommand;
use Illuminate\Support\ServiceProvider;

class CommandScheduleJobServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/command-schedule-job.php', 'command-schedule-job');

        $this->app->singleton(CommandScheduleJobServiceRegistry::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../../lang', 'command-schedule-job');

        $this->publishes([
            __DIR__ . '/../../config/command-schedule-job.php' => config_path('command-schedule-job.php'),
        ], 'command-schedule-job-config');

        $this->publishes([
            __DIR__ . '/../../lang' => $this->app->langPath('vendor/command-schedule-job'),
        ], 'command-schedule-job-lang');

        $this->publishes([
            __DIR__ . '/../../database/migrations/' => database_path('migrations'),
        ], 'command-schedule-job-migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeServiceCommand::class,
            ]);

            $services = app(CommandScheduleJobServiceRegistry::class)->all();

            foreach ($services as $service) {
                $this->app->bind($service);
                $this->app->make($service)->registerCommands();
            }
        }
    }
}
