<?php

declare(strict_types=1);

namespace ArtemYurov\CommandScheduleJob\MoonShine\Resources;

use MoonShine\Support\Enums\Ability;
use MoonShine\Support\Enums\Action;
use Exception;
use Illuminate\Support\Facades\Log;
use ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob;
use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;
use ArtemYurov\CommandScheduleJob\Support\FrequencyHelper;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\Laravel\Resources\ModelResource;
use MoonShine\Support\Enums\SortDirection;
use MoonShine\Support\ListOf;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\Snippet;
use MoonShine\UI\Fields\Fieldset;
use MoonShine\UI\Fields\Date;
use MoonShine\UI\Fields\ID;
use MoonShine\UI\Fields\Json;
use MoonShine\UI\Fields\Preview;
use MoonShine\UI\Fields\Select;
use MoonShine\UI\Fields\Switcher;
use MoonShine\UI\Fields\Text;

/**
 * @extends ModelResource<CommandScheduleJob>
 */
class CommandScheduleJobResource extends ModelResource
{
    protected string $model = CommandScheduleJob::class;

    public function getTitle(): string
    {
        return __('command-schedule-job::messages.resource.title');
    }

    protected string $column = 'service_class';

    protected string $sortColumn = 'service_class';

    protected SortDirection $sortDirection = SortDirection::ASC;

    protected ?Schedule $schedule = null;

    /**
     * @return list<FieldContract>
     */
    protected function indexFields(): iterable
    {
        return [
            ID::make()->sortable(),
            Text::make(__('command-schedule-job::messages.resource.service'), 'service_class')
                ->changePreview(fn($value, Text $field) => $this->formatServiceDisplay($value, $field->getData()?->getOriginal()))
                ->sortable(),

            Fieldset::make(__('command-schedule-job::messages.resource.scheduler'))
            ->fields([
                Switcher::make(column: 'schedule_enabled')
                    ->updateOnPreview()
                    ->canSee(fn($ctx) => empty($ctx->getData()) || !empty($ctx->getData()?->getOriginal()->frequency)),
                Preview::make()
                    ->canSee(fn($ctx) => empty($ctx->getData()?->getOriginal()->frequency)),
            ]),
            Text::make(__('command-schedule-job::messages.resource.frequency'), 'frequency')
                ->changePreview(fn($value, Text $field) => $value ? $this->formatTwoLineDisplay($this->getDefaultDescription($value) ?: '—', $this->formatFrequencyArgs($field->getData()?->getOriginal()->frequency_args)) : ''),
            Date::make(__('command-schedule-job::messages.resource.last_run'), 'last_run_at')
                ->withTime()
                ->changePreview(fn($value) => $this->formatDateTimeWithBreak($value)),
            Text::make(__('command-schedule-job::messages.resource.next_run'), 'next_run_at')
                ->changePreview(fn($value, $field) => $this->getNextRunTime($field->getData()?->getOriginal())),

        ];
    }

    /**
     * @return list<ComponentContract|FieldContract>
     */
    protected function formFields(): iterable
    {
        return [
            Box::make(__('command-schedule-job::messages.resource.general'), [
                Text::make(__('command-schedule-job::messages.resource.service_class'), 'service_class')->readonly(),
                Text::make(__('command-schedule-job::messages.resource.description'), 'description')->readonly(),
                Preview::make(__('command-schedule-job::messages.resource.command'), 'service_class', function($item) {
                    $serviceClass = $item->service_class ?? (is_string($item) ? $item : '');
                    $command = $this->getCommandSignature($serviceClass);
                    return $command ? (string) Snippet::make($command)->customAttributes(['style' => 'flex-direction: row-reverse;']) : '—';
                }),
                Switcher::make(__('command-schedule-job::messages.resource.scheduler_enabled'), 'schedule_enabled')
                    ->canSee(fn() => $this->getItem() && $this->getItem()->frequency !== null),
                Select::make(__('command-schedule-job::messages.resource.frequency'), 'frequency')
                    ->searchable()
                    ->options($this->getFrequencyOptions())
                    ->canSee(fn($ctx) => $ctx->getData()?->getOriginal()->frequency !== null),
                Json::make(__('command-schedule-job::messages.resource.arguments'), 'frequency_args')
                    ->onlyValue()
                    ->removable()
                    ->canSee(fn($ctx) => $ctx->getData()?->getOriginal()->frequency !== null),
                Text::make(__('command-schedule-job::messages.resource.console_args'), 'schedule_console_args')
                    ->hint(__('command-schedule-job::messages.resource.console_args_hint'))
                    ->nullable()
                    ->canSee(fn($ctx) => $ctx->getData()?->getOriginal()->frequency !== null),
                Switcher::make(__('command-schedule-job::messages.resource.without_overlapping_job'), 'without_overlapping_job')
                    ->canSee(fn() => $this->getItem() && $this->serviceHasJob($this->getItem()->service_class)),
            ]),
        ];
    }

    /**
     * @param CommandScheduleJob $item
     *
     * @return array<string, string[]|string>
     */
    protected function rules(mixed $item): array
    {
        return [
            'service_class' => ['required', 'string', 'max:255'],
            'schedule_enabled' => ['boolean'],
            'schedule_console_args' => ['nullable', 'string', 'max:1000', 'regex:/^[\w\s\-=:\/\.\,]+$/'],
            'frequency' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($item) {
                    if (!$value) return;

                    $frequencyArgs = request()->input('frequency_args', []);

                    $filledArgs = [];
                    foreach ($frequencyArgs as $arg) {
                        if (is_array($arg) && isset($arg['value']) && !empty(trim((string)$arg['value']))) {
                            $filledArgs[] = $arg['value'];
                        } elseif (!is_array($arg) && !empty(trim((string)$arg))) {
                            $filledArgs[] = $arg;
                        }
                    }

                    $validationError = FrequencyHelper::validateFrequencyArgs($value, $filledArgs);
                    if ($validationError) {
                        $fail($validationError);
                    }
                }
            ],
            'frequency_args' => ['nullable', 'array'],
            'description' => ['nullable', 'string'],
            'without_overlapping_job' => ['boolean'],
        ];
    }

    protected function isCan(Ability $ability): bool
    {
        return request()->user()?->isSuperUser() ?? false;
    }

    protected function activeActions(): ListOf
    {
        return parent::activeActions()
            ->except(Action::CREATE, Action::DELETE, Action::MASS_DELETE, Action::VIEW);
    }

    protected function filters(): iterable
    {
        return [];
    }

    protected function onLoad(): void
    {
        parent::onLoad();

        try {
            CommandScheduleJob::syncAll();
        } catch (Exception $e) {
            Log::warning('Failed to sync service schedule: ' . $e->getMessage());
        }
    }

    protected function getFrequencyOptions(): array
    {
        $options = [];
        $frequencyInfos = FrequencyHelper::getFrequencyInfo();

        foreach ($frequencyInfos as $methodName => $frequencyInfo) {
            $description = $this->getDefaultDescription($methodName);

            if ($description) {
                $paramCount = $frequencyInfo['count'];
                $params = $frequencyInfo['params'] ?? [];

                if ($paramCount > 0) {
                    $paramsList = implode(', ', $params);
                    $options[$methodName] = "[{$methodName}] " . $description . " ({$paramsList})";
                } else {
                    $options[$methodName] = "[{$methodName}] " . $description;
                }
            }
        }

        return $options;
    }

    private function formatServiceDisplay(string $serviceClass, ?CommandScheduleJob $item): string
    {
        $description = $item?->description ?? class_basename($serviceClass);
        $className = class_basename($serviceClass);
        $command = $this->getCommandSignature($serviceClass);

        $html = e($description);
        $html .= "<br/><i style='font-size: small;'>" . e($className) . "</i>";

        if ($command) {
            $html .= '<br/>' . (string) Snippet::make($command)->customAttributes(['style' => 'white-space: nowrap; padding: 0 4px 0 0; flex-direction: row-reverse; gap: 2px; font-size: small; zoom: 0.8;']);
        }

        return $html;
    }

    private function formatDateTimeWithBreak(string|Carbon|null $value): string
    {
        if (!$value) {
            return '—';
        }

        $carbon = $value instanceof Carbon ? $value : Carbon::parse($value);

        return '<span style="white-space:nowrap">' . $carbon->format('Y-m-d') . '<br>' . $carbon->format('H:i:s') . '</span>';
    }

    private function formatTwoLineDisplay(string $mainLine, string $subLine = ''): string
    {
        if (empty($subLine)) {
            return $mainLine;
        }

        return $mainLine . "<br/><i style='font-size: small;'>" . $subLine . "</i>";
    }

    private function serviceHasJob(string $serviceClass): bool
    {
        try {
            if (!class_exists($serviceClass)) {
                return false;
            }

            $service = app($serviceClass);

            return $service instanceof CommandScheduleJobService && $service->getJobClass() !== null;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getCommandSignature(string $serviceClass): string
    {
        try {
            if (!class_exists($serviceClass)) {
                return '';
            }

            $service = app($serviceClass);
            if (!$service instanceof CommandScheduleJobService) {
                return '';
            }

            $commandSignature = $service->getCommandSignature();
            if (!$commandSignature) {
                return '';
            }

            $commandName = preg_replace('/\s*\{[^}]*\}/', '', $commandSignature);
            $commandName = trim($commandName);

            return "php artisan {$commandName}";
        } catch (Exception $e) {
            return '';
        }
    }

    private function formatFrequencyArgs(?array $args): string
    {
        if (empty($args)) {
            return '';
        }

        $formattedArgs = array_map(function($arg) {
            if (is_string($arg)) {
                return "'" . $arg . "'";
            }
            return (string) $arg;
        }, $args);

        return implode(', ', $formattedArgs);
    }

    protected function getDefaultDescription(string $methodName): ?string
    {
        $key = "command-schedule-job::messages.frequency.{$methodName}";
        $translated = __($key);

        return $translated !== $key ? $translated : null;
    }


    protected function getNextRunTime(?CommandScheduleJob $item): string
    {
        if (!$item || !$item->frequency) {
            return '';
        }

        if (!$item->schedule_enabled) {
            return '—';
        }

        try {
            if ($this->schedule === null) {
                $this->schedule = new Schedule();
            }

            $event = $this->schedule->call(fn() => null);
            $frequency = $item->frequency;
            $args = $item->frequency_args ?: [];

            if (method_exists($event, $frequency)) {
                $event->$frequency(...$args);

                $timezone = new DateTimeZone(config('app.timezone', 'UTC'));
                $nextRun = $event->nextRunDate($timezone);

                return $this->formatDateTimeWithBreak(Carbon::instance($nextRun));
            }

            return '—';
        } catch (Exception $e) {
            return '—';
        }
    }
}
