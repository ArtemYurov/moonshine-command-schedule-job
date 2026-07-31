<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyJob;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyJobService;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyNonQueueableJob;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyOverlappingJob;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

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

    public function test_dispatch_job_skips_dedup_when_should_be_unique_disabled(): void
    {
        Bus::fake();

        // Disable dispatch uniqueness (ShouldBeUnique) protection via DB config
        $config = \ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob::findOrCreateForService(DummyJobService::class);
        $config->update(['should_be_unique_job' => false]);

        // Re-create service to pick up new config
        $service = $this->app->make(DummyJobService::class);

        $this->callProtected($service, 'dispatchJob');

        Bus::assertDispatched(DummyJob::class);
    }

    public function test_dispatch_job_attaches_overlap_middleware_when_without_overlapping_enabled(): void
    {
        Bus::fake();

        // Enable execution-level overlap prevention via DB config
        $config = \ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob::findOrCreateForService(DummyJobService::class);
        $config->update(['without_overlapping_job' => true]);

        $service = $this->app->make(DummyJobService::class);

        $this->callProtected($service, 'dispatchJob');

        // db-joblog package is absent in testbench → the native fallback middleware is used
        Bus::assertDispatched(DummyJob::class, function (DummyJob $job) {
            return collect($job->middleware ?? [])
                ->contains(fn ($m) => $m instanceof \Illuminate\Queue\Middleware\WithoutOverlapping);
        });
    }

    public function test_dispatch_job_does_not_attach_overlap_middleware_when_disabled(): void
    {
        Bus::fake();

        // Default: without_overlapping_job is false
        $this->callProtected($this->service, 'dispatchJob');

        Bus::assertDispatched(DummyJob::class, function (DummyJob $job) {
            return empty($job->middleware);
        });
    }

    public function test_resolve_overlap_middleware_falls_back_to_native_without_joblog(): void
    {
        $job = new DummyJob();

        $middleware = $this->callProtected($this->service, 'resolveOverlapMiddleware', [$job]);

        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware);
    }

    public function test_resolve_overlap_middleware_applies_dont_release_when_configured(): void
    {
        // dontRelease() switches the middleware from serialize (release) to drop.
        // On the native fallback this zeroes out the release delay (releaseAfter = null).
        $this->service->setWithoutOverlappingJobDontRelease(true);

        $job = new DummyJob();

        $middleware = $this->callProtected($this->service, 'resolveOverlapMiddleware', [$job]);

        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware);
        $this->assertNull($middleware->releaseAfter);
    }

    public function test_dispatch_job_attaches_overlap_middleware_even_when_sync(): void
    {
        Bus::fake();

        // Overlap middleware must be attached for ALL dispatches, sync included —
        // there is no !dispatchSync branch anymore.
        $config = \ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob::findOrCreateForService(DummyJobService::class);
        $config->update(['without_overlapping_job' => true]);

        $service = $this->app->make(DummyJobService::class);
        $service->setDispatchSync(true);

        $this->callProtected($service, 'dispatchJob');

        Bus::assertDispatchedSync(DummyJob::class, function (DummyJob $job) {
            return collect($job->middleware ?? [])
                ->contains(fn ($m) => $m instanceof \Illuminate\Queue\Middleware\WithoutOverlapping);
        });
    }

    public function test_attach_overlap_middleware_skips_job_without_should_queue(): void
    {
        Log::spy();

        // Bus\Dispatcher sends a non-ShouldQueue job to dispatchNow(), which applies
        // bus pipes only — the middleware would never run.
        $job = new DummyNonQueueableJob();

        $this->callProtected($this->service, 'attachOverlapMiddleware', [$job]);

        // Nothing attached, and no dynamic property created on a class without Queueable.
        $this->assertFalse(property_exists($job, 'middleware'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_dispatch_job_skips_overlap_middleware_for_non_should_queue_job(): void
    {
        Bus::fake();

        $config = \ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob::findOrCreateForService(DummyJobService::class);
        $config->update(['without_overlapping_job' => true]);

        $service = $this->app->make(DummyJobService::class);
        $this->setProtected($service, 'jobClass', DummyNonQueueableJob::class);

        $this->callProtected($service, 'dispatchJob');

        Bus::assertDispatched(
            DummyNonQueueableJob::class,
            fn (DummyNonQueueableJob $job) => !property_exists($job, 'middleware'),
        );
    }

    public function test_attach_overlap_middleware_does_not_duplicate_middleware_from_method(): void
    {
        Log::spy();

        // The job declares WithoutOverlapping('own-key') via middleware(); stacking ours
        // on top would make it acquire two independent locks.
        $job = new DummyOverlappingJob();

        $this->callProtected($this->service, 'attachOverlapMiddleware', [$job]);

        $this->assertSame([], $job->middleware);
        Log::shouldHaveReceived('debug')->once();
    }

    public function test_attach_overlap_middleware_does_not_duplicate_middleware_from_property(): void
    {
        $job = new DummyJob();
        $job->middleware[] = new \Illuminate\Queue\Middleware\WithoutOverlapping('own-key');

        $this->callProtected($this->service, 'attachOverlapMiddleware', [$job]);

        $this->assertCount(1, $job->middleware);
    }

    public function test_has_overlap_middleware_detects_string_class_names(): void
    {
        // Laravel also accepts middleware as class names and "Class@method" strings.
        $job = new DummyJob();
        $job->middleware[] = \Illuminate\Queue\Middleware\WithoutOverlapping::class . '@handle';

        $this->assertTrue($this->callProtected($this->service, 'hasOverlapMiddleware', [$job]));
    }

    public function test_has_overlap_middleware_ignores_unrelated_middleware(): void
    {
        $job = new DummyJob();
        $job->middleware[] = new \Illuminate\Queue\Middleware\RateLimited('reports');

        $this->assertFalse($this->callProtected($this->service, 'hasOverlapMiddleware', [$job]));

        // ...and the overlap middleware is still attached alongside it.
        $this->callProtected($this->service, 'attachOverlapMiddleware', [$job]);

        $this->assertCount(2, $job->middleware);
    }

    public function test_should_be_unique_job_expires_at_falls_back_to_default(): void
    {
        // With the config key absent, the getter's inline default (3 * 60 * 60) applies.
        $cfg = config('command-schedule-job');
        unset($cfg['default_should_be_unique_job_expires_at']);
        config()->set('command-schedule-job', $cfg);

        $this->assertEquals(10800, $this->service->getShouldBeUniqueJobExpiresAt());
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

    /**
     * Helper to set protected/private properties via reflection.
     */
    private function setProtected(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
