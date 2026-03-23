<?php

namespace ArtemYurov\CommandScheduleJob\Support;

use Illuminate\Console\Scheduling\ManagesFrequencies;

class FrequencyHelper
{
    public static function getFrequencyInfo(?string $frequency = null): array|null
    {
        static $allFrequencies = null;

        if ($allFrequencies === null) {
            $options = [];
            $reflection = new \ReflectionClass(ManagesFrequencies::class);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $methodName = $method->getName();

                if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                    continue;
                }

                if (!in_array($methodName, ['cron', 'between', 'unlessBetween'])) {
                    $paramCount = $method->getNumberOfParameters();
                    $requiredParamCount = $method->getNumberOfRequiredParameters();
                    $params = [];

                    foreach ($method->getParameters() as $parameter) {
                        $paramInfo = '$' . $parameter->getName();
                        if ($parameter->isDefaultValueAvailable()) {
                            $defaultValue = $parameter->getDefaultValue();
                            if (is_string($defaultValue)) {
                                if (preg_match('/^\d{1,2}:\d{1,2}$/', $defaultValue)) {
                                    [$h, $m] = explode(':', $defaultValue);
                                    $defaultValue = sprintf('%02d:%02d', $h, $m);
                                }
                                $paramInfo .= ' = ' . $defaultValue;
                            } elseif (is_null($defaultValue)) {
                                $paramInfo .= ' = null';
                            } else {
                                $paramInfo .= ' = ' . var_export($defaultValue, true);
                            }
                        }
                        $params[] = $paramInfo;
                    }

                    $options[$methodName] = [
                        'count' => $paramCount,
                        'required_count' => $requiredParamCount,
                        'params' => $params,
                        'signature' => $methodName . '(' . implode(', ', $params) . ')'
                    ];
                }
            }

            $allFrequencies = $options;
        }

        return $frequency === null ? $allFrequencies : ($allFrequencies[$frequency] ?? null);
    }

    public static function validateFrequencyArgs(string $frequency, array $args): ?string
    {
        $frequencyInfo = self::getFrequencyInfo($frequency);
        if (!$frequencyInfo) {
            return __('command-schedule-job::messages.validation.unknown_frequency', ['frequency' => $frequency]);
        }

        $minRequired = $frequencyInfo['required_count'] ?? 0;
        $maxAllowed = $frequencyInfo['count'] ?? 0;
        $actualCount = count($args);

        return ($actualCount < $minRequired || $actualCount > $maxAllowed)
            ? __('command-schedule-job::messages.validation.invalid_args_count', [
                'expected' => $minRequired === $maxAllowed ? $minRequired : "{$minRequired}-{$maxAllowed}",
                'actual' => $actualCount,
            ])
            : null;
    }
}
