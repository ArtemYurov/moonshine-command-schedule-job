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

        // Code-only flag: the DB has no say in it any more.
        $service = $this->app->make(DummyJobService::class)->setWithoutOverlappingJob(true);

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

        // Default: $withoutOverlappingJob is false
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

    public function test_resolve_overlap_middleware_applies_release_and_expiry_settings(): void
    {
        // Both settings must reach the middleware: the per-service override when set,
        // the package config otherwise. Silently dropping expireAfter leaves the lock
        // on its own default, which can be shorter than the job's timeout.
        config()->set('command-schedule-job.default_without_overlapping_job_release_after', 7);
        config()->set('command-schedule-job.default_without_overlapping_job_expires_after', 1234);

        $middleware = $this->callProtected($this->service, 'resolveOverlapMiddleware', [new DummyJob()]);

        $this->assertSame(7, $middleware->releaseAfter);
        $this->assertSame(1234, $middleware->expiresAfter);

        $this->service->setWithoutOverlappingJobReleaseAfter(11);
        $this->service->setWithoutOverlappingJobExpiresAfter(4321);

        $middleware = $this->callProtected($this->service, 'resolveOverlapMiddleware', [new DummyJob()]);

        $this->assertSame(11, $middleware->releaseAfter, 'per-service override wins over config');
        $this->assertSame(4321, $middleware->expiresAfter, 'per-service override wins over config');
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

    public function test_overlap_expiry_is_never_shorter_than_the_job_timeout(): void
    {
        // A lock that expires mid-run lets a second execution in — the exact thing the
        // middleware prevents — so a job outliving the configured value raises it.
        config()->set('command-schedule-job.default_without_overlapping_job_expires_after', 600);

        $short = new DummyJob();
        $short->timeout = 120;
        $mw = $this->callProtected($this->service, 'resolveOverlapMiddleware', [$short]);
        $this->assertSame(600, $mw->expiresAfter, 'a job well inside the window keeps the configured value');

        $long = new DummyJob();
        $long->timeout = 900;
        $mw = $this->callProtected($this->service, 'resolveOverlapMiddleware', [$long]);
        $this->assertSame(960, $mw->expiresAfter, 'timeout + margin wins when the configured value is too short');
    }

    public function test_dispatch_job_attaches_overlap_middleware_even_when_sync(): void
    {
        Bus::fake();

        // Overlap middleware must be attached for ALL dispatches, sync included —
        // there is no !dispatchSync branch anymore.
        $service = $this->app->make(DummyJobService::class)->setWithoutOverlappingJob(true);
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

        $service = $this->app->make(DummyJobService::class)->setWithoutOverlappingJob(true);
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

    public function test_should_be_unique_job_expires_after_falls_back_to_default(): void
    {
        // With the config key absent, the getter's inline default (3 * 60 * 60) applies.
        $cfg = config('command-schedule-job');
        unset($cfg['default_should_be_unique_job_expires_after']);
        config()->set('command-schedule-job', $cfg);

        $this->assertEquals(10800, $this->service->getShouldBeUniqueJobExpiresAfter());
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
