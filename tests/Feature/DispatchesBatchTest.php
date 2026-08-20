<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\Models\CommandScheduleJob;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyBatchJob;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyBatchService;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyJobService;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyNoJobClassService;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;
use Illuminate\Support\Facades\Bus;

/** newBatch()/batch(): one job per item, and the three ways it yields nothing. */
class DispatchesBatchTest extends TestCase
{
    private DummyBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        DummyBatchJob::$handled = [];

        $this->app->bind(DummyBatchService::class);
        $this->service = $this->app->make(DummyBatchService::class);
    }

    public function test_builds_one_job_per_item_and_passes_extra_args(): void
    {
        $jobs = $this->service->newBatch(['a', 'b', 'c'], [true])->jobs->all();

        $this->assertCount(3, $jobs);
        $this->assertSame(['a', 'b', 'c'], array_map(fn (DummyBatchJob $j) => $j->item, $jobs));
        $this->assertTrue($jobs[0]->dryRun, 'extra args go after the item');
    }

    public function test_returns_null_for_empty_input(): void
    {
        // An empty batch never finishes, so a caller's barrier would hang on it.
        $this->assertNull($this->service->newBatch([]));
    }

    public function test_defaults_the_queue_to_the_first_job(): void
    {
        // The bulk path ignores $job->queue; without this default the batch goes to default.
        $this->assertSame('batch-fixture', $this->service->newBatch(['a'])->options['queue'] ?? null);
    }

    public function test_the_returned_batch_is_the_native_one(): void
    {
        $pending = $this->service->newBatch(['a'])
            ->name('my batch')
            ->allowFailures()
            ->onQueue('override');

        $this->assertSame('my batch', $pending->name);
        $this->assertTrue($pending->options['allowFailures'] ?? false);
        $this->assertSame('override', $pending->options['queue'] ?? null);
    }

    public function test_runs_inline_in_sync_mode(): void
    {
        // Not through the batch: its failure path differs between Laravel 11 and 12+.
        Bus::fake();

        $this->assertNull($this->service->setDispatchSync(true)->newBatch(['a', 'b']));

        Bus::assertDispatchedSync(DummyBatchJob::class, 2);
    }

    public function test_skips_items_whose_tags_are_already_held(): void
    {
        $this->enableUniqueGuard();
        $this->service->busyItems = ['b'];

        $jobs = $this->service->newBatch(['a', 'b', 'c'])->jobs->all();

        $this->assertSame(['a', 'c'], array_map(fn (DummyBatchJob $j) => $j->item, $jobs));
    }

    public function test_returns_null_when_the_guard_drops_every_item(): void
    {
        $this->enableUniqueGuard();
        $this->service->busyItems = ['a', 'b'];

        $this->assertNull($this->service->newBatch(['a', 'b']));
    }


    public function test_the_static_entry_resolves_a_fresh_service(): void
    {
        $jobs = DummyBatchService::batch(['a', 'b'], false, [true])->jobs->all();

        $this->assertCount(2, $jobs);
        $this->assertTrue($jobs[0]->dryRun);
    }

    public function test_the_static_entry_honours_the_required_sync_flag(): void
    {
        Bus::fake();

        $this->assertNull(DummyBatchService::batch(['a', 'b'], true));

        Bus::assertDispatchedSync(DummyBatchJob::class, 2);
    }

    public function test_a_job_class_without_batchable_cannot_be_batched(): void
    {
        // Checked before anything is built, so it fails the same way in both modes —
        // sync never reaches Bus::batch and would otherwise happily run such a job.
        $this->expectException(\LogicException::class);

        $this->app->make(DummyJobService::class)->newBatch(['a']);
    }

    public function test_a_service_without_a_job_class_cannot_batch(): void
    {
        $this->expectException(\LogicException::class);

        $this->app->make(DummyNoJobClassService::class)->newBatch(['a']);
    }

    private function enableUniqueGuard(): void
    {
        CommandScheduleJob::findOrCreateForService(DummyBatchService::class)
            ->update(['should_be_unique_job' => true]);
    }
}
