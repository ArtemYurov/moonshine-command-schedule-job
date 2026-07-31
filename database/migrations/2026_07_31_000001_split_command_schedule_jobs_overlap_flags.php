<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split the original single overlap flag into two.
 *
 * v1.0 shipped one column `without_overlapping_job` (the dispatch-time uniqueness
 * guard, default true). It is renamed to `should_be_unique_job` — existing values
 * carry over, the default is normalized to false — and a fresh
 * `without_overlapping_job` (default false) is added for the execution-level
 * overlap middleware. Both protection flags are now opt-in.
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('command-schedule-job.table', 'command_schedule_jobs');
    }

    public function up(): void
    {
        $table = $this->tableName();

        Schema::table($table, function (Blueprint $t) {
            $t->renameColumn('without_overlapping_job', 'should_be_unique_job');
        });

        Schema::table($table, function (Blueprint $t) {
            $t->boolean('should_be_unique_job')->default(false)->change(); // v1.0 default was true
            $t->boolean('without_overlapping_job')->default(false);
        });
    }

    public function down(): void
    {
        $table = $this->tableName();

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('without_overlapping_job');
        });

        Schema::table($table, function (Blueprint $t) {
            $t->renameColumn('should_be_unique_job', 'without_overlapping_job');
        });
    }
};
