<?php

namespace ArtemYurov\CommandScheduleJob\Tests\Unit;

use ArtemYurov\CommandScheduleJob\Support\FrequencyHelper;
use ArtemYurov\CommandScheduleJob\Tests\TestCase;

class FrequencyValidationTest extends TestCase
{
    public function test_get_frequency_info_returns_all_frequencies(): void
    {
        $frequencies = FrequencyHelper::getFrequencyInfo();

        $this->assertIsArray($frequencies);
        $this->assertNotEmpty($frequencies);

        $this->assertArrayHasKey('daily', $frequencies);
        $this->assertArrayHasKey('hourly', $frequencies);
        $this->assertArrayHasKey('everyFiveMinutes', $frequencies);
        $this->assertArrayHasKey('dailyAt', $frequencies);
        $this->assertArrayHasKey('weekly', $frequencies);
    }

    public function test_get_frequency_info_returns_null_for_unknown(): void
    {
        $result = FrequencyHelper::getFrequencyInfo('nonExistentMethod');

        $this->assertNull($result);
    }

    public function test_get_frequency_info_has_correct_structure(): void
    {
        $info = FrequencyHelper::getFrequencyInfo('dailyAt');

        $this->assertIsArray($info);
        $this->assertArrayHasKey('count', $info);
        $this->assertArrayHasKey('required_count', $info);
        $this->assertArrayHasKey('params', $info);
        $this->assertArrayHasKey('signature', $info);
    }

    public function test_daily_requires_no_args(): void
    {
        $info = FrequencyHelper::getFrequencyInfo('daily');

        $this->assertEquals(0, $info['count']);
        $this->assertEquals(0, $info['required_count']);
    }

    public function test_daily_at_accepts_one_arg(): void
    {
        $info = FrequencyHelper::getFrequencyInfo('dailyAt');

        $this->assertGreaterThanOrEqual(1, $info['count']);
    }

    public function test_validate_frequency_args_passes_for_correct_args(): void
    {
        $error = FrequencyHelper::validateFrequencyArgs('daily', []);
        $this->assertNull($error);

        $error = FrequencyHelper::validateFrequencyArgs('dailyAt', ['08:00']);
        $this->assertNull($error);
    }

    public function test_validate_frequency_args_fails_for_unknown_frequency(): void
    {
        $error = FrequencyHelper::validateFrequencyArgs('nonExistent', []);

        $this->assertNotNull($error);
        $this->assertStringContainsString('Unknown frequency', $error);
    }

    public function test_validate_frequency_args_fails_for_too_many_args(): void
    {
        $error = FrequencyHelper::validateFrequencyArgs('daily', ['extra', 'args']);

        $this->assertNotNull($error);
        $this->assertStringContainsString('arguments', $error);
    }

    public function test_excludes_cron_between_unless_between(): void
    {
        $frequencies = FrequencyHelper::getFrequencyInfo();

        $this->assertArrayNotHasKey('cron', $frequencies);
        $this->assertArrayNotHasKey('between', $frequencies);
        $this->assertArrayNotHasKey('unlessBetween', $frequencies);
    }
}
