<?php

namespace ArtemYurov\CommandScheduleJob\Models;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobService;
use ArtemYurov\CommandScheduleJob\CommandScheduleJobServiceRegistry;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class CommandScheduleJob extends Model
{
    protected $fillable = [
        'service_class',
        'schedule_enabled',
        'schedule_console_args',
        'frequency',
        'frequency_args',
        'last_run_at',
        'description',
        'without_overlapping_job',
    ];

    public function getTable(): string
    {
        return config('command-schedule-job.table', 'command_schedule_jobs');
    }

    protected $casts = [
        'schedule_enabled' => 'boolean',
        'frequency_args' => 'array',
        'last_run_at' => 'datetime',
        'without_overlapping_job' => 'boolean',
    ];

    public function updateLastRunAt(?Carbon $lastRun = null): void
    {
        $this->update(['last_run_at' => $lastRun ?? now()]);
    }

    public static function findOrCreateForService(string $serviceClass): self
    {
        try {
            $data = app($serviceClass)->getDefaults();
        } catch (\Exception $e) {
            $data = [];
        }

        $existing = self::where('service_class', $serviceClass)->first();

        if (!$existing) {
            $data['schedule_enabled'] = false;
        } else {
            $data['schedule_enabled'] = $existing->schedule_enabled && !empty($data['frequency']);

            unset($data['frequency'], $data['frequency_args'], $data['without_overlapping_job']);
        }

        return self::updateOrCreate(
            ['service_class' => $serviceClass],
            $data
        );
    }

    public static function syncWithRegistry(): void
    {
        $serviceClasses = app(CommandScheduleJobServiceRegistry::class)->all();

        foreach ($serviceClasses as $serviceClass) {
            self::findOrCreateForService($serviceClass);
        }

        self::whereNotIn('service_class', $serviceClasses)->delete();
    }

}
