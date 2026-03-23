<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyScheduleService;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;

class CommandScheduleJobModelTest extends TestCase
{
    public function test_table_created_by_migration(): void
    {
        $this->assertTrue(
            \Schema::hasTable(config('command-schedule-job.table', 'command_schedule_jobs'))
        );
    }

    public function test_find_or_create_creates_new_record(): void
    {
        $this->app->bind(DummyScheduleService::class);

        $schedule = CommandScheduleJob::findOrCreateForService(DummyScheduleService::class);

        $this->assertInstanceOf(CommandScheduleJob::class, $schedule);
        $this->assertEquals(DummyScheduleService::class, $schedule->service_class);
        $this->assertFalse($schedule->schedule_enabled);
        $this->assertEquals('daily', $schedule->frequency);
        $this->assertEquals('Dummy test service', $schedule->description);
    }

    public function test_find_or_create_returns_existing_record(): void
    {
        $this->app->bind(DummyScheduleService::class);

        $first = CommandScheduleJob::findOrCreateForService(DummyScheduleService::class);
        $first->update(['schedule_enabled' => true]);

        $second = CommandScheduleJob::findOrCreateForService(DummyScheduleService::class);

        $this->assertEquals($first->id, $second->id);
        $this->assertTrue($second->schedule_enabled);
    }

    public function test_update_last_run_at(): void
    {
        $this->app->bind(DummyScheduleService::class);

        $schedule = CommandScheduleJob::findOrCreateForService(DummyScheduleService::class);
        $this->assertNull($schedule->last_run_at);

        $schedule->updateLastRunAt();
        $schedule->refresh();

        $this->assertNotNull($schedule->last_run_at);
    }

    public function test_frequency_args_cast_to_array(): void
    {
        $schedule = CommandScheduleJob::create([
            'service_class' => 'Test\\FakeService',
            'frequency' => 'dailyAt',
            'frequency_args' => ['08:00'],
            'description' => 'test',
        ]);

        $schedule->refresh();

        $this->assertIsArray($schedule->frequency_args);
        $this->assertEquals(['08:00'], $schedule->frequency_args);
    }

    public function test_boolean_casts(): void
    {
        $schedule = CommandScheduleJob::create([
            'service_class' => 'Test\\BoolService',
            'schedule_enabled' => 1,
            'description' => 'test',
        ]);

        $schedule->refresh();

        $this->assertTrue($schedule->schedule_enabled);
    }
}
