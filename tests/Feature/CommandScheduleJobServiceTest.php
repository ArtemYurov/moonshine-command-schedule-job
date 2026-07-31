<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyScheduleService;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyNoFrequencyService;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyNonSchedulableService;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

class CommandScheduleJobServiceTest extends TestCase
{
    private DummyScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DummyScheduleService::class);
        $this->app->bind(DummyNoFrequencyService::class);
        $this->app->bind(DummyNonSchedulableService::class);

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

    public function test_schedulable_service_registers_schedule(): void
    {
        // Positive control: an enabled, schedulable service adds a cron entry.
        $config = CommandScheduleJob::findOrCreateForService(DummyScheduleService::class);
        $config->update(['schedule_enabled' => true]);

        $service = $this->app->make(DummyScheduleService::class);
        $schedule = $this->app->make(Schedule::class);
        $before = count($schedule->events());

        $this->callProtected($service, 'registerSchedule');

        $this->assertGreaterThan($before, count($schedule->events()));
    }

    public function test_non_schedulable_service_creates_disabled_row(): void
    {
        // The row must still be created (never null) — $schedulable is a capability
        // flag, not existence — but schedule_enabled is forced false.
        $config = CommandScheduleJob::findOrCreateForService(DummyNonSchedulableService::class);

        $this->assertInstanceOf(CommandScheduleJob::class, $config);
        $this->assertFalse($config->schedule_enabled);
    }

    public function test_non_schedulable_service_extinguishes_enabled_schedule(): void
    {
        // A schedule enabled out-of-band is gracefully extinguished on the next
        // findOrCreateForService pass for a non-schedulable service.
        $config = CommandScheduleJob::findOrCreateForService(DummyNonSchedulableService::class);
        $config->update(['schedule_enabled' => true]);

        $refreshed = CommandScheduleJob::findOrCreateForService(DummyNonSchedulableService::class);

        $this->assertFalse($refreshed->schedule_enabled);
    }

    public function test_non_schedulable_service_does_not_register_schedule(): void
    {
        // Even with schedule_enabled = true, $schedulable = false must block cron registration.
        $config = CommandScheduleJob::findOrCreateForService(DummyNonSchedulableService::class);
        $config->update(['schedule_enabled' => true]);

        $service = $this->app->make(DummyNonSchedulableService::class);
        $schedule = $this->app->make(Schedule::class);
        $before = count($schedule->events());

        $this->callProtected($service, 'registerSchedule');

        $this->assertCount($before, $schedule->events());
    }

    /**
     * Helper to call protected/private methods via reflection.
     */
    private function callProtected(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
