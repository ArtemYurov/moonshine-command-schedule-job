<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function tableName(): string
    {
        return config('command-schedule-job.table', 'command_schedule_jobs');
    }

    public function up(): void
    {
        if (Schema::hasTable($this->tableName())) {
            return;
        }

        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->string('service_class')->unique();
            $table->boolean('schedule_enabled')->default(false);
            $table->text('schedule_console_args')->nullable();
            $table->string('frequency')->nullable();
            $table->json('frequency_args')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->text('description')->nullable();
            $table->boolean('without_overlapping_job')->default(true);
            $table->timestamps();

            $table->index('schedule_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }
};
