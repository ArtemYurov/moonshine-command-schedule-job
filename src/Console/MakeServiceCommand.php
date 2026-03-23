<?php

namespace ArtemYurov\CommandScheduleJob\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:command-schedule-job-service')]
class MakeServiceCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:command-schedule-job-service';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new schedule service class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Service';

    protected function getStub(): string
    {
        return __DIR__ . '/stubs/command-schedule-job-service.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        $namespaces = config('command-schedule-job.discovery.namespaces', ['App\\Services']);

        return $namespaces[0] ?? $rootNamespace . '\\Services';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $commandSignature = $this->option('command')
            ?? 'app:' . Str::kebab(class_basename($name));

        $stub = str_replace('{{ command }}', $commandSignature, $stub);

        if ($this->option('job')) {
            $jobName = $this->resolveJobName($name);
            $jobClass = class_basename($jobName) . '::class';
        } else {
            $jobClass = 'null';
        }

        return str_replace('{{ jobClass }}', $jobClass, $stub);
    }

    public function handle(): bool|null
    {
        $result = parent::handle();

        if ($result === false) {
            return false;
        }

        if ($this->option('job')) {
            $jobName = $this->resolveJobName($this->qualifyClass($this->getNameInput()));
            $this->call('make:job', ['name' => class_basename($jobName)]);
        }

        return $result;
    }

    /**
     * Resolve the job class name from the service name.
     *
     * Strips "Service" suffix and appends "Job".
     * e.g. TestService → TestJob, SendNotifications → SendNotificationsJob
     */
    protected function resolveJobName(string $serviceName): string
    {
        $basename = class_basename($serviceName);
        $stripped = str_ends_with($basename, 'Service')
            ? substr($basename, 0, -7)
            : $basename;

        return $stripped . 'Job';
    }

    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the service class'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['command', null, InputOption::VALUE_OPTIONAL, 'The artisan command signature for the service'],
            ['job', null, InputOption::VALUE_NONE, 'Create a job class for the service'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the service already exists'],
        ];
    }
}
