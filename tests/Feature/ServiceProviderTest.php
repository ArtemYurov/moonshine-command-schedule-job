<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\CommandScheduleJobServiceRegistry;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_registry_skips_nonexistent_paths(): void
    {
        $this->app['config']->set('command-schedule-job.discovery.paths', ['/nonexistent/path/']);
        $this->app['config']->set('command-schedule-job.discovery.namespaces', ['Fake\\Namespace']);

        $services = app(CommandScheduleJobServiceRegistry::class)->all();

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
