<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyScheduleService;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyNoFrequencyService;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;

class CommandScheduleJobServiceTest extends TestCase
{
    private DummyScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DummyScheduleService::class);
        $this->app->bind(DummyNoFrequencyService::class);

        $this->service = $this->app->make(DummyScheduleService::class);
    }

    public function test_execute_runs_handle(): void
    {
        $this->service->execute();

        $this->assertTrue($this->service->handled);
    }

    public function test_execute_updates_last_run_at(): void
    {
        $schedule = CommandScheduleJob::findOrCreateForService(DummyScheduleService::class);
        $this->assertNull($schedule->fresh()->last_run_at);

        $service = $this->app->make(DummyScheduleService::class);
        $service->execute();

        $this->assertNotNull($schedule->fresh()->last_run_at);
    }

    public function test_register_commands_does_not_throw(): void
    {
        $this->service->registerCommands();

        // Artisan command should be registered
        $this->assertTrue(
            array_key_exists('test:dummy', \Artisan::all())
        );
    }

    public function test_no_frequency_service_creates_record_with_null_frequency(): void
    {
        $schedule = CommandScheduleJob::findOrCreateForService(DummyNoFrequencyService::class);

        $this->assertNull($schedule->frequency);
    }
}
