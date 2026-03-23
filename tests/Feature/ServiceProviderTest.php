<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\Providers\CommandScheduleJobServiceProvider;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('command-schedule'));
        $this->assertIsArray(config('command-schedule-job.discovery'));
    }

    public function test_discovery_returns_empty_for_no_paths(): void
    {
        $this->app['config']->set('command-schedule.discovery.paths', []);
        $this->app['config']->set('command-schedule.discovery.namespaces', []);

        $services = CommandScheduleJobServiceProvider::discoverServices();

        $this->assertIsArray($services);
        $this->assertEmpty($services);
    }

    public function test_discovery_skips_nonexistent_paths(): void
    {
        $this->app['config']->set('command-schedule.discovery.paths', ['/nonexistent/path/']);
        $this->app['config']->set('command-schedule.discovery.namespaces', ['Fake\\Namespace']);

        $services = CommandScheduleJobServiceProvider::discoverServices();

        $this->assertIsArray($services);
        $this->assertEmpty($services);
    }

    public function test_migration_creates_table(): void
    {
        $this->assertTrue(
            \Schema::hasTable('command_schedule_jobs')
        );
    }
}
