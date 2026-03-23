<?php

namespace ArtemYurov\CommandScheduleJob\Traits;

use ArtemYurov\CommandScheduleJob\DTO\JobInfo;
use ArtemYurov\CommandScheduleJob\Enums\JobStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

trait TerminatesJobs
{
    /**
     * @param Collection<int, JobInfo> $activeJobs
     */
    protected function terminateActiveJobs(Collection $activeJobs): bool
    {
        $this->command?->warn(__('command-schedule-job::messages.console.force_terminating'));

        $horizonConnection = Arr::first(config('horizon.defaults', []))['connection'] ?? 'redis';

        $needHorizonRestart = false;

        foreach ($activeJobs as $job) {
            try {
                $processKilled = false;

                if ($job->pid && extension_loaded('posix') && $this->isProcessAlive($job->pid)) {
                    $this->killProcess($job->pid);
                    $processKilled = true;
                    $this->command?->info(__('command-schedule-job::messages.console.process_killed', ['pid' => $job->pid, 'uuid' => $job->jobUuid]));
                }

                if ($job->connection === $horizonConnection && $job->status === JobStatus::PROCESSING && !$processKilled) {
                    $needHorizonRestart = true;
                }

                if ($job->connection === $horizonConnection && $job->status === JobStatus::QUEUED) {
                    $this->deleteHorizonJobByUuid($job->jobUuid, $job->queue);
                    $this->command?->info(__('command-schedule-job::messages.console.removed_from_queue', ['uuid' => $job->jobUuid]));
                }

                if ($this->hasMoonshineDbJobLogTrait() && class_exists(\ArtemYurov\JobLog\Logger\JobLogger::class)) {
                    \ArtemYurov\JobLog\Logger\JobLogger::changeStatusFromEvent(
                        $job->jobUuid,
                        \ArtemYurov\JobLog\Enums\JobLogStatus::INTERRUPTED
                    );
                }
                $this->command?->info(__('command-schedule-job::messages.console.marked_interrupted', ['uuid' => $job->jobUuid]));
            } catch (\RuntimeException $e) {
                $this->command?->error(__('command-schedule-job::messages.console.error_terminating', ['uuid' => $job->jobUuid, 'error' => $e->getMessage()]));
                return false;
            }
        }

        if ($needHorizonRestart) {
            if (!$this->command?->confirm(__('command-schedule-job::messages.console.horizon_terminate_confirm'))) {
                $this->command?->warn(__('command-schedule-job::messages.console.horizon_restart_cancelled'));
                return false;
            }

            $this->command->warn(__('command-schedule-job::messages.console.restarting_horizon'));
            Artisan::call('horizon:terminate');
            sleep(3);
        }

        return true;
    }

    protected function isProcessAlive(int $pid): bool
    {
        return posix_getpgid($pid) !== false;
    }

    protected function killProcess(int $pid): void
    {
        posix_kill($pid, SIGTERM);
        usleep(500_000);
        if ($this->isProcessAlive($pid)) {
            posix_kill($pid, SIGKILL);
        }
    }

    protected function deleteHorizonJobByUuid(string $uuid, string $queue): void
    {
        $purged = Redis::connection('horizon')->eval(
            $this->luaPurgeUuidScript(),
            1,
            'pending_jobs',
            config('horizon.prefix'),
            $queue,
            $uuid
        );

        $queueName = 'queues:' . ($queue ?: 'default');

        $cleared = Redis::connection()->eval(
            $this->luaClearUuidScript(),
            2,
            $queueName,
            $queueName . ':delayed',
            $uuid
        );

        if ($purged == 0 && $cleared == 0) {
            $this->isVerbose() && $this->command?->info(__('command-schedule-job::messages.console.job_not_found', ['uuid' => $uuid, 'queue' => $queue]));
        }
    }

    protected function luaPurgeUuidScript(): string
    {
        return <<<'LUA'
            local count = 0
            local cursor = 0

            repeat
                -- Iterate over the pending jobs sorted set
                local scanner = redis.call('zscan', KEYS[1], cursor)
                cursor = scanner[1]

                for i = 1, #scanner[2], 2 do
                    local jobid = scanner[2][i]
                    local hashkey = ARGV[1] .. jobid
                    local job = redis.call('hmget', hashkey, 'status', 'queue')

                    -- Delete the pending jobs, that match the queue and UUID
                    -- name, from the sorted set as well as the job hash
                    if(job[1] == 'pending' and job[2] == ARGV[2] and jobid == ARGV[3]) then
                        redis.call('zrem', KEYS[1], jobid)
                        redis.call('del', hashkey)
                        count = count + 1
                    end
                end
            until cursor == '0'

            return count
        LUA;
    }

    protected function luaClearUuidScript(): string
    {
        return <<<'LUA'
            local count = 0

            -- Check and remove from main queue (KEYS[1])
            local jobs = redis.call('lrange', KEYS[1], 0, -1)
            for i = 1, #jobs do
                local job = cjson.decode(jobs[i])
                if job.uuid == ARGV[1] then
                    redis.call('lrem', KEYS[1], 1, jobs[i])
                    count = count + 1
                    break
                end
            end

            -- Check and remove from delayed queue (KEYS[2])
            local delayedJobs = redis.call('zrange', KEYS[2], 0, -1)
            for i = 1, #delayedJobs do
                local job = cjson.decode(delayedJobs[i])
                if job.uuid == ARGV[1] then
                    redis.call('zrem', KEYS[2], delayedJobs[i])
                    count = count + 1
                end
            end

            return count
        LUA;
    }
}
