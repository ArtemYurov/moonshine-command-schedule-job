<?php

namespace ArtemYurov\CommandScheduleJob\Providers;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;
use ArtemYurov\CommandScheduleJob\Console\MakeServiceCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class CommandScheduleJobServiceProvider extends ServiceProvider
{
    private array $discoveredServices = [];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/command-schedule-job.php', 'command-schedule-job');

        $this->discoveredServices = static::discoverServices();

        foreach ($this->discoveredServices as $service) {
            $this->app->bind($service);
        }
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

            foreach ($this->discoveredServices as $service) {
                $this->app->make($service)->registerCommands();
            }
        }
    }

    public static function discoverServices(): array
    {
        $config = config('command-schedule-job.discovery', []);
        $paths = $config['paths'] ?? ['app/Services/'];
        $namespaces = $config['namespaces'] ?? ['App\\Services'];

        $services = [];

        foreach ($paths as $index => $relativePath) {
            $namespace = $namespaces[$index] ?? $namespaces[0];

            $path = base_path($relativePath);
            if (!File::isDirectory($path)) {
                continue;
            }

            $files = File::allFiles($path);

            foreach ($files as $file) {
                $relativeFilePath = str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
                $className = $namespace . '\\' . $relativeFilePath;

                if (class_exists($className) && is_subclass_of($className, CommandScheduleJobService::class)) {
                    $reflection = new \ReflectionClass($className);
                    if (!$reflection->isAbstract()) {
                        $services[] = $className;
                    }
                }
            }
        }

        return $services;
    }
}
