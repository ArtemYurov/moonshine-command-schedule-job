<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overlap prevention is a property of the task, not an operational setting.
 *
 * Every other knob of the same mechanism — drop-vs-release, release delay, staleness cap,
 * JobLog-vs-native — already lives in code, so the DB held only the off switch, the one
 * direction that can hurt. And findOrCreateForService() never re-seeded an existing row, so
 * $withoutOverlappingJob in code was decorative and could silently disagree with production.
 * Schedule fields and should_be_unique_job stay: those are operational.
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('command-schedule-job.table', 'command_schedule_jobs');
    }

    public function up(): void
    {
        Schema::table($this->tableName(), function (Blueprint $t) {
            $t->dropColumn('without_overlapping_job');
        });
    }

    public function down(): void
    {
        Schema::table($this->tableName(), function (Blueprint $t) {
            $t->boolean('without_overlapping_job')->default(false);
        });
    }
};
