<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyJob;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyJobService;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;
use Illuminate\Support\Facades\Bus;

class DispatchesJobsTest extends TestCase
{
    private DummyJobService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DummyJobService::class);
        $this->service = $this->app->make(DummyJobService::class);
    }

    public function test_resolve_job_tags_uses_job_tags_method(): void
    {
        $job = new DummyJob();

        $tags = $this->callProtected($this->service, 'resolveJobTags', [$job]);

        $this->assertEquals(['dummy-job', 'test'], $tags);
    }

    public function test_resolve_job_tags_returns_empty_without_tags_and_horizon(): void
    {
        $job = new class {
            // No tags() method, no Horizon
        };

        $tags = $this->callProtected($this->service, 'resolveJobTags', [$job]);

        $this->assertEquals([], $tags);
    }

    public function test_get_active_jobs_returns_empty_without_joblog_and_horizon(): void
    {
        $activeJobs = $this->callProtected($this->service, 'getActiveJobsByTags', [['dummy-job']]);

        $this->assertTrue($activeJobs->isEmpty());
    }

    public function test_has_moonshine_db_joblog_trait_returns_false_without_package(): void
    {
        $result = $this->callProtected($this->service, 'hasMoonshineDbJobLogTrait');

        $this->assertFalse($result);
    }

    public function test_dispatch_job_dispatches_when_no_active_jobs(): void
    {
        Bus::fake();

        $this->callProtected($this->service, 'dispatchJob');

        Bus::assertDispatched(DummyJob::class);
    }

    public function test_dispatch_job_dispatches_sync_when_flag_set(): void
    {
        Bus::fake();

        $this->service->setDispatchSync(true);
        $this->callProtected($this->service, 'dispatchJob');

        Bus::assertDispatchedSync(DummyJob::class);
    }

    public function test_dispatch_job_skips_dedup_when_without_overlapping_disabled(): void
    {
        Bus::fake();

        // Disable overlapping job protection via DB config
        $config = \ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob::findOrCreateForService(DummyJobService::class);
        $config->update(['without_overlapping_job' => false]);

        // Re-create service to pick up new config
        $service = $this->app->make(DummyJobService::class);

        $this->callProtected($service, 'dispatchJob');

        Bus::assertDispatched(DummyJob::class);
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
