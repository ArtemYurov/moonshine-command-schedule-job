<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Feature;

use ArtemYurov\CommandScheduleJob\DTO\JobInfo;
use ArtemYurov\CommandScheduleJob\Enums\JobStatus;
use ArtemYurov\CommandScheduleJob\Tests\Fixtures\DummyJobService;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TerminatesJobsTest extends TestCase
{
    private DummyJobService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DummyJobService::class);
        $this->service = $this->app->make(DummyJobService::class);
    }

    public function test_terminate_active_jobs_returns_true_for_empty_collection(): void
    {
        $result = $this->callProtected($this->service, 'terminateActiveJobs', [collect()]);

        $this->assertTrue($result);
    }

    public function test_terminate_queued_job_without_horizon_skips_redis_deletion(): void
    {
        // Job with QUEUED status but no Horizon connection configured
        $job = new JobInfo(
            jobUuid: 'test-uuid-123',
            status: JobStatus::QUEUED,
            connection: 'non-horizon-connection',
            queue: 'default',
            queuedAt: Carbon::now(),
        );

        $result = $this->callProtected($this->service, 'terminateActiveJobs', [collect([$job])]);

        $this->assertTrue($result);
    }

    public function test_terminate_processing_job_without_pid_and_non_horizon(): void
    {
        $job = new JobInfo(
            jobUuid: 'test-uuid-456',
            status: JobStatus::PROCESSING,
            connection: 'non-horizon-connection',
            queue: 'default',
            startedAt: Carbon::now(),
        );

        $result = $this->callProtected($this->service, 'terminateActiveJobs', [collect([$job])]);

        $this->assertTrue($result);
    }

    public function test_is_process_alive_with_current_pid(): void
    {
        if (!extension_loaded('posix')) {
            $this->markTestSkipped('POSIX extension required');
        }

        $currentPid = getmypid();
        $result = $this->callProtected($this->service, 'isProcessAlive', [$currentPid]);

        $this->assertTrue($result);
    }

    public function test_is_process_alive_with_nonexistent_pid(): void
    {
        if (!extension_loaded('posix')) {
            $this->markTestSkipped('POSIX extension required');
        }

        // PID 99999999 should not exist
        $result = $this->callProtected($this->service, 'isProcessAlive', [99999999]);

        $this->assertFalse($result);
    }

    public function test_kill_process_sends_sigterm_first(): void
    {
        if (!extension_loaded('posix') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('POSIX and PCNTL extensions required');
        }

        // Fork a child process that just sleeps
        $pid = pcntl_fork();

        if ($pid === 0) {
            // Child process — sleep and exit
            sleep(30);
            exit(0);
        }

        $this->assertGreaterThan(0, $pid, 'Fork failed');

        // Verify process is alive
        $this->assertTrue($this->callProtected($this->service, 'isProcessAlive', [$pid]));

        // Kill it
        $this->callProtected($this->service, 'killProcess', [$pid]);

        // Wait for child to prevent zombie
        pcntl_waitpid($pid, $status);

        // Verify process is dead
        $this->assertFalse($this->callProtected($this->service, 'isProcessAlive', [$pid]));
    }

    public function test_terminate_job_with_pid_kills_process(): void
    {
        if (!extension_loaded('posix') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('POSIX and PCNTL extensions required');
        }

        $pid = pcntl_fork();

        if ($pid === 0) {
            sleep(30);
            exit(0);
        }

        $this->assertGreaterThan(0, $pid);

        $job = new JobInfo(
            jobUuid: 'test-uuid-kill',
            status: JobStatus::PROCESSING,
            connection: 'non-horizon',
            queue: 'default',
            pid: $pid,
            startedAt: Carbon::now(),
        );

        $result = $this->callProtected($this->service, 'terminateActiveJobs', [collect([$job])]);

        pcntl_waitpid($pid, $status);

        $this->assertTrue($result);
        $this->assertFalse($this->callProtected($this->service, 'isProcessAlive', [$pid]));
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
