<?php

namespace ArtemYurov\CommandScheduleJob;

use Illuminate\Support\Facades\File;

/**
 * Central registry for CommandScheduleJobService classes.
 *
 * Sources: register() from packages, config 'services' key, auto-discovery.
 */
class CommandScheduleJobServiceRegistry
{
    /** @var class-string<CommandScheduleJobService>[] */
    private array $services = [];

    /** @param class-string<CommandScheduleJobService>|class-string<CommandScheduleJobService>[] $services */
    public function register(array|string $services): static
    {
        $this->services = array_unique(array_merge(
            $this->services,
            (array) $services
        ));

        return $this;
    }

    /** @return class-string<CommandScheduleJobService>[] */
    public function all(): array
    {
        return array_unique(array_merge(
            $this->services,
            config('command-schedule-job.services', []),
            $this->discover()
        ));
    }

    /** @return class-string<CommandScheduleJobService>[] */
    protected function discover(): array
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

            foreach (File::allFiles($path) as $file) {
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
